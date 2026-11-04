<?php

use App\Models\WorkJournal;
use App\Models\WorkJournalEntry;
use App\Services\WorkJournalService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * 업무일지(작성자) — 2026-11-04 Jin. 등급 「일반」 활성 사용자만.
 * 줄 추가 = 지금 시각 자동(수정 가능) · 칸마다 자동 저장(서버) · 맨 아래 board 활동은 감사 로그에서 자동, 상신 때 snapshot 으로 같이 올라간다.
 * 🚨 잠금은 서버가 판정한다 — 상신한 일지는 저장·삭제·줄 추가 전부 거절(화면에서 칸을 숨기는 것만으론 못 막는다).
 */
new #[Layout('components.layouts.app')] class extends Component {
    #[Url]
    public string $date = '';

    /** 화면 칸 — [{id,time,task,note}]. 저장은 칸 단위(updatedRows). */
    public array $rows = [];

    public ?string $savedAt = null;

    public function mount(): void
    {
        abort_unless(Auth::user()->isJournalWriter(), 403, __('journal.forbidden'));
        $this->normalizeDate();
        $this->loadRows();
    }

    private function normalizeDate(): void
    {
        try {
            $d = Carbon::parse($this->date ?: today())->startOfDay();
        } catch (\Throwable) {
            $d = today();
        }
        $this->date = ($d->gt(today()) ? today() : $d)->toDateString();
    }

    private function svc(): WorkJournalService
    {
        return app(WorkJournalService::class);
    }

    #[Computed]
    public function journal(): ?WorkJournal
    {
        return WorkJournal::where('user_id', Auth::id())->whereDate('date', $this->date)->first();
    }

    private function loadRows(): void
    {
        unset($this->journal);
        $j = $this->journal;
        $this->rows = $j ? $j->entries()->get()->map(fn ($e) => ['id' => $e->id, 'time' => (string) $e->time, 'task' => (string) $e->task, 'note' => (string) $e->note])->all() : [];
        $this->savedAt = null;
    }

    public function goDate(string $d): void
    {
        $this->date = $d;
        $this->normalizeDate();
        $this->loadRows();
    }

    /** 쓰기 가능한 일지(없으면 만든다). 상신했으면 null + 에러. */
    private function writable(): ?WorkJournal
    {
        $j = $this->svc()->journalFor(Auth::user(), $this->date);
        if (! $j->isDraft()) {
            $this->addError('rows', __('journal.err.locked'));

            return null;
        }
        $this->svc()->logFirstSave($j);

        return $j;
    }

    public function addRow(): void
    {
        $j = $this->writable();
        if (! $j) {
            return;
        }
        $e = $j->entries()->create(['time' => now()->format('H:i'), 'sort' => (int) $j->entries()->max('sort') + 1]);
        $this->rows[] = ['id' => $e->id, 'time' => $e->time, 'task' => '', 'note' => ''];
        $this->touchSaved();
        $this->dispatch('wj-row-added');
    }

    /** 칸 하나가 바뀔 때마다 그 줄만 저장(자동 저장). key = "0.task" 형태. */
    public function updatedRows($value, $key): void
    {
        [$i, $field] = array_pad(explode('.', (string) $key, 2), 2, null);
        if (! in_array($field, ['time', 'task', 'note'], true) || ! isset($this->rows[$i]['id'])) {
            return;
        }
        $j = $this->writable();
        if (! $j) {
            $this->loadRows();

            return;
        }
        $max = ['time' => 5, 'task' => 2000, 'note' => 500][$field];
        $v = mb_substr(trim((string) $value), 0, $max);
        WorkJournalEntry::where('work_journal_id', $j->id)->whereKey($this->rows[$i]['id'])->update([$field => $v === '' ? null : $v]);
        $this->touchSaved();
    }

    public function stampNow(int $i): void
    {
        if (! isset($this->rows[$i])) {
            return;
        }
        $this->rows[$i]['time'] = now()->format('H:i');
        $this->updatedRows($this->rows[$i]['time'], $i.'.time');
    }

    public function deleteRow(int $id): void
    {
        $j = $this->writable();
        if (! $j) {
            return;
        }
        WorkJournalEntry::where('work_journal_id', $j->id)->whereKey($id)->delete();
        $this->rows = array_values(array_filter($this->rows, fn ($r) => $r['id'] !== $id));
        $this->touchSaved();
    }

    public function copyPrev(): void
    {
        $prev = WorkJournal::where('user_id', Auth::id())->whereDate('date', '<', $this->date)->orderByDesc('date')
            ->whereHas('entries')->first();
        if (! $prev) {
            session()->flash('wj_err', __('journal.nothing_to_copy'));

            return;
        }
        $j = $this->writable();
        if (! $j) {
            return;
        }
        $sort = (int) $j->entries()->max('sort');
        foreach ($prev->entries()->get() as $e) {
            $j->entries()->create(['time' => $e->time, 'task' => $e->task, 'note' => $e->note, 'sort' => ++$sort]);
        }
        $this->loadRows();
        $this->touchSaved();
        session()->flash('wj_ok', __('journal.copied'));
    }

    public function submit(): void
    {
        $j = $this->journal;
        if (! $j) {
            $this->addError('rows', __('journal.err.empty'));

            return;
        }
        try {
            $this->svc()->submit($j);
        } catch (\DomainException $e) {
            $this->addError('rows', __('journal.err.'.$e->getMessage()));

            return;
        }
        $this->loadRows();
        session()->flash('wj_ok', __('journal.submitted_flash'));
    }

    public function recall(): void
    {
        $j = $this->journal;
        try {
            abort_unless($j, 404);
            $this->svc()->recall($j);
        } catch (\DomainException $e) {
            $this->addError('rows', __('journal.err.'.$e->getMessage()));

            return;
        }
        $this->loadRows();
        session()->flash('wj_ok', __('journal.recalled_flash'));
    }

    private function touchSaved(): void
    {
        $this->savedAt = now()->format('H:i');
        unset($this->journal);
    }

    /** 상신 전 = 지금 감사 로그, 상신 뒤 = snapshot(고정). */
    #[Computed]
    public function activities(): array
    {
        $j = $this->journal;
        if ($j && ! $j->isDraft()) {
            return (array) data_get($j->snapshot, 'activities', []);
        }

        return $this->svc()->activitiesFor(Auth::user(), $this->date);
    }

    /** 결재 의견 — 나에게 달린 것 + 전체. 다른 사람에게 달린 의견은 안 보인다. */
    #[Computed]
    public function comments()
    {
        $j = $this->journal;
        if (! $j || ! $j->batch_id) {
            return collect();
        }

        return \App\Models\WorkJournalComment::with(['step', 'user'])->where('batch_id', $j->batch_id)
            ->where(fn ($q) => $q->where('work_journal_id', $j->id)->orWhereNull('work_journal_id'))->orderBy('id')->get();
    }

    public function canRecall(): bool
    {
        $j = $this->journal;

        return $j && $j->status === 'submitted' && $j->batch && ! $j->batch->steps()->where('status', 'approved')->exists();
    }
}; ?>

<div class="p-3 md:p-6">
    @php
        $j = $this->journal;
        $status = $j?->status ?? 'none';
        $locked = $j && ! $j->isDraft();
        $d = \Carbon\Carbon::parse($date);
        $svc = app(\App\Services\WorkJournalService::class);
        $shownRows = $locked ? (array) data_get($j->snapshot, 'entries', []) : $rows;
    @endphp
    <div class="mb-4">
        <h1 class="text-xl font-bold text-gray-800">{{ __('journal.title') }}</h1>
        <p class="mt-0.5 text-xs text-gray-500">{{ __('journal.subtitle') }}</p>
    </div>

    @if (session('wj_ok'))<div class="card-sm mb-3 border-green-200 bg-green-50 text-[13px] text-green-700">✓ {{ session('wj_ok') }}</div>@endif
    @if (session('wj_err'))<div class="card-sm mb-3 border-amber-200 bg-amber-50 text-[13px] text-amber-700">⚠ {{ session('wj_err') }}</div>@endif

    <div class="card">
        <div class="mb-3 flex flex-wrap items-center gap-2">
            <button type="button" class="btn-outline btn-sm" wire:click="goDate('{{ $d->copy()->subDay()->toDateString() }}')">{{ __('journal.prev_day') }}</button>
            <input type="date" class="input-filter" value="{{ $date }}" max="{{ today()->toDateString() }}" wire:change="goDate($event.target.value)">
            @if (! $d->isToday())
                <button type="button" class="btn-outline btn-sm" wire:click="goDate('{{ $d->copy()->addDay()->toDateString() }}')">{{ __('journal.next_day') }}</button>
                <button type="button" class="btn-ghost btn-sm" wire:click="goDate('{{ today()->toDateString() }}')">{{ __('journal.today') }}</button>
            @endif
            <span class="badge {{ ['draft' => 'badge-gray', 'submitted' => 'badge-blue', 'approved' => 'badge-green', 'none' => 'badge-gray'][$status] }}">{{ __('journal.status.'.$status) }}</span>
            @if ($j?->submitted_at)<span class="text-xs text-gray-500">{{ __('journal.submitted_at', ['time' => $j->submitted_at->format('H:i')]) }}</span>@endif
            <span class="ml-auto flex items-center gap-2">
                @if (! $locked)
                    @if ($savedAt)<span class="text-xs font-semibold text-green-600">{{ __('journal.saved', ['time' => $savedAt]) }}</span>@endif
                    <button type="button" class="btn-outline btn-sm" wire:click="copyPrev">{{ __('journal.copy_prev') }}</button>
                @elseif ($this->canRecall())
                    <button type="button" class="btn-outline btn-sm" wire:click="recall">{{ __('journal.recall') }}</button>
                @endif
            </span>
        </div>

        @if (! $locked && $d->isToday() && $svc->showUnsubmitted($d))
            <div class="mb-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-[13px] text-amber-800">{{ __('journal.cutoff_warn', ['time' => $svc->cutoff()]) }}</div>
        @endif
        @error('rows') <div class="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-[13px] text-red-700">⚠ {{ $message }}</div> @enderror

        <div class="overflow-x-auto">
            <table class="tbl">
                <thead><tr><th class="w-40">{{ __('journal.col_time') }}</th><th>{{ __('journal.col_task') }}</th><th class="w-1/4">{{ __('journal.col_note') }}</th>@unless ($locked)<th class="w-10"></th>@endunless</tr></thead>
                <tbody>
                    @forelse ($shownRows as $i => $r)
                        <tr wire:key="wj-row-{{ $r['id'] ?? $i }}">
                            @if ($locked)
                                <td class="whitespace-nowrap text-gray-700">{{ $r['time'] }}</td>
                                <td class="whitespace-pre-line text-gray-800">{{ $r['task'] }}</td>
                                <td>@if (($r['note'] ?? '') !== '')<span class="badge badge-amber whitespace-normal">{{ $r['note'] }}</span>@endif</td>
                            @else
                                <td class="whitespace-nowrap">
                                    <div class="flex items-center gap-1">
                                        <input type="text" class="input-base text-center" style="width:5.5rem" maxlength="5" placeholder="09:00" wire:model.live.debounce.600ms="rows.{{ $i }}.time">
                                        <button type="button" class="btn-outline btn-sm whitespace-nowrap" wire:click="stampNow({{ $i }})">{{ __('journal.now') }}</button>
                                    </div>
                                </td>
                                <td><textarea rows="1" class="input-base wj-task" placeholder="{{ __('journal.ph_task') }}" wire:model.live.debounce.600ms="rows.{{ $i }}.task"></textarea></td>
                                <td><textarea rows="1" class="input-base" placeholder="{{ __('journal.ph_note') }}" wire:model.live.debounce.600ms="rows.{{ $i }}.note"></textarea></td>
                                <td class="text-right"><button type="button" class="btn-ghost btn-sm" title="{{ __('journal.delete_row') }}" wire:click="deleteRow({{ $r['id'] }})">✕</button></td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-6 text-center text-gray-400">{{ __('journal.empty_rows') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @unless ($locked)
            <div class="mt-3 flex flex-wrap items-center gap-2">
                <button type="button" class="btn-outline btn-sm" wire:click="addRow">{{ __('journal.add_row') }}</button>
                <button type="button" class="btn-primary ml-auto" wire:click="submit" wire:confirm="{{ __('journal.submit_confirm') }}">{{ __('journal.submit') }}</button>
            </div>
        @else
            <div class="mt-3 rounded-md border border-blue-200 bg-blue-50 px-3 py-2 text-[13px] text-blue-700">{{ $status === 'approved' ? __('journal.status.approved') : ($this->canRecall() ? __('journal.locked_note') : __('journal.locked_note_progress')) }}</div>
        @endunless

        {{-- board 활동(자동) — 옮겨 적지 않아도 상신 때 그대로 같이 올라간다(Jin 2026-11-04) --}}
        <div class="mt-5 border-t-2 border-dashed border-gray-200 pt-4">
            <h3 class="text-sm font-bold text-gray-800">{{ $locked ? __('journal.activity_snapshot') : __('journal.activity_title') }}</h3>
            <p class="mb-2 mt-0.5 text-xs text-gray-500">{{ __('journal.activity_hint') }}</p>
            @include('livewire.work-journal._activities', ['acts' => $this->activities])
        </div>
    </div>

    @if ($this->comments->isNotEmpty())
        <div class="card mt-4">
            <h3 class="mb-2 text-sm font-bold text-gray-800">{{ __('journal.comments_title') }}</h3>
            @foreach ($this->comments as $c)
                <div class="mt-2 rounded-r-md border-l-4 border-violet-400 bg-violet-50 px-3 py-2 text-[13px]">
                    <div class="text-[11px] text-gray-500">{{ $c->step?->title }} {{ $c->user?->name }} · {{ $c->work_journal_id ? __('journal.comment_to_me') : __('journal.comment_all') }}</div>
                    <div class="whitespace-pre-line text-gray-800">{{ $c->body }}</div>
                </div>
            @endforeach
        </div>
    @endif

    <script>
        document.addEventListener('wj-row-added', () => setTimeout(() => { const t = document.querySelectorAll('textarea.wj-task'); t[t.length - 1]?.focus(); }, 50));
    </script>
</div>

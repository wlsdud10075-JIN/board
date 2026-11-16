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

    /** 화면 칸 — [{id,time,location,task,note}]. 저장은 칸 단위(updatedRows). */
    public array $rows = [];

    public ?string $savedAt = null;

    /** 내일 예정업무 · 특이사항(일지마다 글 칸, 자동 저장) — 해외영업부 엑셀 양식(2026-11-16 Jin). */
    public string $plan = '';

    public string $remarks = '';

    /** 고정 업무 추가 칸. */
    public int $rtWeekday = 1;

    public string $rtTime = '';

    public string $rtLocation = '';

    public string $rtTask = '';

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
        $this->rows = $j ? $j->entries()->get()->map(fn ($e) => ['id' => $e->id, 'time' => (string) $e->time, 'location' => (string) $e->location, 'task' => (string) $e->task, 'note' => (string) $e->note])->all() : [];
        $this->plan = (string) $j?->plan_tomorrow;
        $this->remarks = (string) $j?->remarks;
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
        // 위치는 바로 위 줄 값을 이어 쓴다(같은 곳에서 연달아 일하는 게 보통).
        $loc = $this->rows ? (string) end($this->rows)['location'] : '';
        $e = $j->entries()->create(['time' => now()->format('H:i'), 'location' => $loc ?: null, 'sort' => (int) $j->entries()->max('sort') + 1]);
        $this->rows[] = ['id' => $e->id, 'time' => $e->time, 'location' => $loc, 'task' => '', 'note' => ''];
        $this->touchSaved();
        $this->dispatch('wj-row-added');
    }

    /** 칸 하나가 바뀔 때마다 그 줄만 저장(자동 저장). key = "0.task" 형태. */
    public function updatedRows($value, $key): void
    {
        [$i, $field] = array_pad(explode('.', (string) $key, 2), 2, null);
        if (! in_array($field, ['time', 'location', 'task', 'note'], true) || ! isset($this->rows[$i]['id'])) {
            return;
        }
        $j = $this->writable();
        if (! $j) {
            $this->loadRows();

            return;
        }
        $max = ['time' => 5, 'location' => 40, 'task' => 2000, 'note' => 500][$field];
        $v = mb_substr(trim((string) $value), 0, $max);
        WorkJournalEntry::where('work_journal_id', $j->id)->whereKey($this->rows[$i]['id'])->update([$field => $v === '' ? null : $v]);
        $this->touchSaved();
    }

    /** 내일 예정업무·특이사항 자동 저장. */
    public function updatedPlan(): void
    {
        $this->saveText('plan_tomorrow', $this->plan);
    }

    public function updatedRemarks(): void
    {
        $this->saveText('remarks', $this->remarks);
    }

    private function saveText(string $col, string $v): void
    {
        $j = $this->writable();
        if (! $j) {
            $this->loadRows();

            return;
        }
        $v = mb_substr(trim($v), 0, 2000);
        $j->update([$col => $v === '' ? null : $v]);
        $this->touchSaved();
    }

    /** [하루 틀 만들기] — 빈 일지에 09~18시 10줄(+ 그 요일 고정 업무). */
    public function fillTemplate(): void
    {
        $j = $this->writable();
        if (! $j) {
            return;
        }
        $this->svc()->fillDayTemplate($j);
        $this->loadRows();
        $this->touchSaved();
    }

    public function addRoutine(): void
    {
        try {
            $this->svc()->addRoutine(Auth::user(), $this->rtWeekday, $this->rtTime, $this->rtLocation, $this->rtTask);
        } catch (\DomainException $e) {
            $this->addError('routine', __('journal.err.'.$e->getMessage()));

            return;
        }
        $this->reset(['rtTime', 'rtLocation', 'rtTask']);
        unset($this->routines);
    }

    public function deleteRoutine(int $id): void
    {
        $this->svc()->deleteRoutine(Auth::user(), $id);
        unset($this->routines);
    }

    /** 내 고정 업무 전부(요일·시간순). */
    #[Computed]
    public function routines()
    {
        return \App\Models\WorkJournalRoutine::where('user_id', Auth::id())->orderBy('weekday')->orderByRaw('time is null')->orderBy('time')->orderBy('id')->get();
    }

    /** 어제(이 날짜 전 마지막 일지)에 적은 「내일 예정업무」 — 오늘 화면 위에 보여 준다. */
    #[Computed]
    public function prevPlan(): ?string
    {
        $p = WorkJournal::where('user_id', Auth::id())->whereDate('date', '<', $this->date)->whereNotNull('plan_tomorrow')->orderByDesc('date')->first();

        return $p ? trim((string) $p->plan_tomorrow) ?: null : null;
    }

    /** 금일 계약 차량 — 상신 전 = 지금 감사 로그, 상신 뒤 = snapshot(고정). */
    #[Computed]
    public function contracts(): array
    {
        $j = $this->journal;
        if ($j && ! $j->isDraft()) {
            return (array) data_get($j->snapshot, 'contracts', ['rows' => [], 'count' => 0, 'by_currency' => []]);
        }

        return $this->svc()->contractsFor(Auth::user(), $this->date);
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
            $j->entries()->create(['time' => $e->time, 'location' => $e->location, 'task' => $e->task, 'note' => $e->note, 'sort' => ++$sort]);
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
            <span class="badge {{ ['draft' => 'badge-gray', 'submitted' => 'badge-blue', 'approved' => 'badge-green', 'rejected' => 'badge-red', 'none' => 'badge-gray'][$status] }}">{{ __('journal.status.'.$status) }}</span>
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
        {{-- 팀장 반려 — 내용은 그대로, 사유를 보고 고쳐서 다시 상신(2026-11-04 Jin) --}}
        @if ($status === 'rejected')
            <div class="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-[13px] text-red-700">
                <b>{{ __('journal.rejected_title', ['name' => $j->rejecter?->name, 'time' => $j->rejected_at?->format('m-d H:i')]) }}</b>
                <div class="mt-0.5 whitespace-pre-line">{{ $j->reject_reason }}</div>
                <div class="mt-1 text-[12px] text-red-600">{{ __('journal.rejected_hint') }}</div>
            </div>
        @endif
        @error('rows') <div class="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-[13px] text-red-700">⚠ {{ $message }}</div> @enderror
        {{-- 어제 적은 「내일 예정업무」 = 오늘 할 일(2026-11-16 Jin, 엑셀 양식) --}}
        @if (! $locked && $this->prevPlan)
            <div class="mb-3 rounded-md border border-sky-200 bg-sky-50 px-3 py-2 text-[13px] text-sky-800">
                <b>{{ __('journal.prev_plan_title') }}</b>
                <div class="mt-0.5 whitespace-pre-line">{{ $this->prevPlan }}</div>
            </div>
        @endif

        {{-- 모바일 = 줄마다 카드(시간·✕ → 한 일 → 비고, 이름표), PC = 한 줄 4칸. 표(table)로 두면 폰에서 입력칸이 쪼그라든다(2026-11-04 Jin). --}}
        @if ($locked)
            @include('livewire.work-journal._entries', ['rows' => $shownRows])
        @else
            {{-- 2026-11-04 Jin: [지금] 버튼 없음(줄 추가 = 지금 시각, 시간 칸은 수정 가능) · 한 일·비고는 한 줄 높이로 시작해 글만큼 늘어난다.
                 PC = 머리글 + 한 줄 4칸 / 폰 = 줄마다 카드, 칸마다 작은 이름표(무슨 칸인지 보이게). --}}
            @php $grow = "\$el.style.height = 'auto'; \$el.style.height = \$el.scrollHeight + 'px'"; @endphp
            <div class="hidden gap-2 border-b border-gray-200 pb-1.5 text-[12px] font-semibold text-gray-500 sm:flex">
                <div class="w-20 shrink-0">{{ __('journal.col_time') }}</div><div class="w-28 shrink-0">{{ __('journal.col_location') }}</div><div class="flex-1">{{ __('journal.col_task') }}</div><div class="w-64 shrink-0">{{ __('journal.col_note') }}</div><div class="w-8 shrink-0"></div>
            </div>
            @forelse ($rows as $i => $r)
                <div class="mb-2 rounded-lg border border-gray-200 p-2.5 sm:mb-0 sm:flex sm:items-start sm:gap-2 sm:rounded-none sm:border-0 sm:border-b sm:border-gray-100 sm:px-0 sm:py-2" wire:key="wj-row-{{ $r['id'] }}">
                    <div class="flex items-center gap-2 sm:w-20 sm:shrink-0">
                        <span class="text-[11px] font-semibold text-gray-500 sm:hidden">{{ __('journal.col_time') }}</span>
                        <input type="text" class="input-base text-center" style="width:5rem" maxlength="5" inputmode="numeric" placeholder="09:00" wire:model.live.debounce.600ms="rows.{{ $i }}.time">
                        <button type="button" class="btn-ghost btn-sm ml-auto sm:hidden" title="{{ __('journal.delete_row') }}" wire:click="deleteRow({{ $r['id'] }})">✕</button>
                    </div>
                    <div class="mt-2 sm:mt-0 sm:w-28 sm:shrink-0">
                        <span class="mb-0.5 block text-[11px] font-semibold text-gray-500 sm:hidden">{{ __('journal.col_location') }}</span>
                        <input type="text" class="input-base" maxlength="40" list="wj-locations" placeholder="{{ __('journal.ph_location') }}" wire:model.live.debounce.600ms="rows.{{ $i }}.location">
                    </div>
                    <div class="mt-2 min-w-0 sm:mt-0 sm:flex-1">
                        <span class="mb-0.5 block text-[11px] font-semibold text-gray-500 sm:hidden">{{ __('journal.col_task') }}</span>
                        <textarea rows="1" class="input-base wj-task resize-none overflow-hidden" style="field-sizing:content; min-height:2.25rem" placeholder="{{ __('journal.ph_task') }}" wire:model.live.debounce.600ms="rows.{{ $i }}.task"
                                  x-data x-init="$nextTick(() => { {{ $grow }} })" x-on:input="{{ $grow }}"></textarea>
                    </div>
                    <div class="mt-2 sm:mt-0 sm:w-64 sm:shrink-0">
                        <span class="mb-0.5 block text-[11px] font-semibold text-gray-500 sm:hidden">{{ __('journal.col_note') }}</span>
                        <textarea rows="1" class="input-base resize-none overflow-hidden" style="field-sizing:content; min-height:2.25rem" placeholder="{{ __('journal.ph_note') }}" wire:model.live.debounce.600ms="rows.{{ $i }}.note"
                                  x-data x-init="$nextTick(() => { {{ $grow }} })" x-on:input="{{ $grow }}"></textarea>
                    </div>
                    <button type="button" class="btn-ghost btn-sm hidden w-8 shrink-0 sm:block" title="{{ __('journal.delete_row') }}" wire:click="deleteRow({{ $r['id'] }})">✕</button>
                </div>
            @empty
                <div class="py-6 text-center">
                    <p class="text-sm text-gray-400">{{ __('journal.empty_rows') }}</p>
                    <button type="button" class="btn-outline btn-sm mt-2" wire:click="fillTemplate">{{ __('journal.fill_template') }}</button>
                    <p class="mt-1 text-[11px] text-gray-400">{{ __('journal.fill_template_hint') }}</p>
                </div>
            @endforelse
            <datalist id="wj-locations">@foreach (\App\Services\WorkJournalService::LOCATIONS as $loc)<option value="{{ $loc }}"></option>@endforeach</datalist>
        @endif

        @unless ($locked)
            <div class="mt-3 flex flex-wrap items-center gap-2">
                <button type="button" class="btn-outline btn-sm" wire:click="addRow">{{ __('journal.add_row') }}</button>
                <button type="button" class="btn-primary ml-auto" wire:click="submit" wire:confirm="{{ __('journal.submit_confirm') }}">{{ __('journal.submit') }}</button>
            </div>
        @else
            <div class="mt-3 rounded-md border border-blue-200 bg-blue-50 px-3 py-2 text-[13px] text-blue-700">{{ $status === 'approved' ? __('journal.status.approved') : ($this->canRecall() ? __('journal.locked_note') : __('journal.locked_note_progress')) }}</div>
        @endunless

        {{-- 금일 계약 차량(자동) · 내일 예정업무 · 특이사항 — 해외영업부 엑셀 양식(2026-11-16 Jin) --}}
        <div class="mt-5 border-t-2 border-dashed border-gray-200 pt-4">
            <h3 class="text-sm font-bold text-gray-800">{{ __('journal.contracts_title') }}</h3>
            <p class="mb-2 mt-0.5 text-xs text-gray-500">{{ __('journal.contracts_hint') }}</p>
            @include('livewire.work-journal._contracts', ['c' => $this->contracts])
        </div>
        @php $snap = $locked ? (array) $j->snapshot : []; @endphp
        <div class="mt-4 grid gap-3 sm:grid-cols-2">
            <div>
                <h3 class="mb-1 text-sm font-bold text-gray-800">{{ __('journal.plan_title') }}</h3>
                @if ($locked)
                    <div class="min-h-[3rem] whitespace-pre-line rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-[13px] text-gray-800">{{ data_get($snap, 'plan_tomorrow') ?: '—' }}</div>
                @else
                    <textarea class="input-base" rows="3" maxlength="2000" placeholder="{{ __('journal.ph_plan') }}" wire:model.live.debounce.800ms="plan"></textarea>
                @endif
            </div>
            <div>
                <h3 class="mb-1 text-sm font-bold text-gray-800">{{ __('journal.remarks_title') }}</h3>
                @if ($locked)
                    <div class="min-h-[3rem] whitespace-pre-line rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-[13px] text-gray-800">{{ data_get($snap, 'remarks') ?: '—' }}</div>
                @else
                    <textarea class="input-base" rows="3" maxlength="2000" placeholder="{{ __('journal.ph_remarks') }}" wire:model.live.debounce.800ms="remarks"></textarea>
                @endif
            </div>
        </div>

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

    {{-- 고정 업무 — 요일마다 반복하는 일(예: 수 09:00 경매장 AJ 검수). 그 요일 [하루 틀 만들기] 때 줄·특이사항에 미리 채운다(2026-11-16 Jin). --}}
    <details class="card mt-4" @if ($errors->has('routine')) open @endif>
        <summary class="cursor-pointer text-sm font-bold text-gray-800">{{ __('journal.routine_title') }} <span class="text-xs font-normal text-gray-500">· {{ __('journal.routine_hint') }}</span></summary>
        <div class="mt-3">
            @forelse ($this->routines as $rt)
                <div class="flex items-center gap-2 border-b border-gray-100 py-1.5 text-[13px]" wire:key="rt-{{ $rt->id }}">
                    <span class="badge badge-gray">{{ __('journal.weekday.'.$rt->weekday) }}</span>
                    <span class="w-12 text-gray-500">{{ $rt->time ?: '—' }}</span>
                    @if ($rt->location)<span class="badge badge-blue">{{ $rt->location }}</span>@endif
                    <span class="flex-1 text-gray-800">{{ $rt->task }}</span>
                    <button type="button" class="btn-ghost btn-sm" wire:click="deleteRoutine({{ $rt->id }})" title="{{ __('journal.delete_row') }}">✕</button>
                </div>
            @empty
                <p class="py-2 text-[13px] text-gray-400">{{ __('journal.routine_empty') }}</p>
            @endforelse
            <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-[6rem_6rem_8rem_1fr_auto]">
                <select class="input-base" wire:model="rtWeekday">@foreach (\App\Models\WorkJournalRoutine::WEEKDAYS as $w)<option value="{{ $w }}">{{ __('journal.weekday.'.$w) }}</option>@endforeach</select>
                <input type="text" class="input-base" maxlength="5" placeholder="09:00" wire:model="rtTime">
                <input type="text" class="input-base" maxlength="40" list="wj-locations" placeholder="{{ __('journal.ph_location') }}" wire:model="rtLocation">
                <input type="text" class="input-base col-span-2 sm:col-span-1" maxlength="500" placeholder="{{ __('journal.ph_routine_task') }}" wire:model="rtTask">
                <button type="button" class="btn-primary btn-sm col-span-2 justify-center sm:col-span-1" wire:click="addRoutine">{{ __('journal.routine_add') }}</button>
            </div>
            @error('routine') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
    </details>

    <script>
        document.addEventListener('wj-row-added', () => setTimeout(() => { const t = document.querySelectorAll('textarea.wj-task'); t[t.length - 1]?.focus(); }, 50));
    </script>
</div>

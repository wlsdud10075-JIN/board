<?php

use App\Models\WorkJournal;
use App\Models\WorkJournalBatch;
use App\Services\WorkJournalService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * 업무일지결재 — 2026-11-04 Jin. 결재 직급이 있는 사람(팀장·부장·상무·대표)과 관리자 등급(조회)이 본다.
 * 날짜별 묶음(기안) → 결재선 띠 · 상신/미상신 · 인원별 접기·펼치기(snapshot) · 인원별·전체 의견 · [승인]. 반려 없음.
 * 🚨 승인 가능 판정은 서버(`WorkJournalService::canApprove` — 지금 단계·그 사람)가 다시 본다.
 */
new #[Layout('components.layouts.app')] class extends Component {
    #[Url]
    public ?int $sel = null;

    /** 내 인원별 의견 — [journal_id => 내용] */
    public array $opinions = [];

    public string $overall = '';

    public function mount(): void
    {
        $u = Auth::user();
        abort_unless($u->isJournalApprover() || $u->hasFullAccess(), 403, __('journal.ap_forbidden'));
        if (! $this->sel || ! WorkJournalBatch::whereKey($this->sel)->exists()) {
            $this->sel = $this->defaultBatchId();
        }
    }

    private function svc(): WorkJournalService
    {
        return app(WorkJournalService::class);
    }

    /** 처음 열면 — 내 차례인 기안 → 없으면 가장 최근 기안. */
    private function defaultBatchId(): ?int
    {
        $mine = $this->batches->first(fn ($b) => $this->svc()->canApprove($b, Auth::user()));

        return ($mine ?? $this->batches->first())?->id;
    }

    #[Computed]
    public function batches()
    {
        return WorkJournalBatch::with('steps.approver')->withCount('journals')
            ->whereDate('date', '>=', today()->subDays(60)->toDateString())
            ->orderByDesc('date')->orderByDesc('seq')->get();
    }

    #[Computed]
    public function batch(): ?WorkJournalBatch
    {
        return $this->sel ? WorkJournalBatch::with(['steps.approver', 'journals.user', 'comments.user', 'comments.step'])->find($this->sel) : null;
    }

    public function pick(int $id): void
    {
        $this->sel = $id;
        $this->reset('opinions', 'overall');
        unset($this->batch);
    }

    /** 미상신 작성자(마감 지남·평일만) — 그날 첫 기안에만 보인다(추가 기안은 늦게 올린 사람 묶음). */
    #[Computed]
    public function missing()
    {
        $b = $this->batch;
        if (! $b || $b->seq !== 1 || ! $this->svc()->showUnsubmitted($b->date)) {
            return collect();
        }
        $done = WorkJournal::whereDate('date', $b->date->toDateString())->whereIn('status', ['submitted', 'approved'])->pluck('user_id')->all();

        return $this->svc()->writers()->reject(fn ($u) => in_array($u->id, $done, true));
    }

    public function approve(): void
    {
        $b = $this->batch;
        abort_unless($b, 404);
        $final = $b->steps->filter(fn ($s) => $s->status === 'pending')->count() <= 1;
        try {
            $this->svc()->approve($b, Auth::user(), $this->opinions, $this->overall);
        } catch (\DomainException $e) {
            $this->addError('approve', __('journal.err.'.$e->getMessage()));

            return;
        }
        $this->reset('opinions', 'overall');
        unset($this->batch, $this->batches);
        session()->flash('ap_ok', $final ? __('journal.ap_final_flash') : __('journal.ap_approved_flash'));
    }

    public function dow($date): string
    {
        return __('journal.dow.'.\Carbon\Carbon::parse($date)->dayOfWeek);
    }
}; ?>

<div class="p-3 md:p-6">
    <div class="mb-4">
        <h1 class="text-xl font-bold text-gray-800">{{ __('journal.ap_title') }}</h1>
        <p class="mt-0.5 text-xs text-gray-500">{{ __('journal.ap_subtitle') }}</p>
    </div>
    @if (session('ap_ok'))<div class="card-sm mb-3 border-green-200 bg-green-50 text-[13px] text-green-700">✓ {{ session('ap_ok') }}</div>@endif

    @php $svc = app(\App\Services\WorkJournalService::class); $me = auth()->user(); @endphp
    <div class="grid gap-4 lg:grid-cols-[280px_1fr]">
        {{-- 기안 목록 --}}
        <div>
            @forelse ($this->batches as $bt)
                @php $cur = $bt->currentStep(); $mine = $svc->canApprove($bt, $me); @endphp
                <button type="button" wire:click="pick({{ $bt->id }})" wire:key="bt-{{ $bt->id }}"
                        class="mb-2 block w-full rounded-lg border bg-white px-3 py-2.5 text-left {{ $sel === $bt->id ? 'border-[var(--color-primary)] ring-2 ring-violet-100' : 'border-gray-200' }}">
                    <div class="text-sm font-bold text-gray-800">{{ __('journal.ap_batch', ['date' => $bt->date->toDateString(), 'dow' => $this->dow($bt->date)]) }}@if ($bt->seq > 1) <span class="badge badge-gray">{{ __('journal.ap_batch_extra', ['seq' => $bt->seq]) }}</span>@endif</div>
                    <div class="mt-1 flex flex-wrap items-center gap-1.5 text-xs">
                        @if ($cur)<span class="badge badge-amber">{{ __('journal.ap_turn', ['title' => $cur->title]) }}</span>@else<span class="badge badge-green">{{ __('journal.ap_done') }}</span>@endif
                        <span class="text-gray-500">{{ __('journal.ap_count', ['n' => $bt->journals_count]) }}</span>
                        @if ($mine)<span class="badge badge-red">{{ __('journal.ap_mine') }}</span>@endif
                    </div>
                </button>
            @empty
                <div class="card-sm text-center text-sm text-gray-400">{{ __('journal.ap_list_empty') }}</div>
            @endforelse
        </div>

        {{-- 기안 상세 --}}
        <div>
            @if ($b = $this->batch)
                @php $cur = $b->currentStep(); $canApprove = $svc->canApprove($b, $me); $journals = $b->journals->sortBy(fn ($j) => $j->submitted_at); @endphp
                <div class="card">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h3 class="text-base font-bold text-gray-800">{{ __('journal.ap_batch', ['date' => $b->date->toDateString(), 'dow' => $this->dow($b->date)]) }}@if ($b->seq > 1) · {{ __('journal.ap_batch_extra', ['seq' => $b->seq]) }}@endif</h3>
                        @if ($cur)<span class="badge badge-amber">{{ __('journal.ap_now_turn', ['title' => $cur->title, 'name' => $cur->approver?->name]) }}</span>@else<span class="badge badge-green">{{ __('journal.ap_done') }}</span>@endif
                    </div>

                    {{-- 결재선 띠 --}}
                    <div class="my-3 flex flex-wrap items-center gap-1.5">
                        <div class="rounded-lg border border-green-200 bg-green-50 px-3 py-1.5"><div class="text-[11px] text-gray-500">{{ __('journal.ap_draft') }}</div><div class="text-sm font-bold">{{ __('journal.ap_draft_who') }}</div></div>
                        @foreach ($b->steps as $st)
                            <span class="text-gray-400">→</span>
                            <div class="rounded-lg border px-3 py-1.5 {{ $st->status === 'approved' ? 'border-green-200 bg-green-50' : ($st->status === 'skipped' ? 'border-gray-200 bg-gray-50 opacity-60' : ($cur && $cur->id === $st->id ? 'border-amber-200 bg-amber-50' : 'border-gray-200 bg-white')) }}">
                                <div class="text-[11px] text-gray-500">{{ $st->title }}</div>
                                <div class="text-sm font-bold {{ $st->status === 'skipped' ? 'line-through' : '' }}">{{ $st->approver?->name ?? __('journal.ap_skipped') }}</div>
                                <div class="text-[11px] text-gray-500">{{ $st->status === 'approved' ? __('journal.ap_approved_at', ['time' => $st->acted_at?->format('m-d H:i')]) : ($cur && $cur->id === $st->id ? __('journal.ap_waiting') : '') }}</div>
                            </div>
                        @endforeach
                    </div>

                    <div class="mb-3 flex flex-wrap gap-2 text-sm">
                        <span class="rounded-md bg-gray-50 px-3 py-1">{{ __('journal.ap_stat_submitted', ['n' => $journals->count()]) }}</span>
                        @if ($b->seq === 1 && $svc->showUnsubmitted($b->date))<span class="rounded-md bg-gray-50 px-3 py-1">{{ __('journal.ap_stat_missing', ['n' => $this->missing->count()]) }}</span>@endif
                        <span class="rounded-md bg-gray-50 px-3 py-1">{{ __('journal.ap_stat_cutoff', ['time' => $svc->cutoff()]) }}</span>
                    </div>
                    @if ($this->missing->isNotEmpty() && $b->status === 'pending')
                        <div class="mb-3 rounded-md bg-red-50 px-3 py-2 text-[13px] text-red-700">{{ __('journal.ap_missing', ['names' => $this->missing->pluck('name')->implode(', ')]) }}</div>
                    @endif

                    <div class="mb-2 flex gap-2" x-data>
                        <button type="button" class="btn-outline btn-sm" x-on:click="document.querySelectorAll('details.wj-p').forEach(d => d.open = true)">{{ __('journal.ap_expand_all') }}</button>
                        <button type="button" class="btn-outline btn-sm" x-on:click="document.querySelectorAll('details.wj-p').forEach(d => d.open = false)">{{ __('journal.ap_collapse_all') }}</button>
                    </div>

                    {{-- 인원별 카드 — snapshot 만 그린다(상신 뒤 바뀐 것은 결재 내용에 안 들어간다) --}}
                    @foreach ($journals as $jr)
                        @php $prev = $b->comments->where('work_journal_id', $jr->id); $snapRows = (array) data_get($jr->snapshot, 'entries', []); @endphp
                        {{-- wire:ignore.self — 의견을 칠 때 다시 그려져도 펼친 상태(open)를 유지 --}}
                        <details class="wj-p mb-2 rounded-xl border border-gray-200 bg-white" wire:key="jr-{{ $jr->id }}" wire:ignore.self>
                            <summary class="flex cursor-pointer flex-wrap items-center gap-2 px-4 py-2.5">
                                <b class="text-gray-800">{{ $jr->user?->name }}</b>
                                <span class="badge badge-gray">{{ $jr->user?->roleLabel() }}</span>
                                <span class="text-xs text-gray-500">{{ __('journal.ap_rows', ['n' => count($snapRows)]) }} · {{ __('journal.submitted_at', ['time' => $jr->submitted_at?->format('H:i')]) }}</span>
                                @if ($prev->isNotEmpty())<span class="badge badge-purple">💬 {{ $prev->count() }}</span>@endif
                            </summary>
                            <div class="px-4 pb-4">
                                <table class="tbl text-[13px]">
                                    <thead><tr><th class="w-16">{{ __('journal.col_time') }}</th><th>{{ __('journal.col_task') }}</th><th class="w-1/4">{{ __('journal.col_note') }}</th></tr></thead>
                                    <tbody>
                                        @foreach ($snapRows as $r)
                                            <tr><td class="whitespace-nowrap">{{ $r['time'] ?? '' }}</td><td class="whitespace-pre-line">{{ $r['task'] ?? '' }}</td><td>@if (($r['note'] ?? '') !== '')<span class="badge badge-amber whitespace-normal">{{ $r['note'] }}</span>@endif</td></tr>
                                        @endforeach
                                    </tbody>
                                </table>
                                <div class="mt-3 border-t border-dashed border-gray-200 pt-2">
                                    <div class="mb-1 text-xs font-bold text-gray-700">{{ __('journal.activity_snapshot') }}</div>
                                    @include('livewire.work-journal._activities', ['acts' => (array) data_get($jr->snapshot, 'activities', [])])
                                </div>
                                @foreach ($prev as $c)
                                    <div class="mt-2 rounded-r-md border-l-4 border-violet-400 bg-violet-50 px-3 py-1.5 text-[13px]"><div class="text-[11px] text-gray-500">{{ $c->step?->title }} {{ $c->user?->name }}</div><div class="whitespace-pre-line">{{ $c->body }}</div></div>
                                @endforeach
                                @if ($canApprove)
                                    <textarea class="input-base mt-2" rows="2" placeholder="{{ __('journal.ap_opinion_ph', ['name' => $jr->user?->name]) }}" wire:model="opinions.{{ $jr->id }}"></textarea>
                                @endif
                            </div>
                        </details>
                    @endforeach

                    <h3 class="mt-4 text-sm font-bold text-gray-800">{{ __('journal.ap_overall') }}</h3>
                    @forelse ($b->comments->whereNull('work_journal_id') as $c)
                        <div class="mt-2 rounded-r-md border-l-4 border-violet-400 bg-violet-50 px-3 py-1.5 text-[13px]"><div class="text-[11px] text-gray-500">{{ $c->step?->title }} {{ $c->user?->name }}</div><div class="whitespace-pre-line">{{ $c->body }}</div></div>
                    @empty
                        <p class="text-xs text-gray-400">{{ __('journal.ap_none_yet') }}</p>
                    @endforelse

                    @error('approve') <div class="mt-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-[13px] text-red-700">⚠ {{ $message }}</div> @enderror
                    @if ($canApprove)
                        @php $next = $b->steps->first(fn ($s) => $s->seq > $cur->seq && $s->status === 'pending'); @endphp
                        <textarea class="input-base mt-2" rows="2" placeholder="{{ __('journal.ap_overall_ph') }}" wire:model="overall"></textarea>
                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            <span class="text-xs text-gray-500">{{ __('journal.ap_no_reject') }}</span>
                            <button type="button" class="btn-green ml-auto" wire:click="approve">{{ __('journal.ap_approve', ['next' => $next ? __('journal.ap_next_to', ['title' => $next->title]) : __('journal.ap_final')]) }}</button>
                        </div>
                    @endif
                </div>
            @endif
        </div>
    </div>
</div>

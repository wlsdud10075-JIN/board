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

    /**
     * 탭(2026-11-04 Jin — 지난 날·오늘 기안이 섞여 헷갈린다): mine = 지금 내 차례 / open = 진행 중이지만 다른 사람 차례 / done = 최종 승인.
     * 결재자는 mine 으로, 관리자 등급(조회)은 open 으로 연다. 세 탭은 겹치지 않는다.
     */
    #[Url]
    public string $tab = '';

    /** 완료 탭 월(Y-m) — 기본 이번 달. */
    #[Url]
    public string $month = '';

    /** 내 인원별 의견 — [journal_id => 내용] */
    public array $opinions = [];

    public string $overall = '';

    /** 반려 모달(2026-11-04 Jin — 빨간 [반려] → 사유 모달). 반려는 팀장만·팀장 승인 전에만. */
    public bool $rejectModal = false;

    public string $rejectReason = '';

    /** 반려할 때 돌려보낼 사람(일지 id 문자열) — 안 골라도 된다(기안만 첫 결재자에게). 첫 단계에서는 한 명 이상. */
    public array $rejectPick = [];

    /** 결재선 모달(2026-11-16 Jin — 클레임 [결재 요청] 과 같은 창). 팀장 승인 때 부장·상무·대표 중 누구에게 올릴지 고른다. */
    public bool $lineModal = false;

    /** 직급 => user id(빈 값 = 건너뜀). */
    public array $line = [];

    public ?string $lineError = null;

    public function mount(): void
    {
        $u = Auth::user();
        abort_unless($u->isJournalApprover() || $u->hasFullAccess(), 403, __('journal.ap_forbidden'));
        if (! in_array($this->tab, ['mine', 'open', 'done', 'rejected'], true)) {
            $this->tab = $u->isJournalApprover() ? 'mine' : 'open';
        }
        if (! preg_match('/^\d{4}-\d{2}$/', $this->month)) {
            $this->month = today()->format('Y-m');
        }
        if (! $this->sel || ! $this->batches->contains('id', $this->sel)) {
            $this->sel = $this->batches->first()?->id;
        }
    }

    public function setTab(string $t): void
    {
        if (! in_array($t, ['mine', 'open', 'done', 'rejected'], true)) {
            return;
        }
        $this->tab = $t;
        $this->reset('opinions', 'overall');
        unset($this->batches, $this->batch);
        $this->sel = $this->batches->first()?->id;
    }

    public function updatedMonth(): void
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $this->month)) {
            $this->month = today()->format('Y-m');
        }
        unset($this->batches, $this->batch, $this->rejections);
        $this->sel = $this->batches->first()?->id;
    }

    /**
     * 「반려」 탭(2026-11-19 영업팀장 요청) — 그 달 반려 기록(기안 전체 줄). 팀장 선에서 반려돼 위 결재자에게 안 올라간 것도
     * 결재 화면을 보는 사람(결재자·전체 접근 등급)은 전부 본다. 작성자는 자기 일지 화면의 「결재 이력」·「내 반려 이력」으로 본다.
     */
    #[Computed]
    public function rejections()
    {
        $from = \Carbon\Carbon::parse($this->month.'-01');

        return \App\Models\WorkJournalNote::where('kind', 'reject')->whereNull('work_journal_id')
            ->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $from->copy()->endOfMonth()->toDateString())
            ->orderByDesc('id')->get();
    }

    /** 탭 숫자 — 결재할 것 · 진행 중(완료는 개수 대신 월). */
    #[Computed]
    public function counts(): array
    {
        $pending = WorkJournalBatch::with('steps')->where('status', 'pending')->get();
        $mine = $pending->filter(fn ($b) => $this->svc()->canApprove($b, Auth::user()))->count();

        return ['mine' => $mine, 'open' => $pending->count() - $mine];
    }

    private function svc(): WorkJournalService
    {
        return app(WorkJournalService::class);
    }

    #[Computed]
    public function batches()
    {
        $me = Auth::user();
        if ($this->tab === 'rejected') {
            return collect();
        }
        if ($this->tab === 'done') {
            $from = \Carbon\Carbon::parse($this->month.'-01');

            return WorkJournalBatch::with('steps.approver')->withCount('journals')->where('status', 'approved')
                ->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $from->copy()->endOfMonth()->toDateString())
                ->orderByDesc('date')->orderByDesc('seq')->get();
        }
        $pending = WorkJournalBatch::with('steps.approver')->withCount('journals')->where('status', 'pending')
            ->orderBy('date')->orderBy('seq')->get();   // 진행 중은 오래된 것부터 — 밀린 것이 위에

        return $pending->filter(fn ($b) => $this->svc()->canApprove($b, $me) === ($this->tab === 'mine'))->values();
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

    /** 팀장 [결재선 지정 · 승인] — 모달 열기(기본값 = 이 기안에 깔린 결재선). */
    public function openLineModal(): void
    {
        $b = $this->batch;
        abort_unless($b, 404);
        $this->line = array_map(fn ($id) => $id ? (string) $id : '', $this->svc()->defaultLine($b));
        $this->lineError = null;
        $this->lineModal = true;
    }

    public function closeLineModal(): void
    {
        $this->lineModal = false;
        $this->lineError = null;
    }

    public function approve(): void
    {
        $b = $this->batch;
        abort_unless($b, 404);
        $atLeader = $b->currentStep()?->title === \App\Models\User::TEAM_LEADER;
        $final = ! $atLeader && $b->steps->filter(fn ($s) => $s->status === 'pending')->count() <= 1;
        try {
            $this->svc()->approve($b, Auth::user(), $this->opinions, $this->overall, $atLeader ? $this->line : null);
        } catch (\DomainException $e) {
            if ($this->lineModal) {
                $this->lineError = __('journal.err.'.$e->getMessage());

                return;
            }
            $this->addError('approve', __('journal.err.'.$e->getMessage()));

            return;
        }
        $this->lineModal = false;
        $this->reset('opinions', 'overall', 'line');
        unset($this->batch, $this->batches, $this->counts);
        $this->sel = $this->batches->first()?->id;   // 처리한 기안은 「결재할 것」에서 빠진다 → 다음 것
        session()->flash('ap_ok', $final ? __('journal.ap_final_flash') : __('journal.ap_approved_flash'));
    }

    /** [반려] — 기안 전체 반려 창(사유 필수 + 돌려보낼 사람 선택, 2026-11-19). */
    public function openReject(): void
    {
        $this->rejectModal = true;
        $this->rejectReason = '';
        $this->rejectPick = [];
        $this->resetErrorBag('rejectReason');
    }

    public function closeReject(): void
    {
        $this->reset('rejectModal', 'rejectReason', 'rejectPick');
        $this->resetErrorBag('rejectReason');
    }

    public function rejectBatch(): void
    {
        $b = $this->batch;
        abort_unless($b, 404);
        $this->resetErrorBag('rejectReason');
        $names = $b->journals->whereIn('id', array_map('intval', $this->rejectPick))->map(fn ($j) => $j->user?->name)->filter()->implode(', ');
        try {
            $this->svc()->reject($b, Auth::user(), $this->rejectReason, array_map('intval', $this->rejectPick));
        } catch (\DomainException $e) {
            $this->addError('rejectReason', __('journal.err.'.$e->getMessage()));

            return;
        }
        $this->reset('rejectModal', 'rejectReason', 'rejectPick', 'opinions');
        unset($this->batch, $this->batches, $this->counts);
        if (! $this->batches->contains('id', $this->sel)) {
            $this->sel = $this->batches->first()?->id;   // 내 차례에서 빠졌거나 기안이 사라졌으면 다음 것
        }
        session()->flash('ap_ok', $names !== '' ? __('journal.ap_rejected_flash', ['name' => $names]) : __('journal.ap_rejected_flash_all'));
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
        {{-- 기안 목록 — 탭(결재할 것 · 진행 중 · 완료) --}}
        <div>
            <div class="mb-3 flex flex-wrap gap-1">
                @foreach (['mine', 'open', 'done', 'rejected'] as $t)
                    <button type="button" wire:click="setTab('{{ $t }}')"
                            class="whitespace-nowrap rounded-md border px-2 py-1.5 text-[13px] font-semibold {{ $tab === $t ? 'border-[var(--color-primary)] bg-[var(--color-primary)] text-white' : 'border-gray-300 bg-white text-gray-600' }}">
                        {{ __('journal.ap_tab.'.$t) }}@if (in_array($t, ['mine', 'open'], true)) <span class="{{ $tab === $t ? 'text-white/80' : ($t === 'mine' && $this->counts['mine'] > 0 ? 'text-red-600' : 'text-gray-400') }}">{{ $this->counts[$t] }}</span>@endif
                    </button>
                @endforeach
            </div>
            @if (in_array($tab, ['done', 'rejected'], true))
                <input type="month" class="input-filter mb-3 w-full" wire:model.live="month" max="{{ today()->format('Y-m') }}">
            @endif
            @if ($tab === 'rejected')
                <div class="card-sm text-center text-sm text-gray-500">{{ __('journal.ap_rejected_count', ['n' => $this->rejections->count()]) }}</div>
            @else
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
                <div class="card-sm text-center text-sm text-gray-400">{{ __('journal.ap_empty.'.$tab) }}</div>
            @endforelse
            @endif
        </div>

        {{-- 기안 상세 --}}
        <div>
            @if ($tab === 'rejected')
                <div class="card">
                    <h3 class="text-base font-bold text-gray-800">{{ __('journal.ap_rejected_title', ['month' => $month]) }}</h3>
                    <p class="mt-0.5 text-xs text-gray-500">{{ __('journal.ap_rejected_hint') }}</p>
                    @php $states = \App\Models\WorkJournalBatch::with('steps')->whereIn('date', $this->rejections->pluck('date')->map->toDateString()->unique()->all())->get()->keyBy(fn ($x) => $x->date->toDateString().'|'.$x->seq); @endphp
                    @forelse ($this->rejections as $rn)
                        @php $st = $states->get($rn->date->toDateString().'|'.$rn->batch_seq); $stCur = $st?->currentStep(); @endphp
                        <div class="mt-3 rounded-r-md border-l-4 border-red-400 bg-red-50 px-3 py-2 text-[13px]" wire:key="rjn-{{ $rn->id }}">
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                <b class="text-gray-800">{{ __('journal.ap_batch', ['date' => $rn->date->toDateString(), 'dow' => $this->dow($rn->date)]) }}</b>
                                @if (! $st)<span class="badge badge-gray">{{ __('journal.ap_rejected_state_none') }}</span>
                                @elseif ($st->status === 'approved')<span class="badge badge-green">{{ __('journal.ap_done') }}</span>
                                @elseif ($stCur)<span class="badge badge-amber">{{ __('journal.ap_turn', ['title' => $stCur->title]) }}</span>@endif
                            </div>
                            <div class="mt-0.5 text-[11px] text-gray-500">{{ $rn->title }} {{ $rn->user_name }} · {{ $rn->created_at?->format('m-d H:i') }} · {{ $rn->targets ? __('journal.note_targets', ['names' => $rn->targets]) : __('journal.note_targets_none') }}</div>
                            <div class="mt-0.5 whitespace-pre-line text-gray-800">{{ $rn->body }}</div>
                        </div>
                    @empty
                        <p class="mt-3 text-sm text-gray-400">{{ __('journal.ap_empty.rejected') }}</p>
                    @endforelse
                </div>
            @elseif ($b = $this->batch)
                @php $cur = $b->currentStep(); $canApprove = $svc->canApprove($b, $me); $canReject = $svc->canReject($b, $me); $journals = $b->journals->sortBy(fn ($j) => $j->submitted_at); @endphp
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
                    @if ($cur && $cur->title === \App\Models\User::TEAM_LEADER)
                        <p class="-mt-1 mb-3 text-xs text-gray-500">{{ __('journal.ap_line_note') }}</p>
                    @endif

                    <div class="mb-3 flex flex-wrap gap-2 text-sm">
                        <span class="rounded-md bg-gray-50 px-3 py-1">{{ __('journal.ap_stat_submitted', ['n' => $journals->count()]) }}</span>
                        @if ($b->seq === 1 && $svc->showUnsubmitted($b->date))<span class="rounded-md bg-gray-50 px-3 py-1">{{ __('journal.ap_stat_missing', ['n' => $this->missing->count()]) }}</span>@endif
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
                        @php $prev = $b->comments->where('work_journal_id', $jr->id); $snapRows = (array) data_get($jr->snapshot, 'entries', []); $notes = $svc->notesFor($jr->id); $said = $prev->count() + $notes->where('kind', '!=', 'submit')->count(); @endphp
                        {{-- wire:ignore.self — 의견을 칠 때 다시 그려져도 펼친 상태(open)를 유지 --}}
                        <details class="wj-p mb-2 rounded-xl border border-gray-200 bg-white" wire:key="jr-{{ $jr->id }}" wire:ignore.self>
                            <summary class="flex cursor-pointer flex-wrap items-center gap-2 px-3 py-2.5 sm:px-4">
                                <b class="text-gray-800">{{ $jr->user?->name }}</b>
                                <span class="badge badge-gray">{{ $jr->user?->roleLabel() }}</span>
                                <span class="text-xs text-gray-500">{{ __('journal.ap_rows', ['n' => count($snapRows)]) }} · {{ __('journal.submitted_at', ['time' => $jr->submitted_at?->format('H:i')]) }}</span>
                                <a class="text-xs text-blue-700 underline" href="{{ route('work-journal.excel', $jr) }}" x-data x-on:click.stop>{{ __('journal.excel_download') }}</a>
                                @if ($said > 0)<span class="badge badge-purple">💬 {{ $said }}</span>@endif
                                @if ($notes->contains('kind', 'reject'))<span class="badge badge-red">{{ __('journal.ap_was_rejected', ['n' => $notes->where('kind', 'reject')->count()]) }}</span>@endif
                            </summary>
                            <div class="px-3 pb-4 sm:px-4">
                                @include('livewire.work-journal._entries', ['rows' => $snapRows])
                                {{-- 금일 계약 차량 · 내일 예정업무 · 특이사항(2026-11-16 엑셀 양식 — 그 전 snapshot 엔 없어 안 그린다) --}}
                                @if (array_key_exists('contracts', (array) $jr->snapshot))
                                    <div class="mt-3 border-t border-dashed border-gray-200 pt-2">
                                        <div class="mb-1 text-xs font-bold text-gray-700">{{ __('journal.contracts_title') }}</div>
                                        @include('livewire.work-journal._contracts', ['c' => (array) data_get($jr->snapshot, 'contracts')])
                                    </div>
                                    <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                        <div><div class="text-xs font-bold text-gray-700">{{ __('journal.plan_title') }}</div><div class="whitespace-pre-line text-[13px] text-gray-800">{{ data_get($jr->snapshot, 'plan_tomorrow') ?: '—' }}</div></div>
                                        <div><div class="text-xs font-bold text-gray-700">{{ __('journal.remarks_title') }}</div><div class="whitespace-pre-line text-[13px] text-gray-800">{{ data_get($jr->snapshot, 'remarks') ?: '—' }}</div></div>
                                    </div>
                                @endif
                                <div class="mt-3 border-t border-dashed border-gray-200 pt-2">
                                    <div class="mb-1 text-xs font-bold text-gray-700">{{ __('journal.activity_snapshot') }}</div>
                                    @include('livewire.work-journal._activities', ['acts' => (array) data_get($jr->snapshot, 'activities', [])])
                                </div>
                                @foreach ($prev as $c)
                                    <div class="mt-2 rounded-r-md border-l-4 border-violet-400 bg-violet-50 px-3 py-1.5 text-[13px]"><div class="text-[11px] text-gray-500">{{ $c->step?->title }} {{ $c->user?->name }}</div><div class="whitespace-pre-line">{{ $c->body }}</div></div>
                                @endforeach
                                {{-- 이력 — 상신 의견·결재의견·반려(재상신·재결재를 해도 남는다, 2026-11-19) --}}
                                <div class="mt-3 border-t border-dashed border-gray-200 pt-2">
                                    <div class="text-xs font-bold text-gray-700">{{ __('journal.thread_title') }}</div>
                                    @include('livewire.work-journal._thread', ['notes' => $notes])
                                </div>
                                @if ($canApprove)
                                    <label class="mt-3 block text-xs font-bold text-gray-700">{{ __('journal.ap_opinion_label') }}</label>
                                    <textarea class="input-base mt-1" rows="2" placeholder="{{ __('journal.ap_opinion_ph', ['name' => $jr->user?->name]) }}" wire:model="opinions.{{ $jr->id }}"></textarea>
                                @endif
                            </div>
                        </details>
                    @endforeach

                    {{-- 기안 반려 이력(그 날짜) — 결재자에게만. 옛 「전체 의견」은 남아 있는 것만 읽기 전용으로 --}}
                    @php $bNotes = $svc->batchNotes($b->date); $legacyAll = $b->comments->whereNull('work_journal_id'); @endphp
                    @if ($bNotes->isNotEmpty() || $legacyAll->isNotEmpty())
                        <h3 class="mt-4 text-sm font-bold text-gray-800">{{ __('journal.ap_batch_thread') }}</h3>
                        @foreach ($legacyAll as $c)
                            <div class="mt-2 rounded-r-md border-l-4 border-violet-400 bg-violet-50 px-3 py-1.5 text-[13px]"><div class="text-[11px] text-gray-500">{{ $c->step?->title }} {{ $c->user?->name }} · {{ __('journal.comment_all') }}</div><div class="whitespace-pre-line">{{ $c->body }}</div></div>
                        @endforeach
                        @include('livewire.work-journal._thread', ['notes' => $bNotes])
                    @endif

                    @error('approve') <div class="mt-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-[13px] text-red-700">⚠ {{ $message }}</div> @enderror
                    @if ($canApprove)
                        @php $next = $b->steps->first(fn ($s) => $s->seq > $cur->seq && $s->status === 'pending'); @endphp
                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            <span class="min-w-0 flex-1 text-xs text-gray-500">{{ __('journal.ap_reject_hint') }}</span>
                            <div class="ml-auto flex shrink-0 gap-2">
                                <button type="button" class="rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700" wire:click="openReject">{{ __('journal.ap_reject') }}</button>
                                @if ($cur->title === \App\Models\User::TEAM_LEADER)
                                    <button type="button" class="btn-green" wire:click="openLineModal">{{ __('journal.ap_line_open') }}</button>
                                @else
                                    <button type="button" class="btn-green" wire:click="approve">{{ __('journal.ap_approve', ['next' => $next ? __('journal.ap_next_to', ['title' => $next->title]) : __('journal.ap_final')]) }}</button>
                                @endif
                            </div>
                        </div>
                    @endif
                </div>
            @endif
        </div>
    </div>

    {{-- 결재선 모달(클레임 [결재 요청] 과 같은 창 — 모바일 = 하단 시트 · PC = 가운데 창) --}}
    @if ($lineModal && ($b = $this->batch))
        @php $cand = \App\Models\Claim::lineCandidates(); $picked = collect($cand)->filter(fn ($u, $t) => $u && (string) ($line[$t] ?? '') === (string) $u->id); @endphp
        <div class="fixed inset-0 z-[60] bg-black/40" wire:click="closeLineModal"></div>
        <div class="fixed inset-x-0 bottom-0 z-[70] max-h-[85vh] overflow-y-auto rounded-t-2xl bg-white p-5 shadow-2xl
                    sm:inset-x-auto sm:bottom-auto sm:left-1/2 sm:top-1/2 sm:w-[420px] sm:-translate-x-1/2 sm:-translate-y-1/2 sm:rounded-xl"
             x-data @keydown.escape.window="$wire.closeLineModal()">
            <div class="mb-1 flex items-center justify-between">
                <h4 class="font-bold text-gray-800">{{ __('journal.ap_line_title', ['date' => $b->date->toDateString()]) }}</h4>
                <button type="button" class="text-gray-400 hover:text-gray-600" wire:click="closeLineModal">✕</button>
            </div>
            <p class="mb-3 text-xs text-gray-500">{{ __('journal.ap_line_hint') }}</p>

            @if (collect($cand)->filter()->isEmpty())
                <p class="mb-3 text-sm text-red-600">{{ __('journal.err.no_ceo') }}</p>
            @endif
            <div class="space-y-2">
                @foreach ($cand as $title => $u)
                    <label class="flex items-center gap-3 rounded-lg border px-3 py-2.5 {{ $u ? 'border-gray-200' : 'border-dashed border-gray-200 opacity-50' }}">
                        <span class="w-10 shrink-0 text-sm font-semibold text-gray-700">{{ $title }}</span>
                        <select class="input-base flex-1 py-2 text-sm" wire:model.live="line.{{ $title }}" @disabled(! $u)>
                            <option value="">{{ __('journal.ap_line_skip') }}</option>
                            @if ($u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endif
                        </select>
                    </label>
                @endforeach
            </div>

            <div class="mt-3 rounded-lg bg-gray-50 px-3 py-2 text-sm">
                @if ($picked->isEmpty())
                    <span class="text-gray-400">{{ __('journal.err.no_line') }}</span>
                @else
                    {{ __('journal.ap_line_leader') }} → {{ $picked->map(fn ($u, $t) => $t.' '.$u->name)->implode(' → ') }}
                    <div class="mt-0.5 text-xs text-gray-500">{{ __('journal.ap_line_final', ['title' => $picked->keys()->last(), 'name' => $picked->last()->name]) }}</div>
                @endif
            </div>
            @if ($lineError)
                <p class="mt-2 text-sm text-red-600">⚠️ {{ $lineError }}</p>
            @endif

            <div class="mt-4 flex gap-2">
                <button type="button" class="btn-outline flex-1 justify-center py-2.5" wire:click="closeLineModal">{{ __('common.cancel') }}</button>
                <button type="button" class="btn-green flex-1 justify-center py-2.5" wire:click="approve" wire:loading.attr="disabled" wire:target="approve" @disabled($picked->isEmpty())>
                    {{ __('journal.ap_line_send', ['title' => $picked->keys()->first() ?? '—']) }}
                </button>
            </div>
        </div>
    @endif

    {{-- 반려 사유 모달 --}}
    @if ($rejectModal && ($rb = $this->batch))
        @php $firstStep = $svc->atFirstStep($rb); $back = $rb->steps->first(fn ($s) => $s->status !== 'skipped'); @endphp
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" wire:click.self="closeReject">
        <div class="max-h-[90vh] w-full max-w-md overflow-y-auto rounded-xl bg-white p-5 shadow-xl">
            <h3 class="text-base font-bold text-gray-800">{{ __('journal.ap_reject_modal_title', ['date' => $rb->date->toDateString()]) }}</h3>
            <p class="mt-1 text-xs text-gray-500">{{ $firstStep ? __('journal.ap_reject_modal_hint_first') : __('journal.ap_reject_modal_hint', ['title' => $back?->title, 'name' => $back?->approver?->name]) }}</p>
            <label class="mt-3 block text-xs font-bold text-gray-700">{{ __('journal.ap_reject_reason') }}</label>
            <textarea class="input-base mt-1" rows="3" autofocus placeholder="{{ __('journal.ap_reject_ph') }}" wire:model="rejectReason"></textarea>
            <label class="mt-3 block text-xs font-bold text-gray-700">{{ $firstStep ? __('journal.ap_reject_pick_required') : __('journal.ap_reject_pick') }}</label>
            <div class="mt-1 space-y-1">
                @foreach ($rb->journals->sortBy(fn ($j) => $j->submitted_at) as $rj)
                    <label class="flex cursor-pointer items-center gap-2 rounded-md border border-gray-200 px-3 py-2 text-sm" wire:key="rjp-{{ $rj->id }}">
                        <input type="checkbox" wire:model.live="rejectPick" value="{{ $rj->id }}">
                        <span class="font-semibold text-gray-800">{{ $rj->user?->name }}</span>
                        <span class="text-xs text-gray-500">{{ $rj->user?->roleLabel() }}</span>
                    </label>
                @endforeach
            </div>
            <p class="mt-2 rounded-md bg-gray-50 px-3 py-2 text-xs text-gray-600">
                {{ count($rejectPick) > 0 ? __('journal.ap_reject_result_pick', ['n' => count($rejectPick), 'rest' => $rb->journals->count() - count($rejectPick), 'title' => $back?->title]) : __('journal.ap_reject_result_none', ['title' => $back?->title]) }}
            </p>
            @error('rejectReason') <p class="mt-2 text-sm text-red-600">⚠ {{ $message }}</p> @enderror
            <div class="mt-4 flex justify-end gap-2">
                <button type="button" class="btn-ghost" wire:click="closeReject">{{ __('common.cancel') }}</button>
                <button type="button" class="rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700" wire:click="rejectBatch" wire:loading.attr="disabled" wire:target="rejectBatch">{{ __('journal.ap_reject_submit') }}</button>
            </div>
        </div>
        </div>
    @endif
</div>

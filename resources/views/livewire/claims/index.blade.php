<?php

use App\Http\Controllers\ClaimApprovalController;
use App\Models\Claim;
use App\Models\ClaimFile;
use App\Services\ClaimService;
use App\Services\SsancarMediaService;
use App\Support\UploadGuard;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * 클레임 탭(2026-11-10 Jin, 정본 = meetings/plan-claims-2026-11.md) — 기능설정에서 지정한 담당자만(super 도 지정 필요).
 * 목록 2줄(차량번호·차대번호·바이어·금액·총 구매대수 / 내용·도착일+14 경과) → 드로어(ERP 스냅샷·영업 접수·바이어 증빙·우리 검차 미디어·보고서 칸)
 * → [대표 승인 요청](알림톡 ① + 링크) → 결과 → [종결]. 합의액이 바뀌면 다시 [대표 승인 요청](재승인 = 회차 +1).
 */
new #[Layout('components.layouts.app')] class extends Component
{
    use WithFileUploads, WithPagination;

    #[Url]
    public string $tab = 'open';   // open(종결 제외) · pending(승인요청) · decided(승인·반려) · closed · all

    #[Url]
    public string $q = '';

    public ?int $detailId = null;

    public array $form = [];

    public array $extra = [];   // 담당자 추가 첨부(other)

    public ?string $notice = null;

    public ?string $error = null;

    public function mount(): void
    {
        abort_unless(Auth::user()->isClaimHandler(), 403);
    }

    private function base()
    {
        $q = Claim::query()->with(['salesman', 'requester']);
        $q = match ($this->tab) {
            'pending' => $q->where('status', Claim::PENDING_CEO),
            'decided' => $q->whereIn('status', [Claim::APPROVED, Claim::REJECTED]),
            'closed' => $q->where('status', Claim::CLOSED),
            'all' => $q,
            default => $q->where('status', '!=', Claim::CLOSED),
        };
        if (($s = trim($this->q)) !== '') {
            $plate = preg_replace('/\s+/u', '', $s);
            $q->where(fn ($w) => $w->whereRaw("REPLACE(vehicle_number, ' ', '') like ?", ['%'.$plate.'%'])
                ->orWhere('vin', 'like', '%'.$s.'%')->orWhere('buyer_name', 'like', '%'.$s.'%'));
        }

        return $q;
    }

    #[Computed]
    public function claims()
    {
        // 승인요청 중 → 접수 → 검토 → 반려 → 승인 → 종결 순(sqlite 도 되는 CASE 식), 같은 상태면 최신.
        return $this->base()->orderByRaw("CASE status WHEN 'pending_ceo' THEN 0 WHEN 'received' THEN 1 WHEN 'reviewing' THEN 2 WHEN 'rejected' THEN 3 WHEN 'approved' THEN 4 ELSE 5 END")->latest('id')->paginate(20);
    }

    /** 탭 배지 — 종결 제외 건수·승인요청 중 건수. */
    #[Computed]
    public function counts(): array
    {
        $by = Claim::query()->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status')->all();

        return [
            'open' => array_sum(array_filter($by, fn ($v, $k) => $k !== Claim::CLOSED, ARRAY_FILTER_USE_BOTH)),
            'pending' => (int) ($by[Claim::PENDING_CEO] ?? 0),
            'decided' => (int) ($by[Claim::APPROVED] ?? 0) + (int) ($by[Claim::REJECTED] ?? 0),
            'closed' => (int) ($by[Claim::CLOSED] ?? 0),
            'all' => array_sum($by),
        ];
    }

    #[Computed]
    public function detail(): ?Claim
    {
        return $this->detailId ? Claim::with(['files', 'salesman', 'creator', 'requester', 'approver', 'listing'])->find($this->detailId) : null;
    }

    #[Computed]
    public function media(): array
    {
        $c = $this->detail;

        return $c ? ClaimApprovalController::inspectionMedia($c, app(SsancarMediaService::class)) : ['board' => [], 'videos' => [], 'photos' => []];
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['open', 'pending', 'decided', 'closed', 'all'], true) ? $tab : 'open';
        $this->resetPage();
    }

    public function updatedQ(): void
    {
        $this->resetPage();
    }

    public function openDetail(int $id): void
    {
        $this->detailId = $id;
        $this->notice = $this->error = null;
        $this->extra = [];
        unset($this->detail, $this->media);
        $c = $this->detail;
        if (! $c) {
            return;
        }
        $this->form = [];
        foreach (Claim::EDITABLE as $f) {
            $v = $c->getAttribute($f);
            $this->form[$f] = $v instanceof \Illuminate\Support\Carbon ? $v->format('Y-m-d') : ($v === null ? '' : (is_bool($v) ? $v : (string) $v));
        }
        $this->form['evidence_verified'] = (bool) $c->evidence_verified;
    }

    public function closeDetail(): void
    {
        $this->reset('detailId', 'form', 'notice', 'error', 'extra');
        unset($this->detail, $this->media, $this->claims, $this->counts);
    }

    private function refreshDetail(): void
    {
        [$n, $e] = [$this->notice, $this->error];   // openDetail 이 비우므로 보존
        unset($this->detail, $this->media, $this->claims, $this->counts);
        $this->openDetail($this->detailId);
        [$this->notice, $this->error] = [$n, $e];
    }

    public function save(ClaimService $svc): void
    {
        $c = $this->detail;
        if (! $c || ! $c->isOpen()) {
            return;
        }
        $this->validate([
            'form.arrived_at' => ['required', 'date'],
            'form.received_km' => ['required', 'integer', 'min:0'],
            'form.content' => ['required', 'string', 'max:4000'],
            'form.buyer_priority' => ['nullable', 'in:high,medium,low'],
            'form.claim_history_adjust' => ['nullable', 'integer', 'min:-999', 'max:999'],
            'form.claim_amount' => ['nullable', 'numeric', 'min:0'],
            'form.refundable_amount' => ['nullable', 'numeric', 'min:0'],
            'form.claim_currency' => ['nullable', 'string', 'max:3'],
            'form.compensation_type' => ['nullable', 'in:refund,credit'],
        ]);
        $fields = $this->form;
        $fields['claim_history_adjust'] = (int) ($fields['claim_history_adjust'] ?: 0);
        $fields['evidence_verified'] = (bool) ($fields['evidence_verified'] ?? false);
        $fields['claim_currency'] = strtoupper(trim((string) ($fields['claim_currency'] ?? ''))) ?: null;
        $svc->saveReport($c, Auth::user(), $fields);

        // 담당자 추가 첨부(바이어가 나중에 보낸 것) — other 로.
        foreach (array_values(array_filter($this->extra)) as $f) {
            if (UploadGuard::isExecutable($f->getClientOriginalName())) {
                $this->error = __('claims.exec_error', ['name' => $f->getClientOriginalName()]);

                continue;
            }
            $file = $svc->storeFile($c, ClaimFile::KIND_OTHER, $f, Auth::id());
            $svc->audit($c, Auth::id(), 'claim_file', null, $file->original_name);
        }
        $this->extra = [];
        $this->notice = __('claims.saved');
        $this->refreshDetail();
    }

    public function requestApproval(ClaimService $svc): void
    {
        $c = $this->detail;
        if (! $c) {
            return;
        }
        $this->save($svc);   // 눌린 시점의 칸을 먼저 저장(보고서 = 저장된 값)
        $c = $this->detail;
        try {
            $svc->requestApproval($c, Auth::user());
            $this->notice = __('claims.requested', ['ceo' => $c->fresh()->approver?->name ?? '—']);
        } catch (\DomainException $e) {
            $this->error = $this->domainMessage($e->getMessage());
        }
        $this->refreshDetail();
    }

    public function resend(ClaimService $svc): void
    {
        $c = $this->detail;
        if (! $c) {
            return;
        }
        try {
            $svc->resend($c, Auth::user());
            $this->notice = __('claims.resent');
        } catch (\DomainException $e) {
            $this->error = $this->domainMessage($e->getMessage());
        }
        $this->refreshDetail();
    }

    public function closeClaim(ClaimService $svc): void
    {
        $c = $this->detail;
        if (! $c || $c->status !== Claim::APPROVED) {
            return;
        }
        $svc->close($c, Auth::user());
        $this->notice = __('claims.closed_ok');
        $this->refreshDetail();
    }

    public function reloadErp(ClaimService $svc): void
    {
        $c = $this->detail;
        if (! $c) {
            return;
        }
        $got = $svc->snapshotErp($c);
        $svc->audit($c, Auth::id(), 'claim_erp_reload', null, $got ? implode(',', $got) : 'none');
        $this->notice = $got ? __('claims.erp_reloaded', ['parts' => implode(', ', $got)]) : __('claims.erp_reload_failed');
        $this->refreshDetail();
    }

    private function domainMessage(string $code): string
    {
        return match ($code) {
            'no_ceo' => __('claims.err_no_ceo'),
            'overdue_reason_required' => __('claims.err_overdue_reason'),
            'not_requestable' => __('claims.err_not_requestable'),
            'resend_cooldown' => __('claims.err_resend_cooldown', ['min' => Claim::RESEND_COOLDOWN_MINUTES]),
            default => $code,
        };
    }

    /** 담당자가 카톡/WhatsApp 으로 직접 붙여넣을 링크(알림톡 승인 전 임시·보조, 2026-11-10 결정 3). 승인요청 상태에서만. */
    public function approvalLink(): ?string
    {
        $c = $this->detail;
        if (! $c || ! $c->isPendingCeo() || ! $c->approver) {
            return null;
        }

        return $c->approvalLinkFor($c->approver);
    }
}; ?>

<div class="p-3 md:p-6">
    <div class="mb-3 flex flex-wrap items-end justify-between gap-2">
        <div>
            <h1 class="text-xl font-bold text-gray-800">{{ __('claims.title') }}</h1>
            <p class="mt-0.5 text-xs text-gray-500">{{ __('claims.subtitle') }}</p>
        </div>
        <input class="input-base w-auto max-w-[240px] text-[12px]" wire:model.live.debounce.400ms="q" placeholder="{{ __('claims.search_ph') }}">
    </div>

    @if (session('ok'))
        <div class="card-sm mb-3 border-green-200 bg-green-50 text-[13px] text-green-700">✓ {{ session('ok') }}</div>
    @endif

    <div class="mb-3 flex flex-wrap gap-1.5">
        @foreach (['open', 'pending', 'decided', 'closed', 'all'] as $t)
            <button type="button" wire:click="setTab('{{ $t }}')"
                class="rounded-md border px-2.5 py-1 text-[12px] font-semibold {{ $tab === $t ? 'border-[var(--color-primary)] bg-[var(--color-primary)] text-white' : 'border-gray-300 bg-white text-gray-600' }}">
                {{ __('claims.tab.'.$t) }} <span class="opacity-70">{{ $this->counts[$t] }}</span>
            </button>
        @endforeach
    </div>

    <div class="card">
        @forelse ($this->claims as $c)
            {{-- Jin 2줄: 차량번호 / 차대번호 / 바이어 / 금액 / 총 구매차량수 ↵ 클레임내용(호버 전체) / 도착일+14 경과 --}}
            <div wire:key="claim-{{ $c->id }}" class="cursor-pointer border-b border-gray-100 px-1 py-2.5 hover:bg-gray-50" wire:click="openDetail({{ $c->id }})">
                <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-[13px]">
                    <span class="font-bold text-gray-800">{{ \App\Support\Plate::show($c->vehicle_number) }}</span>
                    <span class="text-gray-400">{{ $c->vin ?: '—' }}</span>
                    <span class="text-gray-700">🧑 {{ $c->buyer_name ?: '—' }}</span>
                    <span class="tabular-nums text-gray-700">{{ $c->salePriceDisplay() }}</span>
                    <span class="text-gray-500">{{ $c->total_purchase_count === null ? '—' : __('claims.total_cars', ['count' => $c->total_purchase_count]) }}</span>
                    <span class="badge {{ $c->statusBadge() }} ml-auto whitespace-nowrap">{{ $c->statusLabel() }}@if ($c->revision > 1) ·{{ $c->revision }}@endif</span>
                </div>
                <div class="mt-0.5 flex items-center gap-2 text-[12px]">
                    <span class="min-w-0 flex-1 truncate text-gray-600" title="{{ $c->content }}">{{ $c->content }}</span>
                    <span class="shrink-0 whitespace-nowrap {{ $c->isOverdue() ? 'font-semibold text-red-600' : 'text-gray-400' }}">
                        {{ __('claims.arrived_short') }} D+{{ $c->daysAfterArrival() }}@if ($c->isOverdue()) · {{ __('claims.overdue_chip') }}@endif
                    </span>
                    <span class="shrink-0 text-gray-400">{{ $c->salesman?->name }}</span>
                </div>
            </div>
        @empty
            <div class="py-10 text-center text-gray-400">{{ __('claims.empty') }}</div>
        @endforelse
        <div class="mt-2">{{ $this->claims->links() }}</div>
    </div>

    {{-- ─────────── 처리 드로어 ─────────── --}}
    @if ($this->detail)
        @php
            $d = $this->detail;
            $media = $this->media;
            $kinds = $d->files->groupBy('kind');
            $link = $this->approvalLink();
            $ceo = \App\Services\ClaimService::ceo();
            $editable = $d->isOpen() && ! $d->isPendingCeo();
        @endphp
        <div class="fixed inset-0 z-40 bg-black/40" wire:click="closeDetail"></div>
        <div class="fixed inset-y-0 right-0 z-50 w-full overflow-y-auto bg-white shadow-xl sm:w-[720px]">
            <div class="flex items-center justify-between border-b border-gray-200 px-5 py-3">
                <h3 class="font-bold text-gray-800">{{ \App\Support\Plate::show($d->vehicle_number) }} · {{ $d->buyer_name ?: '—' }}
                    <span class="badge {{ $d->statusBadge() }} ml-1">{{ $d->statusLabel() }}</span>
                    @if ($d->revision > 0)<span class="ml-1 text-xs font-normal text-gray-400">{{ __('claims.rev_n', ['n' => $d->revision]) }}</span>@endif
                </h3>
                <button class="text-gray-400 hover:text-gray-600" wire:click="closeDetail">✕</button>
            </div>

            <div class="px-5 py-4 text-sm">
                @if ($notice)<div class="card-sm mb-3 border-green-200 bg-green-50 text-[13px] text-green-700">✓ {{ $notice }}</div>@endif
                @if ($error)<div class="card-sm mb-3 border-red-200 bg-red-50 text-[13px] text-red-700">⚠️ {{ $error }}</div>@endif

                {{-- 승인 상태·링크 --}}
                @if ($d->isPendingCeo())
                    <div class="card-sm mb-3 border-purple-200 bg-purple-50 text-xs text-gray-700">
                        <div>⏳ {{ __('claims.pending_banner', ['ceo' => $d->approver?->name ?? '—', 'at' => $d->requested_at?->timezone(config('app.timezone'))->format('m-d H:i') ?? '—']) }}</div>
                        @if ($link)
                            <div class="mt-1.5 flex items-center gap-1.5" x-data="{ copied: false }">
                                <input class="input-base flex-1 text-[11px]" readonly value="{{ $link }}">
                                <button type="button" class="btn-outline btn-sm shrink-0" @click="navigator.clipboard.writeText($el.previousElementSibling.value).then(() => { copied = true; setTimeout(() => copied = false, 2000) })">
                                    <span x-show="!copied">{{ __('claims.copy_link') }}</span><span x-show="copied" x-cloak>✓</span>
                                </button>
                                <button type="button" class="btn-outline btn-sm shrink-0" wire:click="resend" wire:loading.attr="disabled"
                                        @disabled(! $d->canResend()) title="{{ $d->canResend() ? '' : __('claims.err_resend_cooldown', ['min' => \App\Models\Claim::RESEND_COOLDOWN_MINUTES]) }}">{{ __('claims.resend') }}</button>
                            </div>
                            <p class="mt-1 text-[11px] text-gray-400">{{ __('claims.link_hint') }}</p>
                        @endif
                    </div>
                @elseif ($d->decided_at)
                    <div class="card-sm mb-3 {{ $d->decision === 'approved' ? 'border-green-200 bg-green-50' : 'border-red-200 bg-red-50' }} text-xs text-gray-700">
                        {{ $d->decision === 'approved' ? '✅' : '⛔' }} {{ __('claims.decided_banner', ['result' => $d->statusLabel(), 'ceo' => $d->approver?->name ?? '—', 'at' => $d->decided_at->timezone(config('app.timezone'))->format('m-d H:i')]) }}
                        @if ($d->decision_reason)<div class="mt-0.5 whitespace-pre-wrap">{{ $d->decision_reason }}</div>@endif
                        @if ($d->status === 'approved')<div class="mt-1 text-gray-500">{{ __('claims.approved_next') }}</div>@endif
                    </div>
                @endif

                {{-- 영업 접수 + ERP 스냅샷 --}}
                <div class="card-sm mb-3 bg-gray-50 text-xs text-gray-600">
                    <div class="flex flex-wrap gap-x-3 gap-y-0.5">
                        <span>{{ __('claims.salesman') }} <b class="text-gray-800">{{ $d->salesman?->name }}</b>@if ($d->creator && $d->creator->id !== $d->salesman_user_id) <span class="text-gray-400">({{ __('claims.by', ['name' => $d->creator->name]) }})</span>@endif</span>
                        <span>{{ __('claims.received_at') }} {{ $d->created_at->timezone(config('app.timezone'))->format('m-d H:i') }}</span>
                        <span>ETA {{ $d->eta_date?->format('Y-m-d') ?? '—' }}</span>
                        <span>{{ __('claims.sale_date') }} {{ $d->sale_date?->format('Y-m-d') ?? '—' }}</span>
                        <span class="{{ $d->isOverdue() ? 'font-semibold text-red-600' : '' }}">{{ __('claims.arrived_short') }} D+{{ $d->daysAfterArrival() }}@if ($d->isOverdue()) · {{ __('claims.overdue_chip') }}@endif</span>
                    </div>
                    <div class="mt-1 flex flex-wrap items-center gap-x-3">
                        <span>ERP {{ $d->erp_snapshot_at?->timezone(config('app.timezone'))->format('m-d H:i') ?? '—' }}</span>
                        <button type="button" class="btn-ghost btn-sm" wire:click="reloadErp" wire:loading.attr="disabled">↻ {{ __('claims.erp_reload') }}</button>
                        @if ($d->listing)<a class="text-[var(--color-primary-text)] hover:underline" href="{{ route('listings') }}" wire:navigate>{{ __('claims.board_listing', ['id' => $d->listing->id]) }}</a>@endif
                    </div>
                </div>

                {{-- 미디어 비교 --}}
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div>
                        <div class="section-title-sm">{{ __('claims.ours_media') }}</div>
                        @php $ours = $media['board'] !== [] || $media['videos'] !== [] || $media['photos'] !== []; @endphp
                        @if ($ours)
                            @foreach ($media['videos'] as $v)
                                @if (! empty($v['embed_url']))
                                    <div class="relative mb-2 overflow-hidden rounded-md bg-black" style="padding-bottom:56.25%"><iframe class="absolute inset-0 h-full w-full" src="{{ $v['embed_url'] }}" loading="lazy" allowfullscreen></iframe></div>
                                @elseif (! empty($v['url']))
                                    <video src="{{ $v['url'] }}" class="mb-2 w-full rounded-md bg-black" controls preload="metadata"></video>
                                @endif
                            @endforeach
                            <div class="grid grid-cols-3 gap-1.5">
                                @foreach ($media['board'] as $m)
                                    @if ($m['video'])<video src="{{ $m['url'] }}" class="aspect-square w-full rounded-md object-cover" controls preload="metadata"></video>
                                    @else<a href="{{ $m['url'] }}" target="_blank"><img src="{{ $m['url'] }}" class="aspect-square w-full rounded-md object-cover" alt=""></a>@endif
                                @endforeach
                                @foreach ($media['photos'] as $u)<a href="{{ $u }}" target="_blank"><img src="{{ $u }}" class="aspect-square w-full rounded-md object-cover" loading="lazy" alt=""></a>@endforeach
                            </div>
                        @else
                            <p class="text-xs text-gray-400">{{ __('claims.inspection_media_none') }}</p>
                        @endif
                    </div>
                    <div>
                        <div class="section-title-sm">{{ __('claims.buyer_media') }}</div>
                        @foreach (\App\Models\ClaimFile::KINDS as $k)
                            @continue(($kinds[$k] ?? collect())->isEmpty())
                            <div class="mb-0.5 mt-1 text-[11px] text-gray-500">{{ __('claims.kind.'.$k) }}</div>
                            <div class="grid grid-cols-3 gap-1.5">
                                @foreach ($kinds[$k] as $file)
                                    @if ($file->isVideo())
                                        <video src="{{ $file->shareUrl() }}" class="aspect-square w-full rounded-md bg-black object-cover" controls preload="metadata"></video>
                                    @elseif ($file->isImage())
                                        <a href="{{ $file->shareUrl() }}" target="_blank"><img src="{{ $file->shareUrl() }}" class="aspect-square w-full rounded-md object-cover" loading="lazy" alt=""></a>
                                    @else
                                        <a href="{{ $file->shareUrl() }}" target="_blank" class="col-span-3 truncate text-xs text-[var(--color-primary-text)] hover:underline">📄 {{ $file->original_name }}</a>
                                    @endif
                                @endforeach
                            </div>
                        @endforeach
                        @if ($editable)
                            <div class="mt-2">
                                <input type="file" multiple class="block w-full text-xs" wire:model="extra">
                                <p class="text-[11px] text-gray-400">{{ __('claims.extra_files_hint') }}</p>
                                @error('extra.*')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                            </div>
                        @endif
                    </div>
                </div>

                {{-- 보고서 칸(양식 순서) --}}
                <div class="section-title-sm">{{ __('claims.report_fields') }}</div>
                <fieldset @disabled(! $editable) class="grid grid-cols-2 gap-2 text-xs {{ $editable ? '' : 'opacity-70' }}">
                    <div><label class="label-base">BUYER NAME</label><input class="input-base" wire:model="form.buyer_contact_name" maxlength="120"></div>
                    <div><label class="label-base">BUYER PRIORITY</label>
                        <select class="input-base" wire:model="form.buyer_priority"><option value="">—</option>@foreach (\App\Models\Claim::PRIORITIES as $p)<option value="{{ $p }}">{{ strtoupper($p) }}</option>@endforeach</select></div>
                    <div><label class="label-base">{{ __('claims.arrived_at') }}</label><input type="date" class="input-base" wire:model="form.arrived_at">@error('form.arrived_at')<p class="text-red-600">{{ $message }}</p>@enderror</div>
                    <div><label class="label-base">{{ __('claims.received_km') }}</label><input type="number" class="input-base" wire:model="form.received_km">@error('form.received_km')<p class="text-red-600">{{ $message }}</p>@enderror</div>
                    <div><label class="label-base">CLAIM HISTORY {{ __('claims.history_adjust') }}</label><input type="number" class="input-base" wire:model="form.claim_history_adjust"><p class="text-[11px] text-gray-400">{{ __('claims.history_adjust_hint', ['n' => $d->yearClaimCount()]) }}</p></div>
                    <div class="flex items-end gap-2 pb-1"><label class="flex items-center gap-1.5"><input type="checkbox" wire:model="form.evidence_verified"> EVIDENCE VERIFIED</label></div>
                    <div class="col-span-2"><label class="label-base">CLAIM REASON ({{ __('claims.content') }})</label><textarea class="input-base" rows="3" wire:model="form.content"></textarea>@error('form.content')<p class="text-red-600">{{ $message }}</p>@enderror</div>
                    <div class="col-span-2"><label class="label-base">INSPECTION REPORT RESULT</label><textarea class="input-base" rows="2" wire:model="form.inspection_result" placeholder="{{ __('claims.inspection_result_ph') }}"></textarea></div>
                    <div class="col-span-2"><label class="label-base">NOTES (1p) <span class="font-normal text-gray-400">— {{ __('claims.notes_hint') }}</span></label><textarea class="input-base" rows="2" wire:model="form.notes"></textarea></div>
                    <div class="col-span-2"><label class="label-base">CLAIM NOTES</label><textarea class="input-base" rows="2" wire:model="form.review_notes"></textarea></div>
                    <div><label class="label-base">CLAIM AMOUNT</label><div class="flex gap-1"><input class="input-base w-16" wire:model="form.claim_currency" maxlength="3" placeholder="{{ $d->sale_currency ?: 'EUR' }}"><input type="number" step="0.01" class="input-base" wire:model="form.claim_amount"></div>@error('form.claim_amount')<p class="text-red-600">{{ $message }}</p>@enderror</div>
                    <div><label class="label-base">REFUNDABLE</label><input type="number" step="0.01" class="input-base" wire:model="form.refundable_amount">@if (($r = $d->refundRate()) !== null)<p class="text-[11px] text-gray-400">{{ $r }}%</p>@endif</div>
                    <div class="col-span-2"><label class="label-base">RESULT ({{ __('claims.result') }})</label><textarea class="input-base" rows="2" wire:model="form.result"></textarea></div>
                    <div class="col-span-2"><label class="label-base">REASON</label><textarea class="input-base" rows="2" wire:model="form.result_reason"></textarea></div>
                    <div class="col-span-2 flex flex-wrap items-center gap-3"><span class="label-base">COMPENSATION TYPE</span>
                        <label class="flex items-center gap-1"><input type="radio" value="refund" wire:model="form.compensation_type"> REFUND</label>
                        <label class="flex items-center gap-1"><input type="radio" value="credit" wire:model="form.compensation_type"> CREDIT (REFUND DEPOSIT)</label>
                        <label class="flex items-center gap-1"><input type="radio" value="" wire:model="form.compensation_type"> —</label></div>
                    @if ($d->isOverdue())
                        <div class="col-span-2"><label class="label-base text-red-700">{{ __('claims.overdue_reason') }} <span class="font-normal">— {{ __('claims.overdue_reason_hint') }}</span></label><textarea class="input-base" rows="2" wire:model="form.overdue_reason"></textarea></div>
                    @endif
                    <div class="col-span-2" x-data="{ bank: {{ ($d->bank_iban || $d->bank_name) ? 'true' : 'false' }} }">
                        <button type="button" class="text-[var(--color-primary-text)] hover:underline" @click="bank = !bank">🏦 {{ __('claims.bank_toggle') }} <span x-text="bank ? '▲' : '▼'"></span></button>
                        <div x-show="bank" x-cloak class="mt-1 grid grid-cols-2 gap-2">
                            <div><label class="label-base">BANK</label><input class="input-base" wire:model="form.bank_name"></div>
                            <div><label class="label-base">SWIFT</label><input class="input-base" wire:model="form.bank_swift"></div>
                            <div><label class="label-base">IBAN / ACCOUNT</label><input class="input-base" wire:model="form.bank_iban"></div>
                            <div><label class="label-base">PHONE</label><input class="input-base" wire:model="form.bank_phone"></div>
                            <div class="col-span-2"><label class="label-base">ADDRESS</label><input class="input-base" wire:model="form.bank_address"></div>
                            <p class="col-span-2 text-[11px] text-gray-400">{{ __('claims.bank_hint') }}</p>
                        </div>
                    </div>
                </fieldset>

                {{-- 버튼 --}}
                <div class="mt-4 flex flex-wrap gap-2">
                    @if ($editable)
                        <button type="button" class="btn-outline" wire:click="save" wire:loading.attr="disabled">{{ __('common.save') }}</button>
                        @if ($d->canRequestApproval())
                            <button type="button" class="btn-primary" wire:click="requestApproval" wire:loading.attr="disabled"
                                    @disabled($ceo === null || trim((string) $ceo->phone) === '' && false)
                                    wire:confirm="{{ __('claims.confirm_request', ['ceo' => $ceo?->name ?? '—', 'rev' => $d->revision + 1]) }}">
                                {{ $d->revision > 0 ? __('claims.request_again') : __('claims.request') }}
                            </button>
                        @endif
                        @if ($d->status === 'approved')
                            <button type="button" class="btn-green" wire:click="closeClaim" wire:confirm="{{ __('claims.confirm_close') }}">{{ __('claims.close_btn') }}</button>
                        @endif
                    @endif
                </div>
                @if ($ceo === null)
                    <p class="mt-1 text-xs text-red-600">{{ __('claims.err_no_ceo') }}</p>
                @elseif (trim((string) $ceo->phone) === '')
                    <p class="mt-1 text-xs text-amber-600">{{ __('claims.warn_no_phone', ['ceo' => $ceo->name]) }}</p>
                @endif

                {{-- 보고서 미리보기(양식 그대로) --}}
                <details class="mt-4" {{ $d->isPendingCeo() || $d->decided_at ? 'open' : '' }}>
                    <summary class="cursor-pointer text-xs font-semibold text-gray-600">📄 {{ __('claims.report_preview') }}</summary>
                    <div class="mt-2 rounded-md border border-gray-200 p-3">
                        @include('claim-approval._report', ['claim' => $d, 'media' => $media])
                    </div>
                </details>
            </div>
        </div>
    @endif
</div>

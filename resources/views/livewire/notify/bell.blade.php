<?php

use App\Services\CarErpReadService;
use App\Services\WebPushService;
use App\Support\BoardRequestLines;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

/**
 * 상단 종 — 내가 보낸 §11 요청(계약금·매입잔금·대표계약금·판매대금확인)의 **결과**와 **진행 중** 목록 (jin 2026-10-12).
 *
 * 왜: ERP 사용자는 화면을 켜 두고 보니 초록 알림으로 알지만, board 영업은 운전 중이라 포털 탭을 열어 칩을 보기 어렵다.
 *     ERP 가 요청을 **거절**(사유 포함)하는 씬이 생기면서 "내 요청이 어떻게 됐나"를 한곳에서 봐야 한다.
 *
 * 원천은 전부 ERP `GET /requests?status=all` — board 는 결과를 복제하지 않고 **마지막 열람 시각(`users.portal_seen_at`) 하나**만 갖는다.
 *   미읽음 = 결과 라인(done·rejected)의 `resolved_at` 이 열람 시각보다 뒤인 것. 상태·사유는 ERP 값 그대로(재계산·완료 coerce 금지, SKILLS §14-2).
 *   ⚠️ `status=open`(기본)은 라인 필터라 done·rejected 가 빠진다 — 반드시 `all`.
 *   ⚠️ 폴링은 `since=열람시각` 을 실어 전체 이력을 매번 끌어오지 않는다(ERP 2026-10-12 추가; 구 ERP 는 무시하고 전량).
 *   ⚠️ 대상은 **로그인한 본인**의 영업 이메일이다 — super 가 포털에서 남을 열람 중이어도 그 사람 결과를 끌어오지 않는다.
 *
 * 폴링 30초(화면이 떠 있을 때). 새 거절 = 빨간 토스트 + 비프, 새 확인 = 초록 토스트(무음) — `notify.poll` 의 `forward-arrived` 리스너 재사용.
 * 화면이 꺼져 있을 때는 웹푸시(별도 슬라이스)가 맡는다.
 */
new class extends Component
{
    /** 안 읽은 결과 건수 — 종 배지. */
    public int $unread = 0;

    public bool $open = false;

    /** 조회 실패 — 비어 있어도 「요청 없음」으로 읽히면 안 된다(§14-2 칩과 같은 원칙). */
    public bool $loadFailed = false;

    /** 최근 결과(done·rejected) 최신순 ≤ 30. 각 항목에 `new`(열람 시각 이후인가) 플래그. */
    public array $results = [];

    /** 진행 중(open) 라인 최신순. */
    public array $inProgress = [];

    /** 검차 보류(ssancar 검차원 패스) — **board DB** 에서 센다(ERP 아님). 내가 등록한 현지확인대기 차만(2026-10-23 Jin). */
    public array $holds = [];

    /** 긴급 검차 결정(ssancar 업무관리자 승인·기각, P1 2026-10-29) — board DB. 내가 등록한 현지확인대기 차만. */
    public array $urgents = [];

    /** 마지막 열람 시각(ISO8601) — 미읽음 판정 기준. 패널을 열 때 now 로 갱신된다. */
    public ?string $seenAt = null;

    /** 직전 폴링 시각(ISO8601) — 토스트는 이 시각 이후 새로 끝난 결과에만. 열람과 별개 축(열람 안 해도 토스트는 한 번만). */
    public ?string $pollFrom = null;

    private const RESULT_STATUSES = ['done', 'rejected'];

    private const MAX_RESULTS = 30;

    private const PANEL_WINDOW_DAYS = 90;

    public function mount(): void
    {
        $u = Auth::user();
        // 첫 사용자는 과거 이력 전부가 미읽음으로 세어지면 안 된다 → 지금을 기준점으로.
        if ($u->portal_seen_at === null) {
            $u->forceFill(['portal_seen_at' => now()])->save();
        }
        $this->seenAt = $u->portal_seen_at->toIso8601String();
        $this->pollFrom = now()->toIso8601String();
        // ⚠️ 여기서 refresh() 를 부르지 않는다 — 이 컴포넌트는 **모든 화면의 레이아웃**에 있어서, 마운트에서 ERP 를
        //    동기 호출하면 heymanerp 가 느릴 때 /listings·/auction 전부가 최대 20초 멈춘다. 첫 조회는 `wire:init`(렌더 뒤).
    }

    private function svc(): CarErpReadService
    {
        return app(CarErpReadService::class);
    }

    /** car-erp 영업 매칭 이메일 — **항상 로그인 본인**(포털의 super 대리 열람과 무관). */
    private function email(): string
    {
        $u = Auth::user();

        return $u->car_erp_salesman_email ?: $u->email;
    }

    /** GET /requests → 라인 단위(`BoardRequestLines::flatten`, 웹푸시 스케줄러와 같은 모양). null = 조회 실패. */
    private function lines(?string $since): ?array
    {
        $env = $this->svc()->boardRequests($this->email(), 'all', $since);
        if (! ($env['ok'] ?? false)) {
            return null;
        }

        return BoardRequestLines::flatten($env['data']);
    }

    private static function isResult(array $l): bool
    {
        return BoardRequestLines::isResult($l);
    }

    private static function resolvedAfter(array $l, ?string $ts): bool
    {
        return BoardRequestLines::resolvedAfter($l, $ts);
    }

    /** 내 검차 보류 차 — 보류 시각 최신순. ERP 가 죽어도 이건 보인다(board 값). */
    private function holdQuery()
    {
        return \App\Models\PurchaseListing::query()
            ->where('created_by_user_id', Auth::id())
            ->where('status', 'draft')
            ->whereNotNull('inspection_skipped_at')
            ->orderByDesc('inspection_skipped_at');
    }

    /** 내 긴급 검차 결정 — 결정 시각 최신순(P1). 보류와 같은 꼴(board 값). 차가 draft 를 벗어나면(검차돼 전달대기) 자연히 빠진다. */
    private function urgentQuery()
    {
        return \App\Models\PurchaseListing::query()
            ->where('created_by_user_id', Auth::id())
            ->where('status', 'draft')
            ->whereNotNull('inspection_urgent_decided_at')
            ->orderByDesc('inspection_urgent_decided_at');
    }

    /** 폴링(30초) — 배지 갱신 + 새로 끝난 결과 토스트. 패널이 열려 있으면 목록은 건드리지 않는다(읽는 중 하이라이트가 사라진다). */
    public function refresh(): void
    {
        // 검차 보류는 board 값이라 ERP 조회와 무관하게 먼저 센다. 새 보류 = 빨간 토스트 + 비프(거절과 같은 무게).
        $holdUnread = $this->holdQuery()->where('inspection_skipped_at', '>', Carbon::parse($this->seenAt))->count();
        $holdFresh = $this->holdQuery()->where('inspection_skipped_at', '>', Carbon::parse($this->pollFrom))->count();
        if ($holdFresh > 0) {
            $this->dispatch('forward-arrived', type: 'rejected', msg: __('nav.bell.toast_hold', ['count' => $holdFresh]));
        }
        // 긴급 검차 결정(P1) — 승인 = 초록(확인과 같은 무게), 기각 = 빨간(거절과 같은 무게).
        $holdUnread += $this->urgentQuery()->where('inspection_urgent_decided_at', '>', Carbon::parse($this->seenAt))->count();
        $urgentFresh = $this->urgentQuery()->where('inspection_urgent_decided_at', '>', Carbon::parse($this->pollFrom))->get();
        $urgentOk = $urgentFresh->where('inspection_urgent_decision', 'approved')->count();
        if ($urgentOk > 0) {
            $this->dispatch('forward-arrived', type: 'done', msg: __('nav.bell.toast_urgent_approved', ['count' => $urgentOk]));
        }
        if ($urgentFresh->count() - $urgentOk > 0) {
            $this->dispatch('forward-arrived', type: 'rejected', msg: __('nav.bell.toast_urgent_rejected', ['count' => $urgentFresh->count() - $urgentOk]));
        }

        $lines = $this->lines($this->seenAt);
        if ($lines === null) {
            $this->loadFailed = true;
            $this->unread = $holdUnread;
            $this->pollFrom = now()->toIso8601String();

            return;
        }
        $this->loadFailed = false;

        $results = array_values(array_filter($lines, fn ($l) => self::isResult($l)));
        $this->unread = $holdUnread + count(array_filter($results, fn ($l) => self::resolvedAfter($l, $this->seenAt)));

        $fresh = array_filter($results, fn ($l) => self::resolvedAfter($l, $this->pollFrom));
        $rejected = count(array_filter($fresh, fn ($l) => $l['status'] === 'rejected'));
        $done = count($fresh) - $rejected;
        if ($rejected > 0) {
            $this->dispatch('forward-arrived', type: 'rejected', msg: __('nav.bell.toast_rejected', ['count' => $rejected]));
        }
        if ($done > 0) {
            $this->dispatch('forward-arrived', type: 'done', msg: __('nav.bell.toast_done', ['count' => $done]));
        }
        $this->pollFrom = now()->toIso8601String();
    }

    /** 패널 열기 = 목록 적재 + 읽음 처리. 하이라이트(`new`)는 **열기 전** 열람 시각 기준으로 한 번 계산해 둔다. */
    public function openPanel(): void
    {
        $this->open = true;
        $seen = Carbon::parse($this->seenAt);
        $this->holds = $this->holdQuery()->limit(20)->get()->map(fn ($l) => [
            'id' => $l->id,
            'plate' => $l->vehicle_number,
            'text' => ($h = $l->ssancarHoldHint()) ? __($h['key'], $h['params']) : '',
            'at' => $l->inspection_skipped_at->timezone(config('app.timezone'))->format('m-d H:i'),
            'new' => $l->inspection_skipped_at->gt($seen),
        ])->all();
        $this->urgents = $this->urgentQuery()->limit(20)->get()->map(fn ($l) => [
            'id' => $l->id,
            'plate' => $l->vehicle_number,
            'approved' => $l->inspection_urgent_decision === 'approved',
            'text' => $l->inspection_urgent_decision === 'approved'
                ? __('nav.bell.urgent_approved_body', ['date' => $l->inspection_work_date?->format('m-d') ?? '—', 'by' => $l->inspection_urgent_decided_by ?: '—'])
                : __('nav.bell.urgent_rejected_body', ['by' => $l->inspection_urgent_decided_by ?: '—', 'note' => $l->inspection_urgent_decision_note ? ' · '.$l->inspection_urgent_decision_note : '']),
            'at' => $l->inspection_urgent_decided_at->timezone(config('app.timezone'))->format('m-d H:i'),
            'new' => $l->inspection_urgent_decided_at->gt($seen),
        ])->all();
        // 패널도 전체 이력을 끌어오지 않는다 — 최근 PANEL_WINDOW_DAYS 일(ERP `since` = updated_at 기준)만.
        //   진행 중(open) 라인도 같은 호출에서 나오므로 그보다 오래 방치된 open 요청은 여기 안 보인다(포털 칩엔 보인다).
        //   잔금은 지급 시 자동소멸·계약금은 계약금 행 저장 시 소멸이라 90일 넘게 열린 요청은 사실상 없다.
        $lines = $this->lines(now()->subDays(self::PANEL_WINDOW_DAYS)->toIso8601String());
        if ($lines === null) {
            $this->loadFailed = true;
            $this->results = [];
            $this->inProgress = [];
            // 보류는 board 값이라 읽음 처리한다(ERP 결과는 다음에 다시 센다 — 열람 시각을 옮기면 ERP 쪽 미읽음이 사라지므로 안 옮긴다).
            $this->unread = 0;

            return;
        }
        $this->loadFailed = false;

        $results = array_values(array_filter($lines, fn ($l) => self::isResult($l)));
        usort($results, fn ($a, $b) => strcmp((string) $b['resolved_at'], (string) $a['resolved_at']));
        $this->results = array_map(
            fn ($l) => $l + ['new' => self::resolvedAfter($l, $this->seenAt)],
            array_slice($results, 0, self::MAX_RESULTS)
        );

        $open = array_values(array_filter($lines, fn ($l) => $l['status'] === 'open'));
        usort($open, fn ($a, $b) => strcmp((string) $b['requested_at'], (string) $a['requested_at']));
        $this->inProgress = $open;

        // 읽음 — board 가 바꾸는 건 **자기 열람 시각**뿐. ERP 라인 상태는 손대지 않는다(§11-5).
        Auth::user()->forceFill(['portal_seen_at' => now()])->save();
        $this->seenAt = now()->toIso8601String();
        $this->unread = 0;
    }

    public function closePanel(): void
    {
        $this->open = false;
    }

    /** 신호 종류 라벨 — 포털 버튼 이름 그대로(새 이름 짓지 않는다). 모르는 type 은 원문. */
    public function typeLabel(string $type): string
    {
        return match ($type) {
            CarErpReadService::REQ_PURCHASE_DEPOSIT => __('portal.req_deposit_btn'),
            CarErpReadService::REQ_PURCHASE_BALANCE => __('portal.req_balance_btn'),
            CarErpReadService::REQ_PURCHASE_DEPOSIT_CEO => __('portal.req_deposit_ceo_btn'),
            CarErpReadService::REQ_SALE_CONFIRM => __('portal.req_sale_btn'),
            CarErpReadService::REQ_PURCHASE_LEGACY => __('portal.req_purchase_btn'),
            default => $type,
        };
    }

    public function fmtAt(?string $iso): string
    {
        if (! $iso) {
            return '';
        }
        try {
            return Carbon::parse($iso)->timezone(config('app.timezone'))->format('m-d H:i');
        } catch (\Throwable) {
            return (string) $iso;
        }
    }
}; ?>

<div class="relative" wire:init="refresh" wire:poll.30s="refresh"
     x-data
     @keydown.escape.window="$wire.open && $wire.closePanel()">
    <button type="button" wire:click="openPanel"
            class="relative flex h-8 w-8 items-center justify-center rounded text-gray-600 transition hover:bg-gray-100"
            aria-label="{{ __('nav.bell.aria') }}" title="{{ __('nav.bell.title') }}">
        <svg class="h-[18px] w-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 10-12 0v3.2a2 2 0 01-.6 1.4L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
        </svg>
        @if ($unread > 0)
            <span class="absolute -right-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-red-600 px-1 text-[10px] font-bold text-white">{{ $unread > 99 ? '99+' : $unread }}</span>
        @elseif ($loadFailed)
            {{-- 조회 실패를 감추지 않는다 — 회색 점 = "지금 상태를 모른다". --}}
            <span class="absolute -right-0.5 -top-0.5 h-2.5 w-2.5 rounded-full bg-gray-400" title="{{ __('nav.bell.unavailable') }}"></span>
        @endif
    </button>

    @if ($open)
        {{-- 모바일 = 상단바 바로 아래 전체 너비 시트(하단 모서리는 토스트·챗봇이 쓴다) / md 이상 = 종 아래 우측 정렬 드롭다운. --}}
        <div @click.outside="$wire.closePanel()"
             class="fixed inset-x-0 top-11 z-50 flex max-h-[75vh] flex-col border-b border-gray-200 bg-white shadow-xl
                    md:absolute md:inset-x-auto md:right-0 md:top-full md:mt-1 md:max-h-[70vh] md:w-[380px] md:rounded-lg md:border">
            <div class="flex items-center justify-between border-b border-gray-100 px-3 py-2">
                <span class="text-[13px] font-bold text-gray-800">🔔 {{ __('nav.bell.title') }}</span>
                <button type="button" wire:click="closePanel" class="rounded px-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-700" aria-label="close">✕</button>
            </div>

            <div class="flex-1 overflow-y-auto">
                @if ($holds)
                    <div class="px-3 pt-2 text-[11px] font-semibold uppercase tracking-wide text-gray-400">{{ __('nav.bell.holds') }}</div>
                    @foreach ($holds as $h)
                        <a wire:key="bell-h-{{ $h['id'] }}" href="{{ route('listings') }}" wire:navigate class="block border-b border-gray-50 px-3 py-2 hover:bg-gray-50 {{ $h['new'] ? 'bg-red-50/60' : '' }}">
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-[13px] font-semibold text-gray-800">{{ \App\Support\Plate::show($h['plate']) }}</span>
                                <span class="badge badge-red shrink-0 whitespace-nowrap">{{ __('nav.bell.hold_chip') }}</span>
                            </div>
                            <p class="mt-0.5 text-[12px] text-red-700">{{ $h['text'] }}</p>
                            <p class="mt-0.5 text-[11px] text-gray-400">{{ $h['at'] }}@if ($h['new'])<span class="ml-1 rounded bg-red-600 px-1 text-[10px] font-bold text-white">{{ __('nav.bell.new') }}</span>@endif</p>
                        </a>
                    @endforeach
                @endif

                {{-- 긴급 검차 결정(P1, 2026-10-29) — ssancar 업무관리자가 승인(초록)·기각(빨강). board 값이라 ERP 가 죽어도 보인다. --}}
                @if ($urgents)
                    <div class="px-3 pt-2 text-[11px] font-semibold uppercase tracking-wide text-gray-400">{{ __('nav.bell.urgents') }}</div>
                    @foreach ($urgents as $g)
                        <a wire:key="bell-u-{{ $g['id'] }}" href="{{ route('listings') }}" wire:navigate class="block border-b border-gray-50 px-3 py-2 hover:bg-gray-50 {{ $g['new'] ? ($g['approved'] ? 'bg-green-50/60' : 'bg-red-50/60') : '' }}">
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-[13px] font-semibold text-gray-800">{{ \App\Support\Plate::show($g['plate']) }}</span>
                                <span class="badge {{ $g['approved'] ? 'badge-green' : 'badge-red' }} shrink-0 whitespace-nowrap">{{ $g['approved'] ? __('nav.bell.urgent_chip_approved') : __('nav.bell.urgent_chip_rejected') }}</span>
                            </div>
                            <p class="mt-0.5 text-[12px] {{ $g['approved'] ? 'text-green-700' : 'text-red-700' }}">{{ $g['text'] }}</p>
                            <p class="mt-0.5 text-[11px] text-gray-400">{{ $g['at'] }}@if ($g['new'])<span class="ml-1 rounded bg-red-600 px-1 text-[10px] font-bold text-white">{{ __('nav.bell.new') }}</span>@endif</p>
                        </a>
                    @endforeach
                @endif

                @if ($loadFailed)
                    <p class="px-3 py-2 text-[11px] text-amber-600">⚠️ {{ __('nav.bell.unavailable') }}</p>
                @endif

                <div class="px-3 pt-2 text-[11px] font-semibold uppercase tracking-wide text-gray-400">{{ __('nav.bell.results') }}</div>
                @forelse ($results as $r)
                    @php
                        $isRejected = $r['status'] === 'rejected';
                    @endphp
                    <div wire:key="bell-r-{{ md5($r['key']) }}" class="border-b border-gray-50 px-3 py-2 {{ $r['new'] ? ($isRejected ? 'bg-red-50/60' : 'bg-green-50/60') : '' }}">
                        <div class="flex items-center justify-between gap-2">
                            <span class="min-w-0 truncate text-[13px] font-semibold text-gray-800">
                                {{ \App\Support\Plate::show($r['vehicle_number']) ?: '—' }}
                                <span class="ml-1 font-normal text-gray-500">{{ $this->typeLabel($r['type']) }}</span>
                                @if (is_numeric($r['amount']))<span class="ml-1 font-normal tabular-nums text-gray-600">{{ number_format((float) $r['amount']) }}</span>@endif
                            </span>
                            <span class="badge {{ $isRejected ? 'badge-red' : 'badge-green' }} shrink-0 whitespace-nowrap">{{ $isRejected ? __('portal.req_chip_rejected') : __('portal.req_chip_done') }}</span>
                        </div>
                        @if ($isRejected && trim((string) $r['reason']) !== '')
                            <p class="mt-0.5 text-[12px] text-red-700">{{ __('portal.req_reject_reason', ['reason' => trim((string) $r['reason'])]) }}</p>
                        @endif
                        <p class="mt-0.5 text-[11px] text-gray-400">
                            {{ $this->fmtAt($r['resolved_at']) }}
                            @if ($r['resolved_by']) · {{ __('nav.bell.resolved_by', ['name' => $r['resolved_by']]) }}@endif
                            @if ($r['buyer_name']) · {{ $r['buyer_name'] }}@endif
                            @if ($r['new'])<span class="ml-1 rounded bg-red-600 px-1 text-[10px] font-bold text-white">{{ __('nav.bell.new') }}</span>@endif
                        </p>
                    </div>
                @empty
                    @unless ($loadFailed)<p class="px-3 py-2 text-[12px] text-gray-400">{{ __('nav.bell.empty_results') }}</p>@endunless
                @endforelse

                <div class="px-3 pt-3 text-[11px] font-semibold uppercase tracking-wide text-gray-400">{{ __('nav.bell.in_progress') }}</div>
                @forelse ($inProgress as $p)
                    <div wire:key="bell-p-{{ md5($p['key']) }}" class="border-b border-gray-50 px-3 py-2">
                        <div class="flex items-center justify-between gap-2">
                            <span class="min-w-0 truncate text-[13px] font-semibold text-gray-800">
                                {{ \App\Support\Plate::show($p['vehicle_number']) ?: '—' }}
                                <span class="ml-1 font-normal text-gray-500">{{ $this->typeLabel($p['type']) }}</span>
                                @if (is_numeric($p['amount']))<span class="ml-1 font-normal tabular-nums text-gray-600">{{ number_format((float) $p['amount']) }}</span>@endif
                            </span>
                            <span class="badge badge-amber shrink-0 whitespace-nowrap">{{ __('portal.req_chip_open') }}</span>
                        </div>
                        <p class="mt-0.5 text-[11px] text-gray-400">{{ __('nav.bell.requested_at', ['at' => $this->fmtAt($p['requested_at'])]) }}@if ($p['buyer_name']) · {{ $p['buyer_name'] }}@endif</p>
                    </div>
                @empty
                    @unless ($loadFailed)<p class="px-3 py-2 text-[12px] text-gray-400">{{ __('nav.bell.empty_in_progress') }}</p>@endunless
                @endforelse
            </div>

            <div class="border-t border-gray-100 px-3 py-1.5 text-[11px] text-gray-400">
                <a href="{{ route('portal') }}" wire:navigate class="hover:underline">{{ __('nav.bell.footer') }}</a>
            </div>

            {{-- 웹푸시 토글 — 화면이 꺼져 있어도 폰이 울리게(Jin 2026-10-12 「알림톡 말고 웹푸시로」). VAPID 가 있을 때만.
                 상태는 브라우저가 진실(구독 유무)이라 Alpine 이 매번 묻는다. 'error' 는 콘솔에 사유. --}}
            @if (WebPushService::configured())
                <div class="border-t border-gray-100 px-3 py-2 text-[12px]"
                     x-data="{ st: 'unknown', busy: false,
                               async load() { this.st = window.__boardPush ? await window.__boardPush.status() : 'unsupported'; },
                               async go(fn) { this.busy = true; try { this.st = await window.__boardPush[fn](); } finally { this.busy = false; } } }"
                     x-init="load()">
                    <template x-if="st === 'off' || st === 'error'">
                        <button type="button" @click="go('enable')" :disabled="busy" class="btn-outline btn-sm">📳 {{ __('nav.bell.push_enable') }}</button>
                    </template>
                    <template x-if="st === 'on'">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-green-700">📳 {{ __('nav.bell.push_on') }}</span>
                            <button type="button" @click="go('disable')" :disabled="busy" class="btn-ghost btn-sm">{{ __('nav.bell.push_disable') }}</button>
                        </div>
                    </template>
                    <template x-if="st === 'denied'"><span class="text-amber-600">{{ __('nav.bell.push_denied') }}</span></template>
                    <template x-if="st === 'unsupported'"><span class="text-gray-400">{{ __('nav.bell.push_unsupported') }}</span></template>
                    <template x-if="st === 'error'"><span class="ml-2 text-red-600">{{ __('nav.bell.push_error') }}</span></template>
                </div>
            @endif
        </div>
    @endif
</div>

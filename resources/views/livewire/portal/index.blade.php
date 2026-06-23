<?php

use App\Models\User;
use App\Services\CarErpReadService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * 영업 포털 — car-erp 읽기 미러(④재무) + 선적요청(③) + 서류(①②). 권위 = car-erp board-portal-api.md.
 * 전부 본인 스코프(salesman_email = Auth car_erp_salesman_email ?: email, 요청 파라미터 금지).
 * degrade: 미설정/401/5xx/403 → "조회 불가"(0원/완납 coerce 금지). 미수금 null = "환율 미입력" 보존.
 * 응답 키 = car-erp InternalPortalController/ShippingRequestController 와 정합(2026-06-18 확인).
 */
new #[Layout('components.layouts.app')] class extends Component {
    public string $tab = 'finance';

    public ?int $viewUserId = null;   // super 전용 — 다른 사용자 포털 열람 대상(본인=null). 비-super 는 무시(본인 격리 유지).

    public array $result = ['ok' => false, 'reason' => 'init', 'data' => null, 'status' => 0];

    // ③ 선적요청 빌더 상태
    public array $selectedIds = [];            // 체크한 vehicle_id

    public array $consigneeByBuyer = [];       // [buyer_id => consignee_id]

    public array $methodByBuyer = [];          // [buyer_id => 'RORO'|'CONTAINER']

    public ?array $shipDone = null;    // 선적요청 성공(접수/건너뜀 건수) — 큰 배너

    public ?string $shipNote = null;   // 선적/서류 경고·오류 — 작은 안내

    // 미수금 정렬/필터
    public string $recvSort = 'unpaid_krw';   // 기본 = 미수금 많은 순

    public string $recvDir = 'desc';

    public bool $hidePaid = true;             // 0원(완납) 숨김

    public array $monthly = [];               // 요약 탭 월별 집계(판매 건수·정산 실지급·매입)

    public array $salesDetail = [];           // 판매내역 펼침용 per-vehicle (바이어별 차량 리스트)

    private const TABS = ['finance', 'receivables', 'purchases', 'sales', 'settlements', 'shipping'];

    /**
     * 월별 집계 — 날짜 있는 리스트(판매/정산/매입)에서 YYYY-MM 별 집계.
     * 판매는 통화 혼재라 건수만(합산 무의미). 정산 실지급·매입가는 원화 합산.
     * 미수금은 날짜 없어 제외. (car-erp 정산 바이어 추가 시 buyer 차원 확장 가능.)
     */
    private function buildMonthly(string $email): array
    {
        $svc = $this->svc();
        $m = [];
        $bump = function (array $env, string $dateKey, ?string $amtKey, string $cnt, ?string $sum) use (&$m) {
            if (! ($env['ok'] ?? false)) {
                return;
            }
            foreach ((array) data_get($env['data'], 'data', []) as $r) {
                $d = data_get($r, $dateKey);
                if (! $d) {
                    continue;
                }
                $key = substr((string) $d, 0, 7);   // YYYY-MM
                $m[$key][$cnt] = ($m[$key][$cnt] ?? 0) + 1;
                if ($amtKey && $sum) {
                    $m[$key][$sum] = ($m[$key][$sum] ?? 0) + (float) (data_get($r, $amtKey) ?? 0);
                }
            }
        };
        $bump($svc->sales($email), 'sale_date', null, 'sales_cnt', null);
        $bump($svc->settlements($email), 'confirmed_at', 'actual_payout', 'settle_cnt', 'settle_sum');
        $bump($svc->purchases($email), 'purchase_date', 'purchase_price', 'purch_cnt', 'purch_sum');
        krsort($m);   // 최근 월 먼저

        return $m;
    }

    /** 미수금 컬럼 정렬 토글. */
    public function sortRecv(string $key): void
    {
        if ($this->recvSort === $key) {
            $this->recvDir = $this->recvDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->recvSort = $key;
            $this->recvDir = in_array($key, ['unpaid_krw', 'exchange_rate'], true) ? 'desc' : 'asc';
        }
    }

    public function mount(): void
    {
        $this->load();
    }

    public function setTab(string $tab): void
    {
        if (! in_array($tab, self::TABS, true)) {
            return;
        }
        $this->tab = $tab;
        $this->shipDone = null;
        $this->shipNote = null;
        $this->load();
    }

    public function reload(): void
    {
        $this->load();
    }

    private function svc(): CarErpReadService
    {
        return app(CarErpReadService::class);
    }

    /**
     * car-erp 영업 매칭 이메일 — 오버라이드 우선, 없으면 로그인.
     * super 가 다른 사용자를 열람 중이면 그 사용자 기준(서버 isSuper 게이트). 비-super 는 항상 본인.
     */
    private function salesmanEmail(): string
    {
        $u = $this->viewingUser() ?? Auth::user();

        return $u->car_erp_salesman_email ?: $u->email;
    }

    /** super 전용 — 포털 열람 가능한 사용자(영업·관리, 활성). 비-super 는 빈 목록. */
    #[Computed]
    public function portalUsers()
    {
        if (! Auth::user()->isSuper()) {
            return collect();
        }

        return User::whereIn('role', ['sales', 'manager'])
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'role', 'email', 'car_erp_salesman_email']);
    }

    /** 현재 열람 중인 사용자 — super 가 지정했고 목록에 있는 유효 대상일 때만. 그 외(비-super 포함) null=본인. */
    private function viewingUser(): ?User
    {
        if ($this->viewUserId === null || ! Auth::user()->isSuper()) {
            return null;
        }

        return $this->portalUsers->firstWhere('id', $this->viewUserId);
    }

    /** 화면에 표시할 열람 대상 이름(본인 또는 super 가 선택한 사용자). */
    public function viewingName(): string
    {
        return ($this->viewingUser() ?? Auth::user())->name;
    }

    /** super 가 다른 사용자를 열람 중인가 — 그렇다면 쓰기(선적요청·서류)는 조회 전용 차단. */
    public function isViewingOther(): bool
    {
        return $this->viewingUser() !== null;
    }

    /** super — 열람 대상 변경(이름 클릭). null=본인. 비-super 는 무시(본인 격리). 목록 밖 id 도 무시. */
    public function viewUser(?int $id): void
    {
        if (! Auth::user()->isSuper()) {
            return;
        }
        $this->viewUserId = ($id !== null && $this->portalUsers->contains('id', $id)) ? $id : null;
        // 대상이 바뀌면 선적 빌더 상태(이전 사용자 차량 id)를 초기화.
        $this->reset(['selectedIds', 'consigneeByBuyer', 'methodByBuyer', 'shipDone', 'shipNote']);
        $this->load();
    }

    private function load(): void
    {
        $email = $this->salesmanEmail();
        $svc = $this->svc();
        $this->result = match ($this->tab) {
            'receivables' => $svc->receivables($email),
            'purchases' => $svc->purchases($email),
            // 판매·정산 = 바이어별 집계(통화별 판매합 / 정산 payout). 같은 by-buyer 엔드포인트.
            'sales', 'settlements' => $svc->byBuyer($email),
            'shipping' => $svc->shippable($email),
            default => $svc->finance($email),
        };
        // 판매내역 = by-buyer(헤더) + per-vehicle 상세(펼침용 차량 리스트).
        $this->salesDetail = [];
        if ($this->tab === 'sales') {
            $s = $svc->sales($email);
            $this->salesDetail = ($s['ok'] ?? false) ? (array) data_get($s['data'], 'data', []) : [];
        }
        // 요약 탭 = 합계 + 월별(판매/정산/매입). 다른 탭은 월별 불필요.
        $this->monthly = $this->tab === 'finance' ? $this->buildMonthly($email) : [];
    }

    /** 선적요청 — 한 바이어 묶음(선택 차 + 컨사이니 + RORO/CONTAINER). */
    public function submitShipping(int $buyerId, array $vehicleIds): void
    {
        // 조회 전용 — super 가 타인 포털 열람 중엔 쓰기 차단(본인 계정에서만 요청). 서버 게이트.
        if ($this->isViewingOther()) {
            $this->shipNote = __('portal.flash_view_only_ship');

            return;
        }
        $this->shipDone = null;
        $ids = array_values(array_intersect(array_map('intval', $this->selectedIds), $vehicleIds));
        if ($ids === []) {
            $this->shipNote = __('portal.flash_select_vehicle');

            return;
        }

        $res = $this->svc()->shippingRequest($this->salesmanEmail(), [
            'vehicle_ids' => $ids,
            'buyer_id' => $buyerId,
            'consignee_id' => $this->consigneeByBuyer[$buyerId] ?? null,
            'shipping_method' => $this->methodByBuyer[$buyerId] ?? 'RORO',
            'requested_at' => now()->toIso8601String(),
        ]);

        if (! ($res['ok'] ?? false)) {
            $this->shipNote = __('portal.flash_ship_failed');

            return;
        }

        $this->shipNote = null;
        $this->shipDone = [
            'created' => count($res['data']['created'] ?? []),
            'skipped' => count($res['data']['skipped'] ?? []),
        ];
        $this->selectedIds = [];
        $this->load();   // shippable 갱신(요청된 차는 목록서 빠짐)
    }

    /** ①② 서류 — 선택 차량의 선적서류(method별 4종 중 2종). xlsx 스트림 다운로드. */
    public function downloadDocs(int $buyerId, array $vehicleIds, string $kind)
    {
        // 조회 전용 — 타인 포털 열람 중엔 서류(타인 PII) 다운로드 차단. 서버 게이트.
        if ($this->isViewingOther()) {
            $this->shipNote = __('portal.flash_view_only_docs');

            return null;
        }
        $ids = array_values(array_intersect(array_map('intval', $this->selectedIds), $vehicleIds));
        if ($ids === []) {
            $this->shipNote = __('portal.flash_select_vehicle_docs');

            return null;
        }
        $method = strtolower($this->methodByBuyer[$buyerId] ?? 'RORO');   // roro|container
        $type = $method.'_'.$kind;   // roro_contract / container_invoice_packing ...

        $res = $this->svc()->document($type, $ids, $this->salesmanEmail());
        if (! ($res['ok'] ?? false)) {
            $this->shipNote = __('portal.flash_docs_failed');

            return null;
        }

        $body = $res['body'];
        $name = $type.'_'.implode('-', $ids).'.xlsx';

        return response()->streamDownload(fn () => print ($body), $name, [
            'Content-Type' => $res['content_type'] ?: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** 한글 축약 금액 (요약 탭 전용): 704369898 → "7억 436만원". */
    public function abbrevKrw(int|float|null $won): string
    {
        if ($won === null) {
            return '—';
        }
        $won = (int) $won;
        $abs = abs($won);
        if ($abs >= 100000000) {
            $eok = intdiv($won, 100000000);
            $man = intdiv(abs($won) % 100000000, 10000);

            return $man ? $eok.__('portal.abbr_eok').' '.number_format($man).__('portal.abbr_man') : $eok.__('portal.abbr_eok').__('portal.abbr_won');
        }
        if ($abs >= 10000) {
            return number_format(intdiv($won, 10000)).__('portal.abbr_man');
        }

        return number_format($won).__('portal.abbr_won');
    }

    public function degradeMessage(): string
    {
        return match ($this->result['status'] ?? 0) {
            403 => __('portal.degrade_403'),
            default => match ($this->result['reason'] ?? '') {
                'not_configured' => __('portal.degrade_not_configured'),
                default => __('portal.degrade_default'),
            },
        };
    }
}; ?>

<div class="p-3 md:p-6">
    <div class="mb-4">
        <h1 class="text-xl font-bold text-gray-800">{{ __('portal.title') }}</h1>
        @if (auth()->user()->isSuper() && $viewUserId !== null)
            <p class="mt-0.5 text-xs text-gray-500">👁️ {!! __('portal.viewing_other', ['name' => '<b class="text-[var(--color-primary-text)]">'.e($this->viewingName()).'</b>']) !!}</p>
        @else
            <p class="mt-0.5 text-xs text-gray-500">🔒 {{ __('portal.viewing_self', ['name' => auth()->user()->name]) }}</p>
        @endif
    </div>

    {{-- 사용자별 조회 (시스템관리자 전용) — 이름 클릭 시 그 사용자의 정산·미수·선적 표시 --}}
    @if (auth()->user()->isSuper())
        <div class="card-sm mb-3" style="background:#f8f9fb">
            <div class="mb-1.5 flex flex-wrap items-center gap-x-2 gap-y-0.5">
                <span class="text-[12px] font-semibold text-gray-600">👁️ {{ __('portal.view_by_user') }}</span>
                <span class="text-[11px] text-gray-400">{{ __('portal.view_by_user_hint') }}</span>
            </div>
            <div class="flex flex-wrap gap-1">
                <button wire:click="viewUser(null)"
                    class="rounded-md border px-2.5 py-1 text-[12px] font-semibold {{ $viewUserId === null ? 'border-[var(--color-primary)] bg-[var(--color-primary)] text-white' : 'border-gray-300 bg-white text-gray-600' }}">{{ __('portal.view_self_btn') }}</button>
                @foreach ($this->portalUsers as $pu)
                    <button wire:click="viewUser({{ $pu->id }})"
                        class="rounded-md border px-2.5 py-1 text-[12px] font-semibold {{ $viewUserId === $pu->id ? 'border-[var(--color-primary)] bg-[var(--color-primary)] text-white' : 'border-gray-300 bg-white text-gray-600' }}">
                        {{ $pu->name }} <span class="text-[10px] font-normal opacity-70">{{ $pu->roleLabel() }}</span>
                    </button>
                @endforeach
            </div>
        </div>
    @endif

    {{-- 탭 --}}
    <div class="mb-3 flex flex-wrap gap-1">
        @foreach (['finance' => __('portal.tab.finance'), 'receivables' => __('portal.tab.receivables'), 'purchases' => __('portal.tab.purchases'), 'sales' => __('portal.tab.sales'), 'settlements' => __('portal.tab.settlements'), 'shipping' => '🚢 '.__('portal.tab.shipping')] as $key => $label)
            <button wire:click="setTab('{{ $key }}')"
                class="rounded-md border px-3 py-1.5 text-[13px] font-semibold {{ $tab === $key ? 'border-[var(--color-primary)] bg-[var(--color-primary)] text-white' : 'border-gray-300 bg-white text-gray-600' }}">{{ $label }}</button>
        @endforeach
        <button wire:click="reload" class="ml-auto rounded-md border border-gray-300 bg-white px-3 py-1.5 text-[13px] text-blue-600" title="{{ __('portal.reload_title') }}">↻ {{ __('portal.reload') }}</button>
    </div>

    {{-- 선적요청 성공 — 크게, 확실하게 --}}
    @if ($shipDone)
        <div wire:key="shipdone-{{ $shipDone['created'] }}-{{ $shipDone['skipped'] }}"
             x-data="{ show: true }" x-show="show" x-transition.scale.origin.top
             class="mb-4 flex items-center gap-4 rounded-xl border-2 border-green-400 bg-green-50 px-5 py-4 shadow-md">
            <div class="text-4xl">✅</div>
            <div class="flex-1">
                <div class="text-lg font-bold text-green-800">{{ __('portal.ship_done_title') }}</div>
                <div class="mt-0.5 text-[15px] text-green-700">
                    {!! __('portal.ship_done_body', ['count' => '<b class="text-xl text-green-800">'.e($shipDone['created']).'</b>']) !!}
                    @if ($shipDone['skipped']) <span class="text-amber-600">{{ __('portal.ship_done_skipped', ['count' => $shipDone['skipped']]) }}</span> @endif
                </div>
                <div class="mt-1 text-[12px] text-green-600">📨 {{ __('portal.ship_done_alarm') }}</div>
            </div>
            <button @click="show = false" class="self-start text-green-400 hover:text-green-700" title="{{ __('common.close') }}">✕</button>
        </div>
    @endif
    @if ($shipNote)
        <div class="card-sm mb-3 border-amber-200 bg-amber-50 text-[13px] text-amber-700">⚠️ {{ $shipNote }}</div>
    @endif

    <div class="card">
        @if (! ($result['ok'] ?? false))
            <div class="card-sm border-amber-200 bg-amber-50 text-[13px] text-amber-800">
                ⚠️ <b>{{ __('portal.unavailable') }}</b> — {{ $this->degradeMessage() }}
            </div>

        @elseif ($tab === 'finance')
            @php $sum = is_array($result['data']) ? $result['data'] : []; @endphp
            <div class="grid gap-3 sm:grid-cols-4">
                <div class="card-sm"><div class="text-xs text-gray-500">{{ __('portal.kpi_unpaid_total') }}</div><div class="mt-1 text-lg font-bold text-gray-800" title="{{ isset($sum['unpaid_total_krw']) ? number_format((int) $sum['unpaid_total_krw']).__('common.won_currency') : '' }}">{{ isset($sum['unpaid_total_krw']) ? $this->abbrevKrw($sum['unpaid_total_krw']) : '—' }}</div></div>
                <div class="card-sm"><div class="text-xs text-gray-500">{{ __('portal.kpi_purchase_unpaid_total') }}</div><div class="mt-1 text-lg font-bold text-gray-800" title="{{ isset($sum['purchase_unpaid_total']) ? number_format((int) $sum['purchase_unpaid_total']).__('common.won_currency') : '' }}">{{ isset($sum['purchase_unpaid_total']) ? $this->abbrevKrw($sum['purchase_unpaid_total']) : '—' }}</div></div>
                <div class="card-sm"><div class="text-xs text-gray-500">{{ __('portal.kpi_settlement_pending') }}</div><div class="mt-1 text-lg font-bold text-gray-800">{{ isset($sum['settlement_pending_count']) ? __('portal.unit_count', ['count' => $sum['settlement_pending_count']]) : '—' }}</div></div>
                <div class="card-sm"><div class="text-xs text-gray-500">{{ __('portal.kpi_fx_missing') }}</div><div class="mt-1 text-lg font-bold {{ ($sum['fx_missing_count'] ?? 0) ? 'text-amber-600' : 'text-gray-800' }}">{{ isset($sum['fx_missing_count']) ? __('portal.unit_count', ['count' => $sum['fx_missing_count']]) : '—' }}</div></div>
            </div>

            {{-- 월별 (판매 건수·정산 실지급·매입) — 날짜 있는 리스트서 집계. 판매액은 통화혼재라 건수만. --}}
            <div class="mt-4" wire:key="monthly" x-data="{ open: true }">
                <button type="button" class="mb-2 flex items-center gap-2 font-bold text-gray-700" @click="open = !open">
                    <span class="w-3 text-gray-400" x-text="open ? '▼' : '▶'"></span> 📅 {{ __('portal.monthly_perf') }}
                </button>
                <div x-show="open" x-cloak>
                    <div class="hidden overflow-x-auto sm:block">
                        <table class="tbl">
                            <thead><tr><th>{{ __('portal.col_month') }}</th><th>{{ __('portal.col_sales_cnt') }}</th><th>{{ __('portal.col_settle_sum') }}</th><th>{{ __('portal.col_purch_cnt') }}</th><th>{{ __('portal.col_purch_sum') }}</th></tr></thead>
                            <tbody>
                                @forelse ($monthly as $month => $row)
                                    <tr>
                                        <td class="font-semibold text-gray-700">{{ $month }}</td>
                                        <td>{{ $row['sales_cnt'] ?? 0 }}</td>
                                        <td>{{ number_format((float) ($row['settle_sum'] ?? 0)) }}</td>
                                        <td>{{ $row['purch_cnt'] ?? 0 }}</td>
                                        <td>{{ number_format((float) ($row['purch_sum'] ?? 0)) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="py-6 text-center text-gray-400">{{ __('portal.monthly_empty') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="space-y-2 sm:hidden">
                        @forelse ($monthly as $month => $row)
                            <div class="card-tight">
                                <div class="font-semibold text-gray-700">{{ $month }}</div>
                                <div class="mt-1 grid grid-cols-2 gap-x-3 gap-y-1 text-xs text-gray-600">
                                    <div>{{ __('portal.m_sales') }} <b class="text-gray-800">{{ $row['sales_cnt'] ?? 0 }}</b>{{ __('portal.count_suffix') }}</div>
                                    <div>{{ __('portal.m_purchase') }} <b class="text-gray-800">{{ $row['purch_cnt'] ?? 0 }}</b>{{ __('portal.count_suffix') }}</div>
                                    <div>{{ __('portal.m_settle') }} <b class="text-gray-800">{{ number_format((float) ($row['settle_sum'] ?? 0)) }}</b></div>
                                    <div>{{ __('portal.m_purch_price') }} <b class="text-gray-800">{{ number_format((float) ($row['purch_sum'] ?? 0)) }}</b></div>
                                </div>
                            </div>
                        @empty
                            <div class="py-6 text-center text-gray-400">{{ __('portal.monthly_empty') }}</div>
                        @endforelse
                    </div>
                    <p class="mt-1 text-[11px] text-gray-400">💡 {{ __('portal.monthly_note') }}</p>
                </div>
            </div>

        @elseif ($tab === 'shipping')
            {{-- ③ 선적요청 + ①② 서류 --}}
            @php
                $vehicles = collect(data_get($result['data'], 'data', []));
                $statusOf = fn ($v) => data_get($v, 'shipping_status', 'none');
                $inProgress = $vehicles->filter(fn ($v) => in_array($statusOf($v), ['requested', 'in_progress'], true))->values();
                $available = $vehicles->reject(fn ($v) => in_array($statusOf($v), ['requested', 'in_progress'], true));
            @endphp

            {{-- 진행 중인 선적요청 — 맨 위 묶음 카드(바이어+방식+상태). car-erp shipping_status 응답으로 표시. --}}
            @if ($inProgress->isNotEmpty())
                @php
                    $batches = $inProgress->groupBy(fn ($v) => (data_get($v, 'buyer.id') ?? 0).'|'.(data_get($v, 'requested_method') ?? '').'|'.$statusOf($v));
                @endphp
                <div class="mb-4">
                    <h3 class="mb-2 flex items-center gap-2 text-[14px] font-bold text-gray-800">🚚 {{ __('portal.ship_inprogress_title') }} <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-semibold text-gray-500">{{ __('portal.unit_vehicles', ['count' => $inProgress->count()]) }}</span></h3>
                    <div class="grid gap-2.5 lg:grid-cols-2">
                        @foreach ($batches as $batch)
                            @php
                                $b0 = $batch->first();
                                $st = $statusOf($b0);
                                $busy = $st === 'in_progress';
                                $method = data_get($b0, 'requested_method');
                            @endphp
                            <div class="overflow-hidden rounded-xl border bg-white shadow-sm {{ $busy ? 'border-blue-200' : 'border-amber-200' }}">
                                <div class="flex items-center justify-between gap-2 px-3.5 py-2.5 {{ $busy ? 'bg-blue-50' : 'bg-amber-50' }}">
                                    <div class="flex min-w-0 items-center gap-2">
                                        <span class="text-base">{{ $method === 'CONTAINER' ? '📦' : '🚢' }}</span>
                                        <div class="min-w-0">
                                            <div class="truncate text-[13px] font-bold text-gray-800">{{ data_get($b0, 'buyer.name') ?: __('portal.buyer_unassigned') }}</div>
                                            <div class="text-[11px] text-gray-500">{{ $method ?: __('portal.ship_method_undefined') }} · {{ __('portal.unit_vehicles', ['count' => $batch->count()]) }}</div>
                                        </div>
                                    </div>
                                    <span class="shrink-0 rounded-full px-2.5 py-1 text-[11px] font-bold {{ $busy ? 'bg-blue-600 text-white' : 'bg-amber-500 text-white' }}">{{ $busy ? __('portal.ship_status_in_progress') : __('portal.ship_status_requested') }}</span>
                                </div>
                                <div class="flex flex-wrap gap-1.5 px-3.5 py-2.5">
                                    @foreach ($batch as $v)
                                        <span class="rounded-md border border-gray-200 bg-gray-50 px-2 py-0.5 text-[12px] font-semibold text-gray-700">{{ data_get($v, 'vehicle_number') }}</span>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <p class="mt-1.5 text-[11px] text-gray-400">💡 {!! __('portal.ship_inprogress_note') !!}</p>
                </div>
            @endif

            <p class="mb-3 text-[13px] text-gray-500">{!! __('portal.ship_intro') !!}</p>
            @php $byBuyer = $available->groupBy(fn ($v) => data_get($v, 'buyer.id') ?? 0); @endphp
            @forelse ($byBuyer as $buyerId => $group)
                @php
                    $buyerName = data_get($group->first(), 'buyer.name') ?? __('portal.buyer_unassigned_paren');
                    $consignees = data_get($group->first(), 'consignees', []);
                    $vIds = $group->pluck('vehicle_id')->map(fn ($i) => (int) $i)->all();
                    $method = $methodByBuyer[$buyerId] ?? 'RORO';
                @endphp
                <div class="card-sm mb-3" style="background:#f8f9fb" wire:key="ship-{{ $buyerId }}" x-data="{ open: false }">
                    <button type="button" class="flex w-full items-center gap-2 text-left font-bold text-gray-800" @click="open = !open">
                        <span class="w-3 text-gray-400" x-text="open ? '▼' : '▶'"></span>
                        🧑 {{ $buyerName }} <span class="text-xs font-normal text-gray-400">· {{ __('portal.ship_available_count', ['count' => count($vIds)]) }}</span>
                    </button>
                    <div x-show="open" x-cloak class="mt-2">
                    @if ($this->isViewingOther())
                        {{-- super 조회 전용 — 선적가능 차량 목록만 표시, 쓰기 액션(선적요청·서류) 숨김 --}}
                        <div class="mb-1 flex flex-wrap gap-1.5">
                            @foreach ($group as $v)
                                <span class="rounded-md border border-gray-200 bg-white px-2 py-0.5 text-[12px] font-semibold text-gray-700">{{ data_get($v, 'vehicle_number') }}</span>
                            @endforeach
                        </div>
                        <p class="text-[11px] text-gray-400">👁️ {{ __('portal.ship_view_only_note', ['name' => $this->viewingName()]) }}</p>
                    @else
                    <div class="mb-2 space-y-1">
                        @foreach ($group as $v)
                            <label class="flex items-center gap-2 text-[13px]">
                                <input type="checkbox" wire:model.live="selectedIds" value="{{ data_get($v, 'vehicle_id') }}">
                                <span class="font-semibold text-gray-700">{{ data_get($v, 'vehicle_number') }}</span>
                            </label>
                        @endforeach
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        {{-- 컨사이니 (기존 선택만) --}}
                        <select wire:model="consigneeByBuyer.{{ $buyerId }}" class="input-base w-auto text-[13px]">
                            <option value="">{{ __('portal.consignee_select') }}</option>
                            @foreach ($consignees as $c)
                                <option value="{{ data_get($c, 'id') }}">{{ data_get($c, 'name') }}</option>
                            @endforeach
                        </select>
                        {{-- RORO / CONTAINER --}}
                        <div class="inline-flex overflow-hidden rounded-md border border-gray-300">
                            @foreach (['RORO', 'CONTAINER'] as $m)
                                <button type="button" wire:click="$set('methodByBuyer.{{ $buyerId }}', '{{ $m }}')"
                                    class="px-3 py-1.5 text-[13px] font-semibold {{ $method === $m ? 'bg-[var(--color-primary)] text-white' : 'bg-white text-gray-600' }}">{{ $m }}</button>
                            @endforeach
                        </div>
                        <button wire:click="submitShipping({{ $buyerId }}, {{ json_encode($vIds) }})" class="btn-primary btn-sm">🚢 {{ __('portal.ship_request_btn') }}</button>
                    </div>
                    {{-- ①② 서류 (선택 차량, method별 2종) --}}
                    <div class="mt-2 flex flex-wrap items-center gap-2 border-t border-gray-200 pt-2 text-[12px]">
                        <span class="text-gray-400">{{ __('portal.docs_label', ['method' => $method]) }}</span>
                        <button wire:click="downloadDocs({{ $buyerId }}, {{ json_encode($vIds) }}, 'contract')" class="btn-ghost btn-sm">📄 {{ __('portal.docs_contract') }}</button>
                        <button wire:click="downloadDocs({{ $buyerId }}, {{ json_encode($vIds) }}, 'invoice_packing')" class="btn-ghost btn-sm">📄 {{ __('portal.docs_invoice_packing') }}</button>
                    </div>
                    @endif
                    </div>
                </div>
            @empty
                <p class="py-8 text-center text-gray-400">{{ __('portal.ship_empty') }}</p>
            @endforelse

        @elseif ($tab === 'receivables')
            {{-- 미수금 — 바이어별 접기 + 완납(0원) 숨김 + 컬럼 정렬 --}}
            @php
                $items = collect(data_get($result['data'], 'data', []));
                if ($hidePaid) {
                    $items = $items->reject(fn ($r) => ($v = data_get($r, 'unpaid_krw')) !== null && is_numeric($v) && (float) $v == 0.0);
                }
                $sortRows = function ($rows) {
                    $key = $this->recvSort;
                    $desc = $this->recvDir === 'desc';

                    return $rows->sort(function ($a, $b) use ($key, $desc) {
                        $x = data_get($a, $key);
                        $y = data_get($b, $key);
                        if ($x === null && $y === null) {
                            return 0;
                        }
                        if ($x === null) {
                            return 1;
                        }   // null 은 항상 뒤로
                        if ($y === null) {
                            return -1;
                        }
                        $cmp = is_numeric($x) && is_numeric($y) ? ((float) $x <=> (float) $y) : strcmp((string) $x, (string) $y);

                        return $desc ? -$cmp : $cmp;
                    })->values();
                };
                $byBuyer = $items->groupBy(fn ($r) => data_get($r, 'buyer') ?: __('portal.buyer_unassigned_paren'));
                $cols = ['vehicle_number' => __('portal.col_vehicle'), 'currency' => __('portal.col_currency'), 'exchange_rate' => __('portal.col_exchange_rate'), 'unpaid_krw' => __('portal.col_unpaid_krw')];
            @endphp
            <label class="mb-2 flex items-center gap-2 text-[12px] text-gray-500">
                <input type="checkbox" wire:model.live="hidePaid"> {{ __('portal.hide_paid') }}
            </label>
            @forelse ($byBuyer as $buyer => $rows)
                <div class="card-sm mb-2" wire:key="recv-{{ $loop->index }}" x-data="{ open: false }">
                    <button type="button" class="flex w-full items-center gap-2 text-left font-bold text-gray-800" @click="open = !open">
                        <span class="w-3 text-gray-400" x-text="open ? '▼' : '▶'"></span>
                        🧑 {{ $buyer }} <span class="text-xs font-normal text-gray-400">· {{ __('portal.unit_vehicles', ['count' => $rows->count()]) }}</span>
                        @php $sum = $rows->sum(fn ($r) => (float) (data_get($r, 'unpaid_krw') ?? 0)); @endphp
                        <span class="ml-auto text-[13px] font-bold text-[var(--color-primary-text)]">{{ number_format($sum) }}{{ __('common.won_currency') }}</span>
                    </button>
                    <div x-show="open" x-cloak class="mt-2">
                        <div class="hidden overflow-x-auto sm:block">
                            <table class="tbl">
                                <thead><tr>
                                    @foreach ($cols as $k => $label)
                                        <th><button type="button" wire:click="sortRecv('{{ $k }}')" class="flex items-center gap-1 font-semibold {{ $recvSort === $k ? 'text-[var(--color-primary-text)]' : '' }}">{{ $label }}<span class="text-[10px]">{{ $recvSort === $k ? ($recvDir === 'asc' ? '▲' : '▼') : '↕' }}</span></button></th>
                                    @endforeach
                                </tr></thead>
                                <tbody>
                                    @foreach ($sortRows($rows) as $row)
                                        <tr>
                                            @foreach ($cols as $k => $label)
                                                @php $val = data_get($row, $k); @endphp
                                                <td class="whitespace-nowrap {{ $val === null ? 'text-amber-600' : 'text-gray-700' }}">
                                                    @if ($val === null)
                                                        {{ $k === 'unpaid_krw' ? __('portal.fx_missing') : '—' }}
                                                    @elseif ($k === 'unpaid_krw' && is_numeric($val))
                                                        {{ number_format((float) $val) }}
                                                    @else
                                                        {{ $val }}
                                                    @endif
                                                </td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <div class="space-y-1.5 sm:hidden">
                            @foreach ($sortRows($rows) as $row)
                                @php $unpaid = data_get($row, 'unpaid_krw'); @endphp
                                <div class="flex items-center justify-between gap-2 rounded-md border border-gray-100 bg-gray-50 px-2.5 py-2">
                                    <div class="min-w-0">
                                        <div class="font-semibold text-gray-700">{{ data_get($row, 'vehicle_number') ?: '—' }}</div>
                                        <div class="text-[11px] text-gray-400">{{ data_get($row, 'currency') ?: '—' }} · {{ __('portal.fx_rate_label') }} {{ data_get($row, 'exchange_rate') ?? '—' }}</div>
                                    </div>
                                    <div class="shrink-0 text-right text-sm font-bold {{ $unpaid === null ? 'text-amber-600' : 'text-gray-800' }}">
                                        {{ $unpaid === null ? __('portal.fx_missing') : number_format((float) $unpaid).__('common.won_currency') }}
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @empty
                <p class="py-8 text-center text-gray-400">{{ __('portal.recv_empty') }}{{ $hidePaid ? __('portal.recv_empty_hidden') : '' }}</p>
            @endforelse

        @elseif ($tab === 'sales')
            {{-- 판매내역 — 바이어별 통화별 합(by-buyer) + 펼치면 그 바이어가 산 차량 리스트 --}}
            @php
                $buyers = data_get($result['data'], 'data', []);
                $detailByBuyer = collect($salesDetail)->groupBy(fn ($r) => data_get($r, 'buyer') ?: __('portal.buyer_unassigned_paren'));
            @endphp
            @forelse ($buyers as $b)
                @php
                    $bName = data_get($b, 'buyer') ?: __('portal.buyer_unassigned_paren');
                    $rows = $detailByBuyer[$bName] ?? collect();
                    $byCur = (array) data_get($b, 'sales_by_currency', []);
                @endphp
                <div class="card-sm mb-2" wire:key="sales-{{ $loop->index }}" x-data="{ open: false }">
                    <button type="button" class="flex w-full items-center gap-2 text-left font-bold text-gray-800" @click="open = !open">
                        <span class="w-3 text-gray-400" x-text="open ? '▼' : '▶'"></span>
                        🧑 {{ $bName }} <span class="text-xs font-normal text-gray-400">· {{ __('portal.unit_vehicles', ['count' => data_get($b, 'vehicle_count', 0)]) }}</span>
                        <span class="ml-auto text-[13px]">@forelse ($byCur as $cur => $amt)<span class="ml-2 whitespace-nowrap"><b class="text-gray-500">{{ $cur }}</b> {{ number_format((float) $amt) }}</span>@empty<span class="text-gray-300">—</span>@endforelse</span>
                    </button>
                    <div x-show="open" x-cloak class="mt-2">
                        <div class="hidden overflow-x-auto sm:block">
                            <table class="tbl">
                                <thead><tr><th>{{ __('portal.col_vehicle') }}</th><th>{{ __('portal.col_currency') }}</th><th>{{ __('portal.col_sale_price') }}</th><th>{{ __('portal.col_sale_date') }}</th></tr></thead>
                                <tbody>
                                    @forelse ($rows as $row)
                                        <tr>
                                            <td class="font-semibold text-gray-700">{{ data_get($row, 'vehicle_number') }}</td>
                                            <td>{{ data_get($row, 'currency') ?: '—' }}</td>
                                            <td>{{ ($p = data_get($row, 'sale_price')) !== null && is_numeric($p) ? number_format((float) $p) : '—' }}</td>
                                            <td>{{ data_get($row, 'sale_date') ?: '—' }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="4" class="py-3 text-center text-gray-400">{{ __('portal.sales_detail_empty') }}</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                        <div class="space-y-1.5 sm:hidden">
                            @forelse ($rows as $row)
                                <div class="flex items-center justify-between gap-2 rounded-md border border-gray-100 bg-gray-50 px-2.5 py-2">
                                    <div class="min-w-0">
                                        <div class="font-semibold text-gray-700">{{ data_get($row, 'vehicle_number') }}</div>
                                        <div class="text-[11px] text-gray-400">{{ data_get($row, 'sale_date') ?: '—' }}</div>
                                    </div>
                                    <div class="shrink-0 text-right text-sm font-semibold text-gray-800">
                                        {{ ($p = data_get($row, 'sale_price')) !== null && is_numeric($p) ? number_format((float) $p) : '—' }}
                                        <span class="text-[11px] font-normal text-gray-400">{{ data_get($row, 'currency') ?: '' }}</span>
                                    </div>
                                </div>
                            @empty
                                <div class="py-3 text-center text-gray-400">{{ __('portal.sales_detail_empty') }}</div>
                            @endforelse
                        </div>
                    </div>
                </div>
            @empty
                <p class="py-8 text-center text-gray-400">{{ __('portal.sales_empty') }}</p>
            @endforelse

        @elseif ($tab === 'settlements')
            {{-- 정산내역 — 바이어별 정산 실지급 (by-buyer, payout 내림차순) --}}
            @php $buyers = data_get($result['data'], 'data', []); @endphp
            <div class="hidden overflow-x-auto sm:block">
                <table class="tbl">
                    <thead><tr><th>{{ __('portal.col_buyer') }}</th><th>{{ __('portal.col_vehicle_count') }}</th><th>{{ __('portal.col_payout_total') }}</th><th>{{ __('portal.col_payout_paid') }}</th></tr></thead>
                    <tbody>
                        @forelse ($buyers as $b)
                            <tr>
                                <td class="font-semibold text-gray-700">{{ data_get($b, 'buyer') ?: __('portal.buyer_unassigned_paren') }}</td>
                                <td class="whitespace-nowrap text-gray-500">{{ __('portal.unit_vehicles', ['count' => data_get($b, 'vehicle_count', 0)]) }}</td>
                                <td class="whitespace-nowrap font-semibold text-gray-800">{{ number_format((float) data_get($b, 'payout_total_krw', 0)) }}</td>
                                <td class="whitespace-nowrap text-gray-500">{{ number_format((float) data_get($b, 'payout_paid_krw', 0)) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-8 text-center text-gray-400">{{ __('portal.settle_empty') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="space-y-2 sm:hidden">
                @forelse ($buyers as $b)
                    <div class="card-tight">
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-semibold text-gray-700">{{ data_get($b, 'buyer') ?: __('portal.buyer_unassigned_paren') }}</span>
                            <span class="shrink-0 text-xs text-gray-400">{{ __('portal.unit_vehicles', ['count' => data_get($b, 'vehicle_count', 0)]) }}</span>
                        </div>
                        <div class="mt-1 grid grid-cols-2 gap-x-3 text-xs text-gray-600">
                            <div>{{ __('portal.lbl_payout_total') }} <b class="text-gray-800">{{ number_format((float) data_get($b, 'payout_total_krw', 0)) }}</b></div>
                            <div>{{ __('portal.lbl_payout_paid') }} <b class="text-gray-800">{{ number_format((float) data_get($b, 'payout_paid_krw', 0)) }}</b></div>
                        </div>
                    </div>
                @empty
                    <div class="py-8 text-center text-gray-400">{{ __('portal.settle_empty') }}</div>
                @endforelse
            </div>

        @else
            {{-- 매입내역 — buyer 무관(경매/판매처) → 평면 목록 --}}
            @php
                $items = data_get($result['data'], 'data', []);
                $cols = ['vehicle_number' => __('portal.col_vehicle'), 'purchase_price' => __('portal.col_purchase_price'), 'cost_total' => __('portal.col_cost_total'), 'purchase_unpaid' => __('portal.col_purchase_unpaid'), 'purchase_date' => __('portal.col_purchase_date')];
            @endphp
            <div class="hidden overflow-x-auto sm:block">
                <table class="tbl">
                    <thead><tr>@foreach ($cols as $label)<th>{{ $label }}</th>@endforeach</tr></thead>
                    <tbody>
                        @forelse ($items as $row)
                            <tr>
                                @foreach ($cols as $key => $label)
                                    @php $val = data_get($row, $key); @endphp
                                    <td class="whitespace-nowrap text-gray-700">
                                        @if ($val === null)
                                            —
                                        @elseif (in_array($key, ['purchase_price', 'cost_total', 'purchase_unpaid'], true) && is_numeric($val))
                                            {{ number_format((float) $val) }}
                                        @else
                                            {{ $val }}
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @empty
                            <tr><td colspan="{{ count($cols) }}" class="py-8 text-center text-gray-400">{{ __('portal.purch_empty') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="space-y-2 sm:hidden">
                @forelse ($items as $row)
                    <div class="card-tight">
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-semibold text-gray-700">{{ data_get($row, 'vehicle_number') ?: '—' }}</span>
                            <span class="shrink-0 text-[11px] text-gray-400">{{ data_get($row, 'purchase_date') ?: '' }}</span>
                        </div>
                        <div class="mt-1 grid grid-cols-3 gap-x-2 text-xs text-gray-600">
                            @foreach (['purchase_price' => __('portal.col_purchase_price'), 'cost_total' => __('portal.col_cost_total'), 'purchase_unpaid' => __('portal.col_purchase_unpaid')] as $key => $label)
                                @php $val = data_get($row, $key); @endphp
                                <div>{{ $label }}<br><b class="text-gray-800">{{ ($val === null || ! is_numeric($val)) ? '—' : number_format((float) $val) }}</b></div>
                            @endforeach
                        </div>
                    </div>
                @empty
                    <div class="py-8 text-center text-gray-400">{{ __('portal.purch_empty') }}</div>
                @endforelse
            </div>
        @endif
    </div>

    <p class="mt-2 text-[11px] text-gray-400">💡 {{ __('portal.footer_note') }}</p>
</div>

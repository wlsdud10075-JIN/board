<?php

use App\Services\CarErpReadService;
use Illuminate\Support\Facades\Auth;
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

    public array $result = ['ok' => false, 'reason' => 'init', 'data' => null, 'status' => 0];

    // ③ 선적요청 빌더 상태
    public array $selectedIds = [];            // 체크한 vehicle_id

    public array $consigneeByBuyer = [];       // [buyer_id => consignee_id]

    public array $methodByBuyer = [];          // [buyer_id => 'RORO'|'CONTAINER']

    public ?string $shipMsg = null;

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
        $this->shipMsg = null;
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

    /** car-erp 영업 매칭 이메일 — 오버라이드 우선, 없으면 로그인. (요청값 절대 사용 안 함) */
    private function salesmanEmail(): string
    {
        $u = Auth::user();

        return $u->car_erp_salesman_email ?: $u->email;
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
        $ids = array_values(array_intersect(array_map('intval', $this->selectedIds), $vehicleIds));
        if ($ids === []) {
            $this->shipMsg = '차량을 선택하세요.';

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
            $this->shipMsg = '선적요청 전송 실패 — 잠시 후 다시 시도하세요.';

            return;
        }

        $created = count($res['data']['created'] ?? []);
        $skipped = count($res['data']['skipped'] ?? []);
        $this->shipMsg = "선적요청 완료: {$created}대 접수".($skipped ? " · {$skipped}대 건너뜀(이미 요청/대상 아님)" : '');
        $this->selectedIds = [];
        $this->load();   // shippable 갱신(요청된 차는 목록서 빠짐)
    }

    /** ①② 서류 — 선택 차량의 선적서류(method별 4종 중 2종). xlsx 스트림 다운로드. */
    public function downloadDocs(int $buyerId, array $vehicleIds, string $kind)
    {
        $ids = array_values(array_intersect(array_map('intval', $this->selectedIds), $vehicleIds));
        if ($ids === []) {
            $this->shipMsg = '서류 받을 차량을 선택하세요.';

            return null;
        }
        $method = strtolower($this->methodByBuyer[$buyerId] ?? 'RORO');   // roro|container
        $type = $method.'_'.$kind;   // roro_contract / container_invoice_packing ...

        $res = $this->svc()->document($type, $ids, $this->salesmanEmail());
        if (! ($res['ok'] ?? false)) {
            $this->shipMsg = '서류를 불러올 수 없습니다. (car-erp 연동 확인)';

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

            return $man ? $eok.'억 '.number_format($man).'만원' : $eok.'억원';
        }
        if ($abs >= 10000) {
            return number_format(intdiv($won, 10000)).'만원';
        }

        return number_format($won).'원';
    }

    public function degradeMessage(): string
    {
        return match ($this->result['status'] ?? 0) {
            403 => '내 영업 계정이 car-erp에 연결되지 않았습니다. (관리자에게 car-erp 영업 이메일 매핑을 요청하세요)',
            default => match ($this->result['reason'] ?? '') {
                'not_configured' => 'car-erp 연동이 아직 설정되지 않았습니다. (관리자 문의)',
                default => '지금은 car-erp 정보를 불러올 수 없습니다. 잠시 후 다시 시도하세요.',
            },
        };
    }
}; ?>

<div class="p-3 md:p-6">
    <div class="mb-4">
        <h1 class="text-xl font-bold text-gray-800">내 정산·미수·선적 (포털)</h1>
        <p class="mt-0.5 text-xs text-gray-500">🔒 본인({{ auth()->user()->name }}) 정보만 — car-erp 원장 읽기. 수정·선적실무는 car-erp.</p>
    </div>

    {{-- 탭 --}}
    <div class="mb-3 flex flex-wrap gap-1">
        @foreach (['finance' => '요약', 'receivables' => '미수금', 'purchases' => '매입내역', 'sales' => '판매내역', 'settlements' => '정산내역', 'shipping' => '🚢 선적요청'] as $key => $label)
            <button wire:click="setTab('{{ $key }}')"
                class="rounded-md border px-3 py-1.5 text-[13px] font-semibold {{ $tab === $key ? 'border-[var(--color-primary)] bg-[var(--color-primary)] text-white' : 'border-gray-300 bg-white text-gray-600' }}">{{ $label }}</button>
        @endforeach
        <button wire:click="reload" class="ml-auto rounded-md border border-gray-300 bg-white px-3 py-1.5 text-[13px] text-blue-600" title="새로고침">↻ 갱신</button>
    </div>

    @if ($shipMsg)
        <div class="card-sm mb-3 border-green-200 bg-green-50 text-[13px] text-green-700">✓ {{ $shipMsg }}</div>
    @endif

    <div class="card">
        @if (! ($result['ok'] ?? false))
            <div class="card-sm border-amber-200 bg-amber-50 text-[13px] text-amber-800">
                ⚠️ <b>조회 불가</b> — {{ $this->degradeMessage() }}
            </div>

        @elseif ($tab === 'finance')
            @php $sum = is_array($result['data']) ? $result['data'] : []; @endphp
            <div class="grid gap-3 sm:grid-cols-4">
                <div class="card-sm"><div class="text-xs text-gray-500">미수금 합계</div><div class="mt-1 text-lg font-bold text-gray-800" title="{{ isset($sum['unpaid_total_krw']) ? number_format((int) $sum['unpaid_total_krw']).'원' : '' }}">{{ isset($sum['unpaid_total_krw']) ? $this->abbrevKrw($sum['unpaid_total_krw']) : '—' }}</div></div>
                <div class="card-sm"><div class="text-xs text-gray-500">매입 미지급 합계</div><div class="mt-1 text-lg font-bold text-gray-800" title="{{ isset($sum['purchase_unpaid_total']) ? number_format((int) $sum['purchase_unpaid_total']).'원' : '' }}">{{ isset($sum['purchase_unpaid_total']) ? $this->abbrevKrw($sum['purchase_unpaid_total']) : '—' }}</div></div>
                <div class="card-sm"><div class="text-xs text-gray-500">정산 대기</div><div class="mt-1 text-lg font-bold text-gray-800">{{ $sum['settlement_pending_count'] ?? '—' }}건</div></div>
                <div class="card-sm"><div class="text-xs text-gray-500">환율 미입력</div><div class="mt-1 text-lg font-bold {{ ($sum['fx_missing_count'] ?? 0) ? 'text-amber-600' : 'text-gray-800' }}">{{ $sum['fx_missing_count'] ?? '—' }}건</div></div>
            </div>

            {{-- 월별 (판매 건수·정산 실지급·매입) — 날짜 있는 리스트서 집계. 판매액은 통화혼재라 건수만. --}}
            <div class="mt-4" x-data="{ open: true }">
                <button type="button" class="mb-2 flex items-center gap-2 font-bold text-gray-700" @click="open = !open">
                    <span class="w-3 text-gray-400" x-text="open ? '▼' : '▶'"></span> 📅 월별 실적
                </button>
                <div x-show="open" x-cloak class="overflow-x-auto">
                    <table class="tbl">
                        <thead><tr><th>월</th><th>판매(건)</th><th>정산 실지급(원)</th><th>매입(건)</th><th>매입가(원)</th></tr></thead>
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
                                <tr><td colspan="5" class="py-6 text-center text-gray-400">월별 실적이 없습니다.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                    <p class="mt-1 text-[11px] text-gray-400">💡 판매액은 통화가 섞여 합산 대신 건수로 표시. 정산·매입은 원화 합산.</p>
                </div>
            </div>

        @elseif ($tab === 'shipping')
            {{-- ③ 선적요청 + ①② 서류 --}}
            @php $vehicles = data_get($result['data'], 'data', []); @endphp
            <p class="mb-3 text-[13px] text-gray-500">판매완료된 본인 수출 차량을 <b>바이어별로 묶어</b> RORO/컨테이너 선적을 요청합니다. 요청하면 car-erp 관리(수출통관)에게 즉시 알람이 갑니다.</p>
            @php $byBuyer = collect($vehicles)->groupBy(fn ($v) => data_get($v, 'buyer.id') ?? 0); @endphp
            @forelse ($byBuyer as $buyerId => $group)
                @php
                    $buyerName = data_get($group->first(), 'buyer.name') ?? '(바이어 미지정)';
                    $consignees = data_get($group->first(), 'consignees', []);
                    $vIds = $group->pluck('vehicle_id')->map(fn ($i) => (int) $i)->all();
                    $method = $methodByBuyer[$buyerId] ?? 'RORO';
                @endphp
                <div class="card-sm mb-3" style="background:#f8f9fb" x-data="{ open: false }">
                    <button type="button" class="flex w-full items-center gap-2 text-left font-bold text-gray-800" @click="open = !open">
                        <span class="w-3 text-gray-400" x-text="open ? '▼' : '▶'"></span>
                        🧑 {{ $buyerName }} <span class="text-xs font-normal text-gray-400">· {{ count($vIds) }}대 선적가능</span>
                    </button>
                    <div x-show="open" x-cloak class="mt-2">
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
                            <option value="">컨사이니 선택</option>
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
                        <button wire:click="submitShipping({{ $buyerId }}, {{ json_encode($vIds) }})" class="btn-primary btn-sm">🚢 선적요청</button>
                    </div>
                    {{-- ①② 서류 (선택 차량, method별 2종) --}}
                    <div class="mt-2 flex flex-wrap items-center gap-2 border-t border-gray-200 pt-2 text-[12px]">
                        <span class="text-gray-400">선택 차량 서류({{ $method }}):</span>
                        <button wire:click="downloadDocs({{ $buyerId }}, {{ json_encode($vIds) }}, 'contract')" class="btn-ghost btn-sm">📄 계약서</button>
                        <button wire:click="downloadDocs({{ $buyerId }}, {{ json_encode($vIds) }}, 'invoice_packing')" class="btn-ghost btn-sm">📄 인보이스·패킹</button>
                    </div>
                    </div>
                </div>
            @empty
                <p class="py-8 text-center text-gray-400">선적 가능한 차량이 없습니다. (판매완료·수출·미요청 차량만 표시)</p>
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
                $byBuyer = $items->groupBy(fn ($r) => data_get($r, 'buyer') ?: '(바이어 미지정)');
                $cols = ['vehicle_number' => '차량', 'currency' => '통화', 'exchange_rate' => '환율', 'unpaid_krw' => '미수금(원)'];
            @endphp
            <label class="mb-2 flex items-center gap-2 text-[12px] text-gray-500">
                <input type="checkbox" wire:model.live="hidePaid"> 완납(0원) 숨기기
            </label>
            @forelse ($byBuyer as $buyer => $rows)
                <div class="card-sm mb-2" x-data="{ open: false }">
                    <button type="button" class="flex w-full items-center gap-2 text-left font-bold text-gray-800" @click="open = !open">
                        <span class="w-3 text-gray-400" x-text="open ? '▼' : '▶'"></span>
                        🧑 {{ $buyer }} <span class="text-xs font-normal text-gray-400">· {{ $rows->count() }}대</span>
                        @php $sum = $rows->sum(fn ($r) => (float) (data_get($r, 'unpaid_krw') ?? 0)); @endphp
                        <span class="ml-auto text-[13px] font-bold text-[var(--color-primary-text)]">{{ number_format($sum) }}원</span>
                    </button>
                    <div x-show="open" x-cloak class="mt-2 overflow-x-auto">
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
                                                    {{ $k === 'unpaid_krw' ? '환율 미입력' : '—' }}
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
                </div>
            @empty
                <p class="py-8 text-center text-gray-400">미수금 내역이 없습니다.{{ $hidePaid ? ' (완납 숨김 적용 중)' : '' }}</p>
            @endforelse

        @elseif ($tab === 'sales')
            {{-- 판매내역 — 바이어별 통화별 합(by-buyer) + 펼치면 그 바이어가 산 차량 리스트 --}}
            @php
                $buyers = data_get($result['data'], 'data', []);
                $detailByBuyer = collect($salesDetail)->groupBy(fn ($r) => data_get($r, 'buyer') ?: '(바이어 미지정)');
            @endphp
            @forelse ($buyers as $b)
                @php
                    $bName = data_get($b, 'buyer') ?: '(바이어 미지정)';
                    $rows = $detailByBuyer[$bName] ?? collect();
                    $byCur = (array) data_get($b, 'sales_by_currency', []);
                @endphp
                <div class="card-sm mb-2" x-data="{ open: false }">
                    <button type="button" class="flex w-full items-center gap-2 text-left font-bold text-gray-800" @click="open = !open">
                        <span class="w-3 text-gray-400" x-text="open ? '▼' : '▶'"></span>
                        🧑 {{ $bName }} <span class="text-xs font-normal text-gray-400">· {{ data_get($b, 'vehicle_count', 0) }}대</span>
                        <span class="ml-auto text-[13px]">@forelse ($byCur as $cur => $amt)<span class="ml-2 whitespace-nowrap"><b class="text-gray-500">{{ $cur }}</b> {{ number_format((float) $amt) }}</span>@empty<span class="text-gray-300">—</span>@endforelse</span>
                    </button>
                    <div x-show="open" x-cloak class="mt-2 overflow-x-auto">
                        <table class="tbl">
                            <thead><tr><th>차량</th><th>통화</th><th>판매가</th><th>판매일</th></tr></thead>
                            <tbody>
                                @forelse ($rows as $row)
                                    <tr>
                                        <td class="font-semibold text-gray-700">{{ data_get($row, 'vehicle_number') }}</td>
                                        <td>{{ data_get($row, 'currency') ?: '—' }}</td>
                                        <td>{{ ($p = data_get($row, 'sale_price')) !== null && is_numeric($p) ? number_format((float) $p) : '—' }}</td>
                                        <td>{{ data_get($row, 'sale_date') ?: '—' }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="py-3 text-center text-gray-400">차량 상세 없음</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            @empty
                <p class="py-8 text-center text-gray-400">판매내역이 없습니다.</p>
            @endforelse

        @elseif ($tab === 'settlements')
            {{-- 정산내역 — 바이어별 정산 실지급 (by-buyer, payout 내림차순) --}}
            @php $buyers = data_get($result['data'], 'data', []); @endphp
            <div class="overflow-x-auto">
                <table class="tbl">
                    <thead><tr><th>바이어</th><th>차량수</th><th>정산 실지급(원)</th><th>지급 완료(원)</th></tr></thead>
                    <tbody>
                        @forelse ($buyers as $b)
                            <tr>
                                <td class="font-semibold text-gray-700">{{ data_get($b, 'buyer') ?: '(바이어 미지정)' }}</td>
                                <td class="whitespace-nowrap text-gray-500">{{ data_get($b, 'vehicle_count', 0) }}대</td>
                                <td class="whitespace-nowrap font-semibold text-gray-800">{{ number_format((float) data_get($b, 'payout_total_krw', 0)) }}</td>
                                <td class="whitespace-nowrap text-gray-500">{{ number_format((float) data_get($b, 'payout_paid_krw', 0)) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-8 text-center text-gray-400">정산내역이 없습니다.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

        @else
            {{-- 매입내역 — buyer 무관(경매/판매처) → 평면 목록 --}}
            @php
                $items = data_get($result['data'], 'data', []);
                $cols = ['vehicle_number' => '차량', 'purchase_price' => '매입가', 'cost_total' => '비용합', 'purchase_unpaid' => '미지급', 'purchase_date' => '매입일'];
            @endphp
            <div class="overflow-x-auto">
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
                            <tr><td colspan="{{ count($cols) }}" class="py-8 text-center text-gray-400">매입내역이 없습니다.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <p class="mt-2 text-[11px] text-gray-400">💡 읽기전용(car-erp 원장). 금액·정산·선적 실무 수정은 car-erp 담당에게. 선적요청은 car-erp 관리에게 알람으로 전달됩니다.</p>
</div>

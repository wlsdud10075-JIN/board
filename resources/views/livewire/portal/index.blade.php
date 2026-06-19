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

    private const TABS = ['finance', 'receivables', 'purchases', 'sales', 'settlements', 'shipping'];

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
            'sales' => $svc->sales($email),
            'settlements' => $svc->settlements($email),
            'shipping' => $svc->shippable($email),
            default => $svc->finance($email),
        };
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
                <div class="card-sm"><div class="text-xs text-gray-500">미수금 합계</div><div class="mt-1 text-lg font-bold text-gray-800">{{ isset($sum['unpaid_total_krw']) ? number_format((int) $sum['unpaid_total_krw']).'원' : '—' }}</div></div>
                <div class="card-sm"><div class="text-xs text-gray-500">매입 미지급 합계</div><div class="mt-1 text-lg font-bold text-gray-800">{{ isset($sum['purchase_unpaid_total']) ? number_format((int) $sum['purchase_unpaid_total']).'원' : '—' }}</div></div>
                <div class="card-sm"><div class="text-xs text-gray-500">정산 대기</div><div class="mt-1 text-lg font-bold text-gray-800">{{ $sum['settlement_pending_count'] ?? '—' }}건</div></div>
                <div class="card-sm"><div class="text-xs text-gray-500">환율 미입력</div><div class="mt-1 text-lg font-bold {{ ($sum['fx_missing_count'] ?? 0) ? 'text-amber-600' : 'text-gray-800' }}">{{ $sum['fx_missing_count'] ?? '—' }}건</div></div>
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
            {{-- 판매내역 — 바이어별 접기 --}}
            @php
                $byBuyer = collect(data_get($result['data'], 'data', []))->groupBy(fn ($r) => data_get($r, 'buyer') ?: '(바이어 미지정)');
                $cols = ['vehicle_number' => '차량', 'currency' => '통화', 'sale_price' => '판매가', 'sale_date' => '판매일'];
            @endphp
            @forelse ($byBuyer as $buyer => $rows)
                <div class="card-sm mb-2" x-data="{ open: false }">
                    <button type="button" class="flex w-full items-center gap-2 text-left font-bold text-gray-800" @click="open = !open">
                        <span class="w-3 text-gray-400" x-text="open ? '▼' : '▶'"></span>
                        🧑 {{ $buyer }} <span class="text-xs font-normal text-gray-400">· {{ $rows->count() }}대</span>
                    </button>
                    <div x-show="open" x-cloak class="mt-2 overflow-x-auto">
                        <table class="tbl">
                            <thead><tr>@foreach ($cols as $label)<th>{{ $label }}</th>@endforeach</tr></thead>
                            <tbody>
                                @foreach ($rows as $row)
                                    <tr>
                                        @foreach ($cols as $k => $label)
                                            @php $val = data_get($row, $k); @endphp
                                            <td class="whitespace-nowrap text-gray-700">
                                                @if ($val === null)—@elseif ($k === 'sale_price' && is_numeric($val)){{ number_format((float) $val) }}@else{{ $val }}@endif
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @empty
                <p class="py-8 text-center text-gray-400">판매내역이 없습니다.</p>
            @endforelse

        @else
            {{-- 매입내역·정산내역 — car-erp 응답에 buyer 없음 → 평면 목록 --}}
            @php
                $items = data_get($result['data'], 'data', []);
                $cols = $tab === 'purchases'
                    ? ['vehicle_number' => '차량', 'purchase_price' => '매입가', 'cost_total' => '비용합', 'purchase_unpaid' => '미지급', 'purchase_date' => '매입일']
                    : ['vehicle_number' => '차량', 'status' => '상태', 'actual_payout' => '실지급액', 'confirmed_at' => '확정일'];
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
                                        @elseif (in_array($key, ['purchase_price', 'cost_total', 'purchase_unpaid', 'actual_payout'], true) && is_numeric($val))
                                            {{ number_format((float) $val) }}
                                        @else
                                            {{ $val }}
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @empty
                            <tr><td colspan="{{ count($cols) }}" class="py-8 text-center text-gray-400">해당 내역이 없습니다.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <p class="mt-2 text-[11px] text-gray-400">💡 읽기전용(car-erp 원장). 금액·정산·선적 실무 수정은 car-erp 담당에게. 선적요청은 car-erp 관리에게 알람으로 전달됩니다.</p>
</div>

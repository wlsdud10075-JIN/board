<?php

use App\Services\CarErpReadService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * 영업 포털 — car-erp 재무 읽기 미러(④). 권위 계약 = car-erp board-portal-api.md.
 * 전부 읽기전용. salesman_email = Auth 본인(car_erp_salesman_email ?: email) — 요청 파라미터 금지.
 * degrade: car-erp 미설정/401/5xx/403 → "조회 불가"(절대 0원/완납 coerce 금지). 개별 null = "환율 미입력".
 * ⚠️ 응답 필드 키는 car-erp 라이브 후 정합(point 7) — 미스매치 시 셀 "—", 레이아웃은 유지.
 */
new #[Layout('components.layouts.app')] class extends Component {
    public string $tab = 'finance';

    public array $result = ['ok' => false, 'reason' => 'init', 'data' => null, 'status' => 0];

    private const TABS = ['finance', 'receivables', 'purchases', 'sales', 'settlements'];

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
        $this->load();
    }

    public function reload(): void
    {
        $this->load();
    }

    /** car-erp 영업 매칭 이메일 — 오버라이드 우선, 없으면 로그인. (요청값 절대 사용 안 함) */
    private function salesmanEmail(): string
    {
        $u = Auth::user();

        return $u->car_erp_salesman_email ?: $u->email;
    }

    private function load(): void
    {
        $svc = app(CarErpReadService::class);
        $email = $this->salesmanEmail();
        $this->result = match ($this->tab) {
            'receivables' => $svc->receivables($email),
            'purchases' => $svc->purchases($email),
            'sales' => $svc->sales($email),
            'settlements' => $svc->settlements($email),
            default => $svc->finance($email),
        };
    }

    /** degrade 안내문 — reason/상태별. */
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
        <h1 class="text-xl font-bold text-gray-800">내 정산·미수 (포털)</h1>
        <p class="mt-0.5 text-xs text-gray-500">🔒 본인({{ auth()->user()->name }}) 정보만 — car-erp 원장 읽기(읽기전용). 수정은 car-erp에서.</p>
    </div>

    {{-- 탭 --}}
    <div class="mb-3 flex flex-wrap gap-1">
        @foreach (['finance' => '요약', 'receivables' => '미수금', 'purchases' => '매입내역', 'sales' => '판매내역', 'settlements' => '정산내역'] as $key => $label)
            <button wire:click="setTab('{{ $key }}')"
                class="rounded-md border px-3 py-1.5 text-[13px] font-semibold {{ $tab === $key ? 'border-[var(--color-primary)] bg-[var(--color-primary)] text-white' : 'border-gray-300 bg-white text-gray-600' }}">{{ $label }}</button>
        @endforeach
        <button wire:click="reload" class="ml-auto rounded-md border border-gray-300 bg-white px-3 py-1.5 text-[13px] text-blue-600" title="새로고침">↻ 갱신</button>
    </div>

    <div class="card">
        @if (! ($result['ok'] ?? false))
            {{-- degrade: 조회 불가 (절대 0/완납 표시 안 함) --}}
            <div class="card-sm border-amber-200 bg-amber-50 text-[13px] text-amber-800">
                ⚠️ <b>조회 불가</b> — {{ $this->degradeMessage() }}
            </div>
        @else
            @php $data = $result['data'] ?? []; @endphp

            @if ($tab === 'finance')
                @php $sum = is_array($data) ? $data : []; @endphp
                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="card-sm">
                        <div class="text-xs text-gray-500">미수금 합계</div>
                        <div class="mt-1 text-lg font-bold text-gray-800">{{ isset($sum['receivables_total_krw']) ? number_format((int) $sum['receivables_total_krw']).'원' : '—' }}</div>
                    </div>
                    <div class="card-sm">
                        <div class="text-xs text-gray-500">매입 미지급 합계</div>
                        <div class="mt-1 text-lg font-bold text-gray-800">{{ isset($sum['purchase_unpaid_total_krw']) ? number_format((int) $sum['purchase_unpaid_total_krw']).'원' : '—' }}</div>
                    </div>
                    <div class="card-sm">
                        <div class="text-xs text-gray-500">정산 대기</div>
                        <div class="mt-1 text-lg font-bold text-gray-800">{{ $sum['settlement_pending_count'] ?? '—' }}건</div>
                    </div>
                </div>
            @else
                @php
                    $items = data_get($data, 'items', is_array($data) ? $data : []);
                    $cols = match ($tab) {
                        'receivables' => ['vehicle_number' => '차량', 'buyer_name' => '바이어', 'currency' => '통화', 'exchange_rate' => '환율', 'unpaid_krw' => '미수금(원)'],
                        'purchases' => ['vehicle_number' => '차량', 'purchase_price' => '매입가', 'cost_total' => '비용합', 'purchase_date' => '매입일', 'purchase_unpaid' => '미지급'],
                        'sales' => ['vehicle_number' => '차량', 'sale_price' => '판매가', 'currency' => '통화', 'buyer_name' => '바이어'],
                        'settlements' => ['vehicle_number' => '차량', 'status' => '상태', 'actual_payout' => '실지급액', 'confirmed_at' => '확정일'],
                        default => [],
                    };
                @endphp
                <div class="overflow-x-auto">
                    <table class="tbl">
                        <thead><tr>@foreach ($cols as $label)<th>{{ $label }}</th>@endforeach</tr></thead>
                        <tbody>
                            @forelse ($items as $row)
                                <tr>
                                    @foreach ($cols as $key => $label)
                                        @php $v = data_get($row, $key); @endphp
                                        <td class="whitespace-nowrap {{ $v === null ? 'text-amber-600' : 'text-gray-700' }}">
                                            {{-- null 보존: 미수금 null = 환율 미입력(0/완납 아님) --}}
                                            @if ($v === null)
                                                {{ $tab === 'receivables' && $key === 'unpaid_krw' ? '환율 미입력' : '—' }}
                                            @else
                                                {{ $v }}
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
        @endif
    </div>

    <p class="mt-2 text-[11px] text-gray-400">💡 이 화면은 car-erp 원장을 읽어 보여줍니다(읽기전용). 금액·상태 수정은 car-erp 담당에게 문의하세요.</p>
</div>

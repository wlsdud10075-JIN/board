{{-- 그 달 정산 상세 — **승인된 ERP 월배치 미러**(car-erp board-portal-api.md §13). 데스크톱·모바일 공용.
     ⚠️ 배치 밖 지급이 기본 형태다(배치는 2026-07 도입 — ssancarerp 는 paid 전량이 배치 밖).
        배치 블록을 먼저, 그 아래 배치 밖 행. 배치 밖에 "예외" 딱지를 붙이지 말 것 — 한 박스에선 그게 전부다.
     ⚠️ 금액은 ERP 값 그대로. `net_payout` 을 settlement_total+adjustment_total 로 다시 계산하지 않는다. --}}
@php
    $batches = (array) ($det['batches'] ?? []);
    $loose = (array) ($det['rows'] ?? []);
@endphp
<div class="space-y-2 py-1 text-xs">
    @foreach ($batches as $b)
        <div class="rounded border border-gray-200 bg-white p-2">
            <div class="mb-1 flex items-center justify-between gap-2">
                <span class="font-semibold text-gray-700">📦 {{ __('portal.settle_batch_label', ['month' => data_get($b, 'month')]) }}</span>
                @if ($paidOn = data_get($b, 'decided_at'))
                    <span class="shrink-0 text-[11px] text-gray-400">{{ __('portal.settle_batch_paid_on', ['date' => $paidOn]) }}</span>
                @endif
            </div>
            {{-- 차량별 표(차량번호·마진율·건당금액 3열, 가운데 정렬) + 조정 행 — ERP 「차량별 보기」 미러(Jin 2026-10-14).
                 조정은 같은 표 안에 사유 colspan 으로 — 금액 열이 한 줄로 맞아야 눈으로 더할 수 있다. --}}
            <div class="overflow-x-auto">
                @include('livewire.portal._settle-vehicle-table', ['rows' => (array) data_get($b, 'settlements', []), 'extra' => (array) data_get($b, 'adjustments', [])])
            </div>
            <div class="mt-1 flex items-center justify-between border-t border-gray-300 pt-1 font-semibold text-gray-800">
                <span>{{ __('portal.settle_batch_net') }}</span>
                <span>{{ number_format((float) (data_get($b, 'net_payout') ?? 0)) }}</span>
            </div>
        </div>
    @endforeach

    @if ($loose !== [])
        {{-- 배치 밖 지급(기본 형태) — 같은 차량별 표 --}}
        <div class="overflow-x-auto">
            @include('livewire.portal._settle-vehicle-table', ['rows' => $loose, 'extra' => []])
        </div>
    @endif

    <div class="flex items-center justify-between border-t-2 border-gray-300 pt-1 font-bold text-gray-800">
        <span>{{ __('portal.settle_month_total') }}</span>
        <span>{{ number_format((float) ($det['total'] ?? 0)) }}</span>
    </div>
</div>

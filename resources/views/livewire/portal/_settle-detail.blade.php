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
            @foreach ((array) data_get($b, 'settlements', []) as $s)
                <div class="flex items-center justify-between gap-2 border-t border-gray-100 py-1">
                    <span class="text-gray-700">{{ data_get($s, 'vehicle_number') ?: '—' }}</span>
                    <b class="shrink-0 text-gray-800">{{ number_format((float) (data_get($s, 'actual_payout') ?? 0)) }}</b>
                </div>
            @endforeach
            @foreach ((array) data_get($b, 'adjustments', []) as $a)
                {{-- 조정 = 부호 있는 정수(환수 −/특별지급 +). 사유는 ERP 가 준다 — 없으면 「조정」으로만.
                     음수를 검정으로 찍으면 실무자가 못 알아챈다(색·부호 유지). --}}
                @php $amt = (float) (data_get($a, 'amount') ?? 0); @endphp
                <div class="flex items-start justify-between gap-2 border-t border-gray-100 py-1">
                    <span class="text-gray-600">{{ data_get($a, 'reason') ?: __('portal.settle_adjustment') }}</span>
                    <b class="shrink-0 {{ $amt < 0 ? 'text-rose-600' : 'text-emerald-700' }}">{{ $amt > 0 ? '+' : '' }}{{ number_format($amt) }}</b>
                </div>
            @endforeach
            <div class="mt-1 flex items-center justify-between border-t border-gray-300 pt-1 font-semibold text-gray-800">
                <span>{{ __('portal.settle_batch_net') }}</span>
                <span>{{ number_format((float) (data_get($b, 'net_payout') ?? 0)) }}</span>
            </div>
        </div>
    @endforeach

    @foreach ($loose as $s)
        <div class="flex items-center justify-between gap-2 border-b border-gray-100 py-1 last:border-0">
            <span class="text-gray-700">{{ data_get($s, 'vehicle_number') ?: '—' }}</span>
            <span class="flex shrink-0 items-center gap-2">
                <span class="text-[11px] text-gray-400">{{ data_get($s, 'paid_at') }}</span>
                <b class="text-gray-800">{{ number_format((float) (data_get($s, 'actual_payout') ?? 0)) }}</b>
            </span>
        </div>
    @endforeach

    <div class="flex items-center justify-between border-t-2 border-gray-300 pt-1 font-bold text-gray-800">
        <span>{{ __('portal.settle_month_total') }}</span>
        <span>{{ number_format((float) ($det['total'] ?? 0)) }}</span>
    </div>
</div>

{{-- 정산 차량별 표 — ERP 「차량별 보기」(payout person-card) 미러: 차량번호 · 마진율 · 건당금액, **전부 가운데 정렬**(Jin 2026-10-14).
     $rows = 정산 행 배열(vehicle_number · actual_payout · [margin_rate] · [confirmed_at|paid_at]).
     $dateKey = 날짜 열로 쓸 키(진행 중 = confirmed_at, 배치 밖 = paid_at). null 이면 날짜 열 없음.
     $extra = 표 안에 이어 그릴 조정 행 배열(사유 colspan + 금액) — 배치 블록에서만.

     ⚠️ **마진율 열은 ERP 가 `margin_rate` 키를 실어 줄 때만 그린다**(필드 없음 ≠ null — `_unpaid-chip` 과 같은 규율).
        현재 ERP `/settlements`·`/payout-batches` 는 마진 raw 를 **의도적으로 빼고** 준다(car-erp InternalPortalController
        「마진 raw 는 미포함」, CLAUDE.md 「마진 노출 = 대표 승인 사안」). 값이 실리는 순간 열이 자동으로 나타나고,
        그 전까지는 차량번호·건당금액 2열(+날짜). 셀 서식은 ERP `Settlement::formatMarginRate` 와 동일(소수 1자리 %, null = —).
     ⚠️ 「정렬」= 가운데 정렬이다. ERP 표의 머리글 클릭 정렬(JS)은 가져오지 않는다. --}}
@php
    $rows = array_values((array) $rows);
    $dateKey = $dateKey ?? null;
    $extra = (array) ($extra ?? []);
    $showMargin = collect($rows)->contains(fn ($r) => is_array($r) && array_key_exists('margin_rate', $r));
    $cols = 2 + ($showMargin ? 1 : 0) + ($dateKey ? 1 : 0);
@endphp
<table class="tbl tbl-center settle-tbl">
    <thead>
        <tr>
            <th>{{ __('portal.settle_col_vehicle') }}</th>
            @if ($showMargin)<th>{{ __('portal.settle_col_margin_rate') }}</th>@endif
            @if ($dateKey)<th>{{ __('portal.settle_col_date') }}</th>@endif
            <th>{{ __('portal.settle_col_payout') }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($rows as $s)
            @php
                $amt = (float) (data_get($s, 'actual_payout') ?? 0);
                $rate = $showMargin ? data_get($s, 'margin_rate') : null;
            @endphp
            <tr>
                <td class="whitespace-nowrap font-semibold text-gray-700">{{ data_get($s, 'vehicle_number') ?: '—' }}</td>
                @if ($showMargin)
                    <td class="whitespace-nowrap font-mono {{ is_numeric($rate) && (float) $rate < 0 ? 'text-rose-600' : 'text-gray-700' }}">{{ is_numeric($rate) ? number_format((float) $rate * 100, 1).'%' : '—' }}</td>
                @endif
                @if ($dateKey)<td class="whitespace-nowrap text-gray-400">{{ data_get($s, $dateKey) ?: '—' }}</td>@endif
                <td class="whitespace-nowrap font-mono font-semibold {{ $amt < 0 ? 'text-rose-600' : 'text-gray-800' }}">{{ number_format($amt) }}</td>
            </tr>
        @endforeach
        @foreach ($extra as $a)
            {{-- 조정 = 부호 있는 정수(환수 −/특별지급 +). 사유는 ERP 가 준다 — 없으면 「조정」으로만. 음수는 색·부호 유지. --}}
            @php $amt = (float) (data_get($a, 'amount') ?? 0); @endphp
            <tr>
                <td colspan="{{ $cols - 1 }}" class="text-gray-600">{{ data_get($a, 'reason') ?: __('portal.settle_adjustment') }}</td>
                <td class="whitespace-nowrap font-mono font-semibold {{ $amt < 0 ? 'text-rose-600' : 'text-emerald-700' }}">{{ $amt > 0 ? '+' : '' }}{{ number_format($amt) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

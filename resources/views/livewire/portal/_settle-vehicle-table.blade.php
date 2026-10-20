{{-- 정산 차량별 표 — ERP 「차량별 보기」(payout person-card) 미러. **열 = 차량번호 · 마진율 · 건당금액 3개, 전부 가운데 정렬**(Jin 2026-10-14).
     🚫 열을 더 붙이지 말 것(일자 등) — Jin 이 3개를 지정했고, 임의로 붙였다가 되돌렸다(2026-10-14).
     $rows = 정산 행 배열(vehicle_number · actual_payout · margin_rate).
     $extra = 표 안에 이어 그릴 조정 행 배열(사유 colspan=2 + 금액) — 배치 블록에서만.

     마진율 = ERP `margin_rate`(Σ총마진 ÷ Σ판매금원화, Settlement accessor). 서식은 ERP `Settlement::formatMarginRate` 와 동일
     (소수 1자리 %, null = —). **ERP 가 아직 안 실어 주면 — 로 뜬다**(car-erp 세션에 /settlements·/payout-batches 세 행 맵 추가 요청
     2026-10-14) — 값이 오는 순간 board 재배포 없이 채워진다. --}}
@php
    $rows = array_values((array) $rows);
    $extra = (array) ($extra ?? []);
@endphp
<table class="tbl tbl-center settle-tbl">
    <thead>
        <tr>
            <th>{{ __('portal.settle_col_vehicle') }}</th>
            <th>{{ __('portal.settle_col_margin_rate') }}</th>
            <th>{{ __('portal.settle_col_payout') }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($rows as $s)
            @php
                $amt = (float) (data_get($s, 'actual_payout') ?? 0);
                $rate = data_get($s, 'margin_rate');
            @endphp
            <tr>
                <td class="whitespace-nowrap font-semibold text-gray-700">{{ \App\Support\Plate::show(data_get($s, 'vehicle_number')) ?: '—' }}</td>
                <td class="whitespace-nowrap font-mono {{ is_numeric($rate) && (float) $rate < 0 ? 'text-rose-600' : 'text-gray-700' }}">{{ is_numeric($rate) ? number_format((float) $rate * 100, 1).'%' : '—' }}</td>
                <td class="whitespace-nowrap font-mono font-semibold {{ $amt < 0 ? 'text-rose-600' : 'text-gray-800' }}">{{ number_format($amt) }}</td>
            </tr>
        @endforeach
        @foreach ($extra as $a)
            {{-- 조정 = 부호 있는 정수(환수 −/특별지급 +). 사유는 ERP 가 준다 — 없으면 「조정」으로만. 음수는 색·부호 유지. --}}
            @php $amt = (float) (data_get($a, 'amount') ?? 0); @endphp
            <tr>
                <td colspan="2" class="text-gray-600">{{ data_get($a, 'reason') ?: __('portal.settle_adjustment') }}</td>
                <td class="whitespace-nowrap font-mono font-semibold {{ $amt < 0 ? 'text-rose-600' : 'text-emerald-700' }}">{{ $amt > 0 ? '+' : '' }}{{ number_format($amt) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

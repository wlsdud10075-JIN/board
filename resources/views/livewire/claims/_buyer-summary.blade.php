{{--
    클레임 「바이어 추가 정보」 상자(2026-11-27 Jin) — 담당자가 ERP 에 들어가지 않고도 그 바이어를 판단할 수 있게.
    **바이어 전체 기준**(그 바이어에게 판 모든 영업의 차) = ERP `GET /buyers/{id}/summary` 를 받은 그대로(`claims.buyer_summary`). board 는 계산하지 않는다.
    아직 못 받았으면(ERP 미배포·실패) 있는 값(영업 기준 구매 대수·신용도)만 보이고 「ERP 연동 대기」 안내. 기존 보고서 칸·문서는 그대로다.
    필요 변수: $d(Claim). 🚫 빨간 글씨 금지(드로어 오류 스크롤 훅) — 미수 강조는 호박색.
--}}
@php
    $bs = is_array($d->buyer_summary) ? $d->buyer_summary : null;
    $num = fn ($v, int $dec = 0) => is_numeric($v) ? number_format((float) $v, $dec) : '—';
    $pct = fn ($v) => is_numeric($v) ? rtrim(rtrim(number_format((float) $v * 100, 1), '0'), '.').'%' : '—';
    $veh = $bs['vehicle'] ?? null;
    $rows = is_array($bs['by_currency'] ?? null) ? $bs['by_currency'] : [];
@endphp
<div class="mt-3 rounded-lg border px-3 py-2 text-xs text-gray-700" style="background:#f5f8ff;border-color:#dbeafe" data-claim-buyer-summary>
    <div class="flex flex-wrap items-baseline gap-x-2">
        <span class="font-semibold text-gray-800">{{ __('claims.bs.title') }}</span>
        <span class="text-gray-500">🧑 {{ $d->buyer_name ?: '—' }}</span>
        @if ($bs && $d->buyer_summary_at)
            <span class="text-[10px] text-gray-400">{{ __('claims.bs.as_of', ['time' => $d->buyer_summary_at->format('m-d H:i')]) }}</span>
        @endif
    </div>

    @if ($bs)
        {{-- 숫자 4칸 — 폰에서는 2칸씩 --}}
        <div class="mt-1.5 grid grid-cols-2 gap-x-3 gap-y-1.5 sm:grid-cols-4">
            <div><div class="text-[10px] text-gray-400">{{ __('claims.bs.total') }}</div>
                <div class="font-bold text-gray-800">{{ __('claims.bs.cars', ['count' => $num($bs['vehicle_count'] ?? null)]) }}</div></div>
            <div><div class="text-[10px] text-gray-400">{{ __('claims.bs.recent') }}</div>
                <div class="font-bold text-gray-800">{{ __('claims.bs.cars', ['count' => $num($bs['vehicle_count_12m'] ?? null)]) }}</div></div>
            <div><div class="text-[10px] text-gray-400">{{ __('claims.bs.paid_ratio') }}</div>
                <div class="font-bold text-gray-800">{{ $pct($bs['paid_ratio'] ?? null) }}</div></div>
            <div><div class="text-[10px] text-gray-400">{{ __('claims.bs.unpaid_cars') }}</div>
                <div class="font-bold {{ ($bs['unpaid_vehicle_count'] ?? 0) > 0 ? 'text-amber-700' : 'text-gray-800' }}">{{ __('claims.bs.cars', ['count' => $num($bs['unpaid_vehicle_count'] ?? null)]) }}</div></div>
        </div>

        {{-- 통화별 — 판매 통화 그대로(환산 없음) --}}
        @if ($rows)
            <div class="mt-2 overflow-x-auto">
                <table class="w-full text-right tabular-nums" style="min-width:17rem">
                    <thead class="text-[10px] text-gray-400"><tr>
                        <th class="py-0.5 text-left font-normal">{{ __('claims.bs.col_currency') }}</th>
                        <th class="py-0.5 font-normal">{{ __('claims.bs.col_cars') }}</th>
                        <th class="py-0.5 font-normal">{{ __('claims.bs.col_sales') }}</th>
                        <th class="py-0.5 font-normal">{{ __('claims.bs.col_unpaid') }}</th>
                        <th class="py-0.5 font-normal">{{ __('claims.bs.col_ratio') }}</th>
                    </tr></thead>
                    <tbody>
                        @foreach ($rows as $r)
                            <tr class="border-t" style="border-color:#dbeafe">
                                <td class="py-0.5 text-left font-semibold">{{ $r['currency'] ?? '—' }}</td>
                                <td class="whitespace-nowrap py-0.5">{{ $num($r['vehicle_count'] ?? null) }}</td>
                                <td class="whitespace-nowrap py-0.5">{{ $num($r['sale_total'] ?? null) }}</td>
                                <td class="whitespace-nowrap py-0.5 {{ ((float) ($r['unpaid'] ?? 0)) > 0 ? 'font-semibold text-amber-700' : '' }}">{{ $num($r['unpaid'] ?? null) }}</td>
                                <td class="whitespace-nowrap py-0.5">{{ $pct($r['paid_ratio'] ?? null) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <div class="mt-1.5 flex flex-wrap gap-x-4 gap-y-0.5 text-[11px] text-gray-600">
            @if (! empty($bs['first_sale_date']))<span>{{ __('claims.bs.first_sale', ['date' => $bs['first_sale_date']]) }}</span>@endif
            <span>{{ __('claims.credit_label') }} <b>{{ $d->creditDisplay() }}</b></span>
            @if (($bs['fx_missing_count'] ?? 0) > 0)<span class="text-amber-700">{{ __('claims.bs.fx_missing', ['count' => $num($bs['fx_missing_count'])]) }}</span>@endif
        </div>

        {{-- 이 차 --}}
        @if (is_array($veh))
            <div class="mt-1.5 border-t pt-1.5 text-[11px]" style="border-color:#dbeafe" data-claim-buyer-summary-vehicle>
                <span class="font-semibold text-gray-700">{{ __('claims.bs.this_car', ['plate' => \App\Support\Plate::show($d->vehicle_number)]) }}</span>
                @if ($veh['fully_paid'] ?? false)
                    <span class="ml-1 font-semibold text-green-700">{{ __('claims.bs.car_paid') }}</span>
                @elseif (is_numeric($veh['unpaid_ratio'] ?? null))
                    <span class="ml-1 font-semibold text-amber-700">{{ __('claims.bs.car_unpaid', ['amount' => ($veh['currency'] ?? '').' '.$num($veh['unpaid'] ?? null), 'ratio' => $pct(1 - (float) $veh['unpaid_ratio'])]) }}</span>
                @else
                    <span class="ml-1 text-amber-700">{{ __('claims.bs.car_unknown') }}</span>
                @endif
                <span class="ml-1 text-gray-500">{{ __('claims.bs.car_total', ['amount' => ($veh['currency'] ?? '').' '.$num($veh['sale_total'] ?? null)]) }}</span>
            </div>
        @endif
        <p class="mt-1 text-[10px] text-gray-400">{{ __('claims.bs.scope_note') }}</p>
    @else
        {{-- 아직 못 받음 — 있는 값만(영업 기준) --}}
        <div class="mt-1 flex flex-wrap gap-x-4 gap-y-0.5 text-[11px] text-gray-600">
            <span>{{ $d->total_purchase_count === null ? '—' : __('claims.total_cars', ['count' => $d->total_purchase_count]) }}</span>
            <span>{{ __('claims.credit_label') }} <b>{{ $d->creditDisplay() }}</b></span>
        </div>
        <p class="mt-1 text-[11px] text-amber-700" data-claim-buyer-summary-pending>{{ __('claims.bs.pending') }}</p>
    @endif
</div>

{{--
    미완납 칩 — 선적 계획에서 "이 차 돈 다 들어왔나"를 한 눈에. $row = /shippable 행.

    2026-08-12 부터 선적 후보에 **미완납 차도 온다**. 표시가 없으면 영업이 모르고 묶는다.

    ⚠️ **`unpaid_krw` 가 null 이면 "완납"이 아니다** — 환율 미입력이라 **판정 자체가 불가**하다는 뜻이다.
       0 으로 바꿔 그리면 가짜 완납이 된다(ERP 도 그 경우 fully_paid=false 로 준다).
    ⚠️ 완납 차에는 아무것도 안 붙인다 — 정상이 조용해야 이상한 게 눈에 띈다.
    ⚠️ **필드가 없는 행 ≠ null** (2026-10-14 heymanboard 무사백 실사고) — 이미 묶인 차는 `/bundles` 차량 행으로만 오고
       그 행엔 미수 필드가 아예 없다(ERP 가 `/shippable` 에서 묶인 차를 뺀다). 그걸 null 로 읽어 **요청됨 묶음의 차 35대
       전부에 「환율 미입력」이 붙었다**(환율은 전부 있었다). 필드가 없으면 판정 자체를 안 한다 — 칩 없음.
       ERP 가 `/bundles` 차량 행에도 같은 필드를 실어 주면(car-erp 세션 요청 2026-10-14) 그때부터 자동으로 칩이 붙는다.
--}}
@php
    $hasUnpaid = is_array($row) ? array_key_exists('unpaid_krw', $row) : (is_object($row) && property_exists($row, 'unpaid_krw'));   // isset 은 null 을 「없음」으로 뭉갠다
    $up = data_get($row, 'unpaid_krw');
    $fxUnknown = $hasUnpaid && $up === null;
    $paid = (bool) data_get($row, 'fully_paid');
    $ratio = data_get($row, 'unpaid_ratio');
@endphp
@if (! $hasUnpaid)
    {{-- 미수 필드 미동봉(묶인 차) — 모르는 것을 「환율 미입력」으로 그리지 않는다 --}}
@elseif ($fxUnknown)
    <span class="badge badge-gray whitespace-nowrap" title="{{ __('portal.plan_fx_missing_hint') }}">{{ __('portal.plan_fx_missing') }}</span>
@elseif (! $paid)
    <span class="badge badge-amber whitespace-nowrap">{{ __('portal.plan_unpaid') }}@if (is_numeric($ratio)) {{ number_format(max(0, min(100, (float) $ratio * 100)), 0) }}%@endif</span>
@endif

{{--
    미완납 칩 — 선적 계획에서 "이 차 돈 다 들어왔나"를 한 눈에. $row = /shippable 행.

    2026-08-12 부터 선적 후보에 **미완납 차도 온다**. 표시가 없으면 영업이 모르고 묶는다.

    ⚠️ **`unpaid_krw` 가 null 이면 "완납"이 아니다** — 환율 미입력이라 **판정 자체가 불가**하다는 뜻이다.
       0 으로 바꿔 그리면 가짜 완납이 된다(ERP 도 그 경우 fully_paid=false 로 준다).
    ⚠️ 완납 차에는 아무것도 안 붙인다 — 정상이 조용해야 이상한 게 눈에 띈다.
--}}
@php
    $up = data_get($row, 'unpaid_krw');
    $fxUnknown = $up === null;
    $paid = (bool) data_get($row, 'fully_paid');
    $ratio = data_get($row, 'unpaid_ratio');
@endphp
@if ($fxUnknown)
    <span class="badge badge-gray whitespace-nowrap" title="{{ __('portal.plan_fx_missing_hint') }}">{{ __('portal.plan_fx_missing') }}</span>
@elseif (! $paid)
    <span class="badge badge-amber whitespace-nowrap">{{ __('portal.plan_unpaid') }}@if (is_numeric($ratio)) {{ number_format(max(0, min(100, (float) $ratio * 100)), 0) }}%@endif</span>
@endif

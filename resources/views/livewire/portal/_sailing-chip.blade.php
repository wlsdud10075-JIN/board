{{--
    §12 운항 칩 — 진행상태와 **직교하는 별개 축**이다(선적중·통관중·거래완료를 가로지른다).
    진행상태 자리에 합치거나 승격시키지 말 것.

    분기는 `sailing`(영문 키), 출력은 `sailing_status`(ERP 라벨) **그대로**.
    ⚠️ 「도착예정」은 ETA 가 지났다는 뜻이지 실제 입항 확인이 아니다 — board 가 "도착"으로 줄여 쓰면
       영업이 바이어에게 잘못 전하고 지연 시 그대로 클레임이 된다. 그래서 라벨을 board 에서 만들지 않는다.
    필드가 없으면(= car-erp §12 배포 전) 조용히 아무것도 안 그린다.

    $row           — ERP 행
    $sailingDetail — 선박명·ETA 를 아래 줄로 펼칠지(호버 title 은 항상)
--}}
@php
    $sailKey = data_get($row, 'sailing');
    $sailLabel = data_get($row, 'sailing_status');
    $sailVessel = data_get($row, 'vessel_name');
    $sailEta = data_get($row, 'eta_date');
    $sailInfo = trim(($sailVessel ? '🛳 '.$sailVessel : '').($sailVessel && $sailEta ? ' · ' : '').($sailEta ? 'ETA '.$sailEta : ''));
@endphp
@if ($sailKey && $sailLabel)
    <span class="badge whitespace-nowrap {{ $sailKey === 'in_transit' ? 'badge-blue' : 'badge-green' }}"
          @if ($sailInfo) title="{{ $sailInfo }}" @endif>{{ $sailKey === 'in_transit' ? '🚢' : '⚓' }} {{ $sailLabel }}</span>
    @if ($sailInfo && ($sailingDetail ?? false))
        <div class="text-[11px] whitespace-nowrap text-gray-400">{{ $sailInfo }}</div>
    @endif
@endif

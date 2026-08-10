{{--
    차량 보조정보 — 차대번호 · 브랜드/차종 (2026-08-10 Jin: "차량번호 보이는 곳이면 같이 보이게").

    ⚠️ 값은 **car-erp 읽기 API 가 줄 때만** 있다. 안 오면(= 그쪽 배포 전, 또는 §3 PII 판단으로 VIN 제외)
       **아무것도 안 그린다** — 대시(`—`)도 찍지 않는다. 빈 줄이 늘어서면 "정보가 없는 차"로 오해된다.
       각 필드는 **독립적으로** degrade 한다(브랜드만 오고 VIN 은 안 와도 브랜드는 보인다).
    권위 = car-erp `docs/integration/board-portal-api.md` + `meetings/handoff-carerp-portal-vehicle-meta.md`.

    $row      — ERP 행 (vin · brand · model_type)
    $metaWrap — 감싸는 태그: 'div'(기본, 줄바꿈) | 'span'(pill 안 등 인라인)
--}}
@php
    $vmVin = data_get($row, 'vin');
    $vmModel = trim((string) data_get($row, 'brand').' '.(string) data_get($row, 'model_type'));
    $vmParts = array_values(array_filter([$vmModel !== '' ? $vmModel : null, $vmVin]));
@endphp
@if ($vmParts !== [])
    @if (($metaWrap ?? 'div') === 'span')
        <span class="text-[10px] font-normal text-gray-400">{{ implode(' · ', $vmParts) }}</span>
    @else
        <div class="text-[11px] font-normal text-gray-400">{{ implode(' · ', $vmParts) }}</div>
    @endif
@endif

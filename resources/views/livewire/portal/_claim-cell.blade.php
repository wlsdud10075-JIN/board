{{--
    판매내역 행 — 클레임 칩/접수 버튼(2026-11-10). 열린 클레임이 있으면 상태 칩, 없으면 [클레임 접수](쓰기 가능할 때만 — 업무관리자 조회 전용은 숨김).
    $row(ERP 행) · $bid(by-buyer buyer_id) · $vid(vehicle_id). ERP 행 스냅샷을 그대로 드로어로 넘긴다(다시 적는 칸 0).
--}}
@php $claimStatus = $vid ? ($this->claimChips[$vid] ?? null) : null; @endphp
@if ($claimStatus)
    <span class="badge badge-amber whitespace-nowrap" title="{{ __('claims.chip_title') }}">📋 {{ $claimStatus }}</span>
@elseif ($vid && ! $this->proxyWriteBlocked())
    <button type="button" class="btn-ghost btn-sm whitespace-nowrap text-[11px]"
            wire:click="openClaimIntake({{ \Illuminate\Support\Js::from([
                'vehicle_id' => $vid, 'vehicle_number' => data_get($row, 'vehicle_number'), 'vin' => data_get($row, 'vin'),
                'brand' => data_get($row, 'brand'), 'model_type' => data_get($row, 'model_type'),
                'buyer' => data_get($row, 'buyer') ?: $bName, 'buyer_id' => $bid ?: null,
                'sale_price' => data_get($row, 'sale_price'), 'currency' => data_get($row, 'currency'),
                'sale_date' => data_get($row, 'sale_date'), 'eta_date' => data_get($row, 'eta_date'),
            ]) }})">📋 {{ __('claims.intake_btn') }}</button>
@endif

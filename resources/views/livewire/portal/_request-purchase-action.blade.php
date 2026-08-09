{{--
    매입 [입금요청] — 차량 1대. $row = 매입내역 한 줄.
    ERP 가 vehicle_id 를 안 주는 행은 **버튼을 없애지 않고 비활성 + 사유**로 둔다 —
    버튼이 사라지면 "이미 보냈다/해당 없다"로 읽힌다(§11-4 항목 5).
--}}
@php
    $vid = data_get($row, 'vehicle_id');
    $vno = (string) (data_get($row, 'vehicle_number') ?: '');
    $chip = $vno !== '' ? $this->requestChip($vno, 'purchase_payment') : null;
@endphp
<div class="flex items-center justify-end gap-1.5">
    @include('livewire.portal._request-chip')
    @if ($vid)
        <button type="button" wire:click="sendPurchasePayment({{ (int) $vid }})"
            wire:loading.attr="disabled" wire:target="sendPurchasePayment"
            class="btn-outline btn-sm shrink-0">{{ __('portal.req_purchase_btn') }}</button>
    @else
        <button type="button" disabled title="{{ __('portal.req_blocked_no_vehicle_id') }}"
            class="btn-outline btn-sm shrink-0 cursor-not-allowed opacity-40">{{ __('portal.req_blocked_short') }}</button>
    @endif
</div>

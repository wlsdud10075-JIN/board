{{--
    매입 요청 — [계약금] / [잔금] 2종 + 금액. $row = 재고(지급대기) 한 줄.

    ERP 가 vehicle_id 를 안 주는 행은 **버튼을 없애지 않고 비활성 + 사유**로 둔다 —
    버튼이 사라지면 "이미 보냈다/해당 없다"로 읽힌다(§11-4 항목 5).

    ⚠️ 칩은 type 별로 각각 그린다. 한 차에 「계약금 확인됨」 + 「잔금 요청중」이 **동시에 성립**한다.
       구 `purchase_payment` 칩도 계속 그린다(과거 요청 이력이 사라지면 영업이 다시 누른다).
    ⚠️ 금액은 vehicle_id 로 키잉 — 한 칸을 공유하면 A 행 금액이 B 행에 뜬다.
--}}
@php
    $vid = data_get($row, 'vehicle_id');
    $vno = (string) (data_get($row, 'vehicle_number') ?: '');
    $reqBtns = [
        \App\Services\CarErpReadService::REQ_PURCHASE_DEPOSIT => __('portal.req_deposit_btn'),
        \App\Services\CarErpReadService::REQ_PURCHASE_BALANCE => __('portal.req_balance_btn'),
    ];
@endphp
<div class="flex flex-col items-end gap-1">
    <div class="flex flex-wrap items-center justify-end gap-1">
        @foreach ($reqBtns as $reqType => $reqLabel)
            @include('livewire.portal._request-chip', [
                'chip' => $vno !== '' ? $this->requestChip($vno, $reqType) : null,
                'chipLabel' => $reqLabel,
            ])
        @endforeach
        @include('livewire.portal._request-chip', [
            'chip' => $vno !== '' ? $this->requestChip($vno, \App\Services\CarErpReadService::REQ_PURCHASE_LEGACY) : null,
            'chipLabel' => __('portal.req_purchase_btn'),
        ])
    </div>

    @if ($vid)
        <div class="flex items-center justify-end gap-1">
            <input type="text" inputmode="numeric" autocomplete="off"
                wire:model="reqAmount.{{ (int) $vid }}"
                wire:key="reqamt-{{ (int) $vid }}"
                placeholder="{{ __('portal.req_amount_ph') }}"
                class="input-base w-28 shrink-0 text-right text-[12px]">
            @foreach ($reqBtns as $reqType => $reqLabel)
                <button type="button" wire:click="sendPurchaseRequest('{{ $reqType }}', {{ (int) $vid }})"
                    wire:loading.attr="disabled" wire:target="sendPurchaseRequest"
                    class="btn-outline btn-sm shrink-0">{{ $reqLabel }}</button>
            @endforeach
        </div>
    @else
        <button type="button" disabled title="{{ __('portal.req_blocked_no_vehicle_id') }}"
            class="btn-outline btn-sm shrink-0 cursor-not-allowed opacity-40">{{ __('portal.req_blocked_short') }}</button>
    @endif
</div>

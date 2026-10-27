{{--
    매입 요청 — [계약금] / [잔금] / [대표계약금] 3종 + 금액. $row = 재고(지급대기) 한 줄.

    ERP 가 vehicle_id 를 안 주는 행은 **버튼을 없애지 않고 비활성 + 사유**로 둔다 —
    버튼이 사라지면 "이미 보냈다/해당 없다"로 읽힌다(§11-4 항목 5).

    ⚠️ 칩은 type 별로 각각 그린다. 한 차에 「계약금 확인됨」 + 「잔금 요청중」이 **동시에 성립**한다.
       [대표계약금]도 마찬가지 — 일반 계약금과 **별개 신호**라 둘 다 open 일 수 있다(막지 않는다).
       구 `purchase_payment` 칩도 계속 그린다(과거 요청 이력이 사라지면 영업이 다시 누른다).
    ⚠️ 금액은 vehicle_id 로 키잉 — 한 칸을 공유하면 A 행 금액이 B 행에 뜬다.

    🔁 재전송(2026-10-27 Jin) — 그 type 칩이 `open` 이면 버튼이 「계약금 재전송」 으로 바뀐다(종류 이름은 남긴다 — 어느 버튼이었는지
       알아야 한다). 회차는 버튼에 안 넣고 **칩**(「재전송 N/L」)과 **confirm 창**(차량·종류·금액·회차)에만 — 버튼 3개가 폰에서
       더 접히지 않게. 상한(ERP `resend_limit`)에 닿으면 **비활성 + 사유**(없애지 않는다). 플래그는 이 버튼에서만 true 로 나간다.
       금액칸이 비면 열려 있는 요청의 금액 그대로(「같은 내용을 한 번 더」) — 잔금의 미지급 전액 프리필은 재전송엔 안 쓴다.
--}}
@php
    $vid = data_get($row, 'vehicle_id');
    $vno = (string) (data_get($row, 'vehicle_number') ?: '');
    $reqBtns = [
        \App\Services\CarErpReadService::REQ_PURCHASE_DEPOSIT => __('portal.req_deposit_btn'),
        \App\Services\CarErpReadService::REQ_PURCHASE_BALANCE => __('portal.req_balance_btn'),
        \App\Services\CarErpReadService::REQ_PURCHASE_DEPOSIT_CEO => __('portal.req_deposit_ceo_btn'),
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
        @php
            // [매입잔금] 빈 금액칸 = ERP 미지급 전액(매도비 포함) — 누르기 전에 그 숫자를 확인창으로 보여 준다(2026-10-12 Jin).
            //   계약금 요청이 아직 open 이면 미지급에 반영 전일 수 있다고 덧붙인다(요청액 ≠ 지급액, 이중 차감 방지는 사람 판단).
            $unpaidRaw = data_get($row, 'purchase_unpaid');
            $unpaidInt = is_numeric($unpaidRaw) && (float) $unpaidRaw > 0 ? (int) round((float) $unpaidRaw) : null;
            $openDeposit = collect([\App\Services\CarErpReadService::REQ_PURCHASE_DEPOSIT, \App\Services\CarErpReadService::REQ_PURCHASE_DEPOSIT_CEO])
                ->map(fn ($t) => $vno !== '' ? $this->requestChip($vno, $t) : null)
                ->first(fn ($c) => $c && ($c['status'] ?? '') === 'open' && is_numeric($c['amount'] ?? null));
            $fillConfirm = $unpaidInt !== null
                ? __('portal.req_balance_fill_confirm', ['amount' => number_format($unpaidInt)])
                    .($openDeposit ? __('portal.req_balance_fill_deposit_hint', ['amount' => number_format((float) $openDeposit['amount'])]) : '')
                : null;
        @endphp
        @php
            // type 별 열려 있는 칩 — 있으면 그 버튼은 재전송 모드. 세 버튼이 각각 독립이라 「계약금 재전송」 옆에 「매입잔금」 이 서는 게 정상.
            $openOf = [];
            foreach ($reqBtns as $t => $l) {
                $c = $vno !== '' ? $this->requestChip($vno, $t) : null;
                if ($c && ($c['status'] ?? '') === 'open') {
                    $openOf[$t] = $c;
                }
            }
        @endphp
        {{-- 좁은 화면(≈360px)에서 input+버튼 2개가 한 줄에 안 들어간다 — 넘치는 대신 접히게 둔다. --}}
        <div class="flex flex-wrap items-center justify-end gap-1" x-data>
            <input type="text" inputmode="numeric" autocomplete="off"
                wire:model="reqAmount.{{ (int) $vid }}"
                wire:key="reqamt-{{ (int) $vid }}"
                x-ref="amt"
                placeholder="{{ __('portal.req_amount_ph') }}"
                class="input-base w-24 shrink-0 text-right text-[12px]">
            @foreach ($reqBtns as $reqType => $reqLabel)
                @if (isset($openOf[$reqType]))
                    @php
                        $rc = $openOf[$reqType];
                        $rLimit = $rc['resend_limit'] ?? null;
                        $rCount = (int) ($rc['resent_count'] ?? 0);
                        $atLimit = $rLimit !== null && $rCount >= $rLimit;
                        $storedAmt = is_numeric($rc['amount'] ?? null) ? number_format((float) $rc['amount']) : '—';
                        $round = $rLimit !== null
                            ? __('portal.req_resend_round', ['n' => $rCount + 1, 'limit' => $rLimit])
                            : __('portal.req_resend_round_nolimit', ['n' => $rCount + 1]);
                        // 금액은 JS 가 채운다 — 칸에 적은 게 있으면 그 값(금액 수정 + 재전송), 비면 열려 있는 요청의 금액.
                        $resendTpl = __('portal.req_resend_confirm', ['plate' => \App\Support\Plate::show($vno), 'type' => $reqLabel, 'amount' => '__AMT__', 'round' => $round]);
                    @endphp
                    <button type="button"
                        @if ($atLimit) disabled title="{{ __('portal.req_resend_limit_reached', ['limit' => $rLimit]) }}" @endif
                        @click="if (confirm(@js($resendTpl).replace('__AMT__', $refs.amt.value.trim() !== '' ? $refs.amt.value.trim() : @js($storedAmt)))) $wire.sendPurchaseRequest('{{ $reqType }}', {{ (int) $vid }}, true)"
                        wire:loading.attr="disabled" wire:target="sendPurchaseRequest"
                        class="btn-outline btn-sm shrink-0{{ $atLimit ? ' cursor-not-allowed opacity-40' : '' }}">{{ __('portal.req_resend_btn', ['type' => $reqLabel]) }}</button>
                @elseif ($reqType === \App\Services\CarErpReadService::REQ_PURCHASE_BALANCE && $fillConfirm !== null)
                    {{-- 빈 칸이면 확인창(금액 표시) 뒤 전송, 적었으면 바로 전송. 서버(sendPurchaseRequest)가 같은 규칙으로 다시 판정한다. --}}
                    <button type="button"
                        @click="if ($refs.amt.value.trim() !== '' || confirm(@js($fillConfirm))) $wire.sendPurchaseRequest('{{ $reqType }}', {{ (int) $vid }})"
                        wire:loading.attr="disabled" wire:target="sendPurchaseRequest"
                        class="btn-outline btn-sm shrink-0">{{ $reqLabel }}</button>
                @else
                    <button type="button" wire:click="sendPurchaseRequest('{{ $reqType }}', {{ (int) $vid }})"
                        wire:loading.attr="disabled" wire:target="sendPurchaseRequest"
                        class="btn-outline btn-sm shrink-0">{{ $reqLabel }}</button>
                @endif
            @endforeach
        </div>
    @else
        <button type="button" disabled title="{{ __('portal.req_blocked_no_vehicle_id') }}"
            class="btn-outline btn-sm shrink-0 cursor-not-allowed opacity-40">{{ __('portal.req_blocked_short') }}</button>
    @endif
</div>

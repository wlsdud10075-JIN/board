{{--
    판매 금액 보완 + ERP 재전송 — $e = 매물 1건.

    급해서 차량정보·매입가만 넣고 보낸 차는 **ERP 판매탭이 빈 채로** 생긴다.
    여기서 판매가·통화·환율을 채워 다시 밀면 car-erp 가 **빈 칸만** 채운다(fill-if-empty, 2026-08-18).

    ⚠️ **환율이 없으면 판매가가 통째로 안 들어간다** — 그래서 세 칸을 **세트로** 받는다(판매가만 받는 칸 ❌).
    🔁 2026-10-29 Jin — **이 경로의 판매측 6칸은 ERP 를 덮어쓴다**(`amounts_overwrite`, 종전 fill-if-empty 는 「금액만 보내기」를 무의미하게 했다). null 은 지우지 않는다.
    🚨 200 만 보고 "반영됨"이라 하지 않는다 — `fields_filled`·`fields_overwritten` 둘 다 비면 **바뀐 게 없는 것**이다(같은 값 또는 환율 없음 보류).
--}}
@php
    $synced = (bool) $e->car_erp_vehicle_id;
    // 재고매입(바이어 미정) 차 = 「바이어 지정해서 ERP 로 보내기」(2026-11-24 Jin, ERP §19). 금액 칸은 아래 「금액 다시 보내기」와 같은 칸·같은 쪼개기.
    //   종전(~11-23)엔 이 칸을 아예 안 줬다 — 재전송 길엔 락이 없어서. 이제 전용 길(assign-buyer)이 ERP 에서 락을 판정한다.
    $stock = $synced && $e->buyer_undecided;
@endphp
@if ($stock)
    <div class="section-title-sm mt-4">{{ __('listings.assign.title') }}
        <span class="text-[11px] font-normal text-gray-400">{{ __('listings.assign.hint') }}</span>
    </div>
    <div class="mt-1 grid grid-cols-1 gap-2 sm:grid-cols-2">
        <div>
            <label class="label-base">{{ __('listings.assign.buyer') }}</label>
            <select wire:model.live="a_buyer_id" class="input-base text-[12px]" data-assign-buyer>
                <option value="">{{ $aBuyerOpts === [] ? __('listings.assign.buyer_none') : '—' }}</option>
                @foreach ($aBuyerOpts as $b)
                    {{-- 락 표시는 ERP 판정 그대로(구매 화면과 같은 표시) — 🔒 = 락·남은 승인 없음 / 🔓N = 승인 남은 대수 --}}
                    <option value="{{ $b['id'] }}">{{ !empty($b['purchase_locked']) ? ((int) data_get($b, 'purchase_lock_allowance.remaining', 0) > 0 ? '🔓'.(int) data_get($b, 'purchase_lock_allowance.remaining').' ' : '🔒 ') : '' }}{{ $b['name'] }}{{ !empty($b['country']) ? ' ('.$b['country'].')' : '' }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label-base">{{ __('listings.assign.consignee') }}</label>
            <select wire:model="a_consignee_id" class="input-base text-[12px]" @disabled(! $a_buyer_id)>
                <option value="">—</option>
                @foreach ($aConsigneeOpts as $c)
                    <option value="{{ $c['id'] }}">{{ $c['name'] }}</option>
                @endforeach
            </select>
        </div>
    </div>
    @php $aLock = $this->assignBuyerLock(); @endphp
    @if ($aLock && $aLock['locked'] && $aLock['remaining'] < 1)
        <p class="mt-1 rounded-md border border-red-200 bg-red-50 px-2.5 py-1.5 text-[11px] text-red-700">🔒 {{ __('listings.assign.locked') }}</p>
    @elseif ($aLock && $aLock['locked'])
        <p class="mt-1 rounded-md border border-amber-200 bg-amber-50 px-2.5 py-1.5 text-[11px] text-amber-800">🔓 {{ __('listings.assign.lock_allowance', ['n' => $aLock['remaining']]) }}</p>
    @endif
    @error('a_buyer_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
@elseif ($synced)
    <div class="section-title-sm mt-4">{{ __('listings.resync.title') }}
        <span class="text-[11px] font-normal text-gray-400">{{ __('listings.resync.hint') }}</span>
    </div>
@endif
@if ($synced)
    {{-- 2026-10-29 Jin — /auction 구매 탭과 같은 칸·같은 공식. 사람이 적는 것 = 총판매가·통화·운임비 USD·환율 USD/EUR, 판매가·운임비(견적통화)는 계산(resendSplit). --}}
    @php $sp = $this->resendSplit(); @endphp
    <div class="mt-1 grid grid-cols-2 gap-2 sm:grid-cols-5">
        <div>
            <label class="label-base">{{ __('listings.resync.sale_total') }}</label>
            <input type="text" inputmode="decimal" wire:model.live.debounce.400ms="e_sale_total" class="input-base text-right text-[12px]">
        </div>
        <div>
            <label class="label-base">{{ __('listings.resync.currency') }}</label>
            <select wire:model.live="e_sale_currency" class="input-base text-[12px]">
                <option value="">—</option>
                @foreach (['KRW', 'USD', 'EUR'] as $cur)
                    <option value="{{ $cur }}">{{ $cur }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label-base">{{ __('listings.resync.transport_fee_usd') }}</label>
            <input type="text" inputmode="numeric" wire:model.live.debounce.400ms="e_transport_fee_usd" class="input-base text-right text-[12px]">
        </div>
        <div>
            <label class="label-base">{{ __('listings.resync.rate_usd') }}</label>
            <input type="text" inputmode="numeric" wire:model.live.debounce.400ms="e_rate_usd" class="input-base text-right text-[12px]">
        </div>
        <div>
            <label class="label-base">{{ __('listings.resync.rate_eur') }}</label>
            <input type="text" inputmode="numeric" wire:model.live.debounce.400ms="e_rate_eur" class="input-base text-right text-[12px]">
        </div>
    </div>
    <div class="mt-1.5 grid grid-cols-2 gap-2">
        <div>
            <label class="label-base">{{ __('listings.resync.transport_fee') }} <span class="text-gray-400">{{ __('listings.resync.auto') }}</span></label>
            <div class="input-base bg-gray-100 text-right text-[12px] text-gray-700">{{ $sp['freight'] !== null ? number_format($sp['freight'], fmod($sp['freight'], 1) == 0 ? 0 : 2) : '—' }}</div>
        </div>
        <div>
            <label class="label-base">{{ __('listings.resync.sale_price') }} <span class="text-gray-400">{{ __('listings.resync.auto') }}</span></label>
            <div class="input-base bg-gray-100 text-right text-[12px] font-semibold text-gray-800">{{ $sp['sale'] !== null ? number_format($sp['sale'], fmod($sp['sale'], 1) == 0 ? 0 : 2) : '—' }}</div>
        </div>
    </div>
    <p class="mt-1 text-[11px] text-gray-500">{{ $sp['currency'] ? __('listings.resync.split_hint', ['currency' => $sp['currency']]) : __('listings.resync.split_hint_nocur') }}</p>
    @error('e_sale_total') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    @error('e_transport_fee_usd') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    @error('e_rate_usd') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    @error('e_rate_eur') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    @error('e_sale_currency') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

    @if ($stock)
        <p class="mt-1 text-[11px] text-gray-500">{{ __('listings.assign.effect') }}</p>
        <button type="button" wire:click="assignBuyer" wire:loading.attr="disabled" wire:target="assignBuyer"
            wire:confirm="{{ __('listings.assign.confirm') }}"
            @disabled($aLock && $aLock['locked'] && $aLock['remaining'] < 1)
            class="btn-primary btn-sm mt-2 disabled:opacity-50">
            <span wire:loading.remove wire:target="assignBuyer">📤 {{ __('listings.assign.btn') }}</span>
            <span wire:loading wire:target="assignBuyer">{{ __('listings.assign.working') }}</span>
        </button>
    @else
    <button type="button" wire:click="resendToErp" wire:loading.attr="disabled" wire:target="resendToErp"
        class="btn-outline btn-sm mt-2">↻ {{ __('listings.resync.btn') }}</button>

    @if ($resyncResult !== null)
        @php
            $filled = $resyncResult['filled'] ?? [];
            $over = $resyncResult['overwritten'] ?? [];
            $any = $filled !== [] || $over !== [];
            $fieldLabel = fn ($f) => __('listings.resync.field.'.$f) === 'listings.resync.field.'.$f ? $f : __('listings.resync.field.'.$f);
        @endphp
        <div class="card-sm mt-2 text-[12px] {{ ! $any ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-green-200 bg-green-50 text-green-800' }}">
            {{-- 운영 큐는 비동기라 누른 직후엔 응답이 아직 없다 — 그때 **직전 전송 결과**를 이번 것처럼
                 보여주면 조용한 오표시가 된다(2026-09-11). --}}
            @if ($resyncResult['pending'] ?? false)
                <b wire:poll.3s="refreshSyncResult">{{ __('listings.resync.queued') }}</b>
            @elseif (! $any)
                {{-- 200 이어도 안 바뀌었을 수 있다: 같은 값이거나 환율이 없어 보류(missing_exchange_rate), 또는 옛 ERP(already_set). --}}
                <b>{{ __('listings.resync.nothing_filled') }}</b>
                @if (! empty($resyncResult['skipped']))
                    {{-- 🚫 ERP 가 준 키를 그대로 그리지 말 것 — 화면에 `sale_price — already_set` 이 떴다(2026-09-11).
                         모르는 값은 원문을 살린다(사유를 버리면 왜 안 들어갔는지 추적할 길이 없다). --}}
                    <div class="mt-1 text-[11px]">
                        @foreach ($resyncResult['skipped'] as $field => $why)
                            <span class="mr-2">{{ __('listings.resync.field.'.$field) === 'listings.resync.field.'.$field ? $field : __('listings.resync.field.'.$field) }}
                                — {{ __('listings.resync.why.'.$why) === 'listings.resync.why.'.$why ? $why : __('listings.resync.why.'.$why) }}</span>
                        @endforeach
                    </div>
                @endif
            @else
                @if ($over !== [])<b>{{ __('listings.resync.overwritten', ['fields' => implode(', ', array_map($fieldLabel, $over))]) }}</b>@endif
                @if ($filled !== [])<b class="{{ $over !== [] ? 'ml-2' : '' }}">{{ __('listings.resync.filled', ['fields' => implode(', ', array_map($fieldLabel, $filled))]) }}</b>@endif
            @endif
        </div>
    @endif
    @endif
@endif
{{-- 바이어 지정 결과 — 성공하면 재고매입 표시가 풀려 아래 칸이 「금액 다시 보내기」로 바뀌므로 분기 밖(맨 아래, 버튼 바로 밑)에 둔다. --}}
@if ($assignResult !== null)
        @if ($assignResult['ok'])
            <div class="card-sm mt-2 border-green-200 bg-green-50 text-[12px] text-green-800" data-assign-ok>
                <b>{{ __('listings.assign.ok', ['buyer' => $assignResult['buyer'] ?: '—']) }}</b>
                @if ($assignResult['sale_date'])<span class="ml-1">{{ __('listings.assign.ok_date', ['date' => $assignResult['sale_date']]) }}</span>@endif
                @if ($assignResult['consumed'])<div class="mt-0.5 text-[11px]">{{ __('listings.assign.ok_consumed', ['n' => (int) $assignResult['remaining']]) }}</div>@endif
            </div>
        @else
            <div class="card-sm mt-2 border-red-200 bg-red-50 text-[12px] text-red-700" data-assign-fail>
                <b>{{ $assignResult['message'] }}</b>
                @if ($assignResult['raw'])<span class="ml-1 text-[11px] text-red-500">({{ $assignResult['raw'] }})</span>@endif
            </div>
        @endif
    @endif

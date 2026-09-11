{{--
    판매 금액 보완 + ERP 재전송 — $e = 매물 1건.

    급해서 차량정보·매입가만 넣고 보낸 차는 **ERP 판매탭이 빈 채로** 생긴다.
    여기서 판매가·통화·환율을 채워 다시 밀면 car-erp 가 **빈 칸만** 채운다(fill-if-empty, 2026-08-18).

    ⚠️ **환율이 없으면 판매가가 통째로 안 들어간다** — 그래서 세 칸을 **세트로** 받는다(판매가만 받는 칸 ❌).
    ⚠️ **이미 값이 있으면 ERP 가 덮지 않는다**(`already_set`) — 고치는 건 ERP 에서. board 가 원장을 되돌리지 않는다.
    🚨 200 만 보고 "반영됨"이라 하지 않는다 — `fields_filled` 가 비면 **안 채워진 것**이다.
--}}
@php $synced = (bool) $e->car_erp_vehicle_id; @endphp
{{-- 재고매입(바이어 미정)은 이 칸을 아예 안 준다 — 판매가·바이어는 ERP 에서 지정한다(거기엔 락 게이트가 있다).
     칸만 남겨두면 채워 넣고 [다시 보내기]를 눌러도 ERP 엔 아무것도 안 들어간다(Job 이 판매측을 비운다). --}}
@if ($synced && $e->buyer_undecided)
    <div class="section-title-sm mt-4">{{ __('listings.resync.title') }}</div>
    <p class="mt-1 rounded-md border border-amber-300 bg-amber-50 px-2.5 py-2 text-[11px] text-amber-800">{{ __('listings.resync.stock_purchase') }}</p>
@elseif ($synced)
    <div class="section-title-sm mt-4">{{ __('listings.resync.title') }}
        <span class="text-[11px] font-normal text-gray-400">{{ __('listings.resync.hint') }}</span>
    </div>
    <div class="mt-1 grid grid-cols-3 gap-2">
        <div>
            <label class="label-base">{{ __('listings.resync.sale_price') }}</label>
            <input type="text" inputmode="decimal" wire:model="e_sale_price" class="input-base text-right text-[12px]">
        </div>
        <div>
            <label class="label-base">{{ __('listings.resync.currency') }}</label>
            <select wire:model="e_sale_currency" class="input-base text-[12px]">
                <option value="">—</option>
                @foreach (['KRW', 'USD', 'EUR'] as $cur)
                    <option value="{{ $cur }}">{{ $cur }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label-base">{{ __('listings.resync.rate') }}</label>
            <input type="text" inputmode="numeric" wire:model="e_sale_rate" class="input-base text-right text-[12px]">
        </div>
    </div>
    @error('e_sale_price') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    @error('e_sale_currency') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    @error('e_sale_rate') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

    <button type="button" wire:click="resendToErp" wire:loading.attr="disabled" wire:target="resendToErp"
        class="btn-outline btn-sm mt-2">↻ {{ __('listings.resync.btn') }}</button>

    @if ($resyncResult !== null)
        @php $filled = $resyncResult['filled'] ?? []; @endphp
        <div class="card-sm mt-2 text-[12px] {{ $filled === [] ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-green-200 bg-green-50 text-green-800' }}">
            {{-- 운영 큐는 비동기라 누른 직후엔 응답이 아직 없다 — 그때 **직전 전송 결과**를 이번 것처럼
                 보여주면 조용한 오표시가 된다(2026-09-11). --}}
            @if ($resyncResult['pending'] ?? false)
                <b>{{ __('listings.resync.queued') }}</b>
            @elseif ($filled === [])
                {{-- 200 이어도 안 채워졌을 수 있다: 이미 값이 있거나(already_set) 환율이 없어 보류(missing_exchange_rate). --}}
                <b>{{ __('listings.resync.nothing_filled') }}</b>
                @if (! empty($resyncResult['skipped']))
                    <div class="mt-1 text-[11px]">
                        @foreach ($resyncResult['skipped'] as $field => $why)
                            <span class="mr-2">{{ $field }} — {{ $why }}</span>
                        @endforeach
                    </div>
                @endif
            @else
                <b>{{ __('listings.resync.filled', ['fields' => implode(', ', $filled)]) }}</b>
            @endif
        </div>
    @endif
@endif

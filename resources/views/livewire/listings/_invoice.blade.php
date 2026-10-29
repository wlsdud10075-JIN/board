{{--
    Invoice 탭 — 송금인(여권/ID 카드) Name·문서번호 → ERP 송금인 칸 + board 전용 COMMERCIAL INVOICE (2026-10-29 ②, Jin 2026-10-28 결정).
    $e = 매물 1건.

    - **전 상태 편집**(draft·구매대기·won·synced 전부, 시간잠금 경매도) — Jin 「ERP 로 보낸 것도, 구매대기 상태로 있는 것도 다 가능해야 해」.
      그래서 저장은 `update()`(editable() 락) 가 아니라 `saveRemitter()` 별도 경로. 소유권은 SalesmanScope 가 막는다(본인 글 + 관리·super).
    - ID = **문서 번호**(여권 passport no. / ID 카드 card no.) — 개인번호·국적·생년월일은 보관하지 않는다. 저장 시 대문자 영숫자만.
    - 이름·ID 를 바꾸면 `remitter_synced_at` 이 비워져 「미전송」으로 돌아간다(ERP 에 옛 값이 남아 있다는 뜻).
    - 비우기는 ERP 로 전파되지 않는다(ERP 스펙: 빈 문자열 무시) — 안내 한 줄.
--}}
<p class="text-[11px] text-gray-500">{{ __('listings.invoice.hint') }}</p>

<label class="label-base mt-3">{{ __('listings.invoice.name') }} <span class="text-gray-400">{{ __('listings.invoice.name_hint') }}</span></label>
<input class="input-base uppercase" wire:model="e_remitter_name" placeholder="{{ __('listings.invoice.name_ph') }}" maxlength="100" autocomplete="off" spellcheck="false">
@error('e_remitter_name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

<label class="label-base mt-3">{{ __('listings.invoice.id') }} <span class="text-gray-400">{{ __('listings.invoice.id_hint') }}</span></label>
<input class="input-base font-mono uppercase" wire:model="e_remitter_id" placeholder="{{ __('listings.invoice.id_ph') }}" maxlength="64" autocomplete="off" spellcheck="false">
@error('e_remitter_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

<div class="mt-3 flex flex-wrap items-center gap-2">
    <button type="button" class="btn-primary btn-sm" wire:click="saveRemitter" wire:loading.attr="disabled" wire:target="saveRemitter">💾 {{ __('listings.invoice.save') }}</button>
    @php $inv = $e->invoiceState(); @endphp
    <span class="text-[11px] {{ $inv === 'synced' ? 'text-emerald-700' : ($inv === 'entered' ? 'text-blue-700' : 'text-gray-400') }}">
        @if ($inv === 'synced')
            ✅ {{ __('listings.invoice.state_synced', ['at' => $e->remitter_synced_at->timezone(config('app.timezone'))->format('m-d H:i')]) }}
        @elseif ($inv === 'entered')
            {{ $e->car_erp_vehicle_id ? __('listings.invoice.state_entered') : __('listings.invoice.state_no_erp_vehicle') }}
        @else
            {{ __('listings.invoice.state_none') }}
        @endif
    </span>
</div>
@if ($invoiceNote)
    <div class="card-sm mt-2 border-blue-100 bg-blue-50/60 text-[12px] text-blue-800">{{ $invoiceNote }}</div>
@endif

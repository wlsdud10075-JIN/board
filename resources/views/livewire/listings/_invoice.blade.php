{{--
    Invoice 탭 — 송금인(여권/ID 카드) Name·문서번호 → ERP 송금인 칸 + board 전용 COMMERCIAL INVOICE (2026-10-29 ②, Jin 2026-10-28 결정).
    $e = 매물 1건.

    - **전 상태 편집**(draft·구매대기·won·synced 전부, 시간잠금 경매도) — Jin 「ERP 로 보낸 것도, 구매대기 상태로 있는 것도 다 가능해야 해」.
      그래서 저장은 `update()`(editable() 락) 가 아니라 `saveRemitter()` 별도 경로. 소유권은 SalesmanScope 가 막는다(본인 글 + 관리·super).
    - 흐름 = 📷 여권/ID 사진(폰 카메라 바로) → OCR(MRZ 체크디지트 + 인쇄 칸 교차검증, 이미지는 읽은 뒤 폐기) → 후보 [적용] → Name·ID 칸(수기도 가능)
      → [송금인 저장] → [ERP 로 전송](ERP 차량 있을 때) → [인보이스 다운로드].
    - ID = **문서 번호**(여권 passport no. / ID 카드 card no.) — 개인번호·국적·생년월일은 보관하지 않는다. 저장 시 대문자 영숫자만.
    - 이름·ID 를 바꾸면 `remitter_synced_at` 이 비워져 「미전송」으로 돌아간다(ERP 에 옛 값이 남아 있다는 뜻). 비우기는 ERP 로 전파되지 않는다.
--}}
<p class="text-[11px] text-gray-500">{{ __('listings.invoice.hint') }}</p>

{{-- OCR — 사진 1장(폰 카메라 바로). 켜져 있지 않으면(GPU PC 없음) 버튼이 「OCR 미설정」으로 끝나고 수기 입력만 남는다. --}}
<div class="card-sm mt-3 border-gray-200 bg-gray-50">
    <div class="text-[12px] font-semibold text-gray-700">📷 {{ __('listings.invoice.ocr_title') }} <span class="text-[11px] font-normal text-gray-400">{{ __('listings.invoice.ocr_hint') }}</span></div>
    <div class="mt-2 flex flex-wrap items-center gap-2">
        <label class="flex cursor-pointer items-center justify-center rounded-lg border-2 border-dashed border-gray-300 px-3 py-2 text-[12px] text-gray-600 hover:border-[var(--color-primary)]">
            {{ $passportFile ? '🖼 '.\Illuminate\Support\Str::limit($passportFile->getClientOriginalName(), 24) : __('listings.invoice.ocr_pick') }}
            <input type="file" accept="image/*" capture="environment" wire:model="passportFile" class="hidden">
        </label>
        <span wire:loading wire:target="passportFile" class="text-[11px] text-gray-400">{{ __('listings.invoice.ocr_uploading') }}</span>
        @if ($passportFile)
            <button type="button" class="btn-primary btn-sm" wire:click="extractPassport" wire:loading.attr="disabled" wire:target="extractPassport,passportFile">{{ __('listings.invoice.ocr_run') }}</button>
        @endif
    </div>
    @error('passportFile') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

    @if ($e->passport_ocr_status === 'pending')
        <p class="mt-2 text-[11px] text-gray-500" wire:poll.5s>⏳ {{ $e->passportOcrSlow() ? __('listings.invoice.ocr_slow') : __('listings.invoice.ocr_running') }}</p>
    @elseif ($e->passport_ocr_status === 'done' && is_array($e->passport_suggestions))
        @php $sug = $e->passport_suggestions; @endphp
        <div class="mt-2 rounded-md border border-blue-200 bg-blue-50 px-2.5 py-2">
            <div class="text-[11px] font-semibold text-blue-800">{{ __('listings.invoice.ocr_candidates') }}@if (($sug['doc_type'] ?? '') !== '') <span class="font-normal text-blue-600">· {{ $sug['doc_type'] }}</span>@endif</div>
            @foreach (($sug['names'] ?? []) as $ni => $n)
                <div class="mt-1 flex items-center gap-2">
                    <span class="min-w-0 flex-1 truncate text-xs text-gray-800">{{ $n['value'] }} <span class="rounded bg-white px-1 text-[10px] text-gray-500">{{ __('listings.invoice.src_'.$n['source']) }}</span></span>
                    <button type="button" wire:click="applyPassportName({{ $ni }})" class="shrink-0 rounded border border-blue-300 bg-white px-2 py-0.5 text-[11px] text-blue-700">{{ __('listings.invoice.apply_name') }}</button>
                </div>
            @endforeach
            @foreach (($sug['ids'] ?? []) as $ii => $d)
                <div class="mt-1 flex items-center gap-2">
                    <span class="min-w-0 flex-1 truncate font-mono text-xs text-gray-800">{{ $d['value'] }} <span class="rounded bg-white px-1 font-sans text-[10px] text-gray-500">{{ __('listings.invoice.src_'.$d['source']) }}</span></span>
                    <button type="button" wire:click="applyPassportId({{ $ii }})" class="shrink-0 rounded border border-blue-300 bg-white px-2 py-0.5 text-[11px] text-blue-700">{{ __('listings.invoice.apply_id') }}</button>
                </div>
            @endforeach
            @foreach (($sug['warnings'] ?? []) as $w)
                <div class="mt-1 text-[11px] text-amber-700">⚠ {{ __('listings.invoice.warn_'.$w) }}</div>
            @endforeach
            <div class="mt-1.5 flex items-center justify-between">
                <span class="text-[10px] text-gray-500">{{ __('listings.invoice.ocr_discarded') }}</span>
                <button type="button" wire:click="dismissPassportSuggestions" class="text-[11px] text-gray-400 underline">{{ __('listings.invoice.ocr_dismiss') }}</button>
            </div>
        </div>
    @elseif (in_array($e->passport_ocr_status, ['none', 'failed'], true))
        <p class="mt-2 text-[11px] font-semibold text-amber-700">⚠ {{ $e->passport_ocr_status === 'none' ? __('listings.invoice.ocr_none') : __('listings.invoice.ocr_failed') }}</p>
    @else
        <p class="mt-1 text-[10px] text-gray-400">{{ __('listings.invoice.ocr_discarded') }}</p>
    @endif
</div>

<label class="label-base mt-3">{{ __('listings.invoice.name') }} <span class="text-gray-400">{{ __('listings.invoice.name_hint') }}</span></label>
<input class="input-base uppercase" wire:model="e_remitter_name" placeholder="{{ __('listings.invoice.name_ph') }}" maxlength="100" autocomplete="off" spellcheck="false">
@error('e_remitter_name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

<label class="label-base mt-3">{{ __('listings.invoice.id') }} <span class="text-gray-400">{{ __('listings.invoice.id_hint') }}</span></label>
<input class="input-base font-mono uppercase" wire:model="e_remitter_id" placeholder="{{ __('listings.invoice.id_ph') }}" maxlength="64" autocomplete="off" spellcheck="false">
@error('e_remitter_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

@php $inv = $e->invoiceState(); $hasErpVehicle = $e->car_erp_vehicle_id !== null; @endphp
<div class="mt-3 flex flex-wrap items-center gap-2">
    <button type="button" class="btn-primary btn-sm" wire:click="saveRemitter" wire:loading.attr="disabled" wire:target="saveRemitter">💾 {{ __('listings.invoice.save') }}</button>
    {{-- ERP 전송 = 저장된 값을 보낸다(입력칸 값 아님). ERP 차량이 없으면(won 전) 비활성 + 「구매확정 시 함께」. --}}
    <button type="button" class="btn-outline btn-sm" wire:click="sendRemitter" wire:loading.attr="disabled" wire:target="sendRemitter"
            @if (! $hasErpVehicle || $inv === 'none') disabled title="{{ ! $hasErpVehicle ? __('listings.invoice.state_no_erp_vehicle') : __('listings.invoice.send_needs_save') }}" @endif>
        📤 {{ __('listings.invoice.send') }}
    </button>
    <button type="button" class="btn-outline btn-sm" wire:click="downloadInvoice" wire:loading.attr="disabled" wire:target="downloadInvoice"
            @if ($inv === 'none') disabled title="{{ __('listings.invoice.download_needs_name') }}" @endif>
        📄 {{ __('listings.invoice.download') }}
    </button>
</div>
<p class="mt-1.5 text-[11px] {{ $inv === 'synced' ? 'text-emerald-700' : ($inv === 'entered' ? 'text-blue-700' : 'text-gray-400') }}">
    @if ($inv === 'synced')
        ✅ {{ __('listings.invoice.state_synced', ['at' => $e->remitter_synced_at->timezone(config('app.timezone'))->format('m-d H:i')]) }}
    @elseif ($inv === 'entered')
        {{ $hasErpVehicle ? __('listings.invoice.state_entered') : __('listings.invoice.state_no_erp_vehicle') }}
    @else
        {{ __('listings.invoice.state_none') }}
    @endif
</p>
@if ($invoiceNote)
    <div class="card-sm mt-2 border-blue-100 bg-blue-50/60 text-[12px] text-blue-800">{{ $invoiceNote }}</div>
@endif

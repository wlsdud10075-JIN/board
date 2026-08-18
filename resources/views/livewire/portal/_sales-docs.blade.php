{{--
    판매계약서 · 프로포마 인보이스 · 전자서명 — **차량 id 기반**이라 묶음(bundle) 없이도 발급된다
    (`downloadDocs(vehicleIds, method, kind)` · `requestSignature(vehicleIds, batchId?)` — batchId 옵셔널).

    그래서 선적 **계획**(아직 sync 전 편집상태)에서도 같은 블록을 쓴다(Jin 2026-08-18) —
    선적묶음까지 가지 않고 바이어별로 차를 담자마자 계약서·서명을 돌릴 수 있어야 한다는 요청.
    선적 4종(`roro_*`/`container_*`)은 **실제 선적 단계 서류라 묶음 화면에만** 둔다.

    필요 변수: $vIds(차량 id 배열) · $method(RORO|CONTAINER) · $batchId(없으면 null) · $signStatus · $signResults
    ⚠️ 서류 버튼 이름은 car-erp `vehicle.shipdoc.*` 과 **같은 이름**을 쓴다 — board 에서 새로 지으면
       "ERP엔 그런 서류 없다"가 된다.
--}}
@php
    $signKey = $batchId ?: implode('-', array_values(array_map('intval', $vIds)));
    $signSt = $signStatus[$signKey]['status'] ?? 'none';
    $signContractNo = $signStatus[$signKey]['contract_no'] ?? null;
@endphp
<div class="mt-2 flex flex-wrap items-center gap-2 text-[12px]">
    <button wire:click="downloadDocs({{ json_encode($vIds) }}, '{{ $method ?: 'RORO' }}', 'sales_contract')" class="btn-ghost btn-sm">📄 {{ __('portal.docs_sales_contract') }}</button>
    {{-- 프로포마 인보이스 — car-erp 타입명은 'invoice'(선적 인보이스·패킹과 다른 서류). --}}
    <button wire:click="downloadDocs({{ json_encode($vIds) }}, '{{ $method ?: 'RORO' }}', 'invoice')" class="btn-ghost btn-sm">📄 {{ __('portal.docs_proforma_invoice') }}</button>
    @if ($signSt === 'signed')
        <span class="inline-flex items-center gap-1 rounded-full bg-green-100 px-2.5 py-1 text-[11px] font-bold text-green-700">✓ {{ __('portal.sign_st_signed') }}@if ($signContractNo)<span class="font-normal">· {{ $signContractNo }}</span>@endif</span>
        <button wire:click="requestSignature({{ json_encode($vIds) }}, {{ $batchId ? "'".$batchId."'" : 'null' }})" wire:confirm="{{ __('portal.sign_reissue_confirm') }}" class="btn-ghost btn-sm">↻ {{ __('portal.sign_reissue_btn') }}</button>
    @elseif ($signSt === 'pending' || $signSt === 'viewed')
        <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2.5 py-1 text-[11px] font-bold text-amber-700">⏳ {{ __('portal.sign_st_pending') }}</span>
        <button wire:click="requestSignature({{ json_encode($vIds) }}, {{ $batchId ? "'".$batchId."'" : 'null' }})" wire:confirm="{{ __('portal.sign_reissue_confirm') }}" class="btn-ghost btn-sm">↻ {{ __('portal.sign_reissue_btn') }}</button>
    @else
        <button wire:click="requestSignature({{ json_encode($vIds) }}, {{ $batchId ? "'".$batchId."'" : 'null' }})" wire:confirm="{{ __('portal.sign_request_confirm') }}" class="btn-ghost btn-sm">✍️ {{ __('portal.sign_request_btn') }}</button>
    @endif
</div>
{{-- §10 서명 세션 결과 — signed_url 을 영업이 바이어에게 직접 전달(ERP 는 전달 대행 안 함). --}}
@php $sign = $signResults[$signKey] ?? null; @endphp
@if ($sign)
    <div class="mt-2 rounded-md border border-blue-200 bg-blue-50 p-2.5 text-[12px]" x-data="{ copied: false }" wire:key="sign-{{ $signKey }}">
        <p class="mb-1 font-semibold text-blue-800">
            ✍️ {{ __('portal.sign_ready_title') }}
            @if ($sign['contract_no'])<span class="ml-1 font-normal text-blue-600">{{ $sign['contract_no'] }}</span>@endif
        </p>
        <p class="mb-1.5 text-[11px] text-blue-600">{{ __('portal.sign_ready_hint') }}</p>
        <div class="flex items-center gap-1.5">
            <input type="text" readonly x-ref="signurl" value="{{ $sign['signed_url'] }}"
                @focus="$event.target.select()" class="input-base flex-1 bg-white text-[11px]">
            <button type="button" class="btn-ghost btn-sm shrink-0"
                @click="navigator.clipboard.writeText($refs.signurl.value); copied = true; setTimeout(() => copied = false, 1500)">
                <span x-show="!copied">📋 {{ __('portal.sign_copy_btn') }}</span>
                <span x-show="copied" x-cloak class="text-green-600">✅ {{ __('portal.sign_copied') }}</span>
            </button>
        </div>
        @if ($sign['expires_at'])
            <p class="mt-1 text-[11px] text-gray-500">{{ __('portal.sign_expires', ['at' => \Illuminate\Support\Carbon::parse($sign['expires_at'])->format('Y-m-d H:i')]) }}</p>
        @endif
    </div>
@endif

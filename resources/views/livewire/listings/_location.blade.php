{{-- 차 위치(2026-11-05 Jin, ssancar 매매단지 연동) — 엔카 링크 없는 검차 대상 차. 같은 지역 단지 먼저 · 「직접 입력」 = 주소.
     @param choiceModel·addressModel = Livewire 프로퍼티명 / choice = 현재 값 / region = 그 폼의 지역 / current = 드로어 매물(등록 폼은 null) --}}
@php
    $yardGroups = \App\Support\SsancarYards::grouped($region);
    $yardListed = collect($yardGroups['same'])->merge($yardGroups['other'])->pluck('id')->all();
    $yardFailed = $yardListed === [];
@endphp
<div class="mt-3">
    <label class="label-base">{{ __('listings.loc.label') }} <span class="text-gray-400">{{ __('listings.loc.hint') }}</span></label>
    <select class="input-base" wire:model.live="{{ $choiceModel }}" @disabled($disabled)>
        <option value="">{{ __('listings.loc.none') }}</option>
        @if ($current?->yard_id && ! in_array($current->yard_id, $yardListed, true))
            <option value="{{ $current->yard_id }}">{{ $current->yard_name ?? '#'.$current->yard_id }} {{ __('listings.loc.not_in_list') }}</option>
        @endif
        @if ($yardGroups['same'])
            <optgroup label="{{ __('listings.loc.same_region') }}">
                @foreach ($yardGroups['same'] as $y)<option value="{{ $y['id'] }}">{{ $y['name'] }}</option>@endforeach
            </optgroup>
        @endif
        @if ($yardGroups['other'])
            <optgroup label="{{ __('listings.loc.other_region') }}">
                @foreach ($yardGroups['other'] as $y)<option value="{{ $y['id'] }}">{{ $y['region'] }} · {{ $y['name'] }}</option>@endforeach
            </optgroup>
        @endif
        <option value="manual">{{ __('listings.loc.manual') }}</option>
    </select>
    @if ($choice === 'manual')
        <input class="input-base mt-1.5" wire:model="{{ $addressModel }}" maxlength="255" placeholder="{{ __('listings.loc.address_ph') }}" @disabled($disabled)>
    @endif
    @error($choiceModel) <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    @error($addressModel) <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    @if ($yardFailed && $choice !== 'manual')
        <p class="mt-1 text-xs text-amber-600">{{ __('listings.loc.list_failed') }}</p>
    @endif
    @if ($current?->yard_id && (string) $current->yard_id === $choice)
        @if ($current->yard_status === 'unknown')
            <p class="mt-1 text-xs font-semibold text-red-600">⚠ {{ __('listings.loc.unknown') }}</p>
        @elseif ($current->yard_status === 'ok')
            <p class="mt-1 text-[11px] text-green-700">✓ {{ __('listings.loc.ok') }}</p>
        @endif
    @endif
</div>

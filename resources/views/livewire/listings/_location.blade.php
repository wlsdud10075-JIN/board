{{-- 차 위치(2026-11-05 Jin, ssancar 매매단지 연동) — 엔카 링크 없는 검차 대상 차. 「직접 입력」 = 주소.
     지역을 적으면 **그 지역 단지만**, 비어 있으면 전체(Jin 2026-11-05 — 서버 렌더로 거르면 지역 칸이 저장 전엔 서버에 안 가서
     다른 칸을 건드려야 바뀌었다 → 브라우저에서 `$wire` 지역 값으로 바로 거른다). 그 지역 단지가 없으면 전체.
     @param choiceModel·addressModel·regionModel = Livewire 프로퍼티명 / choice = 현재 값 / current = 드로어 매물(등록 폼은 null) --}}
@php
    $yards = collect(\App\Support\SsancarYards::all())->sortBy(fn ($y) => [$y['region'], $y['name']])->values()->all();
    $yardIds = array_column($yards, 'id');
    $orphan = $current?->yard_id && ! in_array($current->yard_id, $yardIds, true) ? $current : null;   // 목록에서 빠진 기존 단지
@endphp
<div class="mt-3">
    <label class="label-base">{{ __('listings.loc.label') }} <span class="text-gray-400">{{ __('listings.loc.hint') }}</span></label>
    <div x-data="{
            choice: $wire.entangle(@js($choiceModel)).live,
            yards: @js($yards),
            norm(s) { return String(s ?? '').replace(/\s+/g, '') },
            get list() {
                const r = this.norm($wire[@js($regionModel)]);
                if (r === '') return this.yards;
                const m = this.yards.filter(y => { const yr = this.norm(y.region); return yr !== '' && (yr.includes(r) || r.includes(yr)) });
                return m.length ? m : this.yards;
            },
            get filtered() { return this.norm($wire[@js($regionModel)]) !== '' && this.list.length < this.yards.length },
         }">
        <select class="input-base" x-model="choice" @disabled($disabled)>
            <option value="">{{ __('listings.loc.none') }}</option>
            @if ($orphan)
                <option value="{{ $orphan->yard_id }}">{{ $orphan->yard_name ?? '#'.$orphan->yard_id }} {{ __('listings.loc.not_in_list') }}</option>
            @endif
            <template x-for="y in list" :key="y.id">
                <option :value="String(y.id)" :selected="String(y.id) === String(choice)" x-text="filtered ? y.name : y.region + ' · ' + y.name"></option>
            </template>
            <option value="manual">{{ __('listings.loc.manual') }}</option>
        </select>
    </div>
    @if ($choice === 'manual')
        <input class="input-base mt-1.5" wire:model="{{ $addressModel }}" maxlength="255" placeholder="{{ __('listings.loc.address_ph') }}" @disabled($disabled)>
    @endif
    @error($choiceModel) <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    @error($addressModel) <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    @if ($yards === [] && $choice !== 'manual')
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

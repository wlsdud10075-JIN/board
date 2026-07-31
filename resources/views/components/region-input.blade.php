{{--
    검사지역 입력 + 자동완성.

    ⚠️ `<datalist>` 를 쓰지 않는 이유 = **모바일에서 자동완성이 안 뜬다**(iOS Safari 미지원, 안드로이드도 제각각).
    검차원은 대부분 모바일이라 실질적으로 자동완성이 없는 것과 같았다 → Alpine 드롭다운으로 대체.

    후보 = config('board.regions')(정식 라벨). 공백을 무시하고 부분일치하므로 "안산"·"경기안산" 둘 다 걸린다.
    목록에 없는 값도 그대로 입력 가능(자유 입력) — 저장 시 App\Support\Region 이 정식형으로 맞춘다.

    @props model = 바인딩할 Livewire 프로퍼티명(문자열). $wire.entangle 로 양방향 — 서버가 값을 바꿔도
                   (링크 자동채움 등) 인풋에 반영된다.
--}}
@props(['model', 'placeholder' => '', 'disabled' => false])

<div
    class="relative"
    x-data="{
        value: $wire.entangle(@js($model)),
        isOpen: false,
        active: 0,
        options: @js(config('board.regions')),
        get matches() {
            const q = (this.value ?? '').replace(/\s/g, '');
            const list = q === '' ? this.options : this.options.filter(o => o.replace(/\s/g, '').includes(q));
            return list.slice(0, 8);
        },
        choose(option) {
            this.value = option;
            this.isOpen = false;
        },
        move(step) {
            if (! this.isOpen) { this.isOpen = true; return; }
            const last = this.matches.length - 1;
            this.active = Math.min(Math.max(this.active + step, 0), Math.max(last, 0));
        },
    }"
    @click.outside="isOpen = false"
>
    <input
        type="text"
        class="input-base"
        autocomplete="off"
        x-model="value"
        @focus="isOpen = true; active = 0"
        @input="isOpen = true; active = 0"
        @keydown.escape.stop="isOpen = false"
        @keydown.arrow-down.prevent="move(1)"
        @keydown.arrow-up.prevent="move(-1)"
        @keydown.enter.prevent="matches[active] && choose(matches[active])"
        placeholder="{{ $placeholder }}"
        @disabled($disabled)
    >

    <ul
        x-show="isOpen && matches.length"
        x-cloak
        class="absolute z-30 mt-1 max-h-56 w-full overflow-y-auto rounded-lg border border-gray-200 bg-white py-1 shadow-lg"
    >
        <template x-for="(option, i) in matches" :key="option">
            <li
                x-text="option"
                {{-- mousedown = blur 보다 먼저 발생 → 모바일에서 탭이 씹히지 않는다 --}}
                @mousedown.prevent="choose(option)"
                @mouseenter="active = i"
                class="cursor-pointer px-3 py-2 text-sm"
                :class="active === i ? 'bg-blue-50 text-blue-700' : 'text-gray-700'"
            ></li>
        </template>
    </ul>
</div>

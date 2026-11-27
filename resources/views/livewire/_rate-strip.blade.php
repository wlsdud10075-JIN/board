{{--
    환율 줄(2026-11-27 Jin «매입예정·전달대기·구매 세 곳에, ERP 처럼 실시간·마감 전부, 모바일에서 안 뭉개지게»).
    값은 전부 ERP `/rates` 가 준 것(board 는 계산·수집 없음) — 실시간 = 송금 받을 때, 마감 = ERP 가 준 날짜의 송금받을때
    (같은 날 저녁 Jin «board 는 송금받을때 기준» — 매매기준율 줄은 뺐다. 종류 = ExchangeRateService::CLOSING_KINDS).
    마감 값은 **보여 주기 전용**(계산·ERP 전송에 안 쓴다). ERP 응답에 마감이 없으면 마감 줄을 안 그린다.
    날짜 칸(같은 날 Jin «일자별로 과거 것도») = 화면의 `$fxDate`(빈 값 = 가장 최근 마감). 고르면 그날 마감 두 줄로 바뀌고 실시간 줄은 그대로.
    ↻ = 화면의 `refreshRate`(ERP 값을 다시 읽기만 한다). 표는 3칸 고정(이름·USD·EUR) — 360px 에서도 줄이 안 깨진다.
    🚫 빨간 글씨 금지 — 드로어 오류 스크롤 훅에 걸린다(경고는 호박색).
--}}
@php
    $fx = app(\App\Services\ExchangeRateService::class)->strip($fxDate ?? '');
    $fmt = fn ($v) => $v === null ? '—' : number_format((float) $v, 2);
    $cl = $fx['closing'];
    $md = fn (string $d) => ['m' => (int) substr($d, 5, 2), 'd' => (int) substr($d, 8, 2)];
@endphp
<div class="mb-3 w-full rounded-lg border px-3 py-2 text-xs text-gray-600" style="max-width:26rem;background:#f5f8ff;border-color:#dbeafe" data-rate-strip>
    {{-- 격자는 인라인 스타일(임의 값 클래스는 빌드 전엔 안 먹는다) --}}
    <div style="display:grid;grid-template-columns:minmax(0,1fr) auto auto;column-gap:1rem;row-gap:.25rem;align-items:baseline;font-variant-numeric:tabular-nums">
        <div class="flex items-center gap-1.5 font-semibold text-gray-700">
            💱 {{ __('rates.title') }}
            <span class="text-[10px] font-bold {{ $fx['is_live'] ? 'text-green-600' : 'text-amber-600' }}">{{ $fx['is_live'] ? 'LIVE' : __('rates.temp') }}</span>
            <button type="button" wire:click="refreshRate" wire:loading.attr="disabled" wire:target="refreshRate" class="text-blue-500 hover:text-blue-700" title="{{ __('rates.refresh') }}">↻</button>
        </div>
        <div class="text-right text-[10px] font-semibold text-gray-400">USD</div>
        <div class="text-right text-[10px] font-semibold text-gray-400">EUR</div>

        <div class="min-w-0" data-rate-live>
            {{ __('rates.live') }}
            @if ($fx['fetched_at'])<span class="whitespace-nowrap text-[10px] text-gray-400">{{ __('rates.as_of', ['time' => substr($fx['fetched_at'], 5)]) }}</span>@endif
        </div>
        <div class="whitespace-nowrap text-right font-bold text-gray-800">{{ $fx['USD_display'] }}</div>
        <div class="whitespace-nowrap text-right font-bold text-gray-800">{{ $fx['EUR_display'] }}</div>

        @if ($cl)
            {{-- 마감 날짜 줄 — 세 칸을 다 쓴다. 날짜 칸을 누르면 지난 날짜를 고른다(오늘·미래는 못 고름). --}}
            <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 border-t pt-1.5" style="grid-column:1 / -1;border-color:#dbeafe" data-rate-closing-date>
                <span class="font-semibold text-gray-700">{{ __('rates.closing') }}</span>
                <input type="date" value="{{ $fx['picked'] ?? $cl['date'] }}" max="{{ now()->subDay()->toDateString() }}"
                       wire:change="$set('fxDate', $event.target.value)" aria-label="{{ __('rates.pick_date') }}"
                       class="rounded border border-gray-300 bg-white px-1.5 py-0.5 text-xs text-gray-700" style="max-width:9.5rem">
                @if ($fx['picked'])
                    <button type="button" wire:click="$set('fxDate', '')" class="text-blue-600 hover:underline" data-rate-latest>{{ __('rates.latest') }}</button>
                    @if ($cl['date'] && $cl['date'] !== $fx['picked'])
                        <span class="whitespace-nowrap text-[10px] text-gray-400">{{ __('rates.closing_actual', $md($cl['date'])) }}</span>
                    @endif
                @endif
            </div>
            @foreach (\App\Services\ExchangeRateService::CLOSING_KINDS as $kind)
                <div class="min-w-0 whitespace-nowrap" data-rate-closing="{{ $kind }}">{{ __('rates.kind_'.$kind) }}</div>
                <div class="whitespace-nowrap text-right font-semibold text-gray-700">{{ $fmt($cl[$kind]['USD'] ?? null) }}</div>
                <div class="whitespace-nowrap text-right font-semibold text-gray-700">{{ $fmt($cl[$kind]['EUR'] ?? null) }}</div>
            @endforeach
        @endif
    </div>
    @if ($fx['missing'])
        <p class="mt-1 text-[10px] text-amber-700" data-rate-closing-missing>{{ __('rates.closing_missing') }}</p>
    @elseif ($cl && $cl['stale'])
        <p class="mt-1 text-[10px] text-amber-700" data-rate-closing-stale>{{ __('rates.closing_stale') }}</p>
    @endif
    @if (! $fx['is_live'])
        <p class="mt-1 text-[10px] text-amber-700">{{ __('rates.temp_hint') }}</p>
    @endif
</div>

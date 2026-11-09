{{-- ⏸ 지급보류 · ➕ 지급추가 뱃지 — 요약 월 행 「정산」 금액 옆(ERP 사내직원관리 이름 옆 뱃지 미러, Jin 2026-11-09).
     $row = $monthly[월] 행(hold_sum · extra_sum · hold_lines[] · extra_lines[]). 뱃지를 누르면 그 달 줄 목록(금액·상태·사유·처리일).
     ⚠️ 부호는 ERP 뱃지와 동일하게 **저장값 부호 그대로**(hold 는 지급과 같은 부호의 크기 → 보통 +). board 가 − 로 뒤집지 않는다.
     ⚠️ 월 행 클릭이 상세 펼침이라 뱃지 클릭은 `.stop` — 둘이 겹치면 펼침이 같이 열린다.
     🚫 실송금(정산 − 보류 + 추가) 계산 금지 — ERP 송금엔 급여 줄이 있어 board 가 만든 숫자는 ERP 와 어긋난다(Jin (가), 2026-11-09). --}}
@php
    $kinds = [];
    foreach (['hold' => ['⏸', 'badge-gray', 'settle_hold_title'], 'extra' => ['➕', 'badge-amber', 'settle_extra_title']] as $k => [$icon, $cls, $title]) {
        $lines = (array) ($row[$k.'_lines'] ?? []);
        if ($lines === [] && ! isset($row[$k.'_sum'])) {
            continue;
        }
        $kinds[$k] = ['icon' => $icon, 'cls' => $cls, 'title' => $title, 'sum' => (float) ($row[$k.'_sum'] ?? 0), 'lines' => $lines];
    }
    $fmt = fn (float $v) => ($v < 0 ? '−' : '+').number_format(abs($v));
@endphp
@if ($kinds !== [])
    <span class="inline-flex flex-wrap items-center gap-1 align-middle" x-data="{ hb: false }" @click.stop>
        @foreach ($kinds as $k => $g)
            <button type="button" class="badge {{ $g['cls'] }} text-[10px]" @click="hb = !hb" title="{{ __('portal.'.$g['title']) }}" data-settle-{{ $k }}-badge>{{ $g['icon'] }} {{ $fmt($g['sum']) }}</button>
        @endforeach
        <div x-show="hb" x-cloak class="basis-full text-left text-[11px] font-normal text-gray-600">
            @foreach ($kinds as $k => $g)
                @foreach ($g['lines'] as $l)
                    @php
                        $st = (string) (data_get($l, 'status') ?? '');
                        $stKey = 'portal.settle_hold_status_'.$st;
                        $stLabel = $st !== '' ? (\Illuminate\Support\Facades\Lang::has($stKey) ? __($stKey) : $st) : null;   // 모르는 상태 = 원문
                    @endphp
                    <div class="flex items-start gap-1">
                        <span class="shrink-0">{{ $g['icon'] }}</span>
                        <span>
                            <b class="font-mono text-gray-800">{{ $fmt((float) (data_get($l, 'amount_krw') ?? 0)) }}</b>
                            @if ($stLabel) · {{ $stLabel }}@endif
                            @if ($rel = data_get($l, 'released_at')) · {{ __('portal.settle_hold_released_on', ['date' => substr((string) $rel, 0, 10)]) }}@endif
                            @if ($note = data_get($l, 'note')) · {{ $note }}@endif
                        </span>
                    </div>
                @endforeach
            @endforeach
        </div>
    </span>
@endif

{{-- 진행 중 정산 — **아직 안 받은 것**(ERP 정산처리 탭의 본인 몫). 월별 실적(= 받은 것)과 짝.
     🚨 상태를 **합치지 않는다.** pending 은 비용·환율이 아직 움직여 확정에서 금액이 달라진다 —
        한 숫자로 뭉치면 영업이 "받을 돈"으로 읽고 분쟁이 된다. 그래서 총합 없이 상태별 소계만 낸다.
     ⚠️ 상태 목록은 **ERP 가 준 것을 그대로** 순회한다(board 화이트리스트 아님 — `calculating` 처럼
        board 가 모르는 상태가 와도 사라지면 안 된다). 라벨·힌트가 없는 상태는 원문을 찍고 힌트는 생략. --}}
@if ($inProgress)
    @php
        $stStyle = ['confirmed' => 'text-amber-700', 'calculating' => 'text-sky-700', 'pending' => 'text-gray-500'];
    @endphp
    <div class="mt-4" wire:key="inprog" x-data="{ open: true }">
        <button type="button" class="mb-2 flex items-center gap-2 font-bold text-gray-700" @click="open = !open">
            <span class="w-3 text-gray-400" x-text="open ? '▼' : '▶'"></span>
            ⏳ {{ __('portal.inprog_title') }}
            <span class="text-xs font-normal text-gray-400">({{ __('portal.inprog_estimate') }})</span>
        </button>
        <div x-show="open" x-cloak class="space-y-2 text-xs">
            @foreach ((array) data_get($inProgress, 'rows', []) as $st => $list)
                @php
                    $labelKey = 'portal.inprog_'.$st;
                    $hintKey = 'portal.inprog_hint_'.$st;
                @endphp
                <div class="rounded border border-gray-200 bg-white p-2" wire:key="inprog-{{ $st }}">
                    <div class="mb-1 flex items-center justify-between gap-2">
                        <span class="font-semibold {{ $stStyle[$st] ?? 'text-gray-600' }}">
                            {{ Lang::has($labelKey) ? __($labelKey) : $st }}
                            <span class="font-normal text-gray-400">{{ __('portal.unit_count', ['count' => count($list)]) }}</span>
                        </span>
                        <b class="shrink-0 text-gray-800">{{ number_format((float) data_get($inProgress, 'sum.'.$st, 0)) }}</b>
                    </div>
                    @foreach ($list as $s)
                        <div class="flex items-center justify-between gap-2 border-t border-gray-100 py-1">
                            <span class="text-gray-700">{{ data_get($s, 'vehicle_number') ?: '—' }}</span>
                            <span class="flex shrink-0 items-center gap-2">
                                <span class="text-[11px] text-gray-400">{{ data_get($s, 'confirmed_at') }}</span>
                                <b class="text-gray-800">{{ number_format((float) (data_get($s, 'actual_payout') ?? 0)) }}</b>
                            </span>
                        </div>
                    @endforeach
                    @if (Lang::has($hintKey))
                        <p class="mt-1 text-[11px] text-gray-400">{{ __($hintKey) }}</p>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
@endif

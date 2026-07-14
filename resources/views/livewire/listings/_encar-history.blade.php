{{-- 엔카 차량이력(보험/성능점검/진단) read-only 패널 — 조회전용, board 저장 안 함. --}}
@if ($showHistory)
    <div class="mt-3 rounded-md border border-gray-200 bg-white p-3 text-[12px] text-gray-700" wire:key="encar-history">
        <div class="mb-2 flex items-center justify-between">
            <span class="font-semibold">{{ __('listings.history.title') }}</span>
            <button type="button" class="text-gray-400 hover:text-gray-600" wire:click="$set('showHistory', false)">✕</button>
        </div>

        @if ($encarHistory === [])
            <p class="text-gray-400">{{ __('listings.history.none') }}</p>
        @else
            @php $rec = $encarHistory['record']; $insp = $encarHistory['inspection']; $diag = $encarHistory['diagnosis']; @endphp

            {{-- ① 보험이력 --}}
            <div class="mb-3">
                <p class="mb-1 font-semibold text-gray-600">{{ __('listings.history.record') }}</p>
                @if ($rec)
                    @if ($rec['title'])<p class="mb-1 text-gray-500">{{ $rec['title'] }}</p>@endif
                    <div class="grid grid-cols-2 gap-x-4 gap-y-0.5 sm:grid-cols-3">
                        <span>{{ __('listings.history.my_accident') }} <b>{{ $rec['myAccidentCnt'] }}</b> · ₩{{ number_format($rec['myAccidentCost']) }}</span>
                        <span>{{ __('listings.history.other_accident') }} <b>{{ $rec['otherAccidentCnt'] }}</b> · ₩{{ number_format($rec['otherAccidentCost']) }}</span>
                        <span>{{ __('listings.history.owner_change') }} <b>{{ $rec['ownerChangeCnt'] }}</b></span>
                        <span>{{ __('listings.history.total_loss') }} <b>{{ $rec['totalLossCnt'] }}</b> · {{ __('listings.history.flood') }} <b>{{ $rec['floodCnt'] }}</b></span>
                        <span>{{ __('listings.history.robber') }} <b>{{ $rec['robberCnt'] }}</b> · {{ __('listings.history.special_use') }} <b>{{ $rec['specialUse'] }}</b></span>
                    </div>
                    @if ($rec['accidents'])
                        <div class="mt-1.5 overflow-x-auto">
                            <table class="w-full min-w-[360px] text-[11px]">
                                <thead class="text-gray-400">
                                    <tr>
                                        <th class="py-0.5 text-left font-medium">{{ __('listings.history.acc_date') }}</th>
                                        <th class="py-0.5 text-right font-medium">{{ __('listings.history.acc_benefit') }}</th>
                                        <th class="py-0.5 text-right font-medium">{{ __('listings.history.acc_part') }}</th>
                                        <th class="py-0.5 text-right font-medium">{{ __('listings.history.acc_labor') }}</th>
                                        <th class="py-0.5 text-right font-medium">{{ __('listings.history.acc_paint') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($rec['accidents'] as $a)
                                        <tr class="border-t border-gray-100">
                                            <td class="py-0.5">{{ $a['date'] }}</td>
                                            <td class="py-0.5 text-right">{{ number_format($a['insuranceBenefit']) }}</td>
                                            <td class="py-0.5 text-right">{{ number_format($a['partCost']) }}</td>
                                            <td class="py-0.5 text-right">{{ number_format($a['laborCost']) }}</td>
                                            <td class="py-0.5 text-right">{{ number_format($a['paintingCost']) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                @else
                    <p class="text-gray-400">{{ __('listings.history.no_data') }}</p>
                @endif
            </div>

            {{-- ② 성능점검 (+ 내역) --}}
            <div class="mb-3 border-t border-gray-100 pt-2">
                <p class="mb-1 font-semibold text-gray-600">{{ __('listings.history.inspection') }}</p>
                @if ($insp)
                    <div class="grid grid-cols-2 gap-x-4 gap-y-0.5 sm:grid-cols-3">
                        <span>{{ __('listings.history.mileage') }} <b>{{ number_format($insp['mileage']) }}</b> km</span>
                        <span>{{ __('listings.history.accident_flag') }} <b>{{ $insp['accident'] ? __('listings.history.yes') : __('listings.history.no') }}</b></span>
                        <span>{{ __('listings.history.simple_repair') }} <b>{{ $insp['simpleRepair'] ? __('listings.history.yes') : __('listings.history.no') }}</b></span>
                        <span>{{ __('listings.history.waterlog') }} <b>{{ $insp['waterlog'] ? __('listings.history.yes') : __('listings.history.no') }}</b></span>
                        <span>{{ __('listings.history.recall') }} <b>{{ $insp['recall'] ? ($insp['recallStatus'] ?: __('listings.history.yes')) : __('listings.history.no') }}</b></span>
                        @if ($insp['inspName'])<span>{{ __('listings.history.inspector') }} {{ $insp['inspName'] }}</span>@endif
                    </div>
                    @if ($insp['inners'])
                        <p class="mt-1.5 text-[11px] font-medium text-gray-500">{{ __('listings.history.inners') }}</p>
                        <div class="flex flex-wrap gap-1">
                            @foreach ($insp['inners'] as $sec)
                                @foreach ($sec['children'] as $c)
                                    <span class="badge {{ $c['ok'] ? 'badge-green' : 'badge-red' }}">{{ $c['title'] }} · {{ $c['status'] }}</span>
                                @endforeach
                            @endforeach
                        </div>
                    @endif
                    @if ($insp['outers'])
                        <p class="mt-1.5 text-[11px] font-medium text-gray-500">{{ __('listings.history.outers') }}</p>
                        <div class="flex flex-wrap gap-1">
                            @foreach ($insp['outers'] as $o)
                                <span class="badge badge-{{ $o['color'] }}">{{ $o['title'] }} · {{ $o['status'] }}</span>
                            @endforeach
                        </div>
                    @endif
                @else
                    <p class="text-gray-400">{{ __('listings.history.no_data') }}</p>
                @endif
            </div>

            {{-- ③ 엔카진단 --}}
            <div class="border-t border-gray-100 pt-2">
                <p class="mb-1 font-semibold text-gray-600">{{ __('listings.history.diagnosis') }}</p>
                @if ($diag)
                    <p class="mb-1 text-[11px] text-gray-500">{{ $diag['date'] }} · {{ $diag['center'] }}</p>
                    {{-- 판정문구(무사고 판정 등) — 헤드라인 --}}
                    @foreach ($diag['verdicts'] as $v)
                        <p class="mb-1 rounded border border-green-200 bg-green-50 px-2 py-1 text-[11px] font-medium text-green-800">✓ {{ $v }}</p>
                    @endforeach
                    {{-- 이상 부위만(정상은 숨김) --}}
                    @if ($diag['items'])
                        <div class="flex flex-wrap gap-1">
                            @foreach ($diag['items'] as $it)
                                <span class="badge badge-red">{{ $it['name'] }} · {{ $it['result'] }}</span>
                            @endforeach
                        </div>
                    @endif
                @else
                    <p class="text-gray-400">{{ __('listings.history.no_data') }}</p>
                @endif
            </div>
        @endif
    </div>
@endif

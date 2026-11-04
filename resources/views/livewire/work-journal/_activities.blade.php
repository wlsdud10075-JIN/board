{{-- 업무일지 board 활동 표 — 작성 화면(실시간/snapshot)과 결재 카드(snapshot)가 같이 쓴다. $acts = [{time, plate, what, state}] --}}
@if (empty($acts))
    <p class="text-xs text-gray-400">{{ __('journal.activity_empty') }}</p>
@else
    <div class="overflow-x-auto">
        <table class="tbl text-[13px]">
            <thead><tr><th class="w-16">{{ __('journal.col_time') }}</th><th class="w-28">{{ __('journal.col_plate') }}</th><th>{{ __('journal.col_what') }}</th><th class="w-32">{{ __('journal.col_state') }}</th></tr></thead>
            <tbody>
                @foreach ($acts as $a)
                    <tr>
                        <td class="whitespace-nowrap text-gray-600">{{ $a['time'] ?? '' }}</td>
                        <td class="whitespace-nowrap font-semibold text-gray-800">{{ $a['plate'] ?? '—' }}</td>
                        <td class="text-gray-700">{{ $a['what'] ?? '' }}</td>
                        <td>@if (! empty($a['state']))<span class="badge badge-purple">{{ $a['state'] }}</span>@endif</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

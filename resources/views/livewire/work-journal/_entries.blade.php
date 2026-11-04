{{-- 업무일지 표(읽기 전용) — 작성자 잠김 화면·결재 카드 공용. PC = 표, 폰 = 줄마다 카드(좁은 칸에 글자가 세로로 쌓이던 것, 2026-11-04 Jin). $rows = [{time, task, note}] --}}
<div class="hidden sm:block">
    <table class="tbl text-[13px]">
        <thead><tr><th class="w-16">{{ __('journal.col_time') }}</th><th>{{ __('journal.col_task') }}</th><th class="w-1/4">{{ __('journal.col_note') }}</th></tr></thead>
        <tbody>
            @foreach ($rows as $r)
                <tr><td class="whitespace-nowrap">{{ $r['time'] ?? '' }}</td><td class="whitespace-pre-line">{{ $r['task'] ?? '' }}</td><td>@if (($r['note'] ?? '') !== '')<span class="badge badge-amber whitespace-normal">{{ $r['note'] }}</span>@endif</td></tr>
            @endforeach
        </tbody>
    </table>
</div>
<div class="sm:hidden">
    @foreach ($rows as $r)
        <div class="border-b border-gray-100 py-2 text-[13px]">
            <div class="text-[12px] font-semibold text-gray-500">{{ $r['time'] ?? '' }}</div>
            <div class="whitespace-pre-line break-words text-gray-800">{{ $r['task'] ?? '' }}</div>
            @if (($r['note'] ?? '') !== '')<div class="mt-1"><span class="badge badge-amber whitespace-normal">{{ $r['note'] }}</span></div>@endif
        </div>
    @endforeach
</div>

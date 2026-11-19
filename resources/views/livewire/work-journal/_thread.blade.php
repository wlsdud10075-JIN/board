{{--
    업무일지 결재 이력(스레드) — $notes = WorkJournalNote 모음(오래된 것부터). 상신 의견(회색)·결재의견(보라)·반려(빨강).
    작성자 화면 = 자기 일지 줄만 / 결재 화면 = 사람 카드 안 + 기안 전체 줄(누구를 돌려보냈는지).
--}}
@foreach ($notes as $n)
    @php
        $tone = ['reject' => 'border-red-400 bg-red-50', 'submit' => 'border-gray-300 bg-gray-50', 'opinion' => 'border-violet-400 bg-violet-50'][$n->kind] ?? 'border-gray-300 bg-gray-50';
    @endphp
    <div class="mt-2 rounded-r-md border-l-4 {{ $tone }} px-3 py-1.5 text-[13px]" wire:key="wjn-{{ $n->id }}">
        <div class="text-[11px] text-gray-500">
            <b class="{{ $n->kind === 'reject' ? 'text-red-700' : 'text-gray-700' }}">{{ __('journal.note_kind.'.$n->kind) }}</b>
            · {{ $n->title }} {{ $n->user_name }} · {{ $n->created_at?->format('m-d H:i') }}
            @if ($n->kind === 'reject' && $n->work_journal_id === null)
                · {{ $n->targets ? __('journal.note_targets', ['names' => $n->targets]) : __('journal.note_targets_none') }}
            @endif
        </div>
        <div class="whitespace-pre-line text-gray-800">{{ $n->body }}</div>
    </div>
@endforeach

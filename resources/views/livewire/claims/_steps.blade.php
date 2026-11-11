{{-- 결재선 진행(현재 회차) — 칸마다 직급·이름·상태. $steps = Claim::lineSteps(), $current = 지금 차례 seq(없으면 null). --}}
@if ($steps->isNotEmpty())
    <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
        @foreach ($steps as $s)
            @php
                [$cls, $mark] = match (true) {
                    $s->status === 'approved' => ['badge-green', '✓'],
                    $s->status === 'rejected' => ['badge-red', '✕'],
                    (int) $s->seq === (int) $current => ['badge-purple', '⏳'],
                    default => ['badge-gray', '·'],
                };
            @endphp
            <span class="badge {{ $cls }} whitespace-nowrap" title="{{ $s->acted_at ? $s->acted_at->timezone(config('app.timezone'))->format('m-d H:i') : '' }}{{ $s->note ? ' · '.$s->note : '' }}">
                {{ $mark }} {{ $s->title }} {{ $s->approver?->name ?? '—' }}@if ($s->byProxy()) <span class="opacity-70">({{ $s->actor?->name }})</span>@endif
            </span>
            @if (! $loop->last)<span class="text-gray-300">→</span>@endif
        @endforeach
    </div>
@endif

{{--
    ERP 진행상태 뱃지 — `progress_status_cache` 값을 **그대로** 표시한다.
    board 가 단계를 추리거나 이름을 바꾸지 말 것(jin 2026-08-09) — 갈리면 "ERP엔 있는데 board엔 없다"가 된다.
    색은 '끝났나'만 구분한다. 단계별로 색을 발명하면 그것도 board 가 만든 의미가 된다.
--}}
@if ($status)
    <span class="badge {{ $status === '거래완료' ? 'badge-green' : 'badge-gray' }} whitespace-nowrap">{{ $status }}</span>
@else
    <span class="text-gray-300">—</span>
@endif

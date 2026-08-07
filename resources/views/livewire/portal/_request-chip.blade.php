{{--
    §11 요청·확인 신호 칩. $chip = $this->requestChip(차량번호, type) 반환값(없으면 null).
    상태는 **ERP 집계값 그대로** 쓴다 — board 가 재계산하거나 완료로 coerce 하지 않는다(§11-4 항목 4).
    묶음이 2대 이상일 때만 진행도(3/5)를 붙인다(입금요청은 1대=1묶음이라 의미 없음).
--}}
@if ($chip)
    @php
        $cls = match ($chip['status']) {
            'done' => 'badge-green',
            'cancelled' => 'badge-gray',
            default => 'badge-amber',
        };
        $label = match ($chip['status']) {
            'done' => __('portal.req_chip_done'),
            'cancelled' => __('portal.req_chip_cancelled'),
            default => __('portal.req_chip_open'),
        };
    @endphp
    <span class="badge {{ $cls }} whitespace-nowrap">{{ $label }}@if (($chip['total'] ?? 1) > 1) · {{ __('portal.req_chip_progress', ['done' => $chip['done'], 'total' => $chip['total']]) }}@endif</span>
@endif

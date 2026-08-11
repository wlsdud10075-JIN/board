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
    {{-- $chipLabel(선택) = 어느 신호인지. 매입은 계약금·잔금 칩이 같은 행에 나란히 붙어 구분이 없으면 못 읽는다. --}}
    <span class="badge {{ $cls }} whitespace-nowrap">@if (! empty($chipLabel)){{ $chipLabel }} @endif{{ $label }}@if (is_numeric($chip['amount'] ?? null)) · {{ number_format((float) $chip['amount']) }}@endif@if (($chip['total'] ?? 1) > 1) · {{ __('portal.req_chip_progress', ['done' => $chip['done'], 'total' => $chip['total']]) }}@endif</span>
@endif

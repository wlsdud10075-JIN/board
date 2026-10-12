{{--
    §11 요청·확인 신호 칩. $chip = $this->requestChip(차량번호, type) 반환값(없으면 null).
    상태는 **ERP 집계값 그대로** 쓴다 — board 가 재계산하거나 완료로 coerce 하지 않는다(§11-4 항목 4).
    묶음이 2대 이상일 때만 진행도(3/5)를 붙인다(입금요청은 1대=1묶음이라 의미 없음).
--}}
@if ($chip)
    @php
        // ⚠️ 모르는 상태를 「요청중」으로 뭉개지 말 것 — ERP 가 `rejected`(2026-10-12 거절 씬)를 먼저 배포하면
        //    거절이 노란 「요청중」으로 보여 영업이 기다리기만 한다. 아는 상태만 라벨을 붙이고 나머지는 원문.
        $cls = match ($chip['status']) {
            'open' => 'badge-amber',
            'done' => 'badge-green',
            'rejected' => 'badge-red',
            'cancelled' => 'badge-gray',
            default => 'badge-gray',
        };
        $label = match ($chip['status']) {
            'open' => __('portal.req_chip_open'),
            'done' => __('portal.req_chip_done'),
            'rejected' => __('portal.req_chip_rejected'),
            'cancelled' => __('portal.req_chip_cancelled'),
            default => $chip['status'],
        };
        $reason = trim((string) ($chip['reason'] ?? ''));
    @endphp
    {{-- $chipLabel(선택) = 어느 신호인지. 매입은 계약금·잔금 칩이 같은 행에 나란히 붙어 구분이 없으면 못 읽는다. --}}
    <span class="badge {{ $cls }} whitespace-nowrap">@if (! empty($chipLabel)){{ $chipLabel }} @endif{{ $label }}@if (is_numeric($chip['amount'] ?? null)) · {{ number_format((float) $chip['amount']) }}@endif@if (($chip['total'] ?? 1) > 1) · {{ __('portal.req_chip_progress', ['done' => $chip['done'], 'total' => $chip['total']]) }}@endif</span>
    {{-- 거절 사유는 ERP 사용자가 쓴 문장 그대로(한글, board 로캘 무관 — 전자서명 422 와 같은 구조). 줄을 바꿔 전부 보여준다 —
         칩 안에 잘라 넣으면 모바일(툴팁 없음)에서 사유를 끝까지 못 읽는다. --}}
    @if ($chip['status'] === 'rejected' && $reason !== '')
        <span class="block w-full text-left text-[11px] text-red-600">{{ __('portal.req_reject_reason', ['reason' => $reason]) }}</span>
    @endif
@endif

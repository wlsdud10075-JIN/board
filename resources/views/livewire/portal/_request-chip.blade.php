{{--
    §11 요청·확인 신호 칩. $chip = $this->requestChip(차량번호, type) 반환값(없으면 null).
    상태는 **ERP 집계값 그대로** 쓴다 — board 가 재계산하거나 완료로 coerce 하지 않는다(§11-4 항목 4).
    묶음이 2대 이상일 때만 진행도(3/5)를 붙인다(입금요청은 1대=1묶음이라 의미 없음).
    재전송(2026-10-27) — `resent_count>0` 이면 「재전송 N/L」(L = ERP `resend_limit`, 없으면 「N회」). 회차는 버튼이 아니라 여기에 —
    버튼은 「계약금 재전송」까지만 길어진다(폰 360px 에서 버튼 3개가 더 접히지 않게, Jin UX 조건).
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
    @if ($chip['status'] === 'rejected')
        {{-- 거절(2026-10-20 Jin A안) — 칩은 「계약금 거절」 한 줄만. 금액·사유는 칩을 누르면 펼친다(PC 는 호버 툴팁도).
             종전엔 사유 원문을 전체 폭 줄로 항상 그려 행이 두 줄 넘게 커졌다. 재요청하면 chipMap 이 최신 묶음을 고르므로 이 칩은 자연히 바뀐다. --}}
        @php
            $rejDetail = trim(($reason !== '' ? __('portal.req_reject_reason', ['reason' => $reason]) : '')
                .(is_numeric($chip['amount'] ?? null) ? ' · '.__('portal.req_reject_amount', ['amount' => number_format((float) $chip['amount'])]) : ''));
        @endphp
        <span x-data="{ o: false }" class="contents">
            <button type="button" @click="o = !o" @if ($rejDetail !== '') title="{{ $rejDetail }}" @endif
                class="badge {{ $cls }} whitespace-nowrap cursor-pointer" :aria-expanded="o">@if (! empty($chipLabel)){{ $chipLabel }} @endif{{ $label }}@if ($rejDetail !== '') <span class="ml-0.5 opacity-70">ⓘ</span>@endif</button>
            @if ($rejDetail !== '')
                <span x-show="o" x-cloak class="block w-full text-left text-[11px] text-red-600">{{ $rejDetail }}</span>
            @endif
        </span>
    @else
    <span class="badge {{ $cls }} whitespace-nowrap">@if (! empty($chipLabel)){{ $chipLabel }} @endif{{ $label }}@if (is_numeric($chip['amount'] ?? null)) · {{ number_format((float) $chip['amount']) }}@endif@if (($chip['total'] ?? 1) > 1) · {{ __('portal.req_chip_progress', ['done' => $chip['done'], 'total' => $chip['total']]) }}@endif@if (($chip['resent_count'] ?? 0) > 0) · {{ ($chip['resend_limit'] ?? null) !== null ? __('portal.req_chip_resent', ['n' => $chip['resent_count'], 'limit' => $chip['resend_limit']]) : __('portal.req_chip_resent_nolimit', ['n' => $chip['resent_count']]) }}@endif</span>
    @endif
@endif

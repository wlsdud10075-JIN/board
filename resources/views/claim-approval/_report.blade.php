{{--
    클레임 보고서 — 양식 `CLAIM Krasniqi Arif.docx` 의 칸·순서를 HTML 로(파일 생성 없음, Jin §16 E).
    담당자 드로어(/claims)와 대표 링크 화면(a/claim)이 같이 쓴다. 값은 전부 Claim 스냅샷·담당자 입력 — 여기서 계산하지 않는다(환급률만 표시용).
    $claim — Claim(files·salesman·requester·approver 로드) / $media — ['board'=>[], 'videos'=>[], 'photos'=>[]]
    CSS = `.rep` 블록(app.css + 대표 화면 inline). 인쇄 = 브라우저 인쇄.
--}}
@php
    $f = fn ($v) => ($v === null || $v === '') ? '—' : $v;
    $kinds = $claim->files->groupBy('kind');
    $hasInspection = ($media['board'] ?? []) !== [] || ($media['videos'] ?? []) !== [] || ($media['photos'] ?? []) !== [];
    $refund = $claim->compensation_type === 'refund' || $claim->bank_iban || $claim->bank_name;
@endphp
<div class="rep">
    <div class="rep-title">CLAIM REPORT</div>

    <table class="rep-t">
        <tr><th>BUYER NAME</th><td>{{ $f($claim->buyer_contact_name) }}</td><th>COMPANY</th><td>{{ $f($claim->buyer_name) }}</td></tr>
        <tr><th>MEMBER SINCE</th><td>{{ $f($claim->member_since) }}</td><th>SALES AGENT</th><td>{{ $f($claim->salesman?->name) }}</td></tr>
        <tr><th>TOTAL PURCHASE HISTORY</th><td>{{ $claim->total_purchase_count === null ? '—' : $claim->total_purchase_count.' '.__('claims.unit_cars') }}</td>
            <th>1 YEAR PURCHASE HISTORY</th><td>{{ $claim->year_purchase_count === null ? '—' : $claim->year_purchase_count.' '.__('claims.unit_cars') }}</td></tr>
        <tr><th>CLAIM HISTORY (1 YEAR)</th><td>{{ $claim->yearClaimCount() }} {{ __('claims.unit_cases') }}</td>
            <th>BUYER PRIORITY</th><td>{{ $claim->creditDisplay() }}</td></tr>
    </table>

    <div class="rep-h">CAR DETAILS</div>
    <table class="rep-t">
        <tr><th>MODEL</th><td>{{ $f(trim((string) $claim->brand.' '.(string) $claim->model_type)) }}</td><th>PLATE</th><td>{{ \App\Support\Plate::show($claim->vehicle_number) }}</td></tr>
        <tr><th>VIN</th><td>{{ $f($claim->vin) }}</td><th>MILEAGE ({{ __('claims.received_km_short') }})</th><td>{{ number_format($claim->received_km) }} km</td></tr>
        <tr><th>FOB PRICE</th><td>{{ $claim->salePriceDisplay() }}</td><th>PROFIT ({{ __('claims.margin_rate') }})</th><td>{{ $claim->margin_rate === null ? '—' : rtrim(rtrim(number_format((float) $claim->margin_rate, 2), '0'), '.').'%' }}</td></tr>
        <tr><th>{{ __('claims.arrived_at') }}</th><td>{{ $claim->arrived_at->format('Y-m-d') }} <span class="rep-muted">(ETA {{ $claim->eta_date?->format('Y-m-d') ?? '—' }})</span></td>
            <th>{{ __('claims.days_after_arrival') }}</th><td>D+{{ $claim->daysAfterArrival() }}@if ($claim->isOverdue()) <span class="rep-bad">{{ __('claims.overdue_chip') }}</span>@endif</td></tr>
        @if ($claim->isOverdue())
            <tr><th>{{ __('claims.overdue_reason') }}</th><td colspan="3">{{ $f($claim->overdue_reason) }}</td></tr>
        @endif
    </table>

    <div class="rep-h">INSPECTION REPORT RESULT</div>
    <table class="rep-t">
        <tr><th>{{ __('claims.inspection_media') }}</th><td colspan="3">{{ $hasInspection ? __('claims.inspection_media_yes', ['videos' => count($media['videos'] ?? []) + collect($media['board'] ?? [])->where('video', true)->count(), 'photos' => count($media['photos'] ?? []) + collect($media['board'] ?? [])->where('video', false)->count()]) : __('claims.inspection_media_none') }}</td></tr>
        <tr><th>{{ __('claims.inspection_result') }}</th><td colspan="3" class="rep-pre">{{ $f($claim->inspection_result) }}</td></tr>
    </table>

    <div class="rep-h">NOTES</div>
    @if ($claim->purchase_history)
        <table class="rep-t rep-list">
            <tr><th>{{ __('claims.col_month') }}</th><th>{{ __('claims.col_model') }}</th><th>VIN</th><th>{{ __('claims.col_amount') }}</th></tr>
            @foreach ($claim->purchase_history as $h)
                <tr><td>{{ $h['month'] ?? '—' }}</td><td>{{ $f($h['model'] ?? null) }}@if (! empty($h['vehicle_number'])) <span class="rep-muted">{{ \App\Support\Plate::show($h['vehicle_number']) }}</span>@endif</td><td>{{ $f($h['vin'] ?? null) }}</td>
                    <td class="rep-num">{{ is_numeric($h['amount'] ?? null) ? trim(($h['currency'] ?? '').' '.number_format((float) $h['amount'])) : '—' }}</td></tr>
            @endforeach
        </table>
    @else
        <p class="rep-muted rep-p">{{ __('claims.history_empty') }}</p>
    @endif
    @if ($claim->notes)<p class="rep-p rep-pre">{{ $claim->notes }}</p>@endif

    <div class="rep-h">CLAIM</div>
    <table class="rep-t">
        <tr><th>CLAIM REASON</th><td colspan="3" class="rep-pre">{{ $claim->content }}</td></tr>
        <tr><th>EVIDENCE</th><td colspan="3">
            @foreach (\App\Models\ClaimFile::KINDS as $k)
                <span class="rep-chk">{{ ($kinds[$k] ?? collect())->isNotEmpty() ? '☑' : '☐' }} {{ __('claims.kind.'.$k) }}@if (($n = ($kinds[$k] ?? collect())->count()) > 0) ({{ $n }})@endif</span>
            @endforeach
        </td></tr>
        <tr><th>NOTES</th><td colspan="3" class="rep-pre">{{ $f($claim->review_notes) }}</td></tr>
        <tr><th>CLAIM AMOUNT</th><td>{{ $claim->claimAmountDisplay() }}</td>
            <th>REFUNDABLE</th><td>{{ $claim->refundable_amount === null ? '—' : trim(($claim->claim_currency ?: $claim->sale_currency ?: '').' '.number_format((float) $claim->refundable_amount, fmod((float) $claim->refundable_amount, 1.0) === 0.0 ? 0 : 2)) }}@if (($r = $claim->refundRate()) !== null) <span class="rep-muted">({{ $r }}%)</span>@endif</td></tr>
        {{-- CS 제시금액(2026-11-11 Jin) — CLAIM AMOUNT·REFUNDABLE 바로 아래. 담당자가 사실확인 후 제안한 금액. --}}
        <tr><th>CS PROPOSED AMOUNT</th><td colspan="3">{{ $claim->amountDisplay('cs_proposed_amount') }}@if (($r = $claim->csRate()) !== null) <span class="rep-muted">({{ $r }}%)</span>@endif</td></tr>
        {{-- 확정 금액 — 최종 결재권자가 승인하며 정한 하나(ERP 로 가는 금액). 결재 전 = 「—」. --}}
        <tr><th>FINAL AMOUNT</th><td colspan="3"><b>{{ $claim->amountDisplay('confirmed_amount') }}</b>@if ($claim->confirmed_amount === null && $claim->isOpen()) <span class="rep-muted">{{ __('claims.confirmed_pending') }}</span>@endif</td></tr>
    </table>

    <div class="rep-h">RESULT</div>
    <table class="rep-t">
        <tr><th>RESULT</th><td colspan="3" class="rep-pre">{{ $f($claim->result) }}</td></tr>
        <tr><th>REASON</th><td colspan="3" class="rep-pre">{{ $f($claim->result_reason) }}</td></tr>
        <tr><th>COMPENSATION TYPE</th><td colspan="3">
            <span class="rep-chk">{{ $claim->compensation_type === 'refund' ? '☑' : '☐' }} REFUND</span>
            <span class="rep-chk">{{ $claim->compensation_type === 'credit' ? '☑' : '☐' }} CREDIT (REFUND DEPOSIT)</span>
        </td></tr>
        <tr><th>CLAIM MANAGER</th><td>{{ $f($claim->requester?->name) }}</td><th>SALES MANAGER</th><td>{{ $f($claim->salesman?->name) }}</td></tr>
        {{-- 결재선(현재 회차) 단계별 — 부장 → 상무 → 대표 중 지정한 칸. 마지막 칸이 최종(대표면 REVIEWED BY CEO). --}}
        @forelse ($claim->lineSteps() as $s)
            <tr><th>{{ $loop->last && $s->title === '대표' ? 'REVIEWED BY CEO' : 'REVIEWED BY '.$s->title }}</th><td colspan="3">
                {{ $s->approver?->name ?? '—' }}
                @if ($s->status === 'pending')
                    <span class="rep-muted">· {{ (int) $s->seq === (int) $claim->current_step ? __('claims.step_turn') : __('claims.step_waiting') }}</span>
                @else
                    · {{ $s->status === 'approved' ? __('claims.status.approved') : __('claims.status.rejected') }} · {{ $s->acted_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') }}
                    @if ($s->byProxy())<span class="rep-muted">({{ __('claims.proxy_by', ['name' => $s->actor?->name]) }})</span>@endif
                    @if ($s->note)<div class="rep-pre">{{ $s->note }}</div>@endif
                @endif
            </td></tr>
        @empty
            <tr><th>REVIEWED BY</th><td colspan="3">—</td></tr>
        @endforelse
    </table>

    @if ($refund)
        <div class="rep-title rep-title-2">REFUND REQUEST</div>
        <table class="rep-t">
            <tr><th>REASON</th><td colspan="3" class="rep-pre">{{ $f($claim->result_reason ?: $claim->result) }}</td></tr>
            <tr><th>CAR</th><td>{{ $f(trim((string) $claim->brand.' '.(string) $claim->model_type)) }} · {{ \App\Support\Plate::show($claim->vehicle_number) }}</td><th>VIN</th><td>{{ $f($claim->vin) }}</td></tr>
            <tr><th>ORIGINAL PAYMENT</th><td>{{ $claim->salePriceDisplay() }}</td><th>ORIGINAL PAYMENT DATE</th><td>— <span class="rep-muted">{{ __('claims.payment_date_hint') }}</span></td></tr>
            <tr><th>REFUND AMOUNT</th><td>{{ $claim->refundable_amount === null ? '—' : trim(($claim->claim_currency ?: $claim->sale_currency ?: '').' '.number_format((float) $claim->refundable_amount, 2)) }}</td>
                <th>REQUEST DATE</th><td>{{ $claim->requested_at?->timezone(config('app.timezone'))->format('Y-m-d') ?? '—' }}</td></tr>
            <tr><th>REQUESTED BY</th><td colspan="3">{{ $f($claim->requester?->name) }}</td></tr>
        </table>
        <div class="rep-h">RECIPIENT'S DETAILS</div>
        <table class="rep-t">
            <tr><th>BANK</th><td>{{ $f($claim->bank_name) }}</td><th>SWIFT</th><td>{{ $f($claim->bank_swift) }}</td></tr>
            <tr><th>IBAN / ACCOUNT</th><td>{{ $f($claim->bank_iban) }}</td><th>PHONE</th><td>{{ $f($claim->bank_phone) }}</td></tr>
            <tr><th>ADDRESS</th><td colspan="3">{{ $f($claim->bank_address) }}</td></tr>
        </table>
    @endif
</div>

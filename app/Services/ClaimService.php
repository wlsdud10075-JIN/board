<?php

namespace App\Services;

use App\Models\BoardAuditLog;
use App\Models\Claim;
use App\Models\ClaimFile;
use App\Models\PurchaseListing;
use App\Models\Scopes\SalesmanScope;
use App\Models\User;
use App\Support\AlimtalkTemplates;
use App\Support\Plate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 클레임 흐름의 단일 경로(2026-11-10, 정본 = meetings/plan-claims-2026-11.md) — 접수 · ERP 스냅샷 · 보고서 저장 · 대표 승인 요청/재전송 · 결정 · 종결.
 * 모든 행위 = `board_audit_logs` 1행(Jin 「board 모든 건 로그」) — purchase_listing_id 는 board 에 있는 차면 그 id, 아니면 null, field = claim_*.
 * 알림 = 알림톡 2종(승인요청 → 대표 / 결재 결과 → 담당자+영업) + 웹푸시 + 상단 종(종은 board DB 를 직접 센다, notify.bell).
 */
class ClaimService
{
    public function __construct(private CarErpReadService $erp) {}

    // ── 접수 ──

    /**
     * 영업 접수(포털 판매내역 행). $row = ERP 행 스냅샷(vehicle_id·vehicle_number·vin·brand·model_type·buyer·buyer_id·sale_price·currency·sale_date·eta_date).
     *
     * @param  array<string, list<UploadedFile>>  $files  kind => files (계기판·손상·INVOICE 각 1건 이상은 호출측 검증)
     */
    public function intake(User $actor, User $salesman, array $row, array $input, array $files): Claim
    {
        $listing = PurchaseListing::withoutGlobalScope(SalesmanScope::class)
            ->where('vehicle_number', (string) ($row['vehicle_number'] ?? ''))
            ->orderByDesc('id')->first();

        $claim = Claim::create([
            'status' => Claim::RECEIVED,
            'car_erp_vehicle_id' => (int) ($row['vehicle_id'] ?? 0),
            'purchase_listing_id' => $listing?->id,
            'vehicle_number' => (string) ($row['vehicle_number'] ?? ''),
            'vin' => $row['vin'] ?? null,
            'brand' => $row['brand'] ?? null,
            'model_type' => $row['model_type'] ?? null,
            'car_erp_buyer_id' => ($row['buyer_id'] ?? null) ? (int) $row['buyer_id'] : null,
            'buyer_name' => $row['buyer'] ?? null,
            'buyer_contact_name' => $input['buyer_contact_name'] ?? null,
            'sale_price' => is_numeric($row['sale_price'] ?? null) ? $row['sale_price'] : null,
            'sale_currency' => $row['currency'] ?? null,
            'sale_date' => self::date($row['sale_date'] ?? null),
            'eta_date' => self::date($row['eta_date'] ?? null),
            'salesman_user_id' => $salesman->id,
            'created_by_user_id' => $actor->id,
            'arrived_at' => $input['arrived_at'],
            'received_km' => (int) $input['received_km'],
            'content' => (string) $input['content'],
            'claim_currency' => $row['currency'] ?? null,
        ]);

        foreach ($files as $kind => $list) {
            foreach ($list as $f) {
                $this->storeFile($claim, $kind, $f, $actor->id);
            }
        }

        $this->snapshotErp($claim, $salesman);

        $this->audit($claim, $actor->id, 'claim_intake', null, $this->summary($claim).' · '.__('claims.audit_intake', [
            'km' => number_format($claim->received_km), 'arrived' => $claim->arrived_at->format('Y-m-d'), 'files' => $claim->files()->count(),
        ]));

        // 담당자 인지 — 웹푸시(종은 board DB 를 직접 센다).
        foreach (User::whereIn('id', User::claimHandlerIds())->where('is_active', true)->get() as $h) {
            $this->push($h, __('claims.push_new_title'), Plate::show($claim->vehicle_number).' · '.($claim->buyer_name ?: '—'), route('claims'), 'claim-new-'.$claim->id);
        }

        return $claim;
    }

    public function storeFile(Claim $claim, string $kind, UploadedFile $f, ?int $userId): ClaimFile
    {
        $kind = in_array($kind, ClaimFile::KINDS, true) ? $kind : ClaimFile::KIND_OTHER;
        $path = $f->store(config('board.claims_prefix').'/'.$claim->id.'/'.$kind, config('board.photo_disk'));

        return $claim->files()->create([
            'kind' => $kind,
            's3_path' => $path,
            'original_name' => $f->getClientOriginalName(),
            'mime' => $f->getMimeType(),
            'size' => $f->getSize(),
            'uploaded_by_user_id' => $userId,
        ]);
    }

    /**
     * ERP 스냅샷 — 접수 영업 명의로 by-buyer(총 구매대수)·/sales(최근 12개월 표·첫 판매 연도)·/settlements(그 차 margin_rate).
     * 실패한 조각은 그대로 둔다(null = 「못 봤다」, 0 아님). 담당자 [ERP 다시 불러오기] 가 같은 함수를 부른다.
     */
    public function snapshotErp(Claim $claim, ?User $as = null): array
    {
        $as ??= $claim->salesman;
        $email = $as->car_erp_salesman_email ?: $as->email;
        $got = [];

        $bb = $this->erp->byBuyer($email);
        if ($bb['ok'] ?? false) {
            foreach ((array) data_get($bb['data'], 'data', []) as $b) {
                $hit = $claim->car_erp_buyer_id ? (int) data_get($b, 'buyer_id') === (int) $claim->car_erp_buyer_id : (string) data_get($b, 'buyer') === (string) $claim->buyer_name;
                if ($hit) {
                    $claim->total_purchase_count = (int) data_get($b, 'vehicle_count', 0);
                    $got[] = 'by_buyer';
                    break;
                }
            }
        }

        $sales = $this->erp->sales($email);
        if ($sales['ok'] ?? false) {
            $all = collect((array) data_get($sales['data'], 'data', []));
            // 차량 칸은 ERP 행이 권위 — 접수 드로어가 받은 행은 브라우저를 거친 값이라(public 액션) 같은 vehicle_id 의 ERP 행으로 덮어쓴다.
            if ($claim->car_erp_vehicle_id && ($v = $all->first(fn ($r) => (int) data_get($r, 'vehicle_id') === (int) $claim->car_erp_vehicle_id))) {
                $claim->vehicle_number = (string) (data_get($v, 'vehicle_number') ?: $claim->vehicle_number);
                $claim->vin = data_get($v, 'vin') ?: $claim->vin;
                $claim->brand = data_get($v, 'brand') ?: $claim->brand;
                $claim->model_type = data_get($v, 'model_type') ?: $claim->model_type;
                $claim->buyer_name = data_get($v, 'buyer') ?: $claim->buyer_name;
                $claim->sale_price = is_numeric(data_get($v, 'sale_price')) ? data_get($v, 'sale_price') : $claim->sale_price;
                $claim->sale_currency = data_get($v, 'currency') ?: $claim->sale_currency;
                $claim->sale_date = self::date(data_get($v, 'sale_date')) ?? $claim->sale_date;
                $claim->eta_date = self::date(data_get($v, 'eta_date')) ?? $claim->eta_date;
                $got[] = 'vehicle';
            }
            $rows = $all
                ->filter(fn ($r) => (string) data_get($r, 'buyer') === (string) $claim->buyer_name && $claim->buyer_name);
            $since = Carbon::today()->subMonths(12);
            $recent = $rows->filter(fn ($r) => ($d = self::date(data_get($r, 'sale_date'))) && $d->gte($since))
                ->sortByDesc(fn ($r) => (string) data_get($r, 'sale_date'))->values();
            $claim->year_purchase_count = $recent->count();
            $claim->purchase_history = $recent->map(fn ($r) => [
                'month' => substr((string) data_get($r, 'sale_date'), 0, 7),
                'model' => trim((string) data_get($r, 'brand').' '.(string) data_get($r, 'model_type')),
                'vin' => (string) data_get($r, 'vin'),
                'vehicle_number' => (string) data_get($r, 'vehicle_number'),
                'amount' => data_get($r, 'sale_price'),
                'currency' => (string) data_get($r, 'currency'),
            ])->all();
            $first = $rows->map(fn ($r) => self::date(data_get($r, 'sale_date')))->filter()->min();
            $claim->member_since = $first?->year;
            $got[] = 'sales';
        }

        // BUYER PRIORITY = ERP 바이어 신용도(2026-11-11 Jin). 신용도 전용 `GET /buyers/{id}/credit`(연락처 없음·영업 매칭 없음) — 없으면(ERP 미배포·404) 그대로 null = 「—」.
        if ($claim->car_erp_buyer_id) {
            $b = $this->erp->buyerCredit($email, (int) $claim->car_erp_buyer_id);
            $grade = ($b['ok'] ?? false) ? data_get($b['data'], 'data.credit_grade', data_get($b['data'], 'credit_grade')) : null;
            if (is_string($grade) && $grade !== '') {
                $score = data_get($b['data'], 'data.credit_score', data_get($b['data'], 'credit_score'));
                $claim->buyer_credit_grade = mb_substr($grade, 0, 2);
                $claim->buyer_credit_score = is_numeric($score) ? (int) $score : null;
                $got[] = 'credit';
            }
        }

        $st = $this->erp->settlements($email);
        if ($st['ok'] ?? false) {
            foreach ((array) data_get($st['data'], 'data', []) as $r) {
                $byId = (int) data_get($r, 'vehicle_id') === (int) $claim->car_erp_vehicle_id && $claim->car_erp_vehicle_id;
                $byNo = (string) data_get($r, 'vehicle_number') === (string) $claim->vehicle_number;
                if (($byId || $byNo) && is_numeric(data_get($r, 'margin_rate'))) {
                    $claim->margin_rate = (float) data_get($r, 'margin_rate');
                    $got[] = 'settlements';
                    break;
                }
            }
        }

        $claim->erp_snapshot_at = now();
        $claim->saveQuietly();

        return $got;
    }

    // ── 담당자 ──

    /** 보고서 칸 저장 — 바뀐 칸만 감사(민감 칸은 ***). 접수 직후면 reviewing 으로 올린다. */
    public function saveReport(Claim $claim, User $handler, array $fields): void
    {
        $original = $claim->getOriginal();
        foreach (Claim::EDITABLE as $f) {
            if (array_key_exists($f, $fields)) {
                $claim->$f = $fields[$f] === '' ? null : $fields[$f];
            }
        }
        if ($claim->status === Claim::RECEIVED) {
            $claim->status = Claim::REVIEWING;
        }
        $claim->save();

        foreach (Claim::EDITABLE as $f) {
            $old = $original[$f] ?? null;
            $new = $claim->getAttribute($f);
            if ($old instanceof Carbon) {
                $old = $old->format('Y-m-d');
            }
            if ($new instanceof Carbon) {
                $new = $new->format('Y-m-d');
            }
            if (! BoardAudit::isRealChange($old, $new)) {
                continue;
            }
            $masked = in_array($f, Claim::MASKED, true);
            $this->audit($claim, $handler->id, 'claim_'.$f, $masked ? ($old === null ? null : '***') : self::str($old), $masked ? ($new === null ? null : '***') : self::str($new));
        }
        if (($original['status'] ?? null) !== $claim->status) {
            $this->audit($claim, $handler->id, 'claim_status', $original['status'] ?? null, $claim->status);
        }
    }

    /**
     * [결재 요청] — 결재선(부장 → 상무 → 대표 중 지정한 칸) 을 이 회차로 만들고 1단계 결재자에게 알림톡·푸시(2026-11-11 Jin 결재트리).
     * 대표는 필수 아님 — 지정한 마지막 칸이 최종 결재권자(상무에서 끝나면 상무). 회차 +1 = 반려 뒤 재요청·합의액 변경 재승인 모두 1단계부터.
     *
     * @param  array<string, int|string|null>  $line  직급 => user id(빈 값 = 그 직급 건너뜀)
     *
     * @throws \DomainException not_requestable · overdue_reason_required · no_line · bad_line
     */
    public function requestApproval(Claim $claim, User $handler, array $line): Claim
    {
        if (! $claim->canRequestApproval()) {
            throw new \DomainException('not_requestable');
        }
        if ($claim->isOverdue() && trim((string) $claim->overdue_reason) === '') {
            throw new \DomainException('overdue_reason_required');
        }
        $cand = Claim::lineCandidates();
        $picked = [];
        foreach (User::TOP_TITLES as $title) {
            $id = (int) ($line[$title] ?? 0);
            if ($id === 0) {
                continue;
            }
            if (! $cand[$title] || $cand[$title]->id !== $id) {
                throw new \DomainException('bad_line');   // 그 직급 사람이 아니다(화면 조작·직급 변경)
            }
            $picked[$title] = $cand[$title];
        }
        if ($picked === []) {
            throw new \DomainException('no_line');
        }

        $from = $claim->status;
        $first = null;
        DB::transaction(function () use ($claim, $handler, $picked, &$first) {
            $rev = $claim->revision + 1;
            $seq = 0;
            foreach ($picked as $title => $u) {
                $claim->steps()->create(['revision' => $rev, 'seq' => ++$seq, 'title' => $title, 'approver_user_id' => $u->id, 'status' => 'pending']);
            }
            $first = reset($picked);
            $claim->forceFill([
                'status' => Claim::PENDING_CEO,
                'revision' => $rev,
                'current_step' => 1,
                'approver_user_id' => $first->id,
                'requested_at' => now(),
                'requested_by_user_id' => $handler->id,
                'decided_at' => null, 'decision' => null, 'decision_reason' => null, 'decision_ip' => null,
            ])->save();
        });
        $claim->unsetRelation('steps');

        $this->audit($claim, $handler->id, 'claim_status', $from, Claim::PENDING_CEO);
        $this->audit($claim, $handler->id, 'claim_request', null, __('claims.audit_request', [
            'rev' => $claim->revision, 'line' => collect($picked)->map(fn ($u, $t) => $t.' '.$u->name)->implode(' → '), 'amount' => $claim->claimAmountDisplay(),
        ]));

        $this->notifyStep($claim, $first);

        return $claim;
    }

    /** [다시 보내기] — 지금 차례 결재자에게 같은 회차 링크를 다시(10분 연타 방지). */
    public function resend(Claim $claim, User $handler): void
    {
        if (! $claim->canResend()) {
            throw new \DomainException('resend_cooldown');
        }
        $u = $claim->currentStep()?->approver;
        if ($u === null) {
            throw new \DomainException('not_requestable');
        }
        $this->audit($claim, $handler->id, 'claim_request', null, __('claims.audit_resend', ['rev' => $claim->revision, 'name' => $u->name]));
        $this->notifyStep($claim, $u);
    }

    /** 차례가 온 결재자 1명에게 — 알림톡(버튼 = 그 사람 서명 링크) + 웹푸시. 단계마다 같은 템플릿(ERP 월정산과 같은 결). */
    private function notifyStep(Claim $claim, User $approver): void
    {
        $link = $claim->approvalLinkFor($approver);
        if (trim((string) $approver->phone) !== '') {
            BizmAlimtalkService::active()->send('board_claim_approval_request', $approver->phone, [
                '차량번호' => $claim->vehicle_number,
                '바이어' => $claim->buyer_name ?: '—',
                '청구액' => $claim->claimAmountDisplay(),
                '담당자' => $claim->requester?->name ?? '—',
            ], ['user_id' => $approver->id], [
                ['name' => AlimtalkTemplates::TEMPLATES['board_claim_approval_request']['button'][0]['name'], 'url' => $link],
            ]);
        }
        $this->push($approver, __('claims.push_request_title'), Plate::show($claim->vehicle_number).' · '.($claim->buyer_name ?: '—').' · '.$claim->claimAmountDisplay(), $link, 'claim-req-'.$claim->id);
        $claim->forceFill(['request_notified_at' => now()])->saveQuietly();
    }

    // ── 결재 ──

    /**
     * 승인/반려(서명 링크 POST 또는 super). 트랜잭션 + lockForUpdate 재조회(더블클릭 2회 처리 방지 — 승인·반려 둘 다) + canDecide(상태·차례·회차).
     * 승인 = 다음 칸으로(마지막 칸이면 최종 승인) · super 승인 = 남은 칸과 무관하게 즉시 최종(ERP 월정산 대행과 같은 결) · 반려 = 그 건 rejected(사유 필수).
     * 알림 = 다음 결재자에게 요청 / 최종 승인·반려 때만 담당자+영업에게 결과(중간 진행 알림 없음).
     *
     * @throws \DomainException reason_required · not_decidable
     */
    public function decide(Claim $claim, User $actor, int $revision, string $action, string $reason, ?string $ip): Claim
    {
        $reason = trim($reason);
        if ($action === 'reject' && $reason === '') {
            throw new \DomainException('reason_required');
        }

        $next = null;
        $fresh = DB::transaction(function () use ($claim, $actor, $revision, $action, $reason, $ip, &$next) {
            $fresh = Claim::query()->lockForUpdate()->findOrFail($claim->id);
            if (! $fresh->canDecide($actor, $revision)) {
                throw new \DomainException('not_decidable');
            }
            $step = $fresh->currentStep();
            $proxy = (int) $step->approver_user_id !== (int) $actor->id;   // super 대행
            $note = trim($reason.($proxy ? ' '.__('claims.proxy_note') : '')) ?: null;
            $step->update(['status' => $action === 'approve' ? 'approved' : 'rejected', 'acted_at' => now(), 'acted_by_user_id' => $actor->id, 'note' => $note]);

            $next = $action === 'approve' && ! $proxy ? $fresh->lineSteps()->first(fn ($s) => $s->seq > $step->seq && $s->status === 'pending') : null;
            $this->audit($fresh, $actor->id, 'claim_decision', null, __('claims.audit_step', [
                'rev' => $fresh->revision, 'title' => $step->title, 'result' => $action === 'approve' ? __('claims.status.approved') : __('claims.status.rejected'),
                'reason' => $reason !== '' ? $reason : '—', 'proxy' => $proxy ? ' '.__('claims.proxy_note') : '',
            ]), $ip);

            if ($next) {
                $fresh->forceFill(['current_step' => $next->seq, 'approver_user_id' => $next->approver_user_id])->save();

                return $fresh;
            }
            $to = $action === 'approve' ? Claim::APPROVED : Claim::REJECTED;
            $fresh->forceFill([
                'status' => $to, 'current_step' => null, 'approver_user_id' => $actor->id,
                'decided_at' => now(), 'decision' => $to, 'decision_reason' => $reason !== '' ? $reason : null, 'decision_ip' => $ip,
            ])->save();
            $this->audit($fresh, $actor->id, 'claim_status', Claim::PENDING_CEO, $to, $ip);

            return $fresh;
        });

        $fresh->unsetRelation('steps');
        if ($next) {
            $this->notifyStep($fresh, $next->approver);
        } else {
            $this->notifyDecision($fresh);
        }

        return $fresh;
    }

    private function notifyDecision(Claim $claim): void
    {
        $approved = $claim->status === Claim::APPROVED;
        $recipients = collect([$claim->requester, $claim->salesman])->filter()->unique('id');
        $vars = [
            '차량번호' => $claim->vehicle_number,
            '바이어' => $claim->buyer_name ?: '—',
            '결과' => $approved ? '승인' : '반려',
            '사유' => trim((string) $claim->decision_reason) !== '' ? $claim->decision_reason : '—',
        ];
        $title = $approved ? __('claims.push_approved_title') : __('claims.push_rejected_title');
        $body = Plate::show($claim->vehicle_number).' · '.($claim->buyer_name ?: '—').($claim->decision_reason ? ' · '.$claim->decision_reason : '');
        foreach ($recipients as $u) {
            if (trim((string) $u->phone) !== '') {
                BizmAlimtalkService::active()->send('board_claim_decision', $u->phone, $vars, ['user_id' => $u->id]);
            }
            $this->push($u, $title, $body, $u->isClaimHandler() ? route('claims') : route('portal'), 'claim-dec-'.$claim->id.'-'.$claim->revision);
        }
    }

    // ── 종결 ──

    public function close(Claim $claim, User $handler): void
    {
        $from = $claim->status;
        $claim->forceFill(['status' => Claim::CLOSED, 'closed_at' => now(), 'closed_by_user_id' => $handler->id])->save();
        $this->audit($claim, $handler->id, 'claim_status', $from, Claim::CLOSED);
    }

    // ── 공통 ──

    public function audit(Claim $claim, ?int $userId, string $field, ?string $old, ?string $new, ?string $ip = null): void
    {
        BoardAuditLog::create([
            'user_id' => $userId,
            'purchase_listing_id' => $claim->purchase_listing_id,
            'action' => 'claim',
            'field' => $field,
            'old_value' => $old,
            'new_value' => $new === null ? null : '#'.$claim->id.' '.$new,
            'ip_address' => $ip ?? BoardAuditLog::currentIp(),
        ]);
    }

    private function summary(Claim $claim): string
    {
        return $claim->vehicle_number.' · '.($claim->buyer_name ?: '—');
    }

    private function push(User $u, string $title, string $body, string $url, string $tag): void
    {
        try {
            app(WebPushService::class)->sendToUser($u, ['title' => $title, 'body' => $body, 'url' => $url, 'tag' => $tag, 'sticky' => true]);
        } catch (\Throwable) {
            // 알림 실패가 업무 저장을 깨면 안 된다.
        }
    }

    private static function date(mixed $v): ?Carbon
    {
        if (! $v) {
            return null;
        }
        try {
            return Carbon::parse((string) $v)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function str(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }

        return (string) $v;
    }
}

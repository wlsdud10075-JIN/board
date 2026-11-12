<?php

namespace App\Services;

use App\Models\BoardAuditLog;
use App\Models\PurchaseListing;
use App\Models\Setting;
use App\Models\TransportCompany;
use App\Models\TransportCompanyPhone;
use App\Models\TransportRequest;
use App\Models\TransportRequestFile;
use App\Models\TransportRequestRecipient;
use App\Models\User;
use App\Support\Instance;
use App\Support\UploadGuard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 탁송 배차 요청 — 생성·예약 발송·업체 결정·영업 알림·매입잔금·ERP 보관 위치(2026-11-12 Jin, 정본 meetings/plan-transport-2026-11.md).
 *
 * 흐름: 탁송 탭 [요청] → scheduled(send_at) → `board:transport-send`(매분) 또는 즉시 → 수신자 스냅샷 + 알림톡(버튼 = 서명 링크 5일) → sent
 *      → 업체 링크 [배차완료]/[배차거절](선착순, lockForUpdate) → 영업·관리에게 종·웹푸시·결과 알림톡 → [매입잔금 요청](§11, note 에 배차정보).
 * 감사 = board_audit_logs action `transport_*`(전화 `***`, purchase_listing_id 에 묶인다 — Jin 「board 모든 행위는 감사 행」).
 * 알림톡이 skipped(tmplId 없음·토글 off)여도 요청은 sent 로 올린다 — 승인 전 운영·로컬에서 씬이 멈추면 안 된다.
 */
class TransportService
{
    public function __construct(
        private BizmAlimtalkService $alimtalk,
        private WebPushService $push,
        private CarErpReadService $erp,
    ) {}

    // ─────────── 권한 ───────────

    /** 요청·취소·매입잔금 = 그 매물에 쓸 수 있는 사람: super · 등록 영업 · 공유받은 관리. 업무관리자·최고관리자는 조회 전용(포털 proxyWriteBlocked 미러). */
    public static function canWrite(User $u, PurchaseListing $l): bool
    {
        if ($u->isSuper()) {
            return true;
        }
        if ((int) $l->created_by_user_id === (int) $u->id) {
            return true;
        }
        if ($u->isManager() && ! $u->hasFullAccess()) {
            return DB::table('manager_salesman')->where('manager_user_id', $u->id)->where('salesman_user_id', $l->created_by_user_id)->exists();
        }

        return false;
    }

    // ─────────── 생성·예약·취소 ───────────

    /**
     * @param  array{company_id?: ?int, extra_phone?: ?string, address?: ?string, stock_location?: ?string, send_at?: ?Carbon, message?: ?string}  $data
     * @param  list<UploadedFile>  $files
     *
     * @throws \DomainException open_exists | recipient_required | bad_location | too_many_files | executable_file
     */
    public function create(PurchaseListing $l, User $by, array $data, array $files = []): TransportRequest
    {
        if (TransportRequest::openFor($l->id)) {
            throw new \DomainException('open_exists');
        }
        $company = ! empty($data['company_id']) ? TransportCompany::active()->find((int) $data['company_id']) : null;
        $extra = TransportCompanyPhone::normalize($data['extra_phone'] ?? null);
        if ($company === null && $extra === '') {
            throw new \DomainException('recipient_required');
        }
        $loc = trim((string) ($data['stock_location'] ?? ''));
        if ($loc !== '' && ! in_array($loc, self::stockLocations(), true)) {
            throw new \DomainException('bad_location');
        }
        if (count($files) > TransportRequest::MAX_FILES) {
            throw new \DomainException('too_many_files');
        }
        foreach ($files as $f) {
            if (UploadGuard::isExecutable($f->getClientOriginalName())) {
                throw new \DomainException('executable_file');
            }
        }

        $sendAt = $data['send_at'] ?? now();
        $req = DB::transaction(function () use ($l, $by, $data, $company, $extra, $loc, $sendAt, $files) {
            $req = TransportRequest::create([
                'purchase_listing_id' => $l->id,
                'revision' => TransportRequest::nextRevision($l->id),
                'transport_company_id' => $company?->id,
                'company_name' => $company?->name,
                'extra_phone' => $extra !== '' ? $extra : null,
                'address' => trim((string) ($data['address'] ?? '')) ?: null,
                'message' => trim((string) ($data['message'] ?? '')) ?: TransportRequest::DEFAULT_MESSAGE,
                'stock_location' => $loc ?: null,
                'send_at' => $sendAt,
                'status' => TransportRequest::SCHEDULED,
                'requested_by_user_id' => $by->id,
            ]);
            $disk = (string) config('board.photo_disk');
            foreach ($files as $f) {
                $path = $f->store('transport/'.$l->id.'/'.$req->id, $disk);
                TransportRequestFile::create([
                    'transport_request_id' => $req->id, 's3_path' => $path, 'original_name' => $f->getClientOriginalName(),
                    'mime' => $f->getMimeType(), 'size' => $f->getSize(), 'uploaded_by_user_id' => $by->id,
                ]);
            }
            $this->audit($req, $by->id, 'transport_request_create', null, sprintf('%d회차 · %s · 사진 %d · 발송 %s%s',
                $req->revision, $company?->name ?: ('번호 '.TransportCompanyPhone::mask($extra)), count($files),
                $sendAt->timezone(config('app.timezone'))->format('m-d H:i'), $loc ? ' · 도착 '.$loc : ''));

            return $req;
        });

        if ($sendAt->lte(now())) {
            $this->send($req->fresh());
        }

        return $req->fresh();
    }

    public function reschedule(TransportRequest $req, User $by, Carbon $at): void
    {
        if (! $req->isScheduled()) {
            throw new \DomainException('not_scheduled');
        }
        $old = $req->send_at;
        $req->forceFill(['send_at' => $at])->save();
        $this->audit($req, $by->id, 'transport_request_reschedule', $old?->timezone(config('app.timezone'))->format('m-d H:i'), $at->timezone(config('app.timezone'))->format('m-d H:i'));
        if ($at->lte(now())) {
            $this->send($req->fresh());
        }
    }

    public function cancel(TransportRequest $req, User $by): void
    {
        if (! $req->isScheduled()) {
            throw new \DomainException('not_scheduled');
        }
        $req->forceFill(['status' => TransportRequest::CANCELLED, 'cancelled_at' => now()])->save();
        $this->audit($req, $by->id, 'transport_request_cancel', TransportRequest::SCHEDULED, TransportRequest::CANCELLED);
    }

    // ─────────── 발송 ───────────

    /** 예약 시각이 된 요청 전부 발송 + 5일 지난 sent 는 expired. 명령이 매분 부른다. @return array{sent:int, expired:int} */
    public function runDue(): array
    {
        $sent = 0;
        foreach (TransportRequest::where('status', TransportRequest::SCHEDULED)->where('send_at', '<=', now())->orderBy('id')->get() as $req) {
            try {
                $this->send($req);
                $sent++;
            } catch (\Throwable $e) {
                Log::warning('[transport] send failed', ['id' => $req->id, 'error' => $e->getMessage()]);
            }
        }
        $expired = 0;
        foreach (TransportRequest::where('status', TransportRequest::SENT)->where('sent_at', '<', now()->subDays(TransportRequest::LINK_DAYS))->get() as $req) {
            $req->forceFill(['status' => TransportRequest::EXPIRED])->save();
            $this->audit($req, null, 'transport_request_expired', TransportRequest::SENT, TransportRequest::EXPIRED);
            $expired++;
        }

        return ['sent' => $sent, 'expired' => $expired];
    }

    /** 수신자 스냅샷 + 알림톡. 같은 번호는 한 번만(영업이 관리이기도 한 경우 등). 결과와 무관하게 sent 로. */
    public function send(TransportRequest $req): void
    {
        if (! $req->isScheduled()) {
            return;
        }
        $req->forceFill(['status' => TransportRequest::SENT, 'sent_at' => now()])->save();
        $listing = $req->listing;
        $recipients = $req->recipients()->count() ? $req->recipients : $this->snapshotRecipients($req);

        $vars = [
            '요청회사' => self::companyLabel(),
            '차량번호' => (string) ($listing?->vehicle_number ?: '—'),
            '주소' => $req->address ?: __('transport.alimtalk.address_on_page'),
            '요청일시' => now()->timezone(config('app.timezone'))->format('m-d H:i'),
        ];
        $counts = ['sent' => 0, 'failed' => 0, 'skipped' => 0];
        foreach ($recipients as $r) {
            $log = $this->alimtalk->send('board_transport_request', $r->phone, $vars,
                ['user_id' => $r->user_id],
                [['name' => '배차 확인', 'url' => $req->linkFor($r)]]);
            $r->forceFill(['alimtalk_log_id' => $log->id, 'sent_status' => $log->status])->save();
            $counts[$log->status] = ($counts[$log->status] ?? 0) + 1;
        }
        $this->audit($req, null, 'transport_request_sent', null, sprintf('수신 %d명 (발송 %d · 실패 %d · 건너뜀 %d)', $recipients->count(), $counts['sent'], $counts['failed'], $counts['skipped']));
    }

    /** 업체 번호 전부 + 임의 번호 + 영업담당자(등록자) + 그 영업의 관리(manager_salesman). 번호 기준 중복 제거. */
    private function snapshotRecipients(TransportRequest $req)
    {
        $rows = [];
        $seen = [];
        $add = function (string $kind, ?string $phone, ?string $label, ?int $userId) use (&$rows, &$seen) {
            $n = TransportCompanyPhone::normalize($phone);
            if ($n === '' || isset($seen[$n])) {
                return;
            }
            $seen[$n] = true;
            $rows[] = ['kind' => $kind, 'phone' => $n, 'label' => $label, 'user_id' => $userId];
        };
        if ($req->company) {
            foreach ($req->company->activePhones as $p) {
                $add(TransportRequestRecipient::KIND_COMPANY, $p->phone, $p->label, null);
            }
        }
        $add(TransportRequestRecipient::KIND_EXTRA, $req->extra_phone, null, null);
        $creator = $req->listing?->creator;
        if ($creator) {
            $add(TransportRequestRecipient::KIND_SALES, $creator->phone, $creator->name, $creator->id);
            $managerIds = DB::table('manager_salesman')->where('salesman_user_id', $creator->id)->pluck('manager_user_id');
            foreach (User::whereIn('id', $managerIds)->where('is_active', true)->get() as $m) {
                $add(TransportRequestRecipient::KIND_MANAGER, $m->phone, $m->name, $m->id);
            }
        }
        foreach ($rows as $row) {
            $req->recipients()->create($row);
        }

        return $req->recipients()->get();
    }

    // ─────────── 업체 결정 ───────────

    /**
     * 선착순 — 트랜잭션 + lockForUpdate. 두 번째 사람은 not_decidable.
     *
     * @throws \DomainException not_decidable | bad_action
     */
    public function decide(TransportRequest $req, TransportRequestRecipient $r, string $action, ?string $reason, ?string $name, ?string $ip): TransportRequest
    {
        if (! in_array($action, ['accept', 'reject'], true)) {
            throw new \DomainException('bad_action');
        }
        $req = DB::transaction(function () use ($req, $r, $action, $reason, $name) {
            $locked = TransportRequest::whereKey($req->id)->lockForUpdate()->first();
            if (! $locked->canDecide($r)) {
                throw new \DomainException('not_decidable');
            }
            $locked->forceFill([
                'status' => $action === 'accept' ? TransportRequest::ACCEPTED : TransportRequest::REJECTED,
                'decided_at' => now(),
                'decided_recipient_id' => $r->id,
                'decided_name' => mb_substr(trim((string) $name), 0, 60) ?: null,
                'reject_reason' => $action === 'reject' ? (mb_substr(trim((string) $reason), 0, 200) ?: null) : null,
            ])->save();

            return $locked;
        });
        $req->load('decidedRecipient');
        $this->audit($req, null, 'transport_request_decided', TransportRequest::SENT, $req->status.' · '.$req->dispatchLine().($req->reject_reason ? ' · '.$req->reject_reason : ''), $ip);
        $this->notifyResult($req);

        return $req;
    }

    /** 영업·관리에게 종(DB) + 웹푸시 + 결과 알림톡. 수신자 = 발송 때 스냅샷한 sales/manager 행(결정 시점 재계산 없음 — 일관). */
    private function notifyResult(TransportRequest $req): void
    {
        $plate = (string) ($req->listing?->vehicle_number ?: '—');
        $accepted = $req->isAccepted();
        $vars = [
            '차량번호' => $plate,
            '결과' => $accepted ? __('transport.status.accepted') : __('transport.status.rejected'),
            '업체' => $req->company_name ?: __('transport.alimtalk.company_direct'),
            '처리자' => $req->decidedRecipient ? trim(($req->decided_name ?: ($req->decidedRecipient->label ?: '')).' '.$req->decidedRecipient->maskedPhone()) : '—',
            '처리일시' => $req->decided_at?->timezone(config('app.timezone'))->format('m-d H:i') ?: '—',
            '사유' => $req->reject_reason ?: '—',
        ];
        $payload = [
            'title' => ($accepted ? __('transport.push.title_accepted') : __('transport.push.title_rejected')).' — '.$plate,
            'body' => $req->dispatchLine().($req->reject_reason ? ' · '.$req->reject_reason : ''),
            'url' => route('listings', ['edit' => $req->purchase_listing_id]),
            'tag' => 'transport-'.$req->id,
        ];
        foreach ($req->recipients()->whereIn('kind', [TransportRequestRecipient::KIND_SALES, TransportRequestRecipient::KIND_MANAGER])->get() as $r) {
            $log = $this->alimtalk->send('board_transport_result', $r->phone, $vars, ['user_id' => $r->user_id]);
            $r->forceFill(['result_log_id' => $log->id])->save();
            if ($r->user_id && ($u = User::find($r->user_id))) {
                try {
                    $this->push->sendToUser($u, $payload);
                } catch (\Throwable $e) {
                    Log::warning('[transport] push failed', ['user' => $u->id, 'error' => $e->getMessage()]);
                }
            }
        }
    }

    // ─────────── 매입잔금 (§11) ───────────

    /**
     * [매입잔금 요청] — 포털과 같은 ERP 호출(등록 영업 명의). 금액 비우면 ERP 미지급 전액(지급대기 행 purchase_unpaid). note = 배차 정보.
     *
     * @return array{ok:bool, message:string}
     */
    public function requestBalance(TransportRequest $req, User $by, ?int $amountKrw): array
    {
        $l = $req->listing;
        if (! $l || ! $l->car_erp_vehicle_id) {
            return ['ok' => false, 'message' => __('transport.balance.no_erp_vehicle')];
        }
        $creator = $l->creator;
        $email = $creator?->car_erp_salesman_email ?: $creator?->email;
        if (! $email) {
            return ['ok' => false, 'message' => __('transport.balance.no_salesman')];
        }
        if ($amountKrw === null) {
            $amountKrw = $this->remainingUnpaid($email, $l);
            if ($amountKrw === null) {
                return ['ok' => false, 'message' => __('transport.balance.fill_unavailable')];
            }
        }
        $note = mb_substr(__('transport.balance.note_prefix').$req->dispatchLine(), 0, 200);
        $res = $this->erp->sendBoardRequest($email, CarErpReadService::REQ_PURCHASE_BALANCE, [(int) $l->car_erp_vehicle_id], null, $note, $amountKrw);
        $ok = (bool) ($res['ok'] ?? false);
        $msg = $ok ? __('transport.balance.sent', ['amount' => number_format($amountKrw)])
            : __('transport.balance.failed', ['reason' => (string) ($res['message'] ?? $res['reason'] ?? 'HTTP '.($res['status'] ?? 0))]);
        $req->forceFill(['balance_requested_at' => $ok ? now() : $req->balance_requested_at, 'balance_request_error' => $ok ? null : mb_substr($msg, 0, 500)])->save();
        $this->audit($req, $by->id, 'transport_balance_request', null, ($ok ? 'OK' : 'FAIL').' · '.number_format($amountKrw).'원 · '.$note.' · '.$email);

        return ['ok' => $ok, 'message' => $msg];
    }

    private function remainingUnpaid(string $email, PurchaseListing $l): ?int
    {
        try {
            $r = $this->erp->inventory($email, 'awaiting_payment', (string) $l->vehicle_number, 50);
        } catch (\Throwable) {
            return null;
        }
        foreach ((array) data_get($r, 'data.data', []) as $row) {
            if ((int) data_get($row, 'vehicle_id') === (int) $l->car_erp_vehicle_id) {
                $u = data_get($row, 'purchase_unpaid');

                return is_numeric($u) && (float) $u > 0 ? (int) round((float) $u) : null;
            }
        }

        return null;
    }

    // ─────────── ERP 보관 위치 (ERP 경로 ①③ 생기면 동작 — 없으면 「연동 대기」) ───────────

    /** @return array{ok:bool, available:bool, data:?array} */
    public function erpLocation(PurchaseListing $l): array
    {
        if (! $l->car_erp_vehicle_id || ! $this->erp->configured()) {
            return ['ok' => false, 'available' => false, 'data' => null];
        }
        $email = $l->creator?->car_erp_salesman_email ?: $l->creator?->email;
        $r = $this->erp->getStockLocation((string) $email, (int) $l->car_erp_vehicle_id);

        return ['ok' => (bool) ($r['ok'] ?? false), 'available' => ($r['status'] ?? 0) !== 404, 'data' => $r['ok'] ?? false ? (array) $r['data'] : null];
    }

    /** 요청 때 위치 + 메모, 배차완료 때 메모 갱신. 실패는 행에 남기고 흐름은 안 끊는다. */
    public function syncLocation(TransportRequest $req, ?User $by, string $note): void
    {
        $l = $req->listing;
        if (! $req->stock_location || ! $l?->car_erp_vehicle_id || ! $this->erp->configured()) {
            return;
        }
        $email = $l->creator?->car_erp_salesman_email ?: $l->creator?->email;
        $r = $this->erp->setStockLocation((string) $email, (int) $l->car_erp_vehicle_id, $req->stock_location, $note);
        $ok = (bool) ($r['ok'] ?? false);
        $err = $ok ? null : (($r['status'] ?? 0) === 404 ? __('transport.location.erp_pending') : (string) ($r['message'] ?? $r['reason'] ?? 'HTTP '.($r['status'] ?? 0)));
        $req->forceFill(['erp_location_synced_at' => $ok ? now() : $req->erp_location_synced_at, 'erp_location_error' => $err ? mb_substr($err, 0, 500) : null])->save();
        $this->audit($req, $by?->id, 'transport_location_sent', null, ($ok ? 'OK' : 'FAIL').' · '.$req->stock_location.' · '.$note.($err ? ' · '.$err : ''));
    }

    // ─────────── 도우미 ───────────

    /** @return list<string> */
    public static function stockLocations(): array
    {
        return array_values((array) config('board.stock_locations', []));
    }

    /** `#{요청회사}` — 인스턴스 회사명(config instances.*.company_name), 없으면 기능설정 브랜드. */
    public static function companyLabel(): string
    {
        return (string) ((Instance::assets()['company_name'] ?? null) ?: (Setting::get('sidebar_brand', '') ?: config('app.name')));
    }

    private function audit(TransportRequest $req, ?int $userId, string $action, ?string $old, ?string $new, ?string $ip = null): void
    {
        BoardAuditLog::create([
            'user_id' => $userId,
            'purchase_listing_id' => $req->purchase_listing_id,
            'action' => $action,
            'field' => 'transport#'.$req->id.'r'.$req->revision,
            'old_value' => $old,
            'new_value' => $new,
            'ip_address' => $ip ?? BoardAuditLog::currentIp(),
        ]);
    }
}

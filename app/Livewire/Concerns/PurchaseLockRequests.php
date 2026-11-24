<?php

namespace App\Livewire\Concerns;

use App\Models\BoardAuditLog;
use App\Models\PurchaseListing;
use App\Services\CarErpReadService;

/**
 * 매입 락 승인 요청(2026-11-19 Jin, ERP §17) — 🔒 바이어 + 대수 + 사유 → ERP 승인큐. 금액은 승인 대상이 아니다.
 * 구매탭(/auction 구매확정)과 매입예정 드로어(재고매입 차 바이어 지정, 2026-11-24 Jin)가 **같은 동작**을 쓴다 — 화면 상자 = `livewire._lock-request`.
 *
 * 쓰는 쪽이 정해 줄 것:
 *   lockContext()      — 지금 요청 대상 [email(등록 영업 ERP 이메일), buyer_id, buyer_name, listing] · 락 바이어가 아니면 null
 *   reloadLockState()  — 바이어 목록·승인 상태를 ERP 에서 다시 읽는다(요청·취소·새로고침 뒤)
 */
trait PurchaseLockRequests
{
    public string $lockCount = '1';

    public string $lockReason = '';

    /** 고른 바이어의 승인 상태(CarErpReadService::purchaseLockStatus 모양) — 락 바이어일 때만 채운다. */
    public ?array $lockStatus = null;

    public ?string $lockNotice = null;

    public ?string $lockError = null;

    /** @return array{email:string, buyer_id:int, buyer_name:?string, listing:PurchaseListing}|null */
    abstract protected function lockContext(): ?array;

    abstract protected function reloadLockState(): void;

    /** 고른 바이어의 남은 승인 대수(조회 실패·미조회 = 0). */
    public function lockRemaining(): int
    {
        return (int) ($this->lockStatus['remaining'] ?? 0);
    }

    /**
     * [ERP 승인 요청] — 「이 바이어로 N대 더 등록」 + 사유를 ERP 승인큐로. 등록 영업 명의(누른 사람 이름은 따로 실린다).
     * 금액·첨부는 보내지 않고 드로어 값도 저장하지 않는다(Jin: 락을 풀 것이냐만 판단).
     */
    public function requestLockApproval(): void
    {
        $this->lockNotice = $this->lockError = null;
        $ctx = $this->lockContext();
        if ($ctx === null) {
            return;
        }
        $this->validate([
            'lockCount' => ['required', 'integer', 'min:1', 'max:999'],
            'lockReason' => ['required', 'string', 'min:5', 'max:500'],   // ERP 규격 5~500자
        ], [], ['lockCount' => __('auction.lock_req_count'), 'lockReason' => __('auction.lock_req_reason')]);

        $l = $ctx['listing'];
        $payload = [
            'buyer_id' => $ctx['buyer_id'],
            'requested_count' => (int) $this->lockCount,
            'reason' => trim($this->lockReason),
            'requested_by_name' => mb_substr((string) auth()->user()->name, 0, 50),
            // 실제 누른 사람의 로그인 이메일 — ERP 가 「대리로 요청한 사람은 그 건을 승인 못 한다」를 판정한다(Jin 2026-11-19).
            //   ERP 는 이 값을 ERP 로그인 이메일과 비교한다 → ERP 매핑 이메일(오버라이드)이 있으면 그 값, 없으면 로그인 이메일.
            'requested_by_email' => auth()->user()->car_erp_salesman_email ?: auth()->user()->email,
            'purchase_listing_id' => $l->id,
            'vehicle_number' => $l->vehicle_number,
            'vehicle_label' => null,
        ];
        $r = app(CarErpReadService::class)->requestPurchaseLock($ctx['email'], $payload);
        $ok = (bool) ($r['ok'] ?? false);
        BoardAuditLog::create([
            'user_id' => auth()->id(), 'purchase_listing_id' => $l->id, 'action' => 'erp_purchase_lock_request', 'field' => 'erp_purchase_lock_request',
            'old_value' => null,
            'new_value' => json_encode(['as' => $ctx['email'], 'buyer_id' => $ctx['buyer_id'], 'buyer' => $ctx['buyer_name'], 'count' => (int) $this->lockCount,
                'reason' => trim($this->lockReason), 'result' => $ok ? 'ok' : 'failed', 'status' => $r['status'] ?? null], JSON_UNESCAPED_UNICODE),
            'ip_address' => BoardAuditLog::currentIp(),
        ]);
        if (! $ok) {
            $code = (string) ($r['error'] ?? '');
            $msg = trim((string) ($r['message'] ?? ''));
            $this->lockError = match (true) {
                $code === 'already_pending' => __('auction.lock_req_err_pending'),
                $code === 'not_locked' => __('auction.lock_req_err_not_locked'),
                $msg !== '' => $msg,
                default => __('auction.lock_req_failed', ['status' => $r['status'] ?? 0]),
            };
            $this->reloadLockState();   // not_locked = 그사이 락이 풀렸다 / already_pending = 지금 대기 건을 그대로 보여 준다

            return;
        }
        $this->lockReason = '';
        $this->lockNotice = __('auction.lock_req_sent', ['count' => (int) $this->lockCount]);
        $this->reloadLockState();
    }

    /** [요청 취소] — 대기 중인 요청을 거둔다(대수를 잘못 적었을 때, 2026-11-19 Jin). 이미 결정됐으면 그 결과를 보여 준다. */
    public function cancelLockRequest(): void
    {
        $this->lockNotice = $this->lockError = null;
        $ctx = $this->lockContext();
        $lt = $this->lockStatus['latest'] ?? null;
        if ($ctx === null || ! $lt || ($lt['status'] ?? '') !== 'pending' || empty($lt['id'])) {
            return;
        }
        $r = app(CarErpReadService::class)->cancelPurchaseLock($ctx['email'], (int) $lt['id'], auth()->user()->name);
        $ok = (bool) ($r['ok'] ?? false);
        BoardAuditLog::create([
            'user_id' => auth()->id(), 'purchase_listing_id' => $ctx['listing']->id, 'action' => 'erp_purchase_lock_cancel', 'field' => 'erp_purchase_lock_cancel',
            'old_value' => null,
            'new_value' => json_encode(['as' => $ctx['email'], 'buyer_id' => $ctx['buyer_id'], 'request_id' => (int) $lt['id'], 'count' => $lt['requested_count'] ?? null,
                'result' => $ok ? 'ok' : 'failed', 'status' => $r['status'] ?? null, 'error' => $r['error'] ?? null], JSON_UNESCAPED_UNICODE),
            'ip_address' => BoardAuditLog::currentIp(),
        ]);
        if ($ok) {
            $this->lockNotice = __('auction.lock_req_cancelled');
        } else {
            $msg = trim((string) ($r['message'] ?? ''));
            $this->lockError = ($r['error'] ?? '') === 'already_decided'
                ? __('auction.lock_req_err_decided')
                : ($msg !== '' ? $msg : __('auction.lock_req_failed', ['status' => $r['status'] ?? 0]));
        }
        $this->reloadLockState();
    }

    /** [상태 새로고침] — 승인됐는지 다시 묻는다. */
    public function refreshLockStatus(): void
    {
        $this->lockNotice = $this->lockError = null;
        $this->reloadLockState();
    }
}

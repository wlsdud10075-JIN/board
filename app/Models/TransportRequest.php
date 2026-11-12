<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\URL;

/**
 * 탁송 배차 요청 1건 = 매물 1대 × 회차(2026-11-12 Jin, 정본 meetings/plan-transport-2026-11.md).
 *
 *   scheduled(예약·발송 전) → sent(알림톡 발송) → accepted | rejected | expired(5일 무응답)
 *   scheduled → cancelled(발송 전 취소). 거절·만료·취소 뒤 재요청 = 새 회차(revision+1).
 * 한 매물에 열린(scheduled|sent) 요청은 1건. 결정은 업체 쪽 수신자 링크에서만(TransportRequestRecipient::canDecide).
 */
class TransportRequest extends Model
{
    public const SCHEDULED = 'scheduled';

    public const SENT = 'sent';

    public const ACCEPTED = 'accepted';

    public const REJECTED = 'rejected';

    public const EXPIRED = 'expired';

    public const CANCELLED = 'cancelled';

    public const OPEN = [self::SCHEDULED, self::SENT];

    /** 서명 링크 수명(일) — 클레임 승인 링크와 같다. 만료 판정 = 서명 만료 + 상태 expired 둘 다. */
    public const LINK_DAYS = 5;

    /** POST 결정 서명 수명(분) — 진입 때 새로 발급. */
    public const DECIDE_MINUTES = 60;

    public const MAX_FILES = 5;

    public const DEFAULT_MESSAGE = '배차신청 요청드립니다.';

    protected $fillable = [
        'purchase_listing_id', 'revision', 'transport_company_id', 'company_name', 'extra_phone', 'address', 'message',
        'stock_location', 'send_at', 'status', 'sent_at', 'decided_at', 'decided_recipient_id', 'decided_name', 'reject_reason',
        'requested_by_user_id', 'cancelled_at', 'balance_requested_at', 'balance_request_error', 'erp_location_synced_at', 'erp_location_error',
    ];

    protected $casts = [
        'extra_phone' => 'encrypted',
        'send_at' => 'datetime', 'sent_at' => 'datetime', 'decided_at' => 'datetime', 'cancelled_at' => 'datetime',
        'balance_requested_at' => 'datetime', 'erp_location_synced_at' => 'datetime',
    ];

    public function listing(): BelongsTo
    {
        return $this->belongsTo(PurchaseListing::class, 'purchase_listing_id')->withoutGlobalScopes();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(TransportCompany::class, 'transport_company_id')->withTrashed();
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(TransportRequestRecipient::class)->orderBy('id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(TransportRequestFile::class)->orderBy('id');
    }

    public function decidedRecipient(): BelongsTo
    {
        return $this->belongsTo(TransportRequestRecipient::class, 'decided_recipient_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function isScheduled(): bool
    {
        return $this->status === self::SCHEDULED;
    }

    public function isSent(): bool
    {
        return $this->status === self::SENT;
    }

    public function isAccepted(): bool
    {
        return $this->status === self::ACCEPTED;
    }

    public function isDecided(): bool
    {
        return in_array($this->status, [self::ACCEPTED, self::REJECTED], true);
    }

    /** 결정 가능 = 발송됨 + 서명 수명 안 + 업체 쪽 수신자. 선착순 두 번째는 여기서 false(상태가 이미 바뀜). */
    public function canDecide(?TransportRequestRecipient $r): bool
    {
        return $r !== null
            && $r->transport_request_id === $this->id
            && $r->canDecide()
            && $this->isSent()
            && $this->sent_at !== null
            && $this->sent_at->gt(now()->subDays(self::LINK_DAYS));
    }

    public function statusLabel(): string
    {
        return __('transport.status.'.$this->status);
    }

    public function statusBadge(): string
    {
        return match ($this->status) {
            self::ACCEPTED => 'bg-emerald-100 text-emerald-700',
            self::REJECTED => 'bg-red-100 text-red-700',
            self::SENT => 'bg-blue-100 text-blue-700',
            self::SCHEDULED => 'bg-amber-100 text-amber-700',
            default => 'bg-gray-100 text-gray-600',
        };
    }

    /** 수신자별 서명 링크(5일) — 발송 시점에 만든다(예약 발송과 수명이 어긋나지 않게). APP_URL 고정(BizM 등록 접두와 같아야 K108 안 난다). */
    public function linkFor(TransportRequestRecipient $r): string
    {
        return URL::temporarySignedRoute('transport.show', now()->addDays(self::LINK_DAYS), ['req' => $this->id, 'r' => $r->id]);
    }

    /** 다음 회차 번호. */
    public static function nextRevision(int $listingId): int
    {
        return ((int) self::where('purchase_listing_id', $listingId)->max('revision')) + 1;
    }

    public static function openFor(int $listingId): ?self
    {
        return self::where('purchase_listing_id', $listingId)->whereIn('status', self::OPEN)->latest('id')->first();
    }

    /** 배차 정보 한 줄 — §11 매입잔금 note·결과 알림톡·종에 같이 쓴다(전화는 가운데 마스킹). */
    public function dispatchLine(): string
    {
        $who = $this->decidedRecipient;
        $parts = array_filter([
            $this->company_name,
            $who ? trim(($this->decided_name ?: ($who->label ?: '')).' '.$who->maskedPhone()) : null,
            $this->decided_at?->timezone(config('app.timezone'))->format('m-d H:i'),
        ]);

        return implode(' · ', $parts);
    }
}

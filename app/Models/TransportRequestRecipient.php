<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 발송 수신자 스냅샷 — 서명 링크의 `r` 가 이 행이다. 업체 번호·임의 번호(company_phone|extra)만 결정할 수 있고
 * 영업·관리(sales|manager)는 같은 알림톡을 받되 페이지는 보기 전용(Jin 2026-11-12 결정 2).
 */
class TransportRequestRecipient extends Model
{
    public const KIND_COMPANY = 'company_phone';

    public const KIND_EXTRA = 'extra';

    public const KIND_SALES = 'sales';

    public const KIND_MANAGER = 'manager';

    protected $fillable = ['transport_request_id', 'kind', 'phone', 'label', 'user_id', 'alimtalk_log_id', 'sent_status', 'result_log_id'];

    protected $casts = ['phone' => 'encrypted'];

    public function request(): BelongsTo
    {
        return $this->belongsTo(TransportRequest::class, 'transport_request_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** 결정권 = 업체 쪽(업체 번호·임의 번호)뿐. */
    public function canDecide(): bool
    {
        return in_array($this->kind, [self::KIND_COMPANY, self::KIND_EXTRA], true);
    }

    public function displayName(): string
    {
        return $this->label ?: TransportCompanyPhone::mask($this->phone);
    }

    public function maskedPhone(): string
    {
        return TransportCompanyPhone::mask($this->phone);
    }
}

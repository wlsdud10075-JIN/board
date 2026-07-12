<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 카카오 알림톡(BizM) 발송 감사 로그 — 어떤 템플릿을·누구에게·언제 보냈고 성공/실패했는지.
 *
 * status: sent(발송 성공, msgid 있음) / failed(BizM 오류·예외) / skipped(게이트 off·미설정 등 발송 안 함).
 * user_id = 수신 검차원(있으면), region = 지역 digest 맥락(있으면).
 */
class AlimtalkLog extends Model
{
    protected $fillable = [
        'user_id', 'template_code', 'phone', 'region',
        'message', 'msgid', 'status', 'error',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

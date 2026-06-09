<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * append-only 감사 로그. updated_at 없음.
 * 금액·상태 변경 + 관리자 override 를 Service 단일경로로 기록.
 */
class BoardAuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'purchase_listing_id', 'action', 'field', 'old_value', 'new_value',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(PurchaseListing::class, 'purchase_listing_id');
    }
}

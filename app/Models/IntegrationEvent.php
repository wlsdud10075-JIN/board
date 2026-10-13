<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 외부 연동 push/콜백의 append-only 로그. created_at 만(불변, updated_at 없음).
 *
 * @see database/migrations/*_create_integration_events_table.php
 */
class IntegrationEvent extends Model
{
    public const UPDATED_AT = null;   // append-only

    protected $fillable = [
        'direction', 'target', 'event_type', 'purchase_listing_id',
        'external_event_id', 'request_payload', 'response_status', 'response_body', 'error',
    ];

    /** 감사 화면 차량번호 표시용. 삭제된 차의 전송 기록도 어느 차였는지 보여야 한다. */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(PurchaseListing::class, 'purchase_listing_id')->withTrashed();
    }

    protected function casts(): array
    {
        return [
            'request_payload' => 'array',
            'response_status' => 'integer',
        ];
    }
}

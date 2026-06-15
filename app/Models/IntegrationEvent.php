<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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

    protected function casts(): array
    {
        return [
            'request_payload' => 'array',
            'response_status' => 'integer',
        ];
    }
}

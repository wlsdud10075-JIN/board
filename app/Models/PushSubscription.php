<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 웹푸시 구독 1건(기기 1대). 발급·저장 = `PushSubscriptionController`, 발송·만료 정리 = `WebPushService`.
 * 유일성은 `endpoint_hash`(sha256) — endpoint 원문은 1000자까지라 직접 unique 를 걸지 않는다.
 */
class PushSubscription extends Model
{
    protected $fillable = ['user_id', 'endpoint', 'endpoint_hash', 'p256dh', 'auth_token', 'content_encoding', 'user_agent'];

    public static function hashEndpoint(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

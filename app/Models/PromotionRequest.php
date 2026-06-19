<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 연동 A — 승격 대기(respond.io `board_promote=Yes` 캡처). 영업이 보고 링크+차번호로 승격.
 *
 * @see database/migrations/*_create_promotion_requests_table.php
 * @see app\Console\Commands\PollRespondPromotions.php
 */
class PromotionRequest extends Model
{
    public const PENDING = 'pending';

    public const CONSUMED = 'consumed';

    public const DISMISSED = 'dismissed';

    public const EXPIRED = 'expired';

    protected $fillable = [
        'respond_contact_id', 'label', 'assigned_email', 'status', 'purchase_listing_id', 'handled_by_user_id',
    ];

    public function listing(): BelongsTo
    {
        return $this->belongsTo(PurchaseListing::class, 'purchase_listing_id');
    }
}

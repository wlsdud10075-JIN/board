<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InspectionPhoto extends Model
{
    protected $fillable = [
        'purchase_listing_id', 's3_path', 'original_name', 'sort',
    ];

    public function listing(): BelongsTo
    {
        return $this->belongsTo(PurchaseListing::class, 'purchase_listing_id');
    }

    /** 영상 파일 여부 (확장자 기준) — 렌더링 시 <video> vs <img> 분기. */
    public function isVideo(): bool
    {
        $ext = strtolower(pathinfo($this->original_name ?: $this->s3_path, PATHINFO_EXTENSION));

        return in_array($ext, ['mp4', 'mov', 'avi', 'wmv', 'm4v'], true);
    }
}

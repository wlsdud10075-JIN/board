<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class InspectionPhoto extends Model
{
    protected $fillable = [
        'purchase_listing_id', 's3_path', 'original_name', 'sort', 'share_to_buyer',
    ];

    protected function casts(): array
    {
        return ['share_to_buyer' => 'boolean'];
    }

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

    /** 바이어 전송용 외부 접근 URL (s3=presigned 60분 / 로컬=public). */
    public function shareUrl(): string
    {
        $disk = config('board.photo_disk');
        if ($disk === 's3') {
            return Storage::disk('s3')->temporaryUrl($this->s3_path, now()->addMinutes(60));
        }

        return Storage::disk($disk)->url($this->s3_path);
    }
}

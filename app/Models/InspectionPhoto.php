<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class InspectionPhoto extends Model
{
    // 첨부 종류 (kind). inspection=검차사진(기존), sales_*=영업 차량첨부(→연동 B car-erp).
    public const KIND_INSPECTION = 'inspection';

    public const KIND_SALES_PHOTO = 'sales_photo';

    public const KIND_SALES_DOCUMENT = 'sales_document';

    /** 연동 B 로 car-erp 첨부탭에 전달하는 종류(영업 자료만 — 검차사진은 제외). */
    public const SALES_KINDS = [self::KIND_SALES_PHOTO, self::KIND_SALES_DOCUMENT];

    protected $fillable = [
        'purchase_listing_id', 's3_path', 'original_name', 'sort', 'share_to_buyer',
        'kind', 'uploaded_by_user_id',
    ];

    protected function casts(): array
    {
        return ['share_to_buyer' => 'boolean'];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(PurchaseListing::class, 'purchase_listing_id');
    }

    /** 서류 첨부 여부 — 바이어 전송 제외(§28) + UI 분기. */
    public function isDocument(): bool
    {
        return $this->kind === self::KIND_SALES_DOCUMENT;
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

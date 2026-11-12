<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/** 탁송 요청 사진(명함·차량 외관, ≤5) — ClaimFile 미러. 공개 페이지엔 만료 임시 URL 로만. */
class TransportRequestFile extends Model
{
    protected $fillable = ['transport_request_id', 's3_path', 'original_name', 'mime', 'size', 'uploaded_by_user_id'];

    public function request(): BelongsTo
    {
        return $this->belongsTo(TransportRequest::class, 'transport_request_id');
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime, 'image/');
    }

    /** s3 는 presigned(60분), 로컬은 public 링크(ClaimFile::shareUrl 미러). */
    public function shareUrl(): string
    {
        $disk = (string) config('board.photo_disk');
        if ($disk === 's3') {
            return Storage::disk('s3')->temporaryUrl($this->s3_path, now()->addMinutes(60));
        }

        return Storage::disk($disk)->url($this->s3_path);
    }
}

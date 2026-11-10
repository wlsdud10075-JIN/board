<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * 클레임 첨부 — 바이어가 보낸 증빙을 영업이 접수 때 올린다(종류별 칸). 디스크 = `board.photo_disk`(로컬 public / 운영 s3), prefix `board.claims_prefix`.
 * 종류 = odometer(계기판 사진, 1장 이상 필수) · damage(손상 사진·영상, 1건 이상 필수) · invoice(정비소 자료 = INVOICE, 1건 이상 필수) · other.
 */
class ClaimFile extends Model
{
    public const KIND_ODOMETER = 'odometer';

    public const KIND_DAMAGE = 'damage';

    public const KIND_INVOICE = 'invoice';

    public const KIND_OTHER = 'other';

    public const KINDS = [self::KIND_ODOMETER, self::KIND_DAMAGE, self::KIND_INVOICE, self::KIND_OTHER];

    /** 접수 저장에 꼭 있어야 하는 종류(§12 → §15). */
    public const REQUIRED_KINDS = [self::KIND_ODOMETER, self::KIND_DAMAGE, self::KIND_INVOICE];

    protected $fillable = ['claim_id', 'kind', 's3_path', 'original_name', 'mime', 'size', 'uploaded_by_user_id'];

    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class);
    }

    public function isVideo(): bool
    {
        return str_starts_with((string) $this->mime, 'video/')
            || preg_match('/\.(mp4|mov|m4v|webm|3gp)$/i', (string) $this->original_name) === 1;
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime, 'image/')
            || preg_match('/\.(jpe?g|png|gif|webp|heic)$/i', (string) $this->original_name) === 1;
    }

    /** 보기 URL — s3 는 presigned(60분), 로컬은 public 링크. 담당자 드로어·대표 링크 화면이 같이 쓴다(InspectionPhoto::shareUrl 미러). */
    public function shareUrl(): string
    {
        $disk = config('board.photo_disk');
        if ($disk === 's3') {
            return Storage::disk('s3')->temporaryUrl($this->s3_path, now()->addMinutes(60));
        }

        return Storage::disk($disk)->url($this->s3_path);
    }
}

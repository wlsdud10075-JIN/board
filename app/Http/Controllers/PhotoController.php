<?php

namespace App\Http\Controllers;

use App\Models\InspectionPhoto;
use App\Models\PurchaseListing;
use Illuminate\Support\Facades\Storage;

/**
 * 사진 같은출처(board 도메인) 스트리밍 — 모바일 다중 공유(navigator.share({files}))의 fetch 가
 * 운영 S3 presigned(교차출처) CORS 에 막히지 않도록 board 가 대신 흘려준다.
 * 스코프: 사진의 listing 을 볼 수 있는 사용자만(SalesmanScope → sales 는 본인 것만, IDOR 차단).
 */
class PhotoController extends Controller
{
    public function show(InspectionPhoto $photo)
    {
        // PurchaseListing::find 가 SalesmanScope 안 → 못 보는 영업이면 null → 403
        abort_if(PurchaseListing::find($photo->purchase_listing_id) === null, 403);

        $disk = config('board.photo_disk');
        abort_unless(Storage::disk($disk)->exists($photo->s3_path), 404);

        return Storage::disk($disk)->response($photo->s3_path, $photo->original_name ?: basename($photo->s3_path));
    }
}

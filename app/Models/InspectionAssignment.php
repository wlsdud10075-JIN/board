<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InspectionAssignment extends Model
{
    /** 지역×날짜에 배정 가능한 최대 인원 (§6c). */
    public const MAX_PER_REGION = 3;

    // date 는 'Y-m-d' 평문 문자열로 보관(드라이버 무관 동등비교 — Carbon 캐스트 시 sqlite 에서 시각이 붙어 where 불일치).
    protected $fillable = ['date', 'region', 'user_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

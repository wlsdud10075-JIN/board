<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InspectionAssignment extends Model
{
    /** 지역×날짜에 배정 가능한 최대 인원 (§6c). */
    public const MAX_PER_REGION = 3;

    protected $fillable = ['date', 'region', 'user_id'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

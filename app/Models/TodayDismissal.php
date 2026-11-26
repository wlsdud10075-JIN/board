<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 「오늘 할 일」 [확인] 기록 — 사람별. 판정은 `App\Support\TodayList`. */
class TodayDismissal extends Model
{
    protected $fillable = ['user_id', 'purchase_listing_id', 'kind', 'signature'];
}

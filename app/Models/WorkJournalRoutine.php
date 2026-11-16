<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 업무일지 「고정 업무」 — 사람마다 요일·시간·위치·내용(예: 수 09:00 경매장 AJ 경매장 검수). 그 요일 [하루 틀 만들기] 때 미리 채운다(2026-11-16 Jin). */
class WorkJournalRoutine extends Model
{
    public const WEEKDAYS = [1, 2, 3, 4, 5];

    protected $fillable = ['user_id', 'weekday', 'time', 'location', 'task'];
}

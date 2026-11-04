<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 결재 의견 — work_journal_id 가 있으면 그 사람에게, null 이면 전체. 반려는 없다(의견만). */
class WorkJournalComment extends Model
{
    protected $fillable = ['batch_id', 'step_id', 'work_journal_id', 'user_id', 'body'];

    public function step(): BelongsTo
    {
        return $this->belongsTo(WorkJournalStep::class, 'step_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

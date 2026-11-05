<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** 업무일지 — 사람·날짜 1개. draft(작성 중·자동 저장) → submitted(상신, 잠김) → approved(최종 승인). 팀장 반려 = rejected(내용 그대로 다시 쓰기 가능). 정본 = meetings/plan-work-journal-2026-11.md */
class WorkJournal extends Model
{
    protected $fillable = ['user_id', 'date', 'status', 'submitted_at', 'batch_id', 'snapshot', 'rejected_at', 'rejected_by', 'reject_reason'];

    protected function casts(): array
    {
        return ['date' => 'date', 'submitted_at' => 'datetime', 'snapshot' => 'array', 'rejected_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(WorkJournalBatch::class, 'batch_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(WorkJournalEntry::class)->orderBy('sort')->orderBy('id');
    }

    /** 쓸 수 있는 상태 — 작성 중 또는 팀장 반려(고쳐서 다시 상신). */
    public function isDraft(): bool
    {
        return in_array($this->status, ['draft', 'rejected'], true);
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }
}

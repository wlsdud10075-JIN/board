<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** 업무일지 — 사람·날짜 1개. draft(작성 중·자동 저장) → submitted(상신, 잠김) → approved(최종 승인). 정본 = meetings/plan-work-journal-2026-11.md */
class WorkJournal extends Model
{
    protected $fillable = ['user_id', 'date', 'status', 'submitted_at', 'batch_id', 'snapshot'];

    protected function casts(): array
    {
        return ['date' => 'date', 'submitted_at' => 'datetime', 'snapshot' => 'array'];
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

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }
}

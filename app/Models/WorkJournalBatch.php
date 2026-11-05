<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** 업무일지 결재 묶음 — 날짜별(같은 날 팀장 승인 뒤 상신분은 seq+1 「추가 기안」). 결재선 = steps, 의견 = comments. */
class WorkJournalBatch extends Model
{
    protected $fillable = ['date', 'seq', 'status', 'current_step', 'approved_at'];

    protected function casts(): array
    {
        return ['date' => 'date', 'approved_at' => 'datetime'];
    }

    public function steps(): HasMany
    {
        return $this->hasMany(WorkJournalStep::class, 'batch_id')->orderBy('seq');
    }

    public function journals(): HasMany
    {
        return $this->hasMany(WorkJournal::class, 'batch_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(WorkJournalComment::class, 'batch_id')->orderBy('id');
    }

    public function currentStep(): ?WorkJournalStep
    {
        return $this->current_step === null ? null : $this->steps->firstWhere('seq', $this->current_step);
    }

    /** 이 묶음에서 승인한 단계가 하나라도 있나 — 있으면 회수·합류 불가(새 기안으로). */
    public function hasApprovals(): bool
    {
        return $this->steps->contains('status', 'approved');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 클레임 결재선 한 칸(2026-11-11 Jin) — 회차(revision)마다 새로 만든다. 부장 → 상무 → 대표 중 지정한 칸만. */
class ClaimStep extends Model
{
    protected $fillable = ['claim_id', 'revision', 'seq', 'title', 'approver_user_id', 'status', 'acted_at', 'acted_by_user_id', 'note'];

    protected function casts(): array
    {
        return ['acted_at' => 'datetime'];
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acted_by_user_id');
    }

    /** 결재자 본인이 아니라 super 가 대신 결정했나. */
    public function byProxy(): bool
    {
        return $this->acted_by_user_id !== null && (int) $this->acted_by_user_id !== (int) $this->approver_user_id;
    }
}

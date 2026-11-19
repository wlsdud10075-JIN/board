<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 업무일지 결재 이력 한 줄 — 상신 의견·결재의견·반려 사유(2026-11-19). 일지·날짜에만 묶인다(기안·단계가 지워져도 남는다).
 * work_journal_id null = 기안 전체 줄 — 결재자에게만 보인다(작성자 화면엔 자기 일지 줄만).
 */
class WorkJournalNote extends Model
{
    public const SUBMIT = 'submit';

    public const OPINION = 'opinion';

    public const REJECT = 'reject';

    protected $fillable = ['date', 'work_journal_id', 'batch_seq', 'kind', 'title', 'user_id', 'user_name', 'body', 'targets'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }
}

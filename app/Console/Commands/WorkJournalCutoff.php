<?php

namespace App\Console\Commands;

use App\Services\WorkJournalService;
use Illuminate\Console\Command;

/**
 * 업무일지 마감 알림(2026-11-04 Jin) — 평일, 기능설정 마감 시각(기본 19:00)이 지나면 하루 1번 지금 차례 결재자(보통 팀장)에게 「상신 N · 미상신 M」.
 * 스케줄은 5분마다 돌고, 시각·주말·오늘 보냈는지는 서비스가 판정한다(멱등).
 */
class WorkJournalCutoff extends Command
{
    protected $signature = 'board:work-journal-cutoff';

    protected $description = '업무일지 마감 알림(평일 하루 1번)';

    public function handle(WorkJournalService $svc): int
    {
        $this->info($svc->notifyCutoff() ? '마감 알림 보냄' : '보낼 때 아님(주말·마감 전·오늘 보냄)');

        return self::SUCCESS;
    }
}

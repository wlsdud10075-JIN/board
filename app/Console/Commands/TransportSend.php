<?php

namespace App\Console\Commands;

use App\Services\TransportService;
use Illuminate\Console\Command;

/**
 * 탁송 예약 발송(매분) — send_at 이 된 scheduled 요청을 발송하고, 5일 지난 sent 는 expired 로(2026-11-12).
 * 로컬 시험: `php artisan board:transport-send` 를 손으로 1회(cron 없음).
 */
class TransportSend extends Command
{
    protected $signature = 'board:transport-send';

    protected $description = '탁송 배차 요청 예약 발송 + 5일 무응답 만료';

    public function handle(TransportService $svc): int
    {
        $r = $svc->runDue();
        $this->info("발송 {$r['sent']}건 · 만료 {$r['expired']}건");

        return self::SUCCESS;
    }
}

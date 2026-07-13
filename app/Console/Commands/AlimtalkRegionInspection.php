<?php

namespace App\Console\Commands;

use App\Services\RegionInspectionNotifier;
use Illuminate\Console\Command;

/**
 * 지역 검차 안내 알림톡(A) 스케줄 발송 — 내일 배정분을 전날 저녁에 사전 통보.
 *
 * 대상 = draft + region 지정 + 미통보 차량, 지역별 digest. 수신자 = 내일 날짜 기준(배정 override → 로스터).
 * 발송 시각 = Setting `alimtalk_region_schedule_time`(HH:MM). 스케줄 등록은 routes/console.php 에서 조건부.
 * 알림톡 마스터 off / tmplId 미설정이면 발송기가 skipped 처리(no-op) — 커맨드는 항상 안전.
 */
class AlimtalkRegionInspection extends Command
{
    protected $signature = 'board:alimtalk-region-inspection {--date= : 수신자 해석 기준일(기본=내일)}';

    protected $description = '지역 검차 안내 알림톡 — 내일 배정분 지역별 digest 발송';

    public function handle(RegionInspectionNotifier $notifier): int
    {
        $date = (string) ($this->option('date') ?: now()->addDay()->toDateString());
        $r = $notifier->run($date);

        $this->info("지역 검차 알림톡({$date}): 지역 {$r['regions']} · 발송 {$r['sent']} · skip {$r['skipped']} · 수신자없음 {$r['no_recipient']}");

        return self::SUCCESS;
    }
}

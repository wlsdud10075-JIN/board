<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 시간대 핀 — 2026-09-28 까지 config/app.php 가 'UTC' 하드코딩이라 운영이 UTC 로 돌았다
 * (TimeGate 10:00 잠금이 19:00 KST, 스케줄 dailyAt 9시간 어긋남, 화면 시각 UTC).
 * app.timezone 과 mysql 세션 오프셋은 **쌍**이다 — 하나만 바뀌면 TIMESTAMP 저장값 해석이 9시간 틀어진다.
 */
class TimezoneConfigTest extends TestCase
{
    public function test_app_timezone_is_seoul(): void
    {
        $this->assertSame('Asia/Seoul', config('app.timezone'));
        $this->assertSame('Asia/Seoul', date_default_timezone_get());
    }

    public function test_mysql_session_offset_pairs_with_app_timezone(): void
    {
        $this->assertSame('+09:00', config('database.connections.mysql.timezone'));
    }
}

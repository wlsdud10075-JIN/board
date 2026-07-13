<?php

use App\Models\Setting;
use App\Services\ExchangeRateService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// 환율 갱신 (§6a) — 네이버/다음 조회 → exchange_rates 캐시
Artisan::command('board:refresh-rates', function (ExchangeRateService $rates) {
    $updated = $rates->refresh();
    $this->info($updated ? '환율 갱신: '.json_encode($updated, JSON_UNESCAPED_UNICODE) : '갱신 실패 — 기존 캐시/폴백 유지');
})->purpose('환율(USD·EUR) 라이브 조회·캐시');

// 서버에 scheduler cron 이 있으면 매시 자동 갱신. (cron 없어도 화면 진입 lazy 갱신으로 신선도 유지)
Schedule::command('board:refresh-rates')->hourly();

// board DB 야간 백업 03:00 (30일 보관). 서버 scheduler cron 필요.
Schedule::command('db:backup --keep=30')->dailyAt('03:00');

// 연동 A(C) — respond.io 회신 폴링 → 자동 verdict 적용(직렬화). 미설정이면 명령 내부 no-op.
// withoutOverlapping(락②): 폴링 두 개 동시 실행 방지.
Schedule::command('board:poll-verdicts')->everyTwoMinutes()->withoutOverlapping();

// 연동 A — 승격 플래그 폴링 → 승격 대기 캡처(+7일 만료 정리). 미설정이면 만료 정리만 하고 no-op.
Schedule::command('board:poll-promotions')->everyTwoMinutes()->withoutOverlapping();

// ssancar 검차영상 감지 → draft 매물 자동 전달대기(inspected). 미설정이면 no-op. 인계=handoff-ssancar-media-poll.md.
Schedule::command('board:poll-ssancar-media')->everyTwoMinutes()->withoutOverlapping();

// 지역 검차 안내 알림톡(A) — 내일 배정분 전날 저녁 발송. 시각 = 기능설정 Setting(HH:MM).
// 시각 미설정이면 스케줄 미등록(자동발송 안 함). Setting 은 매 schedule:run(분 단위) 평가 → 값 바꾸면 즉시 반영.
// 알림톡 off/미승인이면 커맨드 내부에서 skipped(no-op)라 이중 안전.
$alimtalkTime = Setting::get('alimtalk_region_schedule_time', '');
if (is_string($alimtalkTime) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $alimtalkTime)) {
    Schedule::command('board:alimtalk-region-inspection')->dailyAt($alimtalkTime)->withoutOverlapping();
}

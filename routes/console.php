<?php

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

// 평일·주말 매일 오전 9시 자동 갱신 (큐 워커 불필요, scheduler cron)
Schedule::command('board:refresh-rates')->dailyAt('09:00');

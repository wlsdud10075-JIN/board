<?php

use App\Http\Controllers\RespondWebhookController;
use App\Http\Controllers\SsancarInspectionWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes (stateless, CSRF 제외) — 연동 A: respond.io inbound webhook
|--------------------------------------------------------------------------
| 인증 = 공유 시크릿 헤더(X-Webhook-Secret) 컨트롤러 내부 검증.
| 멱등 = integration_events.external_event_id. 계약 = meetings/integration-A-design.md.
*/

Route::post('/webhooks/respond', RespondWebhookController::class)
    ->name('webhooks.respond');

// 검차 큐 연동(ssancar.com, 2026-10-23) — 진행·완료·보류·되돌리기. 인증 = X-Webhook-Secret(인스턴스별), 멱등 = event_id.
Route::post('/webhooks/ssancar-inspection', SsancarInspectionWebhookController::class)
    ->name('webhooks.ssancar-inspection');

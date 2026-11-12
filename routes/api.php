<?php

use App\Http\Controllers\RespondWebhookController;
use App\Http\Controllers\SsancarInspectionWebhookController;
use App\Http\Middleware\VerifyOpsHmac;
use App\Services\Ops\OpsSummary;
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

// 관제센터 요약(2026-11-12) — ssancarerp /admin/control 허브가 5분마다 읽는다. 계약 = car-erp docs/design/ops-control-center.md §4.
// 인증 = HMAC(OPS_HMAC_SECRET, 3사 ERP 와 같은 값). 비면 401.
Route::get('/internal/ops/summary', fn () => response()->json(OpsSummary::collect()))
    ->middleware([VerifyOpsHmac::class, 'throttle:30,1'])
    ->name('internal.ops.summary');

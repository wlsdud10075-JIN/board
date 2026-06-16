<?php

use App\Http\Controllers\RespondWebhookController;
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

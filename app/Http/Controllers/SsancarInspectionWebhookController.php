<?php

namespace App\Http\Controllers;

use App\Models\IntegrationEvent;
use App\Models\PurchaseListing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * 검차 큐 연동 — ssancar.com → board 진행·완료 웹훅 (2026-10-23, 스펙 = ssancar `_ops/dev/INSPECTION_PLAN.md` §8-3).
 *
 * 인증 = `X-Webhook-Secret`(인스턴스별, respond 웹훅과 같은 틀) · 멱등 = `event_id` → integration_events.external_event_id.
 * board 는 **칸만 갱신**한다 — 「영상 > 0 → inspected」 전이는 board 폴러 소유 그대로(2분 폴러는 백업으로 계속 돈다).
 * 2xx 가 아니면 ssancar 가 최대 5회 다시 보내므로, 받을 수 없는 본문(모르는 이벤트·없는 매물)도 200 + 로그로 끝낸다.
 */
class SsancarInspectionWebhookController extends Controller
{
    private const EVENTS = ['inspection.started', 'inspection.completed', 'inspection.skipped', 'inspection.reopened', 'inspection.media_updated'];

    public function __invoke(Request $request): JsonResponse
    {
        $secret = (string) config('services.ssancar_inspection.webhook_secret');
        if ($secret === '') {
            return response()->json(['status' => 'misconfigured'], 503);   // 무인증 수락 금지
        }
        if (! hash_equals($secret, (string) $request->header('X-Webhook-Secret', ''))) {
            return response()->json(['status' => 'unauthorized'], 401);
        }

        $event = (string) $request->input('event', '');
        $eventId = (string) $request->input('event_id', '');
        if ($eventId !== '' && IntegrationEvent::where('target', 'ssancar_inspection')
            ->where('direction', 'inbound')->where('external_event_id', $eventId)->exists()) {
            return response()->json(['status' => 'duplicate']);
        }

        [$status, $listingId, $error] = $this->apply($event, $request);

        IntegrationEvent::create([
            'direction' => 'inbound',
            'target' => 'ssancar_inspection',
            'event_type' => $event !== '' ? $event : 'unknown',
            'purchase_listing_id' => $listingId,
            'external_event_id' => $eventId !== '' ? $eventId : null,
            'request_payload' => $request->all(),
            'response_status' => 200,
            'response_body' => $status,
            'error' => $error,
        ]);

        return response()->json(['status' => $status, 'listing_id' => $listingId]);
    }

    /** @return array{0:string,1:?int,2:?string} */
    private function apply(string $event, Request $request): array
    {
        if (! in_array($event, self::EVENTS, true)) {
            return ['ignored_event', null, "unknown event: {$event}"];
        }
        // 무인증 컨텍스트 → 영업 격리 스코프 제거. 삭제된 차는 받지 않는다(철회가 이미 나갔다).
        $l = PurchaseListing::withoutGlobalScopes()->whereNull('deleted_at')->find((int) $request->input('listing_id'));
        if (! $l) {
            return ['no_match', null, 'listing not found'];
        }
        $at = $this->time($request->input('at'));
        $name = $request->input('inspector.name');

        switch ($event) {
            case 'inspection.started':
                $l->inspection_started_at = $at;
                $l->ssancar_inspector_name = $name ?: $l->ssancar_inspector_name;
                break;
            case 'inspection.completed':
                $l->ssancar_inspected_wr_id = (int) $request->input('wr_id') ?: null;
                $l->ssancar_inspector_name = $name ?: $l->ssancar_inspector_name;
                $l->inspection_started_at ??= $at;
                break;
            case 'inspection.skipped':
                $l->inspection_skipped_at = $at;
                $l->inspection_skip_reason = mb_substr((string) $request->input('reason', 'other'), 0, 40);
                $l->inspection_skip_memo = ($m = $request->input('memo')) ? mb_substr((string) $m, 0, 255) : null;
                // 화면에서 링크로 그린다 → http(s) 만 받는다(javascript: 등 차단).
                $u = (string) $request->input('photo_url', '');
                $l->inspection_skip_photo_url = preg_match('#^https?://#i', $u) ? mb_substr($u, 0, 500) : null;
                $l->ssancar_inspector_name = $name ?: $l->ssancar_inspector_name;
                break;
            case 'inspection.reopened':
                // 되돌리기 — 패스 칸만 비운다(다시 큐에 들어갔다).
                $l->inspection_skipped_at = null;
                $l->inspection_skip_reason = null;
                $l->inspection_skip_memo = null;
                $l->inspection_skip_photo_url = null;
                break;
            case 'inspection.media_updated':
                return ['ignored_event', $l->id, null];   // 미디어는 폴러가 본다
        }
        $l->save();   // 큐 필드가 아니라 판정식·재발송은 안 걸린다

        return ['applied', $l->id, null];
    }

    private function time(mixed $v): Carbon
    {
        try {
            return $v ? Carbon::parse((string) $v)->timezone(config('app.timezone')) : now();
        } catch (\Throwable $e) {
            return now();
        }
    }
}

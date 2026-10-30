<?php

namespace App\Http\Controllers;

use App\Models\BoardAuditLog;
use App\Models\IntegrationEvent;
use App\Models\PurchaseListing;
use App\Services\WebPushService;
use App\Support\Plate;
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
    private const EVENTS = ['inspection.started', 'inspection.completed', 'inspection.skipped', 'inspection.reopened', 'inspection.media_updated',
        'inspection.urgent_decided'];   // P1(2026-10-29) — 긴급 요청 승인·기각(ssancar 업무관리자)

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
                // 되돌리기 — 다시 대기(queued)다. 패스 칸 + 지난 검차 시작·검차원도 비운다(안 비우면 「검차중」이 남는다 — 2026-10-23 데모 D6).
                $l->inspection_started_at = null;
                $l->ssancar_inspector_name = null;
                $l->inspection_skipped_at = null;
                $l->inspection_skip_reason = null;
                $l->inspection_skip_memo = null;
                $l->inspection_skip_photo_url = null;
                break;
            case 'inspection.urgent_decided':
                // P1 — ssancar 업무관리자의 긴급 결정. 승인이면 work_date 가 오늘(영업일)로 같이 온다. 결정자는 이름만 저장.
                $prevDecision = $l->inspection_urgent_decision;
                $l->inspection_urgent_decision = $request->boolean('approved') ? 'approved' : 'rejected';
                $l->inspection_urgent_decided_at = $at;
                $l->inspection_urgent_decided_by = ($by = $request->input('by.name')) ? mb_substr((string) $by, 0, 60) : null;
                $l->inspection_urgent_decision_note = ($n = $request->input('note')) ? mb_substr((string) $n, 0, 255) : null;
                break;
            case 'inspection.media_updated':
                return ['ignored_event', $l->id, null];   // 미디어는 폴러가 본다
        }
        // P1 — 모든 이벤트가 `work_date`(검차 예정일, ssancar 소유 값)를 실어 온다. 키가 있을 때만 받아 적는다(YYYY-MM-DD 아니면 비움).
        if ($request->has('work_date')) {
            $wd = $request->input('work_date');
            $l->inspection_work_date = (is_string($wd) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $wd)) ? $wd : null;
        }
        $l->save();   // 큐 필드가 아니라 판정식·재발송은 안 걸린다

        if ($event === 'inspection.urgent_decided') {
            // 결정도 변경 이력에(Jin 2026-10-29 「board 에서 일어나는 모든 건 로그」). 변경자 없음 = 「시스템」, 결정자 이름은 값에.
            BoardAuditLog::create([
                'user_id' => null, 'purchase_listing_id' => $l->id, 'action' => 'inspection_urgent_decided', 'field' => 'inspection_urgent_decision',
                'old_value' => $prevDecision ?? null,
                'new_value' => json_encode(['decision' => $l->inspection_urgent_decision, 'by' => $l->inspection_urgent_decided_by,
                    'note' => $l->inspection_urgent_decision_note, 'work_date' => $l->inspection_work_date?->toDateString()], JSON_UNESCAPED_UNICODE),
            ]);
        }

        if ($event === 'inspection.skipped') {
            $this->pushToCreator($l, 'board-hold-'.$l->id, function () use ($l) {
                $h = $l->ssancarHoldHint();

                return [__('nav.bell.push_title_hold'), $h ? __($h['key'], $h['params']) : ''];
            });
        } elseif ($event === 'inspection.urgent_decided') {
            // 보류 알림(board-hold-)과 다른 tag — 서로 덮어쓰지 않게.
            $this->pushToCreator($l, 'board-urgent-'.$l->id, function () use ($l) {
                $by = $l->inspection_urgent_decided_by ?: '—';
                if ($l->inspection_urgent_decision === 'approved') {
                    return [__('nav.bell.push_title_urgent_approved'), __('nav.bell.urgent_approved_body', ['date' => $l->inspection_work_date?->format('m-d') ?? '—', 'by' => $by])];
                }

                return [__('nav.bell.push_title_urgent_rejected'), __('nav.bell.urgent_rejected_body', ['by' => $by, 'note' => $l->inspection_urgent_decision_note ? ' · '.$l->inspection_urgent_decision_note : ''])];
            });
        }

        return ['applied', $l->id, null];
    }

    /**
     * 등록한 영업에게 웹푸시(진동·소리, 2026-10-23 Jin — 알림톡 말고). 종 배지는 종 컴포넌트가 board DB 에서 센다.
     * 푸시 실패가 웹훅 응답을 깨지 않는다(ssancar 재시도 유발 금지). 제목·본문은 **수신자 로케일**로 만든다 → `$text` 는 로케일을 바꾼 뒤 부른다.
     *
     * @param  callable(): array{0:string,1:string}  $text  [제목, 본문]
     */
    private function pushToCreator(PurchaseListing $l, string $tag, callable $text): void
    {
        $u = $l->creator;
        if (! $u || ! $u->is_active || ! WebPushService::configured()) {
            return;
        }
        $prev = app()->getLocale();
        try {
            app()->setLocale($u->locale ?: $prev);
            [$title, $body] = $text();
            app(WebPushService::class)->sendToUser($u, [
                'title' => $title.' — '.Plate::show($l->vehicle_number),
                'body' => $body,
                'url' => route('listings'),
                'tag' => $tag,
                'sticky' => true,
            ]);
        } catch (\Throwable $e) {
            report($e);
        } finally {
            app()->setLocale($prev);
        }
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

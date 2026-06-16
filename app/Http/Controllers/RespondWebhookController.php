<?php

namespace App\Http\Controllers;

use App\Models\IntegrationEvent;
use App\Models\PurchaseListing;
use App\Models\Scopes\SalesmanScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 연동 A — respond.io inbound webhook 수신.
 *
 * 현재 처리: event=buyer_verdict (바이어 수락/거절 회신 → buyer_verdict + 상태전이).
 * 매칭 = respond_conversation_id(스파인) [+ vehicle_number 보조 disambiguator].
 * 멱등 = integration_events.external_event_id. 인증 = X-Webhook-Secret 공유시크릿.
 *
 * 계약(받는 스펙 권위) = meetings/integration-A-design.md.
 * 설계 메모:
 *  - 무인증 컨텍스트(웹훅) → SalesmanScope 명시 제거(방어). buyer_verdict/status 변경은
 *    PurchaseListing updated 옵저버가 user_id=null(시스템) 감사로그로 남김(연동 B Job 과 동일).
 *  - verdict 는 status=awaiting_buyer 인 차에만 적용(전이 가드 안전). 그 외(draft 등)·무매칭·
 *    다중매칭은 mutation 없이 200 + 로그(노이즈/예외 방지, respond.io 재시도 유발 안 함).
 *  - 서명: 공유시크릿 헤더(상수시간 비교)가 1차. respond.io 가 동적 HMAC 지원 확인되면
 *    sha256 over-body 로 교체(연동 B 식). 지금은 단순/안전한 시크릿 헤더.
 */
class RespondWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        // ── 1. 인증: 공유 시크릿 헤더 (상수시간 비교) ──
        $secret = (string) config('services.respond_io.webhook_secret');
        if ($secret === '') {
            // 시크릿 미설정 = 오설정 → 무인증 수락 금지(보안). 안전밸브로 열어두지 않음.
            return response()->json(['status' => 'misconfigured'], 503);
        }
        $provided = (string) $request->header('X-Webhook-Secret', '');
        if (! hash_equals($secret, $provided)) {
            return response()->json(['status' => 'unauthorized'], 401);
        }

        $event = (string) $request->input('event', '');
        $externalEventId = $request->input('external_event_id');

        // ── 2. 멱등: 이미 처리한 external_event_id 면 no-op ──
        if ($externalEventId !== null && $externalEventId !== '') {
            $seen = IntegrationEvent::where('target', 'respond_io')
                ->where('external_event_id', $externalEventId)
                ->exists();
            if ($seen) {
                return response()->json(['status' => 'duplicate']);
            }
        }

        // ── 3. 이벤트 분기 ──
        [$status, $listingId, $error] = match ($event) {
            'buyer_verdict' => $this->handleVerdict($request),
            default => ['ignored_event', null, "unknown event: {$event}"],
        };

        // ── 4. append-only 로그 (inbound) ──
        IntegrationEvent::create([
            'direction' => 'inbound',
            'target' => 'respond_io',
            'event_type' => $event !== '' ? $event : 'unknown',
            'purchase_listing_id' => $listingId,
            'external_event_id' => $externalEventId,
            'request_payload' => $request->all(),
            'response_status' => 200,
            'response_body' => $status,
            'error' => $error,
        ]);

        return response()->json(['status' => $status, 'listing_id' => $listingId]);
    }

    /**
     * 바이어 수락/거절 회신 처리.
     *
     * @return array{0:string,1:?int,2:?string} [status, listing_id, error]
     */
    private function handleVerdict(Request $request): array
    {
        $convId = $request->input('respond_conversation_id');
        $verdict = $request->input('verdict');             // accepted | rejected
        $vehicleNumber = $request->input('vehicle_number'); // 선택 disambiguator

        if (empty($convId) || ! in_array($verdict, ['accepted', 'rejected'], true)) {
            return ['invalid_payload', null, 'missing respond_conversation_id or verdict'];
        }

        // verdict 적용 가능 상태(회신대기)인 차만. 무인증 → SalesmanScope 제거(방어).
        $query = PurchaseListing::withoutGlobalScope(SalesmanScope::class)
            ->where('respond_conversation_id', $convId)
            ->where('status', 'awaiting_buyer');

        if (! empty($vehicleNumber)) {
            $query->where('vehicle_number', $vehicleNumber);
        }

        $candidates = $query->get();

        if ($candidates->isEmpty()) {
            return ['no_match', null, 'no awaiting_buyer listing for conversation'];
        }
        if ($candidates->count() > 1) {
            // 다중 차 방 → 자동 귀속 불가. 상담원 보조(vehicle_number 동반 재전송) 필요.
            return ['ambiguous', null, 'multiple awaiting_buyer listings; vehicle_number required'];
        }

        $listing = $candidates->first();
        $listing->buyer_verdict = $verdict;
        $listing->respond_contact_id ??= $request->input('respond_contact_id');
        $listing->status = $verdict === 'accepted' ? 'accepted' : 'rejected';
        $listing->save();   // updating 가드 통과(accepted 는 verdict=accepted 전제 충족) + 옵저버 감사

        return ['applied:'.$verdict, $listing->id, null];
    }
}

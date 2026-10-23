<?php

namespace App\Jobs;

use App\Models\IntegrationEvent;
use App\Models\PurchaseListing;
use App\Support\SsancarInspection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * 검차 큐 연동 — board → ssancar.com 큐 등록(`queue.upsert`)·철회(`queue.withdraw`) (2026-10-23, 스펙 = ssancar
 * `_ops/dev/INSPECTION_PLAN.md` §8-2·§8-6). 발사 지점 = `PurchaseListing::booted()` 의 판정식 비교 하나뿐.
 *
 * - `event_id` 는 **디스패치 때** 정한다 → 재시도해도 같은 id(ssancar 가 처음 응답을 그대로 돌려준다).
 * - upsert 는 보낼 때의 행 값을 싣는다(대기 중 바뀐 값도 최신으로 간다). 🚫 소유자명·계좌·금액은 싣지 않는다(PII 최소화).
 * - 미설정(url·시크릿 없음)이면 조용히 no-op — 로컬·배포 전 박스에서 큐가 쌓이지 않게.
 */
class SendInspectionQueueEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public string $eventId;

    /**
     * @param  'upsert'|'withdraw'  $action
     * @param  ?string  $reason  withdraw = deleted|not_inspection_target|closed_in_board|status_override · upsert = reinspect(재검차) 또는 null
     */
    public function __construct(public int $listingId, public string $action, public ?string $reason = null)
    {
        $this->eventId = (string) Str::uuid();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(): void
    {
        if (! SsancarInspection::configured()) {
            return;
        }
        $l = PurchaseListing::withoutGlobalScopes()->withTrashed()->with('creator')->find($this->listingId);
        if (! $l) {
            return;
        }

        $payload = $this->action === 'upsert'
            ? array_filter([
                'event' => 'queue.upsert',
                'event_id' => $this->eventId,
                'listing_id' => $l->id,
                'plate' => $l->vehicle_number,
                'vin' => $l->vin,
                'region' => $l->region,
                'inspection_request' => $l->inspection_request,
                'salesman_name' => $l->creator?->name,
                'source_url' => $l->encar_url,
                'created_at' => $l->created_at?->timezone('Asia/Seoul')->toIso8601String(),
                'reason' => $this->reason,
            ], fn ($v) => $v !== null)
            : [
                'event' => 'queue.withdraw',
                'event_id' => $this->eventId,
                'listing_id' => $l->id,
                'reason' => $this->reason ?? 'closed_in_board',
            ];

        try {
            $response = SsancarInspection::post($payload);
        } catch (\Throwable $e) {
            // 연결 실패 — 큐 워커면 재시도(throw). 로컬 sync 큐면 화면 저장을 깨지 않게 삼킨다(일일 대사가 바로잡는다).
            if (config('queue.default') === 'sync') {
                return;
            }
            throw $e;
        }

        IntegrationEvent::create([
            'direction' => 'outbound',
            'target' => 'ssancar_inspection',
            'event_type' => $payload['event'],
            'purchase_listing_id' => $l->id,
            'external_event_id' => $this->eventId,
            'request_payload' => $payload,
            'response_status' => $response->status(),
            'response_body' => mb_substr((string) $response->body(), 0, 2000),
            'error' => $response->failed() ? 'HTTP '.$response->status() : null,
        ]);

        // 400 = 본문 오류 — 다시 보내도 같다(재시도 안 함, 로그만). 401·5xx·연결 실패는 재시도.
        if ($response->status() === 400) {
            return;
        }
        if ($response->failed() && config('queue.default') !== 'sync') {   // sync(로컬)은 로그만 — 화면 저장을 깨지 않는다
            throw new \RuntimeException("inspect-queue {$payload['event']} 실패 (listing {$l->id}): HTTP ".$response->status());
        }
    }
}

<?php

namespace App\Jobs;

use App\Models\IntegrationEvent;
use App\Models\PurchaseListing;
use App\Models\Scopes\SalesmanScope;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

/**
 * 연동 B — board `won` 차량을 car-erp 로 단방향 push (HMAC 서명).
 *
 * 가드: won 상태 + car_erp_vehicle_id null + car_erp.base_url 설정(안전밸브).
 * 멱등: car_erp_vehicle_id null 가드 + car-erp 측 VIN 사전조회(중복=스킵, 기존 id 반환).
 * 성공: 응답 vehicle_id → car_erp_vehicle_id 저장 → won→synced 전이.
 * 보내는 스펙(payload·HMAC 권위) = SKILLS.md §12. 받는 스펙 = car-erp docs.
 */
class SyncWonListingToCarErp implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public int $listingId) {}

    /** 재시도 백오프(초): 1분 → 5분 → 15분 → 30분 */
    public function backoff(): array
    {
        return [60, 300, 900, 1800];
    }

    public function handle(): void
    {
        $base = config('services.car_erp.base_url');
        $secret = config('services.car_erp.hmac_secret');

        // 안전밸브: 수신측(car-erp) 미설정이면 no-op — master 배포해도 안 터짐
        if (empty($base) || empty($secret)) {
            return;
        }

        // 큐 컨텍스트엔 Auth 없음 → SalesmanScope 명시 제거(방어)
        $l = PurchaseListing::withoutGlobalScope(SalesmanScope::class)->find($this->listingId);

        // 멱등/상태 가드: 이미 동기화됐거나 won 이 아니면 스킵
        if (! $l || $l->status !== 'won' || $l->car_erp_vehicle_id !== null) {
            return;
        }

        $payload = [
            'contract_version' => 1,
            'vin' => $l->vin,
            'vehicle_number' => $l->vehicle_number,
            'source' => $l->source,
            'final_price' => $l->final_price,
            'salesman_email' => $l->creator?->email,
            'car_erp_salesman_id' => $l->creator?->car_erp_salesman_id,
            'c_no' => $l->c_no,
            'payee_name' => $l->payee_name,
            'payee_bank' => $l->payee_bank,
            'payee_account' => $l->payee_account,
        ];

        // 서명 대상 = 직렬화된 raw body (car-erp 가 동일 바이트로 검증)
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha256', $body, $secret);

        $response = Http::timeout(20)
            ->withBody($body, 'application/json')
            ->withHeaders(['X-Board-Signature' => 'sha256='.$signature])
            ->post(rtrim($base, '/').'/api/internal/purchase-sync');

        // append-only 로그 — payee_account 는 민감값이라 마스킹 후 기록(§6e)
        $logged = $payload;
        if ($logged['payee_account'] !== null) {
            $logged['payee_account'] = '***';
        }
        IntegrationEvent::create([
            'direction' => 'outbound',
            'target' => 'car_erp',
            'event_type' => 'purchase_sync',
            'purchase_listing_id' => $l->id,
            'request_payload' => $logged,
            'response_status' => $response->status(),
            'response_body' => mb_substr((string) $response->body(), 0, 2000),
            'error' => $response->failed() ? 'HTTP '.$response->status() : null,
        ]);

        if ($response->failed()) {
            throw new \RuntimeException("purchase-sync 실패 (listing {$l->id}): HTTP ".$response->status());
        }

        $vehicleId = $response->json('vehicle_id');
        if (empty($vehicleId)) {
            throw new \RuntimeException("purchase-sync 응답에 vehicle_id 없음 (listing {$l->id})");
        }

        $l->car_erp_vehicle_id = (int) $vehicleId;
        $l->status = 'synced';   // won→synced (TRANSITIONS 허용)
        $l->save();
    }
}

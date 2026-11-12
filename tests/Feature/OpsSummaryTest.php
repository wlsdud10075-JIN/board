<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyOpsHmac;
use App\Models\IntegrationEvent;
use App\Models\PurchaseListing;
use App\Models\User;
use App\Services\Ops\OpsSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 관제센터 요약 API(2026-11-12) — 계약 = car-erp `docs/design/ops-control-center.md` §4.
 * 서명은 허브(car-erp `OpsHub`)와 같은 canonical 로 만든다.
 */
class OpsSummaryTest extends TestCase
{
    use RefreshDatabase;

    private const PATH = '/api/internal/ops/summary';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.ops.hmac_secret' => 'ops-sek']);
    }

    /** @return array<string,string> */
    private function signed(?int $ts = null, ?string $nonce = null, string $secret = 'ops-sek'): array
    {
        $ts ??= now()->timestamp;

        return [
            'X-Timestamp' => (string) $ts,
            'X-Nonce' => $nonce ?? (string) Str::uuid(),
            'X-Board-Signature' => 'sha256='.hash_hmac('sha256', VerifyOpsHmac::canonical('GET', self::PATH, [], $ts), $secret),
        ];
    }

    /** 허브처럼 본문 없는 GET — getJson() 은 GET 에도 본문 `[]` 을 실어 서명이 어긋난다. */
    private function hit(array $headers = []): TestResponse
    {
        return $this->withHeaders($headers + ['Accept' => 'application/json'])->get(self::PATH);
    }

    public function test_canonical_matches_erp_peer_format(): void
    {
        $this->assertSame("GET\n/api/internal/ops/summary?\n1700000000\n", VerifyOpsHmac::canonical('get', self::PATH, [], 1700000000));
    }

    public function test_signed_request_returns_contract_shape(): void
    {
        $res = $this->hit($this->signed())->assertOk();

        $res->assertJsonStructure([
            'generated_at', 'company',
            'server' => ['version', 'php', 'laravel', 'env', 'pending_migrations', 'disk_used_pct', 'db_mb', 'errors_today'],
            'routines', 'health', 'links', 'anomalies',
        ]);
        foreach ($res->json('anomalies') as $a) {
            $this->assertContains($a['level'], [1, 2, 3]);
            $this->assertIsString($a['kind']);
            $this->assertIsInt($a['count']);
            $this->assertIsString($a['text']);
        }
    }

    public function test_rejects_missing_secret_bad_signature_stale_time_and_replayed_nonce(): void
    {
        $this->hit($this->signed(secret: 'wrong'))->assertUnauthorized();
        $this->hit($this->signed(ts: now()->timestamp - 301))->assertUnauthorized();
        $this->hit()->assertUnauthorized();

        $h = $this->signed(nonce: 'once-1');
        $this->hit($h)->assertOk();
        $this->hit($h)->assertUnauthorized();   // 같은 nonce 재사용

        config(['services.ops.hmac_secret' => '']);
        $this->hit($this->signed(secret: ''))->assertUnauthorized();   // 시크릿 없으면 fail-closed
    }

    /** 🚫 키 원문을 싣지 않는다 — keys 는 있음/없음만. */
    public function test_response_never_contains_secret_values(): void
    {
        config([
            'services.car_erp.hmac_secret' => 'CARERP-SECRET-VALUE',
            'services.car_erp.read_hmac_secret' => 'READ-SECRET-VALUE',
            'services.ssancar_inspection.hmac_secret' => 'INSPECT-SECRET-VALUE',
        ]);
        $body = $this->hit($this->signed())->assertOk()->getContent();

        foreach (['CARERP-SECRET-VALUE', 'READ-SECRET-VALUE', 'INSPECT-SECRET-VALUE', 'ops-sek'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
        $this->assertTrue($this->hit($this->signed())->json('links.keys.car_erp'));
    }

    public function test_scheduler_heartbeat_controls_scheduler_anomaly(): void
    {
        $this->assertContains('스케줄러 멈춤', array_column(OpsSummary::anomalies(), 'kind'));

        OpsSummary::beat();
        $this->assertNotContains('스케줄러 멈춤', array_column(OpsSummary::anomalies(), 'kind'));

        Cache::forever(OpsSummary::HEARTBEAT_KEY, now()->subMinutes(6)->timestamp);
        $this->assertContains('스케줄러 멈춤', array_column(OpsSummary::anomalies(), 'kind'));
    }

    /** 재시도로 성공한 차는 세지 않는다 — 매물별 마지막 시도만 본다. */
    public function test_erp_sync_failure_counts_only_listings_whose_last_attempt_failed(): void
    {
        $u = User::create(['name' => 's', 'email' => 'ops@t.test', 'password' => 'password',
            'role' => 'sales', 'permission' => 'user', 'is_active' => true]);
        $mk = fn (string $plate, string $vin) => PurchaseListing::create([
            'created_by_user_id' => $u->id, 'source' => 'encar', 'vehicle_number' => $plate, 'vin' => $vin,
            'status' => 'synced', 'buyer_verdict' => 'accepted', 'car_erp_vehicle_id' => 1,
        ]);
        $ok = $mk('11가1111', 'OPSVIN0000001');
        $bad = $mk('22나2222', 'OPSVIN0000002');
        $ev = fn (PurchaseListing $l, ?int $status) => IntegrationEvent::create([
            'direction' => 'outbound', 'target' => 'car_erp', 'event_type' => 'purchase_sync',
            'purchase_listing_id' => $l->id, 'response_status' => $status, 'created_at' => now(),
        ]);
        $ev($ok, 500);
        $ev($ok, 201);    // 재시도 성공
        $ev($bad, 422);

        $row = collect(OpsSummary::anomalies())->firstWhere('kind', 'ERP 매입 연동 실패');
        $this->assertNotNull($row);
        $this->assertSame(1, $row['count']);
        $this->assertStringContainsString('22나2222', $row['text']);
        $this->assertStringNotContainsString('11가1111', $row['text']);
    }

    public function test_won_stalled_over_sixty_minutes(): void
    {
        $u = User::create(['name' => 's', 'email' => 'ops2@t.test', 'password' => 'password',
            'role' => 'sales', 'permission' => 'user', 'is_active' => true]);
        $l = PurchaseListing::create([
            'created_by_user_id' => $u->id, 'source' => 'encar', 'vehicle_number' => '33다3333', 'vin' => 'OPSVIN0000003',
            'status' => 'won', 'buyer_verdict' => 'accepted',
        ]);
        PurchaseListing::withoutGlobalScopes()->whereKey($l->id)->update(['updated_at' => now()->subMinutes(61)]);

        $row = collect(OpsSummary::anomalies())->firstWhere('kind', '구매확정 정체');
        $this->assertSame(1, $row['count'] ?? null);
    }

    /** GPU 는 요청 안에서 부르지 않는다 — 캐시가 15분 넘게 실패일 때만 경보. */
    public function test_gpu_down_reads_cached_ping_only(): void
    {
        config(['board.payee_extract.enabled' => true]);
        $kinds = fn () => array_column(OpsSummary::anomalies(), 'kind');

        $this->assertNotContains('OCR·챗봇 GPU 응답 없음', $kinds());   // 기록 없음 = 판정 보류

        Cache::forever(OpsSummary::GPU_PING_KEY, ['ok' => false, 'at' => now()->timestamp, 'last_ok_at' => now()->subMinutes(5)->timestamp]);
        $this->assertNotContains('OCR·챗봇 GPU 응답 없음', $kinds());   // 잠깐의 실패는 넘김

        Cache::forever(OpsSummary::GPU_PING_KEY, ['ok' => false, 'at' => now()->timestamp, 'last_ok_at' => now()->subMinutes(20)->timestamp]);
        $this->assertContains('OCR·챗봇 GPU 응답 없음', $kinds());
    }
}

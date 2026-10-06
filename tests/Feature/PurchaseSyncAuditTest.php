<?php

namespace Tests\Feature;

use App\Models\IntegrationEvent;
use App\Models\PurchaseListing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `board:purchase-sync-audit` — 연동 B 감사(읽기 전용). 판정은 SQL, 수정 0, exit 는 항상 0.
 */
class PurchaseSyncAuditTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private string $out;

    protected function setUp(): void
    {
        parent::setUp();
        $this->out = sys_get_temp_dir().'/psa-'.uniqid().'/audit.json';
        config(['services.car_erp.base_url' => 'https://x.test', 'services.car_erp.read_hmac_secret' => 'sek']);
    }

    protected function tearDown(): void
    {
        @unlink($this->out);
        @rmdir(dirname($this->out));
        parent::tearDown();
    }

    private function owner(): User
    {
        return User::firstOr(fn () => User::create([
            'name' => 'sales', 'email' => 'psa@t.test', 'password' => 'password',
            'role' => 'sales', 'permission' => 'user', 'is_active' => true, 'email_verified_at' => now(),
        ]));
    }

    private function mkListing(array $attr = []): PurchaseListing
    {
        return PurchaseListing::create(array_merge([
            'created_by_user_id' => $this->owner()->id,
            'source' => 'encar',
            'vehicle_number' => '12가'.(1000 + (++$this->seq)),
            'vin' => 'PSA'.str_pad((string) $this->seq, 10, '0', STR_PAD_LEFT),
            'status' => 'draft',
            'buyer_verdict' => 'none',
            'car_erp_buyer_id' => 7,
        ], $attr));
    }

    /** 명령을 돌리고 파일에 남은 JSON 을 배열로 돌려준다. */
    private function audit(): array
    {
        $this->artisan('board:purchase-sync-audit', ['--json' => $this->out])->assertExitCode(0);
        $this->assertFileExists($this->out);

        return json_decode(file_get_contents($this->out), true);
    }

    /** 정체 = won + erp id 없음 + **60분 초과**. 59분은 아직 재시도 창 안이라 안 센다. */
    public function test_stalled_uses_sixty_minute_boundary_and_attaches_last_event(): void
    {
        Http::fake(['*/vehicles/exists*' => Http::response(['exists' => [], 'missing' => []], 200)]);

        Carbon::setTestNow(now()->subMinutes(61));
        $old = $this->mkListing(['status' => 'won', 'vehicle_number' => '11가0061']);
        Carbon::setTestNow(now()->addMinutes(2));   // 이벤트는 매물 뒤에
        IntegrationEvent::create(['direction' => 'outbound', 'target' => 'car_erp', 'event_type' => 'purchase_sync',
            'purchase_listing_id' => $old->id, 'response_status' => 500, 'error' => 'HTTP 500']);
        IntegrationEvent::create(['direction' => 'outbound', 'target' => 'car_erp', 'event_type' => 'purchase_sync',
            'purchase_listing_id' => $old->id, 'response_status' => 502, 'error' => 'HTTP 502']);
        Carbon::setTestNow();

        Carbon::setTestNow(now()->subMinutes(59));
        $this->mkListing(['status' => 'won', 'vehicle_number' => '11가0059']);
        Carbon::setTestNow();

        Carbon::setTestNow(now()->subMinutes(120));
        $this->mkListing(['status' => 'won', 'car_erp_vehicle_id' => 9, 'vehicle_number' => '11가0120']);   // erp id 있으면 정체 아님
        Carbon::setTestNow();

        $r = $this->audit();

        $this->assertSame(1, $r['counts']['stalled']);
        $this->assertSame(['11가0061'], array_column($r['stalled'], 'vehicle_number'));
        $this->assertSame(502, $r['stalled'][0]['last_event']['response_status']);   // 최신 1행
        $this->assertSame('HTTP 502', $r['stalled'][0]['last_event']['error']);
    }

    public function test_stalled_without_events_has_null_last_event(): void
    {
        Http::fake(['*' => Http::response(['exists' => [], 'missing' => []], 200)]);
        Carbon::setTestNow(now()->subMinutes(90));
        $this->mkListing(['status' => 'won']);
        Carbon::setTestNow();

        $r = $this->audit();

        $this->assertNull($r['stalled'][0]['last_event']);
    }

    public function test_integrity_counts_both_directions(): void
    {
        Http::fake(['*' => Http::response(['exists' => [5], 'missing' => []], 200)]);
        $this->mkListing(['status' => 'synced']);                              // synced 인데 erp id 없음
        $this->mkListing(['status' => 'synced']);
        $this->mkListing(['status' => 'accepted', 'car_erp_vehicle_id' => 3]); // erp id 있는데 synced 아님
        $this->mkListing(['status' => 'synced', 'car_erp_vehicle_id' => 5]);   // 정상

        $r = $this->audit();

        $this->assertSame(2, $r['counts']['synced_without_erp_id']);
        $this->assertSame(1, $r['counts']['erp_id_without_synced']);
    }

    public function test_missing_in_erp_lists_only_ids_erp_reports_missing(): void
    {
        Http::fake(['*/vehicles/exists*' => Http::response(['exists' => [5], 'missing' => [6]], 200)]);
        $this->mkListing(['status' => 'synced', 'car_erp_vehicle_id' => 5]);
        $gone = $this->mkListing(['status' => 'synced', 'car_erp_vehicle_id' => 6, 'vehicle_number' => '22나0006']);
        $this->mkListing(['status' => 'won', 'car_erp_vehicle_id' => 7]);      // synced 아니면 대조 대상 아님

        $r = $this->audit();

        $this->assertSame(1, $r['counts']['missing_in_erp']);
        $this->assertSame([['listing_id' => $gone->id, 'vehicle_number' => '22나0006', 'car_erp_vehicle_id' => 6]], $r['missing_in_erp']);
        $this->assertSame(0, $r['counts']['deleted_in_erp']);   // 옛 ERP 응답(deleted 키 없음) = 0 — 하위호환
        $this->assertSame([], $r['deleted_in_erp']);
        $this->assertSame([], $r['errors']);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/api/internal/board/vehicles/exists')
            && $req['ids'] === '5,6');
    }

    /** ERP 에서 소프트 삭제한 차(`deleted`)는 전송 누락이 아니다 — missing 에 섞지 않고 따로 센다(2026-10-06 Jin). */
    public function test_deleted_in_erp_is_counted_separately_from_missing(): void
    {
        Http::fake(['*/vehicles/exists*' => Http::response(['exists' => [5], 'missing' => [6], 'deleted' => [7]], 200)]);
        $this->mkListing(['status' => 'synced', 'car_erp_vehicle_id' => 5]);
        $gone = $this->mkListing(['status' => 'synced', 'car_erp_vehicle_id' => 6, 'vehicle_number' => '22나0006']);
        $erased = $this->mkListing(['status' => 'synced', 'car_erp_vehicle_id' => 7, 'vehicle_number' => '22나0007']);

        $r = $this->audit();

        $this->assertSame(1, $r['counts']['missing_in_erp']);
        $this->assertSame(1, $r['counts']['deleted_in_erp']);
        $this->assertSame([['listing_id' => $gone->id, 'vehicle_number' => '22나0006', 'car_erp_vehicle_id' => 6]], $r['missing_in_erp']);
        $this->assertSame([['listing_id' => $erased->id, 'vehicle_number' => '22나0007', 'car_erp_vehicle_id' => 7]], $r['deleted_in_erp']);
    }

    /** ERP 가 죽어도 명령은 성공(exit 0)하고 그 항목만 null + errors — 침묵은 "이상 없음" 으로 읽히기 때문. */
    public function test_erp_failure_nulls_missing_but_keeps_the_rest(): void
    {
        Http::fake(['*' => Http::response('boom', 500)]);
        $this->mkListing(['status' => 'synced', 'car_erp_vehicle_id' => 5]);
        Carbon::setTestNow(now()->subMinutes(90));
        $this->mkListing(['status' => 'won']);
        Carbon::setTestNow();

        $r = $this->audit();

        $this->assertNull($r['missing_in_erp']);
        $this->assertNull($r['counts']['missing_in_erp']);
        $this->assertNull($r['deleted_in_erp']);               // 못 봤으면 deleted 도 0 이 아니라 null
        $this->assertNull($r['counts']['deleted_in_erp']);
        $this->assertNotEmpty($r['errors']);
        $this->assertStringContainsString('HTTP 500', $r['errors'][0]);
        $this->assertSame(1, $r['counts']['stalled']);
    }

    public function test_not_configured_is_reported_as_error_not_as_zero_missing(): void
    {
        config(['services.car_erp.base_url' => '', 'services.car_erp.read_hmac_secret' => '']);
        $this->mkListing(['status' => 'synced', 'car_erp_vehicle_id' => 5]);

        $r = $this->audit();

        $this->assertNull($r['missing_in_erp']);
        $this->assertStringContainsString('not_configured', $r['errors'][0]);
    }

    /** 고정 형태 — ERP 08:00 보고가 이 키들을 읽는다. 키가 바뀌면 그쪽이 조용히 0 을 읽는다. */
    public function test_json_shape_is_fixed_and_stdout_matches_file(): void
    {
        Http::fake(['*' => Http::response(['exists' => [], 'missing' => []], 200)]);

        $this->artisan('board:purchase-sync-audit', ['--json' => $this->out])
            ->expectsOutputToContain('"generated_at"')
            ->assertExitCode(0);
        $r = json_decode(file_get_contents($this->out), true);

        $this->assertSame(['generated_at', 'counts', 'stalled', 'missing_in_erp', 'deleted_in_erp', 'errors'], array_keys($r));
        $this->assertSame(['stalled', 'synced_without_erp_id', 'erp_id_without_synced', 'missing_in_erp', 'deleted_in_erp'], array_keys($r['counts']));
        $this->assertSame([], $r['stalled']);
        $this->assertSame([], $r['missing_in_erp']);   // synced 매물 0 → ERP 호출 없이 빈 배열
        $this->assertSame([], $r['deleted_in_erp']);
        Http::assertNothingSent();
    }

    /** 감사 명령은 아무것도 고치지 않는다. */
    public function test_command_writes_nothing_to_listings(): void
    {
        Http::fake(['*' => Http::response(['exists' => [], 'missing' => [5]], 200)]);
        Carbon::setTestNow(now()->subMinutes(90));
        $won = $this->mkListing(['status' => 'won']);
        Carbon::setTestNow();
        $synced = $this->mkListing(['status' => 'synced', 'car_erp_vehicle_id' => 5]);
        $before = [$won->fresh()->toArray(), $synced->fresh()->toArray()];

        $this->audit();

        $this->assertSame($before, [$won->fresh()->toArray(), $synced->fresh()->toArray()]);
    }
}

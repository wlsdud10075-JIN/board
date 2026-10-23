<?php

namespace Tests\Feature;

use App\Jobs\SendInspectionQueueEvent;
use App\Models\IntegrationEvent;
use App\Models\PurchaseListing;
use App\Models\User;
use App\Support\SsancarInspection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 검차 큐 연동(ssancar.com, 2026-10-23) — 판정식 등록·철회 / 서명 전송 / 웹훅 수신 / 재검차 요청 / 일일 대사.
 * 스펙 = ssancar `_ops/dev/INSPECTION_PLAN.md` §8.
 */
class InspectionQueueTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function mkUser(string $role = 'sales'): User
    {
        return User::create([
            'name' => $role.$this->seq, 'email' => $role.(++$this->seq).'@t.test', 'password' => 'password',
            'role' => $role, 'permission' => 'user', 'is_active' => true, 'email_verified_at' => now(),
        ]);
    }

    private function mkListing(array $attr = []): PurchaseListing
    {
        return PurchaseListing::create(array_merge([
            'created_by_user_id' => $this->mkUser()->id,
            'source' => 'encar', 'origin' => 'encar',
            'vehicle_number' => '12가'.(1000 + (++$this->seq)),
            'vin' => 'VIN'.str_pad((string) $this->seq, 10, '0', STR_PAD_LEFT),
            'region' => '경기 안산시',
            'status' => 'draft',
        ], $attr));
    }

    private function configure(): void
    {
        config(['services.ssancar_inspection' => [
            'url' => 'https://ssc.test/page/api_inspect_queue.php', 'hmac_secret' => 'hs', 'webhook_secret' => 'ws', 'tenant' => 'heymanboard',
        ]]);
    }

    // ───────────── B1 판정식 ─────────────

    public function test_draft_creation_upserts_and_self_inspection_does_not(): void
    {
        Bus::fake([SendInspectionQueueEvent::class]);
        $a = $this->mkListing();
        $this->mkListing(['origin' => 'self_inspection', 'status' => 'accepted', 'buyer_verdict' => 'accepted']);
        $c = $this->mkListing(['origin' => null]);   // origin NULL 도 검차 대상

        Bus::assertDispatchedTimes(SendInspectionQueueEvent::class, 2);
        Bus::assertDispatched(fn (SendInspectionQueueEvent $j) => $j->listingId === $a->id && $j->action === 'upsert');
        Bus::assertDispatched(fn (SendInspectionQueueEvent $j) => $j->listingId === $c->id && $j->action === 'upsert');
    }

    public function test_leaving_draft_withdraws_with_reason(): void
    {
        $closed = $this->mkListing();
        $self = $this->mkListing();
        $override = $this->mkListing();
        $deleted = $this->mkListing();
        Bus::fake([SendInspectionQueueEvent::class]);

        $closed->update(['status' => 'inspected']);
        $self->update(['origin' => 'self_inspection']);
        $override->allowManagerOverride = true;
        $override->update(['status' => 'rejected']);
        $deleted->delete();

        foreach ([[$closed, 'closed_in_board'], [$self, 'not_inspection_target'], [$override, 'status_override'], [$deleted, 'deleted']] as [$l, $r]) {
            Bus::assertDispatched(fn (SendInspectionQueueEvent $j) => $j->listingId === $l->id && $j->action === 'withdraw' && $j->reason === $r);
        }
    }

    public function test_queue_field_change_resends_and_other_change_does_not(): void
    {
        $l = $this->mkListing();
        Bus::fake([SendInspectionQueueEvent::class]);

        $l->update(['inspection_note' => '메모']);          // 큐 필드 아님
        Bus::assertNotDispatched(SendInspectionQueueEvent::class);

        $l->update(['region' => '부산광역시']);
        Bus::assertDispatched(fn (SendInspectionQueueEvent $j) => $j->action === 'upsert');

        // 셀프검차 → 일반으로 되돌리면 재진입
        $l->update(['origin' => 'self_inspection']);
        $l->update(['origin' => 'encar']);
        Bus::assertDispatched(fn (SendInspectionQueueEvent $j) => $j->action === 'withdraw');
        Bus::assertDispatchedTimes(SendInspectionQueueEvent::class, 3);
    }

    // ───────────── B1 전송 ─────────────

    public function test_upsert_is_signed_and_has_no_pii(): void
    {
        $this->configure();
        Http::fake(['*' => Http::response(['ok' => true, 'listing_id' => 1, 'status' => 'queued', 'wr_id' => null], 200)]);
        $l = $this->mkListing(['owner_name' => '홍길동', 'car_cost' => 9000000, 'inspection_request' => '하부 사진']);

        // sync 큐 — create 에서 이미 한 번 보냈다
        Http::assertSent(function ($req) use ($l) {
            $ts = $req->header('X-Timestamp')[0];
            $body = $req->body();
            $d = json_decode($body, true);

            return $req->url() === 'https://ssc.test/page/api_inspect_queue.php'
                && $req->header('X-Board-Tenant')[0] === 'heymanboard'
                && $req->header('X-Board-Signature')[0] === 'sha256='.hash_hmac('sha256', $ts.'.'.$body, 'hs')
                && $d['event'] === 'queue.upsert' && $d['listing_id'] === $l->id && $d['plate'] === $l->vehicle_number
                && $d['region'] === '경기 안산시' && $d['inspection_request'] === '하부 사진' && isset($d['event_id'], $d['created_at'])
                && str_ends_with($d['created_at'], '+09:00')
                && ! str_contains($body, '홍길동') && ! array_key_exists('car_cost', $d) && ! array_key_exists('owner_name', $d);
        });
        $this->assertDatabaseHas('integration_events', ['target' => 'ssancar_inspection', 'event_type' => 'queue.upsert', 'purchase_listing_id' => $l->id]);
    }

    /** 삭제·전이된 차의 upsert 는 안 보낸다(2026-10-23 로컬 시험에서 삭제된 차를 보냈다). withdraw 는 보낸다. */
    public function test_upsert_skips_listing_no_longer_target(): void
    {
        $l = $this->mkListing();
        $l->delete();
        $this->configure();
        Http::fake(['*' => Http::response(['ok' => true, 'status' => 'withdrawn'], 200)]);

        (new SendInspectionQueueEvent($l->id, 'upsert'))->handle();
        Http::assertNothingSent();

        (new SendInspectionQueueEvent($l->id, 'withdraw', 'deleted'))->handle();
        Http::assertSent(fn ($req) => json_decode($req->body(), true)['event'] === 'queue.withdraw');
    }

    /** 지오게이트 「공사 중」 HTML 이 HTTP 200 으로 와도 실패다(큐 워커면 재시도 = throw). */
    public function test_html_200_is_failure_and_retried(): void
    {
        $l = $this->mkListing();
        $this->configure();
        config(['queue.default' => 'database']);
        Http::fake(['*' => Http::response('<html>공사 중</html>', 200)]);

        $this->expectException(\RuntimeException::class);
        try {
            (new SendInspectionQueueEvent($l->id, 'upsert'))->handle();
        } finally {
            $this->assertDatabaseHas('integration_events', ['target' => 'ssancar_inspection', 'error' => 'not_ok_body']);
        }
    }

    public function test_unconfigured_is_noop(): void
    {
        Http::fake();
        $this->mkListing();
        Http::assertNothingSent();
    }

    public function test_tenant_falls_back_to_instance_map(): void
    {
        config(['services.ssancar_inspection.tenant' => null, 'app.name' => 'board-ssancar']);
        $this->assertSame('ssancarboard', SsancarInspection::tenant());
    }

    // ───────────── B2 웹훅 ─────────────

    private function hook(array $body, string $secret = 'ws')
    {
        return $this->postJson('/api/webhooks/ssancar-inspection', $body, ['X-Webhook-Secret' => $secret]);
    }

    public function test_webhook_rejects_bad_secret_and_unconfigured(): void
    {
        $this->hook(['event' => 'inspection.started'])->assertStatus(503);
        $this->configure();
        $this->hook(['event' => 'inspection.started'], 'nope')->assertStatus(401);
    }

    public function test_webhook_started_completed_skipped_reopened_update_hint(): void
    {
        $l = $this->mkListing();   // 설정 전에 만든다(생성 upsert 가 실제로 나가지 않게)
        $this->configure();
        Bus::fake([SendInspectionQueueEvent::class]);
        $base = ['listing_id' => $l->id, 'plate' => $l->vehicle_number, 'inspector' => ['mb_id' => 'k1', 'name' => '김검차']];

        $this->hook($base + ['event' => 'inspection.started', 'event_id' => 'e1', 'at' => '2026-10-23T09:10:00+09:00'])->assertOk();
        $l->refresh();
        $this->assertSame('김검차', $l->ssancar_inspector_name);
        $this->assertSame('inspection.hold_started', $l->ssancarHoldHint()['key']);

        $this->hook($base + ['event' => 'inspection.skipped', 'event_id' => 'e2', 'at' => '2026-10-23T09:30:00+09:00',
            'reason' => 'no_car', 'memo' => '차주 부재', 'photo_url' => 'javascript:alert(1)'])->assertOk();
        $l->refresh();
        $this->assertSame('draft', $l->status);   // 상태는 그대로
        $this->assertNull($l->inspection_skip_photo_url);   // http(s) 아닌 링크는 버린다
        $this->assertStringContainsString('차 없음/연락 불가', __($l->ssancarHoldHint()['key'], $l->ssancarHoldHint()['params']));

        $this->hook(['event' => 'inspection.reopened', 'event_id' => 'e3', 'listing_id' => $l->id, 'at' => '2026-10-23T10:00:00+09:00', 'by' => 'admin'])->assertOk();
        $this->assertNull($l->fresh()->inspection_skipped_at);

        $this->hook($base + ['event' => 'inspection.completed', 'event_id' => 'e4', 'at' => '2026-10-23T10:30:00+09:00', 'wr_id' => 5123, 'photos' => 30, 'videos' => 2])->assertOk();
        $l->refresh();
        $this->assertSame(5123, (int) $l->ssancar_inspected_wr_id);
        $this->assertSame('draft', $l->status);   // inspected 전이는 폴러 소유
        $this->assertSame('inspection.hold_inspected', $l->ssancarHoldHint()['key']);

        Bus::assertNotDispatched(SendInspectionQueueEvent::class);   // 웹훅 칸 갱신이 큐 재발송을 일으키지 않는다
    }

    public function test_webhook_is_idempotent_and_unknown_skip_code_shows_raw(): void
    {
        $this->configure();
        Bus::fake([SendInspectionQueueEvent::class]);
        $l = $this->mkListing();
        $body = ['event' => 'inspection.skipped', 'event_id' => 'dup1', 'listing_id' => $l->id, 'reason' => 'weird_code', 'at' => now()->toIso8601String()];

        $this->hook($body)->assertJson(['status' => 'applied']);
        $this->hook($body)->assertJson(['status' => 'duplicate']);
        $this->assertSame(1, IntegrationEvent::where('target', 'ssancar_inspection')->where('direction', 'inbound')->count());
        $this->assertStringContainsString('weird_code', __($l->fresh()->ssancarHoldHint()['key'], $l->fresh()->ssancarHoldHint()['params']));

        $this->hook(['event' => 'inspection.started', 'event_id' => 'x2', 'listing_id' => 999999])->assertJson(['status' => 'no_match']);
    }

    // ───────────── B3 재검차 요청 ─────────────

    public function test_reinspection_request_clears_skip_and_upserts_reinspect(): void
    {
        $kim = $this->mkUser();
        $l = $this->mkListing(['created_by_user_id' => $kim->id]);
        $l->forceFill(['inspection_skipped_at' => now(), 'inspection_skip_reason' => 'bad_condition'])->save();
        Bus::fake([SendInspectionQueueEvent::class]);
        $this->actingAs($kim);

        Volt::test('listings.index')->call('openEdit', $l->id)
            ->assertSee(__('listings.drawer.reinspect_btn'))
            ->call('requestReinspection')->assertHasNoErrors();

        $this->assertNull($l->fresh()->inspection_skipped_at);
        Bus::assertDispatched(fn (SendInspectionQueueEvent $j) => $j->listingId === $l->id && $j->action === 'upsert' && $j->reason === 'reinspect');
    }

    public function test_reinspection_not_offered_without_skip_or_to_others(): void
    {
        $l = $this->mkListing();
        $this->actingAs($this->mkUser());   // 남의 차(영업 격리라 열리지도 않음) → 본인 차 보류 없음으로 확인
        $own = $this->mkListing(['created_by_user_id' => auth()->id()]);

        Volt::test('listings.index')->call('openEdit', $own->id)->assertDontSee(__('listings.drawer.reinspect_btn'));
    }

    // ───────────── B4 일일 대사 ─────────────

    public function test_reconcile_sends_targets_and_resends_missing_only(): void
    {
        $a = $this->mkListing();
        $b = $this->mkListing();
        $this->mkListing(['status' => 'inspected']);
        $this->configure();
        Http::fake(['*' => Http::response(['ok' => true, 'withdrawn' => 1, 'missing_listing_ids' => [$b->id, 999999], 'held' => false], 200)]);
        Bus::fake([SendInspectionQueueEvent::class]);

        $this->artisan('board:inspection-reconcile')->assertSuccessful();

        Http::assertSent(fn ($req) => json_decode($req->body(), true)['event'] === 'queue.reconcile'
            && json_decode($req->body(), true)['listing_ids'] === [$a->id, $b->id]
            && json_decode($req->body(), true)['count'] === 2);
        Bus::assertDispatchedTimes(SendInspectionQueueEvent::class, 1);   // 999999 는 대상이 아니라 안 보낸다
        Bus::assertDispatched(fn (SendInspectionQueueEvent $j) => $j->listingId === $b->id);
    }

    public function test_reconcile_never_sends_empty_list(): void
    {
        $this->configure();
        Http::fake();
        $this->artisan('board:inspection-reconcile')->assertSuccessful();
        Http::assertNothingSent();
    }
}

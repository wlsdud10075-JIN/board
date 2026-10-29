<?php

namespace Tests\Feature;

use App\Jobs\SendInspectionQueueEvent;
use App\Models\BoardAuditLog;
use App\Models\IntegrationEvent;
use App\Models\PurchaseListing;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\WebPushService;
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
            'schedule_p1' => true,   // P1 긴급 버튼 게이트(운영 기본 off)
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
                && ! str_contains($body, '홍길동') && ! array_key_exists('car_cost', $d) && ! array_key_exists('owner_name', $d)
                && ! array_key_exists('urgent', $d) && ! array_key_exists('address', $d);   // P1 — 일상 upsert 엔 긴급 플래그·주소가 없다
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
        $this->assertNull($l->fresh()->inspection_started_at);   // 되돌리기 = 다시 대기

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
        $this->assertNull($l->fresh()->inspection_started_at);   // 「검차중」이 남지 않는다
        Bus::assertDispatched(fn (SendInspectionQueueEvent $j) => $j->listingId === $l->id && $j->action === 'upsert' && $j->reason === 'reinspect');
    }

    public function test_reinspection_not_offered_without_skip_or_to_others(): void
    {
        $l = $this->mkListing();
        $this->actingAs($this->mkUser());   // 남의 차(영업 격리라 열리지도 않음) → 본인 차 보류 없음으로 확인
        $own = $this->mkListing(['created_by_user_id' => auth()->id()]);

        Volt::test('listings.index')->call('openEdit', $own->id)->assertDontSee(__('listings.drawer.reinspect_btn'));
    }

    // ───────────── 데모 피드백(2026-10-23 Jin) — 지역 미지정 · 칸 분리 · 보류 알림 ─────────────

    public function test_no_region_hint_only_when_integration_on(): void
    {
        $l = $this->mkListing(['region' => null]);
        $this->assertNotSame('inspection.hold_no_region', $l->ssancarHoldHint()['key']);   // 꺼진 박스 = 종전 문구

        $this->configure();
        $this->assertSame('inspection.hold_no_region', $l->ssancarHoldHint()['key']);
        $this->assertTrue($l->ssancarHoldHint()['alert']);   // 눈에 띄게(빨간 굵은 글씨 + ⚠)
    }

    public function test_list_shows_inspection_request_and_memo_separately(): void
    {
        $kim = $this->mkUser();
        $this->mkListing(['created_by_user_id' => $kim->id, 'inspection_request' => '하부 부식 확인 부탁', 'inspection_note' => '검차원 메모']);
        $this->actingAs($kim);

        Volt::test('listings.index')
            ->assertSee(__('listings.table.inspection_request'))->assertSee('하부 부식 확인 부탁')
            ->assertSee(__('listings.table.inspection_note'))->assertSee('검차원 메모');
    }

    /** 패스 → 등록한 영업에게 웹푸시 1건(알림톡 아님) + 종 배지·패널. */
    public function test_skipped_pushes_to_creator_and_shows_in_bell(): void
    {
        $kim = $this->mkUser();
        $l = $this->mkListing(['created_by_user_id' => $kim->id]);
        $this->configure();
        config(['board.push.vapid_public' => 'pub', 'board.push.vapid_private' => 'priv', 'board.push.vapid_subject' => 'mailto:t@t.test']);
        $sent = [];
        app()->instance(WebPushService::class, new class($sent) extends WebPushService
        {
            public function __construct(private array &$sent) {}

            protected function deliver(PushSubscription $sub, string $json): ?bool
            {
                $this->sent[] = json_decode($json, true);

                return true;
            }
        });
        PushSubscription::create(['user_id' => $kim->id, 'endpoint' => 'https://push.example/x',
            'endpoint_hash' => PushSubscription::hashEndpoint('https://push.example/x'), 'p256dh' => 'k', 'auth_token' => 'a']);
        $kim->forceFill(['portal_seen_at' => now()->subHour()])->save();

        $this->hook(['event' => 'inspection.skipped', 'event_id' => 'p1', 'listing_id' => $l->id, 'reason' => 'bad_condition',
            'memo' => '하부 부식', 'at' => now()->toIso8601String()])->assertOk();

        $this->assertCount(1, $sent);
        $this->assertStringContainsString($l->vehicle_number, $sent[0]['title']);
        $this->assertStringContainsString('차량 상태 불량', $sent[0]['body']);

        $this->actingAs($kim);
        Volt::test('notify.bell')->call('refresh')->assertSet('unread', 1)
            ->call('openPanel')->assertSee(__('nav.bell.holds'))->assertSee('하부 부식');
    }

    // ───────────── P1 검차 예정일 · 긴급 요청 (2026-10-29, 스펙 §10-10) ─────────────

    /** upsert 응답의 `work_date`·`urgent_decision` 을 받아 적는다(키 없으면 그대로). 일상 재발송(지역 수정)은 기각된 긴급이 있어도 `urgent` 를 싣지 않는다. */
    public function test_upsert_response_stores_schedule_and_routine_resend_has_no_urgent(): void
    {
        $this->configure();
        // 첫 upsert(생성) = 예정일·결정 null 응답 / 재발송(지역 수원) = 옛 ssancar 응답(키 없음) → 저장값 그대로여야 한다.
        Http::fake(fn ($req) => str_contains($req->body(), '수원')
            ? Http::response(['ok' => true, 'status' => 'queued'], 200)
            : Http::response(['ok' => true, 'listing_id' => 1, 'status' => 'queued', 'wr_id' => null, 'work_date' => '2026-11-02', 'urgent_decision' => null], 200));
        $l = $this->mkListing();
        $l->refresh();
        $this->assertSame('2026-11-02', $l->inspection_work_date->toDateString());
        $this->assertNull($l->inspection_urgent_decision);

        $l->forceFill(['inspection_urgent_decision' => 'rejected'])->save();
        $l->update(['region' => '경기 수원시']);   // 큐 필드 변경 = 재발송
        Http::assertSent(fn ($r) => ($d = json_decode($r->body(), true)) && ($d['region'] ?? null) === '경기 수원시'
            && ! array_key_exists('urgent', $d) && ! array_key_exists('urgent_note', $d));
        $l->refresh();
        $this->assertSame('2026-11-02', $l->inspection_work_date->toDateString());
        $this->assertSame('rejected', $l->inspection_urgent_decision);
    }

    /** 드로어 「긴급 검차 요청」 = upsert `urgent:true`(+사유) · 낙관적 pending → 응답이 덮는다 · 보류 차는 `reason=reinspect` 와 한 번에. */
    public function test_urgent_request_sends_flag_and_note_and_reinspects_held_car(): void
    {
        $kim = $this->mkUser();
        $l = $this->mkListing(['created_by_user_id' => $kim->id]);   // 설정 전 → 생성 upsert 안 나감
        $this->configure();
        Http::fake(['*' => Http::response(['ok' => true, 'listing_id' => $l->id, 'status' => 'queued', 'wr_id' => null, 'work_date' => '2026-11-02', 'urgent_decision' => 'pending'], 200)]);
        $this->actingAs($kim);

        Volt::test('listings.index')->call('openEdit', $l->id)->assertSee(__('listings.drawer.urgent_btn'))
            ->set('e_urgent_note', '바이어 오늘 결정')->call('requestUrgent')->assertHasNoErrors();
        Http::assertSent(fn ($r) => ($d = json_decode($r->body(), true)) && $d['event'] === 'queue.upsert'
            && $d['urgent'] === true && $d['urgent_note'] === '바이어 오늘 결정' && ! array_key_exists('reason', $d));
        $l->refresh();
        $this->assertSame('pending', $l->inspection_urgent_decision);
        $this->assertNotNull($l->inspection_urgent_requested_at);
        $this->assertSame('바이어 오늘 결정', $l->inspection_urgent_note);
        $this->assertSame('2026-11-02', $l->inspection_work_date->toDateString());
        // 감사 행(Jin 2026-10-29 「board 에서 일어나는 모든 건 로그」) — 누가·사유
        $this->assertDatabaseHas('board_audit_logs', ['user_id' => $kim->id, 'purchase_listing_id' => $l->id, 'action' => 'inspection_urgent_request', 'field' => 'inspection_urgent_request']);
        $this->assertStringContainsString('바이어 오늘 결정', BoardAuditLog::where('action', 'inspection_urgent_request')->latest('id')->value('new_value'));
        // 요청 중엔 버튼 대신 「승인 대기」(ssancar 가 pending·approved 는 무시한다)
        Volt::test('listings.index')->call('openEdit', $l->id)->assertDontSee(__('listings.drawer.urgent_btn'))->assertSee('업무관리자 승인 대기');

        // 기각된 뒤 보류까지 걸린 차 — 긴급 한 번에 재검차(reason=reinspect) + 패스·검차중 칸 비움
        $l->forceFill(['inspection_urgent_decision' => 'rejected', 'inspection_skipped_at' => now(), 'inspection_skip_reason' => 'no_car',
            'inspection_started_at' => now(), 'ssancar_inspector_name' => '김검차'])->save();
        Volt::test('listings.index')->call('openEdit', $l->id)->assertSee(__('listings.drawer.urgent_btn'))->call('requestUrgent')->assertHasNoErrors();
        Http::assertSent(fn ($r) => ($d = json_decode($r->body(), true)) && ($d['reason'] ?? null) === 'reinspect' && $d['urgent'] === true && ! array_key_exists('urgent_note', $d));
        $l->refresh();
        $this->assertNull($l->inspection_skipped_at);
        $this->assertNull($l->ssancar_inspector_name);
        $this->assertNull($l->inspection_urgent_decided_by);
    }

    /** 버튼 게이트 — 오늘 목록(work_date <= 오늘, 이월 포함)·요청 중·승인·연동 꺼짐이면 숨김, 기각이면 다시 보임(사유 표시). */
    public function test_urgent_button_hidden_when_due_today_or_decided_and_shown_after_rejection(): void
    {
        $kim = $this->mkUser();
        $l = $this->mkListing(['created_by_user_id' => $kim->id]);
        $this->configure();
        Bus::fake([SendInspectionQueueEvent::class]);
        $this->actingAs($kim);

        $l->forceFill(['inspection_work_date' => today()->toDateString()])->save();
        Volt::test('listings.index')->call('openEdit', $l->id)->assertDontSee(__('listings.drawer.urgent_btn'))->assertSee(__('listings.drawer.urgent_today'));
        $l->forceFill(['inspection_work_date' => today()->subDay()->toDateString()])->save();   // 이월 차 = 아직 오늘 목록(iq_due <=)
        Volt::test('listings.index')->call('openEdit', $l->id)->assertDontSee(__('listings.drawer.urgent_btn'));
        $l->forceFill(['inspection_work_date' => today()->addDay()->toDateString(), 'inspection_urgent_decision' => 'approved'])->save();
        Volt::test('listings.index')->call('openEdit', $l->id)->assertDontSee(__('listings.drawer.urgent_btn'))->assertDontSee(__('listings.drawer.urgent_today'));
        $l->forceFill(['inspection_urgent_decision' => 'rejected', 'inspection_urgent_decided_by' => '박관리', 'inspection_urgent_decided_at' => now(),
            'inspection_urgent_decision_note' => '내일도 충분'])->save();
        Volt::test('listings.index')->call('openEdit', $l->id)->assertSee(__('listings.drawer.urgent_btn'))->assertSee('내일도 충분')->assertSee(today()->addDay()->format('m-d'))->assertSee(__('listings.table.work_date'));

        config(['services.ssancar_inspection.schedule_p1' => false]);   // P1 게이트 off(운영 기본) — 수신은 되지만 버튼·오늘 안내 없음
        Volt::test('listings.index')->call('openEdit', $l->id)->assertDontSee(__('listings.drawer.urgent_btn'))->assertSee('내일도 충분');
        config(['services.ssancar_inspection.schedule_p1' => true, 'services.ssancar_inspection.url' => '']);   // 연동 꺼진 박스 — 버튼 없음
        Volt::test('listings.index')->call('openEdit', $l->id)->assertDontSee(__('listings.drawer.urgent_btn'));
    }

    /** 웹훅 — 모든 이벤트의 `work_date` 를 받아 적고, `inspection.urgent_decided` 는 칸 갱신 + 등록 영업 웹푸시 + 종. 큐 재발송은 없다. */
    public function test_webhook_work_date_and_urgent_decided_update_fields_push_and_bell(): void
    {
        $kim = $this->mkUser();
        $l = $this->mkListing(['created_by_user_id' => $kim->id]);
        $this->configure();
        Bus::fake([SendInspectionQueueEvent::class]);
        config(['board.push.vapid_public' => 'pub', 'board.push.vapid_private' => 'priv', 'board.push.vapid_subject' => 'mailto:t@t.test']);
        $sent = [];
        app()->instance(WebPushService::class, new class($sent) extends WebPushService
        {
            public function __construct(private array &$sent) {}

            protected function deliver(PushSubscription $sub, string $json): ?bool
            {
                $this->sent[] = json_decode($json, true);

                return true;
            }
        });
        PushSubscription::create(['user_id' => $kim->id, 'endpoint' => 'https://push.example/y',
            'endpoint_hash' => PushSubscription::hashEndpoint('https://push.example/y'), 'p256dh' => 'k', 'auth_token' => 'a']);
        $kim->forceFill(['portal_seen_at' => now()->subHour()])->save();

        $this->hook(['event' => 'inspection.started', 'event_id' => 'w1', 'listing_id' => $l->id, 'at' => now()->toIso8601String(), 'work_date' => '2026-11-03',
            'inspector' => ['mb_id' => 'k1', 'name' => '김검차']])->assertOk();
        $this->assertSame('2026-11-03', $l->fresh()->inspection_work_date->toDateString());
        $this->assertCount(0, $sent);   // 시작은 푸시 없음

        $l->forceFill(['inspection_urgent_decision' => 'pending', 'inspection_urgent_requested_at' => now()])->save();
        $this->hook(['event' => 'inspection.urgent_decided', 'event_id' => 'w2', 'listing_id' => $l->id, 'plate' => $l->vehicle_number, 'at' => now()->toIso8601String(),
            'work_date' => today()->toDateString(), 'approved' => true, 'by' => ['mb_id' => 'm1', 'name' => '박관리'], 'note' => null])->assertJson(['status' => 'applied']);
        $l->refresh();
        $this->assertSame('approved', $l->inspection_urgent_decision);
        $this->assertSame('박관리', $l->inspection_urgent_decided_by);
        $this->assertNull($l->inspection_urgent_decision_note);
        $this->assertTrue($l->inspectionDueToday());
        $this->assertCount(1, $sent);
        $this->assertStringContainsString(__('nav.bell.push_title_urgent_approved'), $sent[0]['title']);
        $this->assertStringContainsString($l->vehicle_number, $sent[0]['title']);

        $this->actingAs($kim);
        Volt::test('notify.bell')->call('refresh')->assertSet('unread', 1)
            ->call('openPanel')->assertSee(__('nav.bell.urgents'))->assertSee('박관리')->assertSee(__('nav.bell.urgent_chip_approved'));

        $this->hook(['event' => 'inspection.urgent_decided', 'event_id' => 'w3', 'listing_id' => $l->id, 'at' => now()->toIso8601String(), 'work_date' => '2026-11-03',
            'approved' => false, 'by' => ['mb_id' => 'm1', 'name' => '박관리'], 'note' => '내일 처리'])->assertOk();
        $l->refresh();
        $this->assertSame('rejected', $l->inspection_urgent_decision);
        $this->assertSame('내일 처리', $l->inspection_urgent_decision_note);
        $this->assertSame('2026-11-03', $l->inspection_work_date->toDateString());   // 기각 = 예정일 그대로
        $this->assertCount(2, $sent);
        $this->assertStringContainsString('내일 처리', $sent[1]['body']);
        // 결정 감사 행 2개(승인·기각) — 변경자 없음(시스템), 결정자·사유는 값에
        $rows = BoardAuditLog::where('purchase_listing_id', $l->id)->where('action', 'inspection_urgent_decided')->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertNull($rows[0]->user_id);
        $this->assertSame('pending', $rows[0]->old_value);
        $this->assertStringContainsString('"approved"', $rows[0]->new_value);
        $this->assertStringContainsString('내일 처리', $rows[1]->new_value);
        Bus::assertNotDispatched(SendInspectionQueueEvent::class);
    }

    /** 목록 「검차 예정일」 칸 — 현지확인대기 차만 날짜, 그 외는 —. */
    public function test_list_shows_work_date_column(): void
    {
        $kim = $this->mkUser();
        $l = $this->mkListing(['created_by_user_id' => $kim->id]);
        $l->forceFill(['inspection_work_date' => '2026-11-05'])->save();
        $this->actingAs($kim);
        Volt::test('listings.index')->assertSee(__('listings.table.work_date'))->assertSee('11-05');
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

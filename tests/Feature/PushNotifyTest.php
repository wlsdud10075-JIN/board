<?php

namespace Tests\Feature;

use App\Models\PushSubscription;
use App\Models\User;
use App\Services\WebPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 웹푸시 — §11 요청 결과(ERP 확인·거절)를 화면이 꺼진 폰에도 (2026-10-12 Jin).
 * 외부 푸시 서비스는 부르지 않는다 — `WebPushService::deliver` 를 가짜로 바꿔 payload 만 잡는다.
 */
class PushNotifyTest extends TestCase
{
    use RefreshDatabase;

    private function mkSales(): User
    {
        return User::create([
            'name' => 'sales', 'email' => 'kim@t.test', 'password' => 'password', 'role' => 'sales',
            'permission' => 'user', 'is_active' => true, 'email_verified_at' => now(),
            'car_erp_salesman_email' => 'kim@ce.test',
        ]);
    }

    private function configurePush(): void
    {
        config([
            'services.car_erp.base_url' => 'https://carerp.test', 'services.car_erp.read_hmac_secret' => 'rs',
            'board.push.vapid_public' => 'pub', 'board.push.vapid_private' => 'priv', 'board.push.vapid_subject' => 'mailto:t@t.test',
        ]);
    }

    /** deliver() 를 기록만 하는 가짜로. 반환 = 잡힌 payload 들(참조). */
    private function fakePush(array &$sent, ?bool $result = true): void
    {
        app()->instance(WebPushService::class, new class($sent, $result) extends WebPushService
        {
            public function __construct(private array &$sent, private ?bool $result) {}

            protected function deliver(PushSubscription $sub, string $json): ?bool
            {
                $this->sent[] = ['sub' => $sub->id, 'payload' => json_decode($json, true)];

                return $this->result;
            }
        });
    }

    private function subscribe(User $u, string $endpoint = 'https://push.example/abc'): PushSubscription
    {
        return PushSubscription::create([
            'user_id' => $u->id, 'endpoint' => $endpoint, 'endpoint_hash' => PushSubscription::hashEndpoint($endpoint),
            'p256dh' => 'k', 'auth_token' => 'a',
        ]);
    }

    private function erpFixture(): array
    {
        return ['count' => 2, 'requests' => [
            ['batch_id' => 'R1', 'type' => 'purchase_balance', 'status' => 'rejected', 'amount_krw' => 7000000, 'requested_at' => '2026-10-02T09:30:00+09:00',
                'vehicles' => [['vehicle_number' => '22나2222', 'status' => 'rejected', 'amount_krw' => 7000000,
                    'resolved_at' => '2026-10-02T10:00:00+09:00', 'resolved_by' => '재무김', 'reject_reason' => '바이어 입금 미확인']]],
            ['batch_id' => 'R2', 'type' => 'purchase_deposit', 'status' => 'done', 'amount_krw' => 3000000, 'requested_at' => '2026-10-01T09:00:00+09:00',
                'vehicles' => [['vehicle_number' => '22나2222', 'status' => 'done', 'amount_krw' => 3000000, 'confirmed_at' => '2026-10-01T10:00:00+09:00']]],
        ]];
    }

    public function test_subscribe_endpoint_stores_once_per_endpoint_and_reassigns_to_current_user(): void
    {
        $a = $this->mkSales();
        $body = ['endpoint' => 'https://push.example/abc', 'keys' => ['p256dh' => 'P', 'auth' => 'A']];

        $this->actingAs($a)->postJson(route('push.subscribe'), $body)->assertOk();
        $this->actingAs($a)->postJson(route('push.subscribe'), $body)->assertOk();
        $this->assertSame(1, PushSubscription::count());

        // 공용 기기에서 다른 사람이 로그인 → 같은 endpoint 는 그 사람 것이 된다(이전 사용자 결과가 이 기기로 가면 안 된다)
        $b = User::create(['name' => 'lee', 'email' => 'lee@t.test', 'password' => 'password', 'role' => 'sales', 'permission' => 'user', 'is_active' => true]);
        $this->actingAs($b)->postJson(route('push.subscribe'), $body)->assertOk();
        $this->assertSame($b->id, PushSubscription::first()->user_id);

        $this->actingAs($b)->postJson(route('push.unsubscribe'), ['endpoint' => $body['endpoint']])->assertOk();
        $this->assertSame(0, PushSubscription::count());
    }

    public function test_subscribe_requires_login_and_valid_shape(): void
    {
        $this->postJson(route('push.subscribe'), ['endpoint' => 'https://push.example/x', 'keys' => ['p256dh' => 'P', 'auth' => 'A']])->assertUnauthorized();
        $this->actingAs($this->mkSales())->postJson(route('push.subscribe'), ['endpoint' => 'not-a-url'])->assertStatus(422);
    }

    /** 첫 실행은 커서만 찍고 과거 이력을 보내지 않는다 — 켜는 순간 수십 건이 울리면 바로 끈다. */
    public function test_first_run_sets_cursor_and_sends_nothing(): void
    {
        $this->configurePush();
        $u = $this->mkSales();
        $this->subscribe($u);
        Http::fake(['*' => Http::response($this->erpFixture(), 200)]);
        $sent = [];
        $this->fakePush($sent);

        $this->artisan('board:push-request-results')->assertSuccessful();

        $this->assertSame([], $sent);
        $this->assertNotNull($u->refresh()->push_notified_at);
        Http::assertNothingSent();
    }

    /** 커서 이후 끝난 결과만 — 거절은 사유·처리자를 본문에, 10-01 확인(커서 전)은 안 보낸다. 커서는 앞으로 간다. */
    public function test_sends_only_results_after_cursor_with_reason(): void
    {
        $this->configurePush();
        $u = $this->mkSales();
        $u->forceFill(['push_notified_at' => '2026-10-01 12:00:00'])->save();
        $this->subscribe($u, 'https://push.example/phone');
        $this->subscribe($u, 'https://push.example/pc');   // 기기 2대 → 같은 결과가 둘 다에
        Http::fake(['*' => Http::response($this->erpFixture(), 200)]);
        $sent = [];
        $this->fakePush($sent);

        $this->artisan('board:push-request-results')->assertSuccessful();

        $this->assertCount(2, $sent);
        $p = $sent[0]['payload'];
        $this->assertStringContainsString('22나2222', $p['title']);
        $this->assertStringContainsString(__('nav.bell.push_title_rejected'), $p['title']);
        $this->assertStringContainsString('7,000,000', $p['body']);
        $this->assertStringContainsString('바이어 입금 미확인', $p['body']);
        $this->assertStringContainsString('재무김', $p['body']);
        $this->assertTrue($p['sticky']);
        $this->assertSame(route('portal'), $p['url']);
        $this->assertTrue($u->refresh()->push_notified_at->gt(now()->subMinute()));

        // ERP 호출은 status=all + since(커서 2분 전) — 전체 이력을 매분 끌어오지 않는다
        Http::assertSent(fn ($req) => str_contains($req->url(), '/board/requests')
            && str_contains($req->url(), 'status=all') && str_contains($req->url(), 'since='));

        // 다음 분 — 같은 결과는 다시 안 간다(커서가 지나갔다)
        $sent = [];
        $this->fakePush($sent);
        $this->artisan('board:push-request-results')->assertSuccessful();
        $this->assertSame([], $sent);
    }

    /** ERP 조회가 실패하면 커서를 옮기지 않는다 — 다음 분에 같은 구간을 다시 본다. */
    public function test_keeps_cursor_when_erp_fails(): void
    {
        $this->configurePush();
        $u = $this->mkSales();
        $u->forceFill(['push_notified_at' => '2026-10-01 12:00:00'])->save();
        $this->subscribe($u);
        Http::fake(['*' => Http::response('nope', 500)]);
        $sent = [];
        $this->fakePush($sent);

        $this->artisan('board:push-request-results')->assertSuccessful();

        $this->assertSame([], $sent);
        $this->assertSame('2026-10-01 12:00:00', $u->refresh()->push_notified_at->format('Y-m-d H:i:s'));
    }

    /** 만료 구독(푸시 서비스 404/410)은 지운다 — 안 지우면 매분 실패 요청을 보낸다. */
    public function test_expired_subscription_is_removed(): void
    {
        $this->configurePush();
        $u = $this->mkSales();
        $u->forceFill(['push_notified_at' => '2026-10-01 12:00:00'])->save();
        $this->subscribe($u);
        Http::fake(['*' => Http::response($this->erpFixture(), 200)]);
        $sent = [];
        $this->fakePush($sent, false);   // false = 만료

        $this->artisan('board:push-request-results')->assertSuccessful();

        $this->assertCount(1, $sent);
        $this->assertSame(0, PushSubscription::count());
    }

    /** VAPID 미설정이면 ERP 도 부르지 않는다(no-op). */
    public function test_noop_without_vapid(): void
    {
        config(['services.car_erp.base_url' => 'https://carerp.test', 'services.car_erp.read_hmac_secret' => 'rs',
            'board.push.vapid_public' => null, 'board.push.vapid_private' => null, 'board.push.vapid_subject' => null]);
        $u = $this->mkSales();
        $u->forceFill(['push_notified_at' => '2026-10-01 12:00:00'])->save();
        $this->subscribe($u);
        Http::fake();

        $this->artisan('board:push-request-results')->assertSuccessful();
        Http::assertNothingSent();
    }

    /** 서비스워커는 공개 경로에 있어야 한다(scope '/'). fetch 핸들러는 두지 않는다(Livewire 요청 가로채기 금지). */
    public function test_service_worker_exists_without_fetch_handler(): void
    {
        $sw = file_get_contents(public_path('sw.js'));
        $this->assertStringContainsString("addEventListener('push'", $sw);
        $this->assertStringContainsString("addEventListener('notificationclick'", $sw);
        $this->assertStringNotContainsString("addEventListener('fetch'", $sw);
    }
}

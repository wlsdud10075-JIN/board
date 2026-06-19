<?php

namespace Tests\Feature;

use App\Jobs\SendOfferToBuyer;
use App\Jobs\SyncWonListingToCarErp;
use App\Models\BoardAuditLog;
use App\Models\ExchangeRate;
use App\Models\InspectionAssignment;
use App\Models\IntegrationEvent;
use App\Models\PromotionRequest;
use App\Models\PurchaseListing;
use App\Models\User;
use App\Services\CarErpReadService;
use App\Services\ExchangeRateService;
use App\Services\ListingEnrichment;
use App\Services\RespondIoService;
use App\Services\VerdictService;
use App\Support\ListingLink;
use App\Support\TimeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\TestCase;

class BoardTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function mkUser(string $role, ?string $email = null, string $permission = 'user'): User
    {
        return User::create([
            'name' => $role,
            'email' => $email ?? $role.(++$this->seq).'@t.test',
            'password' => 'password',
            'role' => $role,
            'permission' => $permission,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function mkListing(User $owner, array $attr = []): PurchaseListing
    {
        return PurchaseListing::create(array_merge([
            'created_by_user_id' => $owner->id,
            'source' => 'encar',
            'vehicle_number' => '12가'.(1000 + (++$this->seq)),
            'vin' => 'VIN'.str_pad((string) $this->seq, 10, '0', STR_PAD_LEFT),
            'status' => 'draft',
            'buyer_verdict' => 'none',
        ], $attr));
    }

    private function assertItThrows(callable $fn): void
    {
        try {
            $fn();
            $this->fail('예외가 발생해야 합니다.');
        } catch (\Throwable $e) {
            $this->assertTrue(true);
        }
    }

    public function test_dashboard_redirects_by_role(): void
    {
        $this->actingAs($this->mkUser('sales'))->get('/dashboard')->assertRedirect('/listings');
        $this->actingAs($this->mkUser('inspection'))->get('/dashboard')->assertRedirect('/inspection');
        $this->actingAs($this->mkUser('auction'))->get('/dashboard')->assertRedirect('/auction');
        $this->actingAs($this->mkUser('manager'))->get('/dashboard')->assertRedirect('/manage');
    }

    public function test_sales_is_isolated_to_own_listings(): void
    {
        $kim = $this->mkUser('sales');
        $lee = $this->mkUser('sales');
        $this->mkListing($kim);

        $this->actingAs($lee);
        $this->assertSame(0, PurchaseListing::count());

        $this->actingAs($kim);
        $this->assertSame(1, PurchaseListing::count());
    }

    public function test_inspection_sees_all_listings(): void
    {
        $kim = $this->mkUser('sales');
        $this->mkListing($kim);
        $this->mkListing($kim);

        $this->actingAs($this->mkUser('inspection'));
        $this->assertSame(2, PurchaseListing::count());
    }

    public function test_role_middleware_guards_views(): void
    {
        $this->actingAs($this->mkUser('sales'))->get('/manage')->assertForbidden();

        $m = $this->mkUser('manager');
        $this->actingAs($m)->get('/listings')->assertOk();
        $this->actingAs($m)->get('/inspection')->assertOk();
        $this->actingAs($m)->get('/auction')->assertOk();
        $this->actingAs($m)->get('/manage')->assertOk();
    }

    public function test_timegate_locks_auction_registration_on_weekday_after_deadline(): void
    {
        // 월요일 09:00 (마감 전) → 미잠금
        Carbon::setTestNow('2026-06-08 09:00:00');
        $this->assertFalse(TimeGate::auctionRegistrationLocked());

        // 월요일 11:00 (마감 후) → 잠금
        Carbon::setTestNow('2026-06-08 11:00:00');
        $this->assertTrue(TimeGate::auctionRegistrationLocked());

        // 토요일 → 잠금 미적용 (lock_at NULL)
        Carbon::setTestNow('2026-06-13 15:00:00');
        $this->assertFalse(TimeGate::auctionRegistrationLocked());
        $this->assertNull(TimeGate::auctionLockAt());

        Carbon::setTestNow();
    }

    public function test_adds_listing_through_volt_component(): void
    {
        $kim = $this->mkUser('sales');
        $this->actingAs($kim);

        Volt::test('listings.index')
            ->set('source', 'encar')
            ->set('vehicle_number', '99가9999')
            ->set('vin', 'TESTVIN0001')
            ->set('car_cost', '13000000')
            ->set('discount_rate', '0')
            ->set('shipping_usd', 1640)
            ->set('payee_name', '판매상사')
            ->set('payee_account', '110-222-333444')
            ->call('save')
            ->assertHasNoErrors();

        $l = PurchaseListing::where('vin', 'TESTVIN0001')->where('created_by_user_id', $kim->id)->first();
        $this->assertNotNull($l);
        // 영업이 미리 입력한 입금정보가 저장(계좌번호 암호화)
        $this->assertSame('판매상사', $l->payee_name);
        $this->assertSame('110-222-333444', $l->payee_account);
        $this->assertNotSame('110-222-333444', \DB::table('purchase_listings')->where('id', $l->id)->value('payee_account'));
        // 차량금액 = 13,000,000 − 0% + 440,000(매도비) = 13,440,000
        // 최종금액 = 13,440,000 + 1640 × 1380(임시환율) = 15,703,200 스냅샷
        $this->assertSame(13440000 + 1640 * (int) config('board.default_krw_per_usd'), $l->final_price);
    }

    public function test_state_machine_blocks_invalid_transition_but_manager_overrides(): void
    {
        $l = $this->mkListing($this->mkUser('sales'), ['status' => 'draft']);

        $this->assertItThrows(fn () => $l->update(['status' => 'accepted']));

        $l->allowManagerOverride = true;
        $l->update(['status' => 'awaiting_buyer']);
        $this->assertSame('awaiting_buyer', $l->fresh()->status);
    }

    public function test_identity_columns_are_locked(): void
    {
        $l = $this->mkListing($this->mkUser('sales'));
        $this->assertItThrows(fn () => $l->update(['vin' => 'CHANGED']));
        $this->assertItThrows(fn () => $l->update(['vehicle_number' => 'CHANGED']));
    }

    public function test_accepted_requires_buyer_acceptance(): void
    {
        $l = $this->mkListing($this->mkUser('sales'), ['status' => 'awaiting_buyer', 'buyer_verdict' => 'pending']);

        $this->assertItThrows(fn () => $l->update(['status' => 'accepted']));

        $l->buyer_verdict = 'accepted';
        $l->save();
        $l->update(['status' => 'accepted']);
        $this->assertSame('accepted', $l->fresh()->status);
    }

    public function test_inspection_send_to_buyer_transitions_to_awaiting(): void
    {
        $l = $this->mkListing($this->mkUser('sales'), ['status' => 'draft']);
        $this->actingAs($this->mkUser('inspection'));

        // 수동씬: 전달 선택 → 저장 눌러야 반영
        Volt::test('inspection.index')
            ->call('openDrawer', $l->id)
            ->set('final_price', '13200000')
            ->set('buyer_name', '드라간')
            ->set('sendSelected', true)
            ->call('save')
            ->assertHasNoErrors();

        $l->refresh();
        $this->assertSame('awaiting_buyer', $l->status);
        $this->assertSame('pending', $l->buyer_verdict);
        $this->assertSame(13200000, $l->final_price);
    }

    public function test_region_assignment_role_limit_and_inspector_filter(): void
    {
        $mgr = $this->mkUser('manager');
        $sales = $this->mkUser('sales');
        $i1 = $this->mkUser('inspection');
        $i2 = $this->mkUser('inspection');
        $i3 = $this->mkUser('inspection');
        $i4 = $this->mkUser('inspection');
        $this->mkListing($sales, ['status' => 'draft', 'region' => '경기 수원시']);
        $today = now()->toDateString();

        $this->actingAs($mgr);

        // 정상 배정
        Volt::test('inspection.index')
            ->set('assignRegion', '경기 수원시')->set('assignUserId', $i1->id)
            ->call('assign')->assertHasNoErrors();
        $this->assertDatabaseHas('inspection_assignments', ['date' => $today, 'region' => '경기 수원시', 'user_id' => $i1->id]);

        // 영업 계정은 배정 불가 (현지확인 role 만)
        Volt::test('inspection.index')
            ->set('assignRegion', '경기 수원시')->set('assignUserId', $sales->id)
            ->call('assign')->assertHasErrors('assignUserId');

        // 지역당 최대 3인
        InspectionAssignment::create(['date' => $today, 'region' => '경기 수원시', 'user_id' => $i2->id]);
        InspectionAssignment::create(['date' => $today, 'region' => '경기 수원시', 'user_id' => $i3->id]);
        Volt::test('inspection.index')
            ->set('assignRegion', '경기 수원시')->set('assignUserId', $i4->id)
            ->call('assign')->assertHasErrors('assignUserId');
        $this->assertSame(3, InspectionAssignment::where('region', '경기 수원시')->count());

        // 미배정 현지확인 담당자는 해당 지역이 안 보임
        $this->actingAs($i4);
        Volt::test('inspection.index')->assertDontSee('경기 수원시');

        // 배정된 담당자는 보임
        $this->actingAs($i1);
        Volt::test('inspection.index')->assertSee('경기 수원시');
    }

    public function test_exchange_rate_service_fetches_and_falls_back(): void
    {
        Http::fake([
            '*from=USD*' => Http::response(['rates' => ['KRW' => 1400.50]]),
            '*from=EUR*' => Http::response(['rates' => ['KRW' => 1550.00]]),
        ]);

        $svc = app(ExchangeRateService::class);
        $svc->refresh();

        $this->assertSame(1401, $svc->krwPerUsd());   // round(1400.50)
        $this->assertSame(1550, $svc->krwPerEur());
        $this->assertDatabaseHas('exchange_rates', ['currency' => 'USD']);

        // 캐시 없으면 config 폴백
        ExchangeRate::query()->delete();
        $this->assertSame((int) config('board.default_krw_per_usd'), $svc->krwPerUsd());
    }

    public function test_exchange_rate_fetch_failure_keeps_fallback(): void
    {
        Http::fake(['*' => Http::response('', 500)]);
        $svc = app(ExchangeRateService::class);
        $svc->refresh();   // 실패해도 예외 없이
        $this->assertSame((int) config('board.default_krw_per_usd'), $svc->krwPerUsd());
        $this->assertDatabaseMissing('exchange_rates', ['currency' => 'USD']);
    }

    public function test_lazy_refresh_runs_only_when_stale(): void
    {
        config(['board.rate_auto_refresh' => true]);   // 테스트 기본 false → 이 테스트만 켬
        Cache::flush();
        Http::fake([
            '*from=USD*' => Http::response(['rates' => ['KRW' => 1400]]),
            '*from=EUR*' => Http::response(['rates' => ['KRW' => 1600]]),
        ]);
        $svc = app(ExchangeRateService::class);

        $this->assertTrue($svc->isStale());   // 캐시 없음 → stale
        $svc->refreshIfStale();
        $this->assertSame(1400, $svc->krwPerUsd());
        $this->assertFalse($svc->isStale());  // 방금 갱신 → 신선(TTL 1h)
    }

    public function test_auction_conclude_marks_won(): void
    {
        $l = $this->mkListing($this->mkUser('sales'), [
            'status' => 'accepted', 'buyer_verdict' => 'accepted',
            'source' => 'auction', 'final_price' => 9000000,
        ]);
        $this->actingAs($this->mkUser('auction'));

        Volt::test('auction.index')
            ->call('conclude', $l->id, 'won')
            ->assertHasNoErrors();

        $this->assertSame('won', $l->fresh()->status);
    }

    public function test_auction_row_detail_drawer_and_conclude(): void
    {
        $l = $this->mkListing($this->mkUser('sales'), [
            'status' => 'accepted', 'buyer_verdict' => 'accepted',
            'source' => 'auction', 'final_price' => 9000000, 'region' => '부산광역시',
        ]);
        $this->actingAs($this->mkUser('auction'));

        Volt::test('auction.index')
            ->call('openDetail', $l->id)
            ->assertSee('부산광역시')
            ->assertSee($l->vehicle_number)
            ->call('conclude', $l->id, 'won')
            ->assertHasNoErrors();

        $this->assertSame('won', $l->fresh()->status);
    }

    public function test_auction_won_saves_encrypted_payee(): void
    {
        $l = $this->mkListing($this->mkUser('sales'), [
            'status' => 'accepted', 'buyer_verdict' => 'accepted', 'source' => 'auction', 'final_price' => 9000000,
        ]);
        $this->actingAs($this->mkUser('auction'));

        Volt::test('auction.index')
            ->call('openDetail', $l->id)
            ->set('payee_name', '홍판매')
            ->set('payee_bank', '국민')
            ->set('payee_account', '123-456-7890')
            ->call('conclude', $l->id, 'won')
            ->assertHasNoErrors();

        $l->refresh();
        $this->assertSame('won', $l->status);
        $this->assertSame('홍판매', $l->payee_name);
        $this->assertSame('123-456-7890', $l->payee_account);   // cast 로 복호화

        // at-rest 암호화 확인: DB raw 값에는 평문이 없어야
        $raw = \DB::table('purchase_listings')->where('id', $l->id)->value('payee_account');
        $this->assertNotNull($raw);
        $this->assertNotSame('123-456-7890', $raw);
    }

    public function test_manager_edit_writes_audit_log_and_overrides(): void
    {
        $l = $this->mkListing($this->mkUser('sales'), ['status' => 'draft', 'expected_price' => 1000000]);
        $this->actingAs($this->mkUser('manager'));

        Volt::test('manage.index')
            ->call('openEdit', $l->id)
            ->set('expected_price', '2000000')
            ->set('owner_name', '김차주')          // 확장 필드
            ->set('payee_account', '333-444-5555')  // 암호화 + 마스킹 감사
            ->set('status', 'won') // 전이행렬 무시 override
            ->call('save')
            ->assertHasNoErrors();

        $l->refresh();
        $this->assertSame(2000000, $l->expected_price);
        $this->assertSame('won', $l->status);
        $this->assertSame('김차주', $l->owner_name);
        $this->assertSame('333-444-5555', $l->payee_account);   // 복호화 읽기

        $this->assertDatabaseHas('board_audit_logs', ['purchase_listing_id' => $l->id, 'field' => 'expected_price']);
        $this->assertDatabaseHas('board_audit_logs', ['purchase_listing_id' => $l->id, 'field' => 'owner_name', 'new_value' => '김차주']);
        $this->assertDatabaseHas('board_audit_logs', ['purchase_listing_id' => $l->id, 'field' => 'status', 'action' => 'status_change']);
        // 계좌번호는 감사로그에 마스킹(***)으로만
        $this->assertDatabaseHas('board_audit_logs', ['purchase_listing_id' => $l->id, 'field' => 'payee_account', 'new_value' => '***']);
    }

    public function test_sales_can_edit_own_listing(): void
    {
        $kim = $this->mkUser('sales');
        $l = $this->mkListing($kim, ['source' => 'encar', 'expected_price' => 1000000]);
        $this->actingAs($kim);

        Volt::test('listings.index')
            ->call('openEdit', $l->id)
            ->set('e_car_cost', '2222222')
            ->set('e_discount_rate', '0')
            ->call('update')
            ->assertHasNoErrors();

        $this->assertSame(2222222, $l->fresh()->car_cost);
    }

    public function test_locked_auction_blocks_sales_edit(): void
    {
        $kim = $this->mkUser('sales');
        $l = $this->mkListing($kim, [
            'source' => 'auction', 'auction_venue' => '롯데', 'lot_number' => 'A-1',
            'expected_price' => 1000000, 'lock_at' => now()->subHour(),
        ]);
        $this->actingAs($kim);

        Volt::test('listings.index')
            ->call('openEdit', $l->id)
            ->set('e_car_cost', '9999999')
            ->call('update')
            ->assertHasErrors('e_car_cost');

        $this->assertNull($l->fresh()->car_cost);
    }

    public function test_manager_corrects_identity_on_unsynced_listing(): void
    {
        $l = $this->mkListing($this->mkUser('sales'), ['vin' => 'OLDVIN001', 'vehicle_number' => '12가0001']);
        $this->actingAs($this->mkUser('manager'));

        Volt::test('manage.index')
            ->call('openEdit', $l->id)
            ->set('vin', 'NEWVIN999')
            ->set('vehicle_number', '99가9999')
            ->call('save')
            ->assertHasNoErrors();

        $l->refresh();
        $this->assertSame('NEWVIN999', $l->vin);
        $this->assertSame('99가9999', $l->vehicle_number);
        $this->assertDatabaseHas('board_audit_logs', ['purchase_listing_id' => $l->id, 'field' => 'vin']);
    }

    public function test_manager_cannot_correct_identity_once_synced(): void
    {
        $l = $this->mkListing($this->mkUser('sales'), ['vin' => 'SYNC0001', 'car_erp_vehicle_id' => 555]);
        $this->actingAs($this->mkUser('manager'));

        Volt::test('manage.index')
            ->call('openEdit', $l->id)
            ->set('vin', 'HACK9999')
            ->call('save');

        $this->assertSame('SYNC0001', $l->fresh()->vin); // 연동된 차량은 식별값 불변
    }

    public function test_user_management_is_super_only(): void
    {
        $this->actingAs($this->mkUser('sales'))->get('/users')->assertForbidden();
        $this->actingAs($this->mkUser('manager'))->get('/users')->assertForbidden(); // 관리 role 이지만 super 아님
        $this->actingAs($this->mkUser('manager', null, 'super'))->get('/users')->assertOk();
    }

    public function test_super_accesses_all_views_and_sees_all_listings(): void
    {
        $kim = $this->mkUser('sales');
        $this->mkListing($kim);
        $this->mkListing($kim);

        $super = $this->mkUser('sales', null, 'super'); // role 은 sales 지만 super
        $this->actingAs($super);

        foreach (['/listings', '/inspection', '/auction', '/manage', '/users'] as $route) {
            $this->get($route)->assertOk();
        }
        $this->assertSame(2, PurchaseListing::count()); // super 는 본인격리 예외
    }

    public function test_manager_creates_user(): void
    {
        $this->actingAs($this->mkUser('manager', null, 'super'));

        Volt::test('users.index')
            ->call('openCreate')
            ->set('name', '새영업')
            ->set('email', 'new@board.test')
            ->set('role', 'sales')
            ->set('password', 'secret123')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'new@board.test', 'role' => 'sales', 'is_active' => true]);
    }

    public function test_manage_filters_listings(): void
    {
        $sales = $this->mkUser('sales');
        $this->mkListing($sales, ['source' => 'encar', 'status' => 'draft']);
        $this->mkListing($sales, ['source' => 'auction', 'status' => 'draft']);
        $this->actingAs($this->mkUser('manager'));

        Volt::test('manage.index')
            ->assertSee('전체 현황')
            ->set('fSource', 'encar')
            ->assertSet('fSource', 'encar');   // 필터 세팅 + 렌더 에러 없음

        // KPI 클릭 토글
        Volt::test('manage.index')
            ->call('kpiFilter', 'won')
            ->assertSet('fStatus', 'won')
            ->call('kpiFilter', 'won')
            ->assertSet('fStatus', '');
    }

    public function test_audit_log_page_is_super_only(): void
    {
        $this->actingAs($this->mkUser('manager'))->get('/audit')->assertForbidden();
        $this->actingAs($this->mkUser('manager', null, 'super'))->get('/audit')->assertOk();
    }

    public function test_inactive_user_is_blocked_from_views(): void
    {
        $u = $this->mkUser('sales');
        $u->update(['is_active' => false]);

        $this->actingAs($u)->get('/listings')->assertForbidden();
    }

    // ─────────────────────── 연동 B (car-erp purchase-sync) ───────────────────────

    public function test_won_dispatches_car_erp_sync_job(): void
    {
        Bus::fake();
        $l = $this->mkListing($this->mkUser('sales'), [
            'status' => 'accepted', 'buyer_verdict' => 'accepted', 'source' => 'auction', 'final_price' => 9000000,
        ]);

        $l->update(['status' => 'won']);

        Bus::assertDispatched(
            SyncWonListingToCarErp::class,
            fn ($job) => $job->listingId === $l->id,
        );
    }

    public function test_sync_job_pushes_payload_and_marks_synced(): void
    {
        config(['services.car_erp.base_url' => 'https://carerp.test', 'services.car_erp.hmac_secret' => 'shh']);
        Http::fake(['*/api/internal/purchase-sync' => Http::response(['vehicle_id' => 777], 200)]);

        $owner = $this->mkUser('sales', 'kim@board.test');
        $l = $this->mkListing($owner, [
            'status' => 'won', 'buyer_verdict' => 'accepted', 'source' => 'auction', 'final_price' => 9000000,
            'owner_name' => '김소유', 'payee_name' => '판매상사', 'payee_account' => '110-222-333444',
        ]);

        (new SyncWonListingToCarErp($l->id))->handle();

        // board 는 vin 을 모름 → 매칭키 = vehicle_number + owner_name (car-erp 가 NICE 로 vin 조회)
        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/internal/purchase-sync')
            && str_starts_with($request->header('X-Board-Signature')[0], 'sha256=')
            && $request['vehicle_number'] === $l->vehicle_number
            && $request['owner_name'] === '김소유'
            && ! array_key_exists('vin', $request->data())
            && $request['salesman_email'] === 'kim@board.test'
            && $request['payee_account'] === '110-222-333444');   // 전송 본문엔 실값

        $fresh = $l->fresh();
        $this->assertSame(777, $fresh->car_erp_vehicle_id);
        $this->assertSame('synced', $fresh->status);

        $ev = IntegrationEvent::first();
        $this->assertSame('outbound', $ev->direction);
        $this->assertSame('car_erp', $ev->target);
        $this->assertSame(200, $ev->response_status);
        $this->assertSame('***', $ev->request_payload['payee_account']);   // 로그엔 마스킹
    }

    public function test_sync_uses_car_erp_salesman_email_override(): void
    {
        config(['services.car_erp.base_url' => 'https://carerp.test', 'services.car_erp.hmac_secret' => 'shh']);
        Http::fake(['*/api/internal/purchase-sync' => Http::response(['vehicle_id' => 1], 200)]);

        $owner = $this->mkUser('sales', 'login@board.test');
        $owner->update(['car_erp_salesman_email' => 'real@carerp.com']);   // 로그인 ≠ car-erp 이메일
        $l = $this->mkListing($owner, ['status' => 'won', 'source' => 'auction', 'final_price' => 9000000]);

        (new SyncWonListingToCarErp($l->id))->handle();

        // 오버라이드 이메일이 salesman_email 로 나가야 함 (로그인 이메일 아님)
        Http::assertSent(fn ($request) => $request['salesman_email'] === 'real@carerp.com');
    }

    public function test_won_to_synced_is_audited_as_system(): void
    {
        config(['services.car_erp.base_url' => 'https://carerp.test', 'services.car_erp.hmac_secret' => 'shh']);
        Http::fake(['*/api/internal/purchase-sync' => Http::response(['vehicle_id' => 321], 200)]);

        $l = $this->mkListing($this->mkUser('sales'), ['status' => 'won', 'source' => 'auction', 'final_price' => 9000000]);
        (new SyncWonListingToCarErp($l->id))->handle();

        // 옵저버가 won→synced 를 시스템(user_id=null) 감사로그로 남김
        $log = BoardAuditLog::where('purchase_listing_id', $l->id)
            ->where('field', 'status')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertNull($log->user_id);                 // 시스템(비로그인 Job)
        $this->assertSame('won', $log->old_value);
        $this->assertSame('synced', $log->new_value);
        $this->assertSame('status_change', $log->action);
    }

    public function test_sync_job_skips_when_already_synced(): void
    {
        config(['services.car_erp.base_url' => 'https://carerp.test', 'services.car_erp.hmac_secret' => 'shh']);
        Http::fake();

        $l = $this->mkListing($this->mkUser('sales'), [
            'status' => 'won', 'source' => 'auction', 'car_erp_vehicle_id' => 555,
        ]);

        (new SyncWonListingToCarErp($l->id))->handle();

        Http::assertNothingSent();
    }

    public function test_sync_job_noops_without_config(): void
    {
        config(['services.car_erp.base_url' => null, 'services.car_erp.hmac_secret' => null]);
        Http::fake();

        $l = $this->mkListing($this->mkUser('sales'), ['status' => 'won', 'source' => 'auction']);

        (new SyncWonListingToCarErp($l->id))->handle();

        Http::assertNothingSent();
        $this->assertNull($l->fresh()->car_erp_vehicle_id);
    }

    // ─────────────────────── 연동 A (respond.io inbound webhook) ───────────────────────

    private function postRespond(array $body, string $secret = 'whsecret')
    {
        return $this->postJson('/api/webhooks/respond', $body, ['X-Webhook-Secret' => $secret]);
    }

    public function test_respond_webhook_rejects_bad_secret(): void
    {
        config(['services.respond_io.webhook_secret' => 'whsecret']);

        $this->postRespond(['event' => 'buyer_verdict'], 'WRONG')->assertStatus(401);
        $this->assertSame(0, IntegrationEvent::count());
    }

    public function test_respond_webhook_accept_moves_listing_to_accepted(): void
    {
        config(['services.respond_io.webhook_secret' => 'whsecret']);
        $l = $this->mkListing($this->mkUser('sales'), [
            'status' => 'awaiting_buyer', 'buyer_verdict' => 'pending',
            'final_price' => 9000000, 'buyer_name' => 'X', 'respond_conversation_id' => 'conv_1',
        ]);

        $this->postRespond([
            'event' => 'buyer_verdict', 'external_event_id' => 'evt_1',
            'respond_conversation_id' => 'conv_1', 'respond_contact_id' => 'ct_9', 'verdict' => 'accepted',
        ])->assertOk()->assertJson(['status' => 'applied:accepted']);

        $l->refresh();
        $this->assertSame('accepted', $l->status);
        $this->assertSame('accepted', $l->buyer_verdict);
        $this->assertSame('ct_9', $l->respond_contact_id);

        $ev = IntegrationEvent::where('target', 'respond_io')->first();
        $this->assertSame('inbound', $ev->direction);
        $this->assertSame($l->id, $ev->purchase_listing_id);
    }

    public function test_respond_webhook_reject_moves_listing_to_rejected(): void
    {
        config(['services.respond_io.webhook_secret' => 'whsecret']);
        $l = $this->mkListing($this->mkUser('sales'), [
            'status' => 'awaiting_buyer', 'buyer_verdict' => 'pending', 'respond_conversation_id' => 'conv_r',
        ]);

        $this->postRespond([
            'event' => 'buyer_verdict', 'external_event_id' => 'evt_r',
            'respond_conversation_id' => 'conv_r', 'verdict' => 'rejected',
        ])->assertOk()->assertJson(['status' => 'applied:rejected']);

        $l->refresh();
        $this->assertSame('rejected', $l->status);
        $this->assertSame('rejected', $l->buyer_verdict);
    }

    public function test_respond_webhook_is_idempotent_on_external_event_id(): void
    {
        config(['services.respond_io.webhook_secret' => 'whsecret']);
        $l = $this->mkListing($this->mkUser('sales'), [
            'status' => 'awaiting_buyer', 'buyer_verdict' => 'pending', 'respond_conversation_id' => 'conv_d',
        ]);
        $body = [
            'event' => 'buyer_verdict', 'external_event_id' => 'dup_1',
            'respond_conversation_id' => 'conv_d', 'verdict' => 'accepted',
        ];

        $this->postRespond($body)->assertJson(['status' => 'applied:accepted']);
        $this->postRespond($body)->assertOk()->assertJson(['status' => 'duplicate']);

        // 중복은 한 번만 기록 + 상태는 1회만 적용
        $this->assertSame(1, IntegrationEvent::where('external_event_id', 'dup_1')->count());
        $this->assertSame('accepted', $l->fresh()->status);
    }

    public function test_respond_webhook_no_match_is_noop(): void
    {
        config(['services.respond_io.webhook_secret' => 'whsecret']);

        $this->postRespond([
            'event' => 'buyer_verdict', 'external_event_id' => 'evt_nm',
            'respond_conversation_id' => 'nonexistent', 'verdict' => 'accepted',
        ])->assertOk()->assertJson(['status' => 'no_match']);
    }

    public function test_respond_webhook_multi_match_needs_vehicle_number(): void
    {
        config(['services.respond_io.webhook_secret' => 'whsecret']);
        $sales = $this->mkUser('sales');
        $l1 = $this->mkListing($sales, [
            'status' => 'awaiting_buyer', 'buyer_verdict' => 'pending',
            'respond_conversation_id' => 'conv_m', 'vehicle_number' => '11가1111',
        ]);
        $l2 = $this->mkListing($sales, [
            'status' => 'awaiting_buyer', 'buyer_verdict' => 'pending',
            'respond_conversation_id' => 'conv_m', 'vehicle_number' => '22가2222',
        ]);

        // disambiguator 없으면 모호 → 변경 없음
        $this->postRespond([
            'event' => 'buyer_verdict', 'external_event_id' => 'evt_amb',
            'respond_conversation_id' => 'conv_m', 'verdict' => 'accepted',
        ])->assertOk()->assertJson(['status' => 'ambiguous']);
        $this->assertSame('awaiting_buyer', $l1->fresh()->status);
        $this->assertSame('awaiting_buyer', $l2->fresh()->status);

        // vehicle_number 동반 → 해당 차만 적용
        $this->postRespond([
            'event' => 'buyer_verdict', 'external_event_id' => 'evt_res',
            'respond_conversation_id' => 'conv_m', 'verdict' => 'accepted', 'vehicle_number' => '22가2222',
        ])->assertOk()->assertJson(['status' => 'applied:accepted']);
        $this->assertSame('awaiting_buyer', $l1->fresh()->status);
        $this->assertSame('accepted', $l2->fresh()->status);
    }

    public function test_respond_webhook_verdict_on_draft_is_noop(): void
    {
        // 회신대기 아닌 차(draft)에 verdict 도착 → 전이 가드 throw 없이 no-op
        config(['services.respond_io.webhook_secret' => 'whsecret']);
        $l = $this->mkListing($this->mkUser('sales'), [
            'status' => 'draft', 'buyer_verdict' => 'none', 'respond_conversation_id' => 'conv_draft',
        ]);

        $this->postRespond([
            'event' => 'buyer_verdict', 'external_event_id' => 'evt_dr',
            'respond_conversation_id' => 'conv_draft', 'verdict' => 'accepted',
        ])->assertOk()->assertJson(['status' => 'no_match']);

        $this->assertSame('draft', $l->fresh()->status);
    }

    public function test_respond_webhook_verdict_audited_as_system(): void
    {
        config(['services.respond_io.webhook_secret' => 'whsecret']);
        $l = $this->mkListing($this->mkUser('sales'), [
            'status' => 'awaiting_buyer', 'buyer_verdict' => 'pending', 'respond_conversation_id' => 'conv_a',
        ]);

        $this->postRespond([
            'event' => 'buyer_verdict', 'external_event_id' => 'evt_aud',
            'respond_conversation_id' => 'conv_a', 'verdict' => 'accepted',
        ])->assertOk();

        $log = BoardAuditLog::where('purchase_listing_id', $l->id)
            ->where('field', 'status')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertNull($log->user_id);   // 무인증 웹훅 = 시스템
        $this->assertSame('accepted', $log->new_value);
    }

    // ─────────────────────── 연동 A — A2 (승격 / 링크 추출) ───────────────────────

    public function test_listing_link_parser_extracts_ids_and_origin(): void
    {
        $enc = ListingLink::parse('https://fem.encar.com/cars/detail/42176484?adv=x');
        $this->assertSame('encar', $enc['origin']);
        $this->assertSame('encar', $enc['source']);
        $this->assertSame('42176484', $enc['encar_id']);

        // 싼카재고(c_no) → 즉시구매
        $stock = ListingLink::parse('https://www.ssancar.com/page/stock_car_view.php?c_no=6915603');
        $this->assertSame('ssancar_stock', $stock['origin']);
        $this->assertSame('encar', $stock['source']);
        $this->assertSame('6915603', $stock['c_no']);

        // 싼카체킹(wr_id) → 즉시구매
        $chk = ListingLink::parse('https://www.ssancar.com/page/inspected_view.php?wr_id=786');
        $this->assertSame('ssancar_checking', $chk['origin']);
        $this->assertSame('encar', $chk['source']);
        $this->assertSame('wr_id:786', $chk['ssancar_ref']);

        // 싼카경매(car_no) → 경매
        $auc = ListingLink::parse('https://www.ssancar.com/page/car_view.php?car_no=1871585');
        $this->assertSame('ssancar_auction', $auc['origin']);
        $this->assertSame('auction', $auc['source']);
        $this->assertSame('car_no:1871585', $auc['ssancar_ref']);

        $this->assertSame([], ListingLink::parse('https://www.google.com/'));
    }

    public function test_promote_via_encar_link_extracts_and_saves(): void
    {
        Http::fake(['*api.encar.com*' => Http::response(['vehicleNo' => '244로9100', 'advertisement' => ['price' => 100]], 200)]);
        $this->actingAs($kim = $this->mkUser('sales'));

        Volt::test('listings.index')
            ->set('encarLink', 'https://fem.encar.com/cars/detail/42176484?x=1')
            ->call('parseLink', 'encar')
            ->assertSet('source', 'encar')
            ->assertSet('encar_id', '42176484')
            ->set('vehicle_number', '88가8888')
            ->set('respond_contact_id', 'ct_xyz')
            ->call('save')
            ->assertHasNoErrors();

        $l = PurchaseListing::where('vehicle_number', '88가8888')->first();
        $this->assertSame('42176484', $l->encar_id);
        $this->assertSame('encar', $l->origin);
        $this->assertSame('encar', $l->source);
        $this->assertSame('ct_xyz', $l->respond_contact_id);
    }

    public function test_promote_via_ssancar_wr_id_link_sets_ssancar_ref(): void
    {
        Http::fake(['*ssancar.com*' => Http::response('<html>상세</html>', 200)]);   // 식별값 없음 → prefill 없음
        $this->actingAs($this->mkUser('sales'));

        Volt::test('listings.index')
            ->set('ssancarLink', 'https://www.ssancar.com/page/inspected_view.php?wr_id=786')
            ->call('parseLink', 'ssancar')
            ->assertSet('origin', 'ssancar_checking')
            ->assertSet('ssancar_ref', 'wr_id:786')
            ->set('vehicle_number', '77가7777')
            ->call('save')
            ->assertHasNoErrors();

        $l = PurchaseListing::where('vehicle_number', '77가7777')->first();
        $this->assertSame('wr_id:786', $l->ssancar_ref);
        $this->assertSame('ssancar_checking', $l->origin);
        $this->assertSame('encar', $l->source);   // 즉시구매로 도출
    }

    public function test_promote_via_ssancar_car_no_link_is_auction(): void
    {
        // 싼카경매(car_no) → origin=ssancar_auction, source=auction(경매 워크플로)
        Carbon::setTestNow('2026-06-13 09:00:00');   // 토요일 → 등록 시간잠금 미적용
        Http::fake(['*ssancar.com*' => Http::response('<html>경매</html>', 200)]);
        $this->actingAs($this->mkUser('sales'));

        Volt::test('listings.index')
            ->set('ssancarLink', 'https://www.ssancar.com/page/car_view.php?car_no=1871585')
            ->call('parseLink', 'ssancar')
            ->assertSet('origin', 'ssancar_auction')
            ->assertSet('source', 'auction')
            ->set('vehicle_number', '66가6666')
            ->call('save')
            ->assertHasNoErrors();

        $l = PurchaseListing::where('vehicle_number', '66가6666')->first();
        $this->assertSame('ssancar_auction', $l->origin);
        $this->assertSame('auction', $l->source);
        $this->assertSame('car_no:1871585', $l->ssancar_ref);
        $this->assertTrue($l->isAuction());

        Carbon::setTestNow();
    }

    public function test_promote_bad_link_shows_error(): void
    {
        $this->actingAs($this->mkUser('sales'));

        Volt::test('listings.index')
            ->set('encarLink', 'https://www.google.com')
            ->call('parseLink', 'encar')
            ->assertHasErrors('encarLink');
    }

    public function test_duplicate_vehicle_number_is_blocked(): void
    {
        $kim = $this->mkUser('sales');
        $this->mkListing($kim, ['vehicle_number' => '55가5555']);
        $this->actingAs($kim);

        Volt::test('listings.index')
            ->set('source', 'encar')
            ->set('vehicle_number', '55가5555')
            ->call('save')
            ->assertHasErrors('vehicle_number');
    }

    // ─────────────────────── 연동 A — (A) 바이어 회신 화면 (per-car verdict) ───────────────────────

    public function test_verdicts_screen_access_by_role(): void
    {
        $this->actingAs($this->mkUser('sales'))->get('/verdicts')->assertOk();
        $this->actingAs($this->mkUser('manager'))->get('/verdicts')->assertOk();
        $this->actingAs($this->mkUser('inspection'))->get('/verdicts')->assertForbidden();
        $this->actingAs($this->mkUser('sales', null, 'super'))->get('/verdicts')->assertOk();
    }

    public function test_verdicts_accept_moves_listing_to_accepted(): void
    {
        $kim = $this->mkUser('sales');
        $l = $this->mkListing($kim, [
            'status' => 'awaiting_buyer', 'buyer_verdict' => 'pending',
            'final_price' => 9000000, 'buyer_name' => 'Dragan', 'respond_conversation_id' => 'conv_1',
        ]);
        $this->actingAs($kim);

        Volt::test('verdicts.index')->call('accept', $l->id)->assertHasNoErrors();

        $l->refresh();
        $this->assertSame('accepted', $l->status);
        $this->assertSame('accepted', $l->buyer_verdict);
    }

    public function test_verdicts_reject_moves_listing_to_rejected(): void
    {
        $kim = $this->mkUser('sales');
        $l = $this->mkListing($kim, ['status' => 'awaiting_buyer', 'buyer_verdict' => 'pending']);
        $this->actingAs($kim);

        Volt::test('verdicts.index')->call('reject', $l->id)->assertHasNoErrors();

        $this->assertSame('rejected', $l->fresh()->status);
        $this->assertSame('rejected', $l->fresh()->buyer_verdict);
    }

    public function test_verdicts_multi_car_per_buyer_are_independent(): void
    {
        // 한 바이어(같은 대화)의 여러 차 → 차별로 독립 처리
        $kim = $this->mkUser('sales');
        $a = $this->mkListing($kim, ['status' => 'awaiting_buyer', 'buyer_verdict' => 'pending', 'respond_conversation_id' => 'conv_x']);
        $b = $this->mkListing($kim, ['status' => 'awaiting_buyer', 'buyer_verdict' => 'pending', 'respond_conversation_id' => 'conv_x']);
        $this->actingAs($kim);

        Volt::test('verdicts.index')->call('accept', $a->id)->call('reject', $b->id);

        $this->assertSame('accepted', $a->fresh()->status);
        $this->assertSame('rejected', $b->fresh()->status);
    }

    public function test_verdicts_sales_cannot_act_on_others_listing(): void
    {
        $kim = $this->mkUser('sales');
        $lee = $this->mkUser('sales');
        $l = $this->mkListing($kim, ['status' => 'awaiting_buyer', 'buyer_verdict' => 'pending']);
        $this->actingAs($lee);

        try {
            Volt::test('verdicts.index')->call('accept', $l->id);
        } catch (\Throwable $e) {
            // SalesmanScope → findOrFail 실패(타 영업 글 접근 불가)
        }

        $this->assertSame('awaiting_buyer', $l->fresh()->status);
    }

    // ─────────────────────── 연동 A — (C) 채널 분리 / 직렬화 가드 / VerdictService ───────────────────────

    public function test_verdict_service_apply_is_idempotent(): void
    {
        $l = $this->mkListing($this->mkUser('sales'), ['status' => 'awaiting_buyer', 'buyer_verdict' => 'pending']);
        $svc = app(VerdictService::class);

        $this->assertTrue($svc->apply($l->id, 'accepted'));     // 1회 적용
        $this->assertSame('accepted', $l->fresh()->status);
        $this->assertFalse($svc->apply($l->id, 'rejected'));    // 이미 처리됨 → 적용 안 됨(락③)
        $this->assertSame('accepted', $l->fresh()->status);     // 안 덮어씀
    }

    public function test_inspection_send_defaults_auto_when_conversation_linked(): void
    {
        $l = $this->mkListing($this->mkUser('sales'), [
            'status' => 'draft', 'region' => '서울', 'respond_contact_id' => 'ct_auto', 'car_cost' => 9000000,
        ]);
        $this->actingAs($this->mkUser('inspection'));

        Volt::test('inspection.index')
            ->call('openDrawer', $l->id)->set('buyer_name', 'D')->set('car_cost', '9000000')
            ->set('sendSelected', true)->call('save')->assertHasNoErrors();

        $l->refresh();
        $this->assertSame('awaiting_buyer', $l->status);
        $this->assertSame('auto', $l->verdict_channel);
    }

    public function test_inspection_send_without_conversation_is_manual(): void
    {
        $l = $this->mkListing($this->mkUser('sales'), ['status' => 'draft', 'car_cost' => 9000000]);  // 대화 미연결
        $this->actingAs($this->mkUser('inspection'));

        Volt::test('inspection.index')
            ->call('openDrawer', $l->id)->set('buyer_name', 'D')->set('car_cost', '9000000')
            ->set('sendSelected', true)->call('save')->assertHasNoErrors();

        $this->assertSame('manual', $l->fresh()->verdict_channel);   // 자동 불가 → 수동
    }

    public function test_inspection_second_auto_car_blocked_then_manual(): void
    {
        $sales = $this->mkUser('sales');
        $a = $this->mkListing($sales, ['status' => 'awaiting_buyer', 'buyer_verdict' => 'pending', 'respond_contact_id' => 'ct_g', 'verdict_channel' => 'auto']);
        $b = $this->mkListing($sales, ['status' => 'draft', 'respond_contact_id' => 'ct_g', 'car_cost' => 9000000]);
        $this->actingAs($this->mkUser('inspection'));

        // 2번째 자동 차 전달 시도 → (가) 보류 + 알림
        $c = Volt::test('inspection.index')
            ->call('openDrawer', $b->id)->set('buyer_name', 'D')->set('car_cost', '9000000')
            ->set('sendSelected', true)->call('save');
        $c->assertSet('sendConflictWith', $a->vehicle_number);
        $this->assertSame('draft', $b->fresh()->status);   // 전달 보류

        // 수동으로 전환해 전달
        $c->call('sendAsManual')->assertHasNoErrors();
        $b->refresh();
        $this->assertSame('awaiting_buyer', $b->status);
        $this->assertSame('manual', $b->verdict_channel);
        // 첫 차는 자동 그대로
        $this->assertSame('auto', $a->fresh()->verdict_channel);
    }

    // ─────────────────────── 연동 A — (C) Developer API 폴링 ───────────────────────

    private function respondConfig(): void
    {
        config(['services.respond_io.base_url' => 'https://api.respond.io', 'services.respond_io.api_token' => 'tok', 'services.respond_io.verdict_field' => 'buyer_verdict']);
    }

    public function test_poll_applies_verdict_for_single_awaiting_auto(): void
    {
        $this->respondConfig();
        Http::fake(['*/v2/contact*' => Http::response(['items' => [
            ['id' => 'ct1', 'conversation_id' => 'conv_p', 'custom_fields' => ['buyer_verdict' => 'Accept']],
        ]])]);
        $l = $this->mkListing($this->mkUser('sales'), [
            'status' => 'awaiting_buyer', 'buyer_verdict' => 'pending', 'respond_contact_id' => 'ct1', 'verdict_channel' => 'auto',
        ]);

        $this->artisan('board:poll-verdicts')->assertSuccessful();

        $this->assertSame('accepted', $l->fresh()->status);
        $this->assertDatabaseHas('integration_events', [
            'target' => 'respond_io', 'event_type' => 'verdict_poll', 'purchase_listing_id' => $l->id,
        ]);
    }

    public function test_poll_skips_when_multiple_awaiting_auto(): void
    {
        $this->respondConfig();
        Http::fake(['*/v2/contact*' => Http::response(['items' => [
            ['id' => 'ct2', 'conversation_id' => 'conv_m2', 'custom_fields' => ['buyer_verdict' => 'Accept']],
        ]])]);
        $sales = $this->mkUser('sales');
        $a = $this->mkListing($sales, ['status' => 'awaiting_buyer', 'buyer_verdict' => 'pending', 'respond_contact_id' => 'ct2', 'verdict_channel' => 'auto']);
        $b = $this->mkListing($sales, ['status' => 'awaiting_buyer', 'buyer_verdict' => 'pending', 'respond_contact_id' => 'ct2', 'verdict_channel' => 'auto']);

        $this->artisan('board:poll-verdicts')->assertSuccessful();

        // 다중 → 모호 → 적용 안 함(사람이 A로)
        $this->assertSame('awaiting_buyer', $a->fresh()->status);
        $this->assertSame('awaiting_buyer', $b->fresh()->status);
    }

    public function test_poll_ignores_manual_channel(): void
    {
        $this->respondConfig();
        Http::fake(['*/v2/contact*' => Http::response(['items' => [
            ['id' => 'ct3', 'conversation_id' => 'conv_man', 'custom_fields' => ['buyer_verdict' => 'Accept']],
        ]])]);
        $l = $this->mkListing($this->mkUser('sales'), [
            'status' => 'awaiting_buyer', 'buyer_verdict' => 'pending', 'respond_contact_id' => 'ct3', 'verdict_channel' => 'manual',
        ]);

        $this->artisan('board:poll-verdicts')->assertSuccessful();

        $this->assertSame('awaiting_buyer', $l->fresh()->status);   // 수동 채널 → 폴러 무시
    }

    public function test_poll_noops_without_config(): void
    {
        config(['services.respond_io.base_url' => null, 'services.respond_io.api_token' => null]);
        Http::fake();

        $this->artisan('board:poll-verdicts')->assertSuccessful();

        Http::assertNothingSent();   // 안전밸브
    }

    // ─────────────────────── 연동 A — outbound (바이어에게 사진+금액 전송) ───────────────────────

    public function test_send_offer_sends_price_and_only_shared_photos(): void
    {
        $this->respondConfig();
        config(['board.photo_disk' => 'public']);
        Http::fake(['*/message' => Http::response(['messageId' => 1], 200)]);

        $l = $this->mkListing($this->mkUser('sales'), [
            'status' => 'awaiting_buyer', 'buyer_verdict' => 'pending', 'respond_contact_id' => 'ct_o', 'final_price' => 13800000,
        ]);
        $l->photos()->create(['s3_path' => 'p/ext.jpg', 'original_name' => 'ext.jpg', 'sort' => 1, 'share_to_buyer' => true]);
        $l->photos()->create(['s3_path' => 'p/doc.jpg', 'original_name' => 'doc.jpg', 'sort' => 2, 'share_to_buyer' => false]);

        (new SendOfferToBuyer($l->id))->handle(app(RespondIoService::class), app(ExchangeRateService::class));

        Http::assertSentCount(2);   // 텍스트(USD 금액) 1 + 공개사진 1 (서류는 미전송)
        $this->assertDatabaseHas('integration_events', [
            'target' => 'respond_io', 'event_type' => 'send_offer', 'purchase_listing_id' => $l->id,
        ]);
    }

    public function test_send_offer_noop_without_contact(): void
    {
        $this->respondConfig();
        Http::fake();
        $l = $this->mkListing($this->mkUser('sales'), ['status' => 'awaiting_buyer', 'final_price' => 9000000]); // 컨택트 없음

        (new SendOfferToBuyer($l->id))->handle(app(RespondIoService::class), app(ExchangeRateService::class));

        Http::assertNothingSent();
    }

    public function test_inspection_send_dispatches_offer_to_buyer(): void
    {
        Bus::fake();
        $l = $this->mkListing($this->mkUser('sales'), ['status' => 'draft', 'respond_contact_id' => 'ct_s', 'car_cost' => 9000000]);
        $this->actingAs($this->mkUser('inspection'));

        Volt::test('inspection.index')
            ->call('openDrawer', $l->id)->set('buyer_name', 'D')->set('car_cost', '9000000')
            ->set('sendSelected', true)->call('save')->assertHasNoErrors();

        Bus::assertDispatched(SendOfferToBuyer::class, fn ($j) => $j->listingId === $l->id);
    }

    // ─────────────────────── 연동 A — 승격 자동연결 (board_promote 폴링) ───────────────────────

    public function test_poll_captures_pending_promotion_and_resets(): void
    {
        $this->respondConfig();
        Http::fake(['*/v2/contact*' => Http::response(['items' => [
            ['id' => 469, 'firstName' => '홍', 'lastName' => '길동', 'assignee' => ['email' => 'agent@x.test'], 'custom_fields' => [['name' => 'board_promote', 'value' => 'Yes']]],
        ]])]);

        $this->artisan('board:poll-promotions')->assertSuccessful();

        $this->assertDatabaseHas('promotion_requests', [
            'respond_contact_id' => '469', 'label' => '홍 길동', 'assigned_email' => 'agent@x.test', 'status' => 'pending',
        ]);
        $this->assertDatabaseHas('integration_events', [
            'target' => 'respond_io', 'event_type' => 'promote_poll',
        ]);
        // 필드 reset(PUT) 발송됨.
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && str_contains($r->url(), 'contact/id:469'));
    }

    public function test_poll_promotion_idempotent_per_buyer(): void
    {
        $this->respondConfig();
        Http::fake(['*/v2/contact*' => Http::response(['items' => [
            ['id' => 470, 'firstName' => 'A', 'custom_fields' => [['name' => 'board_promote', 'value' => 'Yes']]],
        ]])]);
        PromotionRequest::create(['respond_contact_id' => '470', 'label' => 'A', 'status' => 'pending']);

        $this->artisan('board:poll-promotions')->assertSuccessful();

        // 이미 미소비 대기 1건 → 중복 생성 안 함.
        $this->assertSame(1, PromotionRequest::where('respond_contact_id', '470')->where('status', 'pending')->count());
    }

    public function test_poll_promotion_expires_stale(): void
    {
        config(['services.respond_io.base_url' => null, 'services.respond_io.api_token' => null]);
        $r = PromotionRequest::create(['respond_contact_id' => '471', 'label' => 'old', 'status' => 'pending']);
        $r->forceFill(['created_at' => now()->subDays(8)])->save();
        Http::fake();

        $this->artisan('board:poll-promotions')->assertSuccessful();   // 미설정이어도 만료는 돈다

        $this->assertSame('expired', $r->fresh()->status);
        Http::assertNothingSent();   // 미설정 = 안전밸브
    }

    public function test_promote_from_consumes_request_on_save(): void
    {
        $sales = $this->mkUser('sales');
        // 담당 영업 = 로그인 이메일과 매칭(respond_agent_email 폴백) → 본인에게 보임.
        $req = PromotionRequest::create(['respond_contact_id' => 'ct_promo', 'label' => '바이어', 'assigned_email' => $sales->email, 'status' => 'pending']);
        $this->actingAs($sales);

        Volt::test('listings.index')
            ->call('promoteFrom', $req->id)
            ->set('vehicle_number', '99가1234')
            ->call('save')->assertHasNoErrors();

        $listing = PurchaseListing::where('vehicle_number', '99가1234')->first();
        $this->assertNotNull($listing);
        $this->assertSame('ct_promo', $listing->respond_contact_id);   // 컨택트 자동 연결
        $this->assertDatabaseHas('promotion_requests', [
            'id' => $req->id, 'status' => 'consumed', 'purchase_listing_id' => $listing->id,
        ]);
    }

    public function test_dismiss_promotion_marks_dismissed(): void
    {
        $sales = $this->mkUser('sales');
        $req = PromotionRequest::create(['respond_contact_id' => 'ct_x', 'assigned_email' => $sales->email, 'status' => 'pending']);
        $this->actingAs($sales);

        Volt::test('listings.index')->call('dismissPromotion', $req->id);

        $this->assertSame('dismissed', $req->fresh()->status);
    }

    public function test_promotion_visible_only_to_assigned_sales(): void
    {
        $mine = $this->mkUser('sales');
        $other = $this->mkUser('sales');
        PromotionRequest::create(['respond_contact_id' => 'c_a', 'label' => '내것', 'assigned_email' => $mine->email, 'status' => 'pending']);
        PromotionRequest::create(['respond_contact_id' => 'c_b', 'label' => '남것', 'assigned_email' => $other->email, 'status' => 'pending']);

        $this->actingAs($mine);
        Volt::test('listings.index')->assertSee('내것')->assertDontSee('남것');
    }

    public function test_manager_sees_all_promotions_including_unassigned(): void
    {
        $sales = $this->mkUser('sales');
        PromotionRequest::create(['respond_contact_id' => 'c_c', 'label' => '영업것', 'assigned_email' => $sales->email, 'status' => 'pending']);
        PromotionRequest::create(['respond_contact_id' => 'c_d', 'label' => '미배정것', 'assigned_email' => null, 'status' => 'pending']);

        $this->actingAs($this->mkUser('manager'));
        Volt::test('listings.index')->assertSee('영업것')->assertSee('미배정것');   // 관리자 풀
    }

    public function test_sales_cannot_promote_others_request(): void
    {
        $mine = $this->mkUser('sales');
        $other = $this->mkUser('sales');
        $req = PromotionRequest::create(['respond_contact_id' => 'c_e', 'assigned_email' => $other->email, 'status' => 'pending']);

        $this->actingAs($mine);
        // 본인 담당 아님 → firstOrFail(404) → consume 시도 차단(IDOR).
        $this->assertItThrows(fn () => Volt::test('listings.index')->call('promoteFrom', $req->id));
        $this->assertSame('pending', $req->fresh()->status);
    }

    // ─────────────────────── 매물 자동채움 (encar enrichment) ───────────────────────

    public function test_enrichment_maps_encar_and_scales_price(): void
    {
        Http::fake(['*api.encar.com*' => Http::response([
            'vehicleNo' => '12가3456', 'vin' => 'VINX',
            'advertisement' => ['price' => 650],
            'contact' => ['address' => '대구 서구 문화로 37'],
        ], 200)]);

        $r = (new ListingEnrichment)->byEncarId('42116243');

        $this->assertSame('12가3456', $r['vehicle_number']);
        $this->assertSame(6500000, $r['expected_price']);   // 650만 ×10000
        $this->assertSame('대구', $r['region']);
        $this->assertSame('VINX', $r['vin']);
    }

    public function test_enrichment_city_parser(): void
    {
        $s = new ListingEnrichment;
        $this->assertSame('대구', $s->city('대구 서구 문화로 37'));     // 광역시
        $this->assertSame('안산', $s->city('경기 안산시 단원구 원포공원1로 16'));   // 도+시
        $this->assertNull($s->city(''));
    }

    public function test_enrichment_failure_is_safe(): void
    {
        Http::fake(['*api.encar.com*' => Http::response('', 500)]);
        $this->assertSame([], (new ListingEnrichment)->byEncarId('1'));   // throw 안 함, prefill 없음
    }

    public function test_listings_link_prefills_from_encar(): void
    {
        Http::fake(['*api.encar.com/v1/readside/vehicle/*' => Http::response([
            'vehicleNo' => '244로9100', 'vin' => 'WMW21GA04S7R38829',
            'advertisement' => ['price' => 6666],
            'contact' => ['address' => '경기 안산시 단원구 원포공원1로 16'],
        ], 200)]);
        $this->actingAs($this->mkUser('sales'));

        Volt::test('listings.index')
            ->set('encarLink', 'https://fem.encar.com/cars/detail/42176484')
            ->call('parseLink', 'encar')
            ->assertSet('vehicle_number', '244로9100')
            ->assertSet('expected_price', '66660000')   // 6666만 ×10000
            ->assertSet('region', '안산')
            ->assertSet('vin', 'WMW21GA04S7R38829');
    }

    public function test_enrichment_ssancar_inspected_routes_via_encar(): void
    {
        Http::fake([
            '*api.encar.com*' => Http::response([
                'vehicleNo' => '55오5555', 'vin' => 'VV', 'advertisement' => ['price' => 700], 'contact' => ['address' => '서울 강남구 테헤란로 1'],
            ], 200),
            '*ssancar.com*' => Http::response('<html><a href="https://fem.encar.com/cars/detail/999?x=1">원본</a></html>', 200),
        ]);

        $r = (new ListingEnrichment)->fromSsancar('https://www.ssancar.com/x?wr_id=786');

        $this->assertSame('55오5555', $r['vehicle_number']);   // 검차매물 = encar 우회로 KRW·지역 확보
        $this->assertSame(7000000, $r['expected_price']);
        $this->assertSame('서울', $r['region']);
    }

    public function test_enrichment_ssancar_stock_parses_vin_plate_no_usd_price(): void
    {
        Http::fake(['*ssancar.com*' => Http::response('<div>차량번호 12가3456</div><em id="copy_txt">KMHXX1234567</em><span>1,838 USD</span>', 200)]);

        $r = (new ListingEnrichment)->fromSsancar('https://www.ssancar.com/x?c_no=6915603');

        $this->assertSame('KMHXX1234567', $r['vin']);
        $this->assertSame('12가3456', $r['vehicle_number']);
        $this->assertArrayNotHasKey('expected_price', $r);   // USD 차값은 미결정 → 안 채움
    }

    // ─────────────────────── 영업 포털 — car-erp 읽기(HMAC GET) ───────────────────────

    private function carErpReadConfig(): void
    {
        config(['services.car_erp.base_url' => 'https://x.test', 'services.car_erp.read_hmac_secret' => 'sek']);
    }

    /** canonical 핀(계약 §1 정합 검증물) — car-erp 라이브 시 서명 불일치면 여기 vs car-erp diff. */
    public function test_carerp_canonical_string_is_pinned(): void
    {
        $svc = new CarErpReadService;
        // car-erp VerifyBoardReadHmac = ksort + http_build_query(urlencode). @=%40, ,=%2C.
        $this->assertSame(
            "GET\n/api/internal/board/finance?salesman_email=kim%40board.test\n1700000000\n",
            $svc->canonical('GET', '/api/internal/board/finance', ['salesman_email' => 'kim@board.test'], '1700000000', '')
        );
        // 다중 쿼리 ksort: ids < salesman_email.
        $this->assertSame(
            "GET\n/api/internal/board/documents/roro_contract?ids=3%2C1%2C2&salesman_email=a%40b.test\n1700000000\n",
            $svc->canonical('GET', '/api/internal/board/documents/roro_contract', ['salesman_email' => 'a@b.test', 'ids' => '3,1,2'], '1700000000', '')
        );
    }

    public function test_carerp_signature_uses_read_secret(): void
    {
        $this->carErpReadConfig();
        [$headers, $canonical] = (new CarErpReadService)->sign('GET', '/api/internal/board/finance', ['salesman_email' => 'k@b.test'], '');
        $this->assertSame('sha256='.hash_hmac('sha256', $canonical, 'sek'), $headers['X-Board-Signature']);
        $this->assertArrayHasKey('X-Timestamp', $headers);
        $this->assertArrayHasKey('X-Nonce', $headers);
    }

    public function test_carerp_not_configured_is_noop_degrade(): void
    {
        config(['services.car_erp.base_url' => null, 'services.car_erp.read_hmac_secret' => null]);
        Http::fake();

        $r = (new CarErpReadService)->finance('k@b.test');

        $this->assertFalse($r['ok']);
        $this->assertSame('not_configured', $r['reason']);
        Http::assertNothingSent();   // 안전밸브
    }

    public function test_carerp_finance_success_sends_signed_scoped_request(): void
    {
        $this->carErpReadConfig();
        Http::fake(['*/api/internal/board/finance*' => Http::response(['receivables_total_krw' => 100], 200)]);

        $r = (new CarErpReadService)->finance('kim@board.test');

        $this->assertTrue($r['ok']);
        $this->assertSame(100, $r['data']['receivables_total_krw']);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/api/internal/board/finance')
            && str_contains($req->url(), 'salesman_email=kim%40board.test')
            && $req->hasHeader('X-Board-Signature') && $req->hasHeader('X-Timestamp') && $req->hasHeader('X-Nonce'));
    }

    public function test_carerp_http_error_degrades_not_zero(): void
    {
        $this->carErpReadConfig();
        Http::fake(['*' => Http::response('', 403)]);

        $r = (new CarErpReadService)->receivables('k@b.test');

        $this->assertFalse($r['ok']);   // degrade — 화면 "조회 불가"(0/완납 금지)
        $this->assertSame(403, $r['status']);
    }

    public function test_carerp_document_rejects_non_allowed_type(): void
    {
        $this->carErpReadConfig();
        Http::fake();

        $r = (new CarErpReadService)->document('deregistration', [1], 'k@b.test');   // 말소서류=PII

        $this->assertFalse($r['ok']);
        $this->assertSame('type_not_allowed', $r['reason']);
        Http::assertNothingSent();   // 화이트리스트 board 측 강제
    }

    public function test_portal_uses_auth_email_override_and_renders(): void
    {
        $this->carErpReadConfig();
        // 실제 finance 응답 키(InternalPortalController) — unpaid_total_krw.
        Http::fake([
            '*/api/internal/board/finance*' => Http::response(['unpaid_total_krw' => 5000, 'settlement_pending_count' => 2], 200),
            '*' => Http::response(['count' => 0, 'data' => []], 200),   // 월별용 sales/settlements/purchases
        ]);
        $sales = $this->mkUser('sales');
        $sales->update(['car_erp_salesman_email' => 'override@ce.test']);
        $this->actingAs($sales);

        Volt::test('portal.index')->assertSee('미수금 합계')->assertSee('5,000원');

        // 스코프 = Auth 본인 오버라이드 이메일(요청 파라미터 아님).
        Http::assertSent(fn ($req) => str_contains($req->url(), 'salesman_email=override%40ce.test'));
    }

    public function test_portal_degrades_when_not_configured(): void
    {
        config(['services.car_erp.base_url' => null, 'services.car_erp.read_hmac_secret' => null]);
        Http::fake();
        $this->actingAs($this->mkUser('sales'));

        Volt::test('portal.index')->assertSee('조회 불가')->assertDontSee('완납');
    }

    public function test_carerp_shipping_request_posts_signed_with_email(): void
    {
        $this->carErpReadConfig();
        Http::fake(['*/api/internal/board/shipping-request*' => Http::response(['created' => [1], 'skipped' => []], 201)]);

        $r = (new CarErpReadService)->shippingRequest('kim@board.test', [
            'vehicle_ids' => [1], 'buyer_id' => 2, 'consignee_id' => 3, 'shipping_method' => 'RORO',
        ]);

        $this->assertTrue($r['ok']);
        $this->assertSame([1], $r['data']['created']);
        Http::assertSent(fn ($req) => $req->method() === 'POST'
            && str_contains($req->url(), 'salesman_email=kim%40board.test')               // 쿼리(스코프 미들웨어)
            && str_contains($req->body(), '"salesman_email":"kim@board.test"')             // 바디(§5)
            && str_contains($req->body(), '"shipping_method":"RORO"')
            && $req->hasHeader('X-Board-Signature'));
    }

    public function test_portal_receivables_groups_by_buyer_with_sum(): void
    {
        $this->carErpReadConfig();
        Http::fake([
            '*/api/internal/board/finance*' => Http::response(['unpaid_total_krw' => 0], 200),
            '*/api/internal/board/receivables*' => Http::response(['count' => 2, 'data' => [
                ['vehicle_number' => '11가1', 'buyer' => 'BuyerA', 'currency' => 'USD', 'exchange_rate' => 1300, 'unpaid_krw' => 1000],
                ['vehicle_number' => '22나2', 'buyer' => 'BuyerA', 'currency' => 'USD', 'exchange_rate' => 1300, 'unpaid_krw' => 2000],
            ]], 200),
            '*' => Http::response(['count' => 0, 'data' => []], 200),
        ]);
        $this->actingAs($this->mkUser('sales'));

        Volt::test('portal.index')->call('setTab', 'receivables')
            ->assertSee('BuyerA')->assertSee('11가1')->assertSee('3,000원');   // 바이어 그룹 + 합계
    }

    public function test_carerp_by_buyer_signed_scoped(): void
    {
        $this->carErpReadConfig();
        Http::fake(['*/api/internal/board/by-buyer*' => Http::response(['data' => [
            ['buyer' => 'X', 'vehicle_count' => 2, 'sales_by_currency' => ['USD' => 100], 'payout_total_krw' => 5, 'payout_paid_krw' => 3],
        ]], 200)]);

        $r = (new CarErpReadService)->byBuyer('kim@board.test');

        $this->assertTrue($r['ok']);
        $this->assertSame('X', $r['data']['data'][0]['buyer']);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/api/internal/board/by-buyer')
            && str_contains($req->url(), 'salesman_email=kim%40board.test') && $req->hasHeader('X-Board-Signature'));
    }

    public function test_portal_sales_and_settlements_use_by_buyer(): void
    {
        $this->carErpReadConfig();
        Http::fake([
            '*/api/internal/board/finance*' => Http::response(['unpaid_total_krw' => 0], 200),
            '*/api/internal/board/by-buyer*' => Http::response(['data' => [
                ['buyer' => 'BuyerY', 'vehicle_count' => 3, 'sales_by_currency' => ['USD' => 12000, 'EUR' => 3000], 'payout_total_krw' => 7000000, 'payout_paid_krw' => 5000000],
            ]], 200),
            '*/api/internal/board/sales*' => Http::response(['count' => 1, 'data' => [
                ['buyer' => 'BuyerY', 'vehicle_number' => '77다7', 'currency' => 'USD', 'sale_price' => 12000, 'sale_date' => '2026-05-01'],
            ]], 200),
            '*' => Http::response(['count' => 0, 'data' => []], 200),
        ]);
        $this->actingAs($this->mkUser('sales'));

        Volt::test('portal.index')
            ->call('setTab', 'sales')->assertSee('BuyerY')->assertSee('USD')->assertSee('12,000')->assertSee('EUR')
            ->assertSee('77다7')   // 펼침용 차량 상세
            ->call('setTab', 'settlements')->assertSee('7,000,000')->assertSee('지급 완료');
    }

    public function test_portal_finance_abbreviates_amounts(): void
    {
        $this->carErpReadConfig();
        Http::fake([
            '*/api/internal/board/finance*' => Http::response(['unpaid_total_krw' => 704369898, 'purchase_unpaid_total' => 0], 200),
            '*' => Http::response(['count' => 0, 'data' => []], 200),
        ]);
        $this->actingAs($this->mkUser('sales'));

        Volt::test('portal.index')->assertSee('7억 436만원');   // 요약 한글 축약
    }

    public function test_portal_finance_shows_monthly(): void
    {
        $this->carErpReadConfig();
        Http::fake([
            '*/api/internal/board/finance*' => Http::response(['unpaid_total_krw' => 0], 200),
            '*/api/internal/board/sales*' => Http::response(['count' => 2, 'data' => [
                ['vehicle_number' => 'A', 'sale_date' => '2026-05-10', 'sale_price' => 1, 'currency' => 'USD'],
                ['vehicle_number' => 'B', 'sale_date' => '2026-05-20', 'sale_price' => 1, 'currency' => 'USD'],
            ]], 200),
            '*/api/internal/board/settlements*' => Http::response(['count' => 1, 'data' => [
                ['vehicle_number' => 'A', 'confirmed_at' => '2026-05-15', 'actual_payout' => 700000, 'status' => 'paid'],
            ]], 200),
            '*/api/internal/board/purchases*' => Http::response(['count' => 0, 'data' => []], 200),
            '*' => Http::response(['count' => 0, 'data' => []], 200),
        ]);
        $this->actingAs($this->mkUser('sales'));

        Volt::test('portal.index')
            ->assertSee('월별 실적')->assertSee('2026-05')->assertSee('700,000');   // 5월 판매2·정산70만
    }

    public function test_portal_receivables_hides_paid_and_sorts(): void
    {
        $this->carErpReadConfig();
        Http::fake([
            '*/api/internal/board/finance*' => Http::response(['unpaid_total_krw' => 0], 200),
            '*/api/internal/board/receivables*' => Http::response(['count' => 3, 'data' => [
                ['vehicle_number' => 'PAIDX', 'buyer' => 'B', 'currency' => 'USD', 'exchange_rate' => 1300, 'unpaid_krw' => 0],
                ['vehicle_number' => 'OWE1', 'buyer' => 'B', 'currency' => 'USD', 'exchange_rate' => 1300, 'unpaid_krw' => 500],
                ['vehicle_number' => 'OWE2', 'buyer' => 'B', 'currency' => 'USD', 'exchange_rate' => 1300, 'unpaid_krw' => 900],
            ]], 200),
            '*' => Http::response(['count' => 0, 'data' => []], 200),
        ]);
        $this->actingAs($this->mkUser('sales'));

        $c = Volt::test('portal.index')->call('setTab', 'receivables');
        $c->assertDontSee('PAIDX')->assertSee('OWE1')->assertSee('OWE2');   // 완납(0원) 기본 숨김
        $c->set('hidePaid', false)->assertSee('PAIDX');                     // 토글 끄면 보임
        $c->call('sortRecv', 'vehicle_number');                            // 정렬 토글
        $this->assertSame('vehicle_number', $c->get('recvSort'));
        $this->assertSame('asc', $c->get('recvDir'));
    }

    public function test_portal_shipping_lists_and_submits(): void
    {
        $this->carErpReadConfig();
        Http::fake([
            '*/api/internal/board/finance*' => Http::response(['unpaid_total_krw' => 0], 200),
            '*/api/internal/board/shippable*' => Http::response(['count' => 1, 'data' => [
                ['vehicle_id' => 10, 'vehicle_number' => '11가1111', 'buyer' => ['id' => 2, 'name' => 'BuyerX'], 'consignees' => [['id' => 3, 'name' => 'ConsX']]],
            ]], 200),
            '*/api/internal/board/shipping-request*' => Http::response(['created' => [10], 'skipped' => []], 201),
            '*' => Http::response(['count' => 0, 'data' => []], 200),
        ]);
        $this->actingAs($this->mkUser('sales'));

        Volt::test('portal.index')
            ->call('setTab', 'shipping')
            ->assertSee('BuyerX')->assertSee('11가1111')
            ->set('selectedIds', [10])
            ->set('consigneeByBuyer.2', 3)
            ->call('submitShipping', 2, [10])
            ->assertSee('선적요청 접수 완료')->assertSee('1대');   // 큰 성공 배너

        Http::assertSent(fn ($req) => str_contains($req->url(), 'shipping-request')
            && str_contains($req->body(), '"vehicle_ids":[10]') && str_contains($req->body(), '"consignee_id":3'));
    }
}

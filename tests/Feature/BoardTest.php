<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\InspectionAssignment;
use App\Models\PurchaseListing;
use App\Models\User;
use App\Services\ExchangeRateService;
use App\Support\TimeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
            ->call('save')
            ->assertHasNoErrors();

        $l = PurchaseListing::where('vin', 'TESTVIN0001')->where('created_by_user_id', $kim->id)->first();
        $this->assertNotNull($l);
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

        Volt::test('inspection.index')
            ->call('openDrawer', $l->id)
            ->set('final_price', '13200000')
            ->set('buyer_name', '드라간')
            ->call('sendToBuyer')
            ->assertHasNoErrors();

        $l->refresh();
        $this->assertSame('awaiting_buyer', $l->status);
        $this->assertSame('pending', $l->buyer_verdict);
        $this->assertSame(13200000, $l->final_price);
    }

    public function test_inspection_accept_verdict_moves_to_accepted(): void
    {
        $l = $this->mkListing($this->mkUser('sales'), [
            'status' => 'awaiting_buyer', 'buyer_verdict' => 'pending',
            'final_price' => 9000000, 'buyer_name' => 'X',
        ]);
        $this->actingAs($this->mkUser('inspection'));

        Volt::test('inspection.index')
            ->call('openDrawer', $l->id)
            ->call('setVerdict', 'accepted')
            ->assertHasNoErrors();

        $l->refresh();
        $this->assertSame('accepted', $l->status);
        $this->assertSame('accepted', $l->buyer_verdict);
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
            '*FX_USDKRW*' => Http::response(['closePrice' => '1,400.50']),
            '*FX_EURKRW*' => Http::response(['closePrice' => '1,550.00']),
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

    public function test_manager_edit_writes_audit_log_and_overrides(): void
    {
        $l = $this->mkListing($this->mkUser('sales'), ['status' => 'draft', 'expected_price' => 1000000]);
        $this->actingAs($this->mkUser('manager'));

        Volt::test('manage.index')
            ->call('openEdit', $l->id)
            ->set('expected_price', '2000000')
            ->set('status', 'won') // 전이행렬 무시 override
            ->call('save')
            ->assertHasNoErrors();

        $l->refresh();
        $this->assertSame(2000000, $l->expected_price);
        $this->assertSame('won', $l->status);

        $this->assertDatabaseHas('board_audit_logs', ['purchase_listing_id' => $l->id, 'field' => 'expected_price']);
        $this->assertDatabaseHas('board_audit_logs', ['purchase_listing_id' => $l->id, 'field' => 'status', 'action' => 'status_change']);
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

    public function test_inactive_user_is_blocked_from_views(): void
    {
        $u = $this->mkUser('sales');
        $u->update(['is_active' => false]);

        $this->actingAs($u)->get('/listings')->assertForbidden();
    }
}

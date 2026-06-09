<?php

namespace Tests\Feature;

use App\Models\PurchaseListing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class BoardTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function mkUser(string $role, ?string $email = null): User
    {
        return User::create([
            'name' => $role,
            'email' => $email ?? $role.(++$this->seq).'@t.test',
            'password' => 'password',
            'role' => $role,
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
        \Illuminate\Support\Carbon::setTestNow('2026-06-08 09:00:00');
        $this->assertFalse(\App\Support\TimeGate::auctionRegistrationLocked());

        // 월요일 11:00 (마감 후) → 잠금
        \Illuminate\Support\Carbon::setTestNow('2026-06-08 11:00:00');
        $this->assertTrue(\App\Support\TimeGate::auctionRegistrationLocked());

        // 토요일 → 잠금 미적용 (lock_at NULL)
        \Illuminate\Support\Carbon::setTestNow('2026-06-13 15:00:00');
        $this->assertFalse(\App\Support\TimeGate::auctionRegistrationLocked());
        $this->assertNull(\App\Support\TimeGate::auctionLockAt());

        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_adds_listing_through_volt_component(): void
    {
        $kim = $this->mkUser('sales');
        $this->actingAs($kim);

        Volt::test('listings.index')
            ->set('source', 'encar')
            ->set('vehicle_number', '99가9999')
            ->set('vin', 'TESTVIN0001')
            ->set('expected_price', '13500000')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue(
            PurchaseListing::where('vin', 'TESTVIN0001')->where('created_by_user_id', $kim->id)->exists()
        );
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
            ->set('e_expected_price', '2222222')
            ->call('update')
            ->assertHasNoErrors();

        $this->assertSame(2222222, $l->fresh()->expected_price);
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
            ->set('e_expected_price', '9999999')
            ->call('update')
            ->assertHasErrors('e_expected_price');

        $this->assertSame(1000000, $l->fresh()->expected_price);
    }
}

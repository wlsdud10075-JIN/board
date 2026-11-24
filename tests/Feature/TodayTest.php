<?php

namespace Tests\Feature;

use App\Models\InspectionPhoto;
use App\Models\IntegrationEvent;
use App\Models\PurchaseListing;
use App\Models\TransportRequest;
use App\Models\User;
use App\Support\TodayList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** 「오늘 할 일」(2026-11-24 Jin (나)안) — 줄 판정·격리·바로 열기·첫 화면. */
class TodayTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function user(string $role = 'sales', string $permission = 'user'): User
    {
        $this->seq++;

        return User::create([
            'name' => $role.$this->seq, 'email' => "td{$this->seq}@t.test", 'password' => 'password',
            'role' => $role, 'permission' => $permission, 'is_active' => true,
        ]);
    }

    private function listing(User $owner, array $attr = []): PurchaseListing
    {
        $this->seq++;

        return PurchaseListing::create(array_merge([
            'created_by_user_id' => $owner->id, 'source' => 'encar', 'vehicle_number' => '12가'.(2000 + $this->seq),
            'vin' => 'TDY'.str_pad((string) $this->seq, 10, '0', STR_PAD_LEFT), 'status' => 'draft', 'buyer_verdict' => 'none',
            'region' => '경기 안산시',
        ], $attr));
    }

    private function attach(PurchaseListing $l): void
    {
        InspectionPhoto::create(['purchase_listing_id' => $l->id, 's3_path' => 'sales/photos/x.jpg', 'original_name' => 'x.jpg', 'kind' => InspectionPhoto::KIND_SALES_PHOTO, 'sort' => 0]);
    }

    private function kinds(User $u): array
    {
        $this->actingAs($u);

        return TodayList::rows()->pluck('kind', 'listing_id')->all();
    }

    public function test_rows_follow_board_facts(): void
    {
        $s = $this->user();
        $fwd = $this->listing($s, ['status' => 'inspected']);
        $ver = $this->listing($s, ['status' => 'awaiting_buyer', 'buyer_name' => 'BUYER A']);
        $noAtt = $this->listing($s, ['status' => 'accepted', 'buyer_verdict' => 'accepted']);
        $ready = $this->listing($s, ['status' => 'accepted', 'buyer_verdict' => 'accepted']);
        $this->attach($ready);
        $skip = $this->listing($s);
        $skip->forceFill(['inspection_skipped_at' => now(), 'inspection_skip_reason' => 'no_car'])->saveQuietly();
        $plainDraft = $this->listing($s);
        $synced = $this->listing($s, ['status' => 'synced', 'buyer_verdict' => 'accepted', 'car_erp_vehicle_id' => 9]);

        $k = $this->kinds($s);
        $this->assertSame('forward', $k[$fwd->id]);
        $this->assertSame('verdict', $k[$ver->id]);
        $this->assertSame('no_attach', $k[$noAtt->id]);
        $this->assertSame('confirm', $k[$ready->id]);
        $this->assertSame('insp_alert', $k[$skip->id]);
        $this->assertArrayNotHasKey($plainDraft->id, $k);   // 검차 기다리는 중 = 내 일이 아님
        $this->assertArrayNotHasKey($synced->id, $k);
    }

    public function test_erp_failure_rows_use_last_purchase_sync(): void
    {
        $s = $this->user();
        $failed = $this->listing($s, ['status' => 'won', 'buyer_verdict' => 'accepted']);
        IntegrationEvent::create(['direction' => 'outbound', 'target' => 'car-erp', 'event_type' => 'purchase_sync', 'purchase_listing_id' => $failed->id, 'response_status' => 422, 'error' => 'x']);
        $cap = $this->listing($s, ['status' => 'won', 'buyer_verdict' => 'accepted']);
        IntegrationEvent::create(['direction' => 'outbound', 'target' => 'car-erp', 'event_type' => 'purchase_sync', 'purchase_listing_id' => $cap->id, 'response_status' => 409,
            'response_body' => json_encode(['error' => 'general_stock_cap_exceeded', 'general_stock_cap' => ['cap' => 200000000, 'used' => 226800000]])]);
        $pending = $this->listing($s, ['status' => 'won', 'buyer_verdict' => 'accepted']);   // 방금 확정 — Job 대기, 줄 없음

        $this->actingAs($s);
        $rows = TodayList::rows()->keyBy('listing_id');
        $this->assertSame('erp_fail', $rows[$failed->id]['kind']);
        $this->assertStringContainsString('226,800,000', $rows[$cap->id]['detail']);
        $this->assertFalse($rows->has($pending->id));
    }

    public function test_transport_rows_dispatch_done_without_balance_and_rejected(): void
    {
        $s = $this->user();
        $done = $this->listing($s, ['status' => 'synced', 'buyer_verdict' => 'accepted', 'car_erp_vehicle_id' => 3]);
        $rej = $this->listing($s, ['status' => 'synced', 'buyer_verdict' => 'accepted', 'car_erp_vehicle_id' => 4]);
        $paid = $this->listing($s, ['status' => 'synced', 'buyer_verdict' => 'accepted', 'car_erp_vehicle_id' => 5]);
        $base = ['requested_by_user_id' => $s->id, 'revision' => 1, 'address' => '서울', 'send_at' => now(), 'decided_at' => now(), 'company_name' => '위카', 'message' => 'x'];
        foreach ([[$done, TransportRequest::ACCEPTED, null], [$rej, TransportRequest::REJECTED, null], [$paid, TransportRequest::ACCEPTED, now()]] as [$l, $st, $bal]) {
            DB::table('transport_requests')->insert(['purchase_listing_id' => $l->id, 'status' => $st, 'balance_requested_at' => $bal, 'created_at' => now(), 'updated_at' => now()]
                + array_intersect_key($base, array_flip(DB::getSchemaBuilder()->getColumnListing('transport_requests'))));
        }

        $k = $this->kinds($s);
        $this->assertSame('balance', $k[$done->id]);
        $this->assertSame('transport_rejected', $k[$rej->id]);
        $this->assertArrayNotHasKey($paid->id, $k);
        $this->assertStringContainsString('dtab=transport', TodayList::rows()->firstWhere('listing_id', $done->id)['href']);
    }

    public function test_sales_see_only_their_own_and_manager_sees_shared_plus_own(): void
    {
        $a = $this->user();
        $b = $this->user();
        $mgr = $this->user('manager');
        $la = $this->listing($a, ['status' => 'inspected']);
        $lb = $this->listing($b, ['status' => 'inspected']);
        $lm = $this->listing($mgr, ['status' => 'inspected']);

        $this->assertSame([$la->id], array_keys($this->kinds($a)));
        $this->assertSame([$lm->id], array_keys($this->kinds($mgr)));   // 공유 0명 = 본인 것만

        DB::table('manager_salesman')->insert(['manager_user_id' => $mgr->id, 'salesman_user_id' => $b->id]);
        $ids = array_keys($this->kinds($mgr));
        sort($ids);
        $this->assertSame([$lb->id, $lm->id], $ids);

        $wm = $this->user('manager', 'manager');   // 업무관리자 = 전체
        $this->assertCount(3, $this->kinds($wm));
    }

    public function test_page_renders_rows_and_team_table_for_managers(): void
    {
        $s = $this->user();
        $this->listing($s, ['status' => 'inspected']);
        $this->actingAs($s)->get('/today')->assertOk()->assertSee('data-today-row="forward"', false)->assertDontSee('data-today-team', false);

        $wm = $this->user('manager', 'manager');
        $this->actingAs($wm)->get('/today')->assertOk()->assertSee('data-today-team', false);

        Volt::actingAs($wm)->test('today.index')->call('pick', $s->id, 'forward')
            ->assertSee('data-today-row="forward"', false);
    }

    public function test_inspection_role_cannot_open_today_and_keeps_its_home(): void
    {
        $i = $this->user('inspection');
        $this->actingAs($i)->get('/today')->assertForbidden();
        $this->actingAs($i)->get('/dashboard')->assertRedirect(route('inspection'));
        $this->actingAs($this->user())->get('/dashboard')->assertRedirect(route('today'));
        $this->actingAs($this->user('manager', 'manager'))->get('/dashboard')->assertRedirect(route('today'));
    }

    public function test_deep_link_opens_drawer_only_in_scope_and_state(): void
    {
        $a = $this->user();
        $b = $this->user();
        $mine = $this->listing($a, ['status' => 'inspected']);
        $theirs = $this->listing($b, ['status' => 'inspected']);
        $moved = $this->listing($a, ['status' => 'awaiting_buyer']);

        $this->actingAs($a);
        Livewire::withQueryParams(['open' => $mine->id, 'from' => 'today'])->test('forwarding.index')
            ->assertSet('detailId', $mine->id)->assertSet('open', 0)->assertSee('data-back-today', false);
        Livewire::withQueryParams(['open' => $theirs->id])->test('forwarding.index')->assertSet('detailId', null);
        Livewire::withQueryParams(['open' => $moved->id])->test('forwarding.index')->assertSet('detailId', null);   // 상태가 지나간 차 = 무시(404 아님)
        Livewire::withQueryParams(['open' => $moved->id])->test('verdicts.index')->assertSet('detailId', $moved->id);
    }

    public function test_listings_open_param_sets_drawer_tab_and_keeps_edit_link_on_transport(): void
    {
        $a = $this->user();
        $l = $this->listing($a);
        $this->actingAs($a);
        Livewire::withQueryParams(['open' => $l->id, 'dtab' => 'transport'])->test('listings.index')->assertSet('drawerTab', 'transport')->assertSet('tab', 'all');
        Livewire::withQueryParams(['open' => $l->id])->test('listings.index')->assertSet('drawerTab', 'basic');
        Livewire::withQueryParams(['edit' => $l->id])->test('listings.index')->assertSet('drawerTab', 'transport');   // 종·웹푸시 배차 링크(종전)
    }
}

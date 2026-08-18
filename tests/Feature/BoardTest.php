<?php

namespace Tests\Feature;

use App\Jobs\SendOfferToBuyer;
use App\Jobs\SyncWonListingToCarErp;
use App\Models\BoardAuditLog;
use App\Models\ExchangeRate;
use App\Models\InspectionAssignment;
use App\Models\InspectionPhoto;
use App\Models\IntegrationEvent;
use App\Models\PromotionRequest;
use App\Models\PurchaseListing;
use App\Models\Setting;
use App\Models\User;
use App\Services\BizmAlimtalkService;
use App\Services\CarErpReadService;
use App\Services\ExchangeRateService;
use App\Services\ListingEnrichment;
use App\Services\RegionInspectionNotifier;
use App\Services\RespondIoService;
use App\Services\VerdictService;
use App\Support\ListingLink;
use App\Support\Region;
use App\Support\TimeGate;
use App\Support\UploadGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
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
            // 구매확정은 **바이어 필수**다(2026-08-10 Jin, 매입 등록 락의 전제) → 기본으로 채운다.
            // 미선택 동작은 `test_conclude_requires_buyer` 에서 명시적으로 null 로 덮어 검증한다.
            'car_erp_buyer_id' => 7,
        ], $attr));
    }

    /**
     * 딜러 첨부는 `/auction`(구매·경매)에서만 올리는데 그 화면은 accepted·won 만 다룬다
     * ⇒ 연동 B 로 ERP 에 넘어가면(**synced**) board 어디서도 다시 볼 수 없었다.
     * 매입예정 드로어가 그 조회처다 — 본인 차를 **전 상태**로 열 수 있는 유일한 화면이라서.
     */
    public function test_listings_drawer_shows_dealer_attachments_even_after_synced(): void
    {
        $kim = $this->mkUser('sales');
        $l = $this->mkListing($kim, ['status' => 'draft']);
        $l->salesAttachments()->create(['s3_path' => 's/photo.jpg', 'original_name' => 'photo.jpg', 'sort' => 1, 'kind' => InspectionPhoto::KIND_SALES_PHOTO]);
        $l->salesAttachments()->create(['s3_path' => 's/reg.pdf', 'original_name' => 'reg.pdf', 'sort' => 2, 'kind' => InspectionPhoto::KIND_SALES_DOCUMENT]);
        // 검차사진은 딜러 첨부가 아니다 — 이 블록에 섞이면 안 된다.
        $l->photos()->create(['s3_path' => 'i/insp.jpg', 'original_name' => 'insp.jpg', 'sort' => 1, 'kind' => InspectionPhoto::KIND_INSPECTION]);
        $l->forceFill(['status' => 'synced'])->saveQuietly();   // ERP 전환 완료 — 여기서도 보여야 한다

        $this->actingAs($kim);
        Volt::test('listings.index')
            ->call('openEdit', $l->id)
            ->assertSee(__('listings.attach_view.title'))
            ->assertSee('reg.pdf')            // 서류는 파일명 칩으로
            ->assertDontSee('insp.jpg');      // 검차사진은 이 블록에 안 나온다
    }

    /** 첨부가 없으면 "없다"고 말한다 — 빈 화면은 "못 불러온 것"과 구분이 안 된다. */
    public function test_listings_drawer_says_when_no_attachments(): void
    {
        $kim = $this->mkUser('sales');
        $l = $this->mkListing($kim);

        $this->actingAs($kim);
        Volt::test('listings.index')
            ->call('openEdit', $l->id)
            ->assertSee(__('listings.attach_view.empty'));
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
        // 판매가 = 13,000,000 − 0%(할인) − 0(차감) = 13,000,000 (매도비 제외, Model A)
        // 최종금액 = 13,000,000 + 1640 × 1380(임시환율) 스냅샷
        $this->assertSame(13000000 + 1640 * (int) config('board.default_krw_per_usd'), $l->final_price);
    }

    /**
     * 셀프검차매입 — ssancar 검차글(영상)이 없어 자동전이가 안 걸리는 차를
     * 등록 즉시 accepted 로 만들어 경매/구매에서 마무리한다.
     */
    public function test_self_inspection_listing_goes_straight_to_auction(): void
    {
        $kim = $this->mkUser('sales');
        $this->actingAs($kim);

        Volt::test('listings.index')
            ->set('origin', 'self_inspection')
            ->set('vehicle_number', '77사7777')
            ->set('vin', 'SELFVIN0001')
            ->set('car_cost', '10000000')->set('discount_rate', '0')->set('shipping_usd', 1640)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('auction'));

        $l = PurchaseListing::where('vin', 'SELFVIN0001')->first();
        $this->assertNotNull($l);
        $this->assertSame('encar', $l->source);            // 즉시구매 — 경매 10:00 잠금 대상 아님
        $this->assertNull($l->lock_at);
        $this->assertSame('accepted', $l->status);         // 현지확인·전달·회신 건너뜀
        $this->assertSame('accepted', $l->buyer_verdict);  // "accepted 면 회신도 accepted" 불변식 유지
        $this->assertSame('manual', $l->verdict_channel);  // respond.io 폴러(auto 만 조회)가 안 집어감

        // 경매/구매 화면에 바로 뜬다 → 정보 입력 후 구매확정 → 연동 B 까지 완주
        Bus::fake();
        Volt::test('auction.index')
            ->assertSee('77사7777')
            ->call('openDetail', $l->id)
            ->set('owner_name', '차주')
            ->set('payee_name', '판매상사')
            ->set('sale_price', '7000')     // 셀프검차 필수 락 — 차값·판매가·통화
            ->set('quoteCurrency', 'KRW')
            ->set('buyerId', 7)             // 구매확정은 바이어 필수(매입 등록 락의 전제)
            ->call('conclude', $l->id, 'won')
            ->assertHasNoErrors();

        $this->assertSame('won', $l->fresh()->status);
        Bus::assertDispatched(SyncWonListingToCarErp::class);
    }

    /** 셀프검차 차량이 현지확인 화면에 뜨면 이 기능이 건너뛰려던 그 화면에 되돌아온 것이다. */
    public function test_self_inspection_listing_hidden_from_inspection_screen(): void
    {
        $kim = $this->mkUser('sales');
        $this->mkListing($kim, ['vehicle_number' => '88아8888', 'region' => '경기 수원시']);   // 평범한 검차대기
        $this->mkListing($kim, [
            'vehicle_number' => '77사7777', 'origin' => 'self_inspection',
            'region' => '경기 수원시', 'status' => 'accepted', 'buyer_verdict' => 'accepted',
        ]);

        $this->actingAs($this->mkUser('manager'));   // canAssign → 지역필터 없음(전체 노출)
        Volt::test('inspection.index')
            ->assertSee('88아8888')
            ->assertDontSee('77사7777');
    }

    /**
     * 셀프검차매입을 잘못 고른 차는 현지확인에서 origin 으로 걸러지므로,
     * 관리자가 origin 을 되돌릴 수 없으면 그 차는 영영 검차대상에서 사라진다(편도 문).
     */
    public function test_manager_can_revert_self_inspection_back_to_inspection(): void
    {
        $kim = $this->mkUser('sales');
        $l = $this->mkListing($kim, [
            'vehicle_number' => '77사7777', 'origin' => 'self_inspection',
            'region' => '경기 수원시', 'status' => 'accepted', 'buyer_verdict' => 'accepted',
        ]);

        $this->actingAs($this->mkUser('manager'));
        Volt::test('inspection.index')->assertDontSee('77사7777');

        Volt::test('manage.index')
            ->call('openEdit', $l->id)
            ->set('origin', 'encar')
            ->set('status', 'draft')       // manager override 로 전이가드 우회
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('encar', $l->fresh()->origin);
        Volt::test('inspection.index')->assertSee('77사7777');   // 검차대상으로 복귀
    }

    /** 링크 파싱이 origin 을 덮어써서 방금 누른 셀프검차 토글이 조용히 풀리면 안 된다. */
    public function test_self_inspection_survives_encar_link_parse(): void
    {
        Http::fake(['*api.encar.com*' => Http::response(['vehicleNo' => '244로9100'], 200)]);
        $this->actingAs($this->mkUser('sales'));

        Volt::test('listings.index')
            ->call('setOrigin', 'self_inspection')
            ->set('encarLink', 'https://fem.encar.com/cars/detail/42176484')
            ->call('parseLink', 'encar')
            ->assertSet('origin', 'self_inspection')   // 카테고리는 영업의 선택 유지
            ->assertSet('source', 'encar')
            ->assertSet('encar_id', '42176484');       // 식별자는 그대로 받는다
    }

    public function test_listings_blocks_active_duplicate_vin(): void
    {
        $kim = $this->mkUser('sales');
        $this->mkListing($kim, ['vin' => 'DUPVIN00001', 'vehicle_number' => '11가1111']);
        $this->actingAs($kim);

        Volt::test('listings.index')
            ->set('source', 'encar')
            ->set('vehicle_number', '22나2222')   // 다른 번호판, 같은 VIN
            ->set('vin', 'DUPVIN00001')
            ->set('car_cost', '10000000')->set('discount_rate', '0')->set('shipping_usd', 1640)
            ->call('save')
            ->assertHasErrors('vin');   // 친절한 중복 에러(raw 500 아님)

        $this->assertSame(1, PurchaseListing::where('vin', 'DUPVIN00001')->count());
    }

    public function test_listings_allows_reregister_after_soft_delete(): void
    {
        $kim = $this->mkUser('sales');
        $old = $this->mkListing($kim, ['vin' => 'REVIVE00001', 'vehicle_number' => '33다3333']);
        $old->delete();   // 소프트삭제 — VIN 을 계속 쥐고 있음(예전엔 재등록 시 raw 500)
        $this->actingAs($kim);

        Volt::test('listings.index')
            ->set('source', 'encar')
            ->set('vehicle_number', '33다3333')   // 같은 차 재등록
            ->set('vin', 'REVIVE00001')
            ->set('car_cost', '10000000')->set('discount_rate', '0')->set('shipping_usd', 1640)
            ->call('save')
            ->assertHasNoErrors();

        $active = PurchaseListing::where('vin', 'REVIVE00001')->first();   // 삭제행 제외 → 새 활성행
        $this->assertNotNull($active);
        $this->assertNotSame($old->id, $active->id);   // 복원 아님, 새 레코드
        $this->assertSame('draft', $active->status);
    }

    public function test_listings_blocks_active_duplicate_auction_lot(): void
    {
        $mgr = $this->mkUser('manager');   // isManager → TimeGate 우회
        $this->mkListing($mgr, ['source' => 'auction', 'vehicle_number' => '44라4444', 'vin' => 'AUCVIN00001', 'auction_venue' => '현대오토', 'lot_number' => 'L-100']);
        $this->actingAs($mgr);

        Volt::test('listings.index')
            ->set('origin', 'auction')
            ->set('vehicle_number', '55마5555')   // 다른 차, 같은 출품번호
            ->set('auction_venue', '현대오토')
            ->set('lot_number', 'L-100')
            ->set('car_cost', '10000000')->set('discount_rate', '0')->set('shipping_usd', 1640)
            ->call('save')
            ->assertHasErrors('lot_number');
    }

    // ─────────────────────── 차량 첨부 (영업 업로드 → 연동 B car-erp) ───────────────────────

    public function test_sales_attachments_separate_from_inspection_photos(): void
    {
        $l = $this->mkListing($this->mkUser('sales'));
        $l->photos()->create(['s3_path' => 'i/insp.jpg', 'original_name' => 'insp.jpg', 'sort' => 1, 'kind' => InspectionPhoto::KIND_INSPECTION]);
        $l->salesAttachments()->create(['s3_path' => 's/photo.jpg', 'original_name' => 'photo.jpg', 'sort' => 1, 'kind' => InspectionPhoto::KIND_SALES_PHOTO]);
        $l->salesAttachments()->create(['s3_path' => 's/reg.pdf', 'original_name' => 'reg.pdf', 'sort' => 2, 'kind' => InspectionPhoto::KIND_SALES_DOCUMENT]);

        // photos() = 검차사진만, salesAttachments() = 영업 자료만 (서로 격리)
        $this->assertSame(1, $l->photos()->count());
        $this->assertSame('i/insp.jpg', $l->photos()->first()->s3_path);
        $this->assertSame(2, $l->salesAttachments()->count());
    }

    public function test_listings_save_stores_sales_attachments(): void
    {
        Storage::fake('public');
        config(['board.photo_disk' => 'public']);
        $kim = $this->mkUser('sales');
        $this->actingAs($kim);

        // 첨부파일 1칸 — 이미지=사진/그 외=서류 자동분류
        Volt::test('listings.index')
            ->set('source', 'encar')
            ->set('vehicle_number', '77가7777')
            ->set('vin', 'ATTACHVIN01')
            ->set('salesFiles', [
                UploadedFile::fake()->image('front.jpg'),
                UploadedFile::fake()->image('side.jpg'),
                UploadedFile::fake()->create('차량등록증.pdf', 20, 'application/pdf'),
            ])
            ->call('save')
            ->assertHasNoErrors();

        $l = PurchaseListing::where('vin', 'ATTACHVIN01')->first();
        $this->assertNotNull($l);
        $this->assertSame(3, $l->salesAttachments()->count());
        $this->assertSame(2, $l->salesAttachments()->where('kind', InspectionPhoto::KIND_SALES_PHOTO)->count());   // 이미지 2 → 사진
        $doc = $l->salesAttachments()->where('kind', InspectionPhoto::KIND_SALES_DOCUMENT)->first();              // pdf → 서류
        $this->assertNotNull($doc);
        $this->assertFalse((bool) $doc->share_to_buyer);          // 서류는 바이어 미전송
        $this->assertSame($kim->id, $doc->uploaded_by_user_id);
        Storage::disk('public')->assertExists($doc->s3_path);
    }

    public function test_executable_upload_blocked_in_listings(): void
    {
        Storage::fake('public');
        config(['board.photo_disk' => 'public']);
        $this->actingAs($this->mkUser('sales'));

        Volt::test('listings.index')
            ->set('source', 'encar')
            ->set('vehicle_number', '44가4444')
            ->set('vin', 'EXEVIN0001')
            ->set('salesFiles', [UploadedFile::fake()->create('virus.exe', 10)])
            ->call('save')
            ->assertHasErrors('salesFiles');   // 실행파일 차단 → listing 생성 안 됨

        $this->assertNull(PurchaseListing::where('vin', 'EXEVIN0001')->first());
    }

    public function test_upload_guard_blocks_executables_allows_docs(): void
    {
        // 실행파일 차단 (대소문자 무관)
        $this->assertTrue(UploadGuard::isExecutable('virus.exe'));
        $this->assertTrue(UploadGuard::isExecutable('Setup.MSI'));
        $this->assertTrue(UploadGuard::isExecutable('run.bat'));
        // 사진·서류 허용
        $this->assertFalse(UploadGuard::isExecutable('차량등록증.pdf'));
        $this->assertFalse(UploadGuard::isExecutable('front.jpg'));
        $this->assertFalse(UploadGuard::isExecutable('no-extension'));
    }

    public function test_attachment_cap_enforced(): void
    {
        Storage::fake('public');
        config(['board.photo_disk' => 'public', 'board.attachment_max' => 10]);
        $this->actingAs($this->mkUser('sales'));

        $eleven = [];
        for ($i = 0; $i < 11; $i++) {
            $eleven[] = UploadedFile::fake()->image("p{$i}.jpg");
        }

        Volt::test('listings.index')
            ->set('source', 'encar')
            ->set('vehicle_number', '55가5555')
            ->set('vin', 'CAPVIN0001')
            ->set('salesFiles', $eleven)
            ->call('save')
            ->assertHasErrors('salesFiles');

        $this->assertNull(PurchaseListing::where('vin', 'CAPVIN0001')->first());
    }

    public function test_sync_payload_includes_sales_attachments(): void
    {
        config(['services.car_erp.base_url' => 'https://carerp.test', 'services.car_erp.hmac_secret' => 'shh']);
        Http::fake(['*/api/internal/purchase-sync' => Http::response(['vehicle_id' => 900], 200)]);

        $l = $this->mkListing($this->mkUser('sales'), ['status' => 'won', 'source' => 'auction', 'final_price' => 9000000]);
        $l->photos()->create(['s3_path' => 'i/x.jpg', 'original_name' => 'x.jpg', 'sort' => 1, 'kind' => InspectionPhoto::KIND_INSPECTION]);
        $l->salesAttachments()->create(['s3_path' => 's/a.jpg', 'original_name' => 'a.jpg', 'sort' => 1, 'kind' => InspectionPhoto::KIND_SALES_PHOTO]);
        $l->salesAttachments()->create(['s3_path' => 's/r.pdf', 'original_name' => 'r.pdf', 'sort' => 2, 'kind' => InspectionPhoto::KIND_SALES_DOCUMENT]);

        (new SyncWonListingToCarErp($l->id))->handle();

        Http::assertSent(function ($request) {
            $att = $request['attachments'] ?? [];

            return $request['contract_version'] === 4
                && count($att) === 2                                   // 검차사진(i/x.jpg)은 제외, 영업 자료만
                && collect($att)->pluck('s3_path')->sort()->values()->all() === ['s/a.jpg', 's/r.pdf']
                && collect($att)->firstWhere('kind', 'sales_document')['s3_path'] === 's/r.pdf';
        });
    }

    public function test_sync_payload_v3_amounts_buyer_consignee(): void
    {
        config([
            'services.car_erp.base_url' => 'https://carerp.test', 'services.car_erp.hmac_secret' => 'shh',
            'board.default_krw_per_usd' => 1400, 'board.default_krw_per_eur' => 1500, 'board.sales_fee' => 440000,
        ]);
        Http::fake(['*/api/internal/purchase-sync' => Http::response(['vehicle_id' => 901], 200)]);

        $l = $this->mkListing($this->mkUser('sales'), [
            'status' => 'won', 'source' => 'auction', 'final_price' => 12736000,
            'car_cost' => 10000000, 'expected_price_currency' => 'KRW', 'discount_rate' => 1, 'shipping_usd' => 1640,
            'offer_currency' => 'EUR', 'offer_rate' => 1500, 'car_erp_buyer_id' => 55, 'car_erp_consignee_id' => 66,
        ]);

        (new SyncWonListingToCarErp($l->id))->handle();

        Http::assertSent(function ($r) {
            return $r['contract_version'] === 4
                && $r['purchase_price_krw'] === 10000000         // 원가 그대로(할인 미반영, Model A)
                && $r['selling_fee_krw'] === 440000              // 매도비(매입탭 별도, 회사 부담)
                && $r['sale_currency'] === 'EUR'
                && $r['sale_exchange_rate'] === 1500
                && (float) $r['sale_price'] === 6600.0            // 판매가 9,900,000(=1000만−1%) / 1500, 매도비 제외 (whole → JSON int)
                && $r['transport_fee'] === 1530.67               // 1640 USD ×1400 / 1500 (판매통화 환산)
                && $r['buyer_id'] === 55 && $r['consignee_id'] === 66;
        });
    }

    public function test_auction_buyer_dropdown_loads_and_persists(): void
    {
        Bus::fake();
        config(['services.car_erp.base_url' => 'https://carerp.test', 'services.car_erp.read_hmac_secret' => 'rs']);
        Http::fake([
            '*/api/internal/board/buyers*' => Http::response(['count' => 1, 'data' => [['id' => 55, 'name' => 'Faturat', 'country' => 'Kosovo']]], 200),
            '*/api/internal/board/consignees*' => Http::response(['count' => 0, 'data' => []], 200),
        ]);

        $l = $this->mkListing($this->mkUser('sales'), ['status' => 'accepted', 'buyer_verdict' => 'accepted', 'source' => 'auction', 'final_price' => 9000000]);
        $this->actingAs($this->mkUser('manager'));

        Volt::test('auction.index')
            ->call('openDetail', $l->id)
            ->assertSet('buyerOpts', [['id' => 55, 'name' => 'Faturat', 'country' => 'Kosovo']])
            ->set('buyerId', 55)
            ->call('conclude', $l->id, 'won')->assertHasNoErrors();

        $this->assertSame(55, $l->fresh()->car_erp_buyer_id);
    }

    // ── 셀프검차 금액 구멍 (2026-08-10 heymanboard 67도4322 실장애) ──

    /**
     * ★금액 없이 won 시키면 car-erp 가 422(`final_price: required_without:purchase_price_krw`)를 내는데
     * 영업 화면엔 "처리 완료"만 뜬다 = 조용한 실패. 여기서 세운다.
     */
    public function test_conclude_won_is_blocked_without_any_amount(): void
    {
        Bus::fake();
        $l = $this->mkListing($this->mkUser('sales'), [
            'status' => 'accepted', 'buyer_verdict' => 'accepted', 'origin' => 'self_inspection',
            'source' => 'encar', 'car_cost' => null, 'final_price' => null,
        ]);
        $this->actingAs($this->mkUser('manager'));

        Volt::test('auction.index')->call('openDetail', $l->id)
            ->call('conclude', $l->id, 'won')
            ->assertHasErrors('car_cost');

        $this->assertSame('accepted', $l->fresh()->status);   // won 으로 넘어가지 않는다
        Bus::assertNotDispatched(SyncWonListingToCarErp::class);
    }

    /** 경매/구매 탭에서 차값을 넣으면 그대로 won → 연동 B 발사(셀프검차가 금액을 넣는 유일한 지점). */
    public function test_auction_drawer_can_enter_car_cost_and_then_conclude(): void
    {
        Bus::fake();
        $l = $this->mkListing($this->mkUser('sales'), [
            'status' => 'accepted', 'buyer_verdict' => 'accepted', 'origin' => 'self_inspection',
            'source' => 'encar', 'car_cost' => null, 'final_price' => null,
        ]);
        $this->actingAs($this->mkUser('manager'));

        Volt::test('auction.index')->call('openDetail', $l->id)
            ->set('car_cost', '12000000')
            ->set('sale_price', '8000')->set('quoteCurrency', 'KRW')   // 셀프검차 필수 락
            ->call('conclude', $l->id, 'won')->assertHasNoErrors();

        $this->assertSame(12000000, (int) $l->fresh()->car_cost);
        $this->assertSame('won', $l->fresh()->status);
        Bus::assertDispatched(SyncWonListingToCarErp::class);
    }

    /**
     * 이미 won 인데 금액 누락으로 ERP 에 못 넘어간 차 — 금액만 채우면 재발사돼야 한다.
     * 안 그러면 영업이 금액을 넣어도 아무 일도 안 일어나고, /manage 재전송은 super 전용이라 손이 없다.
     */
    public function test_saving_amount_on_stuck_won_listing_resends_to_car_erp(): void
    {
        Bus::fake();
        $l = $this->mkListing($this->mkUser('sales'), [
            'status' => 'won', 'buyer_verdict' => 'accepted', 'origin' => 'self_inspection',
            'source' => 'encar', 'car_cost' => null, 'final_price' => null, 'car_erp_vehicle_id' => null,
        ]);
        $this->actingAs($this->mkUser('manager'));

        Volt::test('auction.index')->call('openDetail', $l->id)
            ->set('car_cost', '9500000')
            ->call('savePayee')->assertHasNoErrors();

        $this->assertSame(9500000, (int) $l->fresh()->car_cost);
        Bus::assertDispatched(SyncWonListingToCarErp::class);
    }

    /**
     * 셀프검차가 **아닌** 출처는 바이어 금액을 직접 타이핑받지 않고 `/forwarding` 과 같은 공식(`totalKrw`)으로 만든다.
     * 직접 입력받으면 할인·차감액과 숫자가 갈린다.
     */
    public function test_auction_quote_fields_recompute_final_price(): void
    {
        Bus::fake();
        $l = $this->mkListing($this->mkUser('sales'), [
            'status' => 'accepted', 'buyer_verdict' => 'accepted', 'origin' => 'encar',
            'source' => 'encar', 'car_cost' => null, 'final_price' => null,
            'expected_price_currency' => 'KRW',
        ]);
        $this->actingAs($this->mkUser('manager'));

        Volt::test('auction.index')->call('openDetail', $l->id)
            ->set('car_cost', '10000000')
            ->set('discount_rate', '10')          // 관례할인 10% → 9,000,000
            ->set('sale_discount', '500000')      // 차감액 → 8,500,000
            ->call('conclude', $l->id, 'won')->assertHasNoErrors();

        $f = $l->fresh();
        $this->assertSame(8500000, (int) $f->final_price);   // 배송 미선택
        $this->assertSame(10.0, (float) $f->discount_rate);
        $this->assertSame(500000, (int) $f->sale_discount_amount);
    }

    /**
     * ★셀프검차매입 — 매도비는 **차값에 포함**된 금액이라 ERP 매입가에서 뺀다(2026-08-10 Jin 확정).
     * 빼지 않으면 매도비가 두 번 잡혀 car-erp 부가세마진(매입가 × 9%)까지 부풀어 오른다.
     */
    public function test_self_inspection_purchase_price_excludes_selling_fee(): void
    {
        $l = $this->mkListing($this->mkUser('sales'), [
            'origin' => 'self_inspection', 'source' => 'encar',
            'car_cost' => 13600000, 'expected_price_currency' => 'KRW', 'selling_fee' => 440000,
        ]);

        $this->assertSame(13160000, $l->purchasePriceKrw(1400, 1500));   // 13,600,000 − 440,000
        $this->assertSame(440000, $l->sellingFeeKrw(1400, 1500));
        // 합계가 영업이 적은 차값 그대로여야 한다
        $this->assertSame(13600000, $l->purchasePriceKrw(1400, 1500) + $l->sellingFeeKrw(1400, 1500));
    }

    /** 다른 출처는 매도비가 **회사 부담 별도**라 차값에서 빼면 안 된다 — 빼면 매입가가 깎인다. */
    public function test_non_self_inspection_purchase_price_keeps_full_car_cost(): void
    {
        $l = $this->mkListing($this->mkUser('sales'), [
            'origin' => 'encar', 'source' => 'encar',
            'car_cost' => 13600000, 'expected_price_currency' => 'KRW',
        ]);

        $this->assertSame(13600000, $l->purchasePriceKrw(1400, 1500));            // 그대로
        $this->assertSame((int) config('board.sales_fee'), $l->sellingFeeKrw(1400, 1500));   // 고정값 유지
    }

    /** 셀프검차 6칸 — 판매가·통화·환율·운임비를 적은 그대로 저장하고 최종금액은 판매가×환율. */
    public function test_self_inspection_amount_fields_are_saved_as_entered(): void
    {
        Bus::fake();
        $l = $this->mkListing($this->mkUser('sales'), [
            'status' => 'accepted', 'buyer_verdict' => 'accepted', 'origin' => 'self_inspection',
            'source' => 'encar', 'car_cost' => null, 'final_price' => null, 'expected_price_currency' => 'KRW',
        ]);
        $this->actingAs($this->mkUser('manager'));

        Volt::test('auction.index')->call('openDetail', $l->id)
            ->assertSet('selling_fee', (string) (int) config('board.sales_fee'))   // 기본값 미리 채움
            ->set('car_cost', '13600000')
            ->set('selling_fee', '440000')
            ->set('quoteCurrency', 'USD')
            ->set('sale_price', '8590')
            ->set('offer_rate', '1400')
            ->set('transport_fee', '1350')      // 판매통화(USD) 기준 · 선택지 밖 값도 허용(직접입력)
            ->call('conclude', $l->id, 'won')->assertHasNoErrors();

        $f = $l->fresh();
        $this->assertSame(440000, (int) $f->selling_fee);
        $this->assertSame(8590.0, (float) $f->sale_price);
        $this->assertSame('USD', $f->offer_currency);
        $this->assertSame(1400, (int) $f->offer_rate);
        $this->assertSame(1350.0, (float) $f->transport_fee);
        // ⚠️ USD 선택형(shipping_usd)과 같이 들고 있으면 어느 게 진짜인지 갈린다 — 셀프검차는 안 쓴다
        $this->assertNull($f->shipping_usd);
        // ★자동계산 없음(2026-08-10 Jin) — 판매가×환율로 final_price 를 만들지 않는다
        $this->assertNull($f->final_price);
        // 할인·차감액은 셀프검차에 없는 개념 — 건드리지 않는다
        $this->assertNull($f->sale_discount_amount);
    }

    /** 연동 B payload — 셀프검차는 매입가에서 매도비가 빠지고 판매가는 적은 값 그대로 나간다. */
    public function test_self_inspection_sync_payload_splits_selling_fee(): void
    {
        config(['services.car_erp.base_url' => 'https://carerp.test', 'services.car_erp.hmac_secret' => 's']);
        Http::fake(['*' => Http::response(['vehicle_id' => 900], 201)]);

        $l = $this->mkListing($this->mkUser('sales'), [
            'status' => 'won', 'buyer_verdict' => 'accepted', 'origin' => 'self_inspection', 'source' => 'encar',
            'car_cost' => 13600000, 'expected_price_currency' => 'KRW', 'selling_fee' => 440000,
            // ★final_price 는 **비어 있다**(자동계산 안 함) — 그래도 판매 통화·환율이 실려야 한다.
            //   안 실리면 car-erp 가 `sale_price>0 && rate>0` 조건에서 판매 pre-fill 을 통째로 보류한다.
            'sale_price' => 8590, 'offer_currency' => 'USD', 'offer_rate' => 1400, 'final_price' => null,
        ]);

        (new SyncWonListingToCarErp($l->id))->handle();

        Http::assertSent(function ($req) {
            if (! str_contains($req->url(), 'purchase-sync')) {
                return false;   // 다른 요청이 먼저 통과시키면 본문을 안 본다(SKILLS §11-14)
            }
            $b = json_decode($req->body(), true);

            return ($b['purchase_price_krw'] ?? null) === 13160000
                && ($b['selling_fee_krw'] ?? null) === 440000
                && (float) ($b['sale_price'] ?? 0) === 8590.0
                && ($b['sale_currency'] ?? null) === 'USD'
                && ($b['sale_exchange_rate'] ?? null) === 1400;
        });
    }

    /**
     * ★셀프검차 필수 락 (2026-08-10 Jin) — **차값·판매가 둘 다** 있어야 구매확정된다.
     * 판매가가 비면 car-erp 가 판매 pre-fill 을 보류해(`sale_price>0 && rate>0`) ERP 판매탭이 빈 채로 생긴다.
     */
    public function test_self_inspection_requires_sale_price_to_conclude(): void
    {
        Bus::fake();
        $l = $this->mkListing($this->mkUser('sales'), [
            'status' => 'accepted', 'buyer_verdict' => 'accepted', 'origin' => 'self_inspection',
            'source' => 'encar', 'expected_price_currency' => 'KRW',
        ]);
        $this->actingAs($this->mkUser('manager'));

        // 차값만 있고 판매가가 없으면 막힌다
        Volt::test('auction.index')->call('openDetail', $l->id)
            ->set('car_cost', '10000000')->set('quoteCurrency', 'KRW')
            ->call('conclude', $l->id, 'won')
            ->assertHasErrors('sale_price');

        $this->assertSame('accepted', $l->fresh()->status);
        Bus::assertNotDispatched(SyncWonListingToCarErp::class);

        // 판매가까지 넣으면 통과
        Volt::test('auction.index')->call('openDetail', $l->id)
            ->set('car_cost', '10000000')
            ->set('sale_price', '7000')->set('quoteCurrency', 'KRW')
            ->call('conclude', $l->id, 'won')->assertHasNoErrors();

        $this->assertSame('won', $l->fresh()->status);
        Bus::assertDispatched(SyncWonListingToCarErp::class);
    }

    /**
     * ★통화 미선택 락 (2026-08-10, 실측으로 발견) — KRW 를 미리 골라두면 USD 판매가를 적고 통화를 안 눌러도
     * 그대로 통과해 ERP 에 **8,590 USD → 8,590원**으로 박힌다. 실제의 1/1400 인데 경고도 없다.
     */
    public function test_self_inspection_requires_explicit_currency(): void
    {
        Bus::fake();
        $l = $this->mkListing($this->mkUser('sales'), [
            'status' => 'accepted', 'buyer_verdict' => 'accepted', 'origin' => 'self_inspection',
            'source' => 'encar', 'expected_price_currency' => 'KRW',
        ]);
        $this->actingAs($this->mkUser('manager'));

        Volt::test('auction.index')->call('openDetail', $l->id)
            ->assertSet('quoteCurrency', '')      // 미선택으로 시작 — KRW 로 흘러가지 않는다
            ->set('car_cost', '12000000')
            ->set('sale_price', '8590')
            ->call('conclude', $l->id, 'won')
            ->assertHasErrors('quoteCurrency');

        $this->assertSame('accepted', $l->fresh()->status);
        $this->assertNull($l->fresh()->offer_currency);
        Bus::assertNotDispatched(SyncWonListingToCarErp::class);
    }

    /**
     * ★환율 락 — 통화를 골라도 환율칸이 '1' 로 미리 채워져 있으면 USD 딜이 **환율 1** 로 ERP 에 박힌다
     * (통화 락을 통과한 뒤 생기는 두 번째 구멍). 그래서 셀프검차는 환율을 미리 채우지 않는다.
     */
    public function test_self_inspection_requires_rate_for_foreign_currency(): void
    {
        Bus::fake();
        $l = $this->mkListing($this->mkUser('sales'), [
            'status' => 'accepted', 'buyer_verdict' => 'accepted', 'origin' => 'self_inspection',
            'source' => 'encar', 'expected_price_currency' => 'KRW',
        ]);
        $this->actingAs($this->mkUser('manager'));

        $c = Volt::test('auction.index')->call('openDetail', $l->id)
            ->assertSet('offer_rate', null)       // 미리 채우지 않는다
            ->set('car_cost', '12000000')
            ->set('sale_price', '8590')
            ->set('quoteCurrency', 'USD')
            ->call('conclude', $l->id, 'won')
            ->assertHasErrors('offer_rate');

        $this->assertSame('accepted', $l->fresh()->status);

        // 환율까지 넣으면 통과
        $c->set('offer_rate', '1400')->call('conclude', $l->id, 'won')->assertHasNoErrors();
        $this->assertSame('won', $l->fresh()->status);
        $this->assertSame(1400, (int) $l->fresh()->offer_rate);
    }

    /** 원화 딜은 환율을 안 물어본다 — 1 이 자명해서 물으면 잡일만 는다. */
    public function test_krw_self_inspection_does_not_require_rate(): void
    {
        Bus::fake();
        $l = $this->mkListing($this->mkUser('sales'), [
            'status' => 'accepted', 'buyer_verdict' => 'accepted', 'origin' => 'self_inspection',
            'source' => 'encar', 'expected_price_currency' => 'KRW',
        ]);
        $this->actingAs($this->mkUser('manager'));

        Volt::test('auction.index')->call('openDetail', $l->id)
            ->set('car_cost', '12000000')
            ->set('sale_price', '11000000')
            ->set('quoteCurrency', 'KRW')
            ->call('conclude', $l->id, 'won')->assertHasNoErrors();

        $this->assertSame('won', $l->fresh()->status);
        $this->assertSame(1, (int) $l->fresh()->offer_rate);
    }

    /** 판매가 락은 셀프검차 전용 — 다른 출처는 견적 씬에서 채워지므로 여기서 막으면 기존 흐름이 죽는다. */
    public function test_sale_price_lock_does_not_apply_to_other_origins(): void
    {
        Bus::fake();
        $l = $this->mkListing($this->mkUser('sales'), [
            'status' => 'accepted', 'buyer_verdict' => 'accepted', 'origin' => 'encar',
            'source' => 'auction', 'car_cost' => null, 'final_price' => 9000000, 'sale_price' => null,
        ]);
        $this->actingAs($this->mkUser('manager'));

        Volt::test('auction.index')->call('openDetail', $l->id)
            ->call('conclude', $l->id, 'won')->assertHasNoErrors();

        $this->assertSame('won', $l->fresh()->status);
    }

    /** 매도비 > 차값 = 오타. 통과시키면 매입가가 0 으로 깎여 **0원짜리 차**가 ERP 원장에 생긴다(ERP 검증도 min:0). */
    public function test_selling_fee_cannot_exceed_car_cost(): void
    {
        Bus::fake();
        $l = $this->mkListing($this->mkUser('sales'), [
            'status' => 'accepted', 'buyer_verdict' => 'accepted', 'origin' => 'self_inspection',
            'source' => 'encar', 'expected_price_currency' => 'KRW',
        ]);
        $this->actingAs($this->mkUser('manager'));

        Volt::test('auction.index')->call('openDetail', $l->id)
            ->set('car_cost', '400000')
            ->set('selling_fee', '440000')
            ->call('conclude', $l->id, 'won')
            ->assertHasErrors('selling_fee');

        $this->assertSame('accepted', $l->fresh()->status);
        Bus::assertNotDispatched(SyncWonListingToCarErp::class);
    }

    /** 차값이 비었을 땐 매도비 규칙을 걸지 않는다 — 진짜 원인(차값 누락)을 가리면 엉뚱한 칸을 고치게 된다. */
    public function test_missing_car_cost_reports_car_cost_not_selling_fee(): void
    {
        Bus::fake();
        $l = $this->mkListing($this->mkUser('sales'), [
            'status' => 'accepted', 'buyer_verdict' => 'accepted', 'origin' => 'self_inspection',
            'source' => 'encar', 'car_cost' => null, 'final_price' => null,
        ]);
        $this->actingAs($this->mkUser('manager'));

        Volt::test('auction.index')->call('openDetail', $l->id)   // 매도비는 440,000 이 미리 채워져 있다
            ->call('conclude', $l->id, 'won')
            ->assertHasErrors('car_cost')
            ->assertHasNoErrors('selling_fee');
    }

    /**
     * ★셀프검차 금액칸은 **자동계산이 없다**(2026-08-10 Jin) — 통화를 눌러도 환율이 안 바뀌고,
     * 환율을 적어도 다른 칸이 안 따라온다. 전부 "적은 값 그대로"다.
     */
    public function test_self_inspection_amounts_never_autocalculate(): void
    {
        $l = $this->mkListing($this->mkUser('sales'), [
            'status' => 'accepted', 'buyer_verdict' => 'accepted', 'origin' => 'self_inspection',
            'source' => 'encar', 'car_cost' => 10000000, 'expected_price_currency' => 'KRW',
        ]);
        $this->actingAs($this->mkUser('manager'));

        Volt::test('auction.index')->call('openDetail', $l->id)
            ->set('offer_rate', '1234')
            ->set('sale_price', '5000')
            ->set('quoteCurrency', 'USD')      // 통화를 바꿔도 환율은 그대로
            ->assertSet('offer_rate', '1234')
            ->assertSet('sale_price', '5000')  // 판매가도 환산되지 않는다
            ->set('offer_rate', '1400')
            ->assertSet('sale_price', '5000'); // 환율을 고쳐도 판매가는 그대로
    }

    /** 운임비는 **판매통화 그대로** ERP 로 간다 — USD 로 환산하면 EUR 딜에서 부풀어 오른다. */
    public function test_self_inspection_transport_fee_goes_raw_in_sale_currency(): void
    {
        config(['services.car_erp.base_url' => 'https://carerp.test', 'services.car_erp.hmac_secret' => 's']);
        Http::fake(['*' => Http::response(['vehicle_id' => 901], 201)]);

        $l = $this->mkListing($this->mkUser('sales'), [
            'status' => 'won', 'buyer_verdict' => 'accepted', 'origin' => 'self_inspection', 'source' => 'encar',
            'car_cost' => 10000000, 'expected_price_currency' => 'KRW', 'selling_fee' => 440000,
            'sale_price' => 7000, 'offer_currency' => 'EUR', 'offer_rate' => 1500,
            'transport_fee' => 900, 'final_price' => null,   // 자동계산 안 함 — 그래도 통화가 실려야 한다
        ]);

        (new SyncWonListingToCarErp($l->id))->handle();

        Http::assertSent(function ($req) {
            if (! str_contains($req->url(), 'purchase-sync')) {
                return false;
            }
            $b = json_decode($req->body(), true);

            return (float) ($b['transport_fee'] ?? 0) === 900.0 && ($b['sale_currency'] ?? null) === 'EUR';
        });
    }

    /** 견적통화는 **바뀐 경우에만** 재스냅 — 저장할 때마다 오늘 환율로 덮으면 EUR 딜 확정환율이 날아간다. */
    public function test_auction_save_preserves_offer_rate_when_currency_unchanged(): void
    {
        Bus::fake();
        $l = $this->mkListing($this->mkUser('sales'), [
            'status' => 'won', 'buyer_verdict' => 'accepted', 'source' => 'encar',
            'car_cost' => 10000000, 'offer_currency' => 'EUR', 'offer_rate' => 1400,
            'car_erp_vehicle_id' => 99,
        ]);
        $this->actingAs($this->mkUser('manager'));

        Volt::test('auction.index')->call('openDetail', $l->id)
            ->assertSet('quoteCurrency', 'EUR')
            ->set('car_cost', '10500000')
            ->call('savePayee')->assertHasNoErrors();

        $this->assertSame(1400, (int) $l->fresh()->offer_rate);   // 확정환율 보존
    }

    /** 이미 ERP 로 넘어간 차는 다시 쏘지 않는다 — 저장할 때마다 재전송되면 안 된다. */
    public function test_saving_amount_does_not_resend_when_already_synced(): void
    {
        Bus::fake();
        $l = $this->mkListing($this->mkUser('sales'), [
            'status' => 'won', 'buyer_verdict' => 'accepted', 'source' => 'encar',
            'car_cost' => 8000000, 'car_erp_vehicle_id' => 321,
        ]);
        $this->actingAs($this->mkUser('manager'));

        Volt::test('auction.index')->call('openDetail', $l->id)
            ->set('car_cost', '8100000')
            ->call('savePayee')->assertHasNoErrors();

        Bus::assertNotDispatched(SyncWonListingToCarErp::class);
    }

    /** 기존 흐름(현지검차에서 final_price 확정)은 차값이 없어도 그대로 통과해야 한다 — 회귀 방지. */
    public function test_conclude_won_still_works_with_only_final_price(): void
    {
        Bus::fake();
        $l = $this->mkListing($this->mkUser('sales'), [
            'status' => 'accepted', 'buyer_verdict' => 'accepted', 'source' => 'auction',
            'car_cost' => null, 'final_price' => 9000000,
        ]);
        $this->actingAs($this->mkUser('manager'));

        Volt::test('auction.index')->call('openDetail', $l->id)
            ->call('conclude', $l->id, 'won')->assertHasNoErrors();

        $this->assertSame('won', $l->fresh()->status);
    }

    // ── 매입 등록 락 (car-erp §4-0, 2026-08-10) ──

    /** 락 응답 헬퍼 — car-erp `GET /buyers` 동봉 형태 그대로. */
    private function buyerRow(int $id, bool $locked, string $mode = 'unsecured', ?string $kind = 'unsecured_krw', $cur = 0, $lim = 5000000): array
    {
        return [
            'id' => $id, 'name' => 'BUYER'.$id, 'country' => 'JP',
            'purchase_locked' => $locked,
            'purchase_lock' => [
                'locked' => $locked, 'mode' => $mode,
                'basis' => ['kind' => $kind, 'current' => $cur, 'limit' => $lim],
                // 🚫 board 는 이걸 절대 안 그린다 — 락과 분모·분자가 달라 나란히 보이면 오판한다
                'reference' => ['available_krw' => 10000000, 'unpaid_krw' => 92000000, 'unpaid_ratio_pct' => 92.0],
            ],
        ];
    }

    /**
     * ★연동 B 는 car-erp 저장 게이트를 안 타므로 **여기가 유일한 상류 차단점**이다.
     * 통과시키면 영업이 돈을 쓴 뒤에야 막힌 걸 알게 된다.
     */
    public function test_locked_buyer_blocks_purchase_confirmation(): void
    {
        Bus::fake();
        $this->carErpReadConfig();
        Http::fake([
            '*/api/internal/board/buyers*' => Http::response(['count' => 1, 'data' => [$this->buyerRow(7, true)]], 200),
            '*' => Http::response(['count' => 0, 'data' => []], 200),
        ]);
        $l = $this->mkListing($this->mkUser('sales'), [
            'status' => 'accepted', 'buyer_verdict' => 'accepted', 'source' => 'auction', 'final_price' => 9000000,
        ]);
        $this->actingAs($this->mkUser('manager'));

        Volt::test('auction.index')->call('openDetail', $l->id)
            ->set('buyerId', 7)
            ->call('conclude', $l->id, 'won')
            ->assertHasErrors('buyerId');

        $this->assertSame('accepted', $l->fresh()->status);
        Bus::assertNotDispatched(SyncWonListingToCarErp::class);
    }

    /**
     * ★바이어 필수 = **락의 전제**(2026-08-10 Jin). 안 고르면 연동 B `buyer_id` 가 null 로 나가
     * car-erp 가 판정할 대상이 없다 → "안 고르면 통과"라는 구멍이 남는다.
     */
    public function test_conclude_requires_buyer(): void
    {
        Bus::fake();
        $l = $this->mkListing($this->mkUser('sales'), [
            'status' => 'accepted', 'buyer_verdict' => 'accepted', 'source' => 'auction',
            'final_price' => 9000000, 'car_erp_buyer_id' => null,   // 기본값을 명시적으로 덮는다
        ]);
        $this->actingAs($this->mkUser('manager'));

        Volt::test('auction.index')->call('openDetail', $l->id)
            ->call('conclude', $l->id, 'won')
            ->assertHasErrors('buyerId');

        $this->assertSame('accepted', $l->fresh()->status);
        Bus::assertNotDispatched(SyncWonListingToCarErp::class);
    }

    /**
     * 버튼 자체가 안 눌려야 한다 — 눌러보고 막히는 것보다 낫다(Jin 2026-08-10).
     * ⚠️ `Http::fake()` 는 **머지**된다 — 한 테스트에서 두 번 부르면 먼저 등록한 와일드카드가
     *    뒤에 등록한 구체 패턴(buyers 경로)을 가린다. 그래서 케이스마다 테스트를 나눈다.
     */
    public function test_confirm_button_disabled_without_buyer(): void
    {
        $this->carErpReadConfig();
        Http::fake(['*' => Http::response(['count' => 0, 'data' => []], 200)]);
        $this->actingAs($this->mkUser('manager'));
        $l = $this->mkListing($this->mkUser('sales'), [
            'status' => 'accepted', 'buyer_verdict' => 'accepted', 'source' => 'auction', 'car_erp_buyer_id' => null,
        ]);

        $c = Volt::test('auction.index')->call('openDetail', $l->id);
        $this->assertSame(__('auction.err_buyer_required'), $c->instance()->purchaseBlockReason());
    }

    /** 락 걸린 바이어를 고르면 구매확정 버튼이 **비활성**으로 렌더된다. 유찰/취소는 그대로 눌린다. */
    public function test_confirm_button_disabled_for_locked_buyer(): void
    {
        $this->carErpReadConfig();
        Http::fake([
            '*/api/internal/board/buyers*' => Http::response(['count' => 1, 'data' => [$this->buyerRow(7, true)]], 200),
            '*' => Http::response(['count' => 0, 'data' => []], 200),
        ]);
        $this->actingAs($this->mkUser('manager'));
        $l = $this->mkListing($this->mkUser('sales'), [
            'status' => 'accepted', 'buyer_verdict' => 'accepted', 'source' => 'auction', 'car_erp_buyer_id' => 7,
        ]);

        $c = Volt::test('auction.index')->call('openDetail', $l->id);
        $this->assertSame(__('auction.err_buyer_purchase_locked'), $c->instance()->purchaseBlockReason());
        $c->assertSee('disabled');
    }

    /** 락이 안 걸린 바이어는 그대로 통과 — 락이 기본 차단으로 굳으면 매입이 통째로 선다. */
    public function test_unlocked_buyer_passes(): void
    {
        Bus::fake();
        $this->carErpReadConfig();
        Http::fake([
            '*/api/internal/board/buyers*' => Http::response(['count' => 1, 'data' => [$this->buyerRow(7, false)]], 200),
            '*' => Http::response(['count' => 0, 'data' => []], 200),
        ]);
        $l = $this->mkListing($this->mkUser('sales'), [
            'status' => 'accepted', 'buyer_verdict' => 'accepted', 'source' => 'auction', 'final_price' => 9000000,
        ]);
        $this->actingAs($this->mkUser('manager'));

        Volt::test('auction.index')->call('openDetail', $l->id)
            ->set('buyerId', 7)
            ->call('conclude', $l->id, 'won')->assertHasNoErrors();

        $this->assertSame('won', $l->fresh()->status);
    }

    /**
     * ⚠️ ERP 조회가 degrade(다운·미설정)면 **막지 않는다** — 여기서 막으면 ERP 장애가
     * board 의 매입 마감을 통째로 세운다. 지금과 같은 동작을 유지한다.
     */
    public function test_buyer_lock_degrades_open_when_erp_unreachable(): void
    {
        Bus::fake();
        $this->carErpReadConfig();
        Http::fake(['*' => Http::response(['error' => 'boom'], 500)]);
        $l = $this->mkListing($this->mkUser('sales'), [
            'status' => 'accepted', 'buyer_verdict' => 'accepted', 'source' => 'auction', 'final_price' => 9000000,
        ]);
        $this->actingAs($this->mkUser('manager'));

        Volt::test('auction.index')->call('openDetail', $l->id)
            ->set('buyerId', 7)
            ->call('conclude', $l->id, 'won')->assertHasNoErrors();

        $this->assertSame('won', $l->fresh()->status);
    }

    /** 근거는 basis 하나뿐 — reference(보증금 여력)를 같이 그리면 "여력 0인데 가능"·"락인데 여력 1천만"이 둘 다 정상이라 오판한다. */
    public function test_buyer_lock_shows_basis_only_never_reference(): void
    {
        $this->carErpReadConfig();
        Http::fake([
            '*/api/internal/board/buyers*' => Http::response(['count' => 1, 'data' => [
                $this->buyerRow(7, true, 'ratio', 'ratio', 92.0, 80),
            ]], 200),
            '*' => Http::response(['count' => 0, 'data' => []], 200),
        ]);
        $l = $this->mkListing($this->mkUser('sales'), ['status' => 'accepted', 'buyer_verdict' => 'accepted', 'source' => 'auction']);
        $this->actingAs($this->mkUser('manager'));

        Volt::test('auction.index')->call('openDetail', $l->id)->set('buyerId', 7)
            ->assertSee('미수율 92% / 임계 80%')          // basis 그대로(20.0 → 20 정규화 포함)
            ->assertSee(__('auction.buyer_lock_notice'))  // "불가"가 아니라 관리자 승인 안내
            ->assertDontSee('10,000,000');                // reference.available_krw 는 절대 안 나온다
    }

    /** 토글 OFF·판정근거 없음(신규 바이어)은 **아무것도 안 그린다** — 빈 배지가 더 헷갈린다. */
    public function test_buyer_lock_hidden_when_off_or_no_basis(): void
    {
        $this->carErpReadConfig();
        $this->actingAs($this->mkUser('manager'));
        $l = $this->mkListing($this->mkUser('sales'), ['status' => 'accepted', 'buyer_verdict' => 'accepted', 'source' => 'auction']);

        foreach ([['off', null], ['unsecured', null]] as [$mode, $kind]) {
            Http::fake([
                '*/api/internal/board/buyers*' => Http::response(['count' => 1, 'data' => [
                    $this->buyerRow(7, false, $mode, $kind),
                ]], 200),
                '*' => Http::response(['count' => 0, 'data' => []], 200),
            ]);
            $c = Volt::test('auction.index')->call('openDetail', $l->id)->set('buyerId', 7);
            $this->assertNull($c->instance()->buyerLock(7), "mode={$mode} kind=".var_export($kind, true));
        }
    }

    /** 바이어 조회 신원 = 운영자(대행 관리자)가 아니라 '딜 작성자(영업)' — car-erp 본인격리 + 연동B salesman 일관성. */
    public function test_auction_buyer_dropdown_uses_listing_creator_identity(): void
    {
        Bus::fake();
        config(['services.car_erp.base_url' => 'https://carerp.test', 'services.car_erp.read_hmac_secret' => 'rs']);
        Http::fake([
            '*/api/internal/board/buyers*' => Http::response(['count' => 0, 'data' => []], 200),
            '*/api/internal/board/consignees*' => Http::response(['count' => 0, 'data' => []], 200),
        ]);

        // 작성자(영업) — car-erp 오버라이드 이메일 보유
        $creator = $this->mkUser('sales');
        $creator->car_erp_salesman_email = 'creator@erp.test';
        $creator->save();
        $l = $this->mkListing($creator, ['status' => 'accepted', 'buyer_verdict' => 'accepted', 'source' => 'auction', 'final_price' => 9000000]);

        // 운영자 = 다른 관리자(대행). 조회는 운영자 신원이 아니라 작성자 신원으로 가야 함.
        $this->actingAs($this->mkUser('manager'));
        Volt::test('auction.index')->call('openDetail', $l->id);

        Http::assertSent(fn ($req) => str_contains($req->url(), '/buyers')
            && str_contains($req->url(), 'salesman_email=creator%40erp.test'));
    }

    /** §5 v2 선적·B/L 묶음 client — 4 신규 엔드포인트 서명/경로/바디 + degrade 봉투. */
    public function test_car_erp_read_service_v2_bundle_endpoints(): void
    {
        config(['services.car_erp.base_url' => 'https://carerp.test', 'services.car_erp.read_hmac_secret' => 'rs']);
        Http::fake([
            '*/api/internal/board/bundles/B1/bl-request*' => Http::response(['ok' => true], 200),
            '*/api/internal/board/bundles*' => Http::response(['count' => 1, 'data' => [['batch_id' => 'B1', 'unpaid_total_krw' => null, 'fx_missing_count' => 1, 'fully_paid' => false]]], 200),
            '*/api/internal/board/shipping-requests/sync*' => Http::response(['created' => [1], 'updated' => [], 'cancelled' => [2], 'skipped' => [], 'locked' => [3]], 200),
            '*/api/internal/board/shipping-requests/change-request*' => Http::response(['ok' => true], 200),
        ]);
        $svc = app(CarErpReadService::class);

        // GET /bundles — 값 그대로 보존(null/false coerce 금지)
        $b = $svc->bundles('s@erp.test');
        $this->assertTrue($b['ok']);
        $this->assertNull($b['data']['data'][0]['unpaid_total_krw']);   // 환율 미입력 → null 보존
        $this->assertFalse($b['data']['data'][0]['fully_paid']);

        // POST /sync — desired 묶음 전체
        $sync = $svc->syncShippingRequests('s@erp.test', [
            ['buyer_id' => 5, 'consignee_id' => 9, 'shipping_method' => 'RORO', 'bl_type' => 'original', 'vehicle_ids' => [1, 2]],
        ]);
        $this->assertTrue($sync['ok']);
        $this->assertSame([2], $sync['data']['cancelled']);
        $this->assertSame([3], $sync['data']['locked']);

        $svc->blRequest('s@erp.test', 'B1', 'surrender');
        $svc->changeRequest('s@erp.test', 7, '바이어 변경');

        // sync: 서명 헤더 + 전체 desired 바디
        Http::assertSent(fn ($r) => str_contains($r->url(), '/api/internal/board/shipping-requests/sync')
            && str_starts_with($r->header('X-Board-Signature')[0], 'sha256=')
            && $r->hasHeader('X-Timestamp') && $r->hasHeader('X-Nonce')
            && str_contains($r->body(), '"bl_type":"original"')
            && str_contains($r->body(), '"vehicle_ids":[1,2]'));
        // bl-request: 경로에 batch + bl_type 바디
        Http::assertSent(fn ($r) => str_contains($r->url(), '/api/internal/board/bundles/B1/bl-request')
            && str_contains($r->body(), '"bl_type":"surrender"'));
        // change-request: vehicle_id + note
        Http::assertSent(fn ($r) => str_contains($r->url(), '/api/internal/board/shipping-requests/change-request')
            && str_contains($r->body(), '"vehicle_id":7'));

        // canonical 바이트 형태 핀(§1 — METHOD\nPATH?sorted_query\nTS\nBODY)
        [, $canon] = $svc->sign('POST', '/api/internal/board/shipping-requests/sync', ['salesman_email' => 's@erp.test'], '{"x":1}');
        $this->assertStringStartsWith("POST\n/api/internal/board/shipping-requests/sync?salesman_email=s%40erp.test\n", $canon);
        $this->assertStringEndsWith("\n".'{"x":1}', $canon);
    }

    /** §10 전자서명 세션 발급 client — POST 서명/경로/바디 + 발급불가 degrade. */
    public function test_car_erp_read_service_signing_session(): void
    {
        config(['services.car_erp.base_url' => 'https://carerp.test', 'services.car_erp.read_hmac_secret' => 'rs']);
        Http::fake([
            '*/api/internal/board/signing-requests*' => Http::response([
                'signed_url' => 'https://heysellcar.com/sign/tok?expires=1&signature=ab',
                'contract_no' => 'SC2607-01215', 'buyer' => ['id' => 42, 'name' => 'ABC TRADING'],
                'currency' => 'USD', 'vehicle_count' => 2, 'status' => 'pending',
                'expires_at' => '2026-07-17T09:00:00+09:00',
            ], 200),
        ]);
        $svc = app(CarErpReadService::class);

        $res = $svc->requestSigningSession('s@erp.test', [1215, 1216]);
        $this->assertTrue($res['ok']);
        $this->assertSame('https://heysellcar.com/sign/tok?expires=1&signature=ab', $res['data']['signed_url']);
        $this->assertSame('pending', $res['data']['status']);

        // POST /signing-requests — 서명 헤더 + salesman_email 쿼리 + vehicle_ids 바디(int). recipient_email 미전송 시 body 에 없음.
        Http::assertSent(fn ($r) => str_contains($r->url(), '/api/internal/board/signing-requests?salesman_email=s%40erp.test')
            && str_starts_with($r->header('X-Board-Signature')[0], 'sha256=')
            && $r->hasHeader('X-Timestamp') && $r->hasHeader('X-Nonce')
            && str_contains($r->body(), '"vehicle_ids":[1215,1216]')
            && str_contains($r->body(), '"salesman_email":"s@erp.test"')
            && ! str_contains($r->body(), 'recipient_email'));

        // recipient_email 전송 시 바디 포함
        $svc->requestSigningSession('s@erp.test', [1215], 'buyer@example.com');
        Http::assertSent(fn ($r) => str_contains($r->body(), '"recipient_email":"buyer@example.com"'));

        // 미설정 → ok=false degrade("발급 불가")
        config(['services.car_erp.read_hmac_secret' => null]);
        $down = app(CarErpReadService::class)->requestSigningSession('s@erp.test', [1]);
        $this->assertFalse($down['ok']);
        $this->assertSame('not_configured', $down['reason']);
    }

    /** §10-2 서명 상태 조회 client — GET vehicle_ids 콤마쿼리 + 서명헤더 + status 반환. */
    public function test_car_erp_read_service_signing_status(): void
    {
        config(['services.car_erp.base_url' => 'https://carerp.test', 'services.car_erp.read_hmac_secret' => 'rs']);
        Http::fake([
            '*/api/internal/board/signing-requests*' => Http::response([
                'status' => 'signed', 'contract_no' => 'SC2607-01215', 'vehicle_count' => 2,
                'sent_at' => '2026-07-10T00:00:00+09:00', 'viewed_at' => '2026-07-10T01:00:00+09:00',
                'signed_at' => '2026-07-10T02:00:00+09:00',
            ], 200),
        ]);
        $svc = app(CarErpReadService::class);

        $res = $svc->signingStatus('s@erp.test', [1215, 1216]);
        $this->assertTrue($res['ok']);
        $this->assertSame('signed', $res['data']['status']);

        // GET — salesman_email + vehicle_ids(콤마, urlencode) 쿼리 + 서명헤더, 빈 바디.
        Http::assertSent(fn ($r) => $r->method() === 'GET'
            && str_contains($r->url(), '/api/internal/board/signing-requests')
            && str_contains($r->url(), 'vehicle_ids=1215%2C1216')
            && str_starts_with($r->header('X-Board-Signature')[0], 'sha256='));
    }

    /** 영업이 집행화면(구매확정/경매) 접근 + SalesmanScope 로 본인 딜만 보이고 won 까지 집행. */
    public function test_sales_can_conclude_own_deal_and_is_scoped(): void
    {
        Bus::fake();
        $mine = $this->mkUser('sales');
        $other = $this->mkUser('sales');
        $lMine = $this->mkListing($mine, ['status' => 'accepted', 'buyer_verdict' => 'accepted', 'final_price' => 9000000]);
        $lOther = $this->mkListing($other, ['status' => 'accepted', 'buyer_verdict' => 'accepted', 'final_price' => 9000000]);

        $this->actingAs($mine);
        $this->get('/auction')->assertOk();   // 영업 접근 허용(역할 확장)

        Volt::test('auction.index')
            ->assertSee($lMine->vehicle_number)        // 본인 딜 노출
            ->assertDontSee($lOther->vehicle_number)   // 타 영업 딜 격리(SalesmanScope)
            ->call('openDetail', $lMine->id)           // 구매확정은 드로어 안에서만 — 바이어가 여기서 잡힌다
            ->call('conclude', $lMine->id, 'won')->assertHasNoErrors();

        $this->assertSame('won', $lMine->fresh()->status);
        Bus::assertDispatched(SyncWonListingToCarErp::class);   // won → 연동B 발화(모델 훅)
    }

    /** verdicts 회신 드로어 — 검차 산출물 읽기로 열고, 드로어에서 수락 시 적용 + 드로어 닫힘. */
    public function test_verdicts_drawer_opens_and_accepts(): void
    {
        Bus::fake();
        $sales = $this->mkUser('sales');
        $l = $this->mkListing($sales, [
            'status' => 'awaiting_buyer', 'buyer_verdict' => 'pending',
            'buyer_name' => 'Buyer A', 'final_price' => 9000000,
        ]);

        $this->actingAs($sales);
        Volt::test('verdicts.index')
            ->call('openDetail', $l->id)
            ->assertSet('detailId', $l->id)
            ->call('accept', $l->id)
            ->assertSet('detailId', null);   // 회신 후 드로어 닫힘

        $this->assertSame('accepted', $l->fresh()->status);
    }

    public function test_edit_drawer_appends_attachments_and_cap_counts_existing(): void
    {
        Storage::fake('public');
        config(['board.photo_disk' => 'public', 'board.attachment_max' => 10]);
        $kim = $this->mkUser('sales');
        $this->actingAs($kim);
        $l = $this->mkListing($kim);
        for ($i = 1; $i <= 9; $i++) {
            $l->salesAttachments()->create(['s3_path' => "s/e{$i}.jpg", 'original_name' => "e{$i}.jpg", 'sort' => $i, 'kind' => InspectionPhoto::KIND_SALES_PHOTO]);
        }

        // 9 + 1 = 10 → OK (편집 드로어 업로드 경로)
        Volt::test('listings.index')
            ->call('openEdit', $l->id)
            ->set('eSalesFiles', [UploadedFile::fake()->create('reg.pdf', 10, 'application/pdf')])
            ->call('update')
            ->assertHasNoErrors();
        $this->assertSame(10, $l->fresh()->salesAttachments()->count());

        // 10 + 1 = 11 → cap 초과(기존건수 반영) → 에러, 저장 안 됨
        Volt::test('listings.index')
            ->call('openEdit', $l->id)
            ->set('eSalesFiles', [UploadedFile::fake()->image('over.jpg')])
            ->call('update')
            ->assertHasErrors('eSalesFiles');
        $this->assertSame(10, $l->fresh()->salesAttachments()->count());
    }

    public function test_delete_sales_attachment_removes_file_and_blocks_other_user(): void
    {
        Storage::fake('public');
        config(['board.photo_disk' => 'public']);
        $kim = $this->mkUser('sales');
        $l = $this->mkListing($kim);
        Storage::disk('public')->put('s/del.jpg', 'x');
        $att = $l->salesAttachments()->create(['s3_path' => 's/del.jpg', 'original_name' => 'del.jpg', 'sort' => 1, 'kind' => InspectionPhoto::KIND_SALES_PHOTO]);

        // 다른 영업은 삭제 불가 (SalesmanScope: 본인 글 아님 → findOrFail throws)
        $this->actingAs($this->mkUser('sales'));
        $this->assertItThrows(fn () => Volt::test('listings.index')->set('editingId', $l->id)->call('deleteSalesAttachment', $att->id));
        $this->assertDatabaseHas('inspection_photos', ['id' => $att->id]);
        Storage::disk('public')->assertExists('s/del.jpg');

        // 본인은 삭제 가능 + S3 파일까지 삭제
        $this->actingAs($kim);
        Volt::test('listings.index')->call('openEdit', $l->id)->call('deleteSalesAttachment', $att->id);
        $this->assertDatabaseMissing('inspection_photos', ['id' => $att->id]);
        Storage::disk('public')->assertMissing('s/del.jpg');
    }

    public function test_auction_drawer_renders_attachment_section(): void
    {
        // 딜러 첨부(사진·서류) 업로드/표시는 구매확정(auction) 드로어로 이동(2026-07-06).
        config(['board.photo_disk' => 'public']);
        $kim = $this->mkUser('sales');
        $l = $this->mkListing($kim, ['status' => 'accepted', 'buyer_verdict' => 'accepted']);
        $l->salesAttachments()->create(['s3_path' => 's/reg.pdf', 'original_name' => 'reg.pdf', 'sort' => 1, 'kind' => InspectionPhoto::KIND_SALES_DOCUMENT]);

        $this->actingAs($this->mkUser('manager'));
        Volt::test('auction.index')
            ->call('openDetail', $l->id)
            ->assertSee(__('auction.attach.title'))
            ->assertSee('reg.pdf');   // 서류 분기(isDocument) 렌더
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

    public function test_inspection_complete_transitions_to_inspected(): void
    {
        $l = $this->mkListing($this->mkUser('sales'), ['status' => 'draft']);
        $this->actingAs($this->mkUser('inspection'));

        // 수동씬: 검차완료 선택 → 저장 눌러야 반영. 바이어 전달(awaiting_buyer)은 영업이 따로.
        Volt::test('inspection.index')
            ->call('openDrawer', $l->id)
            ->set('inspection_memo', '외관 양호')
            ->set('sendSelected', true)
            ->call('save')
            ->assertHasNoErrors();

        $l->refresh();
        $this->assertSame('inspected', $l->status);      // 금액 없이도 검차완료(금액은 forwarding 단계)
        $this->assertSame('none', $l->buyer_verdict);   // 검차완료는 회신 단계 아님
    }

    public function test_state_machine_inspected_path(): void
    {
        $l = $this->mkListing($this->mkUser('sales'), ['status' => 'draft']);

        // draft → awaiting_buyer 직접은 막힘(이제 inspected 경유 필수)
        $this->assertItThrows(fn () => $l->update(['status' => 'awaiting_buyer']));

        // draft → inspected → awaiting_buyer 는 허용
        $l->update(['status' => 'inspected']);
        $this->assertSame('inspected', $l->fresh()->status);
        $l->update(['status' => 'awaiting_buyer']);
        $this->assertSame('awaiting_buyer', $l->fresh()->status);
    }

    public function test_forwarding_screen_forwards_inspected_to_awaiting(): void
    {
        Bus::fake();
        $sales = $this->mkUser('sales');
        $other = $this->mkUser('sales');
        $mine = $this->mkListing($sales, ['status' => 'inspected', 'final_price' => 13200000]);
        $theirs = $this->mkListing($other, ['status' => 'inspected', 'final_price' => 9000000]);

        $this->actingAs($sales);
        $this->get('/forwarding')->assertOk();

        Volt::test('forwarding.index')
            ->assertSee($mine->vehicle_number)         // 본인 검차완료 차 노출
            ->assertDontSee($theirs->vehicle_number)   // 타 영업 격리(SalesmanScope)
            ->call('openDetail', $mine->id)
            ->set('buyer_name', '드라간')
            ->call('forward')
            ->assertHasNoErrors()
            ->assertSet('detailId', null);             // 전달 후 드로어 닫힘

        $mine->refresh();
        $this->assertSame('awaiting_buyer', $mine->status);
        $this->assertSame('pending', $mine->buyer_verdict);
        $this->assertSame('드라간', $mine->buyer_name);
        Bus::assertDispatched(SendOfferToBuyer::class);
    }

    public function test_forwarding_works_without_buyer_name(): void
    {
        Bus::fake();
        $sales = $this->mkUser('sales');
        $l = $this->mkListing($sales, ['status' => 'inspected']);
        $this->actingAs($sales);

        // respond.io 미사용 — 바이어명 미입력해도 전달 완료(에러 없음)
        Volt::test('forwarding.index')
            ->call('openDetail', $l->id)
            ->call('forward')
            ->assertHasNoErrors();

        $this->assertSame('awaiting_buyer', $l->fresh()->status);
    }

    public function test_forwarding_drawer_shows_ssancar_video(): void
    {
        config([
            'services.ssancar_media.base_url' => 'https://www.ssancar.com/page/api_car_media.php',
            'services.ssancar_media.api_key' => 'testkey',
        ]);
        Cache::flush();
        Http::fake(['*api_car_media.php*' => Http::response([
            'ok' => 1, 'mode' => 'plate',
            'videos' => [['embed_url' => 'https://iframe.mediadelivery.net/embed/685063/fwd']],
            'photos' => [],
        ], 200)]);

        $sales = $this->mkUser('sales');
        $l = $this->mkListing($sales, ['status' => 'inspected']);
        $this->actingAs($sales);

        // 전달 드로어에 ssancar 자동감지 영상(embed) 노출 + board 사진·견적 없어도 전송버튼 노출(#2 게이트).
        Volt::test('forwarding.index')
            ->call('openDetail', $l->id)
            ->assertSee('iframe.mediadelivery.net/embed/685063/fwd')
            ->assertSee(__('forwarding.send_all'));
    }

    public function test_forwarding_save_amount_recomputes_total_and_preserves_currency(): void
    {
        $sales = $this->mkUser('sales');
        // EUR 딜(통화 보존 검증) + 배송 없음(환율 무관 순수 KRW 계산)
        $l = $this->mkListing($sales, [
            'status' => 'inspected',
            'expected_price_currency' => 'KRW',
            'car_cost' => 8000000,
            'discount_rate' => 5,
            'final_price' => 7600000,
            'offer_currency' => 'EUR',
            'offer_rate' => 1500,
        ]);
        $this->actingAs($sales);

        // 드로어에서 차값·할인율 조정 후 저장(재견적)
        Volt::test('forwarding.index')
            ->call('openDetail', $l->id)
            ->set('e_car_cost', '10000000')
            ->set('e_discount_rate', '10')
            ->set('e_shipping_usd', null)
            ->call('saveAmount')
            ->assertHasNoErrors();

        $l->refresh();
        // 10,000,000 − 10%(할인) = 9,000,000 (매도비 제외, Model A), 배송 없음
        $this->assertSame(9000000, $l->final_price);
        $this->assertSame(10000000, $l->car_cost);
        $this->assertSame(10.0, (float) $l->discount_rate);
        // 통화 선택(offer_currency/offer_rate)은 건드리지 않음 — listings 미러(EUR 딜 보존)
        $this->assertSame('EUR', $l->offer_currency);
        $this->assertSame(1500, (int) $l->offer_rate);
        // status 불변(전이는 forward 가 담당)
        $this->assertSame('inspected', $l->status);
    }

    public function test_forwarding_unsaved_amount_edit_is_persisted_before_forward(): void
    {
        Bus::fake();
        $sales = $this->mkUser('sales');
        $l = $this->mkListing($sales, [
            'status' => 'inspected',
            'expected_price_currency' => 'KRW',
            'car_cost' => 10000000,
            'discount_rate' => 0,
            'final_price' => 10440000,
        ]);
        $this->actingAs($sales);

        // 별도 저장 없이 할인율만 바꾸고 바로 전달 — blur 자동 저장(updated 훅)으로 새 금액 반영돼야 함
        Volt::test('forwarding.index')
            ->call('openDetail', $l->id)
            ->set('e_discount_rate', '20')   // 입력 변경 = 자동 저장
            ->call('forward')
            ->assertHasNoErrors();

        $l->refresh();
        // 10,000,000 − 20%(할인) = 8,000,000 (매도비 제외; 옛 10,440,000 이 나가면 안 됨)
        $this->assertSame(8000000, $l->final_price);
        $this->assertSame('awaiting_buyer', $l->status);
    }

    public function test_forwarding_shows_send_all_link_and_excludes_video_from_photo_sheet(): void
    {
        config(['board.photo_disk' => 'public']);
        Storage::fake('public');
        $sales = $this->mkUser('sales');
        $l = $this->mkListing($sales, ['status' => 'inspected', 'final_price' => 9000000]);
        $l->photos()->create(['s3_path' => 'i/a.jpg', 'original_name' => 'a.jpg', 'sort' => 1, 'kind' => InspectionPhoto::KIND_INSPECTION, 'share_to_buyer' => true]);
        $l->photos()->create(['s3_path' => 'i/clip.mp4', 'original_name' => 'clip.mp4', 'sort' => 2, 'kind' => InspectionPhoto::KIND_INSPECTION, 'share_to_buyer' => true]);
        $this->actingAs($sales);

        Volt::test('forwarding.index')
            ->call('openDetail', $l->id)
            ->assertSee(__('forwarding.send_all'))                       // 전체 보내기(사진·영상·견적 한 링크)
            ->assertSee(__('forwarding.share_button', ['count' => 1])); // 사진 시트 = 이미지 1장만(영상 제외)
    }

    public function test_buyer_view_requires_signature_and_shows_only_shared_media(): void
    {
        config(['board.photo_disk' => 'public']);
        Storage::fake('public');
        $sales = $this->mkUser('sales');
        $l = $this->mkListing($sales, [
            'status' => 'inspected', 'expected_price_currency' => 'KRW',
            'car_cost' => 10000000, 'discount_rate' => 0, 'final_price' => 10440000, 'offer_currency' => 'KRW',
        ]);
        $l->photos()->create(['s3_path' => 'i/shared.jpg', 'original_name' => 'shared.jpg', 'sort' => 1, 'kind' => InspectionPhoto::KIND_INSPECTION, 'share_to_buyer' => true]);
        $l->photos()->create(['s3_path' => 'i/hidden.jpg', 'original_name' => 'hidden.jpg', 'sort' => 2, 'kind' => InspectionPhoto::KIND_INSPECTION, 'share_to_buyer' => false]);
        $l->photos()->create(['s3_path' => 's/doc.pdf', 'original_name' => 'doc.pdf', 'sort' => 3, 'kind' => InspectionPhoto::KIND_SALES_DOCUMENT, 'share_to_buyer' => true]);

        // 서명 없는 접근 → 403
        $this->get('/v/'.$l->id)->assertForbidden();

        // 유효 서명 → 200: 공유사진만, 비공유·서류 제외, 총액 표시(견적카드와 동일 계산)
        $url = URL::temporarySignedRoute('buyer.view', now()->addDays(30), ['listing' => $l->id]);
        $this->get($url)
            ->assertOk()
            ->assertSee('shared.jpg')
            ->assertDontSee('hidden.jpg')         // share_to_buyer=false 제외
            ->assertDontSee('doc.pdf')            // 서류(kind) 제외 §28
            ->assertSee(number_format(10440000)); // Total
    }

    public function test_buyer_view_embeds_ssancar_media(): void
    {
        config([
            'board.photo_disk' => 'public',
            'services.ssancar_media.base_url' => 'https://www.ssancar.com/page/api_car_media.php',
            'services.ssancar_media.api_key' => 'testkey',
        ]);
        Storage::fake('public');
        Cache::flush();

        Http::fake([
            '*api_car_media.php*' => Http::response([
                'ok' => 1,
                'mode' => 'link',
                'sources' => ['inspected' => ['matched' => 1]],
                'videos' => [[
                    'id' => 981, 'source' => 'bunny', 'guid' => 'abc',
                    'embed_url' => 'https://iframe.mediadelivery.net/embed/685063/abc',
                    'hls_url' => 'https://vz.b-cdn.net/abc/playlist.m3u8',
                    'thumbnail' => 'https://vz.b-cdn.net/abc/thumbnail.jpg',
                ]],
                'photos' => ['https://cdn.ssancar.com/inspected/p1.jpg'],
            ], 200),
        ]);

        $sales = $this->mkUser('sales');
        $l = $this->mkListing($sales, [
            'status' => 'inspected', 'ssancar_ref' => 'wr_id:920',
            'expected_price_currency' => 'KRW', 'car_cost' => 10000000,
            'discount_rate' => 0, 'final_price' => 10440000, 'offer_currency' => 'KRW',
        ]);

        $url = URL::temporarySignedRoute('buyer.view', now()->addDays(30), ['listing' => $l->id]);
        $this->get($url)
            ->assertOk()
            ->assertSee('iframe.mediadelivery.net/embed/685063/abc')   // Bunny 임베드
            ->assertSee('cdn.ssancar.com/inspected/p1.jpg');           // ssancar 사진

        // X-Api-Key 헤더 + type/id 직접모드(wr_id:920 → inspected/920) 로 호출했는지
        Http::assertSent(fn ($req) => $req->hasHeader('X-Api-Key', 'testkey')
            && str_contains($req->url(), 'type=inspected')
            && str_contains($req->url(), 'id=920'));
    }

    public function test_buyer_view_renders_og_tags_and_quote_card(): void
    {
        config(['board.photo_disk' => 'public']);
        Storage::fake('public');
        $sales = $this->mkUser('sales');
        $l = $this->mkListing($sales, [
            'status' => 'inspected', 'expected_price_currency' => 'KRW',
            'car_cost' => 10000000, 'discount_rate' => 0, 'final_price' => 10440000, 'offer_currency' => 'KRW',
        ]);

        // 페이지 head 에 OG 태그 + 견적카드 이미지 링크(서명)
        $page = URL::temporarySignedRoute('buyer.view', now()->addDays(30), ['listing' => $l->id]);
        $this->get($page)->assertOk()
            ->assertSee('og:image', false)
            ->assertSee('card.png')
            ->assertSee('SSANCAR Quotation');

        // 견적카드 PNG — 서명 필수(없으면 403), image/png 반환
        $this->get('/v/'.$l->id.'/card.png')->assertForbidden();
        $res = $this->get(URL::signedRoute('buyer.card', ['listing' => $l->id]));
        $res->assertOk();
        $this->assertSame('image/png', $res->headers->get('Content-Type'));
        $this->assertStringStartsWith("\x89PNG", $res->getContent());   // PNG 매직넘버
    }

    public function test_buyer_view_falls_back_to_vin_crossmatch_for_encar_origin(): void
    {
        config([
            'board.photo_disk' => 'public',
            'services.ssancar_media.base_url' => 'https://www.ssancar.com/page/api_car_media.php',
            'services.ssancar_media.api_key' => 'testkey',
        ]);
        Storage::fake('public');
        Cache::flush();

        Http::fake([
            '*api_car_media.php*' => Http::response([
                'ok' => 1, 'mode' => 'plate',
                'videos' => [['embed_url' => 'https://iframe.mediadelivery.net/embed/685063/xyz']],
                'photos' => ['https://cdn.ssancar.com/inspected/v1.jpg'],
            ], 200),
        ]);

        $sales = $this->mkUser('sales');
        // 엔카 출처 — ssancar_ref/c_no 없음. vin·차량번호는 항상 보유(IDENTITY_LOCKED).
        $l = $this->mkListing($sales, [
            'source' => 'encar', 'status' => 'inspected',
            'expected_price_currency' => 'KRW', 'car_cost' => 10000000,
            'discount_rate' => 0, 'final_price' => 10440000, 'offer_currency' => 'KRW',
        ]);
        $this->assertNull($l->ssancarMediaParams());   // id 모드 불가 → (B) 폴백 경로

        $url = URL::temporarySignedRoute('buyer.view', now()->addDays(30), ['listing' => $l->id]);
        $this->get($url)->assertOk()
            ->assertSee('iframe.mediadelivery.net/embed/685063/xyz')
            ->assertSee('cdn.ssancar.com/inspected/v1.jpg');

        // vin+car_no 둘 다(OR 매칭) 전송, type 파라미터 없음
        Http::assertSent(fn ($req) => $req->hasHeader('X-Api-Key', 'testkey')
            && str_contains($req->url(), 'vin='.$l->vin)
            && str_contains($req->url(), 'car_no=')
            && ! str_contains($req->url(), 'type='));
    }

    /** ssancar api_car_media 응답 빌더 — 소스별(inspected 영상/사진, stock 사진) 개수로 sources+top-level 구성. */
    private function ssancarResp(int $inspVideos, int $inspPhotos, int $stockPhotos): array
    {
        $vids = array_fill(0, $inspVideos, ['embed_url' => 'https://iframe.mediadelivery.net/embed/685063/v']);
        $iPhotos = array_fill(0, $inspPhotos, 'https://cdn.ssancar.com/insp.jpg');
        $sPhotos = array_fill(0, $stockPhotos, 'https://cdn.ssancar.com/stock.jpg');

        return [
            'ok' => 1,
            'videos' => $vids,
            'photos' => array_merge($iPhotos, $sPhotos),
            'sources' => [
                'auction' => ['matched' => 0, 'videos' => [], 'photos' => []],
                'stock' => ['matched' => $stockPhotos > 0 ? 1 : 0, 'videos' => [], 'photos' => $sPhotos],
                'inspected' => ['matched' => ($inspVideos || $inspPhotos) ? 1 : 0, 'videos' => $vids, 'photos' => $iPhotos],
            ],
        ];
    }

    private function ssancarMediaConfig(bool $flag = true): void
    {
        config([
            'services.ssancar_media.base_url' => 'https://www.ssancar.com/page/api_car_media.php',
            'services.ssancar_media.api_key' => 'testkey',
            'board.ssancar_auto_forward' => $flag,
            'board.ssancar_poll_max_age_days' => 3,
        ]);
        Cache::flush();
    }

    public function test_poll_ssancar_media_advances_on_inspected_video(): void
    {
        $this->ssancarMediaConfig();
        Http::fake(['*api_car_media.php*' => Http::response($this->ssancarResp(1, 3, 0), 200)]);
        $l = $this->mkListing($this->mkUser('sales'), ['status' => 'draft']);

        $this->artisan('board:poll-ssancar-media')->assertExitCode(0);

        $this->assertSame('inspected', $l->fresh()->status);   // 검차 영상 → 전이
        $this->assertDatabaseHas('integration_events', [
            'purchase_listing_id' => $l->id, 'target' => 'ssancar_media', 'response_body' => 'advanced:inspected_video',
        ]);
    }

    public function test_poll_ssancar_media_advances_on_stock_photos(): void
    {
        // 재고(stock)=사진만이어도 전달대기(Jin 규칙). 영상 없이 사진만으로 전이.
        $this->ssancarMediaConfig();
        Http::fake(['*api_car_media.php*' => Http::response($this->ssancarResp(0, 0, 16), 200)]);
        $l = $this->mkListing($this->mkUser('sales'), ['status' => 'draft']);

        $this->artisan('board:poll-ssancar-media')->assertExitCode(0);

        $this->assertSame('inspected', $l->fresh()->status);
        $this->assertDatabaseHas('integration_events', [
            'purchase_listing_id' => $l->id, 'response_body' => 'advanced:stock_photos',
        ]);
    }

    public function test_poll_ssancar_media_waits_on_inspected_photos_only(): void
    {
        // inspected 인데 사진만(영상 0)=검차 진행중 → 대기(영상 기다림). 연결 표식만 찍힘.
        $this->ssancarMediaConfig();
        Http::fake(['*api_car_media.php*' => Http::response($this->ssancarResp(0, 5, 0), 200)]);
        $l = $this->mkListing($this->mkUser('sales'), ['status' => 'draft']);

        $this->artisan('board:poll-ssancar-media')->assertExitCode(0);

        $l->refresh();
        $this->assertSame('draft', $l->status);              // 영상 대기 → draft 유지
        $this->assertNotNull($l->ssancar_media_seen_at);     // 연결 표식(에이지아웃 유예)
    }

    public function test_poll_ssancar_media_keeps_draft_when_no_media(): void
    {
        $this->ssancarMediaConfig();
        Http::fake(['*api_car_media.php*' => Http::response($this->ssancarResp(0, 0, 0), 200)]);
        $l = $this->mkListing($this->mkUser('sales'), ['status' => 'draft']);

        $this->artisan('board:poll-ssancar-media')->assertExitCode(0);

        $l->refresh();
        $this->assertSame('draft', $l->status);
        $this->assertNull($l->ssancar_media_seen_at);        // 미디어 0 → 표식 없음(3일 뒤 에이지아웃)
    }

    public function test_poll_ssancar_media_noop_when_unconfigured(): void
    {
        config(['services.ssancar_media.base_url' => '', 'services.ssancar_media.api_key' => '']);
        Http::fake();
        $l = $this->mkListing($this->mkUser('sales'), ['status' => 'draft']);

        $this->artisan('board:poll-ssancar-media')->assertExitCode(0);

        $this->assertSame('draft', $l->fresh()->status);
        Http::assertNothingSent();   // 미설정이면 외부호출 0
    }

    public function test_poll_ssancar_media_noop_when_flag_off(): void
    {
        $this->ssancarMediaConfig(flag: false);
        Http::fake(['*api_car_media.php*' => Http::response($this->ssancarResp(1, 0, 0), 200)]);
        $l = $this->mkListing($this->mkUser('sales'), ['status' => 'draft']);

        $this->artisan('board:poll-ssancar-media')->assertExitCode(0);

        $this->assertSame('draft', $l->fresh()->status);
        Http::assertNothingSent();   // 플래그 off면 조회조차 안 함
    }

    public function test_poll_ssancar_media_ages_out_stale_draft(): void
    {
        $this->ssancarMediaConfig();
        Http::fake(['*api_car_media.php*' => Http::response($this->ssancarResp(1, 0, 0), 200)]);
        $l = $this->mkListing($this->mkUser('sales'), ['status' => 'draft']);
        $l->created_at = now()->subDays(4);   // 4일 전 + 미디어 본 적 없음 → 에이지아웃
        $l->saveQuietly();

        $this->artisan('board:poll-ssancar-media')->assertExitCode(0);

        $this->assertSame('draft', $l->fresh()->status);
        Http::assertNothingSent();   // 쿼리서 빠져 API 조회조차 안 함(부하 0)
    }

    public function test_poll_ssancar_media_keeps_polling_connected_stale_draft(): void
    {
        $this->ssancarMediaConfig();
        Http::fake(['*api_car_media.php*' => Http::response($this->ssancarResp(1, 0, 0), 200)]);
        $l = $this->mkListing($this->mkUser('sales'), ['status' => 'draft']);
        $l->created_at = now()->subDays(4);
        $l->ssancar_media_seen_at = now()->subDays(2);   // 연결됨 → 3일 넘어도 폴링
        $l->saveQuietly();

        $this->artisan('board:poll-ssancar-media')->assertExitCode(0);

        $this->assertSame('inspected', $l->fresh()->status);
    }

    public function test_poll_ssancar_media_advances_regardless_of_ssancar_ref(): void
    {
        // 227소9997 케이스: ssancar_ref=car_no 여도 폴러는 번호판 교차매칭 sources 로 판정 → ref 무관.
        $this->ssancarMediaConfig();
        Http::fake(['*api_car_media.php*' => Http::response($this->ssancarResp(1, 2, 0), 200)]);
        $l = $this->mkListing($this->mkUser('sales'), ['status' => 'draft', 'ssancar_ref' => 'car_no:1639088512']);

        $this->artisan('board:poll-ssancar-media')->assertExitCode(0);

        $this->assertSame('inspected', $l->fresh()->status);
    }

    public function test_inspection_upload_defaults_to_shared(): void
    {
        config(['board.photo_disk' => 'public']);
        Storage::fake('public');
        $insp = $this->mkUser('inspection');
        $l = $this->mkListing($insp, ['status' => 'draft']);
        $this->actingAs($insp);

        Volt::test('inspection.index')
            ->call('openDrawer', $l->id)
            ->set('photos', [UploadedFile::fake()->image('front.jpg')])
            ->call('save')
            ->assertHasNoErrors();

        $p = $l->photos()->first();
        $this->assertNotNull($p);
        $this->assertTrue((bool) $p->share_to_buyer);   // 기본 공유(opt-out) — 바이어 페이지에 바로 노출
    }

    public function test_photo_proxy_streams_for_owner_and_blocks_other_salesman(): void
    {
        Storage::fake('public');
        config(['board.photo_disk' => 'public']);
        $owner = $this->mkUser('sales');
        $l = $this->mkListing($owner, ['status' => 'inspected']);
        $p = $l->photos()->create(['s3_path' => 'i/x.jpg', 'original_name' => 'x.jpg', 'sort' => 1, 'kind' => InspectionPhoto::KIND_INSPECTION]);
        Storage::disk('public')->put('i/x.jpg', 'IMG');

        // 소유 영업 = 스트리밍 OK (모바일 다중 공유 fetch 의 같은출처 소스)
        $this->actingAs($owner)->get(route('photos.show', $p->id))->assertOk();
        // 다른 영업 = SalesmanScope 로 403 (IDOR 차단)
        $this->actingAs($this->mkUser('sales'))->get(route('photos.show', $p->id))->assertForbidden();
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
        // 소스 = car-erp /rates(전신환 매입률 원본, 반올림 X). Frankfurter 폐기.
        config(['services.car_erp.base_url' => 'https://carerp.test', 'services.car_erp.read_hmac_secret' => 'shh']);
        Http::fake(['*/api/internal/board/rates' => Http::response([
            'rates' => ['USD' => 1400.50, 'EUR' => 1550.00, 'JPY' => 905.5],
            'fetched_at' => '2026-07-03 14:05', 'source' => 'naver',
        ], 200)]);

        $svc = app(ExchangeRateService::class);
        $svc->refresh();

        $this->assertSame(1401, $svc->krwPerUsd());   // round(1400.50), 계산/표시용 int
        $this->assertSame(1550, $svc->krwPerEur());
        $this->assertSame('1400.50', (string) ExchangeRate::where('currency', 'USD')->first()->krw_per_unit);   // 원본 소수 보존(car-erp와 어긋남 방지)
        $this->assertSame('1,400.50', $svc->snapshot()['USD_display']);   // 표시 2자리 = car-erp number_format(rate,2) 와 일치

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
        config([
            'board.rate_auto_refresh' => true,   // 테스트 기본 false → 이 테스트만 켬
            'services.car_erp.base_url' => 'https://carerp.test',
            'services.car_erp.read_hmac_secret' => 'shh',
        ]);
        Cache::flush();
        Http::fake(['*/api/internal/board/rates' => Http::response([
            'rates' => ['USD' => 1400, 'EUR' => 1600],
        ], 200)]);
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
            ->call('openDetail', $l->id)
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

    public function test_super_can_delete_listing_with_audit(): void
    {
        $super = $this->mkUser('manager', null, 'super');
        $l = $this->mkListing($this->mkUser('sales'), ['status' => 'awaiting_buyer']);
        $this->actingAs($super);

        Volt::test('manage.index')
            ->call('openEdit', $l->id)
            ->call('deleteListing')
            ->assertHasNoErrors();

        $this->assertSoftDeleted('purchase_listings', ['id' => $l->id]);
        $this->assertDatabaseHas('board_audit_logs', [
            'purchase_listing_id' => $l->id, 'action' => 'delete', 'field' => 'deleted', 'user_id' => $super->id,
        ]);
    }

    public function test_manager_cannot_delete_listing(): void
    {
        $l = $this->mkListing($this->mkUser('sales'));
        $this->actingAs($this->mkUser('manager'));   // super 아님

        Volt::test('manage.index')
            ->call('openEdit', $l->id)
            ->call('deleteListing')
            ->assertForbidden();

        $this->assertNotSoftDeleted('purchase_listings', ['id' => $l->id]);
    }

    public function test_super_can_resync_synced_listing_to_car_erp(): void
    {
        Bus::fake();
        $super = $this->mkUser('manager', null, 'super');
        $l = $this->mkListing($this->mkUser('sales'), ['status' => 'synced', 'car_erp_vehicle_id' => 188]);
        $this->actingAs($super);

        Volt::test('manage.index')
            ->call('openEdit', $l->id)
            ->call('resyncToCarErp')
            ->assertHasNoErrors();

        $l->refresh();
        $this->assertNull($l->car_erp_vehicle_id);   // 멱등 포인터 비움 → 재전송 가드 통과
        $this->assertSame('won', $l->status);         // synced→won 되돌림(Job 가드용)
        Bus::assertDispatched(SyncWonListingToCarErp::class);
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

    /** 사용자관리 = 관리 role + super (2026-08-04 Jin). 영업·검차는 여전히 차단. */
    public function test_user_management_is_open_to_manager(): void
    {
        $this->actingAs($this->mkUser('sales'))->get('/users')->assertForbidden();
        $this->actingAs($this->mkUser('inspection'))->get('/users')->assertForbidden();
        $this->actingAs($this->mkUser('manager'))->get('/users')->assertOk();
        $this->actingAs($this->mkUser('manager', null, 'super'))->get('/users')->assertOk();
    }

    /** super 전용 화면(기능설정·감사로그)은 관리 role 에 열리지 않는다 — /users 개방이 새지 않았는지. */
    public function test_super_only_screens_stay_closed_to_manager(): void
    {
        $this->actingAs($this->mkUser('manager'));
        $this->get('/audit')->assertForbidden();
        $this->get('/admin/settings')->assertForbidden();
    }

    /** 관리 role 은 super 를 지정할 수 없다 — 화면에서 숨겨도 프로퍼티는 조작 가능하므로 서버가 막는다. */
    public function test_manager_cannot_grant_super(): void
    {
        $this->actingAs($this->mkUser('manager'));

        Volt::test('users.index')
            ->call('openCreate')
            ->set('name', '침입')->set('email', 'esc@board.test')->set('role', 'manager')
            ->set('is_super', true)
            ->set('password', 'secret123')
            ->call('save')->assertHasNoErrors();

        $this->assertSame('user', User::where('email', 'esc@board.test')->value('permission'));
    }

    /** 관리 role 은 자기 자신도 super 로 승격할 수 없다(권한상승 차단). */
    public function test_manager_cannot_self_promote_to_super(): void
    {
        $me = $this->mkUser('manager');
        $this->actingAs($me);

        Volt::test('users.index')
            ->call('openEdit', $me->id)
            ->set('is_super', true)
            ->call('save')->assertHasNoErrors();

        $this->assertFalse($me->fresh()->isSuper());
        $this->assertSame('manager', $me->fresh()->role); // 자기 역할도 유지(스스로 잠금 방지)
    }

    /** 관리 role 은 super 계정을 수정·비활성화할 수 없다(비밀번호 교체를 통한 계정 탈취 차단). */
    public function test_manager_cannot_touch_super_account(): void
    {
        $super = $this->mkUser('sales', 'boss@board.test', 'super');
        $this->actingAs($this->mkUser('manager'));

        Volt::test('users.index')
            ->call('openEdit', $super->id)
            ->assertSet('editingId', null)      // 드로어 자체가 안 열린다
            ->set('editingId', $super->id)      // 직접 밀어넣어도
            ->set('name', '탈취')->set('email', 'boss@board.test')->set('role', 'sales')
            ->set('password', 'hacked123')
            ->call('save')
            ->call('toggleActive', $super->id);

        $this->assertSame('sales', $super->fresh()->name);   // mkUser 는 name=role
        $this->assertTrue($super->fresh()->is_active);
        $this->assertTrue(Hash::check('password', $super->fresh()->password));
    }

    /** 관리 role 도 일반 계정은 만들고 고칠 수 있어야 한다(이번 개방의 본래 목적). */
    public function test_manager_can_manage_normal_accounts(): void
    {
        $this->actingAs($this->mkUser('manager'));

        Volt::test('users.index')
            ->call('openCreate')
            ->set('name', '새영업')->set('email', 'byman@board.test')->set('role', 'sales')
            ->set('password', 'secret123')
            ->call('save')->assertHasNoErrors();

        $u = User::where('email', 'byman@board.test')->firstOrFail();
        $this->assertSame('user', $u->permission);

        Volt::test('users.index')->call('toggleActive', $u->id);
        $this->assertFalse($u->fresh()->is_active);
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

    public function test_sync_payload_includes_selling_fee_payee_masked_in_log(): void
    {
        config(['services.car_erp.base_url' => 'https://carerp.test', 'services.car_erp.hmac_secret' => 'shh']);
        Http::fake(['*/api/internal/purchase-sync' => Http::response(['vehicle_id' => 778], 200)]);

        $l = $this->mkListing($this->mkUser('sales'), [
            'status' => 'won', 'source' => 'auction', 'final_price' => 9000000,
            'selling_fee_payee_name' => '매도대행', 'selling_fee_payee_bank' => '신한',
            'selling_fee_payee_account' => '999-888-77766',
        ]);

        (new SyncWonListingToCarErp($l->id))->handle();

        // v4: 매도비 계좌(판매자와 별개) — 전송 본문엔 실값, 로그엔 마스킹
        Http::assertSent(fn ($r) => $r['contract_version'] === 4
            && $r['selling_fee_payee_name'] === '매도대행'
            && $r['selling_fee_payee_bank'] === '신한'
            && $r['selling_fee_payee_account'] === '999-888-77766');

        $ev = IntegrationEvent::first();
        $this->assertSame('***', $ev->request_payload['selling_fee_payee_account']);

        // 계좌번호 at-rest 암호화 확인
        $raw = \DB::table('purchase_listings')->where('id', $l->id)->value('selling_fee_payee_account');
        $this->assertNotSame('999-888-77766', $raw);
    }

    public function test_offer_display_uses_confirmed_currency_and_snapshot_rate(): void
    {
        // EUR 확정 딜 — 표시는 € 금액, 환율은 offer_rate(전달 시점 스냅샷) 고정: 라이브 환율 줘도 불변
        $eur = $this->mkListing($this->mkUser('sales'), [
            'status' => 'won', 'final_price' => 10340000, 'offer_currency' => 'EUR', 'offer_rate' => 1500,
        ]);
        $this->assertSame('€6,893', $eur->offerDisplay(9999, 9999));   // 10,340,000 / 1500, 라이브(9999) 무시

        // 통화 미확정 → KRW(final_price) 표시(전달 전 기본)
        $krw = $this->mkListing($this->mkUser('sales'), ['status' => 'won', 'final_price' => 8500000]);
        $this->assertStringContainsString('8,500,000', $krw->offerDisplay());
    }

    public function test_buyer_page_uses_configured_company_name(): void
    {
        Setting::updateOrCreate(['key' => 'buyer_company_name'], ['value' => 'HEYMAN', 'type' => 'string']);
        $l = $this->mkListing($this->mkUser('sales'), ['status' => 'won', 'final_price' => 9000000, 'offer_currency' => 'USD', 'offer_rate' => 1400]);

        $url = URL::temporarySignedRoute('buyer.view', now()->addDays(30), ['listing' => $l->id]);
        $this->get($url)->assertOk()->assertSee('HEYMAN')->assertDontSee('SSANCAR');
    }

    public function test_og_card_url_is_versioned_for_cache_bust(): void
    {
        // 카톡 OG 캐시(URL 단위) 회피 — 카드 URL에 수정시각 버전이 붙어 견적 변경 시 갱신.
        $l = $this->mkListing($this->mkUser('sales'), ['status' => 'won', 'final_price' => 9000000, 'offer_currency' => 'USD', 'offer_rate' => 1500]);

        $url = URL::temporarySignedRoute('buyer.view', now()->addDays(30), ['listing' => $l->id]);
        $res = $this->get($url)->assertOk();
        $this->assertStringContainsString('card.png?v=', $res->getContent());   // og:image 에 캐시버스트 v=

        // 버전 붙은 카드 URL 도 서명 유효(200 · PNG)
        $cardUrl = URL::signedRoute('buyer.card', ['listing' => $l->id, 'v' => $l->updated_at?->timestamp ?? 0]);
        $this->get($cardUrl)->assertOk()->assertHeader('Content-Type', 'image/png');
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

    /**
     * 모바일에서 공유한 엔카 링크(추적 파라미터 354자)로 매입예정 추가 — 2026-08-04 운영 실패 재현.
     * encar_url 은 varchar(255)+max:255 라 원본을 그대로 넣으면 저장이 조용히 죽었다.
     */
    public function test_promote_via_long_mobile_encar_link_saves(): void
    {
        Http::fake(['*api.encar.com*' => Http::response(['vehicleNo' => '244로9100', 'advertisement' => ['price' => 100]], 200)]);
        $this->actingAs($this->mkUser('sales'));

        $url = 'https://fem.encar.com/cars/detail/41410821?advClickPosition=mweb_mopre_g8_t93&listAdvType=mpremium'
            .'&type=detail&view_type=checked&_gl=1*19pildd*_gcl_au*MTM1MDExODI0My4xNzg1NTE4MjM3Li0uLS4xNzg1NTE4MzA3'
            .'LjYzMTgyMzEwMy4xNzg1NjE1NjUwLjE3ODU2NzgyODQ.*_ga*NTYxODc3NDUzLjE3ODU1MTgyMzc.*_ga_BQ7RK9J6BZ'
            .'*czE3ODU2NzY5OTUkbzkkZzEkdDE3ODU2NzgzNTMkajM3JGwwJGg4Mzg1MDk1OA';
        $this->assertGreaterThan(255, strlen($url));   // 재현 조건 자체를 고정

        Volt::test('listings.index')
            ->set('encarLink', $url)
            ->call('parseLink', 'encar')
            ->assertSet('encar_id', '41410821')
            ->set('vehicle_number', '55가5555')
            ->call('save')
            ->assertHasNoErrors();

        $l = PurchaseListing::where('vehicle_number', '55가5555')->firstOrFail();
        $this->assertSame('https://fem.encar.com/cars/detail/41410821', $l->encar_url);
    }

    /** 드로어에 긴 링크를 직접 붙여넣는 경로도 같이 막혀 있었다(검증이 원본 프로퍼티에 걸린다). */
    public function test_edit_drawer_accepts_long_mobile_encar_link(): void
    {
        $this->actingAs($kim = $this->mkUser('sales'));
        $l = $this->mkListing($kim, ['source' => 'encar', 'origin' => 'encar']);

        Volt::test('listings.index')
            ->call('openEdit', $l->id)
            ->set('e_encar_url', 'https://fem.encar.com/cars/detail/41410821?advClickPosition=mweb_mopre_g8_t93'
                .'&_gl=1*19pildd*_gcl_au*MTM1MDExODI0My4xNzg1NTE4MjM3Li0uLS4xNzg1NTE4MzA3LjYzMTgyMzEwMy4xNzg1NjE1NjUw'
                .'LjE3ODU2NzgyODQ.*_ga*NTYxODc3NDUzLjE3ODU1MTgyMzc.*_ga_BQ7RK9J6BZ*czE3ODU2NzY5OTUkbzkkZzEkdDE3ODU2Nzgz')
            ->call('update')
            ->assertHasNoErrors();

        $this->assertSame('https://fem.encar.com/cars/detail/41410821', $l->fresh()->encar_url);
    }

    /** carid= 구형 URL 은 경로 구조가 달라 손대지 않는다(정규화가 링크를 깨면 안 됨). */
    public function test_canonical_encar_url_leaves_non_detail_links_alone(): void
    {
        $legacy = 'http://www.encar.com/dc/dc_cardetailview.do?carid=12345678&pageid=x';
        $this->assertSame($legacy, ListingLink::canonicalEncarUrl($legacy));
        $this->assertSame('https://www.ssancar.com/page/car_view.php?car_no=1', ListingLink::canonicalEncarUrl('https://www.ssancar.com/page/car_view.php?car_no=1'));
        $this->assertSame('', ListingLink::canonicalEncarUrl(''));
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

    public function test_forwarding_defaults_auto_when_contact_linked(): void
    {
        Bus::fake();
        $sales = $this->mkUser('sales');
        $l = $this->mkListing($sales, ['status' => 'inspected', 'respond_contact_id' => 'ct_auto', 'final_price' => 9000000]);
        $this->actingAs($sales);

        Volt::test('forwarding.index')
            ->call('openDetail', $l->id)->set('buyer_name', 'D')->call('forward')->assertHasNoErrors();

        $l->refresh();
        $this->assertSame('awaiting_buyer', $l->status);
        $this->assertSame('auto', $l->verdict_channel);
    }

    public function test_forwarding_without_contact_is_manual(): void
    {
        Bus::fake();
        $sales = $this->mkUser('sales');
        $l = $this->mkListing($sales, ['status' => 'inspected', 'final_price' => 9000000]);  // 대화 미연결
        $this->actingAs($sales);

        Volt::test('forwarding.index')
            ->call('openDetail', $l->id)->set('buyer_name', 'D')->call('forward')->assertHasNoErrors();

        $this->assertSame('manual', $l->fresh()->verdict_channel);   // 자동 불가 → 수동
    }

    public function test_forwarding_second_auto_car_blocked_then_manual(): void
    {
        Bus::fake();
        $sales = $this->mkUser('sales');
        $a = $this->mkListing($sales, ['status' => 'awaiting_buyer', 'buyer_verdict' => 'pending', 'respond_contact_id' => 'ct_g', 'verdict_channel' => 'auto']);
        $b = $this->mkListing($sales, ['status' => 'inspected', 'respond_contact_id' => 'ct_g', 'final_price' => 9000000]);
        $this->actingAs($sales);

        // 2번째 자동 차 전달 시도 → (가) 보류 + 알림
        $c = Volt::test('forwarding.index')
            ->call('openDetail', $b->id)->set('buyer_name', 'D')->call('forward');
        $c->assertSet('conflictVehicle', $a->vehicle_number);
        $this->assertSame('inspected', $b->fresh()->status);   // 전달 보류

        // 수동으로 전환해 전달
        $c->call('forwardManual')->assertHasNoErrors();
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

    public function test_forwarding_dispatches_offer_to_buyer(): void
    {
        Bus::fake();
        $sales = $this->mkUser('sales');
        $l = $this->mkListing($sales, ['status' => 'inspected', 'respond_contact_id' => 'ct_s', 'final_price' => 9000000]);
        $this->actingAs($sales);

        Volt::test('forwarding.index')
            ->call('openDetail', $l->id)->set('buyer_name', 'D')->call('forward')->assertHasNoErrors();

        Bus::assertDispatched(SendOfferToBuyer::class, fn ($j) => $j->listingId === $l->id);
    }

    public function test_forwarding_notify_fires_on_new_inspected(): void
    {
        $sales = $this->mkUser('sales');
        $this->actingAs($sales);

        $c = Volt::test('notify.poll');   // mount: lastCount=0 (아직 검차완료 없음)
        $this->mkListing($sales, ['status' => 'inspected', 'final_price' => 9000000]);

        // 새 검차완료 도착 → 알림 이벤트 발화
        $c->call('check')->assertDispatched('forward-arrived');
        // 변화 없으면 재발화 안 함
        $c->call('check')->assertNotDispatched('forward-arrived');
    }

    public function test_notify_fires_carerp_synced_toast(): void
    {
        $sales = $this->mkUser('sales');
        $this->actingAs($sales);

        $c = Volt::test('notify.poll');   // mount: lastSynced=0
        $this->mkListing($sales, ['status' => 'synced', 'car_erp_vehicle_id' => 190]);

        // car-erp 전송완료(synced) → type=synced 토스트 발화
        $c->call('check')->assertDispatched('forward-arrived', type: 'synced');
        // 변화 없으면 재발화 안 함
        $c->call('check')->assertNotDispatched('forward-arrived');
    }

    // 견적 통화 확정은 forwarding 단계로 이동(2026-07-06) → test_forwarding_set_quote_currency_saves_only_on_click 참조.

    // ─────────────────────── 견적 카드 + 전달대기 통화 + 재견적 ───────────────────────

    public function test_forwarding_open_detail_does_not_overwrite_offer_currency(): void
    {
        Bus::fake();
        $sales = $this->mkUser('sales');
        $l = $this->mkListing($sales, [
            'status' => 'inspected', 'final_price' => 15000000,
            'offer_currency' => 'EUR', 'offer_rate' => 1500,
        ]);
        $this->actingAs($sales);

        // 드로어 열기 = 표시만 EUR, 저장 ❌ (EUR 딜 보존 — 연동 B 판매통화 안 깨짐)
        Volt::test('forwarding.index')
            ->call('openDetail', $l->id)
            ->assertSet('quoteCurrency', 'EUR');

        $l->refresh();
        $this->assertSame('EUR', $l->offer_currency);
        $this->assertSame(1500, $l->offer_rate);
    }

    public function test_forwarding_set_quote_currency_saves_only_on_click(): void
    {
        Bus::fake();
        $sales = $this->mkUser('sales');
        // 차값 13.8M KRW, 할인 0, 배송 1640 USD → 판매가=13,800,000(매도비 제외) + 1640×1380 = 16,063,200
        $l = $this->mkListing($sales, [
            'status' => 'inspected',
            'car_cost' => 13800000, 'expected_price_currency' => 'KRW',
            'discount_rate' => 0, 'shipping_usd' => 1640,
            'offer_currency' => 'KRW', 'offer_rate' => 1, 'final_price' => 15620000,
        ]);
        $this->actingAs($sales);

        // 통화 선택은 미리보기만 → [적용](saveAmount)에서 offer_currency/rate/final 확정.
        Volt::test('forwarding.index')
            ->call('openDetail', $l->id)
            ->call('pickQuoteCurrency', 'USD')
            ->assertSet('quoteCurrency', 'USD')
            ->assertSet('amountDirty', true)
            ->call('saveAmount')
            ->assertSet('amountDirty', false);

        $l->refresh();
        $this->assertSame('USD', $l->offer_currency);
        $this->assertSame(1380, $l->offer_rate);          // 통화 변경 → 라이브(폴백) 환율 스냅샷
        $this->assertSame(16063200, $l->final_price);      // totalKrw 재스냅샷(매도비 제외, Model A)
    }

    public function test_forwarding_quote_card_amounts_are_consistent(): void
    {
        Bus::fake();
        $sales = $this->mkUser('sales');
        // Model A: 판매가 13,800,000(매도비 제외) + 운임 1,000×1,380 = 15,180,000
        $attr = [
            'status' => 'inspected', 'car_cost' => 13800000, 'expected_price_currency' => 'KRW',
            'discount_rate' => 0, 'shipping_usd' => 1000, 'final_price' => 15180000,
        ];
        $eur = $this->mkListing($sales, $attr + ['offer_currency' => 'EUR', 'offer_rate' => 1500]);
        $krw = $this->mkListing($sales, $attr + ['offer_currency' => 'KRW', 'offer_rate' => 1]);
        $this->actingAs($sales);

        foreach ([$eur, $krw] as $l) {
            // 카드는 라이브 환율(워킹 미리보기) — offer_rate 스냅샷과 일치시키려 동일 환율 주입
            $c = Volt::test('forwarding.index')->set('krwPerUsd', 1380)->set('krwPerEur', 1500)->call('openDetail', $l->id);
            $q = $c->instance()->quoteData();
            $strip = fn ($s) => (int) preg_replace('/[^0-9]/', '', $s);
            $car = $strip($q['car']);
            $ship = $strip($q['shipping']);
            $total = $strip($q['total']);
            // 합 == 최종 == offerAmount (KRW/EUR 각각) — 잔차 흡수로 어긋남 없음
            $this->assertSame($total, $car + $ship, "{$q['currency']} 분해 합 불일치");
            $this->assertSame($l->offerAmount(1380, 1500)['amount'], $total, "{$q['currency']} total≠offerAmount");
        }
    }

    public function test_quote_card_absent_when_final_price_null(): void
    {
        Bus::fake();
        $sales = $this->mkUser('sales');
        $l = $this->mkListing($sales, ['status' => 'inspected', 'final_price' => null]);
        $this->actingAs($sales);

        $c = Volt::test('forwarding.index')->call('openDetail', $l->id);
        $this->assertNull($c->instance()->quoteData());   // 금액 미설정 → 카드 없이 사진만
    }

    public function test_quote_card_romanizes_vehicle_number(): void
    {
        Bus::fake();
        $sales = $this->mkUser('sales');
        $l = $this->mkListing($sales, [
            'status' => 'inspected', 'vehicle_number' => '375러1924', 'car_cost' => 10000000, 'final_price' => 10000000,
            'offer_currency' => 'KRW', 'offer_rate' => 1,
        ]);
        $this->actingAs($sales);

        $c = Volt::test('forwarding.index')->call('openDetail', $l->id);
        $q = $c->instance()->quoteData();

        $this->assertSame('375 REO 1924', $q['vehicle']);            // 카드는 로마자 표기
        $this->assertSame('375러1924', $l->fresh()->vehicle_number);  // 실제 식별값은 불변
    }

    public function test_verdicts_requote_returns_to_inspected(): void
    {
        $sales = $this->mkUser('sales');
        $l = $this->mkListing($sales, ['status' => 'awaiting_buyer', 'buyer_verdict' => 'pending']);
        $this->actingAs($sales);

        Volt::test('verdicts.index')
            ->call('openDetail', $l->id)
            ->call('requote', $l->id)
            ->assertHasNoErrors();

        $l->refresh();
        $this->assertSame('inspected', $l->status);     // 전달대기로 복귀
        $this->assertSame('pending', $l->buyer_verdict); // 거절 아님 — verdict 유지
    }

    public function test_requote_then_reforward_works(): void
    {
        Bus::fake();
        $sales = $this->mkUser('sales');
        $l = $this->mkListing($sales, ['status' => 'awaiting_buyer', 'buyer_verdict' => 'pending']);
        $this->actingAs($sales);

        // 재견적 → inspected → 다시 전달 → awaiting_buyer (manual, 충돌 없음)
        app(VerdictService::class)->requote($l->id);
        $this->assertSame('inspected', $l->fresh()->status);

        Volt::test('forwarding.index')
            ->call('openDetail', $l->id)
            ->call('forward')
            ->assertHasNoErrors();

        $this->assertSame('awaiting_buyer', $l->fresh()->status);
    }

    public function test_reject_remains_terminal(): void
    {
        $sales = $this->mkUser('sales');
        $l = $this->mkListing($sales, ['status' => 'awaiting_buyer', 'buyer_verdict' => 'pending']);
        $this->actingAs($sales);

        Volt::test('verdicts.index')->call('openDetail', $l->id)->call('reject', $l->id)->assertHasNoErrors();

        $l->refresh();
        $this->assertSame('rejected', $l->status);
        $this->assertSame('rejected', $l->buyer_verdict);
        $this->assertSame([], PurchaseListing::TRANSITIONS['rejected']);   // 터미널 유지
    }

    public function test_inspection_can_delete_photo(): void
    {
        Storage::fake('public');
        config(['board.photo_disk' => 'public']);
        $l = $this->mkListing($this->mkUser('sales'), ['status' => 'draft']);
        $p = $l->photos()->create(['s3_path' => 'i/x.jpg', 'original_name' => 'x.jpg', 'sort' => 1, 'kind' => InspectionPhoto::KIND_INSPECTION]);
        Storage::disk('public')->put('i/x.jpg', 'X');
        $this->actingAs($this->mkUser('inspection'));

        Volt::test('inspection.index')
            ->call('openDrawer', $l->id)
            ->call('deletePhoto', $p->id)
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('inspection_photos', ['id' => $p->id]);
        Storage::disk('public')->assertMissing('i/x.jpg');
    }

    public function test_send_offer_uses_chosen_currency(): void
    {
        $this->respondConfig();
        config(['board.photo_disk' => 'public']);
        Http::fake(['*/message' => Http::response(['messageId' => 1], 200)]);

        $l = $this->mkListing($this->mkUser('sales'), [
            'status' => 'awaiting_buyer', 'buyer_verdict' => 'pending', 'respond_contact_id' => 'ct_eur',
            'final_price' => 13800000, 'offer_currency' => 'EUR', 'offer_rate' => 1500,
        ]);

        (new SendOfferToBuyer($l->id))->handle(app(RespondIoService::class), app(ExchangeRateService::class));

        // 13,800,000 / 1500 = 9,200 EUR — 메시지에 EUR 금액
        Http::assertSent(fn ($req) => str_contains($req->url(), '/message')
            && str_contains((string) ($req['message']['text'] ?? ''), 'EUR 9,200'));
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
        $this->assertSame(6500000, $r['prices']['KRW']);   // 650만 ×10000, encar=원화
        $this->assertSame('대구광역시', $r['region']);
        $this->assertSame('VINX', $r['vin']);
    }

    public function test_enrichment_city_parser(): void
    {
        // 크롤링 주소도 users.region 과 같은 정식 라벨로 나와야 조인된다(축약형 금지 — Region 참조).
        $s = new ListingEnrichment;
        $this->assertSame('대구광역시', $s->city('대구 서구 문화로 37'));     // 광역시
        $this->assertSame('경기 안산시', $s->city('경기 안산시 단원구 원포공원1로 16'));   // 도+시
        $this->assertNull($s->city(''));
    }

    public function test_enrichment_failure_is_safe(): void
    {
        Http::fake(['*api.encar.com*' => Http::response('', 500)]);
        $this->assertSame([], (new ListingEnrichment)->byEncarId('1'));   // throw 안 함, prefill 없음
    }

    public function test_encar_history_maps_all_three(): void
    {
        Http::fake([
            '*/v1/readside/vehicle/*' => Http::response(['vehicleNo' => '66머0996'], 200),
            '*/v1/readside/record/vehicle/*' => Http::response([
                'openData' => true, 'year' => '2019', 'maker' => '포드', 'model' => '익스플로러',
                'myAccidentCnt' => 2, 'myAccidentCost' => 11568500, 'otherAccidentCnt' => 1,
                'accidents' => [['date' => '2025-09-14', 'insuranceBenefit' => 450000, 'partCost' => 23750, 'laborCost' => 0, 'paintingCost' => 279438]],
            ], 200),
            '*/v1/readside/inspection/vehicle/*' => Http::response([
                'master' => ['accdient' => false, 'simpleRepair' => true, 'detail' => ['mileage' => 189165, 'recall' => true, 'recallFullFillTypes' => [['title' => '미이행']]]],
                'inners' => [['type' => ['title' => '자기진단'], 'children' => [
                    ['type' => ['title' => '원동기'], 'statusType' => ['title' => '양호']],       // 정상 → ok
                    ['type' => ['title' => '오일누유'], 'statusType' => ['title' => '누수']],       // 문제 → !ok
                    ['type' => ['title' => '자동변속기'], 'statusType' => null],                    // 미점검 → 생략
                ]]],
                'outers' => [
                    ['type' => ['title' => '후드'], 'statusTypes' => [['code' => 'W', 'title' => '판금/용접']]],   // amber
                    ['type' => ['title' => '프론트펜더'], 'statusTypes' => [['code' => 'X', 'title' => '교환']]],  // red
                ],
            ], 200),
            '*/v1/readside/diagnosis/vehicle/*' => Http::response([
                'diagnosisDate' => '2026-05-27T00:00:00', 'reservationCenterName' => '부천 서운',
                'items' => [
                    ['name' => 'FRONT_DOOR_LEFT', 'result' => '정상', 'resultCode' => 'NORMAL'],   // 정상 → 숨김
                    ['name' => 'HOOD', 'result' => '교환', 'resultCode' => 'EXCHANGE'],             // 이상 → 표시
                    ['name' => 'CHECKER_COMMENT', 'result' => "'무사고' 차량으로 판정합니다.\n표준 고지문 생략", 'resultCode' => null],
                ],
            ], 200),
        ]);

        $h = (new ListingEnrichment)->encarHistory('42080261');

        $this->assertSame('66머0996', $h['vehicle_number']);
        $this->assertSame('2019 포드 익스플로러', $h['record']['title']);
        $this->assertSame(2, $h['record']['myAccidentCnt']);
        $this->assertCount(1, $h['record']['accidents']);
        $this->assertSame(189165, $h['inspection']['mileage']);
        $this->assertFalse($h['inspection']['accident']);            // accdient(엔카 원본 오타키) 매핑
        $this->assertSame('미이행', $h['inspection']['recallStatus']);
        // 자기진단: 미점검(null) 생략 → 원동기(ok)·오일누유(!ok) 2개만
        $kids = $h['inspection']['inners'][0]['children'];
        $this->assertCount(2, $kids);
        $this->assertTrue($kids[0]['ok']);            // 원동기 양호
        $this->assertFalse($kids[1]['ok']);           // 오일누유 누수 = 문제
        // 외판·골격 상태부호별 색(Jin 지정): W=파랑, X=빨강
        $this->assertSame('blue', $h['inspection']['outers'][0]['color']);   // 판금/용접
        $this->assertSame('red', $h['inspection']['outers'][1]['color']);    // 교환
        // 엔카진단: 판정문구 분리(첫 줄만) + 정상 숨김, 이상만
        $this->assertSame(["'무사고' 차량으로 판정합니다."], $h['diagnosis']['verdicts']);
        $this->assertCount(1, $h['diagnosis']['items']);              // 정상(FRONT_DOOR_LEFT) 숨김
        $this->assertSame('후드', $h['diagnosis']['items'][0]['name']);   // 이상(HOOD 교환), 코드→한글
    }

    public function test_encar_history_diagnosis_absent_is_null(): void
    {
        Http::fake([
            '*/v1/readside/vehicle/*' => Http::response(['vehicleNo' => '66머0996'], 200),
            '*/v1/readside/record/vehicle/*' => Http::response(['openData' => true], 200),
            '*/v1/readside/inspection/vehicle/*' => Http::response(['master' => ['detail' => []]], 200),
            '*/v1/readside/diagnosis/vehicle/*' => Http::response('', 404),   // 엔카 미진단차
        ]);

        $h = (new ListingEnrichment)->encarHistory('1');

        $this->assertNotNull($h['record']);
        $this->assertNotNull($h['inspection']);
        $this->assertNull($h['diagnosis']);   // 개별 null, 나머진 살아있음
    }

    public function test_encar_history_base_failure_is_empty(): void
    {
        Http::fake(['*api.encar.com*' => Http::response('', 500)]);
        $this->assertSame([], (new ListingEnrichment)->encarHistory('1'));   // base 실패=전체 []
    }

    public function test_listings_history_button_renders_panel(): void
    {
        Http::fake([
            '*/v1/readside/vehicle/*' => Http::response(['vehicleNo' => '66머0996'], 200),
            '*/v1/readside/record/vehicle/*' => Http::response(['openData' => true, 'myAccidentCnt' => 2], 200),
            '*/v1/readside/inspection/vehicle/*' => Http::response(['master' => ['detail' => ['mileage' => 189165]]], 200),
            '*/v1/readside/diagnosis/vehicle/*' => Http::response('', 404),
        ]);
        $this->actingAs($this->mkUser('sales'));

        Volt::test('listings.index')
            ->set('showAdd', true)          // 이력 패널은 추가폼 안에 있음
            ->set('encar_id', '42080261')
            ->call('loadEncarHistory')
            ->assertHasNoErrors()
            ->assertSet('showHistory', true)
            ->assertSee('보험이력')          // record 라벨
            ->assertSee('성능점검')          // inspection 라벨
            ->assertSee('189,165');          // 주행거리 실값 렌더
    }

    public function test_listings_history_needs_link(): void
    {
        $this->actingAs($this->mkUser('sales'));
        Volt::test('listings.index')
            ->call('loadEncarHistory')      // encar_id·링크 없음
            ->assertHasErrors('encarLink');
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
            ->assertSet('expected_price_currency', 'KRW')
            ->assertSet('car_cost', '66660000')         // 크롤링 KRW → 차값 자동매핑(금액산정 입력)
            ->assertSet('region', '경기 안산시')
            ->assertSet('vin', 'WMW21GA04S7R38829');
    }

    public function test_pricetag_toggle_sets_car_cost_as_is(): void
    {
        // 매물표시가 통화토글 = 그 통화 금액을 차값에 "그대로"(외화 그대로) + 통화 기록.
        $this->actingAs($this->mkUser('sales'));

        Volt::test('listings.index')
            ->set('priceOptions', ['KRW' => 10000000, 'USD' => 7000, 'EUR' => 6500])
            ->call('pickCurrency', 'USD')
            ->assertSet('car_cost', '7000')                // 외화 그대로
            ->assertSet('expected_price_currency', 'USD')
            ->call('pickCurrency', 'EUR')
            ->assertSet('car_cost', '6500')
            ->assertSet('expected_price_currency', 'EUR')
            ->call('pickCurrency', 'KRW')
            ->assertSet('car_cost', '10000000')
            ->assertSet('expected_price_currency', 'KRW');
    }

    public function test_display_toggle_does_not_change_car_cost(): void
    {
        // 금액산정 통화토글(displayCurrency) = 표시만, 차값 불변(적용환율 눌러도).
        $this->actingAs($this->mkUser('sales'));

        Volt::test('listings.index')
            ->set('priceOptions', ['KRW' => 10000000, 'USD' => 7000])
            ->call('pickCurrency', 'USD')
            ->assertSet('car_cost', '7000')
            ->set('displayCurrency', 'EUR')
            ->assertSet('car_cost', '7000')                // 환율 토글해도 차값 불변
            ->set('displayCurrency', 'KRW')
            ->assertSet('car_cost', '7000');
    }

    public function test_foreign_car_cost_renders_with_currency_in_drawers(): void
    {
        // USD 차값이 각 화면 드로어에서 $ 로 표기되는지(7,000원 아님) — 렌더 스모크.
        $l = $this->mkListing($this->mkUser('sales'), [
            'status' => 'accepted', 'buyer_verdict' => 'accepted', 'source' => 'auction',
            'car_cost' => 7000, 'expected_price_currency' => 'USD',
        ]);

        $this->actingAs($this->mkUser('manager'));
        Volt::test('manage.index')->call('openEdit', $l->id)->assertSee('차값 ($)');

        // 검차 화면은 금액 미표시(견적·전달로 이동, 2026-07-06) → 구매확정 드로어에서 통화표기 확인
        $this->actingAs($this->mkUser('auction'));
        Volt::test('auction.index')->call('openDetail', $l->id)->assertSee('$7,000');
    }

    public function test_foreign_car_cost_converts_to_krw_in_final_price(): void
    {
        // 싼카 USD 차값 → 저장 시 final_price 는 KRW 환산(차값은 USD 그대로 보관).
        $this->actingAs($this->mkUser('sales'));

        Volt::test('listings.index')
            ->set('priceOptions', ['KRW' => 10000000, 'USD' => 7000])
            ->set('krwPerUsd', 1400)
            ->set('vehicle_number', '33가3333')
            ->set('vin', 'USDVIN0001')
            ->call('pickCurrency', 'USD')                  // 차값 = $7,000(USD)
            ->set('discount_rate', '0')
            ->call('save')
            ->assertHasNoErrors();

        $l = PurchaseListing::where('vin', 'USDVIN0001')->first();
        $this->assertNotNull($l);
        $this->assertSame(7000, $l->car_cost);             // 차값은 USD 금액 그대로 보관
        $this->assertSame('USD', $l->expected_price_currency);
        // final_price(KRW) = 7,000×1,400 − 0%(할인) = 9,800,000 (매도비 제외, Model A, 배송 없음)
        $this->assertSame(7000 * 1400, $l->final_price);
    }

    public function test_currency_toggle_disabled_for_missing_currency(): void
    {
        $this->actingAs($this->mkUser('sales'));

        // 엔카(원화만 추출) — USD/EUR 선택해도 무시(라벨·차값 안 바뀜)
        Volt::test('listings.index')
            ->set('priceOptions', ['KRW' => 10000000])
            ->set('expected_price_currency', 'KRW')
            ->call('pickCurrency', 'USD')
            ->assertSet('expected_price_currency', 'KRW')   // 그대로(미화 비활성)
            ->call('pickCurrency', 'KRW')
            ->assertSet('expected_price_currency', 'KRW');
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
        $this->assertSame(7000000, $r['prices']['KRW']);
        $this->assertSame('서울특별시', $r['region']);
        $this->assertSame('999', $r['encar_id']);              // 원본 엔카 id 반환 → '이력 조회'에 사용
    }

    public function test_ssancar_inspected_link_populates_encar_id_for_history(): void
    {
        // 검차 싼카 링크만 붙여도 원본 엔카 id 가 폼에 채워져 '이력 조회'가 작동해야 함.
        Http::fake([
            '*api.encar.com*' => Http::response(['vehicleNo' => '55오5555'], 200),
            '*ssancar.com*' => Http::response('<a href="https://fem.encar.com/cars/detail/999">원본</a>', 200),
        ]);
        $this->actingAs($this->mkUser('sales'));

        Volt::test('listings.index')
            ->set('showAdd', true)
            ->set('ssancarLink', 'https://www.ssancar.com/x?wr_id=786')
            ->call('parseLink', 'ssancar')
            ->assertSet('encar_id', '999')
            ->assertSee(__('listings.history.view'));   // 싼카 추출 후 '이력 조회' 버튼 노출(추출됨 영역)
    }

    public function test_enrichment_ssancar_money_block_three_currencies(): void
    {
        $money = '<p class="money">Price ₩ <span>10,500,000</span> $ <span>6,791</span> € <span>5,920</span></p>';
        Http::fake(['*ssancar.com*' => Http::response('<em id="copy_txt">VIN1</em><div>12가3456</div>'.$money, 200)]);

        $r = (new ListingEnrichment)->fromSsancar('https://www.ssancar.com/x?c_no=1');

        $this->assertSame(10500000, $r['prices']['KRW']);
        $this->assertSame(6791, $r['prices']['USD']);
        $this->assertSame(5920, $r['prices']['EUR']);
    }

    public function test_listings_currency_toggle_changes_amount(): void
    {
        $money = '<p class="money">₩ <span>10,500,000</span> $ <span>6,791</span> € <span>5,920</span></p>';
        Http::fake(['*ssancar.com*' => Http::response('<em id="copy_txt">VIN1</em><div>12가3456</div>'.$money, 200)]);
        $this->actingAs($this->mkUser('sales'));

        $c = Volt::test('listings.index')
            ->set('ssancarLink', 'https://www.ssancar.com/x?c_no=1')
            ->call('parseLink', 'ssancar')
            ->assertSet('expected_price_currency', 'KRW')->assertSet('expected_price', '10500000');
        $c->call('pickCurrency', 'USD')->assertSet('expected_price', '6791')->assertSet('expected_price_currency', 'USD');
        $c->call('pickCurrency', 'EUR')->assertSet('expected_price', '5920');
    }

    public function test_enrichment_ssancar_stock_parses_vin_plate_usd_price(): void
    {
        Http::fake(['*ssancar.com*' => Http::response('<div>차량번호 12가3456</div><em id="copy_txt">KMHXX1234567</em><span>52,473 USD</span>', 200)]);

        $r = (new ListingEnrichment)->fromSsancar('https://www.ssancar.com/x?c_no=6915603');

        $this->assertSame('KMHXX1234567', $r['vin']);
        $this->assertSame('12가3456', $r['vehicle_number']);
        $this->assertSame(52473, $r['prices']['USD']);   // money 블록 없으면 USD 텍스트 폴백
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

    /**
     * 서류 다운로드 실패 안내가 원인을 사실대로 짚는지.
     * 403(car-erp 가 그 타입을 board 에 안 열어줌)을 "동일 바이어" 로 안내하면 묶음을 뜯어보게 만든다 —
     * 실제로 판매계약서가 그 상태였다(car-erp BOARD_ALLOWED_TYPES = 선적 4종, sales_contract 미포함).
     */
    public function test_portal_document_failure_distinguishes_403_from_422(): void
    {
        $this->carErpReadConfig();
        $sales = $this->mkUser('sales');
        $sales->update(['car_erp_salesman_email' => 'doc@ce.test']);
        $this->actingAs($sales);

        // Http::fake 는 재호출해도 덮어쓰지 않고 누적된다 → 순차 응답은 sequence 로.
        Http::fake(['*/api/internal/board/documents/*' => Http::sequence()
            ->push('Forbidden document type', 403)
            ->push('mixed buyers', 422)]);

        Volt::test('portal.index')
            ->call('downloadDocs', [1, 2], 'RORO', 'sales_contract')
            ->assertSet('shipNote', __('portal.flash_docs_not_allowed'));

        Volt::test('portal.index')
            ->call('downloadDocs', [1, 2], 'RORO', 'sales_contract')
            ->assertSet('shipNote', __('portal.flash_docs_homogeneous_required'));
    }

    // ─────────── §11 요청·확인 신호 (카톡 대체) ───────────

    /**
     * 🚫 판매대금확인에는 금액을 싣지 않는다(Jin 2026-08-11 — 금액 분리는 **입금요청만**).
     * 매입 2종은 금액을 싣지만(§11-2 개정), 그것도 ERP 표시 전용이지 회계 반영이 아니다.
     */
    public function test_sale_confirm_payload_carries_no_amount(): void
    {
        config(['services.car_erp.base_url' => 'https://carerp.test', 'services.car_erp.read_hmac_secret' => 'rs']);
        Http::fake(['*/api/internal/board/requests*' => Http::response(['batch_id' => null, 'created' => ['11가1111'], 'skipped' => []], 201)]);

        app(CarErpReadService::class)
            ->sendBoardRequest('s@ce.test', 'sale_payment_confirm', [12, 34], 7, '5/12 송금분');

        Http::assertSent(function ($req) {
            $body = json_decode($req->body(), true);
            $this->assertSame('sale_payment_confirm', $body['type']);
            $this->assertSame([12, 34], $body['vehicle_ids']);
            $this->assertSame(7, $body['buyer_id']);
            $this->assertSame('5/12 송금분', $body['note']);
            // 금액으로 읽힐 수 있는 키가 하나도 없어야 한다.
            foreach (['amount', 'price', 'krw', 'balance', 'payment_amount', 'total'] as $banned) {
                $this->assertArrayNotHasKey($banned, $body);
            }

            return true;
        });
    }

    /** 재전송해도 뱃지가 두 개 안 생긴다 — skipped(already_open) 는 실패가 아니라 "이미 보냄". */
    public function test_portal_purchase_request_shows_created_and_skipped(): void
    {
        config(['services.car_erp.base_url' => 'https://carerp.test', 'services.car_erp.read_hmac_secret' => 'rs']);
        $sales = $this->mkUser('sales');
        $sales->update(['car_erp_salesman_email' => 'req@ce.test']);
        $this->actingAs($sales);

        Http::fake([
            '*/api/internal/board/requests*' => Http::sequence()
                ->push(['batch_id' => null, 'created' => ['11가1111'], 'skipped' => []], 201)
                ->push(['batch_id' => null, 'created' => [], 'skipped' => [['vehicle_number' => '11가1111', 'reason' => 'already_open']]], 201),
            '*' => Http::response(['count' => 0, 'data' => []], 200),
        ]);

        Volt::test('portal.index')
            ->set('reqAmount.6', '3000000')
            ->call('sendPurchaseRequest', CarErpReadService::REQ_PURCHASE_DEPOSIT, 6)
            ->assertSet('reqResult.created', ['11가1111'])
            ->set('reqAmount.6', '3000000')
            ->call('sendPurchaseRequest', CarErpReadService::REQ_PURCHASE_DEPOSIT, 6)
            ->assertSet('reqResult.created', [])
            ->assertSet('reqResult.skipped.0.reason', 'already_open');
    }

    /**
     * 매입 요청은 계약금·잔금이 **별개 type** 이고 금액을 싣는다(2026-08-11).
     * subtype 한 개로 뭉치면 ERP 멱등키 `(vehicle_id, type)` 에 걸려 잔금 요청이 조용히 버려진다.
     */
    public function test_purchase_request_sends_type_and_amount(): void
    {
        config(['services.car_erp.base_url' => 'https://carerp.test', 'services.car_erp.read_hmac_secret' => 'rs']);
        Http::fake(['*/api/internal/board/requests*' => Http::response(['batch_id' => null, 'created' => ['11가1111'], 'skipped' => []], 201)]);

        app(CarErpReadService::class)
            ->sendBoardRequest('s@ce.test', CarErpReadService::REQ_PURCHASE_BALANCE, [12], null, null, 4500000);

        Http::assertSent(function ($req) {
            $body = json_decode($req->body(), true);
            $this->assertSame('purchase_balance', $body['type']);
            $this->assertSame(4500000, $body['amount_krw']);
            $this->assertArrayNotHasKey('buyer_id', $body);

            return true;
        });
    }

    /** 금액칸이 비면 **아무것도 보내지 않는다** — 금액 없는 요청은 받는 사람이 처리할 수 없다. */
    public function test_purchase_request_without_amount_is_not_sent(): void
    {
        config(['services.car_erp.base_url' => 'https://carerp.test', 'services.car_erp.read_hmac_secret' => 'rs']);
        $sales = $this->mkUser('sales');
        $sales->update(['car_erp_salesman_email' => 'req@ce.test']);
        $this->actingAs($sales);

        Http::fake(['*' => Http::response(['count' => 0, 'data' => []], 200)]);

        Volt::test('portal.index')
            ->call('sendPurchaseRequest', CarErpReadService::REQ_PURCHASE_DEPOSIT, 6)
            ->assertSet('reqResult.error', __('portal.req_amount_required'));

        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/board/requests') && $req->method() === 'POST');
    }

    /**
     * 금액은 **차량별로 격리**된다. 한 칸을 공유하면 A 행에 친 금액이 B 행 요청으로 나간다 —
     * 틀린 금액이 그대로 송금되는 종류의 사고라 화면만 보고는 안 잡힌다.
     */
    public function test_purchase_request_amount_is_keyed_per_vehicle(): void
    {
        config(['services.car_erp.base_url' => 'https://carerp.test', 'services.car_erp.read_hmac_secret' => 'rs']);
        $sales = $this->mkUser('sales');
        $sales->update(['car_erp_salesman_email' => 'req@ce.test']);
        $this->actingAs($sales);

        Http::fake([
            '*/api/internal/board/requests*' => Http::response(['batch_id' => null, 'created' => ['11가1111'], 'skipped' => []], 201),
            '*' => Http::response(['count' => 0, 'data' => []], 200),
        ]);

        // 6번 차에만 금액을 넣고, 금액을 안 넣은 7번 차로 요청 → 6번 금액이 새어나가면 안 된다.
        Volt::test('portal.index')
            ->set('reqAmount.6', '1,200,000')
            ->call('sendPurchaseRequest', CarErpReadService::REQ_PURCHASE_DEPOSIT, 7)
            ->assertSet('reqResult.error', __('portal.req_amount_required'))
            ->call('sendPurchaseRequest', CarErpReadService::REQ_PURCHASE_DEPOSIT, 6)
            ->assertSet('reqResult.created', ['11가1111']);

        Http::assertSent(function ($req) {
            if (! str_contains($req->url(), '/board/requests') || $req->method() !== 'POST') {
                return false;
            }
            $body = json_decode($req->body(), true);
            $this->assertSame([6], $body['vehicle_ids']);
            $this->assertSame(1200000, $body['amount_krw']);   // 콤마 입력도 정규화된다

            return true;
        });
    }

    /**
     * 칩 조회가 실패했는데 칩만 조용히 사라지면 화면이 "아무것도 요청 안 함" 과 똑같이 읽힌다.
     * 버튼과 같은 원칙 — 사라지지 말고 사유를 말해야 한다.
     */
    public function test_portal_says_so_when_request_status_cannot_load(): void
    {
        config(['services.car_erp.base_url' => 'https://carerp.test', 'services.car_erp.read_hmac_secret' => 'rs']);
        $sales = $this->mkUser('sales');
        $sales->update(['car_erp_salesman_email' => 'req@ce.test']);
        $this->actingAs($sales);

        Http::fake([
            '*/api/internal/board/requests*' => Http::response('nope', 401),
            '*' => Http::response(['count' => 0, 'data' => []], 200),
        ]);

        Volt::test('portal.index')->call('setTab', 'inventory')
            ->assertSee(__('portal.req_chip_unavailable'));
    }

    /**
     * ERP 읽기 API 는 정렬 없이(id 순) 준다 → 방금 넘어온 차가 목록 맨 아래로 밀렸다.
     * 날짜 빈 행이 최신처럼 올라오면 더 헷갈리므로 그건 맨 뒤로 보낸다.
     */
    public function test_portal_lists_newest_vehicle_first(): void
    {
        $rows = [
            ['vehicle_id' => 9, 'vehicle_number' => '오래된', 'purchase_date' => '2026-04-22'],
            ['vehicle_id' => 59, 'vehicle_number' => '최신', 'purchase_date' => '2026-08-09'],
            ['vehicle_id' => 60, 'vehicle_number' => '날짜없음', 'purchase_date' => null],
            ['vehicle_id' => 61, 'vehicle_number' => '같은날_큰id', 'purchase_date' => '2026-04-22'],
        ];

        config(['services.car_erp.base_url' => '', 'services.car_erp.read_hmac_secret' => '']);
        $this->actingAs($this->mkUser('sales'));

        $sorted = Volt::test('portal.index')->instance()->latestFirst($rows, 'purchase_date');

        $this->assertSame(['최신', '같은날_큰id', '오래된', '날짜없음'], array_column($sorted, 'vehicle_number'));
    }

    /**
     * ⚠️ 지급대기(awaiting_payment)가 [입금요청] 대상이다 — car-erp `inStock()` 이 출고일뿐 아니라
     * **매입 완납까지** 보기 때문에, 미지급이 남은 차는 재고 3분류 어디에도 없다.
     * 이 탭이 기본이 아니면 영업이 요청할 차를 못 찾는다.
     */
    public function test_inventory_defaults_to_awaiting_payment_and_sends_category(): void
    {
        config(['services.car_erp.base_url' => 'https://carerp.test', 'services.car_erp.read_hmac_secret' => 'rs']);
        $sales = $this->mkUser('sales');
        $sales->update(['car_erp_salesman_email' => 'inv@ce.test']);
        $this->actingAs($sales);

        Http::fake(['*' => Http::response(['count' => 1, 'total' => 1, 'data' => [
            ['vehicle_id' => 11, 'vehicle_number' => '30가3001', 'progress_status' => '말소완료',
                'stock_location' => '홈플', 'purchase_price' => 21000000, 'purchase_unpaid' => 21000000],
        ]], 200)]);

        Volt::test('portal.index')->call('setTab', 'inventory')
            ->assertSet('invCategory', 'awaiting_payment')
            ->assertSee('30가3001')
            ->assertSee('말소완료')      // 진행상태는 ERP 값 그대로
            ->assertSee('홈플');         // 보관위치

        Http::assertSent(function ($req) {
            if (! str_contains($req->url(), '/inventory')) {
                return false;
            }

            return str_contains($req->url(), 'category=awaiting_payment');
        });
    }

    /** 출고완료만 영원히 누적된다 → 탭을 열었다고 전량을 부르면 안 된다. */
    public function test_shipped_out_is_paged_but_stock_is_not(): void
    {
        config(['services.car_erp.base_url' => 'https://carerp.test', 'services.car_erp.read_hmac_secret' => 'rs']);
        $sales = $this->mkUser('sales');
        $sales->update(['car_erp_salesman_email' => 'inv@ce.test']);
        $this->actingAs($sales);
        Http::fake(['*' => Http::response(['count' => 30, 'total' => 90, 'data' => []], 200)]);

        $c = Volt::test('portal.index')->call('setTab', 'inventory')->call('setInvCategory', 'shipped_out');
        $c->assertSet('invLimit', 30);
        Http::assertSent(fn ($req) => ! str_contains($req->url(), 'category=shipped_out') || str_contains($req->url(), 'limit=30'));

        $c->call('invMore')->assertSet('invLimit', 60);

        // 재고 분류로 돌아오면 페이징 깊이가 초기화되고 limit 을 아예 안 보낸다(전량).
        $c->call('setInvCategory', 'general')->assertSet('invLimit', 30);
        Http::assertSent(fn ($req) => ! str_contains($req->url(), 'category=general') || ! str_contains($req->url(), 'limit='));
    }

    /** 거래완료 숨김은 ERP 쿼리로 나가야 한다 — 받아놓고 감추면 트래픽이 그대로다. */
    public function test_hide_done_sales_filters_server_side(): void
    {
        config(['services.car_erp.base_url' => 'https://carerp.test', 'services.car_erp.read_hmac_secret' => 'rs']);
        $sales = $this->mkUser('sales');
        $sales->update(['car_erp_salesman_email' => 'inv@ce.test']);
        $this->actingAs($sales);
        Http::fake(['*' => Http::response(['count' => 0, 'data' => []], 200)]);

        Volt::test('portal.index')->call('setTab', 'sales');
        Http::assertSent(fn ($req) => ! str_contains($req->url(), '/sales')
            || str_contains(urldecode($req->url()), 'exclude_status=거래완료'));
    }

    // ── §12 운항 상태 (2026-08-09) ──

    /**
     * 운항 칩 = ERP 라벨 그대로. 「도착예정」을 board 가 「도착」으로 줄이면 영업이 바이어에게
     * "도착했다"고 전하고 지연 시 그대로 클레임이 된다(ETA 가 지났다는 뜻일 뿐 입항 확인이 아니다).
     */
    public function test_sailing_chip_prints_erp_label_verbatim(): void
    {
        $this->carErpReadConfig();
        Http::fake([
            '*/api/internal/board/by-buyer*' => Http::response(['data' => [
                ['buyer' => 'BuyerS', 'buyer_id' => 3, 'vehicle_count' => 2, 'sales_by_currency' => ['USD' => 100]],
            ]], 200),
            '*/api/internal/board/sales*' => Http::response(['count' => 3, 'data' => [
                ['vehicle_id' => 1, 'buyer' => 'BuyerS', 'vehicle_number' => '11가1111', 'progress_status' => '선적중',
                    'sailing' => 'in_transit', 'sailing_status' => '운항중', 'vessel_name' => 'GLOVIS SKY', 'eta_date' => '2026-09-01'],
                ['vehicle_id' => 2, 'buyer' => 'BuyerS', 'vehicle_number' => '22나2222', 'progress_status' => '거래완료',
                    'sailing' => 'arrived', 'sailing_status' => '도착예정', 'vessel_name' => null, 'eta_date' => '2026-07-20'],
                // ERP 가 라벨을 바꾸면 그대로 나와야 한다 — board 가 자기 문자열로 다시 짓고 있으면 여기서 죽는다.
                ['vehicle_id' => 3, 'buyer' => 'BuyerS', 'vehicle_number' => '33다3333', 'progress_status' => '선적완료',
                    'sailing' => 'in_transit', 'sailing_status' => 'ERP가정한라벨', 'vessel_name' => null, 'eta_date' => null],
            ]], 200),
            '*' => Http::response(['count' => 0, 'data' => []], 200),
        ]);
        $this->actingAs($this->mkUser('sales'));

        // ⚠️ 「운항중」·「도착예정」 자체로 검사하면 안 된다 — 필터 pill 라벨이 같은 문자열이라
        //    칩을 아예 안 그려도 통과한다(§11-14 위양성과 같은 형태). 칩만 낼 수 있는 값으로 본다.
        Volt::test('portal.index')->call('setTab', 'sales')
            ->assertSee('ERP가정한라벨')     // 라벨 그대로 통과 = board 가 다시 짓지 않음
            ->assertSee('GLOVIS SKY')       // 선박명
            ->assertSee('ETA 2026-07-20')   // 선박명 없이 ETA 만 있어도 뜬다
            ->assertSee('선적중');           // 진행상태와 **직교** — 운항 칩이 진행상태를 대체하지 않는다
    }

    /**
     * board 가 만든 문자열에 「도착」/「Arrived」 단독이 있으면 안 된다 —
     * 칩은 ERP 값이라 안전하지만, 필터 pill 라벨은 board 소유라 나중에 누가 줄여 쓸 수 있다.
     */
    public function test_board_never_labels_sailing_as_arrived(): void
    {
        foreach (['ko', 'en'] as $locale) {
            foreach (['sailing_all', 'sailing_in_transit', 'sailing_arrived', 'sailing_filter_label', 'sailing_totals_unfiltered'] as $key) {
                $v = (string) __('portal.'.$key, [], $locale);
                $this->assertStringNotContainsString('Arrived', $v, "{$locale}.{$key}");
                // 「도착예정」은 되고 「도착」 단독은 안 된다.
                $this->assertStringNotContainsString('도착', str_replace('도착예정', '', $v), "{$locale}.{$key} 가 「도착」을 단독으로 씀");
            }
        }
    }

    /** 운항 필터는 **서버로** 나가야 한다(ERP scopeSailing 단일출처) + 거래완료 숨김과 동시에 걸린다. */
    public function test_sailing_filter_goes_to_erp_with_exclude_status(): void
    {
        $this->carErpReadConfig();
        Http::fake(['*' => Http::response(['count' => 0, 'data' => []], 200)]);
        $this->actingAs($this->mkUser('sales'));

        Volt::test('portal.index')->call('setTab', 'sales')->call('setSalesSailing', 'in_transit')
            ->assertSet('salesSailing', 'in_transit');

        // ⚠️ 대상 아닌 요청은 false 로 떨어뜨린다 — true 로 넘기면 mount 의 /by-buyer 가 먼저 만족시켜
        //    정작 /sales 쿼리를 한 번도 안 보고 통과한다(SKILLS §11-14).
        Http::assertSent(function ($req) {
            if (! str_contains($req->url(), '/board/sales')) {
                return false;
            }
            $url = urldecode($req->url());

            return str_contains($url, 'sailing=in_transit') && str_contains($url, 'exclude_status=거래완료');
        });
    }

    /** 화이트리스트 밖 값은 무시 — 쿼리에 실려 나가면 ERP 가 조용히 버려 "필터한 척"이 된다. */
    public function test_sailing_filter_rejects_unknown_phase(): void
    {
        $this->carErpReadConfig();
        Http::fake(['*' => Http::response(['count' => 0, 'data' => []], 200)]);
        $this->actingAs($this->mkUser('sales'));

        Volt::test('portal.index')->call('setTab', 'sales')
            ->call('setSalesSailing', '도착예정')     // 한글 라벨 = HMAC canonical 이 갈리는 값
            ->assertSet('salesSailing', '');

        Http::assertSent(fn ($req) => ! str_contains($req->url(), '/board/sales')
            || ! str_contains(urldecode($req->url()), 'sailing='));
    }

    /**
     * car-erp §12 배포 전에는 응답에 `sailing` 키 자체가 없다 → 필터 pill 을 아예 숨긴다.
     * 띄워두면 ERP 가 파라미터를 무시하므로 "운항중만 보기인데 전부 보이는" 거짓 화면이 된다.
     * (2026-08-09 현재 운영 ERP 가 실제로 이 상태다.)
     */
    public function test_sailing_filter_hidden_until_erp_sends_the_field(): void
    {
        $this->carErpReadConfig();
        Http::fake(['*' => Http::response(['count' => 0, 'data' => []], 200)]);
        $this->actingAs($this->mkUser('sales'));

        $c = Volt::test('portal.index')->call('setTab', 'sales');
        $c->assertDontSee(__('portal.sailing_filter_label'));

        // 필드 있음(값 null 이어도 = "배 안 탐") → 노출.
        $this->assertTrue($c->instance()->sailingSupported([['vehicle_id' => 1, 'sailing' => null]]));
        $this->assertFalse($c->instance()->sailingSupported([['vehicle_id' => 1]]));
    }

    /** 필터가 걸린 채 0건이면 pill 이 사라져 되돌릴 수 없다 → 필터 중엔 항상 노출. */
    public function test_sailing_filter_stays_visible_when_it_returns_nothing(): void
    {
        $this->carErpReadConfig();
        Http::fake(['*' => Http::response(['count' => 0, 'data' => []], 200)]);
        $this->actingAs($this->mkUser('sales'));

        Volt::test('portal.index')->call('setTab', 'sales')->call('setSalesSailing', 'arrived')
            ->assertSee(__('portal.sailing_filter_label'));
    }

    /** 재고에는 운항 **필터가 없다**(ERP 미제공) — 얹으면 조용히 무시돼 "필터한 척"이 된다. 칩만 뜬다. */
    public function test_inventory_shows_sailing_chip_but_sends_no_sailing_param(): void
    {
        $this->carErpReadConfig();
        Http::fake(['*' => Http::response(['count' => 1, 'total' => 1, 'data' => [
            ['vehicle_id' => 9, 'vehicle_number' => '33다3333', 'progress_status' => '수출통관완료',
                'sailing' => 'in_transit', 'sailing_status' => '운항중', 'vessel_name' => 'MORNING CLARA', 'eta_date' => '2026-09-10'],
        ]], 200)]);
        $this->actingAs($this->mkUser('sales'));

        Volt::test('portal.index')->call('setTab', 'inventory')->call('setInvCategory', 'shipped_out')
            ->assertSee('운항중')->assertSee('MORNING CLARA');

        Http::assertSent(fn ($req) => ! str_contains($req->url(), '/inventory')
            || ! str_contains(urldecode($req->url()), 'sailing='));
    }

    /** 바이어 합계는 ERP 값 그대로 둔다 — 필터에 맞춰 board 가 재계산하면 그건 board 가 만든 숫자다. */
    public function test_sailing_filter_hides_empty_buyers_without_touching_totals(): void
    {
        $this->carErpReadConfig();
        Http::fake([
            '*/api/internal/board/by-buyer*' => Http::response(['data' => [
                ['buyer' => 'OnShip', 'buyer_id' => 1, 'vehicle_count' => 9, 'sales_by_currency' => ['USD' => 90000]],
                ['buyer' => 'NoShip', 'buyer_id' => 2, 'vehicle_count' => 4, 'sales_by_currency' => ['USD' => 40000]],
            ]], 200),
            '*/api/internal/board/sales*' => Http::response(['count' => 1, 'data' => [
                ['vehicle_id' => 1, 'buyer' => 'OnShip', 'vehicle_number' => '44라4444',
                    'sailing' => 'in_transit', 'sailing_status' => '운항중'],
            ]], 200),
            '*' => Http::response(['count' => 0, 'data' => []], 200),
        ]);
        $this->actingAs($this->mkUser('sales'));

        Volt::test('portal.index')->call('setTab', 'sales')->call('setSalesSailing', 'in_transit')
            ->assertSee('OnShip')
            ->assertDontSee('NoShip')              // 0대인 바이어 블록은 접는다
            ->assertSee('90,000')                  // 합계는 ERP 값 그대로(필터 미반영) — 재계산 금지
            ->assertSee(__('portal.sailing_totals_unfiltered'));
    }

    // ── 포털 차량 보조정보 (차대번호·브랜드/차종, 2026-08-10 Jin) ──

    /**
     * 차량번호가 보이는 탭이면 차대번호·브랜드/차종도 같이 보인다.
     * ⚠️ 검증값은 **그 partial 만 낼 수 있는 문자열**로 잡는다 — 브랜드명 같은 흔한 낱말로 보면
     *    화면 다른 곳과 겹쳐 partial 을 안 그려도 통과한다(SKILLS §11-17).
     */
    public function test_portal_shows_vin_and_model_where_vehicle_number_appears(): void
    {
        $this->carErpReadConfig();
        $meta = ['vin' => 'ZZTESTVIN00001', 'brand' => '현대', 'model_type' => '그랜저IG'];
        Http::fake([
            '*/api/internal/board/receivables*' => Http::response(['data' => [
                ['vehicle_number' => '11가1111', 'buyer' => 'B1', 'unpaid_krw' => 100] + $meta,
            ]], 200),
            '*/api/internal/board/inventory*' => Http::response(['count' => 1, 'total' => 1, 'data' => [
                ['vehicle_id' => 5, 'vehicle_number' => '22나2222'] + $meta,
            ]], 200),
            '*/api/internal/board/by-buyer*' => Http::response(['data' => [
                ['buyer' => 'B1', 'buyer_id' => 1, 'vehicle_count' => 1, 'sales_by_currency' => ['USD' => 1]],
            ]], 200),
            '*/api/internal/board/sales*' => Http::response(['count' => 1, 'data' => [
                ['vehicle_id' => 5, 'buyer' => 'B1', 'vehicle_number' => '33다3333'] + $meta,
            ]], 200),
            '*/api/internal/board/bundles*' => Http::response(['count' => 1, 'data' => [[
                'batch_id' => 'B9', 'ship_status' => 'requested', 'buyer' => ['id' => 1, 'name' => 'B1'],
                'vehicles' => [['vehicle_id' => 5, 'vehicle_number' => '44라4444'] + $meta],
            ]]], 200),
            '*' => Http::response(['count' => 0, 'data' => []], 200),
        ]);
        $this->actingAs($this->mkUser('sales'));

        foreach (['receivables', 'inventory', 'sales', 'shipping'] as $tab) {
            Volt::test('portal.index')->call('setTab', $tab)
                ->assertSee('ZZTESTVIN00001')     // 이 값은 이 partial 말고 나올 데가 없다
                ->assertSee('현대 그랜저IG');
        }
    }

    /**
     * car-erp 가 아직 필드를 안 보내면(그쪽 배포 전, 또는 §3 PII 판단으로 VIN 제외) **아무것도 안 그린다** —
     * 대시(—)조차 찍지 않는다. 빈 줄이 늘어서면 "정보가 없는 차"로 오해된다.
     */
    public function test_portal_vehicle_meta_degrades_when_erp_omits_fields(): void
    {
        $this->carErpReadConfig();
        Http::fake([
            '*/api/internal/board/inventory*' => Http::response(['count' => 1, 'total' => 1, 'data' => [
                ['vehicle_id' => 5, 'vehicle_number' => '55마5555'],   // vin·brand·model_type 없음
            ]], 200),
            '*' => Http::response(['count' => 0, 'data' => []], 200),
        ]);
        $this->actingAs($this->mkUser('sales'));

        $html = Volt::test('portal.index')->call('setTab', 'inventory')->assertSee('55마5555')->html();
        // 차량번호 셀 바로 뒤에 회색 보조줄이 생기면 안 된다
        $this->assertStringNotContainsString('text-[11px] font-normal text-gray-400', $html);
    }

    /** 브랜드만 오고 VIN 이 없어도(§3 PII 판단으로 제외될 수 있다) 브랜드는 보여야 한다 — 필드별 독립 degrade. */
    public function test_portal_vehicle_meta_shows_model_without_vin(): void
    {
        $this->carErpReadConfig();
        Http::fake([
            '*/api/internal/board/inventory*' => Http::response(['count' => 1, 'total' => 1, 'data' => [
                ['vehicle_id' => 5, 'vehicle_number' => '66바6666', 'vin' => null, 'brand' => '기아', 'model_type' => 'K9ZZTEST'],
            ]], 200),
            '*' => Http::response(['count' => 0, 'data' => []], 200),
        ]);
        $this->actingAs($this->mkUser('sales'));

        Volt::test('portal.index')->call('setTab', 'inventory')->assertSee('기아 K9ZZTEST');
    }

    /** 전송 실패를 성공한 척하지 않는다(§11-4 항목 5) — 영업이 보냈다고 착각하면 카톡보다 나쁘다. */
    public function test_portal_request_degrades_loudly_on_failure(): void
    {
        config(['services.car_erp.base_url' => 'https://carerp.test', 'services.car_erp.read_hmac_secret' => 'rs']);
        $sales = $this->mkUser('sales');
        $sales->update(['car_erp_salesman_email' => 'req@ce.test']);
        $this->actingAs($sales);

        Http::fake(['*' => Http::response(['error' => 'buyer_mismatch'], 422)]);

        Volt::test('portal.index')
            ->set('reqAmount.6', '3000000')
            ->call('sendPurchaseRequest', CarErpReadService::REQ_PURCHASE_DEPOSIT, 6)
            ->assertSet('reqResult.error', __('portal.req_send_failed', ['status' => 422]));
    }

    /** 판매대금확인 선택은 바이어별로 쌓인다 — 서로 다른 바이어가 한 묶음에 섞일 수 없는 구조. */
    public function test_sale_confirm_selection_is_scoped_per_buyer(): void
    {
        config(['services.car_erp.base_url' => 'https://carerp.test', 'services.car_erp.read_hmac_secret' => 'rs']);
        $sales = $this->mkUser('sales');
        $sales->update(['car_erp_salesman_email' => 'req@ce.test']);
        $this->actingAs($sales);
        Http::fake(['*' => Http::response(['count' => 0, 'data' => []], 200)]);

        $c = Volt::test('portal.index')
            ->call('toggleReqVehicle', 7, 12)
            ->call('toggleReqVehicle', 7, 34)
            ->call('toggleReqVehicle', 9, 56);

        $c->assertSet('reqPick.7', [12, 34])->assertSet('reqPick.9', [56]);

        $c->call('toggleReqVehicle', 7, 12)->assertSet('reqPick.7', [34]);   // 해제
        $c->call('sendSaleConfirm', 9);

        // ⚠️ assertSent 는 "한 건이라도 만족" 이다. 포털 mount 가 쏘는 /by-buyer·/sales 를
        //    통과시키는 조건(`! str_contains`)을 쓰면 /requests 본문을 안 보고도 통과한다.
        //    → /requests 가 아니면 false 로 떨궈서, 반드시 그 본문으로만 판정되게 한다.
        Http::assertSent(function ($req) {
            if (! str_contains($req->url(), '/requests')) {
                return false;
            }
            $b = json_decode($req->body(), true);

            return ($b['vehicle_ids'] ?? null) === [56] && ($b['buyer_id'] ?? null) === 9;
        });
    }

    /**
     * 서류 버튼 이름 = car-erp `vehicle.shipdoc.*` **그대로**(Jin 2026-08-01).
     * board 에서 다시 지으면 "ERP엔 그런 서류 없다" 가 된다 — 실제로 '계약서' 라 불러서 그랬다.
     * car-erp 가 라벨을 바꾸면 이 테스트가 먼저 깨져야 한다(양쪽 이름 갈림 감지).
     */
    public function test_portal_doc_labels_match_car_erp(): void
    {
        $expected = [
            'docs_roro_contract' => 'RORO Contract',
            'docs_container_contract' => '컨테이너 Contract',
            'docs_roro_invoice_packing' => 'RORO Invoice&Packing',
            'docs_container_invoice_packing' => '컨테이너 Invoice&Packing',
            'docs_sales_contract' => '판매계약서',
            'docs_proforma_invoice' => 'Proforma Invoice',
        ];
        app()->setLocale('ko');
        foreach ($expected as $key => $label) {
            $this->assertSame($label, __('portal.'.$key), $key);
        }

        // en 도 반드시 정의돼 있어야 한다(키 누락 시 raw 키가 그대로 노출된다).
        app()->setLocale('en');
        foreach (array_keys($expected) as $key) {
            $this->assertNotSame('portal.'.$key, __('portal.'.$key), $key.' (en)');
        }
    }

    /**
     * 판매계약서·프로포마 인보이스는 **리터럴 타입**이라 method 접두를 붙이면 안 된다.
     * 'roro_invoice' 로 나가면 car-erp 화이트리스트에 없는 이름이라 403 — 선적서류만 접두를 붙인다.
     * ⚠️ 프로포마 인보이스의 car-erp 타입명은 'invoice'(선적 'roro_invoice_packing' 과 다른 서류).
     */
    public function test_portal_literal_doc_types_skip_method_prefix(): void
    {
        $this->carErpReadConfig();
        $sales = $this->mkUser('sales');
        $sales->update(['car_erp_salesman_email' => 'doc@ce.test']);
        $this->actingAs($sales);
        Http::fake(['*/api/internal/board/documents/*' => Http::response('xlsx-bytes', 200)]);

        Volt::test('portal.index')->call('downloadDocs', [7], 'CONTAINER', 'invoice');
        Http::assertSent(fn ($r) => str_contains($r->url(), '/documents/invoice?'));

        Volt::test('portal.index')->call('downloadDocs', [7], 'CONTAINER', 'sales_contract');
        Http::assertSent(fn ($r) => str_contains($r->url(), '/documents/sales_contract?'));

        // 선적서류는 반대로 method 접두가 붙어야 한다.
        Volt::test('portal.index')->call('downloadDocs', [7], 'CONTAINER', 'contract');
        Http::assertSent(fn ($r) => str_contains($r->url(), '/documents/container_contract?'));
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

    public function test_portal_super_can_view_another_users_data(): void
    {
        $this->carErpReadConfig();
        Http::fake([
            '*/api/internal/board/finance*' => Http::response(['unpaid_total_krw' => 5000], 200),
            '*' => Http::response(['count' => 0, 'data' => []], 200),
        ]);
        $target = $this->mkUser('sales');
        $target->update(['name' => '김영업', 'car_erp_salesman_email' => 'target@ce.test']);
        $super = $this->mkUser('manager', null, 'super');
        $this->actingAs($super);

        Volt::test('portal.index')
            ->assertSee('사용자별 조회')              // 셀렉터 노출(super 전용)
            ->call('viewUser', $target->id)
            ->assertSet('viewUserId', $target->id)
            ->assertSee('김영업');                    // 조회 대상 이름 표시

        // 스코프 = super 가 선택한 사용자 이메일(서버 isSuper 게이트).
        Http::assertSent(fn ($req) => str_contains($req->url(), 'salesman_email=target%40ce.test'));
    }

    public function test_portal_non_super_cannot_impersonate(): void
    {
        $this->carErpReadConfig();
        Http::fake([
            '*/api/internal/board/finance*' => Http::response(['unpaid_total_krw' => 0], 200),
            '*' => Http::response(['count' => 0, 'data' => []], 200),
        ]);
        $other = $this->mkUser('sales');
        $other->update(['car_erp_salesman_email' => 'other@ce.test']);
        $this->actingAs($this->mkUser('sales', 'me@board.test'));

        Volt::test('portal.index')
            ->call('viewUser', $other->id)            // 비-super → 무시(본인 격리 유지)
            ->assertSet('viewUserId', null)
            ->assertDontSee('사용자별 조회');           // 셀렉터도 비노출

        // 타인 이메일로 전송된 적 없음(임퍼소네이션 차단).
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'salesman_email=other%40ce.test'));
    }

    /**
     * super 는 남의 포털에서도 **대신 실행**할 수 있다(Jin 2026-08-18 — "시스템관리자는 다 되게, erp처럼").
     * 예전엔 조회 전용이라 선적 계획 탭이 통째로 비어 보였다.
     *
     * ⚠️ 요청은 **그 영업 명의**(`salesman_email`)로 car-erp 에 간다 — ERP 관리는 그 영업이 한 것으로 본다.
     *    그래서 화면 배너가 명의를 밝힌다(그게 이 정책의 유일한 안전장치다).
     */
    public function test_portal_super_can_act_on_behalf_of_other(): void
    {
        $this->carErpReadConfig();
        Http::fake([
            '*/api/internal/board/bundles*' => Http::response(['count' => 0, 'data' => []], 200),
            '*/api/internal/board/shippable*' => Http::response(['count' => 1, 'data' => [
                ['vehicle_id' => 10, 'vehicle_number' => '11가1111', 'buyer' => ['id' => 2, 'name' => 'BuyerX'], 'consignees' => []],
            ]], 200),
            '*/api/internal/board/shipping-requests/sync*' => Http::response(['created' => [10], 'updated' => [], 'cancelled' => [], 'skipped' => [], 'locked' => []], 200),
            '*' => Http::response(['count' => 0, 'data' => []], 200),
        ]);
        $target = $this->mkUser('sales');
        $target->update(['car_erp_salesman_email' => 'target@ce.test']);
        $this->actingAs($this->mkUser('manager', null, 'super'));

        $c = Volt::test('portal.index')
            ->call('viewUser', $target->id)
            ->call('setTab', 'shipping')->call('setShipSubtab', 'plan');

        $c->assertSee($target->name)                      // 배너가 누구 명의인지 밝힌다
            ->assertDontSee(__('portal.ship_view_only_note', ['name' => $target->name]));

        $key = $c->get('desired')[0]['key'];               // 계획 화면이 실제로 그려진다(예전엔 통째로 없었다)
        $c->call('assignVehicle', $key, 10)->call('syncBundles');

        // 그 영업 명의로 전송된다.
        Http::assertSent(fn ($req) => str_contains($req->url(), 'shipping-requests/sync')
            && str_contains($req->url(), 'target%40ce.test'));
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

    /** v2 「내 선적묶음」 모니터 — /bundles 값 그대로 표시(상태·미수·환율미입력 경고). */
    public function test_portal_shipping_v2_bundles_monitor(): void
    {
        $this->carErpReadConfig();
        Http::fake([
            '*/api/internal/board/bundles*' => Http::response(['count' => 1, 'data' => [[
                'batch_id' => 'B1', 'ship_status' => 'requested', 'bl_status' => 'none', 'bl_type' => null,
                'shipping_method' => 'RORO', 'buyer' => ['id' => 5, 'name' => 'BuyerZ'],
                'vehicles' => [['vehicle_id' => 1, 'vehicle_number' => 'CAR001']],
                'unpaid_total_krw' => 3000000, 'fx_missing_count' => 1, 'fully_paid' => false, 'unpaid_ratio' => 0.4,
            ]]], 200),
            '*/api/internal/board/shippable*' => Http::response(['count' => 0, 'data' => []], 200),
            '*' => Http::response(['count' => 0, 'data' => []], 200),
        ]);
        $this->actingAs($this->mkUser('sales'));

        Volt::test('portal.index')->call('setTab', 'shipping')
            ->assertSee('BuyerZ')->assertSee('CAR001')
            ->assertSee('요청됨')              // 선적단계 뱃지
            ->assertSee('환율 미입력');        // fx_missing 경고(완납판정 불가)
    }

    /** 선적묶음 상태별 접기/펼치기 그룹 — 상태 헤더별로 묶여 표시(요청됨/완료 등), 카드는 각 그룹 하위. */
    public function test_portal_shipping_bundles_grouped_by_status(): void
    {
        $this->carErpReadConfig();
        Http::fake([
            '*/api/internal/board/bundles*' => Http::response(['count' => 2, 'data' => [
                ['batch_id' => 'B1', 'ship_status' => 'requested', 'shipping_method' => 'RORO',
                    'buyer' => ['id' => 5, 'name' => 'BuyerReq'], 'vehicles' => [['vehicle_id' => 1, 'vehicle_number' => 'CARREQ']]],
                ['batch_id' => 'B2', 'ship_status' => 'done', 'shipping_method' => 'RORO',
                    'buyer' => ['id' => 6, 'name' => 'BuyerDone'], 'vehicles' => [['vehicle_id' => 2, 'vehicle_number' => 'CARDONE']]],
            ]], 200),
            '*/api/internal/board/shippable*' => Http::response(['count' => 0, 'data' => []], 200),
            '*' => Http::response(['count' => 0, 'data' => []], 200),
        ]);
        $this->actingAs($this->mkUser('sales'));

        // 두 상태 그룹 헤더(요청됨/완료) + 각 그룹의 카드가 모두 렌더(접힘은 client-side x-show라 HTML엔 존재).
        Volt::test('portal.index')->call('setTab', 'shipping')
            ->assertSee('요청됨')->assertSee('완료')
            ->assertSee('BuyerReq')->assertSee('CARREQ')
            ->assertSee('BuyerDone')->assertSee('CARDONE');
    }

    /** v2 「선적 계획」 동기화 — desired(requested 묶음에서 차 제거) 전체를 /sync 로 전송. */
    public function test_portal_shipping_v2_sync_sends_full_desired(): void
    {
        $this->carErpReadConfig();
        Http::fake([
            '*/api/internal/board/bundles*' => Http::response(['count' => 1, 'data' => [[
                'batch_id' => 'B1', 'ship_status' => 'requested', 'shipping_method' => 'RORO', 'bl_type' => null,
                'buyer' => ['id' => 5, 'name' => 'BuyerZ'], 'consignee' => ['id' => 9], 'consignees' => [],
                'vehicles' => [['vehicle_id' => 1, 'vehicle_number' => 'CAR001'], ['vehicle_id' => 2, 'vehicle_number' => 'CAR002']],
            ]]], 200),
            '*/api/internal/board/shippable*' => Http::response(['count' => 0, 'data' => []], 200),
            '*/api/internal/board/shipping-requests/sync*' => Http::response(['created' => [], 'updated' => [1], 'cancelled' => [], 'skipped' => [], 'locked' => []], 200),
            '*' => Http::response(['count' => 0, 'data' => []], 200),
        ]);
        $this->actingAs($this->mkUser('sales'));

        // desired 는 requested 묶음(차 2대)으로 시드 → 한 대 빼고 동기화 → vehicle_ids=[1] 전체 전송
        Volt::test('portal.index')->call('setTab', 'shipping')
            ->call('unassignVehicle', 2)
            ->call('syncBundles')->assertHasNoErrors();

        Http::assertSent(fn ($r) => str_contains($r->url(), '/api/internal/board/shipping-requests/sync')
            && str_contains($r->body(), '"buyer_id":5')
            && str_contains($r->body(), '"vehicle_ids":[1]'));   // 뺀 차(2) 제외, 전체 desired
    }

    /** §10 「전자서명 요청」 — 묶음 버튼 → signed_url 발급받아 화면에 노출(바이어 전달용). */
    public function test_portal_requests_signature_and_shows_url(): void
    {
        $this->carErpReadConfig();
        Http::fake([
            '*/api/internal/board/bundles*' => Http::response(['count' => 1, 'data' => [[
                'batch_id' => 'B1', 'ship_status' => 'requested', 'bl_status' => 'none', 'bl_type' => null,
                'shipping_method' => 'RORO', 'buyer' => ['id' => 5, 'name' => 'BuyerZ'],
                'vehicles' => [['vehicle_id' => 1, 'vehicle_number' => 'CAR001']],
            ]]], 200),
            '*/api/internal/board/signing-requests*' => Http::response([
                'signed_url' => 'https://heysellcar.com/sign/tok?expires=1&signature=ab',
                'contract_no' => 'SC2607-00001', 'buyer' => ['id' => 5, 'name' => 'BuyerZ'],
                'currency' => 'USD', 'vehicle_count' => 1, 'status' => 'pending',
                'expires_at' => '2026-07-17T09:00:00+09:00',
            ], 200),
            '*' => Http::response(['count' => 0, 'data' => []], 200),
        ]);
        $this->actingAs($this->mkUser('sales'));

        Volt::test('portal.index')->call('setTab', 'shipping')
            ->call('requestSignature', [1], 'B1')
            ->assertHasNoErrors()
            ->assertSee('SC2607-00001')
            ->assertSee('https://heysellcar.com/sign/tok?expires=1&signature=ab');
    }

    /** §10-2 서명 상태 칩 — signed 폴링 시 묶음 카드에 「서명완료」 녹색 칩 노출. */
    public function test_portal_shows_signed_chip_from_status_poll(): void
    {
        $this->carErpReadConfig();
        Http::fake([
            '*/api/internal/board/bundles*' => Http::response(['count' => 1, 'data' => [[
                'batch_id' => 'B1', 'ship_status' => 'requested', 'bl_status' => 'none', 'bl_type' => null,
                'shipping_method' => 'RORO', 'buyer' => ['id' => 5, 'name' => 'BuyerZ'],
                'vehicles' => [['vehicle_id' => 1, 'vehicle_number' => 'CAR001']],
            ]]], 200),
            '*/api/internal/board/signing-requests*' => Http::response([
                'status' => 'signed', 'contract_no' => 'SC2607-00001', 'signed_at' => '2026-07-10T02:00:00+09:00',
            ], 200),
            '*' => Http::response(['count' => 0, 'data' => []], 200),
        ]);
        $this->actingAs($this->mkUser('sales'));

        Volt::test('portal.index')->call('setTab', 'shipping')
            ->assertSee('서명완료')          // signed 칩
            ->assertSee('SC2607-00001')
            ->assertDontSee('전자서명 요청');   // signed 면 요청 버튼 대신 재요청
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

    public function test_portal_monthly_settlement_buckets_by_paid_at(): void
    {
        // 실지급일(paid_at)이 확정일(confirmed_at)과 다르면 월별은 paid_at 기준으로 갈려야 함.
        // (car-erp 가 엑셀 업로드로 5월/6월 실지급을 paid_at 에 담아 보내는 케이스 — handoff-car-erp-settlement-paid-at.md)
        $this->carErpReadConfig();
        Http::fake([
            '*/api/internal/board/finance*' => Http::response(['unpaid_total_krw' => 0], 200),
            '*/api/internal/board/settlements*' => Http::response(['count' => 2, 'data' => [
                ['vehicle_number' => 'A', 'paid_at' => '2026-05-31', 'confirmed_at' => '2026-06-23', 'actual_payout' => 500000, 'status' => 'paid'],
                ['vehicle_number' => 'B', 'paid_at' => '2026-06-10', 'confirmed_at' => '2026-06-23', 'actual_payout' => 300000, 'status' => 'paid'],
            ]], 200),
            '*' => Http::response(['count' => 0, 'data' => []], 200),
        ]);
        $this->actingAs($this->mkUser('sales'));

        Volt::test('portal.index')
            ->assertSee('2026-05')->assertSee('500,000')   // 확정은 6월이나 실지급 5월 → 5월로
            ->assertSee('2026-06')->assertSee('300,000');
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

    /** v2 미착수 선적 취소 — cancelBundle 이 그 묶음 빼고 전체 desired 재전송(car-erp 자동취소). */
    public function test_portal_shipping_v2_cancel_requested_bundle(): void
    {
        $this->carErpReadConfig();
        Http::fake([
            // batch_id 를 숫자로 — wire:click 은 문자열로 넘기므로 strict 비교면 안 빠지는 버그 회귀 방지
            '*/api/internal/board/bundles*' => Http::response(['count' => 1, 'data' => [[
                'batch_id' => 77, 'ship_status' => 'requested', 'shipping_method' => 'RORO',
                'buyer' => ['id' => 5, 'name' => 'BuyerZ'], 'vehicles' => [['vehicle_id' => 1, 'vehicle_number' => 'CAR001']],
            ]]], 200),
            '*/api/internal/board/shippable*' => Http::response(['count' => 0, 'data' => []], 200),
            '*/api/internal/board/shipping-requests/sync*' => Http::response(['created' => [], 'updated' => [], 'cancelled' => [1], 'skipped' => [], 'locked' => []], 200),
            '*' => Http::response(['count' => 0, 'data' => []], 200),
        ]);
        $this->actingAs($this->mkUser('sales'));

        Volt::test('portal.index')->call('setTab', 'shipping')
            ->call('cancelBundle', '77')->assertHasNoErrors();   // 문자열 인자(blade 와 동일)

        // B1 빠진 전체 desired 전송 → B1만 있었으므로 bundles:[] (car-erp 가 B1 자동취소)
        Http::assertSent(fn ($r) => str_contains($r->url(), '/shipping-requests/sync')
            && str_contains($r->body(), '"bundles":[]'));
    }

    /** v2 B/L요청 무름 — bl_status='requested' 묶음에서 bl-cancel 전송(서명). */
    public function test_portal_shipping_v2_bl_cancel(): void
    {
        $this->carErpReadConfig();
        Http::fake([
            '*/api/internal/board/bundles/B1/bl-cancel*' => Http::response(['ok' => true, 'bl_status' => 'none'], 200),
            '*/api/internal/board/bundles*' => Http::response(['count' => 1, 'data' => [[
                'batch_id' => 'B1', 'ship_status' => 'requested', 'bl_status' => 'requested', 'bl_type' => 'original',
                'shipping_method' => 'RORO', 'buyer' => ['id' => 5, 'name' => 'BuyerZ'],
                'vehicles' => [['vehicle_id' => 1, 'vehicle_number' => 'CAR001']],
            ]]], 200),
            '*' => Http::response(['count' => 0, 'data' => []], 200),
        ]);
        $this->actingAs($this->mkUser('sales'));

        Volt::test('portal.index')->call('setTab', 'shipping')
            ->call('cancelBl', 'B1')->assertHasNoErrors();

        Http::assertSent(fn ($r) => str_contains($r->url(), '/api/internal/board/bundles/B1/bl-cancel')
            && str_starts_with($r->header('X-Board-Signature')[0], 'sha256='));
    }

    /** v2 B/L 무름 — 이미 발급(409)이면 "발급완료 무름 불가" 안내. */
    public function test_portal_shipping_v2_bl_cancel_already_issued(): void
    {
        $this->carErpReadConfig();
        Http::fake([
            '*/api/internal/board/bundles/B1/bl-cancel*' => Http::response(['ok' => false, 'reason' => 'already_issued'], 409),
            '*' => Http::response(['count' => 0, 'data' => []], 200),
        ]);
        $this->actingAs($this->mkUser('sales'));

        Volt::test('portal.index')->call('setTab', 'shipping')
            ->call('cancelBl', 'B1')
            ->assertSee('발급');   // "관리가 이미 B/L을 발급해 무를 수 없습니다"
    }

    /** v2 안전가드 — 기존 묶음에 buyer_id 없으면(car-erp /bundles 가 buyer 문자열만) sync 차단(전체 자동취소 방지). */
    public function test_portal_shipping_v2_blocks_sync_when_bundle_missing_buyer_id(): void
    {
        $this->carErpReadConfig();
        Http::fake([
            '*/api/internal/board/bundles*' => Http::response(['count' => 1, 'data' => [[
                'batch_id' => 'B1', 'ship_status' => 'requested', 'shipping_method' => 'RORO',
                'buyer' => 'BuyerName',   // 문자열(buyer_id 없음) = car-erp 현재 형태 → 재전송 불가
                'vehicles' => [['vehicle_id' => 1, 'vehicle_number' => 'CAR001']],
            ]]], 200),
            '*/api/internal/board/shippable*' => Http::response(['count' => 0, 'data' => []], 200),
            '*/api/internal/board/shipping-requests/sync*' => Http::response(['created' => []], 200),
            '*' => Http::response(['count' => 0, 'data' => []], 200),
        ]);
        $this->actingAs($this->mkUser('sales'));

        Volt::test('portal.index')->call('setTab', 'shipping')->call('syncBundles');

        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/shipping-requests/sync'));
    }

    /** v2 안전가드 — /bundles 조회 degrade(5xx) 시 동기화 차단(빈 desired 전송 → 전체 자동취소 방지). */
    public function test_portal_shipping_v2_sync_blocked_when_bundles_degraded(): void
    {
        $this->carErpReadConfig();
        Http::fake([
            '*/api/internal/board/bundles*' => Http::response(['error' => 'boom'], 500),   // 조회 실패
            '*/api/internal/board/shippable*' => Http::response(['count' => 0, 'data' => []], 200),
            '*/api/internal/board/shipping-requests/sync*' => Http::response(['created' => []], 200),
            '*' => Http::response(['count' => 0, 'data' => []], 200),
        ]);
        $this->actingAs($this->mkUser('sales'));

        Volt::test('portal.index')->call('setTab', 'shipping')->call('syncBundles')
            ->assertSet('syncResult', null);

        // 절대 sync 전송 안 됨 — degrade 시 전체취소 방지.
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/shipping-requests/sync'));
    }

    /** v2 「선적 계획」 — shippable pool 의 새 차를 새 묶음에 담고(빈 묶음=그 차 바이어 채택) 동기화. */
    public function test_portal_shipping_v2_plan_pool_assign_and_sync(): void
    {
        $this->carErpReadConfig();
        Http::fake([
            '*/api/internal/board/bundles*' => Http::response(['count' => 0, 'data' => []], 200),   // 기존 묶음 없음
            '*/api/internal/board/shippable*' => Http::response(['count' => 1, 'data' => [
                ['vehicle_id' => 10, 'vehicle_number' => '11가1111', 'buyer' => ['id' => 2, 'name' => 'BuyerX'], 'consignees' => [['id' => 3, 'name' => 'ConsX']]],
            ]], 200),
            '*/api/internal/board/shipping-requests/sync*' => Http::response(['created' => [10], 'updated' => [], 'cancelled' => [], 'skipped' => [], 'locked' => []], 200),
            '*' => Http::response(['count' => 0, 'data' => []], 200),
        ]);
        $this->actingAs($this->mkUser('sales'));

        $c = Volt::test('portal.index')->call('setTab', 'shipping')->call('setShipSubtab', 'plan')
            ->assertSee('BuyerX')->assertSee('11가1111');   // 바이어별 펼침 — BuyerX 빈 묶음 자동 시드 + 차 체크박스

        $key = $c->get('desired')[0]['key'];               // 자동 시드된 BuyerX 묶음(shippable 전용 바이어)
        $c->call('assignVehicle', $key, 10)                 // 체크 = 묶음에 담기
            ->call('syncBundles')->assertHasNoErrors();

        Http::assertSent(fn ($req) => str_contains($req->url(), '/api/internal/board/shipping-requests/sync')
            && str_contains($req->body(), '"buyer_id":2')
            && str_contains($req->body(), '"vehicle_ids":[10]'));
    }

    /**
     * 선적 계획에 **포워딩사 + 컨테이너 운임비**를 실어 보낸다(2026-08-12).
     * 운임비는 CONTAINER 에서만 — RORO 로 보내면 ERP 가 조용히 버리므로 board 가 먼저 뺀다.
     */
    public function test_portal_plan_sends_forwarder_and_container_freight(): void
    {
        $this->carErpReadConfig();
        Http::fake([
            '*/api/internal/board/bundles*' => Http::response(['count' => 0, 'data' => []], 200),
            '*/api/internal/board/forwarding-companies*' => Http::response(['count' => 1, 'data' => [['id' => 7, 'name' => '한진']]], 200),
            '*/api/internal/board/shippable*' => Http::response(['count' => 1, 'data' => [
                ['vehicle_id' => 10, 'vehicle_number' => '11가1111', 'buyer' => ['id' => 2, 'name' => 'BuyerX'], 'consignees' => [['id' => 3, 'name' => 'ConsX']]],
            ]], 200),
            '*/api/internal/board/shipping-requests/sync*' => Http::response(['created' => [10], 'updated' => [], 'cancelled' => [], 'skipped' => [], 'locked' => []], 200),
            '*' => Http::response(['count' => 0, 'data' => []], 200),
        ]);
        $this->actingAs($this->mkUser('sales'));

        $c = Volt::test('portal.index')->call('setTab', 'shipping')->call('setShipSubtab', 'plan')
            ->assertSee('한진');   // 명부가 오면 포워딩사 드롭다운이 뜬다

        $key = $c->get('desired')[0]['key'];
        $c->call('assignVehicle', $key, 10)
            ->call('setBundleField', $key, 'forwarding_company_id', '7')
            ->call('setBundleField', $key, 'transport_fee_usd_total', '1,000')   // 콤마 입력도 정규화
            ->call('setBundleField', $key, 'shipping_method', 'CONTAINER')
            ->call('syncBundles')->assertHasNoErrors();

        Http::assertSent(function ($req) {
            if (! str_contains($req->url(), '/shipping-requests/sync')) {
                return false;
            }
            $b = json_decode($req->body(), true);
            $this->assertSame(7, $b['bundles'][0]['forwarding_company_id']);
            $this->assertSame(1000, $b['bundles'][0]['transport_fee_usd_total']);

            return true;
        });
    }

    /**
     * 판매계약서·프로포마·전자서명은 **선적 계획에서도** 된다(Jin 2026-08-18).
     * 차량 id 기반이라 묶음(sync) 없이 발급되고, 차를 담아야 대상이 생기므로 빈 묶음엔 안 그린다.
     * 선적 4종(roro_ · container_ 접두)은 실제 선적 단계 서류라 계획에 두지 않는다.
     */
    public function test_portal_plan_offers_sales_contract_and_signature(): void
    {
        $this->carErpReadConfig();
        Http::fake([
            '*/api/internal/board/bundles*' => Http::response(['count' => 0, 'data' => []], 200),
            '*/api/internal/board/shippable*' => Http::response(['count' => 1, 'data' => [
                ['vehicle_id' => 10, 'vehicle_number' => '11가1111', 'buyer' => ['id' => 2, 'name' => 'BuyerX'], 'consignees' => []],
            ]], 200),
            '*' => Http::response(['count' => 0, 'data' => []], 200),
        ]);
        $this->actingAs($this->mkUser('sales'));

        $c = Volt::test('portal.index')->call('setTab', 'shipping')->call('setShipSubtab', 'plan');

        // 차를 담기 전 = 대상이 없으니 안 그린다.
        $c->assertDontSee(__('portal.docs_sales_contract'));

        $key = $c->get('desired')[0]['key'];
        $c->call('assignVehicle', $key, 10)
            ->assertSee(__('portal.docs_sales_contract'))
            ->assertSee(__('portal.docs_proforma_invoice'))
            ->assertSee(__('portal.sign_request_btn'))
            ->assertDontSee(__('portal.docs_roro_contract'));   // 선적 4종은 묶음 화면에만
    }

    /**
     * 422 사유를 **원문으로 갈라** 안내한다(ERP 2026-08-18 가드 추가).
     * 전부 "동일 바이어"로 뭉뚱그리면 영업이 엉뚱한 곳을 고친다 — 실제로 sales_contract 403 을
     * "동일 바이어"로 안내해 원인을 잘못 짚게 만든 전례가 있다.
     */
    public function test_portal_docs_422_reasons_are_distinguished(): void
    {
        $this->carErpReadConfig();
        $this->actingAs($this->mkUser('sales'));

        $cases = [
            ['No buyer: 11가1111', 'flash_docs_no_buyer'],
            ['No sale price: 22나2222', 'flash_docs_no_sale_price'],
            ['Mixed buyers', 'flash_docs_homogeneous_required'],
        ];
        // ⚠️ Http::fake 를 루프마다 다시 부르면 스텁이 **누적**돼 첫 것이 계속 이긴다 → sequence 로 준다.
        $seq = Http::sequence();
        foreach ($cases as [$erpMsg, $_]) {
            $seq->push($erpMsg, 422);
        }
        // 포털 마운트가 여러 엔드포인트를 먼저 부르므로 **서류 경로만** sequence 로 준다.
        Http::fake([
            '*/api/internal/board/documents/*' => $seq,
            '*' => Http::response(['count' => 0, 'data' => []], 200),
        ]);

        foreach ($cases as [$erpMsg, $langKey]) {
            $note = Volt::test('portal.index')
                ->call('downloadDocs', [10], 'RORO', 'sales_contract')
                ->get('shipNote');

            $this->assertStringContainsString(
                mb_substr(__('portal.'.$langKey, ['detail' => $erpMsg]), 0, 12),
                (string) $note,
                "422 '{$erpMsg}' 가 {$langKey} 로 안내돼야 한다. 실제 = ".(string) $note,
            );
        }
    }

    /** RORO 묶음엔 운임비를 안 싣는다 — ERP 가 조용히 버리므로(에러가 아니라) board 가 먼저 뺀다. */
    public function test_portal_plan_omits_freight_for_roro(): void
    {
        $this->carErpReadConfig();
        Http::fake([
            '*/api/internal/board/bundles*' => Http::response(['count' => 0, 'data' => []], 200),
            '*/api/internal/board/shippable*' => Http::response(['count' => 1, 'data' => [
                ['vehicle_id' => 10, 'vehicle_number' => '11가1111', 'buyer' => ['id' => 2, 'name' => 'BuyerX'], 'consignees' => []],
            ]], 200),
            '*/api/internal/board/shipping-requests/sync*' => Http::response(['created' => [10], 'updated' => [], 'cancelled' => [], 'skipped' => [], 'locked' => []], 200),
            '*' => Http::response(['count' => 0, 'data' => []], 200),
        ]);
        $this->actingAs($this->mkUser('sales'));

        $c = Volt::test('portal.index')->call('setTab', 'shipping')->call('setShipSubtab', 'plan');
        $key = $c->get('desired')[0]['key'];
        $c->call('assignVehicle', $key, 10)
            ->call('setBundleField', $key, 'transport_fee_usd_total', '900')
            ->call('setBundleField', $key, 'shipping_method', 'RORO')
            ->call('syncBundles');

        Http::assertSent(function ($req) {
            if (! str_contains($req->url(), '/shipping-requests/sync')) {
                return false;
            }
            $b = json_decode($req->body(), true);
            $this->assertNull($b['bundles'][0]['transport_fee_usd_total']);

            return true;
        });
    }

    /**
     * 화면을 다시 열면 ERP 가 돌려준 포워딩사·운임비가 **편집상태로 복원**돼야 한다.
     * 안 그러면 다음 sync 에서 빈 값이 나가 방금 지정한 걸 board 가 스스로 지운다.
     */
    public function test_portal_plan_restores_forwarder_and_freight_from_bundles(): void
    {
        $this->carErpReadConfig();
        Http::fake([
            '*/api/internal/board/bundles*' => Http::response(['count' => 1, 'data' => [[
                'batch_id' => 'b1', 'ship_status' => 'requested',
                'buyer' => ['id' => 2, 'name' => 'BuyerX'], 'consignee' => ['id' => 3, 'name' => 'ConsX'],
                'shipping_method' => 'CONTAINER', 'bl_type' => null,
                'forwarding_company' => ['id' => 7, 'name' => '한진'],
                'transport_fee_usd_total' => 1500,
                'vehicles' => [['vehicle_id' => 10, 'vehicle_number' => '11가1111']],
            ]]], 200),
            '*' => Http::response(['count' => 0, 'data' => []], 200),
        ]);
        $this->actingAs($this->mkUser('sales'));

        $c = Volt::test('portal.index')->call('setTab', 'shipping')->call('setShipSubtab', 'plan');
        $bd = $c->get('desired')[0];

        $this->assertSame(7, (int) $bd['forwarding_company_id']);
        $this->assertSame(1500, (int) $bd['transport_fee_usd_total']);
    }

    /**
     * ⚠️ `unpaid_krw` null = **환율 미입력이라 판정 불가**이지 완납이 아니다.
     * 조용히 넘기면 영업이 "돈 다 들어온 차"로 읽고 묶는다 — 가짜 완납이 가장 비싼 오독이다.
     */
    public function test_portal_plan_marks_unpaid_and_never_fakes_paid_without_fx(): void
    {
        $this->carErpReadConfig();
        Http::fake([
            '*/api/internal/board/bundles*' => Http::response(['count' => 0, 'data' => []], 200),
            '*/api/internal/board/shippable*' => Http::response(['count' => 3, 'data' => [
                ['vehicle_id' => 10, 'vehicle_number' => '11가1111', 'buyer' => ['id' => 2, 'name' => 'BuyerX'], 'consignees' => [],
                    'unpaid_krw' => 5000000, 'unpaid_ratio' => 0.5, 'fully_paid' => false],
                ['vehicle_id' => 11, 'vehicle_number' => '22나2222', 'buyer' => ['id' => 2, 'name' => 'BuyerX'], 'consignees' => [],
                    'unpaid_krw' => null, 'unpaid_ratio' => null, 'fully_paid' => false],   // 환율 미입력
                ['vehicle_id' => 12, 'vehicle_number' => '33다3333', 'buyer' => ['id' => 2, 'name' => 'BuyerX'], 'consignees' => [],
                    'unpaid_krw' => 0, 'unpaid_ratio' => 0, 'fully_paid' => true],
            ]], 200),
            '*' => Http::response(['count' => 0, 'data' => []], 200),
        ]);
        $this->actingAs($this->mkUser('sales'));

        Volt::test('portal.index')->call('setTab', 'shipping')->call('setShipSubtab', 'plan')
            ->assertSee(__('portal.plan_unpaid'))          // 미수 차 = 표시됨
            ->assertSee(__('portal.plan_fx_missing'))      // 환율 미입력 = 완납으로 안 넘어감
            ->assertSee('33다3333');                        // 완납 차는 칩 없이 조용히
    }

    /**
     * 바이어 없는 후보는 **묶을 그릇이 없어** 화면에서 통째로 빠진다(묶음=바이어별).
     * 후보가 미완납까지 넓어지면서 생길 수 있는 경우라, 조용히 사라지면 "왜 안 뜨지"가 된다.
     */
    public function test_portal_plan_tells_about_vehicles_without_buyer(): void
    {
        $this->carErpReadConfig();
        Http::fake([
            '*/api/internal/board/bundles*' => Http::response(['count' => 0, 'data' => []], 200),
            '*/api/internal/board/shippable*' => Http::response(['count' => 2, 'data' => [
                ['vehicle_id' => 10, 'vehicle_number' => '11가1111', 'buyer' => ['id' => 2, 'name' => 'BuyerX'], 'consignees' => []],
                ['vehicle_id' => 11, 'vehicle_number' => '99무9999', 'buyer' => null, 'consignees' => []],
            ]], 200),
            '*' => Http::response(['count' => 0, 'data' => []], 200),
        ]);
        $this->actingAs($this->mkUser('sales'));

        Volt::test('portal.index')->call('setTab', 'shipping')->call('setShipSubtab', 'plan')
            ->assertSee(__('portal.plan_no_buyer_cars', ['count' => 1]));
    }

    // ─────────────────────── 내 설정 / 기능설정 ───────────────────────

    public function test_personal_settings_pages_load(): void
    {
        $u = $this->mkUser('manager');
        // 프로필 로드 + 계정 자가삭제 버튼은 숨김(board는 super가 계정 관리)
        $this->actingAs($u)->get('/settings/profile')->assertOk()->assertSee('프로필', false)->assertDontSee('계정 삭제', false);
        $this->actingAs($u)->get('/settings/password')->assertOk()->assertSee('비밀번호', false);
        $this->actingAs($u)->get('/settings/appearance')->assertOk()->assertSee('화면 설정', false);
    }

    public function test_feature_settings_is_super_only(): void
    {
        $this->actingAs($this->mkUser('manager'))->get('/admin/settings')->assertForbidden();
        $this->actingAs($this->mkUser('manager', null, 'super'))->get('/admin/settings')->assertOk();
    }

    public function test_brand_setting_drives_sidebar_and_login(): void
    {
        $this->actingAs($this->mkUser('manager', null, 'super'));

        Volt::test('admin.settings')
            ->set('sidebarBrand', '테스트브랜드')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('테스트브랜드', Setting::get('sidebar_brand'));

        // 사이드바(인증) + 로그인 화면(게스트) 둘 다 같은 값 반영
        $this->get('/settings/profile')->assertSee('테스트브랜드', false);
        auth()->logout();
        $this->get('/login')->assertSee('테스트브랜드', false);
    }

    public function test_brand_save_rejects_too_long(): void
    {
        $this->actingAs($this->mkUser('manager', null, 'super'));

        Volt::test('admin.settings')
            ->set('sidebarBrand', str_repeat('가', 13))
            ->call('save')
            ->assertHasErrors(['sidebarBrand']);
    }

    /** 알림톡 발신기 — 미설정/off=skipped, 설정+enabled=발송(페이로드·헤더·본문 치환), 실패응답=failed. 전부 AlimtalkLog 기록. */
    public function test_alimtalk_service_sends_and_logs(): void
    {
        // 미설정 → skipped (게이트 off)
        $log = BizmAlimtalkService::active()->send('board_region_inspection', '010-0000-0000',
            ['지역' => '경기', '건수' => '1', '차량목록' => '12가3456']);
        $this->assertSame('skipped', $log->status);
        $this->assertSame('disabled_or_unconfigured', $log->error);

        // 계정 + enabled + tmplId 설정
        Setting::create(['key' => 'alimtalk_userid', 'value' => 'uid', 'type' => 'string']);
        Setting::create(['key' => 'alimtalk_profile', 'value' => 'prof', 'type' => 'string']);
        Setting::create(['key' => 'alimtalk_enabled', 'value' => '1', 'type' => 'boolean']);
        Setting::create(['key' => 'alimtalk_tmpl_board_region_inspection', 'value' => 'TMPL1', 'type' => 'string']);

        // 순차 응답 — 1차 success, 2차 fail (같은 패턴 fake 2회는 병합돼 첫 stub 이 계속 매칭되므로 sequence 사용).
        Http::fakeSequence('*bizmsg.kr*')
            ->push([['code' => 'success', 'data' => ['msgid' => 'WEB1'], 'message' => 'K000']], 200)
            ->push([['code' => 'fail', 'message' => 'K108', 'data' => ['msgid' => 'X']]], 200);
        $log = BizmAlimtalkService::active()->send('board_region_inspection', '010-1234-5678',
            ['지역' => '경기 화성', '건수' => '2', '차량목록' => "12가3456\n34나5678"], ['region' => '경기 화성']);
        $this->assertSame('sent', $log->status);
        $this->assertSame('WEB1', $log->msgid);
        $this->assertSame('경기 화성', $log->region);

        Http::assertSent(function ($r) {
            $item = $r->data()[0] ?? [];

            return str_contains($r->url(), 'bizmsg.kr')
                && $r->hasHeader('userid', 'uid')
                && ($item['tmplId'] ?? '') === 'TMPL1'
                && ($item['profile'] ?? '') === 'prof'
                && str_contains($item['msg'] ?? '', '경기 화성')
                && str_contains($item['msg'] ?? '', '12가3456');
        });

        // 실패 응답(code != success 인데 msgid 는 옴) → failed (오기록 방지) — sequence 2차
        $log = BizmAlimtalkService::active()->send('board_region_inspection', '01011112222',
            ['지역' => 'x', '건수' => '1', '차량목록' => 'a']);
        $this->assertSame('failed', $log->status);

        // no_phone → skipped
        $log = BizmAlimtalkService::active()->send('board_region_inspection', '', ['지역' => 'x']);
        $this->assertSame('skipped', $log->status);
        $this->assertSame('no_phone', $log->error);
    }

    /** super 가 알림톡 설정 저장 — 계정·tmplId·스케줄 시각·토글 persist. */
    public function test_super_saves_alimtalk_settings(): void
    {
        $this->actingAs($this->mkUser('manager', null, 'super'));

        Volt::test('admin.settings')
            ->set('alimtalkUserid', 'uid')->set('alimtalkProfile', 'prof')
            ->set('alimtalkEnabled', true)->set('alimtalkTmpl', 'TMPL1')
            ->set('alimtalkToggle', true)->set('alimtalkScheduleTime', '08:00')
            ->call('saveAlimtalk')->assertHasNoErrors();

        $this->assertSame('uid', Setting::get('alimtalk_userid'));
        $this->assertSame('prof', Setting::get('alimtalk_profile'));
        $this->assertTrue((bool) Setting::get('alimtalk_enabled'));
        $this->assertSame('TMPL1', Setting::get('alimtalk_tmpl_board_region_inspection'));
        $this->assertSame('08:00', Setting::get('alimtalk_region_schedule_time'));

        // 잘못된 시각 형식 거부
        Volt::test('admin.settings')->set('alimtalkScheduleTime', '25:99')
            ->call('saveAlimtalk')->assertHasErrors(['alimtalkScheduleTime']);
    }

    /** /users 에서 phone 저장·수정 (알림톡 수신번호). */
    public function test_user_phone_is_saved(): void
    {
        $this->actingAs($this->mkUser('manager', null, 'super'));

        Volt::test('users.index')
            ->call('openCreate')
            ->set('name', '검차원A')->set('email', 'insp@board.test')
            ->set('phone', '010-9999-8888')->set('role', 'inspection')
            ->set('password', 'password')
            ->call('save')->assertHasNoErrors();

        $this->assertSame('010-9999-8888', User::where('email', 'insp@board.test')->value('phone'));
    }

    /** 알림톡 발송 가능 상태(계정+enabled+tmplId 2종) 세팅. */
    private function alimtalkOn(): void
    {
        Setting::create(['key' => 'alimtalk_userid', 'value' => 'uid', 'type' => 'string']);
        Setting::create(['key' => 'alimtalk_profile', 'value' => 'prof', 'type' => 'string']);
        Setting::create(['key' => 'alimtalk_enabled', 'value' => '1', 'type' => 'boolean']);
        Setting::create(['key' => 'alimtalk_tmpl_board_region_inspection', 'value' => 'T1', 'type' => 'string']);
        Setting::create(['key' => 'alimtalk_tmpl_board_forward_ready', 'value' => 'T2', 'type' => 'string']);
    }

    /** §Slice2 A — 지역 로스터로 digest 발송 + 차량당 1회 dedup + off 면 stamp 안 함(활성화 안전). */
    public function test_region_inspection_notifier_roster_dedup_and_off(): void
    {
        $insp = $this->mkUser('inspection');
        $insp->update(['phone' => '01011112222', 'region' => '경기 화성']);
        $l1 = $this->mkListing($this->mkUser('sales'), ['status' => 'draft', 'region' => '경기 화성', 'vehicle_number' => '11가1111']);
        $this->mkListing($this->mkUser('sales'), ['status' => 'draft', 'region' => '경기 화성', 'vehicle_number' => '22나2222']);
        $date = now()->addDay()->toDateString();

        // off(미설정) → 발송기 skipped, stamp 안 됨(활성화 후 누락 방지). ⚠️실운영은 커맨드가 매 실행 fresh resolve.
        $r = app(RegionInspectionNotifier::class)->run($date);
        $this->assertSame(0, $r['sent']);
        $this->assertNull($l1->fresh()->region_notified_at);

        // on → 로스터 1명에게 지역 digest(두 차량 목록 포함), stamp. (설정 후 fresh 인스턴스)
        $this->alimtalkOn();
        Http::fake(['*bizmsg.kr*' => Http::response([['code' => 'success', 'data' => ['msgid' => 'M']]], 200)]);
        $r = app(RegionInspectionNotifier::class)->run($date);
        $this->assertSame(1, $r['sent']);
        $this->assertSame(1, $r['regions']);
        $this->assertNotNull($l1->fresh()->region_notified_at);
        Http::assertSent(function ($req) {
            $msg = $req->data()[0]['msg'] ?? '';

            return str_contains($req->url(), 'bizmsg.kr') && str_contains($msg, '경기 화성')
                && str_contains($msg, '11가1111') && str_contains($msg, '22나2222');
        });

        // dedup — 재실행 시 이미 stamp 라 새 발송 없음
        $r2 = app(RegionInspectionNotifier::class)->run($date);
        $this->assertSame(0, $r2['sent']);
    }

    /** §Slice2 A — per-date 배정(override)이 지역 고정 로스터를 이긴다. */
    public function test_region_inspection_override_beats_roster(): void
    {
        $this->alimtalkOn();
        Http::fake(['*bizmsg.kr*' => Http::response([['code' => 'success', 'data' => ['msgid' => 'M']]], 200)]);
        $roster = $this->mkUser('inspection');
        $roster->update(['phone' => '01011110000', 'region' => '인천']);
        $assigned = $this->mkUser('inspection');
        $assigned->update(['phone' => '01022220000', 'region' => '서울']);   // 본인 지역은 서울이지만
        $date = now()->addDay()->toDateString();
        InspectionAssignment::create(['date' => $date, 'region' => '인천', 'user_id' => $assigned->id]);
        $this->mkListing($this->mkUser('sales'), ['status' => 'draft', 'region' => '인천', 'vehicle_number' => '33다3333']);

        $r = app(RegionInspectionNotifier::class)->run($date);
        $this->assertSame(1, $r['sent']);
        Http::assertSent(fn ($req) => ($req->data()[0]['phn'] ?? '') === '01022220000');   // 배정자
        Http::assertNotSent(fn ($req) => ($req->data()[0]['phn'] ?? '') === '01011110000'); // 로스터 아님
    }

    /** §Slice2 B — ssancar 자동전이 시 그 매물 작성 영업에게 전달대기 알림톡. */
    public function test_auto_forward_notifies_sales_rep(): void
    {
        $this->ssancarMediaConfig();
        $this->alimtalkOn();
        Http::fake([
            '*api_car_media.php*' => Http::response($this->ssancarResp(1, 0, 0), 200),
            '*bizmsg.kr*' => Http::response([['code' => 'success', 'data' => ['msgid' => 'M']]], 200),
        ]);
        $sales = $this->mkUser('sales');
        $sales->update(['phone' => '01099998888']);
        $l = $this->mkListing($sales, ['status' => 'draft', 'vehicle_number' => '44라4444']);

        $this->artisan('board:poll-ssancar-media')->assertExitCode(0);

        $this->assertSame('inspected', $l->fresh()->status);
        Http::assertSent(function ($req) {
            $item = $req->data()[0] ?? [];

            return str_contains($req->url(), 'bizmsg.kr') && ($item['phn'] ?? '') === '01099998888'
                && str_contains($item['msg'] ?? '', '44라4444') && ($item['tmplId'] ?? '') === 'T2';
        });
    }

    /** §Slice2 — /users 지역은 검차원만 저장(다른 role 은 null). */
    public function test_user_region_saved_for_inspection_only(): void
    {
        $this->actingAs($this->mkUser('manager', null, 'super'));

        Volt::test('users.index')->call('openCreate')
            ->set('name', '검차원')->set('email', 'r@board.test')->set('role', 'inspection')
            ->set('region', '경기 화성')->set('password', 'password')
            ->call('save')->assertHasNoErrors();
        // 입력이 "경기 화성" 이어도 정식 라벨로 저장 — listings.region 과 표기가 같아야 조인된다.
        $this->assertSame('경기 화성시', User::where('email', 'r@board.test')->value('region'));

        Volt::test('users.index')->call('openCreate')
            ->set('name', '영업')->set('email', 's@board.test')->set('role', 'sales')
            ->set('region', '경기 화성')->set('password', 'password')
            ->call('save')->assertHasNoErrors();
        $this->assertNull(User::where('email', 's@board.test')->value('region'));   // 검차 아니면 미저장
    }

    /** 배포 순간 settings 테이블이 아직 없어도 로그인 화면이 500 나지 않고 기본 브랜드로 degrade. */
    public function test_login_survives_missing_settings_table(): void
    {
        Schema::drop('settings');

        $this->get('/login')->assertOk()->assertSee('HeymanBoard', false);
    }

    public function test_sidebar_work_guide_uses_public_notion_url(): void
    {
        $this->actingAs($this->mkUser('manager', null, 'super'))
            ->get('/manage')
            ->assertOk()
            ->assertSee('https://dashing-stick-008.notion.site/37345d82bd838108a418c76a210f1854', false)
            ->assertDontSee('https://app.notion.com/p/37345d82bd838108a418c76a210f1854', false);
    }

    // ─────────────────────── i18n Phase 0 (한글/영어) ───────────────────────

    private function enableEnglish(): void
    {
        Setting::updateOrCreate(['key' => 'locale_en_enabled'], ['value' => '1', 'type' => 'boolean']);
    }

    public function test_locale_feature_toggle_persists(): void
    {
        $this->actingAs($this->mkUser('manager', null, 'super'));

        Volt::test('admin.settings')->set('localeEnEnabled', true);
        $this->assertTrue((bool) Setting::get('locale_en_enabled'));

        Volt::test('admin.settings')->set('localeEnEnabled', false);
        $this->assertFalse((bool) Setting::get('locale_en_enabled'));
    }

    public function test_user_switches_to_english_when_enabled(): void
    {
        $this->enableEnglish();
        $u = $this->mkUser('manager', null, 'super');

        $this->actingAs($u)->post('/locale', ['locale' => 'en'])->assertRedirect();
        $this->assertSame('en', $u->fresh()->locale);

        // 영어 chrome 렌더 (사이드바 메뉴/브레드크럼 영어)
        $this->actingAs($u->fresh())->get('/admin/settings')
            ->assertSee('Feature Settings', false)
            ->assertSee('Audit Log', false)
            ->assertDontSee('감사 로그', false);
    }

    public function test_english_is_gated_by_feature_toggle(): void
    {
        // 영어 비활성 상태에서 en 시도 → ko 강제 저장
        $u = $this->mkUser('manager');
        $this->actingAs($u)->post('/locale', ['locale' => 'en']);
        $this->assertSame('ko', $u->fresh()->locale);
    }

    public function test_middleware_forces_ko_when_feature_off(): void
    {
        // 사용자 locale 이 en 이라도 기능설정이 꺼져 있으면 미들웨어가 ko 적용
        $u = $this->mkUser('manager', null, 'super');
        $u->update(['locale' => 'en']);

        $this->actingAs($u->fresh())->get('/admin/settings')
            ->assertSee('기능설정', false)
            ->assertDontSee('Feature Settings', false);
    }

    public function test_lang_switch_shown_only_when_enabled(): void
    {
        $u = $this->mkUser('manager', null, 'super');

        $this->actingAs($u)->get('/admin/settings')->assertDontSee('name="locale"', false);

        $this->enableEnglish();
        $this->actingAs($u)->get('/admin/settings')->assertSee('name="locale"', false);
    }

    /** ko 로케일에서 검증 메시지가 raw 키가 아니라 실제 문장으로 렌더되는지(영어 폴백). 리허설 등록폼 직격. */
    public function test_validation_messages_are_not_raw_keys_in_ko(): void
    {
        app()->setLocale('ko');

        $this->assertNotSame('validation.required', __('validation.required'));
        $this->assertStringNotContainsString('validation.', __('validation.max.string', ['attribute' => 'X', 'max' => '12']));
    }

    /** 8개 업무화면이 영어 로케일에서 깨지지 않고 렌더되는지(번역 누락·blade 에러 잡음). super=전 화면 접근. */
    public function test_all_business_screens_render_in_english(): void
    {
        $this->enableEnglish();
        $u = $this->mkUser('manager', null, 'super');
        $u->update(['locale' => 'en']);
        $this->actingAs($u->fresh());

        foreach (['/listings', '/verdicts', '/portal', '/inspection', '/auction', '/manage', '/users', '/audit'] as $url) {
            $this->get($url)->assertOk();
        }

        // 영어 chrome 실제 적용 확인(샘플)
        $this->get('/manage')->assertSee('Feature Settings', false)->assertSee('Audit Log', false);
    }

    // ─────────────────────── 지역명 정합성 ───────────────────────

    /** 크롤링 축약형·표기 변형이 전부 정식 라벨 하나로 모여야 세 테이블이 조인된다. */
    public function test_region_canonicalizes_variants(): void
    {
        $this->assertSame('경기 안산시', Region::canonical('안산'));            // 축약형 복원
        $this->assertSame('경기 안산시', Region::canonical('경기 안산시 단원구'));  // 주소 → 시 단위
        $this->assertSame('경남 창원시', Region::canonical('경상남도 창원시'));   // 도 정식표기
        $this->assertSame('서울특별시', Region::canonical('서울'));
        $this->assertNull(Region::canonical('  '));
    }

    /** ⚠️ 축약형으로 통일하면 안 되는 이유 — 광주광역시와 경기 광주시가 같은 키로 뭉개진다. */
    public function test_region_keeps_distinct_gwangju(): void
    {
        $this->assertSame('광주광역시', Region::canonical('광주광역시'));
        $this->assertSame('경기 광주시', Region::canonical('경기 광주시'));
        $this->assertTrue(Region::isAmbiguous('광주'));    // 도 없는 "광주" 는 사람이 정해야
        $this->assertTrue(Region::isAmbiguous('고성'));    // 강원·경남 둘 다 존재
        $this->assertSame('고성', Region::canonical('고성'));   // 추측하지 않고 원본 유지
    }

    /** 정식 목록은 그대로여야 한다(멱등) — 백필을 여러 번 돌려도 안전. */
    public function test_region_canonical_is_idempotent(): void
    {
        foreach (config('board.regions') as $label) {
            $this->assertSame($label, Region::canonical($label));
        }
    }

    /** 입력 화면이 5곳이라 모델에서 정규화한다 — 어느 경로로 들어와도 같은 표기. */
    public function test_models_normalize_region_on_save(): void
    {
        $sales = $this->mkUser('sales');

        $listing = $this->mkListing($sales, ['region' => '안산']);
        $this->assertSame('경기 안산시', $listing->fresh()->region);

        $inspector = $this->mkUser('inspection');
        $inspector->update(['region' => '안산시']);
        $this->assertSame('경기 안산시', $inspector->fresh()->region);

        $a = InspectionAssignment::create(['date' => now()->toDateString(), 'region' => '안산', 'user_id' => $inspector->id]);
        $this->assertSame('경기 안산시', $a->fresh()->region);
    }

    /** 사용자관리에 지역만 넣으면 그날 배정 없이도 검차 화면에 그 지역 차량이 뜬다. */
    public function test_inspector_sees_region_from_user_roster_without_assignment(): void
    {
        $sales = $this->mkUser('sales');
        $this->mkListing($sales, ['status' => 'draft', 'region' => '경기 수원시']);

        $inspector = $this->mkUser('inspection');
        $inspector->update(['region' => '경기 수원시']);

        $this->actingAs($inspector->fresh());
        Volt::test('inspection.index')->assertSee('경기 수원시');
    }

    /** 그날 배정이 있으면 그것만(덮어쓰기) — 파견 나간 날 원래 지역이 겹쳐 뜨면 업무량이 두 배가 된다. */
    public function test_per_date_assignment_overrides_user_roster(): void
    {
        $sales = $this->mkUser('sales');
        $this->mkListing($sales, ['status' => 'draft', 'region' => '경기 수원시']);
        $this->mkListing($sales, ['status' => 'draft', 'region' => '부산광역시', 'vehicle_number' => '99가1234']);

        $inspector = $this->mkUser('inspection');
        $inspector->update(['region' => '경기 수원시']);
        InspectionAssignment::create([
            'date' => now()->toDateString(), 'region' => '부산광역시', 'user_id' => $inspector->id,
        ]);

        $this->actingAs($inspector->fresh());
        Volt::test('inspection.index')
            ->assertSee('부산광역시')
            ->assertDontSee('경기 수원시');
    }

    /**
     * 사이드바 x-data 가 끝까지 온전히 렌더되는지 — 큰따옴표 HTML 속성이라 안에 " 가 하나라도 들어가면
     * 속성이 거기서 끊기고 Alpine 이 통째로 죽는다(사이드바가 아예 안 열림). 실제로 한 번 깨뜨렸다.
     */
    public function test_sidebar_alpine_data_attribute_is_not_truncated(): void
    {
        $this->actingAs($this->mkUser('manager'));
        $html = $this->get('/listings')->assertOk()->getContent();

        preg_match_all('/x-data="([^"]*)"/', $html, $m);
        $this->assertNotEmpty(
            array_filter($m[1], fn ($d) => str_contains($d, 'toggle()') && str_contains($d, 'closeMobile()')),
            'x-data 가 중간에 잘렸다 — 안에 큰따옴표가 있는지 확인',
        );
    }

    /** 지역 입력은 datalist(모바일에서 안 뜬다) 대신 Alpine 자동완성이어야 한다 — 되돌아가는 것 방지. */
    public function test_region_inputs_use_mobile_autocomplete(): void
    {
        $this->actingAs($this->mkUser('manager', null, 'super'));

        foreach (['/listings', '/inspection', '/manage', '/users'] as $url) {
            $this->assertStringNotContainsString('<datalist', $this->get($url)->assertOk()->getContent(), $url);
        }

        // 자동완성이 Livewire 프로퍼티와 양방향으로 묶여야 한다(링크 자동채움 같은 서버 변경도 반영).
        // 후보는 @js 가 유니코드 이스케이프하므로 평문 대신 이스케이프 형태로 확인.
        // 자동완성이 Livewire 프로퍼티와 양방향으로 묶여야 한다 — 끊기면 서버가 채운 값(링크 자동채움)이 인풋에 안 뜬다.
        $this->assertStringContainsString('$wire.entangle', Volt::test('listings.index')->set('showAdd', true)->html());
    }

    /** 백필은 기본이 dry-run — 실수로 데이터가 바뀌면 안 된다. --apply 에서만 반영. */
    public function test_region_normalize_command_dry_run_then_apply(): void
    {
        $sales = $this->mkUser('sales');
        $listing = $this->mkListing($sales, ['region' => '경기 수원시']);
        DB::table('purchase_listings')->where('id', $listing->id)->update(['region' => '수원']);   // 모델 우회 = 과거 데이터 재현

        $this->artisan('board:region-normalize')->assertSuccessful();
        $this->assertSame('수원', DB::table('purchase_listings')->where('id', $listing->id)->value('region'));

        $this->artisan('board:region-normalize', ['--apply' => true])->assertSuccessful();
        $this->assertSame('경기 수원시', DB::table('purchase_listings')->where('id', $listing->id)->value('region'));
    }
}

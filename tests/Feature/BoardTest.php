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
use App\Services\CarErpReadService;
use App\Services\ExchangeRateService;
use App\Services\ListingEnrichment;
use App\Services\RespondIoService;
use App\Services\VerdictService;
use App\Support\ListingLink;
use App\Support\TimeGate;
use App\Support\UploadGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
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

            return $request['contract_version'] === 3
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
            return $r['contract_version'] === 3
                && $r['purchase_price_krw'] === 9900000          // 1000만 − 할인1%(10만)
                && $r['selling_fee_krw'] === 440000              // 매도비
                && $r['sale_currency'] === 'EUR'
                && $r['sale_exchange_rate'] === 1500
                && $r['sale_price'] === 6893.33                  // 차량금액 10,340,000 / 1500
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

    public function test_edit_drawer_renders_attachment_section(): void
    {
        config(['board.photo_disk' => 'public']);
        $kim = $this->mkUser('sales');
        $this->actingAs($kim);
        $l = $this->mkListing($kim);
        $l->salesAttachments()->create(['s3_path' => 's/reg.pdf', 'original_name' => 'reg.pdf', 'sort' => 1, 'kind' => InspectionPhoto::KIND_SALES_DOCUMENT]);

        Volt::test('listings.index')
            ->call('openEdit', $l->id)
            ->assertSee('차량 첨부')
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
            ->set('final_price', '13200000')
            ->set('sendSelected', true)
            ->call('save')
            ->assertHasNoErrors();

        $l->refresh();
        $this->assertSame('inspected', $l->status);
        $this->assertSame(13200000, $l->final_price);
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
        // 10,000,000 − 10% + 440,000(매도비 고정) = 9,440,000, 배송 없음
        $this->assertSame(9440000, $l->final_price);
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
        // 10,000,000 − 20% + 440,000(매도비) = 8,440,000 (옛 10,440,000 이 나가면 안 됨)
        $this->assertSame(8440000, $l->final_price);
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

    public function test_poll_ssancar_media_advances_draft_when_video_appears(): void
    {
        config([
            'services.ssancar_media.base_url' => 'https://www.ssancar.com/page/api_car_media.php',
            'services.ssancar_media.api_key' => 'testkey',
            'board.ssancar_auto_forward' => true,   // 자동전이 opt-in(계약 확인 후 켬)
        ]);
        Cache::flush();
        Http::fake(['*api_car_media.php*' => Http::response([
            'ok' => 1, 'mode' => 'plate',
            'videos' => [['embed_url' => 'https://iframe.mediadelivery.net/embed/685063/vid']],
            'photos' => [],
        ], 200)]);

        $sales = $this->mkUser('sales');
        $l = $this->mkListing($sales, ['status' => 'draft']);   // encar → (B) vin/번호판 교차매칭

        $this->artisan('board:poll-ssancar-media')->assertExitCode(0);

        $this->assertSame('inspected', $l->fresh()->status);   // 영상 감지 → 전달대기 자동 전이
        $this->assertDatabaseHas('integration_events', [
            'purchase_listing_id' => $l->id,
            'target' => 'ssancar_media',
            'event_type' => 'auto_forward_ready',
        ]);
    }

    public function test_poll_ssancar_media_keeps_draft_when_no_video(): void
    {
        config([
            'services.ssancar_media.base_url' => 'https://www.ssancar.com/page/api_car_media.php',
            'services.ssancar_media.api_key' => 'testkey',
            'board.ssancar_auto_forward' => true,
        ]);
        Cache::flush();
        Http::fake(['*api_car_media.php*' => Http::response([
            'ok' => 1, 'mode' => 'plate', 'videos' => [], 'photos' => ['https://cdn.ssancar.com/x.jpg'],
        ], 200)]);

        $sales = $this->mkUser('sales');
        $l = $this->mkListing($sales, ['status' => 'draft']);

        $this->artisan('board:poll-ssancar-media')->assertExitCode(0);

        $this->assertSame('draft', $l->fresh()->status);   // 사진만/영상 없음 → draft 유지(현지확인 수동 폴백)
    }

    public function test_poll_ssancar_media_noop_when_unconfigured(): void
    {
        config([
            'services.ssancar_media.base_url' => '',
            'services.ssancar_media.api_key' => '',
        ]);
        Http::fake();

        $sales = $this->mkUser('sales');
        $l = $this->mkListing($sales, ['status' => 'draft']);

        $this->artisan('board:poll-ssancar-media')->assertExitCode(0);

        $this->assertSame('draft', $l->fresh()->status);
        Http::assertNothingSent();   // 미설정이면 외부호출 0
    }

    public function test_poll_ssancar_media_noop_when_flag_off_even_with_video(): void
    {
        // 미디어 설정은 됐지만 자동전이 플래그 off(기본) → 상태 자동전이 안 함(계약 확인 전 안전).
        config([
            'services.ssancar_media.base_url' => 'https://www.ssancar.com/page/api_car_media.php',
            'services.ssancar_media.api_key' => 'testkey',
            'board.ssancar_auto_forward' => false,
        ]);
        Http::fake(['*api_car_media.php*' => Http::response([
            'ok' => 1, 'videos' => [['embed_url' => 'https://x/embed/1']], 'photos' => [],
        ], 200)]);

        $sales = $this->mkUser('sales');
        $l = $this->mkListing($sales, ['status' => 'draft']);

        $this->artisan('board:poll-ssancar-media')->assertExitCode(0);

        $this->assertSame('draft', $l->fresh()->status);
        Http::assertNothingSent();   // 플래그 off면 조회조차 안 함
    }

    public function test_poll_ssancar_media_ages_out_stale_draft(): void
    {
        config([
            'services.ssancar_media.base_url' => 'https://www.ssancar.com/page/api_car_media.php',
            'services.ssancar_media.api_key' => 'testkey',
            'board.ssancar_auto_forward' => true,
            'board.ssancar_poll_max_age_days' => 3,
        ]);
        Cache::flush();
        Http::fake(['*api_car_media.php*' => Http::response([
            'ok' => 1, 'videos' => [['embed_url' => 'https://x/embed/1']], 'photos' => [],
        ], 200)]);

        $sales = $this->mkUser('sales');
        $l = $this->mkListing($sales, ['status' => 'draft']);
        $l->created_at = now()->subDays(4);   // 4일 전 등록 + 미디어 본 적 없음 → 에이지아웃
        $l->saveQuietly();

        $this->artisan('board:poll-ssancar-media')->assertExitCode(0);

        $this->assertSame('draft', $l->fresh()->status);   // 제외 → 전이 안 됨
        Http::assertNothingSent();   // 쿼리서 빠져 API 조회조차 안 함(부하 0)
    }

    public function test_poll_ssancar_media_keeps_polling_connected_stale_draft(): void
    {
        config([
            'services.ssancar_media.base_url' => 'https://www.ssancar.com/page/api_car_media.php',
            'services.ssancar_media.api_key' => 'testkey',
            'board.ssancar_auto_forward' => true,
            'board.ssancar_poll_max_age_days' => 3,
        ]);
        Cache::flush();
        Http::fake(['*api_car_media.php*' => Http::response([
            'ok' => 1, 'videos' => [['embed_url' => 'https://x/embed/1']], 'photos' => [],
        ], 200)]);

        $sales = $this->mkUser('sales');
        $l = $this->mkListing($sales, ['status' => 'draft']);
        $l->created_at = now()->subDays(4);              // 4일 전이지만
        $l->ssancar_media_seen_at = now()->subDays(2);   // 이미 연결됨(미디어 본 적) → 계속 폴링
        $l->saveQuietly();

        $this->artisan('board:poll-ssancar-media')->assertExitCode(0);

        $this->assertSame('inspected', $l->fresh()->status);   // 연결된 건 3일 넘어도 폴링 → 영상 감지 전이
    }

    public function test_poll_ssancar_media_marks_connection_on_photo_only(): void
    {
        config([
            'services.ssancar_media.base_url' => 'https://www.ssancar.com/page/api_car_media.php',
            'services.ssancar_media.api_key' => 'testkey',
            'board.ssancar_auto_forward' => true,
        ]);
        Cache::flush();
        Http::fake(['*api_car_media.php*' => Http::response([
            'ok' => 1, 'videos' => [], 'photos' => ['https://cdn.ssancar.com/x.jpg'],
        ], 200)]);

        $sales = $this->mkUser('sales');
        $l = $this->mkListing($sales, ['status' => 'draft']);

        $this->artisan('board:poll-ssancar-media')->assertExitCode(0);

        $l->refresh();
        $this->assertSame('draft', $l->status);              // 사진만 → 전이 안 함(영상 대기)
        $this->assertNotNull($l->ssancar_media_seen_at);     // 연결 표식은 찍힘 → 이후 에이지아웃 유예
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

    public function test_inspection_finalizes_offer_currency(): void
    {
        $l = $this->mkListing($this->mkUser('sales'), ['status' => 'draft', 'car_cost' => 9000000]);
        $this->actingAs($this->mkUser('inspection'));

        Volt::test('inspection.index')
            ->call('openDrawer', $l->id)
            ->set('car_cost', '9000000')
            ->set('displayCurrency', 'EUR')
            ->call('save')->assertHasNoErrors();

        $l->refresh();
        $this->assertSame('EUR', $l->offer_currency);
        $this->assertGreaterThan(0, $l->offer_rate);   // 확정 시점 EUR 환율 스냅샷
    }

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
        // 차값 13.8M KRW, 할인 0, 배송 1000 USD → car=14,240,000 / ship=1,380,000 / total=15,620,000
        $l = $this->mkListing($sales, [
            'status' => 'inspected',
            'car_cost' => 13800000, 'expected_price_currency' => 'KRW',
            'discount_rate' => 0, 'shipping_usd' => 1000,
            'offer_currency' => 'KRW', 'offer_rate' => 1, 'final_price' => 15620000,
        ]);
        $this->actingAs($sales);

        Volt::test('forwarding.index')
            ->call('openDetail', $l->id)
            ->call('setQuoteCurrency', 'USD')
            ->assertSet('quoteCurrency', 'USD');

        $l->refresh();
        $this->assertSame('USD', $l->offer_currency);
        $this->assertSame(1380, $l->offer_rate);          // 라이브(폴백) 환율 스냅샷
        $this->assertSame(15620000, $l->final_price);      // totalKrw 재스냅샷(동일)
    }

    public function test_forwarding_quote_card_amounts_are_consistent(): void
    {
        Bus::fake();
        $sales = $this->mkUser('sales');
        $attr = [
            'status' => 'inspected', 'car_cost' => 13800000, 'expected_price_currency' => 'KRW',
            'discount_rate' => 0, 'shipping_usd' => 1000, 'final_price' => 15620000,
        ];
        $eur = $this->mkListing($sales, $attr + ['offer_currency' => 'EUR', 'offer_rate' => 1500]);
        $krw = $this->mkListing($sales, $attr + ['offer_currency' => 'KRW', 'offer_rate' => 1]);
        $this->actingAs($sales);

        foreach ([$eur, $krw] as $l) {
            $c = Volt::test('forwarding.index')->call('openDetail', $l->id);
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
            'status' => 'inspected', 'vehicle_number' => '375러1924', 'final_price' => 10000000,
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
            ->assertSet('expected_price_currency', 'KRW')
            ->assertSet('car_cost', '66660000')         // 크롤링 KRW → 차값 자동매핑(금액산정 입력)
            ->assertSet('region', '안산')
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

        $this->actingAs($this->mkUser('inspection'));
        Volt::test('inspection.index')->call('openDrawer', $l->id)->assertSee('차값 ($)');

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
        // final_price(KRW) = 7,000×1,400 − 0% + 440,000(매도비) = 10,240,000 (배송 없음)
        $this->assertSame(7000 * 1400 + (int) config('board.sales_fee'), $l->final_price);
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
        $this->assertSame('서울', $r['region']);
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

    public function test_portal_super_impersonation_is_view_only(): void
    {
        $this->carErpReadConfig();
        Http::fake([
            '*/api/internal/board/shipping-requests/sync*' => Http::response(['created' => [1]], 200),
            '*' => Http::response(['count' => 0, 'data' => []], 200),
        ]);
        $target = $this->mkUser('sales');
        $target->update(['car_erp_salesman_email' => 'target@ce.test']);
        $this->actingAs($this->mkUser('manager', null, 'super'));

        Volt::test('portal.index')
            ->call('viewUser', $target->id)              // super 가 타인 열람
            ->call('setTab', 'shipping')
            ->call('syncBundles')                        // 쓰기 시도 → 차단
            ->assertSet('syncResult', null)
            ->assertSee('조회 전용');

        // 동기화가 car-erp 로 전송되지 않음(타인 대행 쓰기 차단).
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'shipping-requests/sync'));
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

    /** 배포 순간 settings 테이블이 아직 없어도 로그인 화면이 500 나지 않고 기본 브랜드로 degrade. */
    public function test_login_survives_missing_settings_table(): void
    {
        Schema::drop('settings');

        $this->get('/login')->assertOk()->assertSee('HeymanBoard', false);
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
}

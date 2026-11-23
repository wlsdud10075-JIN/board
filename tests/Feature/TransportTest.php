<?php

namespace Tests\Feature;

use App\Models\AlimtalkLog;
use App\Models\BoardAuditLog;
use App\Models\InspectionPhoto;
use App\Models\PurchaseListing;
use App\Models\Setting;
use App\Models\TransportCompany;
use App\Models\TransportRequest;
use App\Models\TransportRequestRecipient;
use App\Models\User;
use App\Services\TransportService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 탁송 배차 요청(2026-11-12) — 정본 meetings/plan-transport-2026-11.md. 알림톡은 tmplId 가 없어 skipped 로 남지만 흐름은 끝까지 간다.
 */
class TransportTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function user(string $role = 'sales', string $permission = 'user', ?string $phone = '01011112222'): User
    {
        $this->seq++;

        return User::create([
            'name' => $role.$this->seq, 'email' => "t{$this->seq}@t.test", 'password' => 'password',
            'role' => $role, 'permission' => $permission, 'is_active' => true, 'phone' => $phone,
        ]);
    }

    private function listing(User $owner, array $attr = []): PurchaseListing
    {
        $this->seq++;

        return PurchaseListing::create(array_merge([
            'created_by_user_id' => $owner->id, 'source' => 'encar', 'vehicle_number' => '12가'.(1000 + $this->seq),
            'vin' => 'TRV'.str_pad((string) $this->seq, 10, '0', STR_PAD_LEFT), 'status' => 'draft', 'buyer_verdict' => 'none',
        ], $attr));
    }

    private function company(array $phones = ['01099990001' => '김기사', '01099990002' => '이기사']): TransportCompany
    {
        $c = TransportCompany::create(['name' => '위카', 'is_active' => true]);
        foreach ($phones as $p => $label) {
            $c->phones()->create(['phone' => $p, 'label' => $label, 'is_active' => true]);
        }

        return $c;
    }

    private function svc(): TransportService
    {
        return app(TransportService::class);
    }

    /** 생성 → 즉시 발송: 수신자 = 업체 번호 전부 + 추가 번호 + 영업 + 그 영업의 관리, 같은 번호는 한 번. 알림톡은 skipped 여도 sent. */
    public function test_create_and_send_snapshots_recipients_with_dedupe(): void
    {
        $sales = $this->user('sales', 'user', '01011112222');
        $manager = $this->user('manager', 'user', '01033334444');
        DB::table('manager_salesman')->insert(['manager_user_id' => $manager->id, 'salesman_user_id' => $sales->id, 'created_at' => now(), 'updated_at' => now()]);
        $noPhoneManager = $this->user('manager', 'user', null);
        DB::table('manager_salesman')->insert(['manager_user_id' => $noPhoneManager->id, 'salesman_user_id' => $sales->id, 'created_at' => now(), 'updated_at' => now()]);
        $c = $this->company();
        $l = $this->listing($sales);

        $req = $this->svc()->create($l, $sales, ['company_id' => $c->id, 'extra_phone' => '010-9999-0001', 'address' => '경기 안산시', 'stock_location' => '홈플']);

        $this->assertSame(TransportRequest::SENT, $req->status);
        $this->assertSame(1, $req->revision);
        $kinds = $req->recipients->pluck('kind')->all();
        $this->assertSame([TransportRequestRecipient::KIND_COMPANY, TransportRequestRecipient::KIND_COMPANY, TransportRequestRecipient::KIND_SALES, TransportRequestRecipient::KIND_MANAGER], $kinds);   // 추가 번호 = 김기사와 같아 제외, 전화 없는 관리 제외
        $this->assertSame(4, AlimtalkLog::where('template_code', 'board_transport_request')->count());
        $this->assertSame('skipped', $req->recipients->first()->sent_status);
        $this->assertSame(2, BoardAuditLog::where('purchase_listing_id', $l->id)->whereIn('action', ['transport_request_create', 'transport_request_sent'])->count());
        $this->assertStringNotContainsString('0109999', (string) BoardAuditLog::where('action', 'transport_request_create')->value('new_value'));
    }

    public function test_second_open_request_is_refused_and_rejected_allows_new_revision(): void
    {
        $sales = $this->user();
        $c = $this->company();
        $l = $this->listing($sales);
        $req = $this->svc()->create($l, $sales, ['company_id' => $c->id]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('open_exists');
        try {
            $this->svc()->create($l, $sales, ['company_id' => $c->id]);
        } finally {
            $r = $req->recipients->first();
            $this->svc()->decide($req, $r, 'reject', '내일 가능', null, '127.0.0.1');
            $again = $this->svc()->create($l, $sales, ['company_id' => $c->id]);
            $this->assertSame(2, $again->revision);
        }
    }

    /** 선착순 — 두 번째 결정은 not_decidable. 영업 수신자 행으로는 결정 불가. */
    public function test_first_decision_wins_and_sales_recipient_cannot_decide(): void
    {
        $sales = $this->user();
        $c = $this->company();
        $l = $this->listing($sales);
        $req = $this->svc()->create($l, $sales, ['company_id' => $c->id]);
        [$kim, $lee] = $req->recipients->where('kind', TransportRequestRecipient::KIND_COMPANY)->values();
        $salesRow = $req->recipients->firstWhere('kind', TransportRequestRecipient::KIND_SALES);

        $this->assertFalse($req->canDecide($salesRow));

        $after = $this->svc()->decide($req, $kim, 'accept', null, '김기사', '127.0.0.1');
        $this->assertSame(TransportRequest::ACCEPTED, $after->status);
        $this->assertSame($kim->id, $after->decided_recipient_id);
        $this->assertStringContainsString('010-****-0001', $after->dispatchLine());

        $this->expectException(\DomainException::class);
        $this->svc()->decide($after, $lee, 'reject', null, null, '127.0.0.1');
    }

    /** 결정 → 영업·관리에게 결과 알림톡(스냅샷한 수신자) + 감사 행. */
    public function test_decision_notifies_sales_and_manager_rows(): void
    {
        $sales = $this->user('sales', 'user', '01011112222');
        $manager = $this->user('manager', 'user', '01033334444');
        DB::table('manager_salesman')->insert(['manager_user_id' => $manager->id, 'salesman_user_id' => $sales->id, 'created_at' => now(), 'updated_at' => now()]);
        $c = $this->company(['01099990001' => '김기사']);
        $l = $this->listing($sales);
        $req = $this->svc()->create($l, $sales, ['company_id' => $c->id]);

        $this->svc()->decide($req, $req->recipients->first(), 'reject', '지역 불가', null, null);

        $this->assertSame(2, AlimtalkLog::where('template_code', 'board_transport_result')->count());
        $this->assertSame(2, $req->recipients()->whereNotNull('result_log_id')->count());
        $audit = BoardAuditLog::where('action', 'transport_request_decided')->first();
        $this->assertStringContainsString('rejected', $audit->new_value);
        $this->assertStringContainsString('지역 불가', $audit->new_value);
    }

    /** 공개 페이지 — 서명 없으면 403, 업체 링크는 버튼, 영업 링크는 보기 전용, POST 로 결정. */
    public function test_public_page_and_post_decide(): void
    {
        $sales = $this->user();
        $c = $this->company(['01099990001' => '김기사']);
        $l = $this->listing($sales);
        $req = $this->svc()->create($l, $sales, ['company_id' => $c->id, 'address' => '경기 안산시 단원구']);
        $company = $req->recipients->firstWhere('kind', TransportRequestRecipient::KIND_COMPANY);
        $salesRow = $req->recipients->firstWhere('kind', TransportRequestRecipient::KIND_SALES);

        $this->get(route('transport.show', ['req' => $req->id, 'r' => $company->id]))->assertForbidden();   // 서명 없음
        $this->get($req->linkFor($company))->assertOk()->assertSee('경기 안산시 단원구')->assertSee(__('transport.page.accept'));
        $this->get($req->linkFor($salesRow))->assertOk()->assertSee(__('transport.page.readonly'))->assertDontSee(__('transport.page.accept'));

        $decide = URL::temporarySignedRoute('transport.decide', now()->addMinutes(60), ['req' => $req->id, 'r' => $company->id]);
        $this->post($decide, ['action' => 'accept', 'name' => '김기사'])->assertOk()->assertSee(__('transport.page.done_accept'));
        $this->assertSame(TransportRequest::ACCEPTED, $req->fresh()->status);

        $this->post($decide, ['action' => 'reject'])->assertStatus(410);   // 이미 처리됨
        $salesDecide = URL::temporarySignedRoute('transport.decide', now()->addMinutes(60), ['req' => $req->id, 'r' => $salesRow->id]);
        $this->post($salesDecide, ['action' => 'accept'])->assertStatus(410);
    }

    /** 예약 발송 — 시각 전엔 scheduled, 명령이 돌면 sent. 5일 지난 sent 는 expired. */
    public function test_scheduled_send_and_expiry(): void
    {
        $sales = $this->user();
        $c = $this->company(['01099990001' => '김기사']);
        $l = $this->listing($sales);
        $req = $this->svc()->create($l, $sales, ['company_id' => $c->id, 'send_at' => now()->addHours(2)]);
        $this->assertSame(TransportRequest::SCHEDULED, $req->status);
        $this->assertSame(0, $req->recipients()->count());

        $this->artisan('board:transport-send')->assertExitCode(0);
        $this->assertSame(TransportRequest::SCHEDULED, $req->fresh()->status);

        Carbon::setTestNow(now()->addHours(3));
        $this->artisan('board:transport-send')->assertExitCode(0);
        $this->assertSame(TransportRequest::SENT, $req->fresh()->status);

        Carbon::setTestNow(now()->addDays(6));
        $this->artisan('board:transport-send')->assertExitCode(0);
        $this->assertSame(TransportRequest::EXPIRED, $req->fresh()->status);
        $this->assertFalse($req->fresh()->canDecide($req->recipients()->first()));
        Carbon::setTestNow();
    }

    /** 매입잔금 — 등록 영업 명의, note = 배차 정보(전화 마스킹), 금액 비우면 ERP 미지급 전액. 업무관리자는 쓰기 불가. */
    public function test_balance_request_carries_masked_dispatch_note(): void
    {
        config(['services.car_erp.base_url' => 'https://erp.test', 'services.car_erp.read_hmac_secret' => 'sek']);
        $sales = $this->user('sales', 'user', '01011112222');
        $sales->forceFill(['car_erp_salesman_email' => 'erp-sales@t.test'])->save();
        $c = $this->company(['01099990001' => '김기사']);
        $l = $this->listing($sales, ['status' => 'synced', 'buyer_verdict' => 'accepted', 'car_erp_vehicle_id' => 77]);
        $req = $this->svc()->create($l, $sales, ['company_id' => $c->id]);
        $req = $this->svc()->decide($req, $req->recipients->first(), 'accept', null, '김기사', null);

        Http::fake([
            'erp.test/*/inventory*' => Http::response(['data' => [['vehicle_id' => 77, 'vehicle_number' => $l->vehicle_number, 'purchase_unpaid' => 8560000]]], 200),
            'erp.test/*/requests*' => Http::response(['created' => 1], 201),
        ]);

        $workManager = $this->user('manager', 'manager');
        $this->assertFalse(TransportService::canWrite($workManager, $l));
        $this->assertTrue(TransportService::canWrite($sales, $l));

        $r = $this->svc()->requestBalance($req, $sales, null);
        $this->assertTrue($r['ok'], $r['message']);
        Http::assertSent(function ($rq) {
            if (! str_contains($rq->url(), '/requests')) {
                return false;
            }
            $b = json_decode($rq->body(), true);

            return $b['type'] === 'purchase_balance' && $b['vehicle_ids'] === [77] && $b['amount_krw'] === 8560000
                && str_starts_with($b['note'], __('transport.balance.note_prefix').'위카') && str_contains($b['note'], '010-****-0001') && ! str_contains($b['note'], '0001김');
        });
        $this->assertNotNull($req->fresh()->balance_requested_at);
        $this->assertSame(1, BoardAuditLog::where('action', 'transport_balance_request')->count());
    }

    /** 드로어 탭 컴포넌트 — 등록 영업은 폼이 보이고, 남의 매물은 404(SalesmanScope). */
    public function test_tab_component_scope_and_form(): void
    {
        $sales = $this->user();
        $other = $this->user();
        $c = $this->company();
        $l = $this->listing($sales);

        $this->actingAs($sales);
        Volt::test('transport.tab', ['listingId' => $l->id])
            ->assertSee(__('transport.form.submit'))
            ->set('company_id', $c->id)
            ->set('address', '서울 강서구')
            ->set('stock_location', '야드')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSee(__('transport.status.sent'));
        $this->assertSame('야드', TransportRequest::first()->stock_location);

        $this->actingAs($other);
        $this->expectException(ModelNotFoundException::class);   // 글로벌 SalesmanScope — 화면에선 404
        Volt::test('transport.tab', ['listingId' => $l->id]);
    }

    /** 희망 배차 시각(2026-11-17) — 폼 → 즉시 발송 · 업체 페이지 맨 위 · 이력 · 감사 행 · 매입잔금 메모. 알림톡 변수는 그대로(BizM 승인본). 지난 시각은 막는다. */
    public function test_wanted_dispatch_time_shows_on_page_history_and_balance_note(): void
    {
        Carbon::setTestNow('2026-11-17 11:00:00');
        config(['services.car_erp.base_url' => 'https://erp.test', 'services.car_erp.read_hmac_secret' => 'sek']);
        $sales = $this->user();
        $sales->forceFill(['car_erp_salesman_email' => 'erp-sales@t.test'])->save();
        $c = $this->company(['01099990001' => '김기사']);
        $l = $this->listing($sales, ['status' => 'synced', 'buyer_verdict' => 'accepted', 'car_erp_vehicle_id' => 77]);

        $this->actingAs($sales);
        Volt::test('transport.tab', ['listingId' => $l->id])
            ->assertDontSee('예약 보내기')
            ->set('company_id', $c->id)->set('wanted_at', '2026-11-17T09:00')->call('submit')->assertHasErrors('wanted_at')
            ->set('wanted_at', '2026-11-17T12:00')->call('submit')->assertHasNoErrors()
            ->assertSee(__('transport.status.sent'))->assertSee('희망 배차 11-17(화) 12:00');

        $req = TransportRequest::firstOrFail();
        $this->assertTrue($req->isSent());   // 발송을 미루지 않는다
        $this->assertSame('11-17(화) 12:00', $req->wantedLabel());
        $this->assertStringContainsString('희망 배차 11-17(화) 12:00', BoardAuditLog::where('action', 'transport_request_create')->value('new_value'));

        $company = $req->recipients->firstWhere('kind', TransportRequestRecipient::KIND_COMPANY);
        $this->get($req->linkFor($company))->assertOk()->assertSeeInOrder([__('transport.page.wanted'), '11-17(화) 12:00', $req->message]);

        $req = $this->svc()->decide($req, $company, 'accept', null, '김기사', null);
        Http::fake([
            'erp.test/*/inventory*' => Http::response(['data' => [['vehicle_id' => 77, 'vehicle_number' => $l->vehicle_number, 'purchase_unpaid' => 100]]], 200),
            'erp.test/*/requests*' => Http::response(['created' => 1], 201),
        ]);
        $this->assertTrue($this->svc()->requestBalance($req, $sales, null)['ok']);
        Http::assertSent(fn ($rq) => str_contains($rq->url(), '/requests') && str_contains(json_decode($rq->body(), true)['note'], '희망 배차 11-17(화) 12:00'));

        // 비우면 줄 자체가 없다
        $l2 = $this->listing($sales);
        $r2 = $this->svc()->create($l2, $sales, ['company_id' => $c->id]);
        $this->assertNull($r2->wantedLabel());
        $this->get($r2->linkFor($r2->recipients->firstWhere('kind', TransportRequestRecipient::KIND_COMPANY)))->assertOk()->assertDontSee(__('transport.page.wanted'));
    }

    /** 매입예정 목록 「배차」 칸(2026-11-19) — 없음 「—」 · 진행중 · 배차완료. 거절로 끝난 최근 요청은 없음으로. */
    public function test_listings_table_shows_transport_state_column(): void
    {
        $sales = $this->user();
        $c = $this->company();
        $l = $this->listing($sales);
        $this->assertNull($l->transportState());

        $req = $this->svc()->create($l, $sales, ['company_id' => $c->id]);
        $this->assertSame('open', $l->fresh()->transportState());
        $this->actingAs($sales);
        Volt::test('listings.index')->assertSee(__('listings.table.transport'))->assertSee(__('listings.transport_state.open'));

        $req->forceFill(['status' => TransportRequest::ACCEPTED])->save();
        $this->assertSame('done', $l->fresh()->transportState());
        Volt::test('listings.index')->assertSee(__('listings.transport_state.done'));

        $req->forceFill(['status' => TransportRequest::REJECTED])->save();
        $this->assertSame('rejected', $l->fresh()->transportState());
        Volt::test('listings.index')->assertSee(__('listings.transport_state.rejected'));

        $req->forceFill(['status' => TransportRequest::EXPIRED])->save();
        $this->assertNull($l->fresh()->transportState());
    }

    /** 첨부 탭 사진에서 골라 보내기(2026-11-17) — 처음엔 전부 체크 · 뺀 것은 안 간다 · 서류·남의 매물 사진은 무시 · 새로 올린 것과 합쳐 5장 상한 · 원본 복사. */
    public function test_attachment_photos_can_be_picked_for_the_company_page(): void
    {
        Storage::fake((string) config('board.photo_disk'));
        $disk = Storage::disk((string) config('board.photo_disk'));
        $sales = $this->user();
        $c = $this->company(['01099990001' => '김기사']);
        $l = $this->listing($sales);
        $other = $this->listing($sales);
        $mk = function (PurchaseListing $on, string $name, string $kind) use ($disk) {
            $disk->put('sales/'.$on->id.'/'.$name, 'x');

            return InspectionPhoto::create(['purchase_listing_id' => $on->id, 's3_path' => 'sales/'.$on->id.'/'.$name, 'original_name' => $name, 'sort' => 1, 'kind' => $kind, 'share_to_buyer' => false]);
        };
        $p = [];
        foreach (['a.jpg', 'b.jpg', 'c.jpg'] as $n) {
            $p[] = $mk($l, $n, InspectionPhoto::KIND_SALES_PHOTO);
        }
        $doc = $mk($l, 'contract.pdf', InspectionPhoto::KIND_SALES_DOCUMENT);
        $foreign = $mk($other, 'z.jpg', InspectionPhoto::KIND_SALES_PHOTO);

        $this->actingAs($sales);
        $t = Volt::test('transport.tab', ['listingId' => $l->id])
            ->assertSet('pick', [(string) $p[0]->id, (string) $p[1]->id, (string) $p[2]->id]);   // 사진만, 전부 체크
        $t->set('pick', [(string) $p[0]->id, (string) $p[2]->id, (string) $doc->id, (string) $foreign->id])
            ->set('company_id', $c->id)->call('submit')->assertHasNoErrors();

        $req = TransportRequest::firstOrFail();
        $this->assertSame(['a.jpg', 'c.jpg'], $req->files->pluck('original_name')->all());
        foreach ($req->files as $f) {
            $this->assertStringStartsWith('transport/'.$l->id.'/'.$req->id.'/', $f->s3_path);
            $disk->assertExists($f->s3_path);
        }
        $disk->assertExists($p[1]->s3_path);   // 원본은 그대로
        $this->assertStringContainsString('사진 2 (첨부에서 2)', BoardAuditLog::where('action', 'transport_request_create')->value('new_value'));
        $this->get($req->linkFor($req->recipients->firstWhere('kind', TransportRequestRecipient::KIND_COMPANY)))->assertOk()->assertSee($req->files[0]->shareUrl(), false);

        // 합쳐서 5장 넘으면 막힘
        $l2 = $this->listing($sales);
        $ids = [];
        foreach (range(1, 4) as $i) {
            $ids[] = $mk($l2, "p{$i}.jpg", InspectionPhoto::KIND_SALES_PHOTO)->id;
        }
        $this->expectException(\DomainException::class);
        $this->svc()->create($l2, $sales, ['company_id' => $c->id, 'attachment_ids' => $ids], [UploadedFile::fake()->image('n1.jpg'), UploadedFile::fake()->image('n2.jpg')]);
    }

    /** 업체 관리 — 관리 role 은 추가·번호 등록 가능, 영업은 403. */
    public function test_companies_page_permissions_and_add(): void
    {
        $manager = $this->user('manager');
        $this->actingAs($manager);
        Volt::test('transport.companies')
            ->set('newName', '현대A1')->call('addCompany')->assertHasNoErrors()
            ->set('newPhone.1.phone', '010-1234-5678')->set('newPhone.1.label', '사무실')->call('addPhone', 1)->assertHasNoErrors()
            ->assertSee('010-1234-5678');
        $this->assertSame('01012345678', TransportCompany::first()->phones()->first()->phone);

        $this->actingAs($this->user('sales'));
        $this->get(route('transport.companies'))->assertForbidden();
    }

    /** 매입예정 드로어 안에서 중첩 탭이 렌더되고, `?edit=<id>` 로 들어오면 그 드로어의 탁송 탭이 열린다. */
    public function test_listing_drawer_renders_transport_tab_and_edit_param_opens_it(): void
    {
        $sales = $this->user();
        $this->company();
        $l = $this->listing($sales);
        $this->actingAs($sales);

        Volt::test('listings.index')->call('openEdit', $l->id)
            ->assertSee(__('listings.drawer.tab.transport'))
            ->set('drawerTab', 'transport')
            ->assertSee('transport-tab-'.$l->id, false);   // 중첩 컴포넌트 wire:key — 폼 자체는 test_tab_component_scope_and_form 이 본다

        Volt::test('listings.index', ['edit' => $l->id])
            ->assertSet('editingId', $l->id)
            ->assertSet('drawerTab', 'transport')
            ->assertSet('edit', 0);
    }

    /** 「알림톡 안내」에서 체크한 사람도 배차 결과를 받는다 — 이미 받는 영업과 번호가 겹치면 한 통(2026-11-23). */
    public function test_decision_also_notifies_checked_extra_recipients(): void
    {
        $sales = $this->user('sales', 'user', '01011112222');
        $boss = $this->user('manager', 'manager', '01077770000');
        Setting::updateOrCreate(['key' => 'alimtalk_roles_board_transport_result'], ['type' => 'string', 'value' => "manager,user:{$sales->id}"]);
        $c = $this->company(['01099990001' => '김기사']);
        $req = $this->svc()->create($this->listing($sales), $sales, ['company_id' => $c->id]);

        $this->svc()->decide($req, $req->recipients->first(), 'accept', null, '김기사', null);

        $this->assertEqualsCanonicalizing(['01011112222', '01077770000'], AlimtalkLog::where('template_code', 'board_transport_result')->pluck('phone')->all());
        $this->assertSame($boss->id, (int) AlimtalkLog::where('phone', '01077770000')->value('user_id'));
        $this->assertNotNull(BoardAuditLog::where('action', 'transport_notice_extra')->first());
    }
}

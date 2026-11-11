<?php

namespace Tests\Feature;

use App\Models\AlimtalkLog;
use App\Models\BoardAuditLog;
use App\Models\Claim;
use App\Models\Setting;
use App\Models\User;
use App\Services\CarErpReadService;
use App\Services\ClaimService;
use App\Support\AlimtalkConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 클레임 탭 1차(2026-11-10 Jin, 정본 = meetings/plan-claims-2026-11.md) — 접수 → 담당자 → 대표 서명 링크 → 결과 → 종결.
 * ERP 는 Http::fake(by-buyer·sales·settlements), BizM 도 Http::fake — 외부 호출 0.
 */
class ClaimsTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private const BIZM = 'https://alimtalk-api.bizmsg.kr/v2/sender/send';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config(['board.photo_disk' => 'public', 'services.car_erp.base_url' => 'https://carerp.test', 'services.car_erp.read_hmac_secret' => 'rs']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function mk(string $name, string $role = 'sales', string $permission = 'user', array $extra = []): User
    {
        return User::create(array_merge([
            'name' => $name, 'email' => 'u'.(++$this->seq).'@t.test', 'password' => 'password',
            'role' => $role, 'permission' => $permission, 'is_active' => true, 'email_verified_at' => now(),
        ], $extra));
    }

    private function handler(): User
    {
        $h = $this->mk('담당자', 'manager');
        Setting::updateOrCreate(['key' => 'claim_handler_user_ids'], ['value' => (string) $h->id, 'type' => 'string']);

        return $h;
    }

    private function ceo(string $phone = '010-1111-2222'): User
    {
        return $this->mk('홍대표', 'manager', 'admin', ['approval_title' => '대표', 'phone' => $phone]);
    }

    private function fakeErp(): void
    {
        Http::fake([
            self::BIZM => Http::response([['code' => 'success', 'data' => ['msgid' => 'WEB1'], 'message' => 'K000']], 200),
            '*/by-buyer*' => Http::response(['data' => [['buyer_id' => 7, 'buyer' => 'Arif', 'vehicle_count' => 12]]], 200),
            '*/sales*' => Http::response(['data' => [
                ['vehicle_id' => 55, 'vehicle_number' => '12가3456', 'vin' => 'VIN55', 'brand' => 'Audi', 'model_type' => 'A6', 'buyer' => 'Arif', 'sale_price' => 12000, 'currency' => 'EUR', 'sale_date' => now()->subMonths(2)->format('Y-m-d'), 'eta_date' => now()->subDays(5)->format('Y-m-d')],
                ['vehicle_id' => 56, 'vehicle_number' => '34나5678', 'buyer' => 'Arif', 'sale_price' => 9000, 'currency' => 'EUR', 'sale_date' => now()->subMonths(20)->format('Y-m-d')],
                ['vehicle_id' => 57, 'vehicle_number' => '56다0000', 'buyer' => 'Other', 'sale_price' => 1, 'currency' => 'USD', 'sale_date' => now()->format('Y-m-d')],
            ]], 200),
            '*/buyers/7/credit*' => Http::response(['data' => ['buyer_id' => 7, 'credit_grade' => 'B', 'credit_score' => 72, 'credit_available' => true]], 200),
            '*/settlements*' => Http::response(['data' => [['vehicle_id' => 55, 'vehicle_number' => '12가3456', 'margin_rate' => 4.5, 'status' => 'paid']]], 200),
            '*' => Http::response(['data' => []], 200),
        ]);
    }

    private function row(): array
    {
        // sale_price 99999 = 브라우저가 조작한 값 — 스냅샷은 ERP 행(12000)으로 덮어써야 한다.
        return ['vehicle_id' => 55, 'vehicle_number' => '12가3456', 'vin' => 'VIN55', 'brand' => 'Audi', 'model_type' => 'A6', 'buyer' => 'Arif', 'buyer_id' => 7,
            'sale_price' => 99999, 'currency' => 'EUR', 'sale_date' => now()->subMonths(2)->format('Y-m-d'), 'eta_date' => now()->subDays(5)->format('Y-m-d')];
    }

    /** 영업이 포털 드로어로 접수(필수 5종 전부). */
    private function intake(User $sales, ?User $actor = null, array $over = []): Claim
    {
        $this->actingAs($actor ?? $sales);
        $t = Volt::test('claims.intake')->call('openFor', $this->row(), $sales->id)
            ->set('arrived_at', $over['arrived_at'] ?? now()->subDays(3)->format('Y-m-d'))
            ->set('received_km', '123456')->set('content', '엔진 소음 · 바이어 정비소 점검')
            ->set('odometer', [UploadedFile::fake()->image('odo.jpg')])
            ->set('damage', [UploadedFile::fake()->image('dmg.jpg'), UploadedFile::fake()->create('dmg.mp4', 100, 'video/mp4')])
            ->set('invoice', [UploadedFile::fake()->create('inv.pdf', 10, 'application/pdf')])
            ->call('save')->assertHasNoErrors();

        return Claim::latest('id')->firstOrFail();
    }

    // ── 접수 ──

    public function test_intake_creates_claim_with_files_snapshot_and_audit(): void
    {
        $this->fakeErp();
        $sales = $this->mk('김영업', 'sales', 'user', ['car_erp_salesman_email' => 'kim@ce.test']);
        $h = $this->handler();

        $c = $this->intake($sales);

        $this->assertSame(Claim::RECEIVED, $c->status);
        $this->assertSame(55, $c->car_erp_vehicle_id);
        $this->assertSame('Arif', $c->buyer_name);
        $this->assertSame(7, $c->car_erp_buyer_id);
        $this->assertSame('EUR', $c->sale_currency);
        $this->assertEquals(12000, $c->sale_price);   // ERP 행이 권위(브라우저 값 99999 무시)
        $this->assertSame(123456, $c->received_km);
        $this->assertSame($sales->id, $c->salesman_user_id);
        $this->assertSame(4, $c->files()->count());
        $this->assertSame(['damage', 'damage', 'invoice', 'odometer'], $c->files->pluck('kind')->sort()->values()->all());
        Storage::disk('public')->assertExists($c->files->first()->s3_path);
        // ERP 스냅샷 — 총 12대 · 12개월 1대(20개월 전 건 제외, 다른 바이어 제외) · 첫 판매 연도 · 마진율
        $this->assertSame(12, $c->total_purchase_count);
        $this->assertSame(1, $c->year_purchase_count);
        $this->assertSame((int) now()->subMonths(20)->format('Y'), $c->member_since);
        $this->assertSame('12가3456', $c->purchase_history[0]['vehicle_number']);
        $this->assertEquals(4.5, $c->margin_rate);
        $this->assertSame('B', $c->buyer_credit_grade);   // BUYER PRIORITY = ERP 신용도
        $this->assertSame(72, $c->buyer_credit_score);
        $this->assertSame('72점 (B)', $c->creditDisplay());
        $this->assertSame(1, $c->yearClaimCount());       // 자동 카운트(이번 건)
        $this->assertFalse($c->isOverdue());
        // 감사 1행(접수) — action=claim, field=claim_intake
        $this->assertDatabaseHas('board_audit_logs', ['action' => 'claim', 'field' => 'claim_intake', 'user_id' => $sales->id]);
    }

    public function test_intake_requires_each_evidence_kind_and_fields(): void
    {
        $this->fakeErp();
        $sales = $this->mk('김영업');
        $this->actingAs($sales);
        Volt::test('claims.intake')->call('openFor', $this->row(), $sales->id)
            ->set('arrived_at', now()->format('Y-m-d'))->set('received_km', '1')->set('content', 'x')
            ->set('odometer', [UploadedFile::fake()->image('o.jpg')])
            ->set('damage', [UploadedFile::fake()->image('d.jpg')])
            ->call('save')->assertHasErrors(['invoice']);
        $this->assertSame(0, Claim::count());
    }

    public function test_intake_blocked_for_work_manager_viewing_others(): void
    {
        $this->fakeErp();
        $sales = $this->mk('김영업');
        $wm = $this->mk('업무관리자', 'manager', 'manager');
        $this->actingAs($wm);
        Volt::test('claims.intake')->call('openFor', $this->row(), $sales->id)->assertSet('open', false);
        // super 는 대리 접수 가능 — 명의는 영업, 접수자는 super
        $super = $this->mk('super', 'manager', 'super');
        $c = $this->intake($sales, $super);
        $this->assertSame($sales->id, $c->salesman_user_id);
        $this->assertSame($super->id, $c->created_by_user_id);
    }

    public function test_overdue_is_flag_only_on_intake(): void
    {
        $this->fakeErp();
        $sales = $this->mk('김영업');
        $c = $this->intake($sales, null, ['arrived_at' => now()->subDays(20)->format('Y-m-d')]);
        $this->assertTrue($c->isOverdue());
        $this->assertSame(20, $c->daysAfterArrival());
    }

    // ── 담당자 탭 ──

    public function test_claims_tab_only_for_designated_handlers(): void
    {
        $super = $this->mk('super', 'manager', 'super');
        $this->actingAs($super)->get(route('claims'))->assertForbidden();   // 지정 없이는 super 도 못 본다(첫 예외)
        $h = $this->handler();
        $this->actingAs($h)->get(route('claims'))->assertOk();
        $this->assertTrue($h->isClaimHandler());
        $this->assertFalse($super->fresh()->isClaimHandler());
    }

    public function test_request_approval_requires_ceo_and_overdue_reason_and_sends_button_link(): void
    {
        $this->fakeErp();
        Setting::updateOrCreate(['key' => 'alimtalk_enabled'], ['value' => '1', 'type' => 'boolean']);
        Setting::updateOrCreate(['key' => 'alimtalk_userid'], ['value' => 'uid', 'type' => 'string']);
        Setting::updateOrCreate(['key' => 'alimtalk_profile'], ['value' => '@p', 'type' => 'string']);
        Setting::updateOrCreate(['key' => 'alimtalk_tmpl_board_claim_approval_request'], ['value' => 'TMPL_REQ', 'type' => 'string']);
        Setting::updateOrCreate(['key' => 'alimtalk_toggle_board_claim_approval_request'], ['value' => '1', 'type' => 'boolean']);   // 기본 OFF → 켜고 시험
        $sales = $this->mk('김영업');
        $h = $this->handler();
        $c = $this->intake($sales, null, ['arrived_at' => now()->subDays(20)->format('Y-m-d')]);
        $svc = app(ClaimService::class);

        $ceo = $this->ceo();
        try {
            $svc->requestApproval($c, $h, ['대표' => $ceo->id]);
            $this->fail('overdue expected');
        } catch (\DomainException $e) {
            $this->assertSame('overdue_reason_required', $e->getMessage());   // 기한 초과 사유가 먼저
        }
        $c->forceFill(['overdue_reason' => '바이어 요청·귀책 가능성 있음'])->save();
        try {
            $svc->requestApproval($c, $h, []);   // 결재선 비움
            $this->fail('no_line expected');
        } catch (\DomainException $e) {
            $this->assertSame('no_line', $e->getMessage());
        }
        try {
            $svc->requestApproval($c, $h, ['부장' => $ceo->id]);   // 대표를 부장 칸에 — 그 직급 사람이 아님
            $this->fail('bad_line expected');
        } catch (\DomainException $e) {
            $this->assertSame('bad_line', $e->getMessage());
        }

        $c->forceFill(['claim_amount' => 1000, 'claim_currency' => 'EUR'])->save();
        $svc->requestApproval($c->fresh(), $h, ['대표' => $ceo->id]);
        $c->refresh();
        $this->assertSame(Claim::PENDING_CEO, $c->status);
        $this->assertSame(1, $c->revision);
        $this->assertSame($ceo->id, $c->approver_user_id);
        $this->assertNotNull($c->request_notified_at);

        // 알림톡 ① — 버튼 1개, URL 은 APP_URL + /a/claim/{id} 접두(등록본과 같아야 K108 이 안 난다), 본문에 자리표시자 없음
        $log = AlimtalkLog::where('template_code', 'board_claim_approval_request')->firstOrFail();
        $this->assertSame('sent', $log->status);
        $this->assertStringNotContainsString('#{', $log->message);
        $this->assertStringContainsString('EUR 1,000', $log->message);
        Http::assertSent(function ($req) use ($c) {
            if ($req->url() !== self::BIZM) {
                return false;
            }
            $item = $req->data()[0];

            return isset($item['button1']) && $item['button1']['type'] === 'WL'
                && str_starts_with($item['button1']['url_mobile'], rtrim(config('app.url'), '/').'/a/claim/'.$c->id)
                && $item['button1']['name'] === '승인/반려 바로가기';
        });
        $this->assertDatabaseHas('board_audit_logs', ['action' => 'claim', 'field' => 'claim_request']);

        // 요청 중엔 재요청 불가 · 10분 연타 방지
        $this->assertFalse($c->canRequestApproval());
        $this->assertFalse($c->canResend());
        Carbon::setTestNow(now()->addMinutes(11));
        $this->assertTrue($c->fresh()->canResend());
    }

    // ── 대표 링크 ──

    private function pending(): array
    {
        $this->fakeErp();
        $sales = $this->mk('김영업', 'sales', 'user', ['phone' => '010-3333-4444']);
        $h = $this->handler();
        $h->forceFill(['phone' => '010-5555-6666'])->save();
        $ceo = $this->ceo();
        $c = $this->intake($sales);
        app(ClaimService::class)->requestApproval($c, $h, ['대표' => $ceo->id]);

        return [$c->fresh(), $sales, $h, $ceo];
    }

    private function decideUrl(Claim $c, User $ceo, ?int $rev = null): string
    {
        return URL::temporarySignedRoute('claim.approve.decide', now()->addMinutes(60), ['claim' => $c->id, 'u' => $ceo->id, 'rev' => $rev ?? $c->revision]);
    }

    public function test_ceo_link_shows_and_decides_once(): void
    {
        [$c, $sales, $h, $ceo] = $this->pending();
        Setting::updateOrCreate(['key' => 'alimtalk_enabled'], ['value' => '1', 'type' => 'boolean']);
        Setting::updateOrCreate(['key' => 'alimtalk_userid'], ['value' => 'uid', 'type' => 'string']);
        Setting::updateOrCreate(['key' => 'alimtalk_profile'], ['value' => '@p', 'type' => 'string']);
        Setting::updateOrCreate(['key' => 'alimtalk_tmpl_board_claim_decision'], ['value' => 'TMPL_DEC', 'type' => 'string']);
        Setting::updateOrCreate(['key' => 'alimtalk_toggle_board_claim_decision'], ['value' => '1', 'type' => 'boolean']);

        $link = $c->approvalLinkFor($ceo);
        $this->assertStringStartsWith(rtrim(config('app.url'), '/').'/a/claim/'.$c->id, $link);
        $this->get($link)->assertOk()->assertSee('CLAIM REPORT')->assertSee('12가3456')->assertSee(__('claims.approve'));
        $this->get(route('claim.approve.show', ['claim' => $c->id, 'u' => $ceo->id, 'rev' => 1]))->assertForbidden();   // 서명 없음

        // GET 은 상태를 안 바꾼다
        $this->assertSame(Claim::PENDING_CEO, $c->fresh()->status);

        // 반려 — 사유 없으면 422(상태 불변)
        $this->post($this->decideUrl($c, $ceo), ['action' => 'reject', 'reason' => ''])->assertStatus(422);
        $this->assertSame(Claim::PENDING_CEO, $c->fresh()->status);

        // 승인
        $this->post($this->decideUrl($c, $ceo), ['action' => 'approve', 'reason' => '진행'])->assertOk()->assertSee(__('claims.ceo_done_approved'));
        $c->refresh();
        $this->assertSame(Claim::APPROVED, $c->status);
        $this->assertSame('approved', $c->decision);
        $this->assertNotNull($c->decided_at);
        $this->assertDatabaseHas('board_audit_logs', ['action' => 'claim', 'field' => 'claim_decision', 'user_id' => $ceo->id]);

        // 알림톡 ② — 담당자 + 영업 2명, 결과/사유 치환
        $logs = AlimtalkLog::where('template_code', 'board_claim_decision')->get();
        $this->assertCount(2, $logs);
        $this->assertEqualsCanonicalizing(['01033334444', '01055556666'], $logs->pluck('phone')->all());
        $this->assertStringContainsString('■ 결과: 승인', $logs[0]->message);
        $this->assertStringContainsString('■ 사유: 진행', $logs[0]->message);
        $this->assertStringNotContainsString('#{', $logs[0]->message);

        // 두 번째 클릭 = 410, 링크 재진입 = 결과만
        $this->post($this->decideUrl($c, $ceo), ['action' => 'reject', 'reason' => 'x'])->assertStatus(410);
        $this->get($link)->assertOk()->assertSee(__('claims.ceo_already', ['result' => __('claims.status.approved'), 'at' => $c->decided_at->timezone(config('app.timezone'))->format('Y-m-d H:i')]));
        $this->assertSame(Claim::APPROVED, $c->fresh()->status);
    }

    public function test_reject_then_rerequest_invalidates_old_link(): void
    {
        [$c, $sales, $h, $ceo] = $this->pending();
        $this->post($this->decideUrl($c, $ceo), ['action' => 'reject', 'reason' => '증빙 부족'])->assertOk();
        $c->refresh();
        $this->assertSame(Claim::REJECTED, $c->status);
        $this->assertSame('증빙 부족', $c->decision_reason);

        // 담당자 수정 뒤 재요청 → 회차 2, 옛 회차 링크는 410
        $this->assertTrue($c->canRequestApproval());
        app(ClaimService::class)->requestApproval($c, $h, ['대표' => $ceo->id]);
        $c->refresh();
        $this->assertSame(2, $c->revision);
        $this->assertSame(Claim::PENDING_CEO, $c->status);
        $this->assertNull($c->decision);
        $this->post($this->decideUrl($c, $ceo, 1), ['action' => 'approve'])->assertStatus(410);
        $this->assertSame(Claim::PENDING_CEO, $c->fresh()->status);
        $this->post($this->decideUrl($c, $ceo, 2), ['action' => 'approve'])->assertOk();
        $this->assertSame(Claim::APPROVED, $c->fresh()->status);
    }

    public function test_approved_can_be_reapproved_or_closed_and_closed_is_final(): void
    {
        [$c, $sales, $h, $ceo] = $this->pending();
        $this->post($this->decideUrl($c, $ceo), ['action' => 'approve'])->assertOk();
        $c->refresh();

        // 합의액 변경 → 재승인(회차 2)
        $this->assertTrue($c->canRequestApproval());
        app(ClaimService::class)->requestApproval($c, $h, ['대표' => $ceo->id]);
        $this->assertSame(2, $c->fresh()->revision);
        $this->post($this->decideUrl($c->fresh(), $ceo, 2), ['action' => 'approve'])->assertOk();

        app(ClaimService::class)->close($c->fresh(), $h);
        $c->refresh();
        $this->assertSame(Claim::CLOSED, $c->status);
        $this->assertFalse($c->canRequestApproval());
        $this->expectException(\DomainException::class);
        $c->status = Claim::PENDING_CEO;
        $c->save();
    }

    // ── 결재트리(2026-11-11 Jin) ──

    /** 부장 → 상무 → 대표 차례로 — 중간 승인은 다음 칸으로(요청 알림톡이 다음 사람에게), 대표 승인 = 최종. 차례 아닌 사람 링크 = 410. */
    public function test_approval_line_runs_in_order_and_notifies_each_turn(): void
    {
        $this->fakeErp();
        foreach (['alimtalk_enabled' => '1', 'alimtalk_toggle_board_claim_approval_request' => '1'] as $k => $v) {
            Setting::updateOrCreate(['key' => $k], ['value' => $v, 'type' => 'boolean']);
        }
        foreach (['alimtalk_userid' => 'uid', 'alimtalk_profile' => '@p', 'alimtalk_tmpl_board_claim_approval_request' => 'T'] as $k => $v) {
            Setting::updateOrCreate(['key' => $k], ['value' => $v, 'type' => 'string']);
        }
        $sales = $this->mk('김영업');
        $h = $this->handler();
        $bujang = $this->mk('임부장', 'manager', 'admin', ['approval_title' => '부장', 'phone' => '010-7777-0001']);
        $sangmu = $this->mk('오상무', 'manager', 'admin', ['approval_title' => '상무', 'phone' => '010-7777-0002']);
        $ceo = $this->ceo('010-7777-0003');
        $c = $this->intake($sales);
        app(ClaimService::class)->requestApproval($c, $h, ['부장' => $bujang->id, '상무' => $sangmu->id, '대표' => $ceo->id]);
        $c->refresh();
        $this->assertSame(1, $c->current_step);
        $this->assertSame($bujang->id, $c->approver_user_id);
        $this->assertSame(['01077770001'], AlimtalkLog::where('template_code', 'board_claim_approval_request')->pluck('phone')->all());

        // 대표 링크로 지금(부장 차례) 결정 시도 = 410, 상태 불변
        $this->post($this->decideUrl($c, $ceo), ['action' => 'approve'])->assertStatus(410);
        $this->get($c->approvalLinkFor($ceo))->assertOk()->assertSee(__('claims.link_not_your_turn', ['title' => '부장', 'name' => '임부장']));

        // 부장 승인 → 상무 차례(최종 아님 · 결과 알림 없음)
        $this->post($this->decideUrl($c, $bujang), ['action' => 'approve', 'reason' => '확인'])->assertOk()
            ->assertSee(__('claims.passed_to_next', ['title' => '상무', 'name' => '오상무']));
        $c->refresh();
        $this->assertSame(Claim::PENDING_CEO, $c->status);
        $this->assertSame(2, $c->current_step);
        $this->assertSame($sangmu->id, $c->approver_user_id);
        $this->assertSame(0, AlimtalkLog::where('template_code', 'board_claim_decision')->count());
        $this->assertContains('01077770002', AlimtalkLog::where('template_code', 'board_claim_approval_request')->pluck('phone')->all());
        // 같은 부장 링크 다시 = 410(이미 지나간 차례)
        $this->post($this->decideUrl($c, $bujang), ['action' => 'approve'])->assertStatus(410);

        $this->post($this->decideUrl($c, $sangmu), ['action' => 'approve'])->assertOk();
        $this->post($this->decideUrl($c->fresh(), $ceo), ['action' => 'approve'])->assertOk();
        $c->refresh();
        $this->assertSame(Claim::APPROVED, $c->status);
        $this->assertSame($ceo->id, $c->approver_user_id);
        $this->assertSame(['approved', 'approved', 'approved'], $c->lineSteps()->pluck('status')->all());
        $this->assertSame(3, BoardAuditLog::where('field', 'claim_decision')->count());
    }

    /** 대표는 필수 아님 — 상무에서 끝나면 상무가 최종. 반려 = 그 자리에서 종료 → 재요청은 1단계부터. */
    public function test_line_without_ceo_ends_at_last_step_and_reject_restarts(): void
    {
        $this->fakeErp();
        $sales = $this->mk('김영업');
        $h = $this->handler();
        $bujang = $this->mk('임부장', 'manager', 'admin', ['approval_title' => '부장']);
        $sangmu = $this->mk('오상무', 'manager', 'admin', ['approval_title' => '상무']);
        $c = $this->intake($sales);
        app(ClaimService::class)->requestApproval($c, $h, ['부장' => $bujang->id, '상무' => $sangmu->id]);
        $c->refresh();
        $this->post($this->decideUrl($c, $bujang), ['action' => 'approve'])->assertOk();
        $this->post($this->decideUrl($c->fresh(), $sangmu), ['action' => 'reject', 'reason' => '증빙 부족'])->assertOk();
        $c->refresh();
        $this->assertSame(Claim::REJECTED, $c->status);
        $this->assertSame('증빙 부족', $c->decision_reason);

        app(ClaimService::class)->requestApproval($c, $h, ['부장' => $bujang->id, '상무' => $sangmu->id]);
        $c->refresh();
        $this->assertSame(2, $c->revision);
        $this->assertSame(1, $c->current_step);   // 이미 승인했던 부장부터 다시
        $this->assertSame($bujang->id, $c->approver_user_id);
        $this->post($this->decideUrl($c, $bujang), ['action' => 'approve'])->assertOk();
        $this->post($this->decideUrl($c->fresh(), $sangmu), ['action' => 'approve'])->assertOk();
        $this->assertSame(Claim::APPROVED, $c->fresh()->status);   // 상무 = 최종
    }

    /** super = 어느 단계든 결정, 승인하면 남은 칸과 무관하게 즉시 최종(대행 표시). */
    public function test_super_can_decide_any_step_and_approval_is_final(): void
    {
        $this->fakeErp();
        $sales = $this->mk('김영업');
        $h = $this->handler();
        $bujang = $this->mk('임부장', 'manager', 'admin', ['approval_title' => '부장']);
        $ceo = $this->ceo();
        $super = $this->mk('시스템관리자', 'manager', 'super');
        $c = $this->intake($sales);
        app(ClaimService::class)->requestApproval($c, $h, ['부장' => $bujang->id, '대표' => $ceo->id]);
        $c->refresh();
        $this->assertTrue($c->canDecide($super, 1));
        $this->get($c->approvalLinkFor($super))->assertOk()->assertSee(__('claims.proxy_banner'));
        $this->post($this->decideUrl($c, $super), ['action' => 'approve'])->assertOk();
        $c->refresh();
        $this->assertSame(Claim::APPROVED, $c->status);
        $step = $c->lineSteps()->first();
        $this->assertSame($super->id, $step->acted_by_user_id);
        $this->assertTrue($step->byProxy());
        $this->assertStringContainsString(__('claims.proxy_note'), (string) $step->note);
        $this->assertSame('pending', $c->lineSteps()->last()->status);   // 대표 칸은 손대지 않은 채 최종
    }

    /** [결재 요청] = 모달 — 열면 기본 결재선, 비우고 보내면 모달에 사유(닫히지 않음), 고르고 보내면 결재 중 + 모달 닫힘. */
    public function test_request_modal_flow_from_drawer(): void
    {
        $this->fakeErp();
        $sales = $this->mk('김영업');
        $h = $this->handler();
        $ceo = $this->ceo();
        $c = $this->intake($sales);
        $this->actingAs($h);
        $t = Volt::test('claims.index')->call('openDetail', $c->id)->call('openLineModal')
            ->assertSet('lineModal', true)->assertSet('line.대표', (string) $ceo->id)
            ->set('line.대표', '')->call('requestApproval')
            ->assertSet('lineModal', true)->assertSet('lineError', __('claims.err_no_line'));
        $this->assertSame(Claim::REVIEWING, $c->fresh()->status);   // 칸 저장은 됐고 요청은 안 됨
        $t->set('line.대표', (string) $ceo->id)->call('requestApproval')->assertSet('lineModal', false);
        $this->assertSame(Claim::PENDING_CEO, $c->fresh()->status);
        $this->assertSame($ceo->id, $c->fresh()->approver_user_id);
    }

    public function test_handler_drawer_saves_report_fields_with_audit_and_masks_bank(): void
    {
        [$c, $sales, $h, $ceo] = [null, null, null, null];
        $this->fakeErp();
        $sales = $this->mk('김영업');
        $h = $this->handler();
        $c = $this->intake($sales);
        $this->actingAs($h);
        Volt::test('claims.index')->call('openDetail', $c->id)
            ->set('form.claim_amount', '1500')->set('form.refundable_amount', '750')->set('form.cs_proposed_amount', '600')->set('form.claim_currency', 'EUR')
            ->set('form.result', '부품비 50% 환급')->set('form.compensation_type', 'credit')->set('form.bank_iban', 'AL35202111090000000001234567')
            ->call('save')->assertHasNoErrors()->assertSet('notice', __('claims.saved'));
        $c->refresh();
        $this->assertSame(Claim::REVIEWING, $c->status);
        $this->assertSame('EUR', $c->claim_currency);
        $this->assertEquals(50.0, $c->refundRate());
        $this->assertEquals(40.0, $c->csRate());
        $this->assertSame('EUR 600', $c->amountDisplay('cs_proposed_amount'));
        $this->assertDatabaseHas('board_audit_logs', ['field' => 'claim_cs_proposed_amount']);
        $this->assertSame('AL35202111090000000001234567', $c->bank_iban);
        $this->assertDatabaseHas('board_audit_logs', ['field' => 'claim_bank_iban', 'new_value' => '#'.$c->id.' ***']);
        $this->assertDatabaseHas('board_audit_logs', ['field' => 'claim_claim_amount']);
        $this->assertDatabaseHas('board_audit_logs', ['field' => 'claim_status', 'old_value' => 'received']);
        $this->assertDatabaseMissing('board_audit_logs', ['new_value' => '#'.$c->id.' AL35202111090000000001234567']);
    }

    public function test_bell_counts_new_claim_for_handler_and_decision_for_salesman(): void
    {
        [$c, $sales, $h, $ceo] = $this->pending();
        $this->actingAs($h);
        $h->forceFill(['portal_seen_at' => now()->subHour()])->save();
        // 승인 요청 중(pending_ceo)은 「새 접수」가 아니다 → 0. 접수 직후 상태로 되돌려 센다.
        $c->forceFill(['status' => Claim::RECEIVED])->saveQuietly();
        $bell = Volt::test('notify.bell')->call('refresh');
        $this->assertSame(1, $bell->get('unread'));

        $c->forceFill(['status' => Claim::PENDING_CEO])->saveQuietly();
        $this->post($this->decideUrl($c->fresh(), $ceo), ['action' => 'reject', 'reason' => '사유'])->assertOk();
        $this->actingAs($sales);
        $sales->forceFill(['portal_seen_at' => now()->subHour()])->save();
        $bell = Volt::test('notify.bell')->call('refresh');
        $this->assertSame(1, $bell->get('unread'));
        $bell->call('openPanel');
        $this->assertSame('rejected', $bell->get('claims')[0]['kind']);
    }

    /** ssancar INSPECTED 링크 — 공백·모델명 섞인 번호에서 번호판 부분만, 미설정이면 null(2026-11-09 ssancar 실측·Jin 결정). */
    public function test_ssancar_inspected_url_uses_plate_part_without_spaces(): void
    {
        config(['services.ssancar_media.base_url' => 'https://www.ssancar.com/page/api_car_media.php']);
        $this->assertSame('https://www.ssancar.com/bbs/board.php?bo_table=inspected&no='.rawurlencode('16부5626'), Claim::ssancarInspectedUrl('i30 16부 5626'));
        $this->assertSame('https://www.ssancar.com/bbs/board.php?bo_table=inspected&no='.rawurlencode('123가4567'), Claim::ssancarInspectedUrl('123가 4567'));
        config(['services.ssancar_media.base_url' => '']);
        $this->assertNull(Claim::ssancarInspectedUrl('12가3456'));
    }

    /** 로컬 클레임 씬용 가짜 판매 행(BOARD_PORTAL_FAKE_SALES)은 local 이 아니면 무시된다(테스트 환경 = testing). */
    /** 클레임 알림톡 2종은 기본 OFF — 마스터·tmplId 가 있어도 개별 토글을 안 켜면 skipped(2026-11-11 Jin). 기존 2종은 기본 ON 유지. */
    public function test_claim_alimtalk_toggles_default_off(): void
    {
        Setting::updateOrCreate(['key' => 'alimtalk_enabled'], ['value' => '1', 'type' => 'boolean']);
        Setting::updateOrCreate(['key' => 'alimtalk_userid'], ['value' => 'uid', 'type' => 'string']);
        Setting::updateOrCreate(['key' => 'alimtalk_profile'], ['value' => '@p', 'type' => 'string']);
        foreach (['board_claim_approval_request', 'board_claim_decision', 'board_forward_ready'] as $code) {
            Setting::updateOrCreate(['key' => "alimtalk_tmpl_{$code}"], ['value' => 'T', 'type' => 'string']);
        }
        $cfg = AlimtalkConfig::active();
        $this->assertFalse($cfg->canSend('board_claim_approval_request'));
        $this->assertFalse($cfg->canSend('board_claim_decision'));
        $this->assertTrue($cfg->canSend('board_forward_ready'));
    }

    public function test_fake_sales_fixture_is_ignored_outside_local_env(): void
    {
        $this->fakeErp();
        config(['board.portal_fake_sales' => 'database/dev/claim-demo-sales.json']);
        $rows = (array) data_get(app(CarErpReadService::class)->sales('hwang@car-erp.com')['data'], 'data', []);
        $this->assertFalse(collect($rows)->contains('vehicle_number', '13라2053'));
    }

    public function test_portal_sales_row_shows_claim_chip_and_intake_button(): void
    {
        $this->fakeErp();
        $sales = $this->mk('김영업', 'sales', 'user', ['car_erp_salesman_email' => 'kim@ce.test']);
        $this->actingAs($sales);
        $t = Volt::test('portal.index')->call('setTab', 'sales')->set('vehicleSearch', '12가 3456');
        $t->assertSee(__('claims.intake_btn'));
        $this->intake($sales);
        Volt::test('portal.index')->call('setTab', 'sales')->set('vehicleSearch', 'VIN55')->assertSee(__('claims.status.received'))->assertDontSee('34나5678');
    }
}

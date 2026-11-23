<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\AlimtalkRecipients;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 알림톡 안내(2026-11-23) — 추가 수신자 체크 화면과 해석 규칙. 클레임 발송 지점 연결은 ClaimsTest 쪽.
 */
class AlimtalkCatalogTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function mk(string $name, string $role = 'sales', string $permission = 'user', array $extra = []): User
    {
        return User::create(array_merge([
            'name' => $name, 'email' => 'c'.(++$this->seq).'@t.test', 'password' => 'password',
            'role' => $role, 'permission' => $permission, 'is_active' => true, 'email_verified_at' => now(),
        ], $extra));
    }

    public function test_only_super_can_open(): void
    {
        $this->actingAs($this->mk('업무관리자', 'manager', 'manager'))->get(route('admin.alimtalk-catalog'))->assertForbidden();
        $this->actingAs($this->mk('슈퍼', 'manager', 'super'))->get(route('admin.alimtalk-catalog'))
            ->assertOk()->assertSee(__('alimtalk_catalog.notice.board_claim_erp_sent'))->assertSee(__('alimtalk_catalog.not_pickable'));
    }

    public function test_pick_by_role_and_by_person_saves_tokens_and_audits(): void
    {
        $super = $this->mk('슈퍼', 'manager', 'super');
        $a = $this->mk('김영업', 'sales', 'user', ['phone' => '010-1000-0001']);
        $b = $this->mk('이영업', 'sales', 'user', ['phone' => '010-1000-0002']);
        $m = $this->mk('박관리', 'manager', 'user');   // 번호 없음
        $notice = AlimtalkRecipients::CLAIM_ERP_SENT;

        $this->actingAs($super);
        $t = Volt::test('admin.alimtalk-catalog')
            ->call('toggleMember', $notice, 'role_sales', $a->id)
            ->call('toggleMember', $notice, 'role_sales', $m->id)   // 그 역할 사람이 아님 → 무시
            ->call('toggleGroup', $notice, 'role_manager')
            ->call('toggleContext', $notice, 'approval_line')
            ->assertSee(__('alimtalk_catalog.unsaved'));
        $this->assertSame([], AlimtalkRecipients::selected($notice));   // 저장 전엔 적용 안 됨

        $t->call('save', $notice)->assertSee(__('alimtalk_catalog.saved'));
        $this->assertEqualsCanonicalizing(["user:{$a->id}", 'role_manager', 'approval_line'], AlimtalkRecipients::selected($notice));
        $this->assertDatabaseHas('board_audit_logs', ['action' => 'setting_change', 'field' => 'alimtalk_recipients', 'user_id' => $super->id]);

        // 받는 사람 = 번호 있는 사람만(박관리 제외), 결재선은 넘겨준 사람만
        $this->assertSame([$a->id], AlimtalkRecipients::extras($notice)->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$a->id, $b->id], AlimtalkRecipients::extras($notice, [], [$b])->pluck('id')->all());
        // 이미 받는 번호와 겹치면 뺀다(하이픈 유무 무관)
        $this->assertSame([], AlimtalkRecipients::extras($notice, ['01010000001'])->pluck('id')->all());

        // 역할째 켜면 그 역할의 개별 지정은 지워진다
        $t->call('toggleGroup', $notice, 'role_sales')->call('save', $notice);
        $this->assertEqualsCanonicalizing(['role_sales', 'role_manager', 'approval_line'], AlimtalkRecipients::selected($notice));
        $this->assertEqualsCanonicalizing([$a->id, $b->id], AlimtalkRecipients::extras($notice)->pluck('id')->all());
    }

    public function test_signed_link_notices_and_bad_tokens_are_refused(): void
    {
        $this->actingAs($this->mk('슈퍼', 'manager', 'super'));
        Volt::test('admin.alimtalk-catalog')
            ->call('toggleGroup', 'board_claim_approval_request', 'role_sales')->call('save', 'board_claim_approval_request')
            ->call('toggleContext', 'board_transport_result', 'approval_line')   // 결재선은 클레임 알림에만
            ->set('roles.board_transport_result', ['user:99999', '01012345678', 'nope', 'admin', 'ctx_handler'])->call('save', 'board_transport_result');
        $this->assertNull(Setting::where('key', 'alimtalk_roles_board_claim_approval_request')->first());
        $this->assertSame(['admin'], AlimtalkRecipients::selected('board_transport_result'));
        // 설정이 저장돼 있어도 서명 링크 알림은 추가 수신자가 없다
        Setting::updateOrCreate(['key' => 'alimtalk_roles_board_transport_request'], ['value' => 'role_sales', 'type' => 'string']);
        $this->assertSame([], AlimtalkRecipients::selected('board_transport_request'));
    }

    public function test_party_rows_are_checked_by_default_and_can_be_removed(): void
    {
        $this->actingAs($this->mk('슈퍼', 'manager', 'super'));
        // 설정 행이 없으면 종전 발송 대상 그대로 — 당사자 줄만 체크, ERP 전송 완료는 아무도 없음
        $this->assertSame(['ctx_handler', 'ctx_sales'], AlimtalkRecipients::selected('board_claim_decision'));
        $this->assertSame(['ctx_sales', 'ctx_manager'], AlimtalkRecipients::selected('board_transport_result'));
        $this->assertSame(['ctx_creator'], AlimtalkRecipients::selected('board_forward_ready'));
        $this->assertSame(['ctx_inspector'], AlimtalkRecipients::selected('board_region_inspection'));
        $this->assertSame([], AlimtalkRecipients::selected(AlimtalkRecipients::CLAIM_ERP_SENT));

        $t = Volt::test('admin.alimtalk-catalog')->assertSee(__('alimtalk_catalog.ctx.handler'))
            ->assertSet('roles.board_claim_decision', ['ctx_handler', 'ctx_sales'])
            ->call('toggleContext', 'board_claim_decision', 'ctx_sales')->call('save', 'board_claim_decision');
        $this->assertSame(['ctx_handler'], AlimtalkRecipients::selected('board_claim_decision'));
        $this->assertFalse(AlimtalkRecipients::wants('board_claim_decision', 'ctx_sales'));

        // 전부 끄고 저장 = 아무도 안 받음(기본값으로 되돌아가지 않는다)
        $t->call('toggleContext', 'board_claim_decision', 'ctx_handler')->call('save', 'board_claim_decision');
        $this->assertSame('none', Setting::where('key', 'alimtalk_roles_board_claim_decision')->value('value'));
        $this->assertSame([], AlimtalkRecipients::selected('board_claim_decision'));
    }
}

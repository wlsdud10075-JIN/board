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
            ->call('toggleLine', $notice)
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
            ->call('toggleLine', 'board_transport_result')   // 결재선은 클레임 알림에만
            ->set('roles.board_transport_result', ['user:99999', '01012345678', 'nope', 'admin'])->call('save', 'board_transport_result');
        $this->assertNull(Setting::where('key', 'alimtalk_roles_board_claim_approval_request')->first());
        $this->assertSame(['admin'], AlimtalkRecipients::selected('board_transport_result'));
        // 설정이 저장돼 있어도 서명 링크 알림은 추가 수신자가 없다
        Setting::updateOrCreate(['key' => 'alimtalk_roles_board_transport_request'], ['value' => 'role_sales', 'type' => 'string']);
        $this->assertSame([], AlimtalkRecipients::selected('board_transport_request'));
    }
}

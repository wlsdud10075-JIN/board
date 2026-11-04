<?php

namespace Tests\Feature;

use App\Models\BoardAuditLog;
use App\Models\PurchaseListing;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkJournal;
use App\Models\WorkJournalBatch;
use App\Models\WorkJournalComment;
use App\Services\WorkJournalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** 업무일지 + 업무일지결재(2026-11-04 Jin, 정본 = meetings/plan-work-journal-2026-11.md). */
class WorkJournalTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function mk(string $name, string $permission = 'user', ?string $title = null, string $role = 'sales'): User
    {
        return User::create([
            'name' => $name, 'email' => 'u'.(++$this->seq).'@t.test', 'password' => 'password',
            'role' => $role, 'permission' => $permission, 'approval_title' => $title, 'is_active' => true,
        ]);
    }

    /** 결재선 4명 — 팀장(업무관리자)·부장·상무·대표(최고관리자). */
    private function line(bool $withBujang = true): array
    {
        return [
            'lead' => $this->mk('정팀장', 'manager', '팀장', 'manager'),
            'bujang' => $withBujang ? $this->mk('임부장', 'admin', '부장', 'manager') : null,
            'sangmu' => $this->mk('오상무', 'admin', '상무', 'manager'),
            'ceo' => $this->mk('홍대표', 'admin', '대표', 'manager'),
        ];
    }

    /** 작성자가 줄을 쓰고 상신까지. */
    private function writeAndSubmit(User $w, string $task = '딜러 통화'): WorkJournal
    {
        $this->actingAs($w);
        Volt::test('work-journal.index')->call('addRow')->set('rows.0.task', $task)->call('submit')->assertHasNoErrors();

        return WorkJournal::where('user_id', $w->id)->firstOrFail();
    }

    private function approveAs(User $u, WorkJournalBatch $b, array $opinions = [], string $overall = ''): void
    {
        $this->actingAs($u);
        $c = Volt::test('work-journal.approvals')->call('pick', $b->id);
        foreach ($opinions as $jid => $t) {
            $c->set('opinions.'.$jid, $t);
        }
        $c->set('overall', $overall)->call('approve')->assertHasNoErrors();
    }

    public function test_only_plain_users_write_and_approvers_or_managers_see_approvals(): void
    {
        $line = $this->line();
        $writer = $this->mk('김영업');
        $this->actingAs($writer)->get('/work-journal')->assertOk()->assertSee(__('nav.menu.work_journal'));
        $this->actingAs($writer)->get('/work-journal/approvals')->assertForbidden();
        $this->actingAs($line['lead'])->get('/work-journal')->assertForbidden();
        $this->actingAs($line['lead'])->get('/work-journal/approvals')->assertOk();
        $this->actingAs($this->mk('백업무', 'manager'))->get('/work-journal/approvals')->assertOk();   // 관리자 등급은 조회
        $this->actingAs($this->mk('root', 'super'))->get('/work-journal')->assertForbidden();
    }

    /** 줄 추가 = 지금 시각, 칸마다 자동 저장, 첫 저장만 감사 1행. */
    public function test_add_row_stamps_now_and_autosaves(): void
    {
        Carbon::setTestNow('2026-11-05 10:42:00');
        $w = $this->mk('김영업');
        $this->actingAs($w);
        $c = Volt::test('work-journal.index')->call('addRow')->assertSet('rows.0.time', '10:42')
            ->set('rows.0.task', '매입예정 확인')->set('rows.0.time', '09:30')->set('rows.0.note', '딜러 지연');
        $e = WorkJournal::where('user_id', $w->id)->firstOrFail()->entries()->first();
        $this->assertSame(['09:30', '매입예정 확인', '딜러 지연'], [$e->time, $e->task, $e->note]);
        $this->assertNotNull($c->get('savedAt'));
        $this->assertSame(1, BoardAuditLog::where('action', 'work_journal_write')->count());

        // 다시 열어도 그대로(다른 일을 하다 와도 유지)
        Volt::test('work-journal.index')->assertSet('rows.0.task', '매입예정 확인');
    }

    /** 상신 = snapshot 고정(표 + board 활동) + 그날 묶음 + 결재선(빈 직급 건너뜀). 상신 뒤 수정은 서버가 거절. */
    public function test_submit_snapshots_and_builds_line_skipping_vacant(): void
    {
        $line = $this->line(withBujang: false);
        $w = $this->mk('김영업');
        $this->actingAs($w);
        $l = PurchaseListing::create(['created_by_user_id' => $w->id, 'source' => 'encar', 'vehicle_number' => '12가3456', 'vin' => 'VINWJ00001', 'status' => 'draft', 'buyer_verdict' => 'none']);   // 감사 create 행(행위자 = 로그인)
        $j = $this->writeAndSubmit($w);

        $this->assertSame('submitted', $j->status);
        $this->assertSame('딜러 통화', $j->snapshot['entries'][0]['task']);
        $this->assertSame('12가3456', $j->snapshot['activities'][0]['plate']);
        $this->assertStringContainsString(__('journal.act.create'), $j->snapshot['activities'][0]['what']);
        $b = $j->batch()->with('steps')->first();
        $this->assertSame(['pending', 'skipped', 'pending', 'pending'], $b->steps->pluck('status')->all());
        $this->assertSame([$line['lead']->id, null], [$b->steps[0]->approver_user_id, $b->steps[1]->approver_user_id]);
        $this->assertSame(1, $b->current_step);
        $this->assertTrue(BoardAuditLog::where('action', 'work_journal_step_skipped')->exists());

        // 잠김 — 프로퍼티를 밀어넣어도 저장 안 됨
        Volt::test('work-journal.index')->set('rows.0.task', '고침')->call('addRow');
        $this->assertSame(1, $j->entries()->count());
        $this->assertSame('딜러 통화', $j->entries()->first()->task);
    }

    public function test_submit_blocked_without_ceo_and_empty(): void
    {
        $this->mk('정팀장', 'manager', '팀장', 'manager');
        $w = $this->mk('김영업');
        $this->actingAs($w);
        Volt::test('work-journal.index')->call('submit')->assertHasErrors('rows');   // 빈 일지
        Volt::test('work-journal.index')->call('addRow')->set('rows.0.task', 'x')->call('submit')->assertHasErrors('rows');   // 대표 없음
        $this->assertSame('draft', WorkJournal::first()->status);
    }

    /** 회수 — 팀장 승인 전까지만, 비면 묶음 삭제. 팀장 승인 뒤 늦게 상신 = 추가 기안(seq 2). */
    public function test_recall_before_approval_and_late_submit_creates_extra_batch(): void
    {
        $line = $this->line();
        $a = $this->mk('김영업');
        $b = $this->mk('이영업');
        $ja = $this->writeAndSubmit($a);
        Volt::test('work-journal.index')->call('recall')->assertHasNoErrors();
        $this->assertSame('draft', $ja->fresh()->status);
        $this->assertSame(0, WorkJournalBatch::count());

        $ja = $this->writeAndSubmit($a, '다시');
        $this->approveAs($line['lead'], $ja->batch);
        $this->actingAs($a);
        Volt::test('work-journal.index')->call('recall')->assertHasErrors('rows');   // 팀장이 승인함 — 회수 불가

        $jb = $this->writeAndSubmit($b);
        $this->assertNotSame($ja->batch_id, $jb->batch_id);
        $this->assertSame([1, 2], WorkJournalBatch::orderBy('seq')->pluck('seq')->all());
    }

    /** 결재 — 차례인 사람만, 인원별·전체 의견, 다음 단계로, 마지막이면 전원 approved. 작성자는 나에게 + 전체 의견만 본다. */
    public function test_approval_chain_with_comments_and_final(): void
    {
        $line = $this->line();
        $a = $this->mk('김영업');
        $b = $this->mk('이영업');
        $ja = $this->writeAndSubmit($a);
        $jb = $this->writeAndSubmit($b);
        $batch = $ja->fresh()->batch;
        $this->assertSame($batch->id, $jb->batch_id);   // 같은 날 = 같은 기안

        // 차례 아닌 사람(상무)은 승인 못 함
        $this->actingAs($line['sangmu']);
        Volt::test('work-journal.approvals')->call('pick', $batch->id)->call('approve')->assertHasErrors('approve');

        $this->approveAs($line['lead'], $batch, [$ja->id => '김영업 잘했음', $jb->id => '이영업 확인'], '전체 양호');
        $this->assertSame(2, $batch->fresh()->current_step);
        $this->approveAs($line['bujang'], $batch);
        $this->approveAs($line['sangmu'], $batch, [], '확인');
        $this->approveAs($line['ceo'], $batch);

        $batch->refresh();
        $this->assertSame(['approved', null], [$batch->status, $batch->current_step]);
        $this->assertSame(['approved', 'approved'], [$ja->fresh()->status, $jb->fresh()->status]);
        $this->assertSame(4, BoardAuditLog::where('action', 'work_journal_approve')->count());
        $this->assertSame(4, WorkJournalComment::count());

        // 김영업 화면 — 자기 의견 + 전체 의견 2개, 이영업에게 달린 의견은 안 보임
        $this->actingAs($a);
        Volt::test('work-journal.index')->assertSee('김영업 잘했음')->assertSee('전체 양호')->assertSee('확인')->assertDontSee('이영업 확인');
    }

    /** 결재 카드는 snapshot 을 그린다 — 상신 뒤 바뀐 매물 상태가 결재 내용에 섞이지 않는다. 미상신자 표시(마감 지남·평일). */
    public function test_approval_screen_shows_snapshot_and_missing_after_cutoff(): void
    {
        Carbon::setTestNow('2026-11-05 19:30:00');   // 목요일, 마감 지남
        $line = $this->line();
        $a = $this->mk('김영업');
        $this->mk('최영업');   // 미상신
        $ja = $this->writeAndSubmit($a, '오전 상담');
        $this->actingAs($line['lead']);
        Volt::test('work-journal.approvals')->assertSee('김영업')->assertSee('오전 상담')
            ->assertSee(__('journal.ap_missing', ['names' => '최영업']), false);

        // 주말은 미상신 표시 없음
        Carbon::setTestNow('2026-11-07 20:00:00');   // 토요일
        $this->assertFalse(app(WorkJournalService::class)->showUnsubmitted('2026-11-07'));
    }

    /** 마감 알림 — 평일·마감 시각 이후·하루 1번, 주말·마감 전은 안 보냄. 시각은 기능설정. */
    public function test_cutoff_notify_weekday_once(): void
    {
        $svc = app(WorkJournalService::class);
        $this->assertFalse($svc->notifyCutoff(Carbon::parse('2026-11-04 19:10')));   // 팀장 없음 — 안 보내고 「보냄」도 안 찍음
        $this->line();
        $this->assertTrue($svc->notifyCutoff(Carbon::parse('2026-11-04 19:15')));     // 지정된 뒤 같은 날 다음 확인에서 보낸다
        $this->assertFalse($svc->notifyCutoff(Carbon::parse('2026-11-05 18:59')));
        $this->assertTrue($svc->notifyCutoff(Carbon::parse('2026-11-05 19:00')));
        $this->assertFalse($svc->notifyCutoff(Carbon::parse('2026-11-05 19:05')));   // 오늘 이미
        $this->assertFalse($svc->notifyCutoff(Carbon::parse('2026-11-07 19:30')));   // 토요일
        Setting::updateOrCreate(['key' => 'work_journal_cutoff'], ['value' => '18:00', 'type' => 'string']);
        $this->assertTrue($svc->notifyCutoff(Carbon::parse('2026-11-06 18:01')));
        $this->assertSame(3, BoardAuditLog::where('action', 'work_journal_cutoff')->count());
    }

    /** 기능설정 마감 시각은 super 만(최고관리자도 못 연다). */
    public function test_cutoff_setting_is_super_only(): void
    {
        $this->actingAs($this->mk('최고', 'admin', null, 'manager'))->get('/admin/settings')->assertForbidden();
        $this->actingAs($this->mk('root', 'super', null, 'manager'));
        Volt::test('admin.settings')->set('journalCutoff', '18:30')->call('saveJournalCutoff')->assertHasNoErrors();
        $this->assertSame('18:30', app(WorkJournalService::class)->cutoff());
        $this->assertTrue(BoardAuditLog::where('field', 'work_journal_cutoff')->exists());
    }

    /** 결재 화면 탭 — 결재할 것(내 차례) · 진행 중(다른 사람 차례) · 완료(월). 결재자는 「결재할 것」으로 열리고, 승인하면 거기서 빠진다. */
    public function test_approval_tabs_split_mine_open_done(): void
    {
        $line = $this->line();
        Carbon::setTestNow('2026-11-03 18:00:00');
        $old = $this->writeAndSubmit($this->mk('김영업'), '어제 일');
        foreach (['lead', 'bujang', 'sangmu', 'ceo'] as $k) {
            $this->approveAs($line[$k], $old->fresh()->batch);
        }
        Carbon::setTestNow('2026-11-04 18:00:00');
        $today = $this->writeAndSubmit($this->mk('이영업'), '오늘 일');

        $this->actingAs($line['lead']);
        $c = Volt::test('work-journal.approvals')->assertSet('tab', 'mine')->assertSet('sel', $today->batch_id)
            ->assertSee('오늘 일')->assertDontSee('2026-11-03');
        $this->assertSame(['mine' => 1, 'open' => 0], $c->get('counts'));
        $c->set('opinions.'.$today->id, '확인')->call('approve')->assertSet('sel', null)->assertSee(__('journal.ap_empty.mine'));
        $c->call('setTab', 'open')->assertSee('2026-11-04')->assertDontSee('2026-11-03');   // 이제 부장 차례 = 진행 중
        $c->call('setTab', 'done')->assertSet('month', '2026-11')->assertSee('2026-11-03');

        // 조회만 하는 관리자 등급은 「진행 중」으로 연다
        $this->actingAs($this->mk('백업무', 'manager'));
        Volt::test('work-journal.approvals')->assertSet('tab', 'open');
    }

    /** 로컬 전용 사용자 전환 — 꺼져 있으면 404, super 만, 전환 뒤에도 계속 바꾸고 원래 계정으로 복귀, 감사 행. */
    public function test_dev_user_switch_is_local_super_only(): void
    {
        $root = $this->mk('root', 'super', null, 'manager');
        $lead = $this->mk('정팀장', 'manager', '팀장', 'manager');
        $writer = $this->mk('김영업');

        config(['board.dev_user_switch' => false]);   // 운영과 같음
        $this->actingAs($root)->post('/dev/switch-user', ['user_id' => $writer->id])->assertNotFound();

        config(['board.dev_user_switch' => true]);
        $this->actingAs($writer)->post('/dev/switch-user', ['user_id' => $root->id])->assertForbidden();   // super 아님

        $this->actingAs($root)->post('/dev/switch-user', ['user_id' => $writer->id])->assertRedirect();
        $this->assertSame($writer->id, auth()->id());
        $this->get('/work-journal')->assertOk()->assertSee(__('nav.dev_switch.back'));   // 전환된 상태에서도 전환 상자·복귀 버튼

        $this->post('/dev/switch-user', ['user_id' => $lead->id])->assertRedirect();   // 일반 → 팀장으로 바로
        $this->assertSame($lead->id, auth()->id());
        $this->post('/dev/switch-back')->assertRedirect();
        $this->assertSame($root->id, auth()->id());
        $this->assertSame(3, BoardAuditLog::where('action', 'dev_user_switch')->count());
    }

    /** 사이드바 배지 — 지금 내 차례인 기안 수. */
    public function test_sidebar_badge_counts_my_turn(): void
    {
        $line = $this->line();
        $this->writeAndSubmit($this->mk('김영업'));
        $this->assertSame(1, app(WorkJournalService::class)->myTurnCount($line['lead']));
        $this->assertSame(0, app(WorkJournalService::class)->myTurnCount($line['ceo']));
        $this->actingAs($line['lead'])->get('/work-journal/approvals')->assertOk()->assertSee(__('nav.menu.work_journal_approvals'));
    }
}

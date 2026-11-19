<?php

namespace Tests\Feature;

use App\Models\AlimtalkLog;
use App\Models\BoardAuditLog;
use App\Models\PurchaseListing;
use App\Models\User;
use App\Models\WorkJournal;
use App\Models\WorkJournalBatch;
use App\Models\WorkJournalComment;
use App\Models\WorkJournalNote;
use App\Services\WorkJournalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schedule;
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
        $c->call('openLineModal');   // 팀장 단계면 결재선 기본값(직급 보유자 전원)이 채워진다
        $c->set('overall', $overall)->call('approve')->assertHasNoErrors()->assertSet('lineError', null);
    }

    /** 팀장이 승인할 때 결재선을 고른다 — 클레임과 같은 규칙(부장·상무·대표 중 지정, 마지막 = 최종, 한 명 이상). */
    public function test_team_leader_picks_the_approval_line_like_claims(): void
    {
        $line = $this->line();
        $j = $this->writeAndSubmit($this->mk('김영업'));
        $batch = $j->fresh()->batch;

        $this->actingAs($line['lead']);
        $c = Volt::test('work-journal.approvals')->call('pick', $batch->id)->assertSee(__('journal.ap_line_open'))
            ->call('openLineModal')->assertSet('lineModal', true)
            ->assertSet('line', ['부장' => (string) $line['bujang']->id, '상무' => (string) $line['sangmu']->id, '대표' => (string) $line['ceo']->id]);

        // 아무도 안 고르면 막힘(팀장 혼자 최종 ❌) — 모달에 사유, 결재선 그대로
        $c->set('line', ['부장' => '', '상무' => '', '대표' => ''])->call('approve')->assertSet('lineError', __('journal.err.no_line'));
        $this->assertSame(1, $batch->fresh()->current_step);
        // 다른 직급 사람 id 를 끼워 넣으면 거절
        $c->set('line', ['부장' => (string) $line['ceo']->id, '상무' => '', '대표' => ''])->call('approve')->assertSet('lineError', __('journal.err.bad_line'));

        // 상무 한 명만 → 팀장 → 상무(최종)
        $c->set('line', ['부장' => '', '상무' => (string) $line['sangmu']->id, '대표' => ''])->call('approve')->assertSet('lineModal', false);
        $batch->refresh()->load('steps');
        $this->assertSame([['팀장', 'approved'], ['상무', 'pending']], $batch->steps->map(fn ($s) => [$s->title, $s->status])->all());
        $this->assertSame(2, $batch->current_step);
        $this->assertSame(1, BoardAuditLog::where('action', 'work_journal_line')->count());

        // 부장은 결재선에 없으니 차례가 오지 않는다 · 상무 승인 = 최종
        $this->assertFalse(app(WorkJournalService::class)->canApprove($batch, $line['bujang']));
        $this->approveAs($line['sangmu'], $batch);
        $this->assertSame('approved', $batch->fresh()->status);
        $this->assertSame('approved', $j->fresh()->status);
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
        Volt::test('work-journal.index')->call('addRow')->set('rows.0.task', 'x')->call('submit')->assertHasErrors('rows');   // 부장·상무·대표 전부 없음
        $this->assertSame('draft', WorkJournal::first()->status);
    }

    /** 대표가 비면 지정된 최고관리자 직급 중 가장 위가 최종 결재권자(2026-11-05 Jin). */
    public function test_vacant_ceo_makes_highest_top_title_final(): void
    {
        $lead = $this->mk('정팀장', 'manager', '팀장', 'manager');
        $bujang = $this->mk('박부장', 'admin', '부장', 'manager');
        $sangmu = $this->mk('최상무', 'admin', '상무', 'manager');
        $w = $this->mk('김영업');
        $j = $this->writeAndSubmit($w);
        $b = $j->batch()->with('steps')->first();
        $this->assertSame(['pending', 'pending', 'pending', 'skipped'], $b->steps->pluck('status')->all());

        foreach ([$lead, $bujang] as $u) {
            $this->actingAs($u);
            Volt::test('work-journal.approvals')->call('pick', $b->id)->call('openLineModal')->call('approve');
        }
        $this->assertNotSame('approved', $b->fresh()->status);
        $this->actingAs($sangmu);
        Volt::test('work-journal.approvals')->call('pick', $b->id)->assertSee(__('journal.ap_final'))->call('approve');
        $this->assertSame('approved', $b->fresh()->status);
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
        $this->assertSame(0, WorkJournalComment::count());   // 옛 표에는 더 안 쓴다
        $this->assertSame(2, WorkJournalNote::where('kind', 'submit')->count());
        $this->assertSame(2, WorkJournalNote::where('kind', 'opinion')->whereNotNull('work_journal_id')->count());

        // 김영업 화면 — 자기 상신 의견·자기에게 달린 결재의견만. 이영업 것·기안 전체 줄은 안 보임
        $this->actingAs($a);
        Volt::test('work-journal.index')->assertSee('김영업 잘했음')->assertSee(__('journal.note_kind.submit'))->assertDontSee('이영업 확인')->assertDontSee('전체 양호');
    }

    /** 결재 카드는 snapshot 을 그린다. 상신 마감 시각은 없다(2026-11-19) — 오늘은 몇 시든 미상신 표시 없음, 지난 평일만 미상신 표시. */
    public function test_approval_screen_shows_snapshot_and_missing_only_for_past_weekdays(): void
    {
        Carbon::setTestNow('2026-11-05 21:30:00');   // 목요일 밤 — 예전 마감(19:00) 뒤여도 오늘은 미상신으로 안 친다
        $line = $this->line();
        $a = $this->mk('김영업');
        $this->mk('최영업');   // 미상신
        $this->writeAndSubmit($a, '오전 상담');
        $this->actingAs($line['lead']);
        Volt::test('work-journal.approvals')->assertSee('김영업')->assertSee('오전 상담')
            ->assertDontSee(__('journal.ap_missing', ['names' => '최영업']), false);
        $this->actingAs($a);
        Volt::test('work-journal.index')->assertDontSee('마감');

        Carbon::setTestNow('2026-11-06 09:00:00');   // 다음 날 — 어제 기안에 미상신자가 보인다
        $this->actingAs($line['lead']);
        Volt::test('work-journal.approvals')->assertSee(__('journal.ap_missing', ['names' => '최영업']), false);

        $this->assertFalse(app(WorkJournalService::class)->showUnsubmitted('2026-11-06'));   // 오늘
        $this->assertFalse(app(WorkJournalService::class)->showUnsubmitted('2026-11-01'));   // 지난 일요일
        // 마감 알림 예약 작업도 없다
        $this->assertFalse(collect(Schedule::events())->contains(fn ($e) => str_contains((string) $e->command, 'work-journal-cutoff')));
    }

    /** 「반려」 탭 — 팀장 선에서 반려돼 위로 안 올라간 것도 상무가 본다. 작성자는 자기 화면 「내 반려 이력」으로. 탭 이름 = 결재대기·결재중·결재완료·반려. */
    public function test_rejected_tab_lists_every_return_and_writer_sees_own_history(): void
    {
        $line = $this->line();
        $a = $this->mk('김영업');
        $b = $this->mk('이영업');
        $ja = $this->writeAndSubmit($a);
        $this->writeAndSubmit($b);

        $this->actingAs($line['lead']);
        Volt::test('work-journal.approvals')->assertSee('결재대기')->assertSee('결재중')->assertSee('결재완료')
            ->call('pick', $ja->batch_id)->call('openReject')->set('rejectReason', '오후 업무 누락')->set('rejectPick', [(string) $ja->id])->call('rejectBatch')->assertHasNoErrors();

        // 상무 — 자기 차례가 온 적 없지만 「반려」 탭에서 본다
        $this->actingAs($line['sangmu']);
        Volt::test('work-journal.approvals')->call('setTab', 'rejected')->assertSet('tab', 'rejected')
            ->assertSee('오후 업무 누락')->assertSee(__('journal.note_targets', ['names' => '김영업']))->assertSee('팀장 정팀장');

        // 작성자 — 내 반려 이력. 다른 영업에게는 안 보인다
        $this->actingAs($a);
        Volt::test('work-journal.index')->assertSee(__('journal.my_rejections', ['n' => 1]))->assertSee('오후 업무 누락');
        $this->actingAs($b);
        Volt::test('work-journal.index')->assertDontSee('오후 업무 누락');

        // 왼쪽 목록 — 반려된 사람이 3명 이상이면 「첫 사람 외 N명」(오른쪽 이력엔 전원)
        Carbon::setTestNow(now()->addDay()->setTime(10, 0));
        $ws = collect(['가영업', '나영업', '다영업'])->map(fn ($n) => $this->writeAndSubmit($this->mk($n)));
        $this->actingAs($line['lead']);
        Volt::test('work-journal.approvals')->call('pick', $ws[0]->batch_id)->call('openReject')->set('rejectReason', '전원 다시')
            ->set('rejectPick', $ws->map(fn ($j) => (string) $j->id)->all())->call('rejectBatch')->assertHasNoErrors();
        $this->actingAs($line['sangmu']);
        Volt::test('work-journal.approvals')->call('setTab', 'rejected')
            ->assertSee(__('journal.ap_rejected_more', ['name' => '가영업', 'n' => 2]))->assertSee(__('journal.note_targets', ['names' => '가영업, 나영업, 다영업']));
    }

    /** 첫 단계(팀장) 반려 — 사유·사람 한 명 이상 필수, 고른 사람만 돌아가고 나머지는 기안에 남는다. 재상신하면 같은 기안. 이력은 재상신 뒤에도 남는다. */
    public function test_first_step_reject_sends_picked_people_back_and_history_stays(): void
    {
        $line = $this->line();
        $a = $this->mk('김영업');
        $b = $this->mk('이영업');
        $ja = $this->writeAndSubmit($a, '늦게 온 일지');
        $jb = $this->writeAndSubmit($b, '정상 일지');
        $batch = $ja->fresh()->batch;

        $this->actingAs($line['lead']);
        $c = Volt::test('work-journal.approvals')->call('pick', $batch->id)->call('openReject')->assertSee(__('journal.ap_reject_pick_required'));
        $c->call('rejectBatch')->assertHasErrors('rejectReason');                                         // 사유 없음
        $c->set('rejectReason', '오후 업무 빠짐 — 다시 작성')->call('rejectBatch')->assertHasErrors('rejectReason');   // 첫 단계 — 사람 필수
        $c->set('rejectPick', [(string) $ja->id])->call('rejectBatch')->assertHasNoErrors()->assertSet('rejectModal', false);

        $ja->refresh();
        $this->assertSame(['rejected', null, '오후 업무 빠짐 — 다시 작성', $line['lead']->id], [$ja->status, $ja->batch_id, $ja->reject_reason, $ja->rejected_by]);
        $this->assertSame('늦게 온 일지', $ja->entries()->first()->task);   // 내용 그대로
        $this->assertSame($batch->id, $jb->fresh()->batch_id);               // 나머지는 기안에 그대로
        $this->assertTrue(BoardAuditLog::where('action', 'work_journal_reject')->exists());

        // 작성자 — 사유가 보이고, 고쳐서 다시 상신하면 같은 기안. 반려 이력은 재상신 뒤에도 남는다.
        $this->actingAs($a);
        Volt::test('work-journal.index')->assertSee('오후 업무 빠짐 — 다시 작성')->assertSee(__('journal.status.rejected'))
            ->set('rows.0.task', '늦게 온 일지 + 오후 업무')->set('submitNote', '고쳐서 다시 올립니다')->call('submit')->assertHasNoErrors();
        $ja->refresh();
        $this->assertSame(['submitted', $batch->id, null], [$ja->status, $ja->batch_id, $ja->reject_reason]);
        Volt::test('work-journal.index')->assertSee(__('journal.thread_title'))->assertSee('오후 업무 빠짐 — 다시 작성')->assertSee('고쳐서 다시 올립니다');
        // 이영업에게는 김영업의 반려 이력이 안 보인다
        $this->actingAs($b);
        Volt::test('work-journal.index')->assertDontSee('오후 업무 빠짐 — 다시 작성');
        // 결재자 — 사람 카드와 기안 반려 이력에 보인다
        $this->actingAs($line['lead']);
        Volt::test('work-journal.approvals')->call('pick', $batch->id)->assertSee('오후 업무 빠짐 — 다시 작성')->assertSee(__('journal.note_targets', ['names' => '김영업']))->assertSee('고쳐서 다시 올립니다');
    }

    /** 상무 반려 = 기안 전체 반려 — 고른 사람은 본인에게, 나머지는 기안에 남아 팀장 결재 전 상태로. 재상신 → 같은 기안 → 팀장부터 다시. */
    public function test_later_step_reject_returns_whole_batch_to_first_approver(): void
    {
        $line = $this->line();
        $writers = collect(['일영업', '이영업', '삼영업'])->map(fn ($n) => $this->mk($n));
        $js = $writers->map(fn ($w) => $this->writeAndSubmit($w, $w->name.' 일'))->all();
        $batch = $js[0]->fresh()->batch;

        // 팀장: 상무만 지정 → 팀장 → 상무
        $this->actingAs($line['lead']);
        Volt::test('work-journal.approvals')->call('pick', $batch->id)->call('openLineModal')
            ->set('line', ['부장' => '', '상무' => (string) $line['sangmu']->id, '대표' => ''])->call('approve')->assertSet('lineError', null);
        $this->assertSame(2, $batch->fresh()->current_step);
        $this->assertFalse(app(WorkJournalService::class)->canReject($batch->fresh()->load('steps'), $line['lead']));   // 내 차례가 아니면 반려 못 함

        // 상무: 삼영업을 골라 반려
        $this->actingAs($line['sangmu']);
        Volt::test('work-journal.approvals')->call('pick', $batch->id)->call('openReject')->assertSee(__('journal.ap_reject_pick'))
            ->set('rejectReason', '계약 차량 금액 확인 필요')->set('rejectPick', [(string) $js[2]->id])->call('rejectBatch')->assertHasNoErrors();

        $batch->refresh()->load('steps');
        $this->assertSame(['pending', 1], [$batch->status, $batch->current_step]);
        $this->assertSame(['pending', 'pending'], $batch->steps->pluck('status')->all());   // 팀장 승인도 되돌려진다
        $this->assertSame(['submitted', 'submitted', 'rejected'], collect($js)->map(fn ($j) => $j->fresh()->status)->all());
        $this->assertSame([$batch->id, $batch->id, null], collect($js)->map(fn ($j) => $j->fresh()->batch_id)->all());
        $this->assertTrue(app(WorkJournalService::class)->canApprove($batch, $line['lead']));    // 다시 팀장 차례
        $this->assertFalse(app(WorkJournalService::class)->canApprove($batch, $line['sangmu']));

        // 삼영업 재상신 → 같은 기안에 다시 모인다
        $this->actingAs($writers[2]);
        Volt::test('work-journal.index')->assertSee('계약 차량 금액 확인 필요')->call('submit')->assertHasNoErrors();
        $this->assertSame($batch->id, $js[2]->fresh()->batch_id);
        $this->assertSame(1, WorkJournalBatch::count());

        // 팀장부터 다시 — 결재선 기본값 = 지난번(상무만), 다음 결재자(상무)가 반려 이력을 본다
        $this->actingAs($line['lead']);
        Volt::test('work-journal.approvals')->call('pick', $batch->id)->assertSee('계약 차량 금액 확인 필요')->call('openLineModal')
            ->assertSet('line', ['부장' => '', '상무' => (string) $line['sangmu']->id, '대표' => ''])->call('approve')->assertSet('lineError', null);
        $this->actingAs($line['sangmu']);
        Volt::test('work-journal.approvals')->call('pick', $batch->id)->assertSee('계약 차량 금액 확인 필요')->assertSee(__('journal.note_targets', ['names' => '삼영업']))
            ->call('approve')->assertHasNoErrors();
        $this->assertSame('approved', $batch->fresh()->status);
        $this->assertSame(['approved', 'approved', 'approved'], collect($js)->map(fn ($j) => $j->fresh()->status)->all());
    }

    /** 사람을 안 고른 반려(첫 단계 아님) — 일지는 모두 기안에 남고 기안만 팀장에게. 그 줄은 결재자에게만 보인다. */
    public function test_reject_without_people_returns_batch_only_and_is_approver_only(): void
    {
        $line = $this->line();
        $w = $this->mk('김영업');
        $j = $this->writeAndSubmit($w);
        $batch = $j->fresh()->batch;
        $this->approveAs($line['lead'], $batch);

        $this->actingAs($line['bujang']);
        Volt::test('work-journal.approvals')->call('pick', $batch->id)->call('openReject')->set('rejectReason', '전체적으로 다시 확인')->call('rejectBatch')->assertHasNoErrors();
        $batch->refresh();
        $this->assertSame(['pending', 1], [$batch->status, $batch->current_step]);
        $this->assertSame(['submitted', $batch->id], [$j->fresh()->status, $j->fresh()->batch_id]);

        $this->actingAs($w);
        Volt::test('work-journal.index')->assertDontSee('전체적으로 다시 확인');   // 작성자에겐 안 보임(자기 일지가 반려된 게 아니다)
        $this->actingAs($line['lead']);
        Volt::test('work-journal.approvals')->call('pick', $batch->id)->assertSee('전체적으로 다시 확인')->assertSee(__('journal.note_targets_none'));
    }

    /** 모두 돌려보내면 빈 기안은 사라진다 — 이력은 남는다. */
    public function test_rejecting_everyone_removes_empty_batch(): void
    {
        $line = $this->line();
        $j = $this->writeAndSubmit($this->mk('김영업'));
        $this->actingAs($line['lead']);
        Volt::test('work-journal.approvals')->call('pick', $j->batch_id)->call('openReject')->set('rejectReason', '다시')->set('rejectPick', [(string) $j->id])->call('rejectBatch')->assertHasNoErrors();
        $this->assertSame(0, WorkJournalBatch::count());
        $this->assertSame(1, WorkJournalNote::where('work_journal_id', $j->id)->where('kind', 'reject')->count());
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
        $c->set('opinions.'.$today->id, '확인')->call('openLineModal')->call('approve')->assertSet('sel', null)->assertSee(__('journal.ap_empty.mine'));
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

    /** 알림톡 로그 화면 — 시스템관리자만, 상태·주의 필요 필터, 실패 [확인](한 건·전체) + 감사, 사이드바 배지. 건너뜀 사유는 한글. */
    public function test_alimtalk_log_screen_super_only_with_ack(): void
    {
        $root = $this->mk('root', 'super', null, 'manager');
        $insp = $this->mk('박검차', 'user', null, 'inspection');
        $f1 = AlimtalkLog::create(['user_id' => $insp->id, 'template_code' => 'board_region_inspection', 'phone' => '01012345678', 'region' => '서울특별시', 'status' => 'failed', 'error' => 'HTTP 500 boom']);
        $f2 = AlimtalkLog::create(['template_code' => 'board_forward_ready', 'phone' => '01099998888', 'status' => 'failed', 'error' => 'bizmsg fail — K104']);
        AlimtalkLog::create(['template_code' => 'board_forward_ready', 'phone' => '', 'status' => 'skipped', 'error' => 'no_phone']);
        AlimtalkLog::create(['template_code' => 'board_forward_ready', 'phone' => '01011112222', 'status' => 'sent', 'msgid' => 'm1', 'message' => '전달 대기 안내 본문']);

        $this->actingAs($this->mk('최고', 'admin', null, 'manager'))->get('/admin/alimtalk-logs')->assertForbidden();
        $this->actingAs($root)->get('/admin/alimtalk-logs')->assertOk()->assertSee(__('nav.menu.alimtalk_logs'))
            ->assertSee('지역 검차 안내')->assertSee(__('alimtalk_logs.reason.no_phone'));

        $c = Volt::test('admin.alimtalk-logs')->set('status', 'skipped')->assertSee(__('alimtalk_logs.reason.no_phone'))->assertDontSee('HTTP 500 boom')
            ->set('status', '')->set('attention', true)->assertSee('HTTP 500 boom')->assertDontSee('전달 대기 안내 본문');
        $this->assertSame(2, $c->instance()->attentionCount);
        $c->call('acknowledge', $f1->id);
        $this->assertNotNull($f1->fresh()->acknowledged_at);
        $c->call('acknowledgeAll');
        $this->assertSame(0, AlimtalkLog::needsAttention()->count());
        $this->assertSame(2, BoardAuditLog::where('action', 'alimtalk_ack')->count());
        $c->set('search', '박검')->call('searchNow')->set('attention', false)->assertSee('01012345678')->assertDontSee('01099998888');
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

    // ───── 해외영업부 엑셀 양식 반영(2026-11-16 Jin) ─────

    /** [하루 틀 만들기] = 09~18시 10줄 + 그 요일 고정 업무(같은 시간 줄·특이사항). 이미 줄이 있으면 안 만든다. */
    public function test_day_template_makes_hourly_rows_and_prefills_routines(): void
    {
        Carbon::setTestNow('2026-11-18 08:30:00');   // 수요일
        $w = $this->mk('김영업');
        $this->actingAs($w);
        Volt::test('work-journal.index')->set('rtWeekday', 3)->set('rtTime', '09:00')->set('rtLocation', '경매장')->set('rtTask', 'AJ 경매장 검수')->call('addRoutine')->assertHasNoErrors();
        $this->assertSame(1, BoardAuditLog::where('action', 'work_journal_routine')->count());

        $c = Volt::test('work-journal.index')->call('fillTemplate');
        $j = WorkJournal::where('user_id', $w->id)->firstOrFail();
        $this->assertSame(10, $j->entries()->count());
        $first = $j->entries()->first();
        $this->assertSame(['09:00', '경매장', 'AJ 경매장 검수'], [$first->time, $first->location, $first->task]);
        $this->assertStringContainsString('AJ 경매장 검수', (string) $j->fresh()->remarks);

        $c->call('fillTemplate');
        $this->assertSame(10, $j->entries()->count());   // 덮어쓰지 않는다
    }

    /** 위치·내일 예정업무·특이사항·금일 계약 차량이 상신 snapshot 에 고정되고, 빈 틀 줄은 빠진다. 결재 카드에도 보인다. */
    public function test_location_plan_remarks_and_contracts_are_frozen_in_the_snapshot(): void
    {
        $line = $this->line();
        $w = $this->mk('김영업');
        $car = PurchaseListing::withoutEvents(fn () => PurchaseListing::create([
            'created_by_user_id' => $w->id, 'source' => 'encar', 'vehicle_number' => '11가1001', 'status' => 'won', 'buyer_verdict' => 'accepted',
            'buyer_name' => 'GUIDE SAMPLE TRADING', 'sale_price' => 2000, 'offer_currency' => 'EUR',
        ]));
        BoardAuditLog::create(['user_id' => $w->id, 'purchase_listing_id' => $car->id, 'action' => 'status_change', 'field' => 'status', 'old_value' => 'accepted', 'new_value' => 'won']);

        $this->actingAs($w);
        Volt::test('work-journal.index')->call('fillTemplate')
            ->set('rows.0.location', '화물단지')->set('rows.0.task', '바이어 요청 차량 사진 촬영')
            ->set('plan', '바이어 요청 차량 가격 확인')->set('remarks', '목요일 K카 검수')
            ->assertSee('GUIDE SAMPLE TRADING')->assertSee('총계약 1대')
            ->call('submit')->assertHasNoErrors();

        $snap = WorkJournal::where('user_id', $w->id)->firstOrFail()->snapshot;
        $this->assertCount(1, $snap['entries']);   // 빈 틀 줄 9개는 빠진다
        $this->assertSame('화물단지', $snap['entries'][0]['location']);
        $this->assertSame('바이어 요청 차량 가격 확인', $snap['plan_tomorrow']);
        $this->assertSame('목요일 K카 검수', $snap['remarks']);
        $this->assertSame(1, $snap['contracts']['count']);
        $this->assertEquals(['EUR' => 2000], $snap['contracts']['by_currency']);

        $this->actingAs($line['lead']);
        Volt::test('work-journal.approvals')->assertSee('GUIDE SAMPLE TRADING')->assertSee('바이어 요청 차량 가격 확인')->assertSee('화물단지');
    }

    /** 엑셀 내려받기 — 상신한 일지만, 본인·결재자만(다른 영업 403), 내려받을 때마다 감사 행. */
    public function test_excel_download_is_for_submitted_journals_and_allowed_people(): void
    {
        $line = $this->line();
        $w = $this->mk('김영업');
        $other = $this->mk('이영업');
        $this->actingAs($w);
        Volt::test('work-journal.index')->call('addRow')->set('rows.0.task', '딜러 통화');
        $j = WorkJournal::where('user_id', $w->id)->firstOrFail();
        $this->get(route('work-journal.excel', $j))->assertForbidden();   // 작성 중 = 없음

        Volt::test('work-journal.index')->call('submit')->assertHasNoErrors();
        $res = $this->get(route('work-journal.excel', $j->fresh()));
        $res->assertOk();
        $this->assertStringContainsString('spreadsheetml', (string) $res->headers->get('Content-Type'));
        $this->actingAs($line['lead'])->get(route('work-journal.excel', $j))->assertOk();
        $this->actingAs($other)->get(route('work-journal.excel', $j))->assertForbidden();
        $this->assertSame(2, BoardAuditLog::where('action', 'work_journal_excel')->count());
    }

    /** 다음 날 화면 위에 어제 적은 「내일 예정업무」. */
    public function test_yesterdays_plan_is_shown_on_today(): void
    {
        $w = $this->mk('김영업');
        WorkJournal::create(['user_id' => $w->id, 'date' => today()->subDay()->toDateString(), 'status' => 'draft', 'plan_tomorrow' => '검수 리포트 발송']);
        $this->actingAs($w);
        Volt::test('work-journal.index')->assertSee(__('journal.prev_plan_title'))->assertSee('검수 리포트 발송');
    }
}

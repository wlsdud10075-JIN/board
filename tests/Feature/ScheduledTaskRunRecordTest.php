<?php

namespace Tests\Feature;

use App\Models\ScheduledTaskRun;
use App\Services\Ops\OpsSummary;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 예약 작업 실행 기록 — 관제센터 「루틴」 칸(ops summary routines[], 2026-11-16 Jin). car-erp 같은 이름 테스트를 옮김.
 * 실패 순서는 실제 스케줄러와 같게: Finished(exitCode≠0) 가 먼저, 그 뒤 Failed(car-erp SKILLS §8 #90).
 * ⚠️ car-erp 의 「schedule:run 실제 경로」 테스트는 안 옮긴다 — board 는 매분 작업(transport-send·push)이 있어 테스트 안에서 같이 돈다.
 */
class ScheduledTaskRunRecordTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function task(string $command)
    {
        return app(Schedule::class)->command($command)->dailyAt('03:00');
    }

    public function test_a_successful_run_is_one_ok_row(): void
    {
        $t = $this->task('db:backup');
        event(new ScheduledTaskStarting($t));
        $t->exitCode = 0;
        event(new ScheduledTaskFinished($t, 1.234));

        $run = ScheduledTaskRun::sole();
        $this->assertSame('db:backup', $run->command);
        $this->assertSame('ok', $run->status);
        $this->assertSame(1234, $run->duration_ms);
        $this->assertSame('0 3 * * *', $run->expression);
        $this->assertNotNull($run->finished_at);
    }

    public function test_a_failed_run_is_never_recorded_as_ok_and_keeps_the_reason(): void
    {
        $t = $this->task('board:transport-send');
        event(new ScheduledTaskStarting($t));
        $t->exitCode = 1;
        event(new ScheduledTaskFinished($t, 0.5));   // 스케줄러는 실패해도 Finished 를 먼저 낸다
        $this->assertSame('failed', ScheduledTaskRun::sole()->status);
        event(new ScheduledTaskFailed($t, new \RuntimeException('BizM 401')));

        $run = ScheduledTaskRun::sole();
        $this->assertSame('failed', $run->status);
        $this->assertSame(1, $run->exit_code);
        $this->assertSame('BizM 401', $run->error);
    }

    public function test_an_exception_before_finish_is_recorded_too(): void
    {
        $t = $this->task('board:poll-ssancar-media');
        event(new ScheduledTaskStarting($t));
        event(new ScheduledTaskFailed($t, new \RuntimeException('DB 끊김')));

        $this->assertSame(['failed', 'DB 끊김'], [ScheduledTaskRun::sole()->status, ScheduledTaskRun::sole()->error]);
    }

    public function test_latest_by_command_gives_last_run_and_last_success(): void
    {
        Carbon::setTestNow('2026-11-11 03:00:00');
        $a = $this->task('db:backup');
        event(new ScheduledTaskStarting($a));
        $a->exitCode = 0;
        event(new ScheduledTaskFinished($a, 1));

        Carbon::setTestNow('2026-11-12 03:00:00');
        $b = $this->task('db:backup');
        event(new ScheduledTaskStarting($b));
        event(new ScheduledTaskFailed($b, new \RuntimeException('디스크 가득')));

        $x = ScheduledTaskRun::latestByCommand()['db:backup'];
        $this->assertSame('failed', $x['last']->status);
        $this->assertSame('2026-11-11 03:00:00', $x['last_ok_at']->format('Y-m-d H:i:s'));
    }

    public function test_rows_older_than_90_days_are_pruned(): void
    {
        ScheduledTaskRun::create(['command' => 'old', 'status' => 'ok', 'started_at' => now()->subDays(91)]);
        ScheduledTaskRun::create(['command' => 'new', 'status' => 'ok', 'started_at' => now()->subDays(1)]);

        $this->artisan('model:prune', ['--model' => [ScheduledTaskRun::class]])->assertSuccessful();
        $this->assertSame(['new'], ScheduledTaskRun::pluck('command')->all());
    }

    /** ops summary 의 routines[] — car-erp 와 같은 키, 작업마다 1줄. */
    public function test_ops_summary_routines_has_the_erp_shape(): void
    {
        Carbon::setTestNow('2026-11-16 07:40:00');
        $t = $this->task('board:purchase-sync-audit');
        event(new ScheduledTaskStarting($t));
        $t->exitCode = 0;
        event(new ScheduledTaskFinished($t, 0.25));

        $rows = OpsSummary::routines();
        $this->assertCount(1, $rows);
        $this->assertSame(['command', 'expression', 'status', 'started_at', 'duration_ms', 'error', 'last_ok_at'], array_keys($rows[0]));
        $this->assertSame(['board:purchase-sync-audit', 'ok', 250], [$rows[0]['command'], $rows[0]['status'], $rows[0]['duration_ms']]);
        $this->assertNotNull($rows[0]['last_ok_at']);
    }
}

<?php

namespace App\Services;

use App\Models\BoardAuditLog;
use App\Models\PurchaseListing;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkJournal;
use App\Models\WorkJournalBatch;
use App\Models\WorkJournalComment;
use App\Support\Plate;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 업무일지 + 업무일지결재(2026-11-04 Jin) — 화면 두 개(work-journal/index·approvals)와 마감 명령이 이 하나를 쓴다.
 * 정본 = meetings/plan-work-journal-2026-11.md.
 *
 *  - 상신 = 표 + board 활동을 **snapshot 으로 고정**하고 그날 열린 묶음에 넣는다(묶음에 승인 단계가 하나라도 있으면 새 묶음 = 추가 기안).
 *  - 결재선 = 팀장(지정 업무관리자) → 부장 → 상무 → 대표(최고관리자 직급). 그 직급 사람이 없으면 skipped, 대표가 없으면 상신을 막는다.
 *  - 반려 = **팀장만, 팀장 승인 전에만**, 사람별·사유 필수(2026-11-04 Jin). 반려된 일지는 내용 그대로 작성자에게 돌아가 고쳐 다시 상신.
 *    그 위 단계(부장·상무·대표)는 의견만 남기고 승인한다.
 *  - 모든 행위는 board_audit_logs(Jin 원칙).
 */
class WorkJournalService
{
    /** 감사 로그에서 「board 활동」에 넣지 않는 행위 — 매물과 무관한 내부 기록·업무일지 자신. */
    private const ACTIVITY_SKIP = ['assistant_query', 'user_shared_managers', 'user_permission'];

    public function cutoff(): string
    {
        $v = (string) Setting::get('work_journal_cutoff', '19:00');

        return preg_match('/^\d{2}:\d{2}$/', $v) ? $v : '19:00';
    }

    public static function isWeekend(Carbon|string $date): bool
    {
        return Carbon::parse($date)->isWeekend();
    }

    /** 미상신 표시를 할 때인가 — 주말 아님 + (지난 날짜 또는 오늘 마감 시각 지남). Jin: 주말엔 마감 알림·미상신 표시 없음. */
    public function showUnsubmitted(Carbon|string $date): bool
    {
        $d = Carbon::parse($date)->startOfDay();
        if (self::isWeekend($d)) {
            return false;
        }
        if ($d->lt(today())) {
            return true;
        }

        return $d->isSameDay(today()) && now()->format('H:i') >= $this->cutoff();
    }

    /** 작성 대상 전원(등급 일반 활성) — 미상신 집계 기준. */
    public function writers(): Collection
    {
        return User::where('permission', 'user')->where('is_active', true)->orderBy('name')->get();
    }

    // ───────────── 작성 ─────────────

    public function journalFor(User $u, string $date): WorkJournal
    {
        // whereDate — sqlite(테스트)는 date 캐스트가 시각을 붙여 저장해 문자열 비교가 어긋난다
        return WorkJournal::where('user_id', $u->id)->whereDate('date', $date)->first()
            ?? WorkJournal::create(['user_id' => $u->id, 'date' => $date, 'status' => 'draft']);
    }

    /** 작성 첫 저장 — 하루 1번만 감사 행(타이핑마다 남기지 않는다). */
    public function logFirstSave(WorkJournal $j): void
    {
        $exists = BoardAuditLog::where('action', 'work_journal_write')->where('user_id', $j->user_id)
            ->where('new_value', $j->date->toDateString())->exists();
        if (! $exists) {
            $this->audit($j->user_id, 'work_journal_write', null, $j->date->toDateString());
        }
    }

    /**
     * 그날 그 사람의 board 활동 — 감사 로그를 매물·분 단위로 묶어 한 줄씩(구매확정 1번 = 칸별 수십 행이라 묶지 않으면 표가 터진다).
     * 진행상태는 **지금** 매물 상태(상신 때 snapshot 으로 고정). 모르는 action 은 원문.
     *
     * @return list<array{time:string, plate:string, what:string, state:?string}>
     */
    public function activitiesFor(User $u, string $date): array
    {
        $rows = BoardAuditLog::where('user_id', $u->id)
            ->where('created_at', '>=', $date.' 00:00:00')->where('created_at', '<=', $date.' 23:59:59')
            ->whereNotIn('action', self::ACTIVITY_SKIP)->where('action', 'not like', 'work_journal_%')
            ->orderBy('created_at')->orderBy('id')->get();
        if ($rows->isEmpty()) {
            return [];
        }
        $listings = PurchaseListing::withoutGlobalScopes()->withTrashed()->whereIn('id', $rows->pluck('purchase_listing_id')->filter()->unique())->get()->keyBy('id');

        $groups = [];
        foreach ($rows as $r) {
            $key = ($r->purchase_listing_id ?? 'x'.$r->id).'|'.$r->created_at->format('H:i');
            $groups[$key][] = $r;
        }

        $out = [];
        foreach ($groups as $g) {
            $first = $g[0];
            $l = $first->purchase_listing_id ? ($listings[$first->purchase_listing_id] ?? null) : null;
            $parts = [];
            $edits = [];
            $attach = 0;
            foreach ($g as $r) {
                switch ($r->action) {
                    case 'create':
                        if ($r->field === 'created') {
                            $parts[] = __('journal.act.create');
                        }
                        break;
                    case 'status_change':
                        $parts[] = __('journal.act.status', ['to' => PurchaseListing::statusOptions()[$r->new_value] ?? $r->new_value]);
                        break;
                    case 'field_edit':
                        $edits[] = $this->fieldLabel($r->field);
                        break;
                    case 'attachment_add':
                        $attach++;
                        break;
                    case 'delete':
                        $parts[] = __('journal.act.delete');
                        break;
                    default:
                        $parts[] = $this->fieldLabel($r->field ?: $r->action);
                }
            }
            if ($attach > 0) {
                $parts[] = __('journal.act.attach', ['n' => $attach]);
            }
            if ($edits !== []) {
                $edits = array_values(array_unique($edits));
                $parts[] = __('journal.act.edit', ['fields' => implode('·', array_slice($edits, 0, 3)).(count($edits) > 3 ? ' '.__('journal.act.more', ['n' => count($edits) - 3]) : '')]);
            }
            $parts = array_values(array_unique($parts));
            if ($parts === []) {
                continue;
            }
            $plate = $l ? Plate::show($l->vehicle_number) : $this->plateFromValue($first->new_value);
            $out[] = [
                'time' => $first->created_at->format('H:i'),
                'plate' => $plate,
                'what' => implode(' · ', $parts),
                'state' => $l && ! $l->trashed() ? $l->statusLabel() : ($l ? __('journal.act.deleted_state') : null),
            ];
        }

        return $out;
    }

    private function fieldLabel(?string $f): string
    {
        if ($f === null || $f === '') {
            return '';
        }
        $label = __('audit.field.'.$f);

        return $label === 'audit.field.'.$f ? $f : $label;
    }

    /** 포털 요청처럼 매물이 안 붙은 행 — 감사 값의 vehicles(차량번호 목록)가 있으면 그것. */
    private function plateFromValue(?string $json): string
    {
        $v = json_decode((string) $json, true);
        $plates = is_array($v) ? (array) ($v['vehicles'] ?? []) : [];

        return $plates === [] ? '—' : implode(', ', array_map(fn ($p) => Plate::show((string) $p), $plates));
    }

    // ───────────── 상신·회수 ─────────────

    /**
     * 상신 — snapshot 고정 + 그날 열린 묶음에 합류(없으면 새 묶음·결재선 생성). 대표가 없으면 \DomainException('no_ceo').
     */
    public function submit(WorkJournal $j): WorkJournalBatch
    {
        if (! $j->isDraft()) {
            throw new \DomainException('not_draft');
        }
        $entries = $j->entries()->get()->filter(fn ($e) => trim((string) $e->task) !== '' || trim((string) $e->note) !== '');
        if ($entries->isEmpty()) {
            throw new \DomainException('empty');
        }

        return DB::transaction(function () use ($j, $entries) {
            $date = $j->date->toDateString();
            $batch = WorkJournalBatch::whereDate('date', $date)->where('status', 'pending')->orderByDesc('seq')->with('steps')->lockForUpdate()->first();
            if (! $batch || $batch->hasApprovals()) {
                $batch = $this->createBatch($date);
            }
            $j->update([
                'status' => 'submitted',
                'submitted_at' => now(),
                'batch_id' => $batch->id,
                'rejected_at' => null, 'rejected_by' => null, 'reject_reason' => null,   // 다시 상신 — 반려 이력은 감사 로그에
                'snapshot' => [
                    'entries' => $entries->map(fn ($e) => ['time' => $e->time, 'task' => $e->task, 'note' => $e->note])->values()->all(),
                    'activities' => $this->activitiesFor($j->user, $date),
                ],
            ]);
            $this->audit($j->user_id, 'work_journal_submit', null, json_encode(['date' => $date, 'batch' => $batch->seq, 'entries' => $entries->count()], JSON_UNESCAPED_UNICODE));

            return $batch;
        });
    }

    /** 새 묶음 + 결재선. 직급 사람이 없는 단계는 skipped(시스템 감사 행), 대표가 없으면 막는다. */
    private function createBatch(string $date): WorkJournalBatch
    {
        $holders = [];
        foreach (User::JOURNAL_LINE as $title) {
            $holders[$title] = User::where('approval_title', $title)->where('is_active', true)
                ->where('permission', $title === User::TEAM_LEADER ? 'manager' : 'admin')->orderBy('id')->first();
        }
        if (! $holders['대표']) {
            throw new \DomainException('no_ceo');
        }
        $seq = (int) WorkJournalBatch::whereDate('date', $date)->max('seq') + 1;
        $batch = WorkJournalBatch::create(['date' => $date, 'seq' => $seq, 'status' => 'pending']);
        $current = null;
        foreach (User::JOURNAL_LINE as $i => $title) {
            $u = $holders[$title];
            $batch->steps()->create(['seq' => $i + 1, 'title' => $title, 'approver_user_id' => $u?->id, 'status' => $u ? 'pending' : 'skipped']);
            if ($u && $current === null) {
                $current = $i + 1;
            }
            if (! $u) {
                $this->audit(null, 'work_journal_step_skipped', null, json_encode(['date' => $date, 'batch' => $seq, 'title' => $title], JSON_UNESCAPED_UNICODE));
            }
        }
        $batch->update(['current_step' => $current]);

        return $batch->load('steps');
    }

    /** 회수 — 그 묶음에 승인 단계가 없을 때만. 묶음이 비면 지운다(결재자에게 빈 기안이 보이지 않게). */
    public function recall(WorkJournal $j): void
    {
        $batch = $j->batch()->with('steps')->first();
        if ($j->status !== 'submitted' || ! $batch || $batch->hasApprovals()) {
            throw new \DomainException('cannot_recall');
        }
        DB::transaction(function () use ($j, $batch) {
            $j->update(['status' => 'draft', 'submitted_at' => null, 'batch_id' => null, 'snapshot' => null]);
            if (! $batch->journals()->exists()) {
                $batch->delete();
            }
            $this->audit($j->user_id, 'work_journal_recall', null, $j->date->toDateString());
        });
    }

    // ───────────── 결재 ─────────────

    /** 반려할 수 있나 — 지금 팀장 단계이고 그 팀장 본인일 때만(부장 이상에게 넘어간 뒤엔 없음). */
    public function canReject(WorkJournalBatch $b, User $u): bool
    {
        return $this->canApprove($b, $u) && $b->currentStep()?->title === User::TEAM_LEADER;
    }

    /**
     * 반려 — 그 사람 일지만 묶음에서 빼서 rejected(내용 그대로)로 돌려보낸다. 사유 필수. 묶음이 비면 지운다.
     */
    public function reject(WorkJournal $j, User $actor, string $reason): void
    {
        $reason = trim($reason);
        $b = $j->batch()->with('steps')->first();
        if ($j->status !== 'submitted' || ! $b || ! $this->canReject($b, $actor)) {
            throw new \DomainException('cannot_reject');
        }
        if ($reason === '') {
            throw new \DomainException('reason_required');
        }
        DB::transaction(function () use ($j, $b, $actor, $reason) {
            $j->update([
                'status' => 'rejected', 'batch_id' => null, 'snapshot' => null, 'submitted_at' => null,
                'rejected_at' => now(), 'rejected_by' => $actor->id, 'reject_reason' => mb_substr($reason, 0, 500),
            ]);
            if (! $b->journals()->exists()) {
                $b->delete();
            }
            $this->audit($actor->id, 'work_journal_reject', null, json_encode([
                'date' => $j->date->toDateString(), 'user' => $j->user?->name, 'reason' => $reason,
            ], JSON_UNESCAPED_UNICODE));
        });
        $this->push($j->user, __('journal.push.reject_title'), __('journal.push.reject_body', ['date' => $j->date->format('m-d'), 'reason' => mb_substr($reason, 0, 80)]), 'wj-reject-'.$j->id, route('work-journal', ['date' => $j->date->toDateString()]));
    }

    public function canApprove(WorkJournalBatch $b, User $u): bool
    {
        $step = $b->currentStep();

        return $b->status === 'pending' && $step && $step->status === 'pending' && $step->approver_user_id === $u->id && $u->is_active;
    }

    /**
     * 승인 — 의견(인원별 + 전체)을 남기고 다음 단계로. 마지막이면 묶음·일지 전부 approved.
     *
     * @param  array<int,string>  $perJournal  work_journal_id => 의견
     */
    public function approve(WorkJournalBatch $b, User $actor, array $perJournal, ?string $overall): void
    {
        $b->load('steps');
        if (! $this->canApprove($b, $actor)) {
            throw new \DomainException('not_your_turn');
        }
        $memberIds = $b->journals()->pluck('id')->all();
        $next = null;

        DB::transaction(function () use ($b, $actor, $perJournal, $overall, $memberIds, &$next) {
            $step = $b->currentStep();
            $saved = [];
            foreach ($perJournal as $jid => $body) {
                $body = trim((string) $body);
                if ($body !== '' && in_array((int) $jid, $memberIds, true)) {
                    WorkJournalComment::create(['batch_id' => $b->id, 'step_id' => $step->id, 'work_journal_id' => (int) $jid, 'user_id' => $actor->id, 'body' => mb_substr($body, 0, 2000)]);
                    $saved[(int) $jid] = $body;
                }
            }
            $overall = trim((string) $overall);
            if ($overall !== '') {
                WorkJournalComment::create(['batch_id' => $b->id, 'step_id' => $step->id, 'work_journal_id' => null, 'user_id' => $actor->id, 'body' => mb_substr($overall, 0, 2000)]);
            }
            $step->update(['status' => 'approved', 'acted_at' => now()]);
            $next = $b->steps->first(fn ($s) => $s->seq > $step->seq && $s->status === 'pending');
            if ($next) {
                $b->update(['current_step' => $next->seq]);
            } else {
                $b->update(['status' => 'approved', 'current_step' => null, 'approved_at' => now()]);
                WorkJournal::where('batch_id', $b->id)->update(['status' => 'approved']);
            }
            $this->audit($actor->id, 'work_journal_approve', null, json_encode([
                'date' => $b->date->toDateString(), 'batch' => $b->seq, 'title' => $step->title,
                'comments' => count($saved) + ($overall !== '' ? 1 : 0), 'final' => $next === null,
            ], JSON_UNESCAPED_UNICODE));
        });

        $label = $b->date->format('m-d');
        if ($next) {
            $this->push($next->approver, __('journal.push.turn_title'), __('journal.push.turn_body', ['date' => $label, 'title' => $next->title]), 'wj-turn-'.$b->id);
        } else {
            foreach (User::whereIn('id', WorkJournal::where('batch_id', $b->id)->pluck('user_id'))->get() as $w) {
                $this->push($w, __('journal.push.done_title'), __('journal.push.done_body', ['date' => $label]), 'wj-done-'.$b->id, route('work-journal'));
            }
        }
    }

    /** 지금 내 차례인 묶음 수 — 사이드바 배지. */
    public function myTurnCount(User $u): int
    {
        if (! $u->isJournalApprover()) {
            return 0;
        }

        return WorkJournalBatch::where('status', 'pending')
            ->whereHas('steps', fn ($q) => $q->whereColumn('work_journal_steps.seq', 'work_journal_batches.current_step')->where('approver_user_id', $u->id))
            ->count();
    }

    // ───────────── 마감 알림 ─────────────

    /**
     * 마감 알림(평일, 기능설정 시각 이후 하루 1번) — 그날 열린 묶음의 지금 차례 결재자에게 「상신 N · 미상신 M」.
     * 반환 = 보냈으면 true. 하루 1번 = Setting `work_journal_cutoff_notified_on`.
     */
    public function notifyCutoff(?Carbon $now = null): bool
    {
        $now ??= now();
        $today = $now->toDateString();
        if ($now->isWeekend() || $now->format('H:i') < $this->cutoff() || Setting::get('work_journal_cutoff_notified_on') === $today) {
            return false;
        }
        $submitted = WorkJournal::whereDate('date', $today)->whereIn('status', ['submitted', 'approved'])->count();
        $missing = max(0, $this->writers()->count() - $submitted);
        $leader = User::where('approval_title', User::TEAM_LEADER)->where('permission', 'manager')->where('is_active', true)->first();
        $batch = WorkJournalBatch::whereDate('date', $today)->where('status', 'pending')->with('steps.approver')->orderBy('seq')->first();
        $to = $batch?->currentStep()?->approver ?? $leader;
        if (! $to) {
            return false;   // 받을 사람(팀장)이 아직 없다 — 「보냄」으로 찍지 않아 그날 지정되면 다음 확인 때 보낸다
        }
        Setting::updateOrCreate(['key' => 'work_journal_cutoff_notified_on'], ['value' => $today, 'type' => 'string', 'description' => '업무일지 마감 알림 보낸 날(하루 1번)']);
        $this->audit(null, 'work_journal_cutoff', null, json_encode(['date' => $today, 'submitted' => $submitted, 'missing' => $missing, 'to' => $to?->name], JSON_UNESCAPED_UNICODE));
        $this->push($to, __('journal.push.cutoff_title'), __('journal.push.cutoff_body', ['submitted' => $submitted, 'missing' => $missing]), 'wj-cutoff-'.$today);

        return true;
    }

    // ───────────── 공통 ─────────────

    private function push(?User $u, string $title, string $body, string $tag, ?string $url = null): void
    {
        if (! $u || ! $u->is_active || ! WebPushService::configured()) {
            return;
        }
        try {
            app(WebPushService::class)->sendToUser($u, ['title' => $title, 'body' => $body, 'url' => $url ?? route('work-journal.approvals'), 'tag' => $tag, 'sticky' => true]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function audit(?int $userId, string $action, ?string $old, ?string $new): void
    {
        BoardAuditLog::create([
            'user_id' => $userId, 'purchase_listing_id' => null, 'action' => $action, 'field' => $action,
            'old_value' => $old, 'new_value' => $new, 'ip_address' => BoardAuditLog::currentIp(),
        ]);
    }
}

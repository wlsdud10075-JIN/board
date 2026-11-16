<?php

// 씬 점검 되돌리기(업무일지) — 오늘 날짜 업무일지 데이터(일지·줄·기안·결재 단계·의견)를 **전 사용자** 지우고, 결재 직급 4명을 확인한다.
//   같은 날 다시 돌릴 수 있게 하려는 것(일지는 사람·날짜 1개, 기안은 (날짜, 순번) 유일).
// 🚫 모델 이벤트 없이(DB::table) · 지난 날짜 데이터는 건드리지 않는다 · 감사 로그는 남긴다(append-only).
// 결재 직급: 팀장=leader(업무관리자) · 부장=bujang · 상무=sangmu · 대표=ceo(최고관리자). 비었으면 채우고, 다른 사람이 그 직급을 갖고 있으면 멈춘다.

$boot = __DIR__.'/../boot.php';
require $boot;

use Illuminate\Support\Facades\DB;

$out = [];
$stop = function (string $why) {
    echo json_encode(['ok' => false, 'problem' => $why], JSON_UNESCAPED_UNICODE), "\n";
    exit(1);
};

$today = DB::selectOne('select curdate() as d')->d;

// 오늘 업무일지 — 자식부터
$batchIds = DB::table('work_journal_batches')->whereDate('date', $today)->pluck('id');
$journalIds = DB::table('work_journals')->whereDate('date', $today)->pluck('id');
$c = DB::table('work_journal_comments')->whereIn('batch_id', $batchIds)->orWhereIn('work_journal_id', $journalIds)->delete();
$s = DB::table('work_journal_steps')->whereIn('batch_id', $batchIds)->delete();
$e = DB::table('work_journal_entries')->whereIn('work_journal_id', $journalIds)->delete();
$j = DB::table('work_journals')->whereIn('id', $journalIds)->delete();
$b = DB::table('work_journal_batches')->whereIn('id', $batchIds)->delete();
$out[] = "오늘({$today}) 업무일지 삭제 — 일지 {$j}·줄 {$e}·기안 {$b}·단계 {$s}·의견 {$c}";

// 결재 직급 4명
$line = [
    'leader@board.test' => ['팀장', 'manager'],
    'bujang@board.test' => ['부장', 'admin'],
    'sangmu@board.test' => ['상무', 'admin'],
    'ceo@board.test' => ['대표', 'admin'],
];
$fixed = [];
foreach ($line as $email => [$title, $perm]) {
    $u = DB::table('users')->where('email', $email)->first();
    if (! $u || ! $u->is_active || $u->permission !== $perm) {
        $stop("결재 계정 {$email} 이 없거나 비활성이거나 등급이 {$perm} 이 아님 — 손으로 확인");
    }
    if (DB::table('users')->where('approval_title', $title)->where('id', '!=', $u->id)->exists()) {
        $stop("다른 사용자가 결재 직급 {$title} 을 갖고 있음 — 결재선이 그 사람에게 감(손으로 확인)");
    }
    if ($u->approval_title !== $title) {
        DB::table('users')->where('id', $u->id)->update(['approval_title' => $title]);
        $fixed[] = $title;
    }
}
$out[] = $fixed === [] ? '결재 직급 팀장·부장·상무·대표 그대로' : '결재 직급 다시 채움: '.implode('·', $fixed);

// 작성자 두 명(김영업·이영업) — 등급 일반·활성이어야 업무일지를 쓴다
foreach (['guide-demo@local.test', 'lee@board.test'] as $email) {
    if (! DB::table('users')->where('email', $email)->where('permission', 'user')->where('is_active', true)->exists()) {
        $stop("작성자 계정 {$email} 이 없거나 등급 일반·활성이 아님");
    }
}

echo json_encode(['ok' => true, 'done' => $out], JSON_UNESCAPED_UNICODE), "\n";

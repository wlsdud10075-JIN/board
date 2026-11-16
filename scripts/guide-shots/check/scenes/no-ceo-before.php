<?php

// 씬 점검 before(업무일지 상신 막힘) — 부장·상무·대표 결재 직급을 잠깐 비운다(after 가 반드시 되돌린다).
//   오늘 열린(pending) 기안이 있으면 이영업이 거기 합류해 막힘이 안 뜨므로 오늘 pending 기안이 없어야 한다 — 있으면 멈춘다.

$boot = __DIR__.'/../boot.php';
require $boot;

use Illuminate\Support\Facades\DB;

if (DB::table('work_journal_batches')->whereRaw('date = curdate()')->where('status', 'pending')->exists()) {
    fwrite(STDERR, "오늘 결재 중인 기안이 있음 — 이영업이 거기 합류해 막힘이 안 뜬다(업무일지 씬이 중간에 멈췄는지 확인)\n");
    exit(1);
}
$n = DB::table('users')->whereIn('email', ['bujang@board.test', 'sangmu@board.test', 'ceo@board.test'])->update(['approval_title' => null]);
echo json_encode(['ok' => true, 'done' => ["부장·상무·대표 직급 비움 {$n}명"]], JSON_UNESCAPED_UNICODE), "\n";

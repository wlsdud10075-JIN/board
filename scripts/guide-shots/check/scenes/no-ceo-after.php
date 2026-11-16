<?php

// 씬 점검 after(업무일지 상신 막힘) — 부장·상무·대표 결재 직급을 되돌린다. 몇 번 돌려도 같은 결과(실패한 씬 뒤에도 돈다).

$boot = __DIR__.'/../boot.php';
require $boot;

use Illuminate\Support\Facades\DB;

foreach (['bujang@board.test' => '부장', 'sangmu@board.test' => '상무', 'ceo@board.test' => '대표'] as $email => $title) {
    DB::table('users')->where('email', $email)->update(['approval_title' => $title]);
}
echo json_encode(['ok' => true, 'done' => ['부장·상무·대표 직급 되돌림']], JSON_UNESCAPED_UNICODE), "\n";

<?php

// 포털 씬 추가 되돌리기 — portal-share 는 「김영업 → 관리자 공유 중」에서 시작해 해제·재공유 후 같은 상태로 끝난다.
// 씬이 중간에 실패해 공유가 풀린 채 남았으면 여기서 다시 공유로 돌린다. 이메일로 찾는다(고정 id 금지). 모델 이벤트 없이 pivot 한 줄만.

require __DIR__.'/../boot.php';

use Illuminate\Support\Facades\DB;

$stop = function (string $why) {
    echo json_encode(['ok' => false, 'problem' => $why], JSON_UNESCAPED_UNICODE), "\n";
    exit(1);
};

$sales = DB::table('users')->where('email', 'guide-demo@local.test')->where('is_active', true)->first();
$mgr = DB::table('users')->where('email', 'manager@board.test')->where('is_active', true)->first();
if (! $sales || $sales->role !== 'sales') {
    $stop('가이드 영업 계정(guide-demo@local.test)이 없거나 영업이 아님');
}
if (! $mgr || $mgr->role !== 'manager' || $mgr->permission !== 'user') {
    $stop('관리 계정(manager@board.test)이 없거나 「관리·일반 등급」이 아님 — 공유 후보에 안 뜬다');
}

$exists = DB::table('manager_salesman')->where('salesman_user_id', $sales->id)->where('manager_user_id', $mgr->id)->exists();
if (! $exists) {
    DB::table('manager_salesman')->insert(['salesman_user_id' => $sales->id, 'manager_user_id' => $mgr->id, 'created_at' => now(), 'updated_at' => now()]);
}

echo json_encode(['ok' => true, 'done' => ['김영업 → 관리자 포털 공유 '.($exists ? '그대로' : '다시 넣음')]], JSON_UNESCAPED_UNICODE), "\n";

<?php

// 씬 점검 2b(ERP 에 쓰는 씬) board 쪽 되돌리기 — 전용 영업 board-check@local.test(점검영업) · 차량번호 30가3001~3020 · 표식 「[board점검]」.
// ERP 쪽(그 차·요청·선적·클레임 미러)은 car-erp GuideSceneFixtureSeeder::resetBoardCheck 가 지운다(2026-11-16 car-erp 세션 합의, Jin 결정).
// board 는 board 행만: 그 번호 매물을 소프트 삭제(DB::table — 모델 이벤트가 ERP 전송·검차 큐 Job 을 부른다).

require __DIR__.'/../boot.php';

use Illuminate\Support\Facades\DB;

$u = DB::table('users')->where('email', 'board-check@local.test')->first();
if (! $u || ! $u->is_active || $u->role !== 'sales' || $u->permission !== 'user') {
    echo json_encode(['ok' => false, 'problem' => '점검 전용 영업 board-check@local.test 가 없거나 「일반·영업·활성」이 아님'], JSON_UNESCAPED_UNICODE), "\n";
    exit(1);
}
$n = DB::table('purchase_listings')->where('vehicle_number', 'like', '30가30%')->whereNull('deleted_at')->update(['deleted_at' => now()]);

echo json_encode(['ok' => true, 'done' => ["점검영업 확인 · 30가30xx 매물 {$n}건 지움"]], JSON_UNESCAPED_UNICODE), "\n";

<?php

// 씬 점검 추가 되돌리기 — 매입예정 씬(listing-register·listing-delete·self-inspection)이 만드는 점검 전용 차를 치운다.
// 🔎 점검 전용 차량번호만(가이드 예시 11가1001~1006 은 손대지 않는다):
//    11가1101 = 매입예정 등록 → (삭제와 재등록) / 11가1102 = 셀프검차매입 / 11가1103 = 삭제 씬 대안(거절 차, before 훅이 살림)
// 🚫 모델 이벤트 없이(DB::table) — 모델로 지우면 draft 는 ssancar 검차 큐 withdraw Job 을 부른다.
//    ⚠️ 그래서 여기서 지운 draft 는 로컬 ssancar(8002) 큐에 남는다(일일 대사 몫). 정상 흐름은 listing-delete 씬이 화면에서 지워 withdraw 로 정리한다.
// ⚠️ 실행마다 지운 행이 차량번호당 1개씩 쌓인다(soft delete) — 로컬 점검 데이터라 그대로 둔다.

require __DIR__.'/../boot.php';

use Illuminate\Support\Facades\DB;

$plates = ['11가1101', '11가1102', '11가1103'];
$out = [];
foreach ($plates as $p) {
    $n = DB::table('purchase_listings')->where('vehicle_number', $p)->whereNull('deleted_at')->update(['deleted_at' => now()]);
    $out[] = "{$p} 살아 있는 행 {$n}건 지움";
}

echo json_encode(['ok' => true, 'done' => $out], JSON_UNESCAPED_UNICODE), "\n";

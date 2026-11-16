<?php

// listing-delete-rejected 씬 before 훅 — 김영업의 「거절」 차 11가1103 을 한 대 살려 둔다(없으면 처음 한 번 만든다).
// 거절 차는 검차 큐 대상(draft)이 아니라서 화면에서 지워도 ssancar(8002) withdraw 가 안 나간다 → 바깥 쓰기 없는 삭제 씬.
// 🚫 모델 이벤트 없이(DB::table).

require __DIR__.'/../boot.php';

use Illuminate\Support\Facades\DB;

$uid = DB::table('users')->where('email', 'guide-demo@local.test')->value('id');
if (! $uid) {
    echo json_encode(['ok' => false, 'problem' => '가이드 계정 guide-demo@local.test 없음'], JSON_UNESCAPED_UNICODE), "\n";
    exit(1);
}
$row = [
    'created_by_user_id' => $uid, 'source' => 'encar', 'origin' => 'encar', 'status' => 'rejected', 'buyer_verdict' => 'rejected',
    'verdict_channel' => 'manual', 'region' => '경기 수원시', 'car_erp_vehicle_id' => null, 'deleted_at' => null, 'updated_at' => now(),
];
// 다른 활성 행이 있으면(지난 실행이 중간에 멈춤) 먼저 지운다 — 같은 차량번호는 하나만 살아 있어야 한다.
DB::table('purchase_listings')->where('vehicle_number', '11가1103')->whereNull('deleted_at')->update(['deleted_at' => now()]);
$last = DB::table('purchase_listings')->where('vehicle_number', '11가1103')->orderByDesc('id')->first();
if ($last) {
    DB::table('purchase_listings')->where('id', $last->id)->update($row);
    $id = $last->id;
} else {
    $id = DB::table('purchase_listings')->insertGetId($row + ['vehicle_number' => '11가1103', 'created_at' => now()]);
}

echo json_encode(['ok' => true, 'done' => ["11가1103(#{$id}) → 거절·김영업"]], JSON_UNESCAPED_UNICODE), "\n";

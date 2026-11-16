<?php

// 씬 점검 되돌리기 — 점검 씬이 바꿔 놓은 가이드용 가짜 데이터를 「씬 시작 전」으로 되돌린다.
// 🔎 고정 id 가 아니라 차량번호·이메일로 찾는다. 예시가 없으면 **만들지 않고 멈춘다**(무엇을 지웠는지 모르는 채 새로 만들면 다른 씬이 깨진다).
// 🚫 migrate:fresh 없음 · 모델 이벤트 없이 쓴다(DB::table) — won 은 ERP 전송, draft 는 ssancar 검차 큐 Job 을 부른다.

require __DIR__.'/boot.php';

use Illuminate\Support\Facades\DB;

$out = [];
$stop = function (string $why) {
    echo json_encode(['ok' => false, 'problem' => $why], JSON_UNESCAPED_UNICODE), "\n";
    exit(1);
};

foreach (['guide-demo@local.test', 'leader@board.test', 'manager@board.test'] as $email) {
    if (! DB::table('users')->where('email', $email)->where('is_active', true)->exists()) {
        $stop("가이드 계정이 없거나 비활성: {$email}");
    }
}

$listing = fn (string $plate) => DB::table('purchase_listings')->where('vehicle_number', $plate)->orderByDesc('id')->first();

// 중복 등록 막힘(막혔을 때) — 이미 있는 차. 그대로 있어야 한다.
$l = $listing('11가1004');
if (! $l || $l->deleted_at !== null) {
    $stop('예시 차 11가1004 가 없거나 삭제됨');
}

// 구매확정 막힘(막혔을 때) — 바이어 없는 구매대기 차. 차값 비우기 단계가 저장하므로 되돌린다.
$l = $listing('11가1006');
if (! $l) {
    $stop('예시 차 11가1006 이 없음');
}
if (DB::table('inspection_photos')->where('purchase_listing_id', $l->id)->exists()) {
    $stop('11가1006 에 첨부가 생김 — 「첨부 없음」 단계가 의미를 잃는다(손으로 확인)');
}
DB::table('purchase_listings')->where('id', $l->id)->update([
    'status' => 'accepted', 'buyer_verdict' => 'accepted', 'car_cost' => 9500000, 'car_erp_buyer_id' => null, 'car_erp_vehicle_id' => null, 'deleted_at' => null,
]);
$out[] = '11가1006 → 구매대기·차값 9,500,000';

// 관리자 화면에서 고치기 — 회신대기·지역 경기 수원시로, 지웠으면 살린다.
$l = $listing('11가1005');
if (! $l) {
    $stop('예시 차 11가1005 가 없음');
}
DB::table('purchase_listings')->where('id', $l->id)->update([
    'status' => 'awaiting_buyer', 'buyer_verdict' => 'pending', 'region' => '경기 수원시', 'deleted_at' => null,
]);
$out[] = '11가1005 → 회신대기·경기 수원시(삭제 해제)';

// 사용자 관리 — 점검이 만드는 계정을 지운다(다시 만들 때 이메일 중복으로 막힘).
$n = DB::table('users')->where('email', 'guide-new@local.test')->delete();
$out[] = "가이드 신입 계정 삭제 {$n}건";

// 클레임 14일 초과(막혔을 때) — 사유 없이 검토 중이어야 한다.
$c = DB::table('claims')->where('vehicle_number', '13라2053')->orderByDesc('id')->first();
if (! $c || $c->status !== 'reviewing' || trim((string) $c->overdue_reason) !== '' || DB::table('claim_steps')->where('claim_id', $c->id)->exists()) {
    $stop('클레임 13라2053 이 「검토·사유 없음·결재선 없음」이 아님 — 손으로 확인');
}

// 처음 쓰기 — 영어 전환 버튼은 기능설정이 켜져 있어야 보인다(운영 두 박스는 켜져 있음). 김영업은 한국어로.
DB::table('settings')->updateOrInsert(['key' => 'locale_en_enabled'], ['value' => '1', 'type' => 'boolean']);
DB::table('users')->where('email', 'guide-demo@local.test')->update(['locale' => 'ko']);
$out[] = '영어 전환 켬 · 김영업 한국어';

echo json_encode(['ok' => true, 'done' => $out], JSON_UNESCAPED_UNICODE), "\n";

<?php

// 씬 점검 2b board 쪽 추가 되돌리기 — claim-erp-send(30가3014) · transport-balance(30가3015) 가 board 에 남기는 클레임·탁송 행.
// erp-check.php 는 30가30xx 매물만 소프트 삭제한다 — 클레임(claims·claim_steps·claim_files)·탁송(transport_requests·recipients·files)은 남는다.
// 그 행은 다음 실행을 바꾼다(포털 판매내역 칩 「접수」가 버튼을 가림 · 「열려 있는 배차 요청」 · defaultLine 이 마지막 결재선을 따라감).
// 범위 = 차량번호 30가3014~30가3020(이 묶음 몫) · 클레임은 영업이 board-check 여야(아니면 지우지 않고 멈춤, exit 1).
// 저장 파일(클레임 증빙·탁송 사진)도 지운다. board_audit_logs·알림톡 로그(append-only)는 남긴다.

require __DIR__.'/../boot.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

$plates = array_map(fn ($n) => '30가'.$n, range(3014, 3020));
$uid = DB::table('users')->where('email', 'board-check@local.test')->value('id');
if (! $uid) {
    echo json_encode(['ok' => false, 'problem' => 'board-check@local.test 없음'], JSON_UNESCAPED_UNICODE), "\n";
    exit(1);
}

$claims = DB::table('claims')->whereIn('vehicle_number', $plates)->get(['id', 'salesman_user_id']);
$foreign = $claims->filter(fn ($c) => (int) $c->salesman_user_id !== (int) $uid)->count();
if ($foreign > 0) {
    echo json_encode(['ok' => false, 'problem' => "30가3014~3020 클레임 {$foreign}건이 점검영업 것이 아님 — 손으로 확인"], JSON_UNESCAPED_UNICODE), "\n";
    exit(1);
}
$listingIds = DB::table('purchase_listings')->whereIn('vehicle_number', $plates)->pluck('id')->all();   // 소프트 삭제 포함
$foreignL = DB::table('purchase_listings')->whereIn('id', $listingIds ?: [0])->where('created_by_user_id', '!=', $uid)->count();
if ($foreignL > 0) {
    echo json_encode(['ok' => false, 'problem' => "30가3014~3020 매물 {$foreignL}건이 점검영업 등록이 아님 — 손으로 확인"], JSON_UNESCAPED_UNICODE), "\n";
    exit(1);
}

$disk = Storage::disk(config('board.photo_disk'));
$claimIds = $claims->pluck('id')->all();
$reqIds = DB::table('transport_requests')->whereIn('purchase_listing_id', $listingIds ?: [0])->pluck('id')->all();
$paths = array_merge(
    DB::table('claim_files')->whereIn('claim_id', $claimIds ?: [0])->pluck('s3_path')->all(),
    DB::table('transport_request_files')->whereIn('transport_request_id', $reqIds ?: [0])->pluck('s3_path')->all(),
);

$n = DB::transaction(fn () => [
    'claim_files' => DB::table('claim_files')->whereIn('claim_id', $claimIds ?: [0])->delete(),
    'claim_steps' => DB::table('claim_steps')->whereIn('claim_id', $claimIds ?: [0])->delete(),
    'claims' => DB::table('claims')->whereIn('id', $claimIds ?: [0])->delete(),
    'transport_files' => DB::table('transport_request_files')->whereIn('transport_request_id', $reqIds ?: [0])->delete(),
    'transport_recipients' => DB::table('transport_request_recipients')->whereIn('transport_request_id', $reqIds ?: [0])->delete(),
    'transport_requests' => DB::table('transport_requests')->whereIn('id', $reqIds ?: [0])->delete(),
]);
$files = 0;
foreach ($paths as $p) {
    if ($p && $disk->exists($p)) {
        $disk->delete($p);
        $files++;
    }
}
foreach ($claimIds as $id) {
    $disk->deleteDirectory(config('board.claims_prefix').'/'.$id);
}

echo json_encode(['ok' => true, 'done' => ['30가3014~3020 클레임 '.$n['claims'].'(결재 '.$n['claim_steps'].'·증빙 '.$n['claim_files'].') · 탁송 요청 '.$n['transport_requests'].'(수신 '.$n['transport_recipients'].'·사진 '.$n['transport_files'].') · 파일 '.$files.' 지움']], JSON_UNESCAPED_UNICODE), "\n";

<?php

// 씬 점검 2b — 탁송 업체 링크(알림톡 [배차 확인] 버튼 링크와 같은 것) = 그 차의 열린 배차 요청 · 첫 업체 번호 수신자 서명 링크를 한 줄로 찍는다.
//   php transport-link.php 30가3015   → 「배차 요청중」 요청이 없으면 멈춘다(exit 1). 결과·레포에 안 남김(러너 gotoPhp 가 읽음).

require __DIR__.'/../boot.php';

use App\Models\TransportRequest;
use App\Models\TransportRequestRecipient;
use Illuminate\Support\Facades\DB;

//   php transport-link.php 30가3015 decided  → 처리 끝난(배차완료·거절) 요청의 두 번째 업체 번호 링크 = 「이미 처리된 요청입니다」 화면 확인용.
$plate = (string) ($argv[1] ?? '');
$decided = ($argv[2] ?? '') === 'decided';
$lid = DB::table('purchase_listings')->where('vehicle_number', $plate)->whereNull('deleted_at')->value('id');
$req = $lid ? TransportRequest::where('purchase_listing_id', $lid)->whereIn('status', $decided ? ['accepted', 'rejected'] : ['sent'])->latest('id')->first() : null;
if (! $req) {
    echo "배차 요청중인 요청 없음: {$plate}\n";
    exit(1);
}
$rc = $req->recipients()->where('kind', TransportRequestRecipient::KIND_COMPANY)->orderBy('id')->skip($decided ? 1 : 0)->first();
if (! $rc) {
    echo "업체 번호 수신자 없음\n";
    exit(1);
}
echo $req->linkFor($rc), "\n";

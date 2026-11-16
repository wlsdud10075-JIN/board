<?php

// 씬 점검 2b — 클레임 결재 링크(알림톡 버튼 링크와 같은 것) = 지금 차례 결재자 서명 링크를 한 줄로 찍는다(러너 gotoPhp 가 읽음, 결과·레포에 안 남김).
//   php claim-link.php 30가3014 부장   → 그 차의 board-check 클레임이 「결재 중 · 지금 차례 = 부장」이 아니면 멈춘다(exit 1).
// 로컬·board DB 에서만(boot.php).

require __DIR__.'/../boot.php';

use App\Models\Claim;
use Illuminate\Support\Facades\DB;

[$plate, $title] = [(string) ($argv[1] ?? ''), (string) ($argv[2] ?? '')];
$uid = DB::table('users')->where('email', 'board-check@local.test')->value('id');
$c = Claim::where('vehicle_number', $plate)->where('salesman_user_id', $uid)->latest('id')->first();
if (! $c || ! $c->isPendingCeo()) {
    echo "결재 중인 클레임 없음: {$plate}\n";
    exit(1);
}
$step = $c->currentStep();
if (! $step || $step->title !== $title || ! $step->approver) {
    echo '지금 차례가 '.$title.' 아님: '.($step?->title ?? '—')."\n";
    exit(1);
}
echo $c->approvalLinkFor($step->approver), "\n";

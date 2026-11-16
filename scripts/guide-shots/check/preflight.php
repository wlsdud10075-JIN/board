<?php

// 씬 점검 사전 확인 — 고치지 않고 멈춘다. 결과 = JSON 한 줄 {ok, problems[]}.
//   ① 로컬·board DB(boot.php) ② 알림톡 마스터 스위치 꺼짐(켜져 있으면 점검 중 실제 알림톡이 나갈 수 있다)

require __DIR__.'/boot.php';

$problems = [];
if (App\Support\AlimtalkConfig::active()->enabled) {
    $problems[] = '알림톡 마스터 스위치가 켜져 있습니다 — 기능설정에서 끄고 다시 돌리세요(로컬 점검이 실제 알림톡을 보낼 수 있음).';
}

echo json_encode(['ok' => $problems === [], 'problems' => $problems], JSON_UNESCAPED_UNICODE), "\n";
exit($problems === [] ? 0 : 1);

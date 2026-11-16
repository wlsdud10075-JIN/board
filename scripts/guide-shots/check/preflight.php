<?php

// 씬 점검 사전 확인 — 고치지 않고 멈춘다. 결과 = JSON 한 줄 {ok, problems[], notes[]}.
//   ① 로컬·board DB(boot.php) ② board 알림톡 마스터 스위치 꺼짐(켜져 있으면 점검 중 실제 알림톡이 나갈 수 있다)
//   ③ 로컬 ERP 알림톡도 꺼짐 — board 요청을 받은 로컬 ERP 가 BizM 으로 보낼 수 있다. car-erp 가 준 읽기 전용 판정 스크립트(dev 에만 있음)를 부른다(2026-11-16).

require __DIR__.'/boot.php';

$problems = [];
$notes = [];
if (App\Support\AlimtalkConfig::active()->enabled) {
    $problems[] = 'board 알림톡 마스터 스위치가 켜져 있습니다 — 기능설정에서 끄고 다시 돌리세요(로컬 점검이 실제 알림톡을 보낼 수 있음).';
}

$erpGuard = 'C:/xampp/htdocs/car-erp/scripts/scene/check/alimtalk-guard.php';
if (is_file($erpGuard)) {
    $out = trim((string) shell_exec('php '.escapeshellarg($erpGuard).' 2>&1'));
    $j = json_decode($out, true);
    if (! is_array($j)) {
        $problems[] = '로컬 ERP 알림톡 판정을 못 읽음: '.mb_substr($out, 0, 120);
    } elseif (! empty($j['enabled'])) {
        $problems[] = '로컬 ERP 알림톡 마스터 스위치가 켜져 있습니다 — ERP 기능설정에서 끄고 다시 돌리세요.';
    }
} else {
    $notes[] = '로컬 ERP 알림톡 판정 스크립트 없음 — ERP 에 쓰는 씬은 돌리지 마세요.';
}

echo json_encode(['ok' => $problems === [], 'problems' => $problems, 'notes' => $notes], JSON_UNESCAPED_UNICODE), "\n";
exit($problems === [] ? 0 : 1);

<?php

// 씬 점검 DB 조회 — SELECT 한 문장만 받아 첫 행을 JSON 으로 돌려준다(쓰기 금지).
//   php db.php "select status from purchase_listings where vehicle_number = ?" 11가1005

require __DIR__.'/boot.php';

$sql = trim((string) ($argv[1] ?? ''));
if (! preg_match('/^select\s/i', $sql) || str_contains($sql, ';')) {
    fwrite(STDERR, "SELECT 한 문장만 받습니다\n");
    exit(2);
}
$row = Illuminate\Support\Facades\DB::selectOne($sql, array_slice($argv, 2));
echo json_encode($row === null ? null : (array) $row, JSON_UNESCAPED_UNICODE), "\n";

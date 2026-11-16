<?php

// 씬 점검 결과를 관제센터 허브(heymancar.com) 「우리 루틴」에 한 줄 기록 — 서명 POST /api/internal/ops/mark (2026-11-16 Jin).
//   php ops-mark.php ok "통과 5 / 실패 0"      (status = ok | failed | todo)
// 서명 = car-erp OpsSignature v2: METHOD\npath?query\nts\nnonce\nbody, sha256 HMAC, 시크릿 = OPS_HMAC_SECRET(운영 두 박스와 같은 값, 로컬 .env).
// ⚠️ 운영 허브에 쓰는 유일한 경로 — 씬 점검 러너가 --mark 일 때만 부른다. 로컬·board DB 가 아니면 boot.php 가 멈춘다.

require __DIR__.'/boot.php';

const HUB = 'https://heymancar.com';
const KEY = 'board-scene-check';
const PATH = '/api/internal/ops/mark';

$status = (string) ($argv[1] ?? 'ok');
$note = mb_substr((string) ($argv[2] ?? ''), 0, 500);
$secret = (string) config('services.ops.hmac_secret');
if ($secret === '') {
    fwrite(STDERR, "OPS_HMAC_SECRET 없음 — 로컬 .env 에 넣어야 관제에 기록된다\n");
    exit(2);
}

$body = json_encode(['key' => KEY, 'status' => $status, 'note' => $note], JSON_UNESCAPED_UNICODE);
$ts = time();
$nonce = (string) Illuminate\Support\Str::uuid();
$canonical = "POST\n".PATH."?\n".$ts."\n".$nonce."\n".$body;
$res = Illuminate\Support\Facades\Http::timeout(20)
    ->withHeaders(['X-Timestamp' => (string) $ts, 'X-Nonce' => $nonce, 'X-Board-Signature' => 'sha256='.hash_hmac('sha256', $canonical, $secret)])
    ->withBody($body, 'application/json')->post(HUB.PATH);

echo json_encode(['status' => $res->status(), 'body' => mb_substr($res->body(), 0, 200)], JSON_UNESCAPED_UNICODE), "\n";
exit($res->successful() ? 0 : 1);

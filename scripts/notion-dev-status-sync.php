<?php

/**
 * Notion 「개발 현황판」 ← board 배포 이력 동기화 (2026-11-22 Jin «새벽에 자동으로 우리(ERP)처럼 되게 하고 누락되는 거 없게»).
 *
 *   원본 = **git `origin/master` 의 first-parent 커밋**. board 는 master 푸시 = 두 박스(heymanboard·ssancarboard) 자동배포라
 *   master 커밋 하나 = 배포 하나 = 카드 하나. 따로 적는 배포 기록 파일이 없다 → 적는 걸 잊어서 빠지는 일이 없다.
 *   (car-erp 는 `docs/operations/aws-deployment-record.md` 를 읽는다 — 같은 현황판, 원본만 다르다. 그쪽 파일을 복사하지 않는다.)
 *
 *   카드 제목 = 「📦 2026-11-20 (2) — board master e6c25c0 · 요약」. 요약 = 머지 커밋 제목의 「— 」 뒤, 없으면 그 배포에 든 첫 기능 커밋 제목.
 *   본문 = 그 배포에 들어간 커밋 제목들(문서·테스트 전용 커밋 제외). 상태 완료 · 대표 진 · 분류 운영·배포+BOARD · 앱 board.
 *   한 번 올린 카드는 다시 안 건드린다(기록용). 있는지 판정 = 현황판 제목에 「board master <해시 7자>」 가 있나.
 *
 * 사용 (NOTION_TOKEN 환경변수 필요, board 레포 어디서 불러도 됨):
 *   php scripts/notion-dev-status-sync.php --verify             # 읽기 전용 — 빠진 카드 목록. 빠진 게 있으면 exit 1(실패 아님)
 *   php scripts/notion-dev-status-sync.php --apply              # 빠진 카드를 날짜순으로 올린다
 *   php scripts/notion-dev-status-sync.php --verify --since=2026-11-01
 *   php scripts/notion-dev-status-sync.php --list               # Notion 을 안 부르고 만들 카드만 본다(토큰 불필요)
 *
 * 야간: 회사 PC `nightly-notion.ps1`(car-erp 레포, 04:30)이 --verify → (-Apply 면) --apply 를 직접 돌린다(LLM 없음).
 * 시작일 = 2026-09-18 — 현황판의 board 수동 카드가 2026-09-17 배포분(대표계약금)에서 멈춰 있었다(2026-11-22 실측).
 */
$token = getenv('NOTION_TOKEN') ?: '';
$apply = in_array('--apply', $argv, true);
$listOnly = in_array('--list', $argv, true);
$since = '2026-09-18';
foreach ($argv as $a) {
    if (str_starts_with($a, '--since=')) {
        $since = substr($a, 8);
    }
}
$base = 'https://api.notion.com/v1';
$version = '2022-06-28';
$repo = realpath(__DIR__.'/..');

if (! $listOnly && $token === '') {
    fwrite(STDERR, "❌ NOTION_TOKEN 설정 필요\n");
    exit(2);
}

function git(string $repo, string $args): array
{
    $out = [];
    $code = 0;
    exec('git -C '.escapeshellarg($repo).' '.$args.' 2>&1', $out, $code);
    if ($code !== 0) {
        fwrite(STDERR, "❌ git 실패: $args\n".implode("\n", $out)."\n");
        exit(2);
    }

    return $out;
}

function notion(string $method, string $url, array $body, string $token, string $version): array
{
    for ($try = 1; $try <= 4; $try++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$token, 'Content-Type: application/json', 'Notion-Version: '.$version],
            CURLOPT_POSTFIELDS => $body ? json_encode($body, JSON_UNESCAPED_UNICODE) : '{}',
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 60,
        ]);
        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        $json = json_decode((string) $raw, true) ?? [];
        if ($raw !== false && $code > 0 && ! in_array($code, [409, 429, 500, 502, 503, 504], true)) {
            if ($code >= 300) {
                fwrite(STDERR, "❌ Notion API 오류 ($code): ".($json['message'] ?? $raw)."\n");
                exit(2);
            }

            return $json;
        }
        usleep(400000 * $try);
    }
    fwrite(STDERR, '❌ Notion API 연결 실패: '.($error ?: '응답 없음')."\n");
    exit(2);
}

function rich(string $text): array
{
    $out = [];
    foreach (mb_str_split($text, 1900) as $chunk) {
        $out[] = ['type' => 'text', 'text' => ['content' => $chunk]];
    }

    return $out ?: [['type' => 'text', 'text' => ['content' => '']]];
}

function block(string $type, string $text): array
{
    return ['object' => 'block', 'type' => $type, $type => ['rich_text' => rich($text)]];
}

/** 「feat(auction): 내용」 → 「내용」. 제목 요약용. */
function bare(string $subject): string
{
    return trim((string) preg_replace('/^[a-z]+(\([^)]*\))?!?:\s*/u', '', $subject));
}

/** 문서·테스트만 고친 커밋 — 배포 내용이 아니다(업무 가이드 본문 `docs(guides)` 는 화면에 나가므로 남긴다). */
function isNoise(string $subject): bool
{
    return (bool) preg_match('/^(docs(?!\(guides?\))|test|chore\(docs\))(\([^)]*\))?:/u', $subject)
        || str_starts_with($subject, 'merge ') || str_starts_with($subject, 'Merge ');
}

// ── 1. 배포 이력 = origin/master first-parent ─────────────────────────────
$ref = 'origin/master';
exec('git -C '.escapeshellarg($repo).' rev-parse --verify -q '.$ref, $chk, $chkCode);
if ($chkCode !== 0) {
    $ref = 'master';
}
$rows = git($repo, 'log '.$ref.' --first-parent --reverse --since='.escapeshellarg($since.' 00:00:00').' --date=format:%Y-%m-%d --format=%H%x09%ad%x09%s');

$cards = [];
$perDay = [];
foreach ($rows as $row) {
    [$hash, $date, $subject] = array_pad(explode("\t", $row, 3), 3, '');
    if ($hash === '' || $date < $since) {
        continue;
    }
    $short = substr($hash, 0, 7);
    $parents = git($repo, 'rev-list --parents -n 1 '.$hash);
    $p = explode(' ', trim($parents[0] ?? ''));
    $items = [];
    if (count($p) >= 2) {   // 첫 부모가 있으면 그 사이에 들어온 커밋(머지면 dev 쪽 커밋들, 아니면 자기 자신)
        $items = count($p) >= 3
            ? git($repo, 'log '.$p[1].'..'.$hash.' --no-merges --reverse --format=%s')
            : [$subject];
    }
    $items = array_values(array_filter($items, fn ($s) => ! isNoise($s)));
    // 커밋 제목에 「ssancarboard 홍길동 …」처럼 제보한 직원 이름이 붙은 것은 이름만 가린다(현황판에 실명을 옮기지 않는다).
    $items = array_map(fn ($s) => (string) preg_replace('/(heymanboard|ssancarboard|heymanerp|ssancarerp)\s+[가-힣]{2,4}(?=\s)/u', '$1 직원', $s), $items);

    $summary = '';
    if (preg_match('/—\s*(.+)$/u', $subject, $m)) {
        $summary = trim($m[1]);
    } elseif ($items !== []) {
        $summary = bare($items[0]).(count($items) > 1 ? ' 외 '.(count($items) - 1).'건' : '');
    } else {
        $summary = '문서·테스트만';
    }

    $perDay[$date] = ($perDay[$date] ?? 0) + 1;
    $n = $perDay[$date];
    $title = '📦 '.$date.($n > 1 ? " ($n)" : '').' — board master '.$short.' · '.mb_substr($summary, 0, 110);

    $body = [block('paragraph', 'board 운영 배포 — heymanboard·ssancarboard 두 박스 동시(자동배포). master '.$short)];
    foreach (array_slice($items, 0, 90) as $s) {
        $body[] = block('bulleted_list_item', $s);
    }
    if (count($items) > 90) {
        $body[] = block('paragraph', '(이하 '.(count($items) - 90).'건 생략 — board 레포 git log 참조)');
    }
    if ($items === []) {
        $body[] = block('paragraph', '화면·동작 변경 없음(문서·테스트만 들어간 배포).');
    }
    $body[] = block('paragraph', '원본: board git origin/master '.$short.' · 자동 동기화 '.date('Y-m-d'));
    $cards[$short] = ['date' => $date, 'title' => $title, 'key' => 'board master '.$short, 'body' => $body, 'items' => count($items)];
}
echo '📄 board 배포 '.count($cards).'건 ('.$since." 이후, $ref)\n";

if ($listOnly) {
    foreach ($cards as $c) {
        echo '  '.$c['title'].' ['.$c['items']."줄]\n";
    }
    exit(0);
}

// ── 2. 현황판 찾기 + 기존 제목 ────────────────────────────────────────────
$search = notion('POST', "$base/search", ['filter' => ['property' => 'object', 'value' => 'database']], $token, $version);
$db = null;
foreach ($search['results'] ?? [] as $result) {
    if (($result['title'][0]['plain_text'] ?? '') === '개발 현황판') {
        $db = $result;
        break;
    }
}
if (! $db) {
    fwrite(STDERR, "❌ 개발 현황판을 찾지 못했습니다.\n");
    exit(2);
}
$dbId = $db['id'];

$titles = '';
$count = 0;
$cursor = null;
do {
    $query = ['page_size' => 100];
    if ($cursor) {
        $query['start_cursor'] = $cursor;
    }
    $pages = notion('POST', "$base/databases/$dbId/query", $query, $token, $version);
    foreach ($pages['results'] ?? [] as $page) {
        foreach ($page['properties']['제목']['title'] ?? [] as $part) {
            $titles .= $part['plain_text'] ?? '';
        }
        $titles .= "\n";
        $count++;
    }
    $cursor = ($pages['has_more'] ?? false) ? ($pages['next_cursor'] ?? null) : null;
} while ($cursor);
echo "📊 현황판 기존 카드 {$count}건\n";

// ── 3. 대조 / 발행 ───────────────────────────────────────────────────────
$missing = array_filter($cards, fn ($c) => ! str_contains($titles, $c['key']));
if ($missing === []) {
    echo "✅ 정합 — board 배포가 전부 현황판에 있습니다.\n";
    exit(0);
}
echo '🔎 대조 — 현황판에 없는 board 배포 '.count($missing)."건\n";
foreach ($missing as $c) {
    echo '  ❌ '.$c['title']."\n";
}
if (! $apply) {
    echo '⚠️ 불일치 '.count($missing)."건. --apply 로 올리세요.\n";
    exit(1);
}

$added = 0;
foreach ($missing as $c) {
    notion('POST', "$base/pages", [
        'parent' => ['database_id' => $dbId],
        'properties' => [
            '제목' => ['title' => [['text' => ['content' => $c['title']]]]],
            '상태' => ['select' => ['name' => '완료']],
            '대표' => ['select' => ['name' => '진']],
            '분류' => ['multi_select' => [['name' => '운영·배포'], ['name' => 'BOARD']]],
            '앱' => ['select' => ['name' => 'board']],
        ],
        'children' => $c['body'],
    ], $token, $version);
    echo '  ✅ '.$c['title']."\n";
    $added++;
}
echo "✅ 발행 완료: {$added}건\n";
exit(0);

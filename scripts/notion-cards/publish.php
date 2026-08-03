<?php

/**
 * publish.php — BOARD 챗봇 지식카드를 Notion
 *   "사내 업무 가이드 › 🛒 매입보드 (BOARD)" 아래의 별도
 *   「📇 기능 카드 (챗봇용)」 트리로 발행한다.
 *
 * 기존 전체 워크플로우/영업/검차/관리/에러·락 대처 페이지와
 * 허브 네비게이션은 수정하지 않는다.
 *
 * 각 카드 = heading_2(제목) + 본문 문단들.
 * 챗봇 색인은 H2 단위로 청크하므로 카드 하나가 검색 단위가 된다.
 *
 * 사용:
 *   php publish.php --verify              라이브 Notion ↔ cards.json 대조 (읽기 전용)
 *   php publish.php --card "제목"          카드 1장 dry-run
 *   php publish.php --card "제목" --apply  카드 1장 갱신 — 있으면 제자리 교체, 없으면 그룹 끝에 추가
 *   php publish.php                       전체 dry-run (쓰기 없음)
 *   php publish.php --apply               최초 발행 (이미 있으면 중단)
 *   php publish.php --apply --replace     전체 재발행 — 그룹 페이지째 교체
 *
 * ⭐ **평소 작업 = `--verify` → 어긋난 카드마다 `--card … --apply` → 다시 `--verify`.**
 *    car-erp `scripts/notion-cards/publish.php` 와 같은 흐름이다(그쪽은 audience 필수, board 는 없음).
 *
 * ⚠️ `--replace` 는 그룹 페이지 id 가 전부 바뀌므로 평소엔 쓰지 않는다. 그룹 구성 자체가
 *    달라졌을 때만 쓴다. 부모 페이지(「📇 기능 카드 (챗봇용)」)와 안내 문단은 유지된다.
 *    색인 source 경로는 페이지 제목으로 만들어지므로 그룹 id 가 바뀌어도 색인엔 영향이 없다.
 *
 * ⚠️ `--card --apply` 는 "기존 본문 삭제 → 새 본문 삽입" 순서다. 삽입에서 실패하면 그 카드가
 *    제목만 남을 수 있다. 같은 명령을 다시 실행하면 cards.json 에서 복원된다. 끝나면 항상 --verify.
 */
$token = getenv('NOTION_TOKEN') ?: '';
if ($token === '') {
    fwrite(STDERR, "❌ NOTION_TOKEN 없음\n");
    exit(1);
}

$notionVersion = '2022-06-28';
$boardSection = '37345d82-bd83-8150-beec-e006cb5b36e1'; // 🛒 매입보드 (BOARD)
$parentTitle = '📇 기능 카드 (챗봇용)';
$apply = in_array('--apply', $argv, true);
$replace = in_array('--replace', $argv, true);
$verify = in_array('--verify', $argv, true);
$cardArg = null;
foreach ($argv as $i => $a) {
    if ($a === '--card') {
        $cardArg = $argv[$i + 1] ?? null;
    }
}
$cards = json_decode(file_get_contents(__DIR__.'/cards.json'), true);

if (! is_array($cards) || $cards === []) {
    fwrite(STDERR, "❌ cards.json 로드 실패\n");
    exit(1);
}

// 제목 → 그룹 맵. --card 가 제목으로 그룹을 찾고, 중복 제목이면 어느 쪽인지 알 수 없어 거부한다.
$titles = [];
$dupes = [];
foreach ($cards as $g) {
    foreach ($g['cards'] ?? [] as $c) {
        $t = $c['title'] ?? '';
        if (isset($titles[$t])) {
            $dupes[] = $t;
        }
        $titles[$t] = $g['group'];
    }
}
if ($dupes) {
    fwrite(STDERR, '❌ cards.json 제목 중복: '.implode(', ', array_unique($dupes))."\n");
    exit(1);
}

function notion(string $method, string $url, array $body, string $token, string $version): array
{
    for ($try = 0; $try < 6; $try++) {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer '.$token,
                'Content-Type: application/json',
                'Notion-Version: '.$version,
            ],
            CURLOPT_POSTFIELDS => $body
                ? json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : ($method === 'GET' ? null : '{}'),
        ]);
        $raw = curl_exec($curl);
        $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($code === 429) {
            usleep(600000);

            continue;
        }

        $json = json_decode((string) $raw, true) ?: [];
        if ($code >= 300) {
            fwrite(STDERR, "❌ Notion {$method} ({$code}): ".($json['message'] ?? $raw)."\n");
            exit(1);
        }

        usleep(350000);

        return $json;
    }

    fwrite(STDERR, "❌ Notion 429 재시도 초과\n");
    exit(1);
}

function children(string $blockId, string $token, string $version): array
{
    $result = [];
    $cursor = null;

    do {
        $url = "https://api.notion.com/v1/blocks/{$blockId}/children?page_size=100"
            .($cursor ? '&start_cursor='.$cursor : '');
        $response = notion('GET', $url, [], $token, $version);
        foreach ($response['results'] ?? [] as $block) {
            $result[] = $block;
        }
        $cursor = ($response['has_more'] ?? false) ? ($response['next_cursor'] ?? null) : null;
    } while ($cursor);

    return $result;
}

function heading2(string $text): array
{
    return [
        'object' => 'block',
        'type' => 'heading_2',
        'heading_2' => [
            'rich_text' => [[
                'type' => 'text',
                'text' => ['content' => $text],
            ]],
        ],
    ];
}

function paragraph(string $label, string $value): array
{
    return [
        'object' => 'block',
        'type' => 'paragraph',
        'paragraph' => [
            'rich_text' => [
                [
                    'type' => 'text',
                    'text' => ['content' => $label.': '],
                    'annotations' => ['bold' => true],
                ],
                [
                    'type' => 'text',
                    'text' => ['content' => $value],
                ],
            ],
        ],
    ];
}

/** 카드 1장 → 블록 배열 (heading_2 + 본문 문단들). 발행·--card 공용. */
function cardBlocks(array $card): array
{
    $b = [heading2($card['title'])];
    foreach ($card['rows'] as [$label, $value]) {
        $b[] = paragraph($label, $value);
    }

    return $b;
}

/**
 * 그룹 페이지의 블록을 카드 단위로 자른다.
 *
 * @return list<array{title:string,h2id:string,blockIds:list<string>,rows:list<array{0:string,1:string}>,extra:list<array>}>
 */
function liveCards(string $pageId, string $t, string $v): array
{
    $out = [];
    $cur = null;
    foreach (children($pageId, $t, $v) as $b) {
        $type = $b['type'] ?? '';
        $rt = $b[$type]['rich_text'] ?? [];
        $text = '';
        foreach ($rt as $r) {
            $text .= $r['plain_text'] ?? '';
        }
        $text = trim($text);

        if ($type === 'heading_2') {
            if ($cur) {
                $out[] = $cur;
            }
            $cur = ['title' => $text, 'h2id' => $b['id'], 'blockIds' => [], 'rows' => [], 'extra' => []];

            continue;
        }
        if (! $cur || $text === '') {
            continue;
        }
        $cur['blockIds'][] = $b['id'];
        // 본문 문단 = bold 라벨 + ": " + 값 (paragraph() 가 만든 형태)
        if ($type === 'paragraph' && count($rt) >= 2 && ! empty($rt[0]['annotations']['bold'])) {
            $label = rtrim(trim($rt[0]['plain_text'] ?? ''), ':');
            $val = '';
            for ($i = 1; $i < count($rt); $i++) {
                $val .= $rt[$i]['plain_text'] ?? '';
            }
            $cur['rows'][] = [trim($label), trim($val)];
        } else {
            $cur['extra'][] = ['?'.$type => $text];   // 사람이 손으로 넣은 블록 = 다음 발행 때 사라진다
        }
    }
    if ($cur) {
        $out[] = $cur;
    }

    return $out;
}

/** 「📇 기능 카드 (챗봇용)」 부모 + 그룹 페이지 목록. 없으면 null. */
function findTree(string $section, string $parentTitle, string $t, string $v): ?array
{
    foreach (children($section, $t, $v) as $b) {
        if (($b['type'] ?? '') !== 'child_page' || ($b['child_page']['title'] ?? '') !== $parentTitle) {
            continue;
        }
        $groups = [];
        foreach (children($b['id'], $t, $v) as $k) {
            if (($k['type'] ?? '') === 'child_page') {
                $groups[$k['child_page']['title']] = $k['id'];
            }
        }

        return ['id' => $b['id'], 'groups' => $groups];
    }

    return null;
}

// ── --verify : 라이브 ↔ cards.json 대조 (읽기 전용) ──────
if ($verify) {
    $tree = findTree($boardSection, $parentTitle, $token, $notionVersion);
    if (! $tree) {
        fwrite(STDERR, "❌ 「{$parentTitle}」 페이지를 Notion 에서 못 찾음 — 최초 발행은 --apply\n");
        exit(1);
    }
    echo "🔎 대조 — 라이브 「{$parentTitle}」 ↔ cards.json\n\n";
    $problems = 0;
    $repoGroups = array_column($cards, 'group');
    foreach (array_diff($repoGroups, array_keys($tree['groups'])) as $g) {
        echo "  ❌ 라이브에 없는 그룹: $g\n";
        $problems++;
    }
    foreach (array_diff(array_keys($tree['groups']), $repoGroups) as $g) {
        echo "  ❌ cards.json 에 없는 그룹: $g\n";
        $problems++;
    }
    foreach ($cards as $g) {
        if (! isset($tree['groups'][$g['group']])) {
            continue;
        }
        $live = [];
        foreach (liveCards($tree['groups'][$g['group']], $token, $notionVersion) as $lc) {
            $live[$lc['title']] = $lc;
        }
        foreach ($g['cards'] as $c) {
            if (! isset($live[$c['title']])) {
                echo "  ❌ 라이브에 없는 카드: {$g['group']} / {$c['title']}\n";
                $problems++;

                continue;
            }
            $l = $live[$c['title']];
            unset($live[$c['title']]);
            $norm = fn ($rows) => array_map(fn ($r) => preg_replace('/\s+/u', ' ', $r[0].': '.$r[1]), $rows);
            $lr = $norm($l['rows']);
            $rr = $norm($c['rows']);
            if ($lr !== $rr) {
                echo "  ❌ 본문 불일치: {$c['title']}\n";
                foreach (array_diff($lr, $rr) as $x) {
                    echo '       ＋라이브만: '.mb_substr($x, 0, 100)."\n";
                }
                foreach (array_diff($rr, $lr) as $x) {
                    echo '       －repo만  : '.mb_substr($x, 0, 100)."\n";
                }
                $problems++;
            }
            if ($l['extra']) {
                echo '  ❌ 예상 밖 블록 '.count($l['extra'])."개: {$c['title']} (손으로 넣은 내용은 다음 발행 때 사라집니다)\n";
                $problems++;
            }
        }
        foreach ($live as $t2 => $_) {
            echo "  ❌ cards.json 에 없는 카드: {$g['group']} / $t2\n";
            $problems++;
        }
    }
    $total = array_sum(array_map(fn ($g) => count($g['cards'] ?? []), $cards));
    echo "\n".($problems === 0
        ? "✅ 정합 — 라이브와 cards.json 이 완전히 같습니다 (카드 {$total}장).\n"
        : "⚠️ 불일치 {$problems}건. cards.json 을 고친 뒤 --card \"제목\" --apply 로 반영하세요.\n");
    exit($problems === 0 ? 0 : 1);
}

// ── --card : 카드 1장 제자리 갱신 ────────────────────────
if ($cardArg !== null) {
    if (! isset($titles[$cardArg])) {
        fwrite(STDERR, "❌ cards.json 에 없는 카드: {$cardArg}\n   (제목은 정확히 일치해야 합니다)\n");
        exit(1);
    }
    $group = $titles[$cardArg];
    $card = null;
    foreach ($cards as $g) {
        foreach ($g['cards'] as $c) {
            if ($c['title'] === $cardArg) {
                $card = $c;
            }
        }
    }
    $tree = findTree($boardSection, $parentTitle, $token, $notionVersion);
    if (! $tree || ! isset($tree['groups'][$group])) {
        fwrite(STDERR, "❌ 라이브에서 그룹 페이지를 못 찾음: {$group}\n");
        exit(1);
    }
    $liveGroup = liveCards($tree['groups'][$group], $token, $notionVersion);
    $target = null;
    foreach ($liveGroup as $lc) {
        if ($lc['title'] === $cardArg) {
            $target = $lc;
        }
    }

    // 신규 카드 = 그룹 페이지 끝에 추가
    if (! $target) {
        $new = cardBlocks($card);
        $last = end($liveGroup) ?: null;
        $after = $last ? (end($last['blockIds']) ?: $last['h2id']) : null;
        echo ($apply ? '▶ APPLY' : '▶ DRY-RUN')." — 「{$group}」 / {$cardArg}  ★신규 추가\n";
        echo '   그룹 끝에 '.count($new)."블록 추가\n";
        if (! $apply) {
            echo "\n(쓰기 없음. 실제 추가: --card \"{$cardArg}\" --apply)\n";
            exit(0);
        }
        $body = ['children' => $new];
        if ($after !== null) {
            $body['after'] = $after;
        }
        notion('PATCH', "https://api.notion.com/v1/blocks/{$tree['groups'][$group]}/children", $body, $token, $notionVersion);
        echo "✅ 추가 완료. 다음 03:00 색인에 반영됩니다.\n   확인: php publish.php --verify\n";
        exit(0);
    }

    // 기존 카드 = 본문만 제자리 교체 (heading_2 유지 → 페이지 내 위치 보존)
    $new = cardBlocks($card);
    array_shift($new);
    echo ($apply ? '▶ APPLY' : '▶ DRY-RUN')." — 「{$group}」 / {$cardArg}\n";
    echo '   기존 본문 '.count($target['blockIds']).'블록 삭제 → 새 본문 '.count($new)."블록 삽입\n";
    if (! $apply) {
        echo "\n(쓰기 없음. 실제 갱신: --card \"{$cardArg}\" --apply)\n";
        exit(0);
    }
    foreach ($target['blockIds'] as $bid) {
        notion('DELETE', "https://api.notion.com/v1/blocks/$bid", [], $token, $notionVersion);
    }
    notion('PATCH', "https://api.notion.com/v1/blocks/{$tree['groups'][$group]}/children",
        ['children' => $new, 'after' => $target['h2id']], $token, $notionVersion);
    echo "✅ 완료. 다음 03:00 색인에 반영됩니다.\n   확인: php publish.php --verify\n";
    exit(0);
}

// 기존 가이드는 건드리지 않는다. 이미 발행된 카드 트리는 기본 보존(중단), --replace 일 때만 교체.
$existingParentId = null;
foreach (children($boardSection, $token, $notionVersion) as $block) {
    if (
        ($block['type'] ?? '') === 'child_page'
        && ($block['child_page']['title'] ?? '') === $parentTitle
    ) {
        $existingParentId = $block['id'];
        break;
    }
}

if ($existingParentId !== null && ! $replace) {
    fwrite(
        STDERR,
        "⚠️ 이미 존재: {$parentTitle} ({$existingParentId}) — 기존 페이지 보존을 위해 중단합니다.\n"
        ."   내용을 cards.json 으로 교체하려면: php publish.php --apply --replace\n"
    );
    exit(1);
}

if ($existingParentId === null && $replace) {
    fwrite(STDERR, "❌ --replace 인데 「{$parentTitle}」 페이지가 없습니다. 최초 발행은 --apply 로 하세요.\n");
    exit(1);
}

$totalCards = array_sum(array_map(
    static fn (array $group): int => count($group['cards'] ?? []),
    $cards
));

echo ($apply ? '▶ APPLY' : '▶ DRY-RUN').($replace ? ' (REPLACE — 기존 그룹 페이지 교체)' : '')
    ." — 「{$parentTitle}」 아래 그룹 ".count($cards)."개 · 카드 {$totalCards}장\n";
foreach ($cards as $group) {
    echo sprintf(
        "   · %-30s 카드 %d장\n",
        $group['group'],
        count($group['cards'])
    );
}

if (! $apply) {
    echo "\n(쓰기 없음. 실제 발행: php publish.php --apply)\n";
    exit(0);
}

if ($existingParentId !== null) {
    // 재발행 — 부모 페이지와 안내 문단은 그대로 두고 그룹 페이지만 걷어낸다.
    $parentId = $existingParentId;
    echo "\n♻️ 기존 부모 페이지 재사용: {$parentTitle} ({$parentId})\n";
    foreach (children($parentId, $token, $notionVersion) as $block) {
        if (($block['type'] ?? '') !== 'child_page') {
            continue;
        }
        notion('PATCH', "https://api.notion.com/v1/pages/{$block['id']}", ['archived' => true], $token, $notionVersion);
        echo "   🗑 이전 그룹 보관: {$block['child_page']['title']}\n";
    }
} else {
    $parent = notion('POST', 'https://api.notion.com/v1/pages', [
        'parent' => ['page_id' => $boardSection],
        'properties' => [
            'title' => [
                'title' => [[
                    'text' => ['content' => $parentTitle],
                ]],
            ],
        ],
        'children' => [[
            'object' => 'block',
            'type' => 'paragraph',
            'paragraph' => [
                'rich_text' => [[
                    'type' => 'text',
                    'text' => [
                        'content' => '사내 챗봇(업무 도우미)이 BOARD 질문에 답할 때 참고하는 기능 안내 카드입니다. 사이드바 화면별로 “어디서 · 무엇을 · 무엇을 적나 · 누가 · 어디에 반영되나”를 정리했습니다. 사람이 읽는 전체 업무 흐름은 기존 「전체 워크플로우」와 역할별 가이드를 참고하세요.',
                    ],
                ]],
            ],
        ]],
    ], $token, $notionVersion);

    $parentId = $parent['id'];
    echo "\n✅ 부모 페이지: {$parentTitle} ({$parentId})\n";
}

foreach ($cards as $group) {
    $blocks = [];
    foreach ($group['cards'] as $card) {
        foreach (cardBlocks($card) as $b) {
            $blocks[] = $b;
        }
    }

    $page = notion('POST', 'https://api.notion.com/v1/pages', [
        'parent' => ['page_id' => $parentId],
        'properties' => [
            'title' => [
                'title' => [[
                    'text' => ['content' => $group['group']],
                ]],
            ],
        ],
        'children' => $blocks,
    ], $token, $notionVersion);

    echo "   ✅ {$group['group']} — 카드 ".count($group['cards'])."장 ({$page['id']})\n";
}

echo "\n완료. Notion 새로고침 후 「{$parentTitle}」 확인. 이어서 챗봇 지식 재색인이 필요합니다.\n";
echo "PARENT_ID={$parentId}\n";

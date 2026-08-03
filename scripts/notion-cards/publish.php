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
 *   php publish.php                      # dry-run (쓰기 없음)
 *   php publish.php --apply              # 최초 발행 (이미 있으면 중단)
 *   php publish.php --apply --replace    # 재발행 — cards.json 으로 카드 내용 교체
 *
 * --replace 는 부모 페이지(「📇 기능 카드 (챗봇용)」)와 그 안내 문단은 그대로 두고,
 * 그 아래 그룹 페이지만 보관(archive) 처리한 뒤 cards.json 대로 다시 만든다.
 * 블록 단위로 지우면 API 호출이 수백 회라 그룹 페이지째 갈아끼운다.
 * 색인 source 경로는 페이지 제목으로 만들어지므로 그룹 페이지 id 가 바뀌어도 색인엔 영향이 없다.
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
$cards = json_decode(file_get_contents(__DIR__.'/cards.json'), true);

if (! is_array($cards) || $cards === []) {
    fwrite(STDERR, "❌ cards.json 로드 실패\n");
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
        $blocks[] = heading2($card['title']);
        foreach ($card['rows'] as [$label, $value]) {
            $blocks[] = paragraph($label, $value);
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

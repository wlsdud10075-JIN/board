<?php

/**
 * Notion "사내 업무 가이드" 허브에 매입보드(board) 업무 가이드를 발행(교체)한다.
 *
 * car-erp 의 scripts/notion-guide-publish.php 패턴을 미러 — 단, board 전용 중첩 구조:
 *   사내 업무 가이드(허브)
 *     └ 🛒 매입보드 (BOARD)   ← 기존 "영업" 페이지를 이 이름으로 승격·리네임
 *         ├ 영업 / ├ 검차 / └ 관리   (board 앱 화면·버튼 실무 가이드)
 *
 * 대상 허브 = "사내 업무 가이드" (board sidebar work_guide_url 가 가리키는 그 페이지).
 * 동작:
 *   - 부모 : 허브 하위 기존 "영업" 페이지를 "🛒 매입보드 (BOARD)" 로 리네임 + 인트로로 내용 교체.
 *            (이미 그 이름이면 인트로만 교체. 둘 다 없으면 허브 직속에 신규 생성.)
 *   - 영업/검차/관리 : 부모 하위 child page — 없으면 생성, 있으면 내용 교체.
 *   child_page(하위 페이지)는 보존 — 직접 만든 블록만 지운다.
 *
 * ⚠️ "영업" 페이지는 원래 car-erp/scripts/notion-guide-publish.php 가 발행하던 곳.
 *    리네임으로 car-erp 스크립트는 "영업"을 못 찾아 건너뛴다(재생성 안 함).
 *    허브는 앱별 2섹션 — board: "🛒 매입보드 (BOARD)" / car-erp: "🏢 ERP (car-erp)".
 *    (car-erp 4페이지를 ERP 부모로 옮기는 건 Notion API 미지원 → 수동 드래그.
 *     이후 car-erp 스크립트가 ERP 부모 하위를 타깃하도록 손봐야 중복 안 생김.)
 *
 * 확인(dry-run, 쓰기 X):
 *   $env:NOTION_TOKEN=[Environment]::GetEnvironmentVariable('NOTION_TOKEN','User'); php scripts/notion-guide-publish.php
 * 실제 발행:
 *   ... ; php scripts/notion-guide-publish.php --apply
 * 특정 페이지만:
 *   ... ; php scripts/notion-guide-publish.php --apply 검차
 */
$token = getenv('NOTION_TOKEN') ?: '여기에_토큰_붙여넣기';
$apply = in_array('--apply', $argv, true);
$only = array_values(array_intersect($argv, ['매입보드', '영업', '검차', '관리', '전체 워크플로우', '에러·락 대처'])); // 비어있으면 전체
$HUB_TITLE = '사내 업무 가이드';
$V = '2022-06-28';
$BASE = 'https://api.notion.com/v1';

if (str_contains($token, '여기에_')) {
    fwrite(STDERR, "❌ NOTION_TOKEN 설정 필요\n");
    exit(1);
}

function notion(string $m, string $url, array $body, string $t, string $v): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $m, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$t, 'Content-Type: application/json', 'Notion-Version: '.$v],
        CURLOPT_POSTFIELDS => $body ? json_encode($body, JSON_UNESCAPED_UNICODE) : '{}',
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $j = json_decode($res, true) ?? [];
    if ($code >= 300) {
        fwrite(STDERR, "❌ Notion API ($code): ".($j['message'] ?? $res)."\n");
        exit(1);
    }

    return $j;
}

function notionDeleteBlock(string $id, string $t, string $v): void
{
    $url = "https://api.notion.com/v1/blocks/$id";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'DELETE', CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$t, 'Content-Type: application/json', 'Notion-Version: '.$v],
        CURLOPT_POSTFIELDS => '{}',
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $j = json_decode($res, true) ?? [];

    if ($code < 300) {
        return;
    }

    $msg = $j['message'] ?? $res;
    if ($code === 400 && str_contains($msg, 'archived')) {
        return;
    }

    fwrite(STDERR, "❌ Notion API ($code): $msg\n");
    exit(1);
}

// ── rich text + block builders ──────────────────────────────
function seg(string $t, array $ann = []): array
{
    $s = ['type' => 'text', 'text' => ['content' => $t]];
    if ($ann) {
        $s['annotations'] = $ann;
    }

    return $s;
}
function tx(string $t): array
{
    return [seg($t)];
}
function h2(string $t): array
{
    return ['object' => 'block', 'type' => 'heading_2', 'heading_2' => ['rich_text' => tx($t)]];
}
function h3(string $t): array
{
    return ['object' => 'block', 'type' => 'heading_3', 'heading_3' => ['rich_text' => tx($t)]];
}
function para(string $t): array
{
    return ['object' => 'block', 'type' => 'paragraph', 'paragraph' => ['rich_text' => tx($t)]];
}
function pararich(array $segs): array
{
    return ['object' => 'block', 'type' => 'paragraph', 'paragraph' => ['rich_text' => $segs]];
}
function num(string $t): array
{
    return ['object' => 'block', 'type' => 'numbered_list_item', 'numbered_list_item' => ['rich_text' => tx($t)]];
}
function bul(string $t): array
{
    return ['object' => 'block', 'type' => 'bulleted_list_item', 'bulleted_list_item' => ['rich_text' => tx($t)]];
}
function todo(string $t): array
{
    return ['object' => 'block', 'type' => 'to_do', 'to_do' => ['rich_text' => tx($t), 'checked' => false]];
}
function callout(string $e, string $t, string $c = 'gray_background'): array
{
    return ['object' => 'block', 'type' => 'callout', 'callout' => ['icon' => ['type' => 'emoji', 'emoji' => $e], 'color' => $c, 'rich_text' => tx($t)]];
}
function divider(): array
{
    return ['object' => 'block', 'type' => 'divider', 'divider' => (object) []];
}
function linkpage(string $pageId): array
{
    return ['object' => 'block', 'type' => 'link_to_page', 'link_to_page' => ['type' => 'page_id', 'page_id' => $pageId]];
}
function footer(string $dept): array
{
    return callout('🕒', "SSANCAR 매입보드 업무 가이드 · $dept · 2026-07-24 갱신. 이 아래에는 현장에서 발견한 메모를 매일 1~2줄씩 남기세요. 화면 캡처도 여기에.", 'gray_background');
}

// ════════════════════════════════════════════════════════════
//  영업 (board /listings + 회신 + /auction)
// ════════════════════════════════════════════════════════════
function blocks_sales(): array
{
    $b = [];
    $b[] = callout('🛒', '이 페이지는 「매입보드」 앱의 영업 실무 가이드입니다. 매입보드는 차량을 사기 전부터 구매확정까지 쓰는 앱이고, 구매확정된 차만 ERP로 자동 넘어갑니다.', 'blue_background');
    $b[] = callout('👨‍💼', '영업이 딜을 끝까지 맡습니다 — 매입예정 등록 → 검차팀 현지확인(ssancar.com inspected 업로드) → 전달 대기에서 검차 사진·영상 그대로 사용해 견적 전송 → 바이어 회신 → 구매확정 → ERP 자동전송.', 'gray_background');
    $b[] = h2('🔄 전체 흐름');
    $b[] = bul('매입예정 등록 → 검차팀 현지확인·ssancar.com inspected 영상/사진 업로드 → 전달 대기로 자동 이동 → 영업이 검차 사진·영상과 견적을 바이어에게 전달 → 바이어 수락 → 구매확정/낙찰 → ERP 자동전송');
    $b[] = callout('🏷', '같은 단계라도 출처에 따라 화면 글자가 조금 다릅니다. 엔카는 "구매대기/구매확정", 경매는 "경매대기/낙찰"로 보입니다. 바이어가 거절하면 종료 또는 재견적, 경매가 안 되면 유찰/취소로 처리합니다.', 'gray_background');
    $b[] = callout('⏰', '경매 차량만 시간이 잠깁니다. 경매는 매일 10:00에 등록이 마감되고 이후에는 관리자만 수정할 수 있습니다. 엔카는 시간 마감 없이 진행합니다.', 'yellow_background');
    $b[] = divider();

    $b[] = h2('1. 매입예정 등록 — 화면: 매입예정  [영업]');
    $b[] = num('[+ 매입예정 추가] 클릭 → 먼저 출처 선택: 엔카 / 경매.');
    $b[] = num('엔카/ssancar 링크를 붙여넣고 [추출] — 차량번호·차값·지역·VIN·매물번호가 잡히면 자동으로 채워짐.');
    $b[] = num('필수는 차량번호. 소유자명은 ERP 조회에 필요하므로 알면 함께 입력. 검사지역·매물번호도 확인.');
    $b[] = num('할인율·배송비·입금계좌·딜러 사진/서류는 여기서 입력하지 않음 — 각각 전달 대기와 구매확정 단계에서 입력.');
    $b[] = num('저장 → 본인 매입예정 목록에 뜸. 검차팀이 같은 차를 현지확인 화면에서 봄.');
    $b[] = h3('📋 상태 탭 · 페이지당 건수');
    $b[] = bul('기본은 「전체」 탭의 최신 30건입니다. 진행중·현지확인대기·검차완료·회신대기·수락·낙찰/확정·ERP전환완료·종료 탭으로 필요한 상태만 좁혀 봅니다. 구매확정 차량은 사라진 것이 아니라 ERP전환완료로 이동합니다.');
    $b[] = bul('페이지당 10·20·30·50·100건 또는 「건수만」을 선택합니다. 건수만은 탭별 숫자만 계산하고 차량 행은 불러오지 않습니다. 탭·건수 변경과 신규 등록 뒤에는 첫 페이지로 돌아갑니다.');
    $b[] = bul('탭 배지는 현재 로그인한 영업에게 보이는 본인 차량 기준입니다. 새 상태가 별도 탭에 아직 묶이지 않았으면 「전체」에서만 보일 수 있습니다.');
    $b[] = bul('잘못 등록했거나 거절·현지확인 지연으로 다시 등록해야 하는 건은 본인 차량 수정창의 [이 건 삭제]로 정리합니다. 아직 ERP에 넘어가지 않았고 수락·구매확정 전인 차량만 가능하며, 경매 시간잠금 차량은 삭제도 잠깁니다. 삭제 이력은 감사로그에 남고 같은 차량번호·VIN으로 다시 등록할 수 있습니다.');
    $b[] = h3('🚗 셀프검차매입 (영업이 이미 직접 확인한 차량)');
    $b[] = bul('영업이 이미 차량을 직접 검차해 현지확인·바이어 전달 절차가 필요 없는 경우에만 유입 카테고리에서 「셀프검차매입」을 선택합니다.');
    $b[] = bul('저장하면 현지확인·전달·회신 단계를 건너뛰고 바로 구매/경매 화면으로 이동해 구매확정을 마무리합니다.');
    $b[] = bul('구매/경매 화면에서 차값·매도비는 원화로, 판매가·견적통화·환율·운임비는 견적통화 기준으로 직접 적습니다. 자동계산 없이 입력값 그대로 ERP로 전송됩니다.');
    $b[] = bul('차값·판매가·견적통화는 필수이고 외화는 환율도 필수입니다. 매도비는 선택 입력이며 실제로 적은 경우에만 입력값 그대로 ERP에 전달됩니다. 비워두면 0으로 자동 기입하지 않고, 차값에서 빼지도 않습니다.');
    $b[] = callout('⚠️', '일반 매입 차량에 잘못 선택하지 마세요. 잘못 골랐다면 관리자가 유입 카테고리를 정상 출처로 고쳐 현지확인 흐름으로 되돌립니다.', 'yellow_background');
    $b[] = h3('🔗 매물 링크 자동채움 (입력 줄이기)');
    $b[] = bul('엔카 링크칸에 엔카 매물 URL 붙여넣기 → 차종·연식·주행거리·가격을 자동으로 가져옴.');
    $b[] = bul('ssancar 링크칸(별도)에 ssancar 매물 URL → 페이지 파싱으로 3통화 가격·VIN·번호판 자동채움.');
    $b[] = callout('💱', '차값 통화 — 엔카는 원화만. ssancar는 매물에 표시된 원/달러/유로 중 선택한 통화를 그대로 저장합니다. 화면 표시 통화를 바꿔도 원래 차값은 바뀌지 않습니다.', 'gray_background');
    $b[] = callout('⚠️', '차량번호·VIN은 한 번 저장하면 잠깁니다. 자동으로 채워진 값도 저장 전에 꼭 눈으로 확인하세요. 오타는 관리자만, 아직 ERP로 넘어가지 않은 차량만 정정할 수 있습니다.', 'yellow_background');
    $b[] = divider();

    $b[] = h2('2. 금액 설정 — 전달 대기에서만 확정  [영업]');
    $b[] = callout('🧮', '금액 계산 방식 — 매입 원가는 링크에서 온 금액 그대로 둡니다. 판매가 = 매입 원가 − 관례 할인율(%) − 차감액. 매도비는 바이어 판매가에 넣지 않고 회사 부담 매입비용으로 ERP에 따로 넘어갑니다.', 'purple_background');
    $b[] = bul('매입예정 단계의 차값은 처음 가져온 매입 원가입니다. 할인·차감액은 판매가에만 반영되고 매입 원가는 바꾸지 않습니다.');
    $b[] = bul('차감액은 바이어가 추가로 깎아달라고 할 때 입력하는 절대금액(KRW)입니다.');
    $b[] = bul('배송비 = 1640 / 1740 / 1840 USD 중 택1. 최종 견적 = 판매가 + 배송비.');
    $b[] = bul('금액·통화 입력은 먼저 미리보기만 바뀌고, [적용]을 눌러야 저장됩니다. 미적용 상태에서는 공유가 막히고, [전달 완료]는 먼저 적용한 뒤 진행됩니다.');
    $b[] = callout('💡', '검차는 금액을 입력하지 않습니다. 검차 결과를 보고 영업이 「전달 대기」에서 관례 할인율·차감액·배송비·견적 통화를 확정합니다.', 'gray_background');
    $b[] = divider();

    $b[] = h2('3. 딜러 사진 · 서류 업로드  [구매확정 단계]');
    $b[] = num('딜러에게 차를 산 뒤 받은 사진·서류는 구매확정 상세창에서 업로드.');
    $b[] = num('구매확정/낙찰되면 ERP 첨부탭에 자동 등록 — 카톡으로 따로 안 보내도 됨.');
    $b[] = num('ERP 전환 뒤 늦게 받은 사진·서류는 「매입예정」 수정창의 별도 「ERP에 사진·서류 추가」 영역에서 올립니다. 전용 전송이라 판매가·바이어·진행상태는 건드리지 않고 새 첨부만 보냅니다.');
    $b[] = bul('운영 전송은 비동기라 결과 카드가 3초마다 붙은 장수와 실패·제한 사유를 확인합니다. ERP 차량 기본사진은 최대 10건이라 보낸 수보다 적게 붙을 수 있습니다.');
    $b[] = callout('🔒', '아직 ERP에 없는 차량에는 추가 전송을 사용할 수 없습니다. ERP로 복사된 파일은 BOARD에서 삭제해도 같이 지워지지 않으므로 삭제·정리는 ERP 차량 첨부에서 처리하세요.', 'gray_background');
    $b[] = callout('🛡', '서류는 바이어에게 전송되지 않습니다. 차량등록증은 주소·주민번호를 가린 파일만 올리세요. 실행파일(.exe 등)은 업로드할 수 없습니다.', 'purple_background');
    $b[] = divider();

    $b[] = h2('4. 입금정보(정산계좌) 입력  [구매확정 단계]');
    $b[] = num('구매확정/낙찰 상세창에서 매입계좌와 매도비 계좌를 입력.');
    $b[] = num('은행명은 자동완성(13개 한국은행), 계좌번호는 은행별로 보기 좋게 하이픈이 붙음.');
    $b[] = num('딜러 첨부에 계좌가 보이는 사진을 올리고 [계좌정보 찾기]를 누르면 차값·매도비 계좌 후보와 근거 사진이 표시됩니다. 사진과 대조한 뒤 [차값에 적용] 또는 [매도비에 적용]을 눌러야 입력칸에 들어갑니다.');
    $b[] = bul('후보는 자동 확정되지 않습니다. 예금주 불일치 경고와 0으로 시작하는 평생계좌를 사람이 확인하며, 못 찾으면 직접 입력합니다. 계좌는 필수가 아니어서 추출 중에도 [계좌 없이 확정하기]로 진행할 수 있습니다.');
    $b[] = callout('⚠️', '계좌와 매도비는 구매확정 전에 확인하세요. 구매확정 뒤 BOARD에서 늦게 고친 값은 이미 생성된 ERP 원장에 다시 반영되지 않습니다.', 'yellow_background');
    $b[] = callout('🔐', '계좌번호는 암호화 저장. 매입계좌와 매도비 계좌는 서로 다른 대상일 수 있으므로 분리해서 확인.', 'gray_background');
    $b[] = divider();

    $b[] = h2('5. 전달 대기 — 바이어에게 사진·영상·견적 전송  [영업]');
    $b[] = num('ssancar.com 의 inspected 에 영상/사진이 올라가면 매입보드가 자동으로 확인해 "전달 대기" 목록으로 이동시킴. 영업은 상세창에서 그 검차 사진·영상을 그대로 확인.');
    $b[] = bul('같은 차량의 inspected 게시물이 사진·영상으로 나뉘어도 차량번호·매물정보가 같으면 한 차량의 검차 자료로 합산되어 보입니다.');
    $b[] = callout('🔄', '영상이 바로 안 보이면 다시 업로드하지 말고 잠시 기다린 뒤 새로고침하세요. 계속 누락되면 차량번호·매물번호와 각 inspected 링크를 관리/개발(Jin)에게 전달합니다.', 'yellow_background');
    $b[] = callout('🔔', '검차 사진·영상이 올라와 전달 대기로 넘어오면 영업 담당자에게 알림톡이 올 수 있습니다. 알림을 받으면 전달 대기에서 견적을 확인하고 바이어에게 보내세요.', 'blue_background');
    $b[] = num('관례 할인율·차감액·배송비·견적 통화를 조정 → 카드 미리보기 확인 → [적용]을 눌러 저장.');
    $b[] = num('통화 선택(₩/$/€)도 먼저 미리보기만 바뀜. 실제 확정은 [적용] 버튼에서 이뤄짐.');
    $b[] = h3('📤 바이어에게 보내기 — 모바일 / PC 방식이 다름');
    $b[] = bul('모바일: [전체 보내기] → 휴대폰 공유시트(카톡·왓츠앱)로 링크 전송. (사진만 파일로 보내는 보조 버튼도 있음)');
    $b[] = bul('PC: [바이어 링크 복사] 버튼 → 카톡 PC / WhatsApp Web 대화창에 붙여넣기(Ctrl+V)로 전송. (PC는 파일 공유가 안 돼 링크 방식으로 보냄)');
    $b[] = callout('🔗', '링크 1개에 ssancar.com inspected 에 올라온 사진·영상과 견적이 모두 담긴 바이어 공개 페이지가 열립니다. 영업이 별도로 검차 영상을 다시 올리는 흐름이 아닙니다. 영상이 몇 개든 링크 1개, 30일 유효. 링크를 카톡 등에 붙여넣으면 견적 미리보기 카드가 함께 뜨도록 만들어져 있습니다.', 'blue_background');
    $b[] = num('바이어명(선택) 입력 후 [전달 완료] → 회신 대기로 이동.');
    $b[] = num('바이어 수락/거절은 회신 화면에서 기록. 수락하면 구매 대기로 이동해 구매확정을 진행할 수 있음. 거절이면 딜 종료 또는 재견적(금액 수정 후 재전달).');
    $b[] = callout('⏱', '바이어 응답은 몇 시간 뒤일 수 있음. "회신대기"로 두고 다른 차 먼저 처리.', 'orange_background');
    $b[] = callout('🛡', '바이어에게 가는 사진·영상은 차량 외관/상태만 — 번호판·서류는 전송 제외(개인정보 레드라인).', 'purple_background');
    $b[] = divider();

    $b[] = h2('6. 구매확정 / 낙찰  [영업]');
    $b[] = num('바이어가 수락한 차량만 구매확정으로 넘어갈 수 있음.');
    $b[] = num('바이어는 필수. 바이어 선택 목록에 「ERP 관리자 승인 필요」가 표시되면 구매확정 버튼이 잠깁니다. ERP 관리자가 사유를 확인해 승인한 뒤 진행합니다.');
    $b[] = callout('🛡️', '매입 등록 가능 여부는 구매확정 직전에 ERP에서 다시 확인합니다. BOARD가 미수·한도 조건을 따로 계산하지 않습니다.', 'yellow_background');
    $b[] = num('현지 최종금액으로 집행 — 엔카: 구매확정/취소, 경매: 낙찰/유찰. + 소유자·입금정보·매도비계좌·딜러 첨부 확인.');
    $b[] = bul('매도비는 실제로 발생한 경우에만 별도 칸에 적습니다. 비워두면 자동 금액이 붙지 않고, 차값에서도 빼지 않습니다.');
    $b[] = num('딜러에게 받은 사진·서류는 여기서 추가. 이미지=사진, 그 외=서류로 자동 분류되어 car-erp 첨부탭으로 전달.');
    $b[] = num('구매확정/낙찰 = SSANCAR 소유(우리 재고).');
    $b[] = callout('🤖', '구매확정되면 매입보드가 ERP에 차량+매입+사진/서류를 자동 전송합니다. 영업담당자는 로그인 이메일로 ERP에서 자동 연결됩니다. 카톡 수동인계 불필요.', 'green_background');
    $b[] = callout('⚠️', 'ERP 전송완료 알림이 안 뜨고 멈춰 있으면 보통 서버 자동처리 문제입니다. 관리/개발(Jin)에게 알리세요.', 'yellow_background');
    $b[] = divider();

    $b[] = h2('7. 영업 포털 — 내 정산·미수·재고·선적 한눈에 — 화면: /portal  [영업]');
    $b[] = callout('📊', '영업 포털은 car-erp(원장)의 내 정산·미수·재고·판매·선적 현황을 board 에서 바로 조회하는 화면입니다. 원장 금액은 읽기전용이며, board 포털에서는 계약금·매입잔금 요청, 판매대금확인, 선적계획·B/L 요청, 서류 다운로드·전자서명 요청을 처리합니다.', 'blue_background');
    $b[] = h3('탭 구성');
    $b[] = bul('요약 — 내 월별 판매/정산/매입 요약 숫자 (판매는 통화가 섞여 건수로, 정산·매입은 원화 합산).');
    $b[] = bul('미수 · 재고 · 판매내역 · 정산 — 각 차량별 목록(car-erp 원장 값 그대로 표시, 재계산 안 함).');
    $b[] = bul('미수금·재고·판매내역·선적요청에서는 차량번호 아래에 ERP 원장의 차대번호(VIN)·브랜드·차종이 함께 표시됩니다. 값이 없는 항목은 빈 줄 없이 빠집니다.');
    $b[] = bul('🚢 선적요청 — 판매완료된 수출 차량을 바이어별로 묶어 선적을 요청.');
    $b[] = h3('💰 정산 수령액 · 진행 중 정산');
    $b[] = bul('요약의 월별 실적에서 월을 누르면 그 달 실제로 받은 정산을 펼쳐 봅니다. ERP 승인 월배치와 배치 밖 지급완료 건이 함께 나오고, 차량별 지급액·지급일·월배치 조정 사유와 최종 수령액을 확인할 수 있습니다.');
    $b[] = bul('「진행 중 정산」은 ERP 정산처리 탭의 본인 차량 중 아직 받지 않은 건입니다. pending·calculating·confirmed 상태를 나눠 보여주며, ERP가 모르는 새 상태를 보내도 원문으로 표시합니다.');
    $b[] = callout('⚠️', '진행 중 정산은 예상 금액입니다. 비용·환율 검토와 확정 과정에서 바뀔 수 있으므로 실제로 받은 돈과 합산하지 마세요. 금액 기준은 BOARD 계산이 아니라 ERP 원장입니다.', 'yellow_background');
    $b[] = h3('📦 재고 4분류 · ERP 요청 신호');
    $b[] = bul('지급대기 = 매입 대금이 남은 입고 전 차량 · 일반재고 = 미판매·출고 전 · 선적전 = 판매·출고 전 · 출고완료 = 출고일이 입력된 이력입니다. 재고 검색은 차량번호·차대번호·선박명과 바이어명을 함께 찾습니다.');
    $b[] = num('매입대금 지급이 필요한 차는 재고의 「지급대기」에서 금액을 직접 적고 [계약금] 또는 [매입잔금]을 선택해 요청합니다. 두 요청은 별개로 관리되며 금액을 자동계산하지 않습니다.');
    $b[] = bul('담당자가 휴가·외근 등으로 바로 확인하기 어려우면 같은 차량에서 금액을 적고 [대표계약금]을 누릅니다. 일반 계약금과 같은 내용이 대표 수신자에게 바로 전달되며 근무시간 규칙을 따르지 않습니다. 일반 계약금과 대표계약금은 별개 요청이라 둘 다 열릴 수 있으므로 상태 뱃지를 확인해 중복 송금을 피하세요.');
    $b[] = bul('금액을 잘못 보냈으면 같은 차량·같은 요청 종류로 다시 보냅니다. 새 요청이 쌓이지 않고 기존 요청 금액이 바뀌며, 실제 송금·회계 반영은 ERP 담당자가 확인해 처리합니다.');
    $b[] = num('바이어가 보낸 판매대금 확인이 필요하면 판매내역에서 같은 바이어의 차량을 선택해 [판매대금확인] — ERP 관리·재무 담당자가 차량별로 확인하면 진행 상태가 표시됩니다.');
    $b[] = h3('🚢 운항중 · 도착예정 확인');
    $b[] = bul('판매내역의 운항 필터에서 「운항중」 또는 「도착예정」을 선택하면 진행상태와 별개로 배 위의 차량을 모아 봅니다. 「거래완료 숨기기」와 동시에 사용할 수 있습니다.');
    $b[] = bul('차량의 운항 칩에는 ERP가 판정한 상태와 선박명·ETA가 표시됩니다. 재고의 출고완료 차량에서도 운항 칩과 선박 정보를 확인하고 선박명으로 검색할 수 있습니다.');
    $b[] = callout('⚠️', '「도착예정」은 ETA가 이미 지났다는 뜻이지 실제 입항이 확인됐다는 뜻이 아닙니다. 바이어에게 「도착했다」고 확정해서 안내하지 마세요.', 'yellow_background');
    $b[] = h3('🚢 선적요청 (선적·B/L 묶음)');
    $b[] = num('[선적 계획] 탭에서 판매가가 입력된 수출 차량을 바이어별로 묶음(한 묶음 = 한 바이어) → 하단 [동기화]로 car-erp 에 전송. 대금이 덜 들어온 차량도 서류 사전 준비를 위해 후보에 나오며, car-erp 관리(수출통관)에게 즉시 알람이 갑니다.');
    $b[] = bul('묶였다고 완납된 것은 아닙니다. 미수 금액·완납 뱃지를 확인하고 「환율 미입력」은 완납으로 보지 마세요. 출고일이 찍혀 있어도 반입지·B/L이 없으면 후보에 나올 수 있습니다.');
    $b[] = bul('포워딩사는 ERP 명부에서 선택만 합니다. BOARD에서 새 업체를 만들 수 없고, ERP 관리자가 이미 고친 차량 포워딩사는 재전송해도 덮어쓰지 않습니다. 변경 이력에는 요청한 영업담당자가 표시됩니다.');
    $b[] = bul('컨테이너 묶음에서만 총 운임비(USD)를 입력합니다. ERP가 차량 수로 나눠 기록하되 나머지는 한 차량에 붙고 기존 차량값은 보호하므로, 차량별 합계가 입력 총액과 다를 수 있습니다. RORO에서는 운임비가 전송되지 않습니다.');
    $b[] = bul('바이어가 없는 후보는 묶을 수 없지만 조용히 숨기지 않고 화면에 제외 대수를 표시합니다.');
    $b[] = num('[내 선적묶음] 탭에서 진행 상태를 모니터 — 요청됨 / 진행중 / 선적완료 + B/L 상태.');
    $b[] = bul('선적묶음은 상태별로 접고 펼쳐 볼 수 있습니다. 요청됨·진행중·완료 묶음이 섞여 있으면 먼저 상태 그룹을 펼쳐 필요한 묶음만 확인하세요.');
    $b[] = num('B/L 요청(오리지널 / 써랜더) → 관리에게 알람. 관리가 발급하기 전이면 [B/L요청 무름]으로 취소할 수 있음(발급 후엔 관리에게 요청).');
    $b[] = num('아직 처리 전(요청됨)인 선적은 취소 가능 — 묶음에서 빼고 다시 동기화하면 car-erp 가 자동 취소.');
    $b[] = h3('📄 서류 다운로드');
    $b[] = bul('묶음 차량의 계약서 · 인보이스/패킹 · 판매계약서를 xlsx 로 바로 다운로드.');
    $b[] = bul('판매계약서는 board 에서 전자서명 요청을 보낼 수 있습니다. 요청 뒤에는 상태 칩으로 요청됨·대기중·완료를 확인합니다.');
    $b[] = callout('✍️', '전자서명 자체는 ERP가 세션을 만들고 서명본을 보관합니다. board 포털은 영업이 요청하고 상태를 보는 창구입니다.', 'gray_background');
    $b[] = callout('⚠️', '판매계약서는 동일 바이어·단일 통화 차량만 함께 발급됩니다(혼합 묶음이면 실패 안내). 서류엔 타인 정보가 있어, 남의 포털을 조회 중일 땐 다운로드가 차단됩니다.', 'yellow_background');
    $b[] = callout('👤', '남의 정산·선적을 보는 건 시스템관리자만(이름 클릭). 일반 영업은 본인 것만 보입니다.', 'gray_background');
    $b[] = divider();

    $b[] = h2('👀 본인 차량만 보임');
    $b[] = para('영업 화면(매입예정·구매)에는 본인이 등록한 차량만 보입니다. 전체를 보려면 관리자 화면을 사용해야 합니다.');
    $b[] = h2('⚠️ 자주 하는 실수');
    $b[] = bul('현지 확인 전에 바이어에게 최종금액 확정해 줌 → 차 상태로 금액 달라져 신뢰 문제');
    $b[] = bul('VIN·차량번호 오타 저장 → 잠겨서 수정 불가(관리자만, ERP로 넘어가기 전 차량 한정)');
    $b[] = bul('경매 차량을 10:00 넘겨 등록 시도 → 막힘(엔카는 상시라 무관)');
    $b[] = bul('바이어에게 서류·번호판 사진 전송 → 금지');
    $b[] = bul('전달 대기에서 금액·통화를 바꾼 뒤 [적용]을 누르지 않고 공유하려 함 → 공유 전 적용 필요');
    $b[] = bul('매입예정 단계에서 계좌·딜러서류를 찾으려 함 → 이제 구매확정에서 입력');
    $b[] = footer('영업');

    return $b;
}

// ════════════════════════════════════════════════════════════
//  검차 (board /inspection)
// ════════════════════════════════════════════════════════════
function blocks_inspection(): array
{
    $b = [];
    $b[] = callout('🔍', '이 페이지는 「매입보드」 앱의 검차(현지확인) 실무 가이드입니다. 매입보드에서는 "오늘 내가 검차할 차량"을 확인하고, 검차 영상·사진은 ssancar.com 의 inspected 메뉴에 업로드합니다.', 'blue_background');
    $b[] = callout('📌', '검차는 매입보드에 사진·영상을 직접 올리거나 [검차완료]를 저장해서 넘기는 흐름이 아닙니다. ssancar.com inspected 에 영상/사진이 올라가면 매입보드가 자동으로 확인해 영업의 「전달 대기」로 이동시킵니다.', 'green_background');
    $b[] = h2('🔄 전체 흐름');
    $b[] = bul('오늘 내 배정 지역의 검차대기 차량 확인 → 현장 실차 검수 → ssancar.com inspected 에 영상/사진 업로드 → 매입보드가 자동 확인 → 전달 대기 → 영업이 그 사진·영상을 그대로 바이어에게 전달');
    $b[] = divider();

    $b[] = h2('1. 오늘 배정 확인 — 화면: 현지확인  [검차]');
    $b[] = num('화면은 검사지역별로 그룹핑됨. 내 오늘 배정 지역의 검차대기 차량 목록을 확인.');
    $b[] = num('같은 지역에 배정된 동행자도 함께 표시됨.');
    $b[] = callout('🔔', '내 담당 지역에 오늘 검차할 차량이 있으면 검차 알림톡을 받을 수 있습니다. 알림을 받으면 매입보드 현지확인 화면에서 대상 차량을 확인하세요.', 'blue_background');
    $b[] = callout('👀', '검차 화면에는 내 배정 지역의 차량만 보입니다. 전체를 보려면 관리 화면을 사용합니다.', 'gray_background');
    $b[] = callout('🗺', '지역·날짜 배정은 관리가 미리 분배(지역×날짜 최대 3명). 내 배정이 안 보이면 관리에 문의.', 'gray_background');
    $b[] = divider();

    $b[] = h2('2. 현장 실차 검수 & inspected 업로드  [검차]');
    $b[] = num('매입보드에서 오늘 배정 지역의 대상 차량을 확인하고, 현장에서 외관·주행·사고흔적 등 실차 상태를 검수.');
    $b[] = num('검차 영상·사진은 ssancar.com 의 inspected 메뉴에 업로드. 매입보드 상세창에 따로 다시 올리는 흐름이 아님.');
    $b[] = num('inspected 업로드가 완료되면 매입보드가 차량번호/매물 정보를 기준으로 자동 확인하고, 해당 차량을 전달 대기로 이동.');
    $b[] = bul('같은 차량 촬영분이 여러 inspected 게시물로 나뉘어도 차량번호·매물정보가 같으면 BOARD가 사진·영상을 합산합니다.');
    $b[] = callout('⚠️', '게시물마다 차량번호나 매물정보가 다르면 같은 차량으로 합산되지 않습니다. 업로드 전에 식별값이 같은지 확인하세요.', 'yellow_background');
    $b[] = num('영업은 전달 대기 화면에서 ssancar.com inspected 에 올라온 영상·사진을 그대로 사용해 바이어 공개링크를 전송.');
    $b[] = callout('🛡', '촬영은 차량 외관/상태만. 번호판·서류(등록증·성능지·말소신청서)는 제외 — inspected 사진·영상은 영업이 바이어에게 그대로 공유합니다.', 'purple_background');
    $b[] = callout('💰', '최종금액(관례 할인율·차감액·배송비·견적통화)은 검차가 입력하지 않습니다 — 금액은 영업이 전달 대기에서 확정합니다.', 'gray_background');
    $b[] = divider();

    $b[] = h2('⚠️ 자주 하는 실수');
    $b[] = bul('매입보드에 검차 영상·사진을 직접 올리려 함 → ssancar.com inspected 에 업로드해야 함');
    $b[] = bul('inspected 업로드가 안 됐거나 차량번호가 안 맞음 → 전달 대기로 자동 이동하지 않음');
    $b[] = bul('업로드 직후 즉시 확인 → 자동 확인이 잠시 늦을 수 있으니 새로고침 후 재확인');
    $b[] = bul('번호판·서류를 촬영 → 바이어에게 노출됨(외관/상태만)');
    $b[] = footer('검차');

    return $b;
}

// ════════════════════════════════════════════════════════════
//  관리 (board /manage + /users + /audit)
// ════════════════════════════════════════════════════════════
function blocks_manager(?string $salesPageId = null): array
{
    $b = [];
    $b[] = callout('🗂️', '이 페이지는 「매입보드」 앱의 관리 화면 가이드입니다. ERP의 "관리 (통합)" 과는 별개 — 여기서는 매입보드 안의 전체현황 확인과 예외 수정 기준만 다룹니다.', 'blue_background');
    $b[] = callout('🔑', '관리 권한은 영업/검차 화면을 모두 보고, 필요한 경우 차량 식별값과 진행상태를 수정할 수 있습니다. 단, 예외 수정은 꼭 필요한 경우에만 합니다.', 'gray_background');
    $b[] = callout('👨‍💼', '관리자도 영업 업무를 할 때는 「영업」 페이지를 그대로 따릅니다. 이 관리 페이지는 전체현황 확인과 예외 수정처럼 관리 화면에서만 하는 일만 정리합니다.', 'blue_background');
    if ($salesPageId) {
        $b[] = para('👉 영업 업무 가이드 (관리자도 동일하게 수행):');
        $b[] = linkpage($salesPageId);
    }
    $b[] = h2('🔄 관리가 보는 것');
    $b[] = bul('전체현황 → 무제한 수정 상세창 → 필요한 예외 수정');
    $b[] = divider();

    $b[] = h2('1. 전체현황 — 화면: 전체현황  [관리]');
    $b[] = num('상단 요약 숫자 5개 — 클릭하면 해당 조건으로 목록이 좁혀짐.');
    $b[] = num('검색·상태·출처·회신 필터 + 20건씩 보기. 영업·검차 구분 없이 전 차량을 봄.');
    $b[] = num('행 클릭 → 무제한 수정 상세창 — 대부분의 항목 수정 가능.');
    $b[] = callout('📝', '관리의 모든 변경은 변경기록에 자동 기록됩니다. 누가·언제·무엇을 이전값에서 새값으로 바꿨는지 남습니다.', 'gray_background');
    $b[] = divider();

    $b[] = h2('2. 관리자 고유 우회 권한  [관리]');
    $b[] = bul('경매 10시 마감 우회 — 경매 10:00 마감 이후에도 등록/수정(영업은 잠긴 경매 읽기전용).');
    $b[] = bul('상태 강제 수정 — 진행 순서 보호를 우회해 예외적으로 다음 단계/이전 단계로 이동.');
    $b[] = bul('식별값(VIN·차량번호) 정정 — ⚠️ 아직 ERP로 넘어가지 않은 차량만. ERP 전송 후엔 잠금 유지.');
    $b[] = bul('삭제(휴지통)한 차량과 같은 차량번호·VIN 으로 새로 등록 가능 — 중복 체크는 활성(미삭제) 차량 기준. (예전엔 재등록 시 오류였으나 수정됨)');
    $b[] = callout('⚠️', '식별값 정정은 오타 교정용입니다. 이미 ERP로 넘어간 차는 연결 기준값이라 잠겨 있습니다. 함부로 풀지 마세요.', 'red_background');
    $b[] = divider();

    $b[] = h2('3. 알림톡 확인  [관리]');
    $b[] = bul('검차 담당자는 담당 지역 차량 안내 알림톡을 받을 수 있습니다.');
    $b[] = bul('영업 담당자는 검차 사진·영상이 올라와 전달 대기로 넘어온 차량 안내 알림톡을 받을 수 있습니다.');
    $b[] = callout('🔔', '알림톡은 업무를 놓치지 않게 도와주는 보조 알림입니다. 알림이 없더라도 매입보드 화면의 상태가 기준입니다.', 'blue_background');
    $b[] = divider();

    $b[] = h2('⚠️ 관리 운영 핵심 주의');
    $b[] = bul('식별값(VIN) 잠금 해제는 ERP로 넘어가기 전 오타교정만 — ERP 전송완료 차량은 건드리지 않기');
    $b[] = bul('상태 강제 수정은 최소화 — 진행 순서 보호는 바이어 수락·시간잠금 같은 안전장치');
    $b[] = bul('담당자 변경·퇴사자 처리처럼 권한이 바뀌는 일은 board·car-erp 양쪽 담당자에게 함께 확인');
    $b[] = bul('ERP 자동전송이 멈추면 관리/개발(Jin)에게 차량번호와 화면 상태를 알려주기');
    $b[] = footer('관리');

    return $b;
}

// ════════════════════════════════════════════════════════════
//  🔄 전체 워크플로우 — 전 과정 한눈에
// ════════════════════════════════════════════════════════════
function blocks_workflow(): array
{
    $b = [];
    $b[] = callout('🔄', '「매입보드」 전체 업무 흐름을 한눈에 보는 페이지입니다. 세부 실무는 영업/검차/관리 각 페이지를, 막히거나 잠겼을 때는 「⚠️ 에러·락 대처」 페이지를 보세요.', 'blue_background');
    $b[] = callout('🧭', '매입보드는 매입 "확정 전"만 다룹니다 — 매입예정 → 현지검차 → 바이어 회신 → 구매확정. 구매확정된 차만 ERP로 자동 넘어가 재고가 됩니다.', 'gray_background');

    $b[] = h2('📊 상태 흐름 (한 줄 요약)');
    $b[] = callout('➡️', '현지확인 대기 → 전달 대기 → 회신 대기 → 구매 대기 → 구매확정/낙찰 → ERP 전송완료', 'green_background');
    $b[] = bul('갈래: 바이어 거절 → 딜 종료 또는 재견적 · 경매 유찰/구매취소 → 실패 처리');
    $b[] = callout('🏷', '같은 단계라도 화면 글자는 출처별로 다를 수 있습니다. 엔카는 "구매대기/구매확정", 경매는 "경매대기/낙찰"로 보입니다.', 'gray_background');
    $b[] = divider();

    $b[] = h2('👥 단계별 담당 · 화면');
    $b[] = num('매입예정 등록 — 영업. 링크 붙여넣기·추출, 차량번호·소유자·검사지역·매물번호 확인. 할인·배송·계좌·딜러첨부는 여기서 입력하지 않음.');
    $b[] = bul('셀프검차매입 — 영업이 이미 직접 확인한 차량만 선택. 구매/경매에서 차값·매도비(KRW), 판매가·통화·환율·운임비를 직접 적고 바이어를 선택한 뒤 구매확정. 매도비는 적은 경우에만 별도 전달하고 차값에서는 빼지 않음. 현지확인·전달·회신은 건너뜀.');
    $b[] = num('현지 검차 — 검차. 오늘 배정지역 차량을 확인하고 현장에서 실차 검수.');
    $b[] = num('검차 업로드·자동 이동 — 검차가 ssancar.com inspected 에 영상/사진 업로드 → 매입보드가 자동 확인 → 영업의 전달 대기 목록으로 이동.');
    $b[] = bul('같은 차량의 inspected 게시물이 사진·영상으로 나뉘어도 차량 식별값이 같으면 미디어를 합산한 뒤 전달 대기로 자동 이동.');
    $b[] = num('알림톡 확인 — 검차 담당자는 담당 지역 차량 안내를, 영업 담당자는 전달 대기 이동 안내를 받을 수 있음.');
    $b[] = num('바이어 전달 — 영업. 관례 할인율·차감액·배송비·견적통화를 [적용]으로 확정하고, ssancar.com inspected 사진·영상과 견적을 바이어 공개링크로 전송.');
    $b[] = num('바이어 회신 — 영업. 수락하면 구매 대기, 거절하면 딜 종료 또는 재견적.');
    $b[] = num('구매확정 / 낙찰 — 영업. 수락된 차만 진입. 바이어 필수이며 ERP 관리자 승인 필요 표시가 있으면 확정 불가. 사진의 계좌 후보를 확인하고 소유자·매입계좌·매도비·딜러 첨부를 확정 전에 점검.');
    $b[] = num('ERP 전송완료 — 시스템. 구매확정되면 차량 + 매입/판매 금액 + 계좌 + 딜러 사진/서류를 ERP로 자동 전송. 늦게 받은 첨부는 매입예정 수정창에서 첨부만 추가 전송.');
    $b[] = num('영업 포털 — 영업. ERP와 같은 재고 4분류·운항 상태와 차량번호 아래 차대번호·브랜드·차종을 확인하고 계약금·대표계약금·매입잔금 요청, 판매대금확인, 선적계획·B/L 요청, 판매계약서 전자서명 요청·상태 확인을 처리.');
    $b[] = divider();

    $b[] = h2('🔀 출처 · 유입 갈래');
    $b[] = bul('엔카(즉시구매) — 상시 등록(시간잠금 없음). 차값은 원화. 링크칸에 엔카 URL 붙이면 차종·연식·주행·가격 자동수집.');
    $b[] = bul('경매 — 매일 10:00 등록 마감(주말 해제, 관리자 우회 가능). 경매장·출품번호 기록.');
    $b[] = bul('ssancar 유입 — ssancar.com 클릭 → 채팅으로 매물번호(c_no) 자동 캡처. 차값은 원/달러/유로 중 택1한 통화 그대로 저장.');
    $b[] = callout('💡', '엔카·기타 링크 유입은 매물번호가 없어 영업이 수동 등록 — 정상입니다. 어느 출처든 구매확정되면 동일하게 ERP로 넘어갑니다.', 'gray_background');
    $b[] = divider();

    $b[] = h2('🧮 금액 한눈에');
    $b[] = callout('🧮', '금액 계산 방식 — 매입 원가는 그대로 두고, 판매가 = 매입 원가 − 관례 할인율(%) − 차감액. 최종 견적 = 판매가 + 배송비(1640 / 1740 / 1840 USD 중 택1). 매도비는 바이어 판매가에 넣지 않고 ERP 매입비용으로 별도 전달.', 'purple_background');
    $b[] = bul('금액 확정 지점은 전달 대기 하나로 통일. 검차는 금액 입력 없음.');
    $b[] = bul('견적 통화 선택은 미리보기만 바꾸고, [적용]을 눌러야 저장·전달에 반영됨.');
    $b[] = divider();

    $b[] = h2('🔗 이어지는 문서');
    $b[] = bul('세부 실무 = 영업 / 검차 / 관리 페이지');
    $b[] = bul('막힘·잠김 = ⚠️ 에러·락 대처 페이지');
    $b[] = footer('전체 워크플로우');

    return $b;
}

// ════════════════════════════════════════════════════════════
//  ⚠️ 에러·락 대처 — 증상별 찾아보기 (직원용)
// ════════════════════════════════════════════════════════════
function blocks_troubleshoot(): array
{
    $b = [];
    $b[] = callout('⚠️', '업무 중 "안 돼요 / 잠겼어요 / 안 보여요"가 생겼을 때 찾아보는 페이지입니다. 대부분은 실수가 아니라 board 의 안전장치(락)입니다 — 아래 증상 목록에서 찾아 원인·대처를 확인하세요. (Ctrl+F 로 증상 검색)', 'yellow_background');
    $b[] = callout('🟢', '🔒 = 정상 안전장치(락). 이해하면 스스로 해소 가능.    🚨 = 시스템 장애. 직원이 못 고침 → 관리/개발(Jin)에게 알려야 함.', 'gray_background');

    $b[] = h2('🔎 증상별 빠른 찾기');
    $b[] = bul('🔒 경매 차량이 10시 넘어 등록/수정이 안 돼요');
    $b[] = bul('🔒 차량번호·VIN 을 못 고쳐요 (회색으로 잠김)');
    $b[] = bul('🔒 구매 화면에 그 차가 안 떠요');
    $b[] = bul('🔒 상태를 건너뛰거나 되돌릴 수 없어요');
    $b[] = bul('🔒 다른 사람이 등록한 차가 안 보여요');
    $b[] = bul('🔒 ssancar.com inspected 에 올렸는데 전달 대기로 안 넘어가요');
    $b[] = bul('🔒 견적 금액을 바꿨는데 공유가 안 돼요');
    $b[] = bul('🔒 매입예정에서 계좌·딜러첨부·할인/배송 입력칸이 안 보여요');
    $b[] = bul('🔒 판매계약서·서류 다운로드가 안 돼요');
    $b[] = bul('🔒 삭제한 차를 같은 번호로 다시 등록해도 되나요');
    $b[] = bul('🚨 구매확정했는데 ERP로 안 넘어가요');
    $b[] = bul('🚨 화면이 전부 오류(500)거나 로그인이 안 돼요');
    $b[] = divider();

    $b[] = h2('🔒 경매 차량이 10시 넘어 등록/수정이 안 돼요');
    $b[] = callout('📖', '원인 — 경매 출처는 매일 오전 10:00 에 등록이 마감됩니다. 이후엔 그날 경매 행이 잠깁니다.', 'gray_background');
    $b[] = bul('✅ 엔카는 상시 등록이라 시간과 무관 — 급하면 출처를 먼저 확인.');
    $b[] = bul('✅ 주말은 잠금이 해제됩니다.');
    $b[] = bul('✅ 마감 후 꼭 등록·수정해야 하면 관리자에게 요청(관리자만 우회 가능).');
    $b[] = divider();

    $b[] = h2('🔒 차량번호·VIN 을 못 고쳐요 (회색으로 잠김)');
    $b[] = callout('📖', '원인 — 등록 후 차량번호·차대번호(VIN)는 잠깁니다. 중복 방지와 ERP 연결 기준값이라 바꾸면 안 됩니다.', 'gray_background');
    $b[] = bul('✅ 오타면 관리자에게 정정 요청 — 아직 ERP로 넘어가지 않은 차량만 정정 가능.');
    $b[] = bul('✅ 이미 ERP로 넘어간 차는 잠금 유지 — 풀지 않습니다.');
    $b[] = bul('✅ 예방 = 저장 전에 자동채움 값도 눈으로 한 번 확인.');
    $b[] = divider();

    $b[] = h2('🔒 구매 화면에 그 차가 안 떠요');
    $b[] = callout('📖', '원인 — 구매 화면에는 바이어가 "수락"한 차만 들어갑니다. 아직 회신 대기 상태면 구매 화면에 안 뜨는 게 정상입니다.', 'gray_background');
    $b[] = bul('✅ 회신 화면에서 바이어 수락을 먼저 기록 → 구매 대기로 넘어와 구매 화면에 뜸.');
    $b[] = divider();

    $b[] = h2('🔒 상태를 건너뛰거나 되돌릴 수 없어요');
    $b[] = callout('📖', '원인 — 진행상태는 정해진 순서로만 이동합니다. 단계 건너뛰기·되돌리기는 차단됩니다.', 'gray_background');
    $b[] = bul('✅ 순서대로 진행. 예외적으로 되돌려야 하면 관리자에게 강제 수정을 요청.');
    $b[] = divider();

    $b[] = h2('🔒 다른 사람이 등록한 차가 안 보여요');
    $b[] = callout('📖', '원인 — 영업 화면은 본인이 등록한 차량만 보입니다. 다른 영업 담당자의 차량은 보이지 않습니다.', 'gray_background');
    $b[] = bul('✅ 전체를 봐야 하면 관리 화면 사용.');
    $b[] = bul('✅ 시스템관리자는 이름 클릭으로 남의 정산·선적도 조회 가능.');
    $b[] = divider();

    $b[] = h2('🔒 ssancar.com inspected 에 올렸는데 전달 대기로 안 넘어가요');
    $b[] = callout('📖', '원인 — 전달 대기로 넘어가는 기준은 매입보드 버튼이 아니라 ssancar.com inspected 업로드입니다. 같은 차량의 분할 게시물은 자동 합산되지만 업로드 위치가 다르거나 차량번호/매물번호가 안 맞거나 자동 확인이 지연되면 바로 안 넘어갈 수 있습니다.', 'gray_background');
    $b[] = bul('✅ 영상·사진이 ssancar.com 의 inspected 에 올라갔는지 확인.');
    $b[] = bul('✅ 차량번호·매물번호가 board 의 매입예정 차량과 맞는지 확인.');
    $b[] = bul('✅ 업로드 직후면 잠시 기다린 뒤 board 를 새로고침.');
    $b[] = bul('✅ 계속 안 잡히면 관리/개발(Jin)에게 차량번호·매물번호와 각 ssancar.com inspected 링크를 함께 전달.');
    $b[] = divider();

    $b[] = h2('🔒 견적 금액을 바꿨는데 공유가 안 돼요');
    $b[] = callout('📖', '원인 — 전달 대기에서 할인율·차감액·배송비·견적통화를 바꾸면 먼저 미리보기만 바뀝니다. 바이어에게 보낼 값으로 확정하려면 [적용]을 눌러야 합니다.', 'gray_background');
    $b[] = bul('✅ 견적 카드 아래의 [적용] 버튼을 누른 뒤 공유.');
    $b[] = bul('✅ [전달 완료]는 미적용 금액이 있으면 먼저 적용한 뒤 진행합니다.');
    $b[] = divider();

    $b[] = h2('🔒 매입예정에서 계좌·딜러첨부·할인/배송 입력칸이 안 보여요');
    $b[] = callout('📖', '원인 — 2026-07-06 흐름 재배치로 매입예정은 링크·식별정보 중심으로 단순화됐습니다.', 'gray_background');
    $b[] = bul('✅ 할인율·차감액·배송비·견적통화는 전달 대기에서 입력.');
    $b[] = bul('✅ 매입계좌·매도비계좌·딜러 사진/서류는 구매확정 상세창에서 입력.');
    $b[] = divider();

    $b[] = h2('🔒 판매계약서·서류 다운로드가 안 돼요');
    $b[] = callout('📖', '원인 — 판매계약서는 동일 바이어·단일 통화 묶음만 함께 발급됩니다(혼합이면 실패). 또 남의 포털을 조회 중이면 서류 다운로드가 차단됩니다(타인 정보 보호).', 'gray_background');
    $b[] = bul('✅ 묶음에 통화·바이어가 섞였는지 확인.');
    $b[] = bul('✅ 본인 포털에서 다운로드.');
    $b[] = divider();

    $b[] = h2('🔒 삭제한 차를 같은 번호로 다시 등록해도 되나요');
    $b[] = callout('📖', '원인 — 차량번호·VIN 중복 체크는 "활성(미삭제) 차량" 기준입니다.', 'gray_background');
    $b[] = bul('✅ 휴지통으로 삭제한 차와 같은 번호로 새로 등록해도 됩니다(예전엔 오류였으나 수정됨).');
    $b[] = divider();

    $b[] = h2('🚨 구매확정했는데 ERP로 안 넘어가요');
    $b[] = callout('📖', '원인 — 구매확정되면 자동으로 ERP에 전송됩니다. ERP 전송완료 알림이 안 뜨고 멈춰 있으면 보통 서버 자동처리 문제입니다. 직원이 직접 고칠 수 없습니다.', 'gray_background');
    $b[] = bul('🚨 관리/개발(Jin)에게 알리세요.');
    $b[] = bul('ℹ️ 시스템관리자는 변경기록의 ERP 전송기록에서 실패 원인을 확인할 수 있습니다. 복구는 개발(Jin)이 서버에서 처리합니다.');
    $b[] = divider();

    $b[] = h2('🚨 화면이 전부 오류(500)거나 로그인이 안 돼요');
    $b[] = callout('📖', '원인 — 서버 설정·배포 문제일 수 있습니다. 개별 실무로는 해결 불가.', 'gray_background');
    $b[] = bul('🚨 즉시 관리/개발(Jin)에게 알리세요.');
    $b[] = footer('에러·락 대처');

    return $b;
}

// ════════════════════════════════════════════════════════════
//  🛒 매입보드 (BOARD) — 부모(섹션) 페이지 인트로
// ════════════════════════════════════════════════════════════
function blocks_board_parent(): array
{
    $b = [];
    $b[] = callout('🛒', '「매입보드」 앱 업무 가이드 섹션입니다. 매입보드는 매입 확정 전(매입예정 → 현지검차 → 바이어 회신 → 구매확정)을 다루는 앱이고, 구매확정된 차만 ERP로 자동 넘어갑니다.', 'blue_background');
    $b[] = h2('사이드바 항목들의 역할');
    $b[] = bul('매입예정 — 영업이 엔카·경매·ssancar 유입 후보 차량을 등록하고, 차량번호·소유자·검사지역·매물번호를 확인합니다.');
    $b[] = bul('전달 대기 — ssancar.com inspected 영상·사진이 올라온 차량을 확인하고, 영업이 견적 금액·통화·배송비를 확정해 바이어에게 공유합니다.');
    $b[] = bul('회신 대기 — 바이어가 수락/거절했는지 기록하고, 수락 차량만 구매 대기 단계로 넘깁니다.');
    $b[] = bul('구매/경매 — 수락된 차량을 엔카 구매확정 또는 경매 낙찰로 처리하고, 매입계좌·매도비계좌·딜러 첨부를 확인합니다.');
    $b[] = bul('검차 — 검차팀이 오늘 배정된 지역의 현지확인 차량을 보고, ssancar.com inspected 업로드가 필요한 대상을 확인합니다.');
    $b[] = bul('관리 — 전체 차량 진행상태, 필터, 예외 수정, ERP 전송 상태, 변경기록을 확인합니다.');
    $b[] = bul('영업 포털 — ERP 원장의 내 미수·매입·판매·정산·선적묶음을 읽기전용으로 확인하고, 선적요청·서류 다운로드·전자서명 요청을 처리합니다.');
    $b[] = bul('변경기록/사용자관리 — 시스템관리자가 사용자 권한, 영업담당자 매핑, 변경 이력, ERP 전송 로그를 확인합니다.');
    $b[] = callout('🧭', '매입 전 후보 등록은 매입예정, 검차 결과 확인 후 바이어에게 보낼 일은 전달 대기, 구매가 확정된 일은 구매/경매, ERP 원장 정보를 보는 일은 영업 포털에서 시작하세요.', 'gray_background');
    $b[] = divider();
    $b[] = para('아래 하위 페이지에서 본인 업무를 선택하세요:');
    $b[] = bul('🔄 전체 워크플로우 — 전 과정 한눈에(진행상태 · 단계별 담당 · 출처 갈래). 처음이면 여기부터.');
    $b[] = bul('👨‍💼 영업 — 링크 기반 매입예정 등록 · 견적 확정/전달 · 회신 · 구매확정');
    $b[] = bul('🔍 검차 — 오늘 배정지역 현지확인 · ssancar.com inspected 영상/사진 업로드 · 자동 전달대기');
    $b[] = bul('🗂️ 관리 — 전체현황 확인 · 필요한 예외 수정 · 알림톡 상태 확인');
    $b[] = bul('⚠️ 에러·락 대처 — "안 돼요 / 잠겼어요 / 안 보여요" 증상별 원인·대처(찾아보기용).');
    $b[] = footer('매입보드(BOARD) 섹션');
    $b[] = para('ASSISTANT_AUDIENCE=staff');

    return $b;
}

// ── 페이지 트리 탐색 / 블록 관리 ─────────────────────────────
function childPages(string $pid, string $t, string $v): array
{
    $out = [];
    $cursor = null;
    do {
        $url = "https://api.notion.com/v1/blocks/$pid/children?page_size=100".($cursor ? "&start_cursor=$cursor" : '');
        $r = notion('GET', $url, [], $t, $v);
        foreach ($r['results'] as $blk) {
            if (($blk['type'] ?? '') === 'child_page') {
                $out[$blk['child_page']['title']] = $blk['id'];
            }
        }
        $cursor = $r['has_more'] ? $r['next_cursor'] : null;
    } while ($cursor);

    return $out;
}

function blockSignature(array $block): string
{
    $type = $block['type'] ?? '';
    $text = '';
    $payload = isset($block[$type]) ? (array) $block[$type] : [];
    foreach (($payload['rich_text'] ?? []) as $seg) {
        $seg = (array) $seg;
        $textNode = isset($seg['text']) ? (array) $seg['text'] : [];
        $text .= $seg['plain_text'] ?? ($textNode['content'] ?? '');
    }

    return $type.'|'.$text;
}

/** @return array{count:int, footer:bool, after_footer:int, rows:array<int,string>} */
function pageAudit(string $pid, string $t, string $v): array
{
    $count = 0;
    $footerAt = null;
    $rows = [];
    $cursor = null;
    do {
        $url = "https://api.notion.com/v1/blocks/$pid/children?page_size=100".($cursor ? "&start_cursor=$cursor" : '');
        $r = notion('GET', $url, [], $t, $v);
        foreach (($r['results'] ?? []) as $blk) {
            if (($blk['type'] ?? '') === 'child_page') {
                continue;
            }
            $signature = blockSignature($blk);
            $text = substr($signature, strpos($signature, '|') + 1);
            if (str_starts_with($text, 'SSANCAR 매입보드 업무 가이드 ·')) {
                $footerAt = $count;
            }
            $rows[] = $signature;
            $count++;
        }
        $cursor = ($r['has_more'] ?? false) ? ($r['next_cursor'] ?? null) : null;
    } while ($cursor);

    return [
        'count' => $count,
        'footer' => $footerAt !== null,
        'after_footer' => $footerAt === null ? 0 : max(0, $count - $footerAt - 1),
        'rows' => $rows,
    ];
}
function clearBlocks(string $pid, string $t, string $v, bool $apply): int
{
    $ids = [];
    $cursor = null;
    do {
        $url = "https://api.notion.com/v1/blocks/$pid/children?page_size=100".($cursor ? "&start_cursor=$cursor" : '');
        $r = notion('GET', $url, [], $t, $v);
        foreach ($r['results'] as $blk) {
            if (($blk['type'] ?? '') === 'child_page') {
                continue;
            } // 하위 페이지는 보존
            $ids[] = $blk['id'];
        }
        $cursor = $r['has_more'] ? $r['next_cursor'] : null;
    } while ($cursor);
    if ($apply) {
        foreach ($ids as $id) {
            notionDeleteBlock($id, $t, $v);
        }
    }

    return count($ids);
}
function appendBlocks(string $pid, array $blocks, string $t, string $v): void
{
    foreach (array_chunk($blocks, 90) as $chunk) {
        notion('PATCH', "https://api.notion.com/v1/blocks/$pid/children", ['children' => $chunk], $t, $v);
    }
}

// ── 1. 허브 찾기 ────────────────────────────────────────────
echo "▶ '$HUB_TITLE' 허브 검색...\n";
$ps = notion('POST', "$BASE/search", ['query' => $HUB_TITLE, 'filter' => ['property' => 'object', 'value' => 'page']], $token, $V);
$hubId = null;
foreach ($ps['results'] as $p) {
    foreach (($p['properties'] ?? []) as $prop) {
        if (($prop['type'] ?? '') === 'title' && ($prop['title'][0]['plain_text'] ?? '') === $HUB_TITLE) {
            $hubId = $p['id'];
            break 2;
        }
    }
}
if (! $hubId) {
    fwrite(STDERR, "❌ 허브 '$HUB_TITLE' 없음. 노션에서 integration 과 공유됐는지 확인.\n");
    exit(1);
}
echo "   • 허브 id=$hubId\n";

$kids = childPages($hubId, $token, $V);
echo '▶ 허브 하위 페이지: '.implode(', ', array_keys($kids))."\n";

$PARENT_TITLE = '🛒 매입보드 (BOARD)';
$PARENT_ICON = '🛒';
$OLD_TITLE = '영업'; // 기존 car-erp 영업 페이지를 board 부모로 승격·리네임

// ── 2. 부모(섹션) 페이지 결정: 신규명 우선, 없으면 기존 '영업' 승격, 둘 다 없으면 생성 ──
echo "\n▶ ".($apply ? "발행 시작...\n" : "[확인 모드] 발행 계획 (실제 변경 X):\n");

$parentId = $kids[$PARENT_TITLE] ?? $kids[$OLD_TITLE] ?? null;
$publishParent = ! $only || in_array('매입보드', $only, true);
$parentBlocks = blocks_board_parent();

if ($parentId && $publishParent) {
    $fromOld = ! isset($kids[$PARENT_TITLE]) && isset($kids[$OLD_TITLE]);
    if ($apply) {
        // 기존 '영업' 이면 제목·아이콘을 '🛒 매입보드 (BOARD)' 로 변경(승격)
        if ($fromOld) {
            notion('PATCH', "$BASE/pages/$parentId", [
                'properties' => ['title' => ['title' => tx($PARENT_TITLE)]],
                'icon' => ['type' => 'emoji', 'emoji' => $PARENT_ICON],
            ], $token, $V);
        }
        $del = clearBlocks($parentId, $token, $V, $apply);
        appendBlocks($parentId, $parentBlocks, $token, $V);
        echo "   ✔ 부모 '{$PARENT_TITLE}' — ".($fromOld ? "기존 '{$OLD_TITLE}' 페이지 승격(리네임) + " : '')."기존 {$del}블록 삭제 + ".count($parentBlocks)."블록(인트로) 발행\n";
    } else {
        echo "   + 부모 '{$PARENT_TITLE}' — ".($fromOld ? "기존 '{$OLD_TITLE}' 페이지를 이 이름으로 승격(리네임) 예정 + " : '(이미 존재) ').'인트로 '.count($parentBlocks)."블록 교체 예정 (하위 페이지 보존)\n";
    }
} elseif (! $parentId) {
    if ($apply) {
        $pg = notion('POST', "$BASE/pages", [
            'parent' => ['type' => 'page_id', 'page_id' => $hubId],
            'icon' => ['type' => 'emoji', 'emoji' => $PARENT_ICON],
            'properties' => ['title' => ['title' => tx($PARENT_TITLE)]],
            'children' => $parentBlocks,
        ], $token, $V);
        $parentId = $pg['id'];
        echo "   ✔ 부모 '{$PARENT_TITLE}' — 허브 직속에 신규 생성\n";
    } else {
        echo "   + 부모 '{$PARENT_TITLE}' — 허브 직속에 신규 생성 예정\n";
        $parentId = null;
    }
} else {
    echo "   · 부모 '{$PARENT_TITLE}' — 대상 아님(건너뜀)\n";
}

// ── 3. 자식 페이지 발행: 부모 하위 영업/검차/관리 ────────────
$targets = [
    '전체 워크플로우' => ['fn' => 'blocks_workflow',    'emoji' => '🔄'],
    '영업' => ['fn' => 'blocks_sales',      'emoji' => '👨‍💼'],
    '검차' => ['fn' => 'blocks_inspection', 'emoji' => '🔍'],
    '관리' => ['fn' => 'blocks_manager',    'emoji' => '🗂️'],
    '에러·락 대처' => ['fn' => 'blocks_troubleshoot', 'emoji' => '⚠️'],
];

$pkids = $parentId ? childPages($parentId, $token, $V) : [];

foreach ($targets as $name => $cfg) {
    if ($only && ! in_array($name, $only, true)) {
        continue;
    }
    $blocks = $name === '관리' ? blocks_manager($pkids['영업'] ?? null) : $cfg['fn']();
    $cid = $pkids[$name] ?? null;
    $audit = $cid ? pageAudit($cid, $token, $V) : ['count' => 0, 'footer' => false, 'after_footer' => 0, 'rows' => []];

    if (! $apply) {
        echo "   + └ $name — 부모 하위에 ".($cid ? '교체' : '신규').' '.count($blocks)."블록 (확인 모드)";
        if ($cid) {
            $expectedRows = array_map('blockSignature', $blocks);
            echo " · 라이브 {$audit['count']}블록 · 본문 ".($audit['rows'] === $expectedRows ? '✅ 일치' : '❌ 불일치')." · footer ".($audit['footer'] ? '있음' : '없음')." · footer 뒤 {$audit['after_footer']}블록";
        }
        echo "\n";

        continue;
    }
    if (! $parentId) {
        echo "   ⚠ └ $name — 부모 없음(건너뜀)\n";

        continue;
    }

    if ($cid) {
        if ($audit['after_footer'] > 0) {
            echo "   ⚠ └ $name — footer 뒤 직원 메모 {$audit['after_footer']}블록이 있어 자동 발행 건너뜀\n";
            continue;
        }
        $del = clearBlocks($cid, $token, $V, $apply);
        appendBlocks($cid, $blocks, $token, $V);
        echo "   ✔ └ $name — 기존 {$del}블록 삭제 + ".count($blocks)."블록 발행(교체)\n";
    } else {
        $pg = notion('POST', "$BASE/pages", [
            'parent' => ['type' => 'page_id', 'page_id' => $parentId],
            'icon' => ['type' => 'emoji', 'emoji' => $cfg['emoji']],
            'properties' => ['title' => ['title' => tx($name)]],
            'children' => array_slice($blocks, 0, 90),
        ], $token, $V);
        if (count($blocks) > 90) {
            appendBlocks($pg['id'], array_slice($blocks, 90), $token, $V);
        }
        echo "   ✔ └ $name — 부모 하위에 신규 생성(".count($blocks)."블록)\n";
    }
}

echo "\n".($apply ? "✅ 발행 완료.\n" : "ℹ️  확인만 했습니다. 실제 발행:  php scripts/notion-guide-publish.php --apply\n");

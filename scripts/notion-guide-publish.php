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
$only = array_values(array_intersect($argv, ['영업', '검차', '관리'])); // 비어있으면 전체
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
function footer(string $dept): array
{
    return callout('🕒', "SSANCAR 매입보드(board) 업무 가이드 · $dept · 2026-07-03 갱신 (자동 발행). 이 아래에 running log(매일 1~2줄)를 쌓으세요. 화면 캡처도 여기에.", 'gray_background');
}

// ════════════════════════════════════════════════════════════
//  영업 (board /listings + 회신 + /auction)
// ════════════════════════════════════════════════════════════
function blocks_sales(): array
{
    $b = [];
    $b[] = callout('🛒', '이 페이지는 「매입보드(board)」 앱의 영업 실무 가이드입니다. car-erp(원장)와 별개 시스템 — board 는 매입 확정 전(매입예정→검차→회신→구매확정)을 다루고, 구매확정된 차만 car-erp 로 자동 넘어갑니다.', 'blue_background');
    $b[] = callout('👨‍💼', '영업이 딜을 끝까지 소유합니다 — 매입예정 등록 → (검차팀 현지확인) → 바이어 사진+최종금액 전달·회신 → 구매확정 → car-erp 자동전환. (예전 "경매팀" 분리는 사실상 폐지, 영업이 구매확정까지.)', 'gray_background');
    $b[] = h2('🔄 전체 흐름 (board 상태)');
    $b[] = bul('매입예정 등록(draft) → 검차팀 현지확인·최종금액(검차완료 inspected = 전달대기) → 영업이 사진·영상·견적 바이어 전달(회신대기 awaiting_buyer) → 바이어 수락(구매대기 accepted) → 구매확정/낙찰(won) → car-erp 자동전환(synced)');
    $b[] = callout('🏷', '상태 라벨은 출처별로 다름 — 엔카: 구매대기/구매확정, 경매: 경매대기/낙찰. 거절=rejected, 유찰/취소=failed.', 'gray_background');
    $b[] = callout('⏰', '시간잠금은 경매 차량만 — 경매는 매일 10:00 등록 마감(이후엔 관리자만 수정). 엔카는 상시 등록(시간 마감 없음). 주말은 잠금 해제.', 'yellow_background');
    $b[] = divider();

    $b[] = h2('1. 매입예정 등록 — 화면: /listings  [영업]');
    $b[] = num('[+ 매입예정 추가] 클릭 → 먼저 출처 토글: 엔카 / 경매 선택.');
    $b[] = num('차량번호 · 차대번호(VIN) 입력 — 필수. ⚠️ 등록 후 수정 불가(중복방지 + car-erp 매칭키). 오타는 관리자만(미연동 차량 한정) 정정.');
    $b[] = num('차값(car_cost) · 할인율(%) 입력. 검사지역(region) · 추가검사사항(inspection_note 단문)도 여기서.');
    $b[] = num('저장 → 본인 화면 매입예정 목록(draft)에 뜸. 검차팀이 같은 차를 현지확인 탭에서 봄.');
    $b[] = h3('🔗 매물 링크 자동채움 (입력 줄이기)');
    $b[] = bul('엔카 링크칸에 엔카 매물 URL 붙여넣기 → 엔카 JSON API 로 차종·연식·주행거리·가격 자동수집.');
    $b[] = bul('ssancar 링크칸(별도)에 ssancar 매물 URL → 페이지 파싱으로 3통화 가격·VIN·번호판 자동채움.');
    $b[] = callout('💱', '차값 통화 — 엔카는 원화만. ssancar 는 매물표시가 토글로 원/달러/유로 중 택1한 통화를 그대로 저장(외화 그대로). 금액산정 토글(표시통화)은 보기만 바꿀 뿐 차값은 안 변함.', 'gray_background');
    $b[] = callout('⚠️', 'VIN·차량번호는 한 번 저장하면 잠김. 붙여넣기 자동채움 값도 저장 전에 꼭 눈으로 확인.', 'yellow_background');
    $b[] = divider();

    $b[] = h2('2. 금액 공식 (예상가가 아니라 계산값)  [영업/검차 공통]');
    $b[] = callout('🧮', '차량금액 = 차값 − (차값 × 할인율%) + 매도비 440,000원.  최종금액 = 차량금액 + 배송비.', 'purple_background');
    $b[] = bul('할인은 차값에만 적용, 매도비(440,000원 고정)는 할인 제외.');
    $b[] = bul('배송비 = 1640 / 1740 / 1840 USD 중 택1 (차량 크기 기준, 목적지 무관). 담당자 드롭다운.');
    $b[] = bul('표시통화 토글(KRW/USD/EUR) → Car·Shipping·Total 3줄을 그 통화로 환산해 보여줌(원장값은 불변). 환율은 board 가 자체 조회.');
    $b[] = callout('💡', '영업이 등록할 땐 차값·할인율만 넣으면 됨. 진짜 최종금액은 검차팀이 현지에서 차 상태 보고 확정 — 등록 시 금액은 잠정.', 'gray_background');
    $b[] = divider();

    $b[] = h2('3. 차량 사진 · 서류 업로드  [영업, 선택]');
    $b[] = num('매입예정 등록/편집에서 차량 사진 + 서류(차량등록증 등) 업로드.');
    $b[] = num('낙찰(won) 되면 연동 B 로 car-erp 첨부탭에 자동 등록 — 카톡으로 따로 안 보내도 됨.');
    $b[] = callout('🛡', '서류는 바이어에게 전송되지 않음(강제 제외). 차량등록증은 주소·주민번호 마스킹본만 올릴 것. 실행파일(.exe 등)은 업로드 차단됨.', 'purple_background');
    $b[] = divider();

    $b[] = h2('4. 입금정보(정산계좌) 입력  [영업, 선택]');
    $b[] = num('알고 있으면 매입예정 단계에서 예금주·은행·계좌번호 미리 입력 → 구매 드로어에 자동표시.');
    $b[] = num('은행명은 자동완성(13개 한국은행), 계좌번호는 은행별 하이픈 마스킹.');
    $b[] = callout('🔐', '계좌번호는 암호화 저장. 공란이면 구매확정 담당자가 그때 입력해도 됨.', 'gray_background');
    $b[] = divider();

    $b[] = h2('5. 전달 대기 — 바이어에게 사진·영상·견적 전송 — 화면: /forwarding(전달 대기)  [영업]');
    $b[] = num('검차완료(inspected)된 차가 "전달 대기" 목록에 뜸. 행 클릭 → 드로어에서 검차 사진·영상·최종금액 확인.');
    $b[] = num('필요하면 드로어에서 차값·할인율·배송비 바로 수정(재견적) — 최종금액·견적카드 자동 재계산.');
    $b[] = num('견적 통화 토글(₩/$/€) 후 견적카드 확인. ⚠️ 통화는 버튼을 눌렀을 때만 저장됨(무심코 누르면 EUR 딜 금액이 바뀜).');
    $b[] = h3('📤 바이어에게 보내기 — 모바일 / PC 방식이 다름');
    $b[] = bul('모바일: [전체 보내기] → 휴대폰 공유시트(카톡·왓츠앱)로 링크 전송. (사진만 파일로 보내는 보조 버튼도 있음)');
    $b[] = bul('PC: [바이어 링크 복사] 버튼 → 카톡 PC / WhatsApp Web 대화창에 붙여넣기(Ctrl+V)로 전송. (PC는 파일 공유가 안 돼 링크 방식으로 보냄)');
    $b[] = callout('🔗', '링크 1개에 검차 사진·영상(ssancar 검차영상 포함)·견적이 모두 담긴 바이어 공개 페이지가 열립니다. 영상이 몇 개든 링크 1개, 30일 유효. 사진이 없어도 영상·견적만으로 전송 가능. 링크를 카톡 등에 붙여넣으면 견적 미리보기 카드가 함께 뜨도록 만들어져 있습니다.', 'blue_background');
    $b[] = num('바이어명(선택) 입력 후 [전달 완료] → 회신대기(awaiting_buyer)로 전이.');
    $b[] = num('바이어 수락/거절은 회신 화면에서 기록. 수락 = 구매대기(accepted)로 전이(수락 차량만 구매 진입). 거절이면 딜 종료 또는 재견적(금액 수정 후 재전달).');
    $b[] = callout('⏱', '바이어 응답은 몇 시간 뒤일 수 있음. "회신대기"로 두고 다른 차 먼저 처리.', 'orange_background');
    $b[] = callout('🛡', '바이어에게 가는 사진·영상은 차량 외관/상태만 — 번호판·서류는 전송 제외(개인정보 레드라인).', 'purple_background');
    $b[] = divider();

    $b[] = h2('6. 구매확정 / 낙찰 — 화면: /auction  [영업]');
    $b[] = num('바이어 수락(accepted) 차량만 진입(미수락 차단).');
    $b[] = num('현지 최종금액으로 집행 — 엔카: 구매확정/취소, 경매: 낙찰/유찰. + 소유자·입금정보 확인.');
    $b[] = num('구매확정/낙찰(won) = SSANCAR 소유(우리 재고).');
    $b[] = callout('🤖', 'won 되면 board 가 자동으로 car-erp 에 차량+매입+사진/서류를 push(연동 B) → synced. 영업담당자는 로그인 이메일로 car-erp 에서 자동 매칭됨. 카톡 수동인계 불필요.', 'green_background');
    $b[] = callout('⚠️', 'synced 토스트가 안 뜨고 멈춰 있으면 보통 서버 큐 워커 문제 — 관리/개발(Jin)에게 알릴 것.', 'yellow_background');
    $b[] = divider();

    $b[] = h2('7. 영업 포털 — 내 정산·미수·선적 한눈에 — 화면: /portal  [영업]');
    $b[] = callout('📊', '영업 포털은 car-erp(원장)의 내 정산·미수·매입·판매·선적 현황을 board 에서 바로 조회하는 화면입니다. 읽기전용 — 금액·정산·선적 실무 수정은 car-erp 담당 몫. board 포털에서 하는 실무는 「선적요청」과 「서류 다운로드」뿐입니다.', 'blue_background');
    $b[] = h3('탭 구성');
    $b[] = bul('요약 — 내 월별 판매/정산/매입 KPI (판매는 통화가 섞여 건수로, 정산·매입은 원화 합산).');
    $b[] = bul('미수 · 매입 · 판매내역 · 정산 — 각 차량별 목록(car-erp 원장 값 그대로 표시, 재계산 안 함).');
    $b[] = bul('🚢 선적요청 — 판매완료된 수출 차량을 바이어별로 묶어 선적을 요청.');
    $b[] = h3('🚢 선적요청 (선적·B/L 묶음)');
    $b[] = num('[선적 계획] 탭에서 판매완료 차량을 바이어별로 묶음(한 묶음 = 한 바이어) → 하단 [동기화]로 car-erp 에 전송. car-erp 관리(수출통관)에게 즉시 알람이 갑니다.');
    $b[] = num('[내 선적묶음] 탭에서 진행 상태를 모니터 — 요청됨 / 진행중 / 선적완료 + B/L 상태.');
    $b[] = num('B/L 요청(오리지널 / 써랜더) → 관리에게 알람. 관리가 발급하기 전이면 [B/L요청 무름]으로 취소할 수 있음(발급 후엔 관리에게 요청).');
    $b[] = num('아직 처리 전(요청됨)인 선적은 취소 가능 — 묶음에서 빼고 다시 동기화하면 car-erp 가 자동 취소.');
    $b[] = h3('📄 서류 다운로드');
    $b[] = bul('묶음 차량의 계약서 · 인보이스/패킹 · 판매계약서를 xlsx 로 바로 다운로드.');
    $b[] = callout('⚠️', '판매계약서는 동일 바이어·단일 통화 차량만 함께 발급됩니다(혼합 묶음이면 실패 안내). 서류엔 타인 정보가 있어, 남의 포털을 조회 중일 땐 다운로드가 차단됩니다.', 'yellow_background');
    $b[] = callout('👤', '남의 정산·선적을 보는 건 시스템관리자만(이름 클릭). 일반 영업은 본인 것만 보입니다.', 'gray_background');
    $b[] = divider();

    $b[] = h2('👀 본인 차량만 보임 (SalesmanScope)');
    $b[] = para('영업 화면(매입예정·구매)에는 본인이 등록한 차량만 보입니다(크로스 영업 노출 차단). 전체를 보려면 관리자.');
    $b[] = h2('⚠️ 자주 하는 실수');
    $b[] = bul('현지 확인 전에 바이어에게 최종금액 확정해 줌 → 차 상태로 금액 달라져 신뢰 문제');
    $b[] = bul('VIN·차량번호 오타 저장 → 잠겨서 수정 불가(관리자만, 미연동 한정)');
    $b[] = bul('경매 차량을 10:00 넘겨 등록 시도 → 막힘(엔카는 상시라 무관)');
    $b[] = bul('바이어에게 서류·번호판 사진 전송 → 금지');
    $b[] = bul('견적카드 통화 토글을 무심코 눌러 EUR 딜 금액을 바꿈 → 클릭=저장임을 기억');
    $b[] = footer('영업');

    return $b;
}

// ════════════════════════════════════════════════════════════
//  검차 (board /inspection)
// ════════════════════════════════════════════════════════════
function blocks_inspection(): array
{
    $b = [];
    $b[] = callout('🔍', '이 페이지는 「매입보드(board)」 앱의 검차(현지확인) 실무 가이드입니다. 영업이 등록한 매입예정 차량을 현장에서 실차 확인하고, 사진/영상·차상태 메모·최종금액을 board 에 입력하는 절차.', 'blue_background');
    $b[] = h2('🔄 전체 흐름');
    $b[] = bul('오늘 내 배정 지역의 검차대기(draft) 차량 확인 → 현장 실차 검수 → 사진/영상·메모·최종금액 입력 → "전달" 선택 후 저장 → 영업에게 넘어감');
    $b[] = callout('📱', 'board 검차 화면은 모바일 드로어 기반 — 현장에서 휴대폰으로 바로 촬영·업로드.', 'gray_background');
    $b[] = divider();

    $b[] = h2('1. 오늘 배정 확인 — 화면: /inspection  [검차]');
    $b[] = num('화면은 검사지역별로 그룹핑됨. 내 오늘 배정 지역의 검차대기(draft) 차량 목록 확인.');
    $b[] = num('같은 지역에 배정된 동행자도 함께 표시됨.');
    $b[] = callout('🗺', '지역·날짜 배정은 관리가 미리 분배(지역×날짜 최대 3명). 내 배정이 안 보이면 관리에 문의.', 'gray_background');
    $b[] = divider();

    $b[] = h2('2. 현장 실차 검수 & 사진/영상  [검차]');
    $b[] = num('외관·주행·사고흔적 등 실차 상태 확인.');
    $b[] = num('차량 행 클릭 → 드로어에서 사진/영상 업로드. 차상태 메모(inspection_memo) 입력.');
    $b[] = callout('🛡', '촬영·업로드 사진은 차량 외관/상태만. 번호판·서류(등록증·성능지·말소신청서)는 제외 — 검차 사진은 기본적으로 바이어에게 공유됨.', 'purple_background');
    $b[] = callout('🎬', '영상 — 30초~1분 정도는 OK(현재 업로드 한도 100MB). 2분 넘는 풀HD 원본(예: 170MB+)은 한도 초과 → 휴대폰 카메라를 1080p로 설정하거나 짧게 끊어 촬영.', 'yellow_background');
    $b[] = callout('🔋', '업로드 중에는 화면이 자동으로 꺼지지 않도록 돼 있음(Wake Lock) — 큰 영상 올릴 때 화면 절전으로 업로드가 끊기지 않음. 업로드가 끝날 때까지 앱 화면을 열어둘 것.', 'gray_background');
    $b[] = divider();

    $b[] = h2('3. 최종금액 산정  [검차]');
    $b[] = num('차 상태 반영해 차값·할인율·배송비 입력 → 차량금액/최종금액 자동 계산.');
    $b[] = num('예상가(영업 등록값)와 달라도 됨 — 실제 차 상태가 기준.');
    $b[] = callout('🧮', '차량금액 = 차값 − (차값 × 할인율%) + 매도비 440,000원.  최종금액 = 차량금액 + 배송비(1640/1740/1840 USD). 표시통화 토글(KRW/USD/EUR)은 보기만 바꿈.', 'purple_background');
    $b[] = callout('⚡', '현지(한국) 흥정은 그 자리에서 빠르게 — 차 보고 즉시 금액 결정.', 'gray_background');
    $b[] = divider();

    $b[] = h2('4. 검차 완료 처리 = "선택 후 저장" (중요)  [검차]');
    $b[] = callout('🖐', 'board 검차 완료는 "수동 2단계" — 행을 클릭하면 색강조만 되고, 화면 하단의 [저장]을 눌러야 상태전이가 실제 커밋됩니다. 클릭만으로는 안 넘어감.', 'orange_background');
    $b[] = num('최종금액·사진·메모 입력 완료 후, 완료 처리할 차량 선택(색강조).');
    $b[] = num('하단 [저장] → 검차완료(inspected = 전달대기)로 전이. 이후 영업이 "전달 대기" 화면에서 사진·영상·견적을 바이어에게 보냅니다 — 전달은 검차가 아니라 영업 몫.');
    $b[] = callout('🤖', 'ssancar.com 에 그 차의 검차영상이 올라오면 board 가 자동 감지(번호판 매칭)해 해당 매입예정(draft) 차를 자동으로 전달대기(검차완료)로 넘겨줍니다 — 이 경우 검차 직원이 board 에서 따로 저장하지 않아도 영업의 전달대기 목록에 뜹니다.', 'green_background');
    $b[] = divider();

    $b[] = h2('⚠️ 자주 하는 실수');
    $b[] = bul('행 클릭만 하고 하단 [저장]을 안 눌러 → 검차완료로 안 넘어감(영업 전달대기에 안 뜸)');
    $b[] = bul('번호판·서류 사진을 올림 → 바이어에게 노출됨(외관/상태만)');
    $b[] = bul('2분 넘는 풀HD 영상 원본 업로드 → 100MB 한도 초과로 실패(1080p·짧게)');
    $b[] = bul('예상가에 맞추려고 금액 보정 → 실제 차 상태가 기준');
    $b[] = footer('검차');

    return $b;
}

// ════════════════════════════════════════════════════════════
//  관리 (board /manage + /users + /audit)
// ════════════════════════════════════════════════════════════
function blocks_manager(): array
{
    $b = [];
    $b[] = callout('🗂️', '이 페이지는 「매입보드(board)」 앱의 관리자 화면 가이드입니다. car-erp 의 "관리 (통합)" 과는 별개 — 여기서는 board 안의 전체현황 모니터링·무제한 수정·계정관리·감사로그를 다룹니다.', 'blue_background');
    $b[] = callout('🔑', '관리(manager) 권한은 영업/검차 화면을 모두 보고(전체현황), 식별값·상태를 우회 수정할 수 있습니다. super(시스템관리자)는 추가로 계정관리·감사로그까지.', 'gray_background');
    $b[] = h2('🔄 관리가 보는 것');
    $b[] = bul('전체현황(/manage) → 무제한 수정 드로어 → (super) 계정관리(/users) · 감사로그(/audit)');
    $b[] = divider();

    $b[] = h2('1. 전체현황 — 화면: /manage  [관리]');
    $b[] = num('상단 KPI 5종 — 클릭하면 그 차원으로 목록 필터 토글.');
    $b[] = num('필터(검색·상태·출처·회신) + 페이지네이션(20건). 영업·검차 구분 없이 전 차량을 봄.');
    $b[] = num('행 클릭 → 무제한 수정 드로어 — 어지간한 필드 전부 수정 가능.');
    $b[] = callout('📝', '관리의 모든 변경은 감사로그(board_audit_logs)에 자동 기록됨(누가·언제·무엇을 이전값→새값).', 'gray_background');
    $b[] = divider();

    $b[] = h2('2. 관리자 고유 우회 권한  [관리]');
    $b[] = bul('TimeGate 우회 — 경매 10:00 마감 이후에도 등록/수정(영업은 잠긴 경매 읽기전용).');
    $b[] = bul('상태 override — 상태머신 가드를 우회해 강제 전이(allowManagerOverride).');
    $b[] = bul('식별값(VIN·차량번호) 정정 — ⚠️ car-erp 미연동 차량(car_erp_vehicle_id 없음)만. 연동 후엔 잠금 유지.');
    $b[] = bul('삭제(휴지통)한 차량과 같은 차량번호·VIN 으로 새로 등록 가능 — 중복 체크는 활성(미삭제) 차량 기준. (예전엔 재등록 시 오류였으나 수정됨)');
    $b[] = callout('⚠️', '식별값 정정은 오타 교정용. 이미 car-erp 로 넘어간(synced) 차는 매칭키라 잠겨 있음 — 함부로 풀지 말 것.', 'red_background');
    $b[] = divider();

    $b[] = h2('3. 계정관리 — 화면: /users  [super 전용]');
    $b[] = num('계정 생성 · 역할 지정(영업/현지확인/경매/관리) · 시스템관리자(super) 지정 · 활성토글.');
    $b[] = num('car-erp 영업 이메일 매핑 — board 로그인 이메일과 car-erp 영업 이메일이 다르면 여기서 car-erp 이메일을 적어줌(연동 B 자동매칭용).');
    $b[] = callout('💡', '대부분 board 로그인 이메일 = car-erp 이메일이라 자동 매칭됨. "car-erp 연동 안 됨"은 보통 이 이메일 매핑 누락.', 'gray_background');
    $b[] = num('퇴사자 = 활성토글 끔(is_active=false) → 로그인돼도 업무화면 403. car-erp 쪽도 같이 정지.');
    $b[] = divider();

    $b[] = h2('4. 감사로그 — 화면: /audit  [super 전용]');
    $b[] = bul('변경이력(board_audit_logs) — 상태/회신/출처 변경을 한글로 표시.');
    $b[] = bul('car-erp 전송로그(integration_events) — 연동 B push 의 요청/응답 payload·재시도·실패 기록.');
    $b[] = callout('🔧', 'won 인데 car-erp 로 안 넘어가면 /audit 의 integration_events 로 실패 원인 확인. 큐 워커가 죽은 경우가 많음 → 서버에서 board-worker 재시작(개발/Jin).', 'yellow_background');
    $b[] = divider();

    $b[] = h2('⚠️ 관리 운영 핵심 주의');
    $b[] = bul('식별값(VIN) 잠금 해제는 미연동·오타교정만 — synced 차는 건드리지 않기');
    $b[] = bul('상태 강제 override 는 최소화 — 가드는 바이어 수락·시간잠금 같은 안전장치');
    $b[] = bul('퇴사자는 board·car-erp 양쪽 동시 비활성');
    $b[] = bul('연동 B 가 멈추면(synced 안 됨) 키 문제보다 큐 워커 점검부터');
    $b[] = footer('관리');

    return $b;
}

// ════════════════════════════════════════════════════════════
//  🛒 매입보드 (BOARD) — 부모(섹션) 페이지 인트로
// ════════════════════════════════════════════════════════════
function blocks_board_parent(): array
{
    $b = [];
    $b[] = callout('🛒', '「매입보드(board)」 앱 업무 가이드 섹션입니다. board 는 매입 확정 전(매입예정 → 현지검차 → 바이어 회신 → 구매확정)을 다루는 앱으로, car-erp(원장)와는 별개 시스템 — 구매확정된 차만 car-erp 로 자동 넘어갑니다.', 'blue_background');
    $b[] = para('아래 하위 페이지에서 본인 업무를 선택하세요:');
    $b[] = bul('👨‍💼 영업 — 매입예정 등록 · 사진+최종금액 바이어 전달·회신 · 구매확정');
    $b[] = bul('🔍 검차 — 오늘 배정지역 현지확인 · 사진/영상 · 최종금액 산정');
    $b[] = bul('🗂️ 관리 — 전체현황 모니터링 · 무제한 수정 · 계정관리 · 감사로그');
    $b[] = footer('매입보드(BOARD) 섹션');

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
            notion('DELETE', "https://api.notion.com/v1/blocks/$id", [], $t, $v);
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
$parentBlocks = blocks_board_parent();

if ($parentId) {
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
} else {
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
}

// ── 3. 자식 페이지 발행: 부모 하위 영업/검차/관리 ────────────
$targets = [
    '영업' => ['fn' => 'blocks_sales',      'emoji' => '👨‍💼'],
    '검차' => ['fn' => 'blocks_inspection', 'emoji' => '🔍'],
    '관리' => ['fn' => 'blocks_manager',    'emoji' => '🗂️'],
];

$pkids = ($parentId && $apply) ? childPages($parentId, $token, $V) : [];

foreach ($targets as $name => $cfg) {
    if ($only && ! in_array($name, $only, true)) {
        continue;
    }
    $blocks = $cfg['fn']();
    $cid = $pkids[$name] ?? null;

    if (! $apply) {
        echo "   + └ $name — 부모 하위에 ".($cid ? '교체' : '신규').' '.count($blocks)."블록 (확인 모드)\n";

        continue;
    }
    if (! $parentId) {
        echo "   ⚠ └ $name — 부모 없음(건너뜀)\n";

        continue;
    }

    if ($cid) {
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

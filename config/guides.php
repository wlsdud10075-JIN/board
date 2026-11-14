<?php

/*
 * 업무 가이드(/guides) — 씬 워크스루 목록의 단일 출처 (Jin 2026-11-13, 기획 = meetings/plan-scene-walkthrough-2026-11.md §3-2·§4-1).
 * car-erp 의 같은 화면과 **같은 구조**다(Jin 「board 도 같은 구성 + 서로 링크」) — 그쪽 config/guides.php 와 키 이름·순서를 맞춘다.
 *
 * 씬 하나 = 기능 하나를 처음부터 끝까지 실제로 눌러 본 한 장. 본문은 `resources/guides/{slug}/index.html`(+ 같은 폴더 png).
 * 본문 파일이 없는 씬은 목록에 「준비 중」으로 나오고 열리지 않는다 — 상태를 여기 적지 않는다(파일이 곧 상태).
 *
 * 🚫 시스템관리자 일(기능설정·알림톡 로그)은 넣지 않는다 — 목록에도, 씬 본문 단계에도(Jin 11-13).
 * 🔗 slug 규칙 = 영문 소문자 kebab, 「대상-동작」(ERP 와 같은 규칙 — 서로 slug 로 가리킨다).
 */
return [
    'path' => resource_path('guides'),

    // 갱신 뒤 이 날수 동안 「새로 올라옴」으로 보인다.
    'new_days' => 14,

    // 묶음(업무 흐름 순). 화면 이름은 lang/{ko,en}/guides.php `groups` — 키만 여기서 순서를 정한다.
    'groups' => ['purchase' => '매입', 'money' => '입금·탁송', 'portal' => '포털', 'claim' => '클레임', 'journal' => '업무일지', 'manage' => '관리', 'common' => '공통'],

    // 「처음이라면 — 한 바퀴」 순서(같은 차 한 대로 등록부터 선적까지).
    'lap' => ['listing-register', 'inspection-wait', 'inspection-visit', 'buyer-forward', 'purchase-confirm', 'payment-request', 'transport-dispatch', 'shipping-plan'],

    // ERP 업무 가이드로 가는 링크는 두지 않는다(2026-11-14 Jin — ERP 계정만 있고 board 계정이 없는 사람이 있어 링크가 로그인에서 막힌다.
    // ERP 와 board 는 각자 업무 가이드를 본다. ERP 쪽 board 링크도 같은 날 제거).

    // 🇬🇧 영어 화면(언어 = en)에서 쓰는 제목·요약 [slug => [제목, 한 줄 요약]].
    //    본문 영어판은 `resources/guides/{slug}/index.en.html` — 없으면 한국어 본문을 「Korean only」 표시와 함께 보여준다.
    //    씬을 추가하면 여기도 같이 넣는다(가드 = GuidesPageTest::test_every_scene_has_an_english_title).
    'scenes_en' => [
        'listing-register' => ['Register a planned purchase', 'Paste the listing link, let it fill in, add the car location and inspection request, then save'],
        'inspection-wait' => ['While waiting for inspection', 'Read the inspection status, ask for an urgent or repeat inspection, or move on with photos only'],
        'inspection-visit' => ['On-site inspection (ssancar.com)', 'How the inspector and team lead assign, visit, upload and finish in the inspection app'],
        'buyer-forward' => ['Forward to the buyer and record the reply', 'Enter the quote, send the buyer link, then record accept or reject'],
        'purchase-confirm' => ['Confirm the purchase', 'Enter amounts, attach photos and documents, pick the buyer and confirm — the car moves to ERP stock'],
        'self-inspection' => ['Self-inspected purchase', 'A car you inspected yourself goes straight to purchase confirmation'],
        'stock-purchase' => ['Stock purchase with no buyer yet', 'Confirm a purchase without a buyer and continue in the ERP'],
        'erp-resend' => ['Fix a car after it went to the ERP', 'Send amounts again or add photos and documents later'],
        'remitter-invoice' => ['Remitter from passport / ID and the invoice', 'Read the passport, send the remitter to the ERP and download the invoice'],
        'listing-delete' => ['Delete and register again', 'When you can delete your own listing and how to re-register a rejected car'],
        'payment-request' => ['Payment requests', 'Ask for the deposit or balance, follow the confirmation or rejection, and resend'],
        'sale-payment-check' => ['Sale payment check request', 'Ask the ERP to check a buyer payment and follow the result'],
        'transport-dispatch' => ['Vehicle transport dispatch', 'Ask transport companies for a driver, get the result and request the balance'],
        'portal-read' => ['Reading the portal', 'Summary, receivables, the stock groups, sales history and the sailing chips'],
        'settlement-read' => ['Reading my settlement', 'Settlements in progress, the monthly detail and the hold / extra badges'],
        'shipping-plan' => ['Shipping', 'Group cars, request shipping, download documents, e-sign and request the B/L'],
        'portal-share' => ['Share my portal', 'A salesperson ticks a manager, who can then view and act for them'],
        'claim-handle' => ['Claims', 'File a claim, write the report, get approval and send it to the ERP'],
        'work-journal' => ['Work journal', 'Write, submit, follow the approval line and resubmit after a return'],
        'manage-edit' => ['Fixing a car on the admin screen', 'Edit fields, roll back a status, delete or resend to the ERP'],
        'users-manage' => ['User management', 'Create accounts, set roles and link the ERP salesperson'],
        'audit-find' => ['Find who entered it in the audit log', 'Search by plate number, read the change history and the ERP transfer records'],
        'first-steps' => ['First steps', 'Sign in, add the app to your home screen, turn on phone alerts and pick a language'],
        'when-blocked' => ['When you are stuck', 'Why a button will not press and how to read the red notices'],
    ],

    // [slug => [묶음, 제목, 한 줄 요약, 누가 쓰나[], 찾는 말]]
    'scenes' => [
        'listing-register' => ['purchase', '매입예정 등록', '링크를 붙여넣어 자동으로 채우고 차 위치·검차 요청사항을 적어 저장합니다', ['영업'], '엔카 링크 추출 출처 차 위치 매매단지 검차 요청사항'],
        'inspection-wait' => ['purchase', '검차 기다리기', '검차상태를 읽고 긴급 검차·재검차를 요청하거나 사진만으로 전달 대기로 넘깁니다', ['영업'], '검차상태 긴급 검차 요청 보류 재검차 전달대기'],
        'inspection-visit' => ['purchase', '현지확인 검차 (ssancar.com)', '검차원과 팀장이 검차앱에서 배정·방문·사진 영상 올리기·완료까지 하는 순서입니다', ['영업', '관리', '검차원', '검차 팀장'], '오늘 내 할 차 검차 시작 등록하기 패스 보류 경로 확정 배정'],   // 독자 = 모두(신규 검차원·신규 영업 — Jin 11-13)
        'buyer-forward' => ['purchase', '바이어 전달과 회신', '전달 대기에서 견적을 넣어 바이어에게 보내고 수락·거절을 처리합니다', ['영업'], '전달 대기 바이어 회신 견적 통화 수락 거절'],
        'purchase-confirm' => ['purchase', '구매확정', '금액과 사진·서류를 넣고 바이어를 골라 확정하면 ERP 재고로 넘어갑니다', ['영업'], '경매 구매 차값 매도비 총판매가 운임비 이미지에서 정보 찾기 계좌 매입처'],
        'self-inspection' => ['purchase', '셀프검차매입', '직접 검차한 차는 등록하자마자 구매 단계로 갑니다', ['영업'], '셀프검차 직접 검차'],
        'stock-purchase' => ['purchase', '재고매입 (바이어 미정)', '바이어 없이 먼저 사두고 이후는 ERP 에서 진행합니다', ['영업'], '재고매입 바이어 미정 지급대기'],
        'erp-resend' => ['purchase', 'ERP 로 넘어간 뒤 고치기', '금액을 다시 보내거나 사진·서류를 나중에 추가합니다', ['영업', '관리'], '금액 다시 보내기 사진 서류 추가 재전송'],
        'remitter-invoice' => ['purchase', '여권/ID 로 송금인·Invoice', '여권 사진을 읽어 송금인을 ERP 로 보내고 인보이스를 받습니다', ['영업'], '여권 송금인 인보이스 COMMERCIAL INVOICE'],
        'listing-delete' => ['purchase', '삭제와 재등록', '본인 글을 지울 수 있는 조건과 거절된 차를 다시 올리는 방법입니다', ['영업'], '삭제 거절 재등록 중복'],
        'payment-request' => ['money', '입금요청', '계약금·매입잔금·대표계약금을 요청하고 확인·거절 결과를 받습니다', ['영업', '관리'], '계약금 매입잔금 대표계약금 재전송 거절 종 알림'],
        'sale-payment-check' => ['money', '판매대금확인 요청', '바이어 입금을 ERP 에 확인해 달라고 요청합니다', ['영업', '관리'], '판매대금확인 입금 확인'],
        'transport-dispatch' => ['money', '탁송 배차', '탁송 업체에 배차를 요청하고 결과를 받아 매입잔금을 요청합니다', ['영업', '관리'], '탁송 배차 업체 도착 위치 홈플 화물 야드'],
        'portal-read' => ['portal', '포털 읽는 법', '요약·미수금·재고 분류·판매내역·운항 표시를 읽는 방법입니다', ['영업', '관리'], '미수금 재고 지급대기 일반재고 선적전 출고완료 거래완료 운항중'],
        'settlement-read' => ['portal', '내 정산 읽는 법', '진행 중 정산과 월별 상세, 보류·추가 표시를 읽는 방법입니다', ['영업'], '정산 월별 지급보류 지급추가 마진율'],
        'shipping-plan' => ['portal', '선적', '선적 계획을 묶어 선적요청을 보내고 서류·전자서명·B/L 까지 진행합니다', ['영업', '관리'], '선적 계획 선적요청 포워딩사 컨테이너 판매계약서 전자서명 B/L 변경요청'],
        'portal-share' => ['portal', '내 포털 공유', '영업이 관리를 체크하면 그 관리가 대신 보고 대신 요청할 수 있습니다', ['영업', '관리'], '포털 공유 대신 실행'],
        'claim-handle' => ['claim', '클레임', '접수하고 보고서를 써 결재를 받은 뒤 ERP 로 보냅니다', ['영업', '관리'], '클레임 접수 결재 요청 확정 금액 ERP 로 보내기'],
        'work-journal' => ['journal', '업무일지', '쓰고 상신하면 결재선을 따라 결재됩니다', ['영업', '관리', '업무관리자'], '업무일지 상신 결재 반려'],
        'manage-edit' => ['manage', '관리자 화면에서 고치기', '필드를 고치고 상태를 되돌리거나 삭제·ERP 재전송을 합니다', ['관리', '업무관리자'], '관리자 수정 상태 되돌리기 삭제 재전송'],
        'users-manage' => ['manage', '사용자 관리', '계정을 만들고 역할과 ERP 영업 연결을 정합니다', ['관리', '업무관리자'], '계정 역할 비활성 ERP 영업 이메일'],
        'audit-find' => ['manage', '감사 로그에서 「누가 넣었나」 찾기', '차량번호로 찾아 변경 이력과 ERP 전송 기록을 봅니다', ['업무관리자'], '감사 로그 변경이력 전송 기록'],
        'first-steps' => ['common', '처음 쓰기', '로그인하고 홈 화면에 앱을 추가한 뒤 휴대폰 알림과 언어를 정합니다', ['영업', '관리', '업무관리자'], '로그인 홈 화면 앱 설치 알림 켜기 언어 영어'],
        'when-blocked' => ['common', '막혔을 때', '버튼이 안 눌리는 이유와 빨간 안내를 읽는 방법입니다', ['영업', '관리', '업무관리자'], '버튼 안 눌림 바이어 락 시간잠금 필수 오류'],
    ],
];

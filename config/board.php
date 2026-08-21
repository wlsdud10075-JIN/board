<?php

return [
    // 경매 차량 등록 마감 시각 (KST). 엔카는 상시(잠금 없음). 주말은 잠금 미적용.
    'auction_lock_time' => env('BOARD_AUCTION_LOCK_TIME', '10:00'),

    // S3 검차 사진 prefix (외관만 — 서류/번호판 제외)
    'inspection_photo_prefix' => 'purchase-board/inspections/vehicle-photos',

    // 영업 차량 첨부 prefix — 사진(외관)·서류(차량등록증 등) 분리. 연동 B 로 car-erp 첨부탭 전달.
    'sales_photo_prefix' => 'purchase-board/sales/photos',
    'sales_document_prefix' => 'purchase-board/sales/documents',

    // 영업 첨부 1대당 최대 건수 (car-erp 차량 첨부탭이 10건 cap 이라 맞춤)
    'attachment_max' => (int) env('BOARD_ATTACHMENT_MAX', 10),

    // 업로드 금지 확장자 (실행파일 — Jin: "exe 같은 실행파일만 차단, 나머지 허용")
    'blocked_upload_ext' => [
        'exe', 'msi', 'bat', 'cmd', 'com', 'scr', 'pif', 'cpl', 'reg',
        'ps1', 'psm1', 'vbs', 'vbe', 'js', 'jse', 'wsf', 'wsh', 'hta',
        'sh', 'bin', 'run', 'dll', 'jar', 'app', 'apk', 'deb', 'rpm',
    ],

    // 사진 저장 디스크 — 로컬은 public, 운영은 s3 (FILESYSTEM 분리). 운영 전환 시 .env BOARD_PHOTO_DISK=s3
    'photo_disk' => env('BOARD_PHOTO_DISK', 'public'),

    // ─────────── 금액 재설계 (§6) ───────────
    // 매도비 (한화 고정) — 차량금액 = 차값 − 할인 + 매도비
    'sales_fee' => (int) env('BOARD_SALES_FEE', 440000),

    // 배송금액 선택지 (USD 고정값)
    'shipping_options' => [1640, 1740, 1840],

    // 환율 폴백 (라이브 조회 실패 시) — 평시엔 exchange_rates 캐시값 사용
    'default_krw_per_usd' => (int) env('BOARD_DEFAULT_KRW_PER_USD', 1380),
    'default_krw_per_eur' => (int) env('BOARD_DEFAULT_KRW_PER_EUR', 1500),

    // 환율 캐시 신선도 (시간) — 이보다 오래되면 화면 진입 시 lazy 갱신
    'rate_ttl_hours' => (int) env('BOARD_RATE_TTL_HOURS', 1),

    // 환율 조회 소스 (키 불필요, ECB 기준). 네이버/다음 등으로 바꾸려면 base + 파서만 교체.
    'rate_api_base' => env('BOARD_RATE_API_BASE', 'https://api.frankfurter.app'),

    // 화면 진입 시 lazy 자동갱신 on/off (테스트에선 false 로 네트워크 차단)
    'rate_auto_refresh' => (bool) env('BOARD_RATE_AUTO_REFRESH', true),

    // 연동 A 승격 대기 — 캡처 후 이 일수 방치되면 폴러가 자동 expired (목록서 사라짐)
    'promotion_ttl_days' => (int) env('BOARD_PROMOTION_TTL_DAYS', 7),

    // ssancar 검차영상 자동감지 → draft 자동 전달대기 전이 on/off. ★기본 off★
    //  - 미디어 표시(services.ssancar_media)와 별개 스위치: 버퍼페이지 영상은 켜되 상태 자동전이는
    //    ssancar 폴링 계약(영상 업로드 전 videos[] 빈배열 등) 확인 후에만 켠다. 인계=handoff-ssancar-media-poll.md.
    'ssancar_auto_forward' => (bool) env('BOARD_SSANCAR_AUTO_FORWARD', false),

    // ssancar.com 미디어 폴링 에이지아웃(일) — 등록 후 이 일수 내 미디어(사진/영상) 못 받은 draft 는
    // 폴링 제외(죽은 draft=엔카 등 무한폴링 방지). 단 한 번이라도 미디어 받으면(연결됨) 이후 계속 폴링.
    'ssancar_poll_max_age_days' => (int) env('BOARD_SSANCAR_POLL_MAX_AGE_DAYS', 3),

    // ssancar 미디어 조회 타임아웃(초) — **폴러 전용**(바이어페이지는 4초 고정, 가용성 우선).
    // ssancar 박스가 2코어라 크롤러·배치와 겹치면 순간 지연(교차매칭 4.8초 실측, 평시 0.2초대).
    // 4초로는 타임아웃 → 빈 배열 → advance=false → **조용히 전이 실패**. 폴러는 백그라운드라
    // 기다리는 사용자가 없으므로 넉넉히 잡는다(ssancar 권고 15초).
    'ssancar_poll_timeout' => (int) env('BOARD_SSANCAR_POLL_TIMEOUT', 15),

    // 업무 가이드(Notion 등 외부) — 사이드바 하단 링크. 비우면 미노출.
    'work_guide_url' => env('BOARD_WORK_GUIDE_URL', 'https://dashing-stick-008.notion.site/37345d82bd838108a418c76a210f1854'),

    // 검사지역 정식 라벨 — 전국 시·군 (정적 번들, 외부 API 미사용).
    // ⚠️ 이 목록이 지역명 **정합성의 기준(canonical)**이다. App\Support\Region 이 크롤링 주소(엔카)와
    //    사람 입력을 전부 이 라벨로 맞춘다 → users.region ↔ purchase_listings.region ↔
    //    inspection_assignments.region 이 문자열 그대로 조인된다.
    // ⚠️ 도 약칭(경기/충북…)·접미사 표기를 바꾸지 말 것 — 이미 저장된 값과 어긋난다.
    //    도시를 추가하는 건 안전(축약형 복원 후보가 늘 뿐).
    'regions' => [
        '서울특별시', '부산광역시', '대구광역시', '인천광역시', '광주광역시', '대전광역시', '울산광역시', '세종특별자치시',
        '경기 수원시', '경기 성남시', '경기 의정부시', '경기 안양시', '경기 부천시', '경기 광명시', '경기 평택시',
        '경기 동두천시', '경기 안산시', '경기 고양시', '경기 과천시', '경기 구리시', '경기 남양주시', '경기 오산시',
        '경기 시흥시', '경기 군포시', '경기 의왕시', '경기 하남시', '경기 용인시', '경기 파주시', '경기 이천시',
        '경기 안성시', '경기 김포시', '경기 화성시', '경기 광주시', '경기 양주시', '경기 포천시', '경기 여주시',
        '경기 연천군', '경기 가평군', '경기 양평군',
        '강원 춘천시', '강원 원주시', '강원 강릉시', '강원 동해시', '강원 태백시', '강원 속초시', '강원 삼척시',
        '강원 홍천군', '강원 횡성군', '강원 영월군', '강원 평창군', '강원 정선군', '강원 철원군', '강원 화천군',
        '강원 양구군', '강원 인제군', '강원 고성군', '강원 양양군',
        '충북 청주시', '충북 충주시', '충북 제천시', '충북 보은군', '충북 옥천군', '충북 영동군', '충북 증평군',
        '충북 진천군', '충북 괴산군', '충북 음성군', '충북 단양군',
        '충남 천안시', '충남 공주시', '충남 보령시', '충남 아산시', '충남 서산시', '충남 논산시', '충남 계룡시',
        '충남 당진시', '충남 금산군', '충남 부여군', '충남 서천군', '충남 청양군', '충남 홍성군', '충남 예산군', '충남 태안군',
        '전북 전주시', '전북 군산시', '전북 익산시', '전북 정읍시', '전북 남원시', '전북 김제시', '전북 완주군',
        '전북 진안군', '전북 무주군', '전북 장수군', '전북 임실군', '전북 순창군', '전북 고창군', '전북 부안군',
        '전남 목포시', '전남 여수시', '전남 순천시', '전남 나주시', '전남 광양시', '전남 담양군', '전남 곡성군',
        '전남 구례군', '전남 고흥군', '전남 보성군', '전남 화순군', '전남 장흥군', '전남 강진군', '전남 해남군',
        '전남 영암군', '전남 무안군', '전남 함평군', '전남 영광군', '전남 장성군', '전남 완도군', '전남 진도군', '전남 신안군',
        '경북 포항시', '경북 경주시', '경북 김천시', '경북 안동시', '경북 구미시', '경북 영주시', '경북 영천시',
        '경북 상주시', '경북 문경시', '경북 경산시', '경북 의성군', '경북 청송군', '경북 영양군', '경북 영덕군',
        '경북 청도군', '경북 고령군', '경북 성주군', '경북 칠곡군', '경북 예천군', '경북 봉화군', '경북 울진군', '경북 울릉군',
        '경남 창원시', '경남 진주시', '경남 통영시', '경남 사천시', '경남 김해시', '경남 밀양시', '경남 거제시',
        '경남 양산시', '경남 의령군', '경남 함안군', '경남 창녕군', '경남 고성군', '경남 남해군', '경남 하동군',
        '경남 산청군', '경남 함양군', '경남 거창군', '경남 합천군',
        '제주 제주시', '제주 서귀포시',
    ],

    // ─────────── 브라우저 탭 아이콘 (jin 2026-08-21) ───────────
    // 인스턴스(회사)별 파비콘. car-erp 와 **같은 아이콘**을 쓴다(heymanerp=파란 H, ssancarerp=빨간 SS).
    //
    // 🧭 키 = 그 박스 `.env` 의 **APP_NAME**. board 엔 car-erp 의 `company.template_set` 같은
    //    「내가 어느 회사인가」 값이 없지만, APP_NAME 이 이미 박스마다 다르다(실측: heymanboard=
    //    `board-heyman` / ssancarboard=`board-ssancar`). 그래서 식별 수단을 새로 만들지 않는다
    //    — .env 를 건드리는 순간 백업 동기화·`config:cache`(ubuntu 전용) 가 딸려온다.
    // ⚠️ 파일명이 car-erp 와 다른 게 하나 있다: ssancar 는 저쪽에서 `favicon-system.ico`(구 명칭
    //    template_set='system') 인데, board 엔 그 잔재가 없어 회사명 그대로 `favicon-ssancar.ico` 다.
    // ⚠️ 목록에 없는 APP_NAME(로컬 `board`, 아직 없는 karababoard)은 **선언 자체를 생략**한다.
    //    아무거나 폴백하면 그 박스에 **다른 회사 로고**가 뜬다 — 조용히 틀리는 부류라 안 그리는 게 낫다.
    'favicons' => [
        'board-heyman' => 'favicon-heyman.ico',
        'board-ssancar' => 'favicon-ssancar.ico',
    ],
];

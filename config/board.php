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

    // 업무 가이드(Notion 등 외부) — 사이드바 하단 링크. 비우면 미노출.
    'work_guide_url' => env('BOARD_WORK_GUIDE_URL', 'https://dashing-stick-008.notion.site/37345d82bd838108a418c76a210f1854'),

    // 검사지역 자동완성 — 한국 도+주요 시 (정적 번들, 외부 API 미사용)
    'regions' => [
        '서울특별시', '부산광역시', '대구광역시', '인천광역시', '광주광역시', '대전광역시', '울산광역시', '세종특별자치시',
        '경기 수원시', '경기 성남시', '경기 용인시', '경기 고양시', '경기 부천시', '경기 안산시', '경기 안양시', '경기 남양주시',
        '경기 화성시', '경기 평택시', '경기 의정부시', '경기 시흥시', '경기 파주시', '경기 김포시', '경기 광주시', '경기 광명시',
        '강원 춘천시', '강원 원주시', '강원 강릉시',
        '충북 청주시', '충북 충주시',
        '충남 천안시', '충남 아산시', '충남 서산시',
        '전북 전주시', '전북 익산시', '전북 군산시',
        '전남 여수시', '전남 순천시', '전남 목포시',
        '경북 포항시', '경북 구미시', '경북 경주시', '경북 경산시',
        '경남 창원시', '경남 김해시', '경남 진주시', '경남 양산시', '경남 거제시',
        '제주 제주시', '제주 서귀포시',
    ],
];

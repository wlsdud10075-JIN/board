<?php

return [
    // 경매 차량 등록 마감 시각 (KST). 엔카는 상시(잠금 없음). 주말은 잠금 미적용.
    'auction_lock_time' => env('BOARD_AUCTION_LOCK_TIME', '10:00'),

    // S3 검차 사진 prefix (외관만 — 서류/번호판 제외)
    'inspection_photo_prefix' => 'purchase-board/inspections/vehicle-photos',

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

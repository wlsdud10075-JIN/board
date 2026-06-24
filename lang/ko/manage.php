<?php

// 관리자(manage) 화면 — 보이는 텍스트.

return [
    // 헤더
    'title' => '관리자',
    'subtitle_html' => '✏️ 시간잠금 무관 수정 (예상가·최종금액·출처·상태) — 단 <b>차량번호·VIN은 수정 불가</b>. 모든 변경은 감사로그 기록.',

    // KPI 카드
    'kpi_today' => '오늘 매입예정',
    'kpi_encar' => '엔카',
    'kpi_auction' => '경매',
    'kpi_accepted' => '바이어 수락',
    'kpi_won' => 'ERP 전환대기',

    // 전체 현황
    'overview' => '전체 현황',
    'count_suffix' => '건',
    'clear_filters' => '필터해제 ✕',
    'search_placeholder' => '🔍 차량번호·매물번호·소유자',
    'status_all' => '상태 전체',
    'source_all' => '출처 전체',
    'verdict_all' => '바이어회신 전체',
    'verdict_pending' => '회신대기',
    'verdict_accepted' => '수락',
    'verdict_rejected' => '거절',

    // 출처
    'source_encar' => '엔카',
    'source_auction' => '경매',

    // 표 헤더
    'th_vehicle' => '차량',
    'th_source' => '출처',
    'th_sales' => '영업',
    'th_expected' => '예상가',
    'th_total' => '최종금액',
    'th_buyer' => '바이어',
    'th_status' => '상태',
    'empty' => '조건에 맞는 데이터가 없습니다.',

    // 모바일 카드
    'card_sales' => '영업',
    'card_expected' => '예상',
    'card_total' => '최종',

    // 수정 드로어
    'edit_suffix' => '수정',
    'vehicle_number' => '차량번호',
    'vehicle_number_hint' => '· 오타 정정 가능',
    'vin' => '차대번호 VIN',
    'identity_locked_html' => '🔗 이미 car-erp 연동된 차량 — 식별값 수정 불가',
    'identity_vehicle' => '차량번호',
    'identity_vin' => 'VIN',
    'owner_name' => '소유자 (차주명)',
    'c_no' => '매물번호 (c_no)',
    'source' => '출처',
    'region' => '지역',
    'region_placeholder' => '검사지역',
    'car_cost' => '차값',
    'discount_rate' => '할인율%',
    'shipping_usd' => '배송$',
    'expected_price' => '예상가',
    'final_price' => '현지 최종금액',
    'status' => '상태',
    'buyer_verdict' => '바이어 회신',
    'verdict_none' => '없음',
    'buyer_name' => '바이어명',
    'inspection_memo' => '메모 (차상태)',
    'inspection_note' => '추가검사사항',
    'encar_url' => '엔카 매물 URL',
    'encar_dealer' => '엔카 딜러',
    'auction_venue' => '경매장',
    'lot_number' => '출품번호',

    // 입금정보
    'payment_info' => '입금정보',
    'payment_info_sub' => '(정산 계좌)',
    'payee_bank' => '은행',
    'payee_name' => '예금주',
    'payee_account' => '계좌번호 (암호화)',

    // 액션
    'save' => '저장 (감사로그 기록)',
    'delete' => '이 건 삭제 (시스템관리자)',
    'delete_confirm' => '이 매입 건을 삭제할까요? 잘못 등록된 건 정리용입니다. (감사로그에 기록)',
    'resync' => 'car-erp 재전송 (시스템관리자)',
    'resync_confirm' => 'car-erp 로 다시 전송할까요? 중복은 안 생깁니다(차량번호로 매칭 — 있으면 재연결, 지웠으면 새로 생성).',

    // flash
    'saved' => ':vehicle 수정 완료 — 변경 내역이 감사로그에 기록됐습니다.',
    'deleted' => ':vehicle 삭제 완료 — 감사로그에 기록됐습니다.',
    'resynced' => ':vehicle car-erp 재전송 요청 — 처리되면 ERP전환완료(synced)로 바뀝니다.',
];

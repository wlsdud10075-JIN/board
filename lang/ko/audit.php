<?php

// 감사 로그 화면(/audit) — 변경 이력(board_audit_logs) + car-erp 전송 로그(integration_events).

return [
    'title' => '감사 로그',
    'super_only' => '시스템관리자 전용',
    'intro' => '🔒 금액·상태·식별값 변경 이력 + car-erp 전송 기록(append-only). 감사·대조용 보존.',

    // 변경 이력 테이블
    'change_history' => '변경 이력',
    'col_time' => '시각',
    'col_changer' => '변경자',
    'col_vehicle' => '차량',
    'col_item' => '항목',
    'col_change' => '변경',
    'system' => '시스템',
    'no_changes' => '기록된 변경이 없습니다.',

    // car-erp 전송 로그 테이블
    'transmission' => 'car-erp 전송',
    'col_direction_target' => '방향/대상',
    'col_event' => '이벤트',
    'col_response' => '응답',
    'col_content' => '내용',
    'no_transmissions' => '전송 기록이 없습니다.',

    // 변경이력 필드명 한글 표시 (field 코드 → 한글)
    'field' => [
        'source' => '출처',
        'status' => '상태',
        'buyer_verdict' => '바이어회신',
        'buyer_name' => '바이어',
        'expected_price' => '예상가',
        'final_price' => '최종금액',
        'car_cost' => '차값',
        'discount_rate' => '할인율',
        'shipping_usd' => '배송비',
        'owner_name' => '소유자',
        'payee_name' => '예금주',
        'payee_bank' => '은행',
        'payee_account' => '계좌',
        'vehicle_number' => '차량번호',
        'vin' => 'VIN',
        'car_erp_vehicle_id' => 'car-erp차량',
        'region' => '지역',
        'inspection_note' => '추가검사',
        'inspection_memo' => '메모',
        'c_no' => '매물번호',
        'encar_url' => '엔카URL',
        'encar_dealer' => '엔카딜러',
        'auction_venue' => '경매장',
        'lot_number' => '출품번호',
    ],
];

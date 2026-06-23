<?php

// 경매/구매 화면 (auction) — 사용자에게 보이는 문구.

return [
    'title' => '경매/구매',
    'subtitle' => '🏁 바이어가 <b>수락</b>한 차량만 진입 — 경매=낙찰/유찰 · 엔카=구매확정/취소 · 현지 최종금액으로 집행',

    'panel_title' => '경매/구매 컨트롤창',
    'accepted_count' => '수락 :count건',

    // 표 헤더
    'col_vehicle' => '차량',
    'col_source' => '출처',
    'col_salesman' => '영업',
    'col_final_price' => '현지 최종금액',
    'col_process' => '처리',

    'pending_click' => '집행 대기 · 클릭',
    'pending_tap' => '집행 대기 · 탭',
    'empty' => '수락된 차량이 없습니다.',
    'row_click_hint' => '💡 행을 클릭하면 차량 상세를 볼 수 있습니다.',

    // 모바일 카드
    'salesman_label' => '영업',
    'region_label' => '지역',

    // 드로어
    'vin_pending' => '— (NICE 조회 예정)',
    'listing_no' => '· 매물 :no',

    'car_cost' => '차값',
    'discount_rate' => '할인율',
    'shipping' => '배송',
    'buyer' => '바이어',
    'final_price' => '현지 최종금액',

    'inspection_memo' => '검사 메모',
    'vehicle_photos' => '차량 사진',

    'owner' => '소유자',
    'owner_hint' => '(차주명 · car-erp VIN 조회용)',
    'owner_placeholder' => '등록 소유자명',

    'payment_info' => '입금정보',
    'payment_info_hint' => '(매입 정산 계좌 · car-erp 전달)',
    'bank_placeholder' => '은행',
    'payee_placeholder' => '예금주',
    'account_placeholder' => '계좌번호 (암호화 저장)',

    'execute' => '집행',
    'execute_hint' => '낙찰/구매확정 시 위 입금정보가 함께 저장됩니다.',
    'won_auction' => '낙찰',
    'won_encar' => '구매확정',
    'failed_auction' => '유찰',
    'failed_encar' => '취소',
    'save_payment_info' => '입금정보 저장',

    // flash
    'flash_payee_saved' => '입금정보를 저장했습니다.',
    'flash_only_accepted' => '바이어 수락 상태의 차량만 집행할 수 있습니다.',
    'flash_processed' => ':no — :label 처리되었습니다.',
];

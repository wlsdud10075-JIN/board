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
    // 차값 입력 (2026-08-10) — 셀프검차매입이 금액을 넣을 수 있는 유일한 지점.
    'amount_section' => '금액 (바이어 금액이 여기서 계산됩니다)',
    'quote_currency' => '견적 통화',
    // 셀프검차매입 전용 금액칸 (2026-08-10) — 검차·견적 씬이 없어 파생계산의 근거가 없다.
    'selling_fee' => '매도비',
    'sale_price' => '판매가',
    'offer_rate' => '환율',
    'transport_fee' => '운임비',
    'self_currency_hint' => '판매가·환율·운임비는 아래에서 고른 :currency 기준입니다. 차값·매도비는 항상 원화입니다.',
    'self_currency_unset' => '⚠️ 견적통화를 아직 안 골랐습니다 — 아래에서 고르세요. 판매가·환율·운임비가 그 통화 기준이 됩니다.',
    'self_amount_hint' => 'ERP 매입가 = 차값 − 매도비 = :purchase원. 매도비는 차값에 포함된 금액이라 빼서 보냅니다(합계는 그대로).',
    'car_cost_ph' => '예: 12000000',
    'car_cost_hint' => '차값·할인·차감액·배송으로 아래 현지 최종금액(바이어 금액)이 계산됩니다. 차값이 비면 구매확정이 막힙니다.',
    'car_cost_missing' => '⚠️ 차값이 비어 있습니다 — 넣어야 ERP 로 넘어갑니다.',
    'err_amount_required' => '차값을 넣어야 구매확정할 수 있습니다. 금액이 없으면 ERP 로 넘어가지 않습니다.',
    'err_sale_price_required' => '판매가를 넣어야 구매확정할 수 있습니다. 비우면 ERP 판매정보가 빈 채로 생깁니다.',
    // 매입 등록 락 (car-erp §4-0, 2026-08-10) — 락은 절대 규칙이 아니다(ERP 관리자가 사유를 적으면 통과).
    'buyer_lock_title' => '이 바이어는 매입 등록이 막혀 있습니다',
    'buyer_lock_unsecured' => '무담보 잔액 :current원 / 한도 :limit원',
    'buyer_lock_ratio' => '미수율 :current% / 임계 :limit%',
    'buyer_lock_notice' => 'ERP 관리자 승인이 필요합니다. 승인 후 다시 시도하세요.',
    'err_attachment_required' => '차량 사진·서류를 최소 1건 첨부해야 구매확정할 수 있습니다 — 확정 시 car-erp 첨부탭으로 함께 전달됩니다.',
    'err_buyer_required' => '바이어를 선택해야 구매확정할 수 있습니다.',
    'err_buyer_purchase_locked' => '이 바이어는 매입 등록이 막혀 있습니다 — ERP 관리자 승인 후 구매확정할 수 있습니다.',
    'err_currency_required' => '견적통화를 골라야 합니다. 안 고르면 판매가가 원화로 기록됩니다(8,590 USD → 8,590원).',
    'err_rate_required' => '환율을 넣어야 합니다. 비우면 합의환율이 아니라 오늘 환율이 기록됩니다.',
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
    'selling_fee_info' => '매도비 계좌',
    'selling_fee_info_hint' => '(판매자와 다른 대상 · car-erp 전달)',
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
    'flash_resent' => ':no 저장 완료 — ERP 로 다시 전송했습니다. 처리되면 ERP전환완료(synced)로 바뀝니다.',
    'flash_only_accepted' => '바이어 수락 상태의 차량만 집행할 수 있습니다.',
    'flash_processed' => ':no — :label 처리되었습니다.',

    // 바이어/컨사이니 드롭다운 (car-erp 목록)
    'buyer' => '바이어 / 컨사이니',
    'buyer_hint' => 'car-erp 목록에서 선택 (본인 담당)',
    'buyer_unavailable' => '바이어 목록을 불러올 수 없습니다 — car-erp에서 수동 지정.',
    'buyer_select' => '바이어 선택',
    'consignee_select' => '컨사이니 선택 (선택)',

    'attach' => [
        'title' => '딜러 차량 첨부',
        'hint' => '매입 후 딜러에게 받은 사진·서류 (최대 :max건). 낙찰 시 car-erp 첨부탭으로 전달',
        'dropzone' => '＋ 사진·서류 추가 (탭)',
        'uploading' => '업로드 중…',
        'delete_confirm' => '이 첨부를 삭제할까요?',
        'help' => '이미지=사진 / 그 외=서류로 자동 분류. 서류는 바이어에게 전송되지 않습니다.',
        'exec_error' => '실행파일은 첨부할 수 없습니다 (:name).',
        'max_error' => '첨부는 최대 :max건입니다 (현재 :existing건).',
    ],
];

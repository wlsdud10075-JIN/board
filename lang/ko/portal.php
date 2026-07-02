<?php

// 영업 포털 — 재무·미수·매입·판매·정산·선적요청·서류.

return [
    // 헤더
    'title' => '내 정산·미수·선적 (포털)',
    'viewing_other' => ':name 님의 정보 조회 중 — car-erp 원장 읽기. 수정·선적실무는 car-erp.',
    'viewing_self' => '본인(:name) 정보만 — car-erp 원장 읽기. 수정·선적실무는 car-erp.',
    'footer_note' => '읽기전용(car-erp 원장). 금액·정산·선적 실무 수정은 car-erp 담당에게. 선적요청은 car-erp 관리에게 알람으로 전달됩니다.',

    // 사용자별 조회 (super)
    'view_by_user' => '사용자별 조회',
    'view_by_user_hint' => '이름을 누르면 그 사용자의 정산·미수·선적이 표시됩니다 (시스템관리자 전용)',
    'view_self_btn' => '나(본인)',

    // 탭
    'tab' => [
        'finance' => '요약',
        'receivables' => '미수금',
        'purchases' => '매입내역',
        'sales' => '판매내역',
        'settlements' => '정산내역',
        'shipping' => '선적요청',
    ],
    'reload' => '갱신',
    'reload_title' => '새로고침',

    // 선적요청 성공 배너
    'ship_done_title' => '선적요청 접수 완료!',
    'ship_done_body' => ':count대 선적요청이 car-erp로 전송됐습니다.',
    'ship_done_skipped' => '(:count대는 이미 요청됨/대상 아님 — 건너뜀)',
    'ship_done_alarm' => 'car-erp 관리(수출통관)에게 알람이 전달되어 선적 진행이 시작됩니다.',

    // degrade / 조회 불가
    'unavailable' => '조회 불가',
    'degrade_403' => '내 영업 계정이 car-erp에 연결되지 않았습니다. (관리자에게 car-erp 영업 이메일 매핑을 요청하세요)',
    'degrade_not_configured' => 'car-erp 연동이 아직 설정되지 않았습니다. (관리자 문의)',
    'degrade_default' => '지금은 car-erp 정보를 불러올 수 없습니다. 잠시 후 다시 시도하세요.',

    // flash (선적·서류)
    'flash_view_only_ship' => '조회 전용입니다. 선적요청은 본인 계정에서 진행하세요.',
    'flash_select_vehicle' => '차량을 선택하세요.',
    'flash_ship_failed' => '선적요청 전송 실패 — 잠시 후 다시 시도하세요.',
    'flash_view_only_docs' => '조회 전용입니다. 서류는 본인 계정에서 받으세요.',
    'flash_select_vehicle_docs' => '서류 받을 차량을 선택하세요.',
    'flash_docs_failed' => '서류를 불러올 수 없습니다. (car-erp 연동 확인)',
    'flash_docs_sales_contract_failed' => '판매계약서를 발급할 수 없습니다. 동일 바이어·단일 통화 차량만 함께 발급됩니다. (묶음 구성/연동 확인)',

    // 요약(재무) KPI
    'kpi_unpaid_total' => '미수금 합계',
    'kpi_purchase_unpaid_total' => '매입 미지급 합계',
    'kpi_settlement_pending' => '정산 대기',
    'kpi_fx_missing' => '환율 미입력',

    // 월별 실적
    'monthly_perf' => '월별 실적',
    'monthly_empty' => '월별 실적이 없습니다.',
    'monthly_note' => '판매액은 통화가 섞여 합산 대신 건수로 표시. 정산·매입은 원화 합산.',
    'col_month' => '월',
    'col_sales_cnt' => '판매(건)',
    'col_settle_sum' => '정산 실지급(원)',
    'col_purch_cnt' => '매입(건)',
    'col_purch_sum' => '매입가(원)',
    'm_sales' => '판매',
    'm_purchase' => '매입',
    'm_settle' => '정산',
    'm_purch_price' => '매입가',

    // 선적요청 탭
    'ship_inprogress_title' => '진행 중인 선적요청',
    'ship_status_requested' => '요청됨',
    'ship_status_in_progress' => '진행중',
    'ship_method_undefined' => '방식 미정',
    'ship_inprogress_note' => '<b>요청됨</b> = car-erp 관리(수출통관) 접수 / <b>진행중</b> = 처리 중. 선적·통관이 끝나면 목록에서 빠집니다.',
    'ship_intro' => '판매완료된 본인 수출 차량을 <b>바이어별로 묶어</b> RORO/컨테이너 선적을 요청합니다. 요청하면 car-erp 관리(수출통관)에게 즉시 알람이 갑니다.',
    'buyer_unassigned' => '바이어 미지정',
    'buyer_unassigned_paren' => '(바이어 미지정)',
    'ship_available_count' => ':count대 선적가능',
    'ship_view_only_note' => '조회 전용 — 선적요청·서류는 본인(:name) 계정에서 진행합니다.',
    'consignee_select' => '컨사이니 선택',
    'ship_request_btn' => '선적요청',
    'docs_label' => '선택 차량 서류(:method):',
    'docs_contract' => '계약서',
    'docs_invoice_packing' => '인보이스·패킹',
    'docs_sales_contract' => '판매계약서',
    'ship_empty' => '선적 가능한 차량이 없습니다. (판매완료·수출·미요청 차량만 표시)',

    // 선적·B/L 묶음 v2
    'ship_sub_bundles' => '내 선적묶음',
    'ship_sub_plan' => '선적 계획',
    'ship_status_done' => '선적완료',
    'ship_status_cancelled' => '취소됨',
    'bl_status_requested' => 'B/L요청됨',
    'bl_status_issued' => 'B/L발급됨',
    'bl_original' => '오리지널',
    'bl_surrender' => '써랜더',
    'bl_undecided' => '미정',
    'bl_request_label' => 'B/L 요청:',
    'bl_confirm' => ':type B/L을 요청할까요? 관리에게 알람이 가며, 되돌리려면 관리에게 취소를 요청해야 합니다.',
    'bl_requested_already' => '요청됨: :type',
    'bl_cancel_btn' => 'B/L요청 무름',
    'bl_cancel_confirm' => 'B/L 요청을 무를까요? (관리 발급 전에만 가능)',
    'flash_bl_cancelled' => 'B/L 요청을 무름 처리했습니다.',
    'flash_bl_already_issued' => '관리가 이미 B/L을 발급해 무를 수 없습니다.',
    'ship_fx_missing' => '환율 미입력 :count대 — 완납 판정 불가(B/L 발급 전 입력 필요)',
    'ship_fully_paid' => '완납',
    'ship_unpaid' => '미수',
    'fx_missing_short' => '환율 미입력',
    'change_request_hint' => '관리가 착수한 묶음 — 자동 변경 불가. 변경/취소는 요청하면 관리가 처리합니다.',
    'change_request_ph' => '변경/취소 사유',
    'change_request_btn' => '변경·취소 요청',
    'cancel_bundle_btn' => '선적 취소',
    'cancel_bundle_confirm' => '이 선적(요청)을 취소할까요? car-erp 에서 자동 취소됩니다.',
    'bundles_empty' => '선적묶음이 없습니다. "선적 계획"에서 차량을 묶어 동기화하세요.',
    'plan_intro' => '판매완료 수출 차량을 <b>묶음으로 구성</b>해 한 번에 동기화합니다. 묶음 = 1선적 = 1 B/L.',
    'plan_remove_bundle' => '묶음 삭제',
    'plan_bundle_empty' => '차량 없음 — 아래에서 담기',
    'plan_add_bundle' => '새 묶음 추가',
    'plan_shipment' => '선적',
    'plan_shipment_n' => '선적 #:n',
    'plan_add_shipment' => '선적 추가',
    'plan_no_cars' => '담을 차 없음',
    'plan_no_buyers' => '선적 계획할 차가 없습니다. (판매완료·수출 차량)',
    'plan_pool_title' => '새로 묶을 차',
    'plan_pool_empty' => '새로 묶을 차가 없습니다.',
    'plan_assign_to' => '묶음에 담기…',
    'plan_new_bundle_opt' => '새 묶음',
    'plan_sync_btn' => '동기화',
    'plan_sync_warn' => '동기화하면 화면의 전체 묶음이 car-erp에 반영됩니다 — 묶음에서 뺀 미착수 차는 자동 취소됩니다.',
    'sync_done_title' => '동기화 완료!',
    'sync_created' => '생성 :count',
    'sync_updated' => '갱신 :count',
    'sync_cancelled' => '취소 :count',
    'sync_locked' => '처리중(잠금) :count',
    'flash_bl_requested' => 'B/L 요청이 전송됐습니다. car-erp 관리에게 알람이 갑니다.',
    'flash_change_requested' => '변경요청이 전송됐습니다. 관리가 확인 후 처리합니다.',
    'flash_change_note_required' => '변경/취소 사유를 입력하세요.',
    'flash_sync_blocked_degraded' => '선적묶음을 불러오지 못해 동기화를 막았습니다(전체취소 방지). 새로고침 후 다시 시도하세요.',
    'flash_sync_incomplete_buyer' => '기존 묶음의 바이어 정보(buyer_id)가 응답에 없어 동기화를 막았습니다(전체취소 방지). car-erp 연동 점검 필요.',

    // 미수금 탭
    'hide_paid' => '완납(0원) 숨기기',
    'recv_empty' => '미수금 내역이 없습니다.',
    'recv_empty_hidden' => ' (완납 숨김 적용 중)',
    'fx_missing' => '환율 미입력',
    'fx_rate_label' => '환율',
    'col_vehicle' => '차량',
    'col_currency' => '통화',
    'col_exchange_rate' => '환율',
    'col_unpaid_krw' => '미수금(원)',

    // 판매내역 탭
    'sales_empty' => '판매내역이 없습니다.',
    'sales_detail_empty' => '차량 상세 없음',
    'col_sale_price' => '판매가',
    'col_sale_date' => '판매일',

    // 정산내역 탭
    'settle_empty' => '정산내역이 없습니다.',
    'col_buyer' => '바이어',
    'col_vehicle_count' => '차량수',
    'col_payout_total' => '정산 실지급(원)',
    'col_payout_paid' => '지급 완료(원)',
    'lbl_payout_total' => '정산 실지급',
    'lbl_payout_paid' => '지급 완료',

    // 매입내역 탭
    'purch_empty' => '매입내역이 없습니다.',
    'col_purchase_price' => '매입가',
    'col_cost_total' => '비용합',
    'col_purchase_unpaid' => '미지급',
    'col_purchase_date' => '매입일',

    // 단위
    'unit_vehicles' => ':count대',
    'unit_count' => ':count건',
    'count_suffix' => '건',   // 굵은 숫자 뒤 단위 접미 (모바일 월별)

    // 한글 축약 금액 (abbrevKrw) — 억=10^8, 만원=10^4
    'abbr_eok' => '억',
    'abbr_man' => '만원',
    'abbr_won' => '원',
];

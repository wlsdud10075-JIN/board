<?php

// 현지확인(inspection) 화면 전용 텍스트.

return [
    'title' => '현지확인',
    'subtitle_manage' => '관리: 그날치 인원 배정',
    'subtitle_mine' => '본인 배정 지역만 표시',
    'subtitle_flow' => '지역별 그룹 · :mode → 차 상태 확인 → 최종금액 산정 → 바이어 전달',

    // 지역 배정 패널
    'assign_panel_title' => '오늘 지역 배정',
    'max_per_region' => '지역당 최대 :max인',
    'region' => '지역',
    'region_select' => '지역 선택',
    'assignee_inspection' => '담당자 (현지확인)',
    'assignee_select' => '담당자 선택',
    'assign_button' => '+ 배정',
    'assign_hint' => '검차대기 차량에 :region이 지정되면 여기서 배정할 수 있습니다. (매입예정에서 지역 입력)',
    'assign_hint_region_word' => '지역',
    'max_per_region_error' => '지역당 최대 :max인까지 배정할 수 있습니다.',
    'only_inspection_assignable' => '현지확인 담당자만 배정할 수 있습니다.',
    'assigned_ok' => '배정되었습니다.',

    // 배정 현황 요약 테이블
    'col_region' => '지역',
    'col_people' => '배정 인원',
    'col_cars' => '차량 수',
    'unassigned' => '미배정',
    'cars_count' => ':count건',

    // 지역별 차량 목록
    'region_unset' => '지역 미지정',
    'items_count' => ':count건',
    'no_assignment_label' => '미배정',
    'final_amount_prefix' => '최종 :amount원',
    'amount_undecided' => '금액 미정',
    'empty_for_manager' => '현지확인 대상 차량이 없습니다.',
    'empty_for_inspector' => '오늘 배정된 지역이 없습니다. (관리자 배정 대기)',

    // 드로어
    'drawer_title' => '현지 확인',
    'expected_price_line' => '예상가 :price · 차 상태 보고 최종금액 산정',

    // 사진/영상
    'photos_section' => '차량 사진·영상 (외관만 · 서류/번호판 제외)',
    'photo_upload_label' => '후면카메라 촬영 / 사진·영상 업로드',
    'uploading' => '업로드 중…',
    'new_files_count' => '새 파일 :count개 — 저장 시 반영',
    'share_to_buyer' => '바이어공개',
    'share_to_buyer_on' => '✓ 바이어공개',
    'photo_share_hint' => '바이어에게 보낼 :exterior "바이어공개" 켜기 (서류·번호판 제외). 전달 시 USD 금액과 함께 자동 전송.',
    'photo_share_hint_exterior' => '외관 사진/영상만',

    // 검사지역
    'inspection_region_section' => '검사지역',
    'region_placeholder' => '예: 경기 수원시 (입력 시 자동완성)',

    // 메모
    'memo_section' => '차 상태 메모',
    'memo_placeholder' => '예: 운전석 시트 사용감, 앞범퍼 미세 스크래치',

    // 추가검사사항
    'note_section' => '추가검사사항',
    'note_placeholder' => '예: 보증서 미비, 타이어 교체 권장',

    // 금액 산정
    'pricing_section' => '금액 산정',
    'car_cost_label' => '차값 (:symbol)',
    'car_cost_placeholder' => '13000000',
    'discount_rate_label' => '할인율 (%)',
    'sales_fee_label' => '＋ 매도비 (고정)',
    'car_price_label' => '차량금액 (Car Price)',
    'shipping_label' => '배송금액 (USD 고정)',
    'shipping_line' => '배송 :amount',
    'total_label' => '최종금액 (Total)',
    'shipping_rate_note' => '배송 $:usd × :rate원 적용',

    // 바이어 전달
    'forward_section' => '바이어에게 전달',
    'buyer_name_placeholder' => '바이어명 (respond.io 연락처)',
    'forward_button' => '사진 + 최종금액 바이어에게 전달',
    'forward_button_selected' => '— 선택됨 ✓',
    'forward_hint' => '선택 후 아래 :save를 눌러야 전달됩니다. 전달 후 바이어 회신은 :verdicts 화면에서 처리합니다.',
    'forward_hint_save' => '저장',
    'forward_hint_verdicts' => '"바이어 회신"',

    // (가) 가드: 같은 바이어 자동 회신대기 충돌
    'conflict_title' => '이 바이어는 이미 :vehicle가 있습니다.',
    'conflict_auto_word' => '자동 회신대기 차(:vehicle)',
    'conflict_desc' => '자동 회신은 한 번에 1대만 됩니다. 어떻게 할까요?',
    'conflict_wait' => '앞 차 처리 후 진행 (대기)',
    'conflict_manual' => '수동으로 전환해 전달',
    'conflict_manual_note' => '※ "수동 전환" 시 이 차는 바이어 회신 화면에서 직접 처리합니다.',

    'already_forwarded' => '이미 바이어에게 전달됨(회신대기). 수락/거절은 :verdicts 화면에서 처리하세요.',
    'already_forwarded_verdicts' => '"바이어 회신"',

    // 검증/플래시 메시지
    'attr_region' => '지역',
    'attr_assignee' => '담당자',
    'attr_buyer_name' => '바이어명',
    'need_amount_to_forward' => '차값(또는 최종금액)을 입력해야 검차완료할 수 있습니다.',
    'saved_ok' => '저장되었습니다.',
    'inspected_ok' => '검차완료 처리했습니다. 영업이 "전달 대기" 화면에서 바이어에게 전달합니다.',
    'complete_section' => '검차완료',
    'complete_button' => '검차완료 (전달 대기로)',
    'complete_button_selected' => '— 선택됨 ✓',
    'complete_hint' => '사진·금액 입력 후 검차완료를 선택하고 저장하세요. 바이어 전달은 영업이 "전달 대기" 화면에서 합니다.',
    'already_inspected' => '검차완료됨. 영업의 "전달 대기" 목록에 있습니다. (금액·사진은 계속 수정 가능)',
    'already_forwarded_simple' => '이미 바이어에게 전달됨(회신대기 이후). 영업 화면에서 처리됩니다.',
    'forwarded_manual' => '바이어에게 전달했습니다 (수동 회신 — 바이어 회신 화면에서 처리).',
    'forwarded_auto' => '바이어에게 전달했습니다 (자동 회신대기 — respond.io 회신 시 자동 처리).',
    'forward_held' => '전달을 보류했습니다. 앞 차 회신 처리 후 다시 전달하세요. (입력값은 저장됨)',
];

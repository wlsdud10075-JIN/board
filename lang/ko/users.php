<?php

// 사용자 관리 화면(/users · super 전용).

return [
    'title' => '사용자 관리',
    'subtitle' => '시스템관리자(super) 전용. 계정 생성·역할·시스템관리자 지정·활성여부. 비활성 계정은 업무화면 접근이 차단됩니다.',
    'add_user' => '사용자 추가',
    'edit_user' => '사용자 수정',

    // 테이블 헤더
    'col_name' => '이름',
    'col_email' => '이메일',
    'col_perm_role' => '권한 / 역할',
    'col_car_erp_match' => 'car-erp 영업 매칭',
    'col_status' => '상태',

    // 행
    'me' => '나',
    'status_active' => '활성',
    'status_inactive' => '비활성',
    'action_deactivate' => '비활성화',
    'action_activate' => '활성화',

    // 폼
    'label_name' => '이름',
    'ph_name' => '홍길동',
    'label_email' => '이메일 (로그인 ID)',
    'ph_email' => 'user@board.test',
    'label_phone' => '휴대폰 (알림톡 수신)',
    'ph_phone' => '010-1234-5678',
    'label_region' => '담당 지역',
    'region_hint' => '지역 검차 알림톡 수신 기준 (고정 로스터)',
    'ph_region' => '예: 경기 화성',
    'label_role' => '역할',
    'label_car_erp_email' => 'car-erp 영업 이메일',
    'optional_only_if_different' => '(선택 · 로그인과 다를 때만)',
    'ph_car_erp_email' => 'car-erp 영업담당자 이메일',
    'hint_car_erp_email' => '연동 B는 <b>이메일로 car-erp 영업담당자를 자동 매칭</b>합니다. <b>위 로그인 이메일 = car-erp 영업 이메일이면 비워두세요</b>(자동 매칭). 로그인 이메일이 다를 때만 여기에 car-erp 영업 이메일을 적으면 그걸로 매칭합니다.',
    'label_respond_email' => 'respond.io 상담원 이메일',
    'ph_respond_email' => 'respond.io 상담원 이메일',
    'hint_respond_email' => '연동 A <b>승격 대기</b>는 respond.io 대화 담당 상담원에게만 보입니다. <b>로그인 이메일 = respond.io 상담원 이메일이면 비워두세요</b>(자동 매칭). 다를 때만 여기에 적으면 그걸로 라우팅합니다.',
    'label_password' => '비밀번호',
    'label_password_edit_suffix' => '(변경 시에만 입력)',
    'ph_password_keep' => '비워두면 기존 유지',
    'ph_password_new' => '6자 이상',
    'super_checkbox' => '시스템관리자',
    'super_checkbox_desc' => '(전체 접근 + 사용자관리)',
    'active_checkbox' => '활성 계정 (로그인 허용)',

    // flash / 검증 메시지
    'saved' => '저장되었습니다.',
    'err_cannot_deactivate_self' => '본인 계정은 비활성화할 수 없습니다.',
    'err_cannot_remove_own_super' => '본인의 시스템관리자 권한은 해제할 수 없습니다.',
];

<?php

// 알림톡 안내(2026-11-23) — 알림마다 자동 수신자 + 추가로 받을 사람(역할·인원별 체크). 규칙 = App\Support\AlimtalkRecipients.
return [
    'title' => '알림톡 안내',
    'intro' => '알림톡마다 체크된 사람이 받습니다. 파란 줄은 그 건의 당사자(지금까지 자동으로 받던 사람)이고, 아래 역할 줄에서 역할째 또는 사람별로 넣고 뺄 수 있습니다.',
    'master_off' => '알림톡 전체 스위치가 꺼져 있습니다 — 기능설정에서 켜야 실제로 나갑니다.',

    'state_on' => '발송 중',
    'state_off' => '꺼짐',
    'state_off_hint' => '기능설정에서 전체 스위치·이 알림 스위치·템플릿 코드가 모두 있어야 나갑니다.',
    'show_body' => '본문 보기',
    'same_template' => '「:name」와 같은 본문으로 나갑니다.',

    'recipients_label' => '받는 사람 (체크한 사람만)',
    'ctx_hint' => '건마다 해당하는 사람',
    'not_pickable' => '받는 사람마다 처리 버튼(본인 전용 링크)이 붙는 알림이라 받는 사람을 바꿀 수 없습니다.',

    'group_all' => '전체 :n명 (나중에 들어온 사람도 받음)',
    'group_some' => ':n명만 지정',
    'group_none' => '안 받음 · :n명',
    'group_empty' => '이 역할에 사람이 없습니다',
    'no_phone' => '번호 없음',
    'no_phone_hint' => '사용자 관리에 휴대폰번호가 없어 체크해도 받지 못합니다.',

    'save' => '저장',
    'saved' => '저장했습니다',
    'unsaved' => '저장 전 — [저장]을 눌러야 적용됩니다',
    'extra_count' => '역할·사람으로 체크돼 받는 사람 :n명 (번호 있는 사람 기준 · 파란 줄 당사자는 건마다 달라 세지 않음)',
    'footnote' => '역할·사람으로 체크한 사람은 그 알림을 건마다 전부 받습니다(담당 차량만 가려 보내지 않습니다). 같은 사람이 여러 줄에 걸려도 한 통만 갑니다. 체크를 꺼도 화면 알림(종·웹푸시)은 그대로 갑니다. 비활성 계정은 자동으로 빠지고, 변경은 감사 로그에 남습니다.',

    'notice' => [
        'board_region_inspection' => '지역 검차 안내',
        'board_forward_ready' => '전달 대기 안내',
        'board_claim_approval_request' => '클레임 결재요청',
        'board_claim_decision' => '클레임 결재 결과',
        'board_claim_erp_sent' => '클레임 ERP 전송 완료',
        'board_transport_request' => '탁송 배차 요청',
        'board_transport_result' => '탁송 배차 결과',
    ],

    'when' => [
        'board_region_inspection' => '지역별 검차 대상 차량 목록을 정해진 시각에 보냅니다.',
        'board_forward_ready' => '검차 사진·영상이 올라와 매물이 전달 대기로 넘어갈 때 보냅니다.',
        'board_claim_approval_request' => '클레임 결재 차례가 온 결재자에게 승인/반려 버튼과 함께 보냅니다.',
        'board_claim_decision' => '클레임이 최종 승인·반려되거나 자체승인될 때 보냅니다.',
        'board_claim_erp_sent' => '담당자가 [ERP 로 보내기]를 눌러 ERP 반영에 성공했을 때, 결재 결과를 한 번 더 보냅니다. 실패하면 보내지 않습니다.',
        'board_transport_request' => '탁송 배차를 요청할 때 배차 확인 버튼과 함께 보냅니다.',
        'board_transport_result' => '탁송 업체가 배차완료·배차거절을 누르면 보냅니다.',
    ],

    'fixed' => [
        'claim_approval_request' => '지금 차례 결재자 1명',
        'transport_request' => '탁송 업체 번호 + 직접 적은 번호 + 요청 영업 + 담당 관리',
    ],

    // 그 건의 당사자 줄 — 지금까지 자동으로 받던 사람들(기본 체크)
    'ctx' => [
        'inspector' => '그 지역에 배정된 검차원',
        'creator' => '그 매물을 등록한 영업',
        'handler' => '그 클레임 담당자',
        'claim_sales' => '그 클레임 접수 영업',
        'approval_line' => '그 클레임 결재선 전원',
        'transport_sales' => '탁송을 요청한 영업',
        'transport_manager' => '그 영업의 담당 관리',
    ],
    'ctx_short' => [
        'ctx_inspector' => '배정 검차원',
        'ctx_creator' => '등록 영업',
        'ctx_handler' => '담당자',
        'ctx_sales' => '영업(당사자)',
        'ctx_manager' => '담당 관리',
        'approval_line' => '결재선 전원',
    ],

    'group' => [
        'super' => '시스템관리자',
        'admin' => '최고관리자',
        'manager' => '업무관리자',
        'role_manager' => '관리',
        'role_sales' => '영업',
        'role_inspection' => '현지확인',
        'role_auction' => '경매',
    ],
];

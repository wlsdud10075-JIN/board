<?php

return [
    'group' => [
        'work' => '업무',
        'manage' => '관리',
        'system' => '시스템',
    ],

    'menu' => [
        'listings' => '매입예정 (영업)',
        'forwarding' => '전달 대기',
        'verdicts' => '바이어 회신',
        'portal' => '내 정산·미수 (포털)',
        'inspection' => '현지확인',
        'auction' => '경매/구매',
        'manage' => '관리자',
        'users' => '사용자 관리',
        'audit' => '감사 로그',
        'settings' => '기능설정',
    ],

    'crumb' => [
        'dashboard' => '대시보드',
        'listings' => '매입예정',
        'forwarding' => '전달 대기',
        'verdicts' => '바이어 회신',
        'portal' => '내 정산·미수·선적',
        'inspection' => '현지확인',
        'auction' => '경매/구매',
        'manage' => '관리자',
        'users' => '사용자 관리',
        'audit' => '감사 로그',
        'settings_profile' => '내 설정',
        'settings_password' => '비밀번호',
        'settings_appearance' => '화면 설정',
        'admin_settings' => '기능설정',
    ],

    'role' => [
        'sales' => '영업',
        'inspection' => '현지확인',
        'auction' => '경매',
        'manager' => '관리',
    ],

    'perm' => [
        'super' => '시스템관리자',
    ],

    'lang' => [
        'label' => '언어',
        'ko' => '한국어',
        'en' => 'English',
    ],

    'action' => [
        'work_guide' => '업무 가이드',
        'my_settings' => '내 설정',
        'logout' => '로그아웃',
    ],

    'brand_sub' => '매입·검차·경매',

    // 상단 종 — 내가 보낸 요청(계약금·매입잔금·판매대금확인)의 결과(ERP 확인·거절)와 진행 중 목록 (2026-10-12 Jin)
    'bell' => [
        'title' => '요청 알림',
        'aria' => '요청 알림 열기',
        'results' => '결과',
        'in_progress' => '진행 중',
        'empty_results' => '아직 결과가 없습니다.',
        'empty_in_progress' => '진행 중인 요청이 없습니다.',
        'unavailable' => 'ERP 에서 요청 상태를 가져오지 못했습니다 — 비어 있어도 「요청 없음」이 아닙니다.',
        'new' => '새 결과',
        'resolved_by' => ':name 처리',
        'requested_at' => ':at 요청',
        'toast_rejected' => '🔔 요청 거절 :count건 — 종을 눌러 사유를 확인하세요',
        'toast_done' => '✅ 요청 확인 :count건',
        'footer' => '자세한 상태는 포털 재고·판매내역 탭에서 확인하세요.',
    ],
];

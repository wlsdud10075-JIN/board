<?php

// 알림톡 로그(2026-11-04 Jin, car-erp /admin/alimtalk-logs 미러) — 시스템관리자 전용

return [
    'title' => '알림톡 로그',
    'subtitle' => '카카오 알림톡(BizM) 발송 기록입니다. 「발송」은 BizM 접수까지이며 실제 도착 확인은 아닙니다. 실패 건은 확인한 뒤 [확인]을 눌러 주세요.',
    'search_ph' => '전화번호 · 받는 사람 · 템플릿 · 지역',
    'status_all' => '상태 전체',
    'status' => ['sent' => '발송', 'failed' => '실패', 'skipped' => '건너뜀'],
    'attention_only' => '주의 필요만(실패·미확인)',
    'count' => ':count건',
    'per_page' => ':count건씩',
    'col_time' => '시각',
    'col_template' => '템플릿',
    'col_to' => '받는 사람',
    'col_status' => '상태',
    'col_detail' => '상세',
    'ack' => '확인',
    'acked' => '확인함',
    'ack_all' => '실패 :n건 모두 확인',
    'ack_all_confirm' => '확인하지 않은 실패 :n건을 모두 확인 처리할까요? 기록은 지워지지 않습니다.',
    'empty' => '알림톡 발송 기록이 없습니다.',
    'reason' => [
        'unknown_template' => '모르는 템플릿 — 코드 확인 필요',
        'no_phone' => '받는 사람 휴대폰 번호 없음',
        'disabled_or_unconfigured' => '알림톡이 꺼져 있거나 설정(템플릿 코드·발신프로필)이 비어 있음',
        'unconfigured' => '알림톡 계정 설정이 비어 있음',
        'no_test_tmplid' => '테스트용 템플릿 코드가 비어 있음',
    ],
];

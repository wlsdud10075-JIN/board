<?php

return [
    // 위젯
    'title' => '업무 도우미',
    'hint' => '로컬 LLM · 매입보드 업무가이드',
    'open' => '업무 도우미 열기',
    'greeting' => '안녕하세요. 매입보드 업무 가이드를 물어보세요.',
    'example' => '예: "매입예정은 어떻게 등록해?", "전달 대기로 왜 안 넘어가?"',
    'placeholder' => '질문을 입력하세요…',
    'send' => '전송',
    'loading' => '찾는 중…',

    // 응답 문구
    'empty_question' => '질문을 입력해 주세요.',
    'no_index' => '업무 가이드 색인이 아직 준비되지 않았습니다. 관리자에게 문의해 주세요.',
    'no_answer' => '(응답 없음)',
    'error' => '업무 가이드 조회 중 오류가 발생했습니다. 잠시 후 다시 시도해 주세요.',

    // 기능설정
    'settings_title' => '사내 업무 도우미(챗봇)',
    'settings_intro' => '영업·관리·시스템관리자에게 업무 가이드 Q&A 챗봇을 노출합니다. 서버 설정(ASSISTANT_*)이 되어 있어야 실제로 동작합니다.',
    'settings_enabled' => '챗봇 사용',
    'settings_env_off' => '⚠️ 서버 설정(ASSISTANT_ENABLED)이 꺼져 있어 이 토글을 켜도 노출되지 않습니다.',
    'settings_index_missing' => '⚠️ 색인 파일을 찾을 수 없습니다 (ASSISTANT_INDEX_PATH).',
    'settings_index_ok' => '색인 :size MB · 갱신 :when',
];

<?php

/*
|--------------------------------------------------------------------------
| 사내 업무 도우미(로컬 LLM 챗봇) 설정 — board
|--------------------------------------------------------------------------
| car-erp assistant 의 A(업무가이드 RAG)만 이식. B(미수·자금 조회)는 board 에
| 그 데이터가 없어 해당 없음. 완전 로컬(Ollama) — 데이터 외부 전송 0.
|
| 전부 .env 기반(머신 특정값 하드코딩 없음):
|   - ASSISTANT_ENABLED     인프라 준비 여부(GPU 박스 도달 가능). 운영 on/off 는
|                           기능설정(Setting `assistant_enabled`)에서 super 가 토글.
|   - ASSISTANT_OLLAMA_URL  Ollama 엔드포인트 (사설터널 주소)
|   - ASSISTANT_INDEX_PATH  ⚠️ **board 전용 색인**(index-board.json) 절대경로.
|                           통합 index.json 을 가리키면 ERP 가이드가 섞인다.
|                           색인은 회사 GPU PC 가 03:00 에 생성 → scp 로 배포
|                           (meetings/handoff-assistant-index-push.md).
*/

return [
    'enabled' => filter_var(env('ASSISTANT_ENABLED', false), FILTER_VALIDATE_BOOL),

    'ollama' => rtrim(env('ASSISTANT_OLLAMA_URL', 'http://localhost:11434'), '/'),
    'llm_model' => env('ASSISTANT_LLM_MODEL', 'qwen3:8b'),
    'emb_model' => env('ASSISTANT_EMB_MODEL', 'bge-m3'),

    'index_path' => env('ASSISTANT_INDEX_PATH', ''),
    'rag_topk' => (int) env('ASSISTANT_RAG_TOPK', 3),

    // 색인 신선도 경고 기준(일) — board:assistant-health 가 mtime 으로 판정.
    // scp 실패가 조용히 넘어가면 옛 색인으로 계속 답하므로 여기서 잡는다.
    'index_max_age_days' => (int) env('ASSISTANT_INDEX_MAX_AGE_DAYS', 3),

    'timeout' => (int) env('ASSISTANT_TIMEOUT', 180),
];

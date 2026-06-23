<?php

// 바이어 회신(연동 A) 화면 — 회신대기 차를 바이어별로 묶어 수락/거절.

return [
    'title' => '바이어 회신',
    'subtitle' => '회신대기 차량을 바이어별로 묶어 표시 · 차마다 :accept/:reject을 처리하세요 (한 바이어가 여러 대 검토 가능)',

    'buyer_unassigned' => '바이어 미지정',
    'count_awaiting' => ':count대 회신대기',
    'contact' => '컨택트',

    'th_vehicle' => '차량',
    'th_origin' => '출처',
    'th_final_price' => '최종금액',
    'th_inspection_note' => '추가검사사항',
    'th_process' => '회신 처리',

    'owner_empty' => '소유자 —',

    'accept' => '수락',
    'reject' => '거절',

    'confirm_accept' => ':vehicle — 바이어 수락으로 처리할까요? (구매/경매 대기로 이동)',
    'confirm_reject' => ':vehicle — 바이어 거절로 처리할까요?',

    'flash_accepted_note' => '수락 (구매/경매 대기로 이동)',
    'flash_rejected_note' => '거절',
    'flash_processed' => ':vehicle → :verdict 처리됨',
    'flash_already' => ':vehicle 은 이미 처리되었습니다.',

    'empty' => '회신대기 중인 차량이 없습니다. (현지확인에서 바이어에게 전달되면 여기 표시됩니다.)',
];

<?php

// 전달 대기 — 검차완료 차를 영업이 바이어에게 전달

return [
    'title' => '전달 대기',
    'subtitle' => '🔍 검차완료된 차를 사진 확인 후 바이어에게 전달합니다. 전달하면 "바이어 회신"으로 넘어갑니다.',
    'panel_title' => '검차완료 · 전달 대기',
    'count' => ':count대 대기',
    'empty' => '전달 대기 차량이 없습니다. (검차완료된 차가 여기 표시됩니다)',

    'th_vehicle' => '차량',
    'th_origin' => '출처',
    'th_final_price' => '최종금액',
    'th_inspection_note' => '추가검사사항',

    'forward_section' => '바이어 전달',
    'buyer_placeholder' => '바이어명 (respond.io 연락처)',
    'attr_buyer_name' => '바이어명',
    'forward_button' => '바이어에게 전달',
    'forward_hint' => '전달하면 사진+최종금액이 바이어에게 가고(자동채널) "바이어 회신" 화면으로 넘어갑니다.',
    'flash_forwarded' => ':vehicle 를 바이어에게 전달했습니다. (바이어 회신 화면에서 수락/거절 처리)',

    'conflict_title' => '같은 바이어에게 이미 자동 회신대기 차(:vehicle)가 있습니다.',
    'conflict_desc' => '자동 회신은 한 바이어당 1대씩 처리됩니다. 이 차는 수동으로 전달할 수 있습니다.',
    'conflict_manual' => '수동으로 전달',
];

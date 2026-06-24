<?php

// 전달 대기 — 검차완료 차를 영업이 바이어에게 전달

return [
    'title' => '전달 대기',
    'subtitle' => '🔍 검차완료 차의 사진을 받아(다운로드/공유) 바이어에게 보낸 뒤, "전달 완료"를 누르면 "바이어 회신"으로 넘어갑니다.',
    'panel_title' => '검차완료 · 전달 대기',
    'count' => ':count대 대기',
    'empty' => '전달 대기 차량이 없습니다. (검차완료된 차가 여기 표시됩니다)',

    'th_vehicle' => '차량',
    'th_origin' => '출처',
    'th_final_price' => '최종금액',
    'th_inspection_note' => '추가검사사항',

    // 진행 뱃지 (검차완료 → 사진확보)
    'badge_inspected' => '검차완료',
    'badge_no_photos' => '사진 없음',

    // 견적 카드 — 바이어에게 보낼 통화/금액
    'quote_section' => '견적 통화',
    'quote_car' => '차량금액',
    'quote_shipping' => '배송',
    'quote_total' => '최종',
    'quote_unset' => '금액 미설정 — 가격 협의 중(카드 없이 사진만 전송).',

    // 사진 확보 — 외부 메신저로 보내기
    'share_button' => '사진 전체 공유 :count장',
    'share_hint' => '검차사진 전체를 한 번에 카톡/왓츠앱 등으로 보냅니다. 한 장만 보내려면 사진을 탭 → 길게 누르세요.',
    'share_hint_quote' => '견적 카드 1장 + 검차사진을 한 번에 카톡/왓츠앱 등으로 보냅니다. 통화는 위 토글에서 바꾸세요.',

    'forward_section' => '바이어 전달',
    'buyer_placeholder' => '바이어명 (선택)',
    'attr_buyer_name' => '바이어명',
    'forward_button' => '전달 완료',
    'forward_hint' => '바이어에게 보냈으면 누르세요. (자동채널 연결 시 사진+최종금액도 함께 발송) → "바이어 회신" 화면으로.',
    'flash_forwarded' => ':vehicle 를 바이어에게 전달했습니다. (바이어 회신 화면에서 수락/거절 처리)',

    // 인앱 알림 (검차완료 도착)
    'notify' => '🔔 검차완료 :count대 — 전달 대기',
    'notify_synced' => 'car-erp 전송 완료 :count건 — ERP전환완료',

    'conflict_title' => '같은 바이어에게 이미 자동 회신대기 차(:vehicle)가 있습니다.',
    'conflict_desc' => '자동 회신은 한 바이어당 1대씩 처리됩니다. 이 차는 수동으로 전달할 수 있습니다.',
    'conflict_manual' => '수동으로 전달',
];

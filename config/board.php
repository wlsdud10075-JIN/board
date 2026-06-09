<?php

return [
    // 경매 차량 등록 마감 시각 (KST). 엔카는 상시(잠금 없음). 주말은 잠금 미적용.
    'auction_lock_time' => env('BOARD_AUCTION_LOCK_TIME', '10:00'),

    // S3 검차 사진 prefix (외관만 — 서류/번호판 제외)
    'inspection_photo_prefix' => 'purchase-board/inspections/vehicle-photos',

    // 사진 저장 디스크 — 로컬은 public, 운영은 s3 (FILESYSTEM 분리). 운영 전환 시 .env BOARD_PHOTO_DISK=s3
    'photo_disk' => env('BOARD_PHOTO_DISK', 'public'),
];

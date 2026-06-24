<?php

// 도메인 공통 라벨 — 상태/회신/출처. 모델(PurchaseListing) 라벨 메서드 + 드롭다운/필터가 공유.

return [
    // 정적 라벨(출처 무관 통합) — 드롭다운/필터/감사로그
    'status' => [
        'draft' => '현지확인 대기',
        'inspected' => '검차완료 (전달대기)',
        'awaiting_buyer' => '회신대기',
        'accepted' => '수락 (구매/경매대기)',
        'rejected' => '거절',
        'won' => '낙찰/구매확정',
        'failed' => '유찰/취소',
        'synced' => 'ERP 전환완료',
    ],

    // 표시용 라벨 — statusLabel() 의 출처(경매/엔카)별 분기
    'status_live' => [
        'draft' => '현지확인 대기',
        'inspected' => '검차완료 (전달대기)',
        'awaiting_buyer' => '회신대기',
        'accepted_auction' => '경매대기',
        'accepted_encar' => '구매대기',
        'rejected' => '거절',
        'won_auction' => '낙찰',
        'won_encar' => '구매확정',
        'failed_auction' => '유찰',
        'failed_encar' => '취소',
        'synced' => 'ERP 전환완료',
    ],

    'verdict' => [
        'pending' => '회신대기',
        'accepted' => '수락',
        'rejected' => '거절',
    ],

    'origin' => [
        'ssancar_auction' => '싼카-경매',
        'ssancar_stock' => '싼카-재고',
        'ssancar_checking' => '싼카-체킹',
        'encar' => '엔카',
        'auction' => '경매',
    ],

    'source' => [
        'encar' => '엔카',
        'auction' => '경매',
    ],
];

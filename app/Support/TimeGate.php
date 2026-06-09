<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * 경매 차량 등록 시간잠금 — 서버시각 단일 판정 (KST). 엔카는 상시.
 * 주말은 lock_at=NULL (잠금 미적용). 관리자는 우회.
 */
class TimeGate
{
    /** 해당 일자의 경매 등록 마감 시각. 주말이면 null. */
    public static function auctionLockAt(?Carbon $day = null): ?Carbon
    {
        $day = ($day ?? now())->copy();

        if ($day->isWeekend()) {
            return null;
        }

        [$h, $m] = array_pad(explode(':', (string) config('board.auction_lock_time', '10:00')), 2, '0');

        return $day->setTime((int) $h, (int) $m, 0);
    }

    /** 지금 경매 신규 등록이 잠겼는지 (마감 시각 지남). */
    public static function auctionRegistrationLocked(): bool
    {
        $lock = self::auctionLockAt();

        return $lock !== null && now()->greaterThanOrEqualTo($lock);
    }
}

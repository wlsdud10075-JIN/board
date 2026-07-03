<?php

namespace App\Services;

use App\Models\ExchangeRate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 환율 조회/캐시 (§6a). 소스 = **car-erp `/rates`**(네이버 전신환 매입률 원본, 반올림 X = car-erp 값과 일치).
 * 실패 시 마지막 캐시값 → config 폴백 순으로 항상 값을 보장한다(car-erp 불통도 board 안 깨짐).
 * (종전 Frankfurter/ECB 폐기 — 소스 달라 환율 어긋난 원인. refresh() 참고.)
 *
 * 차량금액은 KRW 원장, 배송금액은 USD 원장 → 표시통화로 변환 시 이 환율 사용.
 */
class ExchangeRateService
{
    public const SUPPORTED = ['USD', 'EUR'];

    /** 1단위(1 USD/EUR)당 원화. 캐시 없으면 config 폴백. */
    public function krwPer(string $currency): int
    {
        $row = ExchangeRate::where('currency', $currency)->first();
        if ($row) {
            return (int) round((float) $row->krw_per_unit);
        }

        return $this->fallback($currency);
    }

    public function krwPerUsd(): int
    {
        return $this->krwPer('USD');
    }

    public function krwPerEur(): int
    {
        return $this->krwPer('EUR');
    }

    /** 표시용 환율맵 + 마지막 조회 시각. */
    public function snapshot(): array
    {
        $rows = ExchangeRate::whereIn('currency', self::SUPPORTED)->get()->keyBy('currency');

        return [
            'USD' => $this->krwPerUsd(),   // 계산용 int(반올림)
            'EUR' => $this->krwPerEur(),
            // 표시용 2자리 문자열 — car-erp `number_format(rate, 2)` 와 동일(같은 소스·같은 반올림 → 같은 값).
            'USD_display' => $this->displayRate('USD', $rows),
            'EUR_display' => $this->displayRate('EUR', $rows),
            'fetched_at' => optional($rows->max('fetched_at'))?->format('Y-m-d H:i') ?? null,
            'is_live' => $rows->isNotEmpty(),
        ];
    }

    /** 표시용 환율 문자열(소수 2자리) — car-erp 표시(number_format(rate,2))와 일치. 캐시 없으면 config 폴백. */
    private function displayRate(string $currency, $rows): string
    {
        $row = $rows[$currency] ?? null;
        $val = $row ? (float) $row->krw_per_unit : (float) $this->fallback($currency);

        return number_format($val, 2);
    }

    private function fallback(string $currency): int
    {
        return $currency === 'EUR'
            ? (int) config('board.default_krw_per_eur')
            : (int) config('board.default_krw_per_usd');
    }

    /** 캐시가 TTL(config rate_ttl_hours) 보다 오래됐거나 없으면 stale. */
    public function isStale(): bool
    {
        $latest = ExchangeRate::whereNotNull('fetched_at')->max('fetched_at');
        if (! $latest) {
            return true;
        }

        return Carbon::parse($latest)->lt(now()->subHours((int) config('board.rate_ttl_hours')));
    }

    /**
     * 화면 진입 시 호출 — stale 일 때만 갱신(lazy). cron 없이도 신선도 유지.
     * 실패 재시도 폭주 방지: 10분에 1회만 시도(성공/실패 무관).
     */
    public function refreshIfStale(): void
    {
        if (! config('board.rate_auto_refresh')) {
            return;
        }
        if (! $this->isStale()) {
            return;
        }
        if (Cache::get('exchange_rate_attempt_at')) {
            return;
        }
        Cache::put('exchange_rate_attempt_at', now()->toDateTimeString(), now()->addMinutes(10));
        $this->refresh();
    }

    /**
     * 라이브 조회 후 캐시 갱신 — 소스 = **car-erp `/rates`**(네이버 전신환 매입률 원본, ⚠️반올림 X = car-erp와 값 일치).
     * 실패/미설정 시 아무것도 갱신 안 함 → 폴백 체인(마지막 캐시값 → config default) 유지, board 안 깨짐. 반환 = 갱신 통화맵.
     * (종전 Frankfurter/ECB 직접호출 폐기 — 소스 불일치로 car-erp 와 환율 달랐던 원인. 인계=handoff-car-erp-exchange-rate.)
     */
    public function refresh(): array
    {
        $updated = [];
        $resp = app(CarErpReadService::class)->rates();
        if (($resp['ok'] ?? false) !== true) {
            Log::warning('환율 조회 실패(car-erp /rates): '.($resp['reason'] ?? 'unknown'));

            return $updated;
        }

        $rates = $resp['data']['rates'] ?? [];
        foreach (self::SUPPORTED as $currency) {
            $rate = $rates[$currency] ?? null;
            if ($rate !== null && (float) $rate > 0) {
                ExchangeRate::updateOrCreate(
                    ['currency' => $currency],
                    ['krw_per_unit' => $rate, 'fetched_at' => now()],
                );
                $updated[$currency] = (float) $rate;
            }
        }

        return $updated;
    }
}

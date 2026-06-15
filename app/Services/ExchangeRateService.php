<?php

namespace App\Services;

use App\Models\ExchangeRate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 환율 조회/캐시 (§6a). 기본 소스 = Frankfurter(키 불필요, ECB 기준).
 * 실패 시 마지막 캐시값 → config 폴백 순으로 항상 값을 보장한다.
 * 소스 교체(네이버/다음 등)는 config('board.rate_api_base') + fetch() 파서만 수정.
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
            'USD' => $this->krwPerUsd(),
            'EUR' => $this->krwPerEur(),
            'fetched_at' => optional($rows->max('fetched_at'))?->format('Y-m-d H:i') ?? null,
            'is_live' => $rows->isNotEmpty(),
        ];
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

    /** 라이브 조회 후 캐시 갱신. 통화별로 독립 실패 허용(부분 성공). 반환 = 갱신된 통화맵. */
    public function refresh(): array
    {
        $updated = [];
        foreach (self::SUPPORTED as $currency) {
            try {
                $rate = $this->fetch($currency);
                if ($rate !== null && $rate > 0) {
                    ExchangeRate::updateOrCreate(
                        ['currency' => $currency],
                        ['krw_per_unit' => $rate, 'fetched_at' => now()],
                    );
                    $updated[$currency] = $rate;
                }
            } catch (\Throwable $e) {
                Log::warning("환율 조회 실패({$currency}): ".$e->getMessage());
            }
        }

        return $updated;
    }

    /**
     * 환율 조회 (1 {currency} = ? KRW). 실패/형식오류 시 null(상위에서 폴백 유지).
     * 기본 소스 = Frankfurter(키 불필요, ECB 기준). config('board.rate_api_base') 로 교체 가능.
     */
    protected function fetch(string $currency): ?float
    {
        $base = rtrim((string) config('board.rate_api_base'), '/');
        $res = Http::timeout(8)->get("{$base}/latest", ['from' => $currency, 'to' => 'KRW']);

        if (! $res->ok()) {
            return null;
        }

        $rate = $res->json('rates.KRW');

        return ($rate && (float) $rate > 0) ? (float) $rate : null;
    }
}

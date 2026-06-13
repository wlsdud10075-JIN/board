<?php

namespace App\Services;

use App\Models\ExchangeRate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 환율 조회/캐시 (§6a). 네이버 마켓인덱스 JSON 을 1차 소스로,
 * 실패 시 마지막 캐시값 → config 폴백 순으로 항상 값을 보장한다.
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

    /** 네이버 마켓인덱스 JSON — 실패/형식오류 시 null(상위에서 폴백 유지). */
    protected function fetch(string $currency): ?float
    {
        $code = 'FX_'.$currency.'KRW';
        $res = Http::timeout(8)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0'])
            ->get("https://api.stock.naver.com/marketindex/exchange/{$code}/basic");

        if (! $res->ok()) {
            return null;
        }

        // closePrice 예: "1,380.50"
        $raw = $res->json('closePrice');
        if (! $raw) {
            return null;
        }

        $value = (float) str_replace(',', '', (string) $raw);

        return $value > 0 ? $value : null;
    }
}

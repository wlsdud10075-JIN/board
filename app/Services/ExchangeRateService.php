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

    public const CLOSING_CACHE_KEY = 'exchange_rate_closing';

    /**
     * 마감환율(2026-11-27 Jin) — ERP `/rates` 의 `closing` 블록을 받은 그대로(캐시). **보여 주기 전용**:
     * krwPer()·exchange_rates 행(계산·ERP 전송이 쓰는 값)과 섞지 않는다. ERP 가 안 주면 null → 화면은 마감 줄을 안 그린다.
     * 날짜·「아직 없음」(stale)도 ERP 값 그대로 — board 는 「직전 영업일」을 계산하지 않는다.
     * 로컬 미리 보기 = config board.fake_closing_rates(JSON 파일, **local 전용**).
     *
     * @return array{date: ?string, stale: bool, receive: array<string, ?float>, base: array<string, ?float>}|null
     */
    public function closing(): ?array
    {
        $raw = Cache::get(self::CLOSING_CACHE_KEY);
        if (app()->environment('local') && ($fake = config('board.fake_closing_rates')) && is_file(base_path($fake))) {
            $raw = json_decode((string) file_get_contents(base_path($fake)), true);
        }

        return $this->normalizeClosing($raw);
    }

    /**
     * 과거 날짜의 마감환율(2026-11-27 Jin «일자별로 과거 것도») — ERP `/rates?closing_date=` 를 그날 처음 볼 때 한 번 묻고 보관한다
     * (지난 마감은 안 바뀐다 → 7일). ERP 가 못 주면 null(1분만 기억 — 화면을 그릴 때마다 다시 묻지 않게, ERP 호출 한도는 분당 120 공유).
     * 주말·공휴일을 고르면 ERP 가 직전 영업일 값을 주고 `date` 에 그 실제 날짜가 온다. 오늘·미래·형식 오류는 null.
     */
    public function closingOn(string $date): ?array
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date >= now()->toDateString()) {
            return null;
        }
        if (app()->environment('local') && config('board.fake_closing_rates')) {
            // 가짜 파일의 `history`(날짜 → 블록)에서 그날 이전 가장 가까운 날 — ERP 의 「직전 영업일」 흉내. 없으면 null(「받지 못했습니다」).
            $file = base_path((string) config('board.fake_closing_rates'));
            $hist = is_file($file) ? (json_decode((string) file_get_contents($file), true)['history'] ?? []) : [];
            krsort($hist);
            foreach ($hist as $d => $block) {
                if ($d <= $date) {
                    return $this->normalizeClosing(['date' => $d, 'stale' => false] + (array) $block);
                }
            }

            return null;
        }
        $key = self::CLOSING_CACHE_KEY.':'.$date;
        $hit = Cache::get($key);
        if ($hit === null) {
            $resp = app(CarErpReadService::class)->rates($date);
            $block = ($resp['ok'] ?? false) === true ? ($resp['data']['closing'] ?? null) : null;
            // 날짜가 없는 블록 = 옛 ERP 가 인자를 무시하고 준 최신 마감일 수 있다 — 요청한 날 이후 날짜면 버린다.
            $ok = is_array($block) && is_string($block['date'] ?? null) && $block['date'] <= $date;
            $hit = $ok ? $block : 'none';
            Cache::put($key, $hit, $ok ? now()->addDays(7) : now()->addMinute());
        }

        return is_array($hit) ? $this->normalizeClosing($hit) : null;
    }

    private function normalizeClosing(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }
        $pick = function (string $kind) use ($raw): array {
            $out = [];
            foreach (self::SUPPORTED as $cur) {
                $v = $raw[$kind][$cur] ?? null;
                $out[$cur] = is_numeric($v) && (float) $v > 0 ? (float) $v : null;
            }

            return $out;
        };
        $date = isset($raw['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $raw['date']) ? (string) $raw['date'] : null;

        return ['date' => $date, 'stale' => (bool) ($raw['stale'] ?? false), 'receive' => $pick('receive'), 'base' => $pick('base')];
    }

    /** 화면 환율 줄(`livewire._rate-strip`) 한 벌 — 실시간(snapshot) + 마감. */
    public function strip(string $date = ''): array
    {
        if ($date === '') {
            return $this->snapshot() + ['closing' => $this->closing(), 'picked' => null, 'missing' => false];
        }
        $cl = $this->closingOn($date);
        $none = array_fill_keys(self::SUPPORTED, null);

        return $this->snapshot() + [
            'closing' => $cl ?? ['date' => null, 'stale' => false, 'receive' => $none, 'base' => $none],
            'picked' => $date,
            'missing' => $cl === null,
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

        // 마감환율 블록 — 있으면 그대로 보관(표시 전용). 없는 응답(옛 ERP)이면 지난 값을 그대로 둔다.
        if (is_array($resp['data']['closing'] ?? null)) {
            Cache::forever(self::CLOSING_CACHE_KEY, $resp['data']['closing']);
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

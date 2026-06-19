<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * 매물 자동채움(enrichment) — encar JSON API + ssancar 페이지 파싱 → 차량번호·차값·지역·VIN.
 *
 * 권위 = meetings/encar-ssancar-enrichment-design.md. 전부 board PHP(외부 스크래퍼 불필요).
 * 실패/미해당이면 [] (prefill 없음, 절대 throw 안 함).
 *
 * 반환: {vehicle_number?, region?, vin?, prices?:{KRW?,USD?,EUR?}}.
 *  - 차값은 **통화별 금액 맵 `prices`** — 영업이 통화 토글하면 해당 금액으로 바뀜.
 *  - encar = KRW 1종. ssancar 페이지 = `<p class="money">₩/$/€` 3종.
 */
class ListingEnrichment
{
    /** ListingLink::parse 결과(+ 원본 URL)로 enrich. encar_id=API / ssancar=페이지 파싱. */
    public function enrich(array $parsed, string $url = ''): array
    {
        if (! empty($parsed['encar_id'])) {
            return $this->byEncarId((string) $parsed['encar_id']);
        }
        if ($url !== '' && str_contains(mb_strtolower($url), 'ssancar.com')
            && (! empty($parsed['c_no']) || ! empty($parsed['ssancar_ref']))) {
            return $this->fromSsancar($url);
        }

        return [];
    }

    /**
     * ssancar 페이지 파싱.
     *  - inspected(검차): 원본 encar 링크 → encar API 로 차량번호·지역·VIN. ⭐
     *  - stock(재고): VIN(<em id="copy_txt">) + 차량번호(번호판 패턴).
     *  - 차값 = 페이지 `<p class="money">` 의 ₩/$/€ 3종(stock·inspected 공통). 없으면 USD 텍스트 폴백.
     */
    public function fromSsancar(string $url): array
    {
        try {
            $res = Http::timeout(8)->get($url);
        } catch (\Throwable) {
            return [];
        }
        if ($res->failed()) {
            return [];
        }

        $html = $res->body();
        $out = [];

        // 차량번호·지역·VIN
        if (preg_match('#encar\.com/cars/detail/(\d+)#i', $html, $m)) {
            $out = $this->byEncarId($m[1]);   // 검차매물 = encar 우회(차량번호·지역·VIN·KRW 가격)
        } else {
            if (preg_match('/id=["\']copy_txt["\'][^>]*>\s*([^<\s][^<]*?)\s*</u', $html, $m)) {
                $out['vin'] = trim($m[1]);
            }
            if (preg_match('/(\d{2,3}\s?[가-힣]\s?\d{4})/u', $html, $m)) {
                $out['vehicle_number'] = preg_replace('/\s+/u', '', $m[1]);
            }
        }

        // 차값 = money 블록 3통화
        $prices = $this->parseMoneyBlock($html);
        if (! $prices && preg_match('/([\d,]+)\s*USD/', $html, $m)) {   // 폴백(USD 텍스트)
            $usd = (int) str_replace(',', '', $m[1]);
            if ($usd > 0) {
                $prices = ['USD' => $usd];
            }
        }
        if ($prices) {
            $out['prices'] = $prices;
        }

        return $out;
    }

    /**
     * `<p class="money">… ₩ <b>79,900,000</b> $ <b>52,473</b> € <b>45,746</b>` → {KRW,USD,EUR}.
     * 금액 태그는 페이지별로 <b>(재고)/<span>(검차) 제각각 → 기호 뒤 임의 태그 1개로 일반화.
     */
    private function parseMoneyBlock(string $html): array
    {
        if (! preg_match('/class=["\']money["\'][^>]*>(.*?)<\/p>/su', $html, $b)) {
            return [];
        }
        $block = $b[1];
        $out = [];
        foreach (['KRW' => '₩', 'USD' => '\$', 'EUR' => '€'] as $code => $sym) {
            if (preg_match('/'.$sym.'\s*<[^>]+>\s*([\d,]+)/u', $block, $m)) {
                $v = (int) str_replace(',', '', $m[1]);
                if ($v > 0) {
                    $out[$code] = $v;
                }
            }
        }

        return $out;
    }

    /** encar 공개 API → {vehicle_number, region(시), vin, prices:{KRW}}. 실패=[]. */
    public function byEncarId(string $id): array
    {
        $base = rtrim((string) config('services.encar.base_url', 'https://api.encar.com'), '/');
        try {
            $res = Http::timeout(8)->get($base.'/v1/readside/vehicle/'.$id);
        } catch (\Throwable) {
            return [];
        }
        if ($res->failed()) {
            return [];
        }

        $j = (array) $res->json();
        $price = data_get($j, 'advertisement.price');   // 만원 단위 → ×10000

        $out = array_filter([
            'vehicle_number' => data_get($j, 'vehicleNo'),
            'region' => $this->city(data_get($j, 'contact.address')),
            'vin' => data_get($j, 'vin'),
        ], fn ($v) => $v !== null && $v !== '');
        if (is_numeric($price)) {
            $out['prices'] = ['KRW' => (int) round(((float) $price) * 10000)];
        }

        return $out;
    }

    /** 주소 → 시 단위. "대구 서구 …" → 대구 / "경기 안산시 …" → 안산. */
    public function city(?string $addr): ?string
    {
        $addr = trim((string) $addr);
        if ($addr === '') {
            return null;
        }
        $parts = preg_split('/\s+/', $addr);
        $provinces = ['경기', '강원', '충북', '충남', '전북', '전남', '경북', '경남', '제주', '세종', '충청북도', '충청남도', '전라북도', '전라남도', '경상북도', '경상남도'];
        if (in_array($parts[0], $provinces, true) && isset($parts[1])) {
            return preg_replace('/(시|군|구)$/u', '', $parts[1]);   // 안산시 → 안산
        }

        return preg_replace('/(특별자치시|특별자치도|특별시|광역시|시)$/u', '', $parts[0]);   // 대구광역시 → 대구
    }
}

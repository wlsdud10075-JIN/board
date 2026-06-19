<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * 매물 자동채움(enrichment) — encar 공개 JSON API → 차량번호·표시가·지역·VIN prefill.
 *
 * 권위 = meetings/encar-ssancar-enrichment-design.md. 전부 board PHP(외부 스크래퍼 불필요).
 * 실패/미해당이면 [] 반환(prefill 없음, 절대 throw 안 함). ssancar 는 링크 방식 확정 후.
 */
class ListingEnrichment
{
    /**
     * ListingLink::parse 결과(+ 원본 URL)로 enrich.
     *  - encar_id 있으면 encar JSON API.
     *  - ssancar 링크면 페이지 HTML 파싱(그누보드 서버렌더, Http::get). inspected=encar 우회.
     */
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
     *  - inspected_view(검차매물): 원본 encar 링크(wr_link1) 있음 → encar API 우회(KRW·지역 확보). ⭐
     *  - stock_car_view(재고): VIN(<em id="copy_txt">) + 차량번호(번호판 패턴). 차값=USD 라 미결정 → 제외.
     *  - car_view(경매): 미실측 → 위 패턴 best-effort.
     * ⚠️ 셀렉터는 실링크 검증 필요(차량번호 번호판 정규식은 휴리스틱 — 영업 확인 후 저장).
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

        // ① 원본 encar 링크가 있으면 그걸로 encar API 재활용(KRW·지역까지).
        if (preg_match('#encar\.com/cars/detail/(\d+)#i', $html, $m)) {
            $e = $this->byEncarId($m[1]);
            if ($e !== []) {
                return $e;
            }
        }

        // ② VIN = <em id="copy_txt">…</em>, 차량번호 = 한국 번호판 패턴, 차값 = USD(페이지엔 USD만).
        $out = [];
        if (preg_match('/id=["\']copy_txt["\'][^>]*>\s*([^<\s][^<]*?)\s*</u', $html, $m)) {
            $out['vin'] = trim($m[1]);
        }
        if (preg_match('/(\d{2,3}\s?[가-힣]\s?\d{4})/u', $html, $m)) {
            $out['vehicle_number'] = preg_replace('/\s+/u', '', $m[1]);
        }
        if (preg_match('/([\d,]+)\s*USD/', $html, $m)) {   // ssancar 재고는 미화 표기. 통화=USD 로 저장, 영업이 토글 가능.
            $usd = (int) str_replace(',', '', $m[1]);
            if ($usd > 0) {
                $out['expected_price'] = $usd;
                $out['currency'] = 'USD';
            }
        }

        return $out;
    }

    /** encar 공개 API → {vehicle_number, expected_price(원), region(시), vin}. 실패=[]. */
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
            'expected_price' => is_numeric($price) ? (int) round(((float) $price) * 10000) : null,
            'region' => $this->city(data_get($j, 'contact.address')),
            'vin' => data_get($j, 'vin'),
        ], fn ($v) => $v !== null && $v !== '');
        if (isset($out['expected_price'])) {
            $out['currency'] = 'KRW';   // encar = 원화
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

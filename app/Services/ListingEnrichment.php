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
    /** ListingLink::parse 결과로 enrich. encar_id 있으면 encar API. */
    public function enrich(array $parsed): array
    {
        if (! empty($parsed['encar_id'])) {
            return $this->byEncarId((string) $parsed['encar_id']);
        }

        return [];   // ssancar(c_no/wr_id)는 별도(링크 방식 확정 후)
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

        return array_filter([
            'vehicle_number' => data_get($j, 'vehicleNo'),
            'expected_price' => is_numeric($price) ? (int) round(((float) $price) * 10000) : null,
            'region' => $this->city(data_get($j, 'contact.address')),
            'vin' => data_get($j, 'vin'),
        ], fn ($v) => $v !== null && $v !== '');
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

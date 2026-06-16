<?php

namespace App\Support;

/**
 * 매입예정 "승격" — 영업이 붙인 유입 링크(encar/ssancar)에서 식별자 자동 추출.
 *
 * 실링크 샘플 기반 확정(2026-06-16, meetings/integration-A-design.md):
 *  - Encar : fem.encar.com/cars/detail/{id} (서브도메인 무관) — encar_id + source=encar.
 *  - ssancar: 페이지별 3종 — stock_car_view?c_no / inspected_view?wr_id / car_view?car_no.
 *             c_no 는 스파인(기존 컬럼), wr_id/car_no 는 generic ssancar_ref("wr_id:786")로.
 *             ssancar 는 source(encar/auction)를 결정하지 않음(영업이 선택, 기본 encar).
 */
class ListingLink
{
    /**
     * URL 1건을 파싱해 매핑 가능한 필드만 반환.
     * 가능 키: source · encar_id · encar_url · c_no · ssancar_ref. (매치 없으면 빈 배열)
     *
     * @return array<string,string>
     */
    public static function parse(string $url): array
    {
        $url = trim($url);
        if ($url === '') {
            return [];
        }
        $lower = mb_strtolower($url);
        $out = [];

        if (str_contains($lower, 'encar.com')) {
            if (preg_match('#/cars/detail/(\d+)#', $url, $m) || preg_match('#[?&]carid=(\d+)#i', $url, $m)) {
                $out['source'] = 'encar';
                $out['encar_id'] = $m[1];
                $out['encar_url'] = $url;
            }

            return $out;
        }

        if (str_contains($lower, 'ssancar.com')) {
            if (preg_match('#[?&]c_no=(\d+)#i', $url, $m)) {
                $out['c_no'] = $m[1];
            } elseif (preg_match('#[?&]wr_id=(\d+)#i', $url, $m)) {
                $out['ssancar_ref'] = 'wr_id:'.$m[1];
            } elseif (preg_match('#[?&]car_no=(\d+)#i', $url, $m)) {
                $out['ssancar_ref'] = 'car_no:'.$m[1];
            }

            return $out;
        }

        return $out;
    }
}

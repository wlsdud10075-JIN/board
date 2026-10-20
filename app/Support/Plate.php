<?php

namespace App\Support;

/**
 * 차량번호 표시 — EN 화면이면 번호판 한글을 로마자로(30나3333 → 30NA3333, 2026-10-20 Jin).
 *
 * 🔑 **표시 전용.** 저장값·검색·연동 B 매칭키(vehicle_number)는 한글 그대로다 — 여기 결과를 저장하거나
 *    비교에 쓰면 같은 차가 두 번호가 된다. 입력칸(wire:model)에도 쓰지 말 것.
 * 변환표 = car-erp `DocValue::romanizePlate`(수출서류 영문차량번호)와 같은 32자 + 배 — ERP 서류와 같은 표기가 나와야
 * 바이어·영업이 서로 다른 번호를 보지 않는다. 번호판에 안 쓰는 한글(지역명 등)은 그대로 둔다.
 * ℹ️ 바이어 견적 카드(`forwarding` vehicleRoman)는 띄어 쓴 대문자(375 REO 1924)로 따로 쓴다 — 그건 카드 표기라 이 함수와 무관.
 */
class Plate
{
    private const MAP = [
        '가' => 'GA', '거' => 'GEO', '고' => 'GO', '구' => 'GU',
        '나' => 'NA', '너' => 'NEO', '노' => 'NO', '누' => 'NU',
        '다' => 'DA', '더' => 'DEO', '도' => 'DO', '두' => 'DU',
        '라' => 'RA', '러' => 'REO', '로' => 'RO', '루' => 'RU',
        '마' => 'MA', '머' => 'MEO', '모' => 'MO', '무' => 'MU',
        '바' => 'BA', '버' => 'BEO', '보' => 'BO', '부' => 'BU',
        '사' => 'SA', '서' => 'SEO', '소' => 'SO', '수' => 'SU',
        '아' => 'A', '어' => 'EO', '오' => 'O', '우' => 'U',
        '자' => 'JA', '저' => 'JEO', '조' => 'JO', '주' => 'JU',
        '하' => 'HA', '허' => 'HEO', '호' => 'HO', '배' => 'BAE',
    ];

    public static function romanize(?string $plate): ?string
    {
        if ($plate === null || $plate === '') {
            return $plate;
        }

        // 번호판 모양(숫자 2~3 + 한글 1 + 숫자 4)일 때만 바꾼다 — 지역명 붙은 옛 번호판(서울12가3456)을
        // 글자 단위로 바꾸면 「SEO울12GA3456」 같은 반쪽 표기가 된다.
        if (! preg_match('/^(\d{2,3})\s*([가-힣])\s*(\d{4})$/u', trim($plate), $m) || ! isset(self::MAP[$m[2]])) {
            return $plate;
        }

        return $m[1].self::MAP[$m[2]].$m[3];
    }

    /** 화면용 — 현재 로케일이 en 일 때만 로마자. */
    public static function show(?string $plate): ?string
    {
        return app()->getLocale() === 'en' ? self::romanize($plate) : $plate;
    }
}

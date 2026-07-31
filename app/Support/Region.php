<?php

namespace App\Support;

/**
 * 지역명 정규화 — 크롤링 주소(엔카)와 사람 입력을 `config('board.regions')` 의 정식 라벨 하나로 맞춘다.
 *
 * 왜 필요한가: `users.region`(검차원 담당지역) · `purchase_listings.region` · `inspection_assignments.region`
 * 은 전부 **문자열 그대로 조인**된다. 한쪽이 "안산", 다른 쪽이 "경기 안산시" 면 배정도 알림톡도 영영 안 맞는다.
 *
 * ⚠️ 정식형은 **풀네임**("경기 안산시")이다. 축약형("안산")으로 통일하면 `광주광역시` 와 `경기 광주시` 가
 *    같은 키로 뭉개진다 — 축약은 도(道) 정보를 버리므로 canonical 이 될 수 없다.
 *
 * 해석 규칙:
 *  1. 도 접두가 있으면 그대로 사용 — "경기 안산시 단원구", "경상남도 창원시" → 경기 안산시 / 경남 창원시
 *  2. 도 없이 시/군명만 오면 후보가 **하나뿐일 때만** 복원 — "안산" → 경기 안산시
 *  3. 후보가 여럿이면(고성=강원·경남) 손대지 않고 원본 유지 — 추측해서 틀리느니 그대로 두는 게 낫다
 * 어느 경우든 못 알아보면 입력을 그대로 돌려준다(공백만 정리). 절대 null 로 지우지 않는다.
 */
class Region
{
    /** 도(道) 표기 변형 → `config('board.regions')` 가 쓰는 약칭. */
    private const PROVINCE = [
        '경기' => '경기', '경기도' => '경기',
        '강원' => '강원', '강원도' => '강원', '강원특별자치도' => '강원',
        '충북' => '충북', '충청북도' => '충북',
        '충남' => '충남', '충청남도' => '충남',
        '전북' => '전북', '전라북도' => '전북', '전북특별자치도' => '전북',
        '전남' => '전남', '전라남도' => '전남',
        '경북' => '경북', '경상북도' => '경북',
        '경남' => '경남', '경상남도' => '경남',
        '제주' => '제주', '제주도' => '제주', '제주특별자치도' => '제주',
    ];

    /** 정식 라벨로 변환. 빈값=null. 이미 정식형이면 그대로(멱등 — 백필을 여러 번 돌려도 안전). */
    public static function canonical(?string $value): ?string
    {
        $value = trim(preg_replace('/\s+/u', ' ', (string) $value));
        if ($value === '') {
            return null;
        }

        [$province, $city] = self::parse($value);
        $index = self::index();

        if (isset($index[$province.'|'.$city])) {
            return $index[$province.'|'.$city];
        }
        if ($province === '') {
            $matches = self::cityMatches($city);

            return count($matches) === 1 ? $matches[0] : $value;   // 여럿이면 추측 금지
        }

        return $value;   // 도는 알지만 목록에 없는 시·군 — 입력 유지
    }

    /**
     * 도 정보 없이 들어온 값이 두 지역 이상으로 읽히는가(광주=광역시/경기, 고성=강원/경남).
     * 백필이 이런 값을 자동변환하지 않고 사람에게 넘기기 위한 판정.
     */
    public static function isAmbiguous(?string $value): bool
    {
        $value = trim(preg_replace('/\s+/u', ' ', (string) $value));
        if ($value === '') {
            return false;
        }

        [$province, $city] = self::parse($value);

        return $province === '' && count(self::cityMatches($city)) > 1;
    }

    /** "경기 안산시 단원구" → ['경기','안산'] / "서울특별시" → ['','서울'] / "안산시" → ['','안산']. */
    private static function parse(string $value): array
    {
        $parts = explode(' ', $value);
        if (isset(self::PROVINCE[$parts[0]], $parts[1])) {
            return [self::PROVINCE[$parts[0]], self::bare($parts[1])];
        }

        return ['', self::bare($parts[0])];
    }

    /** 행정 접미사 제거 — 안산시→안산, 가평군→가평, 부산광역시→부산. */
    private static function bare(string $token): string
    {
        return preg_replace('/(특별자치시|특별자치도|특별시|광역시|시|군)$/u', '', $token);
    }

    /** 시·군명만으로 매칭되는 정식 라벨들(광역시 포함). */
    private static function cityMatches(string $city): array
    {
        $found = [];
        foreach (self::index() as $key => $label) {
            if ($city !== '' && str_ends_with($key, '|'.$city)) {
                $found[] = $label;
            }
        }

        return $found;
    }

    /** "도|시" → 정식 라벨. config 목록에서 1회 생성. */
    private static function index(): array
    {
        static $index = null;
        if ($index !== null) {
            return $index;
        }

        $index = [];
        foreach (config('board.regions', []) as $label) {
            [$province, $city] = self::parse($label);
            $index[$province.'|'.$city] = $label;
        }

        return $index;
    }
}

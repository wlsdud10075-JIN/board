<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * ssancar 매매단지 목록(`yard.list`, 2026-11-05 Jin) — 엔카 링크 없는 차의 「차 위치」 드롭다운.
 * 읽기라 화면 열 때마다 불러도 되지만 10분 캐시(ssancar 권장 「몇 분」). **실패는 캐시하지 않는다** — 빈 목록이면 화면은 「직접 입력」만.
 * region 은 board `config('board.regions')` 와 같은 표기(ssancar 가 정적 복사).
 */
class SsancarYards
{
    public const CACHE_KEY = 'ssancar_yards';

    /** @return list<array{id:int,name:string,region:string,address:string}> */
    public static function all(): array
    {
        if (! SsancarInspection::configured()) {
            return [];
        }
        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }
        try {
            $r = SsancarInspection::post(['event' => 'yard.list', 'event_id' => (string) Str::uuid()]);
        } catch (\Throwable) {
            return [];
        }
        if (! SsancarInspection::ok($r) || ! is_array($r->json('yards'))) {
            return [];
        }
        $yards = [];
        foreach ($r->json('yards') as $y) {
            if (! is_array($y) || ! isset($y['id'], $y['name'])) {
                continue;
            }
            $yards[] = ['id' => (int) $y['id'], 'name' => (string) $y['name'], 'region' => (string) ($y['region'] ?? ''), 'address' => (string) ($y['address'] ?? '')];
        }
        Cache::put(self::CACHE_KEY, $yards, now()->addMinutes(10));

        return $yards;
    }

    public static function find(int $id): ?array
    {
        foreach (self::all() as $y) {
            if ($y['id'] === $id) {
                return $y;
            }
        }

        return null;
    }

    /**
     * 드롭다운용 — 같은 지역 먼저, 나머지는 지역·이름순.
     *
     * @return array{same: list<array>, other: list<array>}
     */
    public static function grouped(?string $region): array
    {
        $region = Region::canonical($region);
        $same = $other = [];
        foreach (self::all() as $y) {
            if ($region !== null && $y['region'] === $region) {
                $same[] = $y;
            } else {
                $other[] = $y;
            }
        }
        usort($other, fn ($a, $b) => [$a['region'], $a['name']] <=> [$b['region'], $b['name']]);

        return ['same' => $same, 'other' => $other];
    }
}

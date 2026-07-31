<?php

namespace App\Models\Concerns;

use App\Support\Region;
use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * `region` 컬럼을 **저장 시점에** 정식 라벨로 맞춘다.
 *
 * 세 모델(PurchaseListing·User·InspectionAssignment)의 region 은 문자열 그대로 조인되므로
 * 표기가 어긋나면 배정·알림톡이 조용히 빗나간다. 입력 화면이 5곳·크롤링 1곳으로 흩어져 있어
 * 화면마다 정규화하면 언젠가 하나가 빠진다 → 모델을 단일 경로로 삼는다.
 */
trait NormalizesRegion
{
    protected function region(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => Region::canonical($value));
    }
}

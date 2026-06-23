<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 런타임 전역 설정 key-value (car-erp Setting 미러).
 * 읽기는 정적 get(), 쓰기는 화면에서 updateOrCreate.
 */
class Setting extends Model
{
    protected $fillable = ['key', 'value', 'type', 'description'];

    /**
     * 타입 캐스팅해 설정값 반환. 없으면 $default.
     * 사이드바·로그인 등 매 렌더 호출 → 마이그 전(배포 순간) 테이블 부재 시에도
     * 500 대신 $default 로 degrade (인증 관문 락아웃 방지).
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        try {
            $setting = static::query()->where('key', $key)->first();
        } catch (\Throwable $e) {
            return $default;
        }

        if (! $setting) {
            return $default;
        }

        return match ($setting->type) {
            'boolean' => filter_var($setting->value, FILTER_VALIDATE_BOOLEAN),
            'integer' => (int) $setting->value,
            default => $setting->value,
        };
    }
}

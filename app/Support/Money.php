<?php

namespace App\Support;

/**
 * 통화 환산 유틸 — 차값(car_cost)은 가져온 통화 그대로 보관(엔카=KRW, ssancar=원/미/유로 택1).
 * KRW 환산은 "계산할 때만"(차값 자체는 불변). 화면 미리보기·모델이 공통으로 사용.
 */
class Money
{
    public const SYMBOLS = ['KRW' => '원', 'USD' => '$', 'EUR' => '€'];

    /** 금액을 통화 기준으로 KRW 로 환산. KRW(또는 미지정)는 그대로. 빈값=null. */
    public static function toKrw($amount, ?string $currency, int $krwPerUsd, int $krwPerEur): ?int
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        return match ($currency) {
            'USD' => (int) round((float) $amount * $krwPerUsd),
            'EUR' => (int) round((float) $amount * $krwPerEur),
            default => (int) $amount,
        };
    }

    /** 통화 기호 — 원화만 언어를 탄다(ko 「원」 / en 「KRW」, 2026-10-20 — EN 화면에 「원」이 남던 것). $·€ 는 공통. */
    public static function symbol(?string $currency): string
    {
        $cur = $currency ?: 'KRW';

        return $cur === 'KRW' ? __('common.won_currency') : (self::SYMBOLS[$cur] ?? $cur);
    }

    /** 차값 표시 — 통화 기호 포함(KRW=…원·… KRW / USD=$… / EUR=€…). */
    public static function display($amount, ?string $currency): string
    {
        if ($amount === null || $amount === '') {
            return '—';
        }
        $cur = $currency ?: 'KRW';

        if ($cur !== 'KRW') {
            return (self::SYMBOLS[$cur] ?? '').number_format($amount);
        }

        return app()->getLocale() === 'en' ? number_format($amount).' KRW' : number_format($amount).'원';
    }
}

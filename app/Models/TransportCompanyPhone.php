<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 업체 번호 1개 — 전화는 at-rest 암호화(seller_phone 선례), 화면엔 그대로·감사/로그 `***`. */
class TransportCompanyPhone extends Model
{
    protected $fillable = ['transport_company_id', 'phone', 'label', 'is_active'];

    protected $casts = ['phone' => 'encrypted', 'is_active' => 'boolean'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(TransportCompany::class, 'transport_company_id');
    }

    /** 숫자만(하이픈·공백 제거). 중복 판정·발송에 쓴다. */
    public static function normalize(?string $phone): string
    {
        return preg_replace('/\D/', '', (string) $phone) ?? '';
    }

    /** 010-****-1234 — 알림톡 본문·감사 행용(가운데 마스킹). */
    public static function mask(?string $phone): string
    {
        $d = self::normalize($phone);
        if (strlen($d) < 7) {
            return $d === '' ? '—' : '***';
        }

        return substr($d, 0, 3).'-****-'.substr($d, -4);
    }
}

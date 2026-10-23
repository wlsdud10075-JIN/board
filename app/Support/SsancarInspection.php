<?php

namespace App\Support;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * 검차 큐 연동(ssancar.com) 공통 — 설정·테넌트·서명 전송 (2026-10-23).
 * 서명 = `X-Board-Signature: sha256=<hex>`, hex = HMAC-SHA256(timestamp + "." + raw body, 테넌트 시크릿),
 * `X-Timestamp` = unix 초(±300초), `X-Board-Tenant` = heymanboard/ssancarboard. ssancar 가 서명 키로 테넌트를 판정해 대조한다.
 */
class SsancarInspection
{
    public static function configured(): bool
    {
        return (string) config('services.ssancar_inspection.url') !== ''
            && (string) config('services.ssancar_inspection.hmac_secret') !== ''
            && self::tenant() !== '';
    }

    /** X-Board-Tenant — 설정값 우선, 없으면 APP_NAME 으로 인스턴스 지도에서(새 .env 값 없이 두 박스가 갈린다). */
    public static function tenant(): string
    {
        $t = (string) config('services.ssancar_inspection.tenant');
        if ($t !== '') {
            return $t;
        }

        return (string) (config('board.instances.'.config('app.name').'.tenant') ?? '');
    }

    /** @param array<string,mixed> $payload */
    public static function post(array $payload): Response
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ts = (string) time();
        $sig = hash_hmac('sha256', $ts.'.'.$body, (string) config('services.ssancar_inspection.hmac_secret'));

        return Http::timeout(15)
            ->withBody($body, 'application/json')
            ->withHeaders([
                'X-Board-Tenant' => self::tenant(),
                'X-Timestamp' => $ts,
                'X-Board-Signature' => 'sha256='.$sig,
            ])
            ->post((string) config('services.ssancar_inspection.url'));
    }
}

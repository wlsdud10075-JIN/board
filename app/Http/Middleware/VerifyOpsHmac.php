<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * 관제센터(ssancarerp /admin/control 허브)가 board 요약을 읽을 때의 서명 검증(2026-11-12).
 * 계약 권위 = car-erp `docs/design/ops-control-center.md` §4 — 허브는 `VerifyErpPeerHmac::canonical` 로 서명한다.
 *
 *   canonical = METHOD\npath?ksort+http_build_query(query)\nts\nbody → hmac sha256(OPS_HMAC_SECRET)
 *   v2(2026-11-12 Jin) = ts 와 body 사이에 nonce 한 줄 추가 — 전환기엔 v2·v1 둘 다 받는다.
 *   헤더 = X-Timestamp(초, ±300) · X-Nonce(1회용) · X-Board-Signature: sha256=<hex>
 *
 * 시크릿이 비면 401(fail-closed). 다른 연동 시크릿을 재사용하지 않는다(값은 3사 ERP 와 같고 Jin 이 넣는다).
 */
class VerifyOpsHmac
{
    private const WINDOW = 300;

    private const NONCE_PREFIX = 'ops_nonce:';

    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.ops.hmac_secret');
        if ($secret === '') {
            return $this->reject($request, 'no_secret');
        }

        $ts = (int) $request->header('X-Timestamp', '0');
        if ($ts <= 0 || abs(now()->timestamp - $ts) > self::WINDOW) {
            return $this->reject($request, 'timestamp');
        }

        $nonce = (string) $request->header('X-Nonce', '');
        if ($nonce === '' || strlen($nonce) > 100) {
            return $this->reject($request, 'nonce');
        }

        $provided = (string) $request->header('X-Board-Signature', '');
        $provided = str_starts_with($provided, 'sha256=') ? substr($provided, 7) : $provided;
        // v2(nonce 포함) 먼저, 안 맞으면 v1 — 전환기 동안 둘 다 받는다(Jin 2026-11-12). 허브가 v2 로 넘어가고 며칠 뒤 v1 삭제(car-erp 세션이 알림).
        $args = [$request->getMethod(), $request->getPathInfo(), $request->query->all(), $ts];
        $ok = $provided !== '' && (
            hash_equals(hash_hmac('sha256', self::canonicalV2(...$args, nonce: $nonce, body: $request->getContent()), $secret), $provided)
            || hash_equals(hash_hmac('sha256', self::canonical(...$args, body: $request->getContent()), $secret), $provided)
        );
        if (! $ok) {
            return $this->reject($request, 'signature');
        }

        // nonce 는 서명이 맞은 뒤에 소비한다 — 서명 틀린 요청이 남의 nonce 를 태워 없애지 못하게.
        if (! Cache::add(self::NONCE_PREFIX.$nonce, 1, self::WINDOW * 2)) {
            return $this->reject($request, 'nonce');
        }

        return $next($request);
    }

    /** v2 — nonce 가 서명 안에 들어간다(ts 와 body 사이). car-erp `docs/design/ops-control-center.md` §4. */
    public static function canonicalV2(string $method, string $path, array $query, int $ts, string $nonce, string $body = ''): string
    {
        ksort($query);

        return strtoupper($method)."\n".$path.'?'.http_build_query($query)."\n".$ts."\n".$nonce."\n".$body;
    }

    /** v1 — car-erp `VerifyErpPeerHmac::canonical` 과 바이트 일치. 전환기 호환용, 허브가 v2 로 간 뒤 삭제. */
    public static function canonical(string $method, string $path, array $query, int $ts, string $body = ''): string
    {
        ksort($query);

        return strtoupper($method)."\n".$path.'?'.http_build_query($query)."\n".$ts."\n".$body;
    }

    private function reject(Request $request, string $why): Response
    {
        Log::warning('[ops] summary HMAC 거부 — 401', ['ip' => $request->ip(), 'why' => $why]);

        return response()->json(['error' => 'unauthorized', 'message' => 'Unauthorized'], 401);
    }
}

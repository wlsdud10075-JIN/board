<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 시스템관리자(super) 또는 업무관리자(permission=manager) — 감사로그 등 role 무관 등급 화면.
 * 기능설정처럼 super 만 열어야 하는 곳은 `EnsureSuper` 를 그대로 쓴다(이걸 넓히면 설정까지 열린다).
 */
class EnsureFullAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! $user->is_active || ! $user->hasFullAccess()) {
            abort(403);
        }

        return $next($request);
    }
}

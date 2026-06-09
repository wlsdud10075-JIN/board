<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 시스템관리자(super) 전용 — 사용자관리·기능설정 등. role=관리 도 차단.
 */
class EnsureSuper
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->is_active || ! $user->isSuper()) {
            abort(403);
        }

        return $next($request);
    }
}

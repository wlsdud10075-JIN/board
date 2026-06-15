<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    /**
     * board 역할 기반 접근 제어. manager 는 어디든 통과시키지 않고
     * 라우트에서 명시한 roles 목록에 포함돼야 통과.
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user || ! $user->is_active) {
            abort(403);
        }

        // 시스템관리자(super)는 role 무관 전체 통과 (car-erp super 대응)
        if ($user->isSuper()) {
            return $next($request);
        }

        if (! in_array($user->role, $roles, true)) {
            abort(403);
        }

        return $next($request);
    }
}

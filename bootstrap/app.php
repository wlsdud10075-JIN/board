<?php

use App\Http\Middleware\EnsureFullAccess;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\EnsureSuper;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',   // 연동 A — respond.io inbound webhook (/api/*, stateless·CSRF 제외)
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'role' => EnsureRole::class,
            'super' => EnsureSuper::class,
            'full-access' => EnsureFullAccess::class,   // super 또는 업무관리자
        ]);

        // i18n Phase 0 — 모든 web 요청에서 사용자 언어 적용
        $middleware->web(append: [SetLocale::class]);

        // 클레임 대표 승인 링크(로그인 없음) — 서명 URL(60분)이 인가·위조 방지를 맡는다(ERP a/payout/* 미러).
        $middleware->validateCsrfTokens(except: ['a/claim/*']);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();

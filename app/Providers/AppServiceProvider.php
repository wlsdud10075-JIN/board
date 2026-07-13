<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // 알림톡 발송기 = Setting 기반 config(::active())로만 생성 가능(AlimtalkConfig 는 스칼라 생성자라
        // 오토와이어 불가). 컨테이너 주입(커맨드 handle·Notifier 생성자)이 이 팩토리를 쓰게 바인딩.
        $this->app->bind(
            \App\Services\BizmAlimtalkService::class,
            fn () => \App\Services\BizmAlimtalkService::active(),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 운영은 nginx TLS 종료(https 서빙) + APP_URL=http → 생성 scheme 불일치로 서명 URL 검증 403.
        // production 에서 URL 생성을 https 로 강제(콘솔·웹 공통) → 바이어 공개 페이지(signed) 정상 + 불필요한 http→https 301 제거.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // 연동 A — /api/* (respond.io webhook) 의 기본 api 미들웨어 그룹이 쓰는 'api' 리미터.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));
    }
}

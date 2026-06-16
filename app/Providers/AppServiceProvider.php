<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 연동 A — /api/* (respond.io webhook) 의 기본 api 미들웨어 그룹이 쓰는 'api' 리미터.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));
    }
}

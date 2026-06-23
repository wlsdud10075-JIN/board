<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white antialiased dark:bg-linear-to-b dark:from-neutral-950 dark:to-neutral-900">
        {{-- 브랜드명은 기능설정의 sidebar_brand 와 동일 출처 — 한 곳에서 바꾸면 로그인·사이드바 함께 변경. --}}
        @php
            $authBrand = trim((string) \App\Models\Setting::get('sidebar_brand', 'HeymanBoard')) ?: 'HeymanBoard';
            $authBrandInitial = mb_strtoupper(mb_substr($authBrand, 0, 1));
        @endphp
        <div class="bg-background flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
            <div class="flex w-full max-w-sm flex-col gap-6">
                <a href="{{ route('home') }}" class="flex flex-col items-center gap-3" wire:navigate>
                    <span class="flex h-14 w-14 items-center justify-center rounded-2xl text-2xl font-bold text-white shadow-sm" style="background-color: var(--color-primary);">{{ $authBrandInitial }}</span>
                    <span class="flex flex-col items-center gap-0.5">
                        <span class="text-2xl font-bold tracking-tight text-white">{{ $authBrand }}</span>
                        <span class="text-xs font-medium text-zinc-400">{{ __('nav.brand_sub') }}</span>
                    </span>
                    <span class="sr-only">{{ config('app.name', 'Laravel') }}</span>
                </a>
                <div class="flex flex-col gap-6">
                    {{ $slot }}
                </div>
            </div>
        </div>
        @fluxScripts
    </body>
</html>

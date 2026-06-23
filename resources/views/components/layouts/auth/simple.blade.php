<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-linear-to-b from-[#f3f1fb] via-white to-white text-zinc-800 antialiased">
        {{-- car-erp auth 레이아웃 정렬 — 밝은 배경 + 흰 카드. 색만 board 퍼플(--color-primary).
             --color-accent override 를 auth 컨테이너로 스코프 → Flux primary 버튼/링크가
             앱 전역 영향 없이 이 화면에서만 board 퍼플.
             브랜드명은 기능설정의 sidebar_brand 와 동일 출처 — 한 곳에서 바꾸면 로그인·사이드바 함께 변경. --}}
        @php
            $authBrand = trim((string) \App\Models\Setting::get('sidebar_brand', 'HeymanBoard')) ?: 'HeymanBoard';
            $authBrandInitial = mb_strtoupper(mb_substr($authBrand, 0, 1));
        @endphp
        <div class="flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10"
             style="--color-accent: #7c6fcd; --color-accent-content: #6b5dbd; --color-accent-foreground: #ffffff;">
            <div class="flex w-full max-w-sm flex-col gap-6">
                <a href="{{ route('home') }}" class="flex flex-col items-center gap-3" wire:navigate>
                    <span class="flex h-14 w-14 items-center justify-center rounded-2xl text-2xl font-bold text-white shadow-sm" style="background-color: var(--color-primary); box-shadow: 0 1px 3px 0 rgba(124,111,205,0.35);">{{ $authBrandInitial }}</span>
                    <span class="flex flex-col items-center gap-0.5">
                        <span class="text-2xl font-bold tracking-tight" style="color: var(--color-primary-text);">{{ $authBrand }}</span>
                        <span class="text-xs font-medium text-zinc-400">{{ __('nav.brand_sub') }}</span>
                    </span>
                    <span class="sr-only">{{ config('app.name', 'Laravel') }}</span>
                </a>
                <div class="flex flex-col gap-6 rounded-2xl border bg-white p-6 shadow-sm" style="border-color: var(--color-primary-light);">
                    {{ $slot }}
                </div>
            </div>
        </div>
        @fluxScripts
    </body>
</html>

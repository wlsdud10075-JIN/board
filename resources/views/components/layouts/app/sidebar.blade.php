<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head')
</head>
<body class="min-h-screen bg-gray-50">
@php
    $user = auth()->user();
    $permLabel = $user->isSuper() ? __('nav.perm.super') : $user->roleLabel();
    $workGuideUrl = config('board.work_guide_url') ?: '';

    // 브랜드 텍스트 — 기능설정(sidebar_brand)과 동일 출처. 로그인 화면과 한 곳에서 같이 변경.
    $sidebarBrand = trim((string) \App\Models\Setting::get('sidebar_brand', 'HeymanBoard')) ?: 'HeymanBoard';
    $sidebarBrandInitial = mb_strtoupper(mb_substr($sidebarBrand, 0, 1));

    // i18n Phase 0 — 영어 활성 시에만 상단바 언어 전환 노출
    $localeEnEnabled = (bool) \App\Models\Setting::get('locale_en_enabled', false);

    // 승격 대기 뱃지 (영업=본인 담당 / 관리·super=전체)
    $pendingPromo = 0;
    if ($user->isSuper() || $user->isSales() || $user->isManager()) {
        $pq = \App\Models\PromotionRequest::where('status', 'pending');
        if (! $user->canSeeAll()) {
            $pq->where('assigned_email', $user->respondAgentEmail());
        }
        $pendingPromo = $pq->count();
    }

    // 전달 대기 뱃지 — 검차완료(inspected) 차 수. SalesmanScope 가 영업은 본인 것만 자동집계.
    $pendingForward = 0;
    if ($user->isSuper() || $user->isSales() || $user->isManager()) {
        $pendingForward = \App\Models\PurchaseListing::where('status', 'inspected')->count();
    }

    $routeName = request()->route()?->getName();
    $breadcrumb = match ($routeName) {
        'dashboard' => __('nav.crumb.dashboard'),
        'listings' => __('nav.crumb.listings'),
        'forwarding' => __('nav.crumb.forwarding'),
        'verdicts' => __('nav.crumb.verdicts'),
        'portal' => __('nav.crumb.portal'),
        'inspection' => __('nav.crumb.inspection'),
        'auction' => __('nav.crumb.auction'),
        'manage' => __('nav.crumb.manage'),
        'users' => __('nav.crumb.users'),
        'audit' => __('nav.crumb.audit'),
        'settings.profile' => __('nav.crumb.settings_profile'),
        'settings.password' => __('nav.crumb.settings_password'),
        'settings.appearance' => __('nav.crumb.settings_appearance'),
        'admin.settings' => __('nav.crumb.admin_settings'),
        default => '',
    };

    $can = fn ($roles) => $user->isSuper() || in_array($user->role, (array) $roles, true);
    $menuGroups = [
        // 업무 흐름 순서대로 (등록 → 검차 → 전달 → 회신 → 구매확정 → 포털). 영업은 본인 역할 항목만 보여 순서 유지.
        ['key' => 'work', 'label' => __('nav.group.work'), 'items' => [
            ['label' => __('nav.menu.listings'), 'href' => route('listings'), 'icon' => 'clipboard', 'active' => request()->routeIs('listings'), 'show' => $can(['sales', 'manager']), 'badge' => $pendingPromo ?: null],
            ['label' => __('nav.menu.inspection'), 'href' => route('inspection'), 'icon' => 'camera', 'active' => request()->routeIs('inspection'), 'show' => $can(['inspection', 'manager'])],
            ['label' => __('nav.menu.forwarding'), 'href' => route('forwarding'), 'icon' => 'paper-airplane', 'active' => request()->routeIs('forwarding'), 'show' => $can(['sales', 'manager']), 'badge' => $pendingForward ?: null],
            ['label' => __('nav.menu.verdicts'), 'href' => route('verdicts'), 'icon' => 'chat', 'active' => request()->routeIs('verdicts'), 'show' => $can(['sales', 'manager'])],
            ['label' => __('nav.menu.auction'), 'href' => route('auction'), 'icon' => 'banknotes', 'active' => request()->routeIs('auction'), 'show' => $can(['sales', 'auction', 'manager'])],
            ['label' => __('nav.menu.portal'), 'href' => route('portal'), 'icon' => 'wallet', 'active' => request()->routeIs('portal'), 'show' => $can(['sales', 'manager'])],
        ]],
        ['key' => 'manage', 'label' => __('nav.group.manage'), 'items' => [
            ['label' => __('nav.menu.manage'), 'href' => route('manage'), 'icon' => 'shield', 'active' => request()->routeIs('manage'), 'show' => $can(['manager'])],
        ]],
        ['key' => 'system', 'label' => __('nav.group.system'), 'items' => [
            ['label' => __('nav.menu.users'), 'href' => route('users'), 'icon' => 'user-group', 'active' => request()->routeIs('users'), 'show' => $user->isSuper()],
            ['label' => __('nav.menu.audit'), 'href' => route('audit'), 'icon' => 'document', 'active' => request()->routeIs('audit'), 'show' => $user->isSuper()],
            ['label' => __('nav.menu.settings'), 'href' => route('admin.settings'), 'icon' => 'cog', 'active' => request()->routeIs('admin.settings'), 'show' => $user->isSuper()],
        ]],
    ];

    $icons = [
        'clipboard' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>',
        'chat' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.86 9.86 0 01-4-.8L3 20l1.3-3.9A7.96 7.96 0 013 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>',
        'wallet' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M21 12V7H5a2 2 0 010-4h14v4M3 5v14a2 2 0 002 2h16v-5M18 12a2 2 0 000 4h4v-4h-4z"/>',
        'camera' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/>',
        'banknotes' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 8h18v8H3V8zm9 4a2 2 0 11-4 0 2 2 0 014 0zm-9 0h.01M21 12h.01"/>',
        'paper-airplane' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M6 12L3.27 4.36a.6.6 0 01.83-.73l16.5 7.82a.6.6 0 010 1.08l-16.5 7.82a.6.6 0 01-.83-.73L6 12zm0 0h7"/>',
        'shield' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>',
        'user-group' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>',
        'document' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>',
        'cog' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>',
        'book' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/>',
        'logout' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>',
        'menu' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 6h16M4 12h16M4 18h16"/>',
    ];
@endphp

<div class="flex min-h-screen"
     x-data="{
        open: localStorage.getItem('sidebar-open') !== 'false',
        mobileOpen: false,
        isMobile: window.innerWidth < 768,
        openedAt: 0,
        toggle() {
            if (this.isMobile) {
                this.mobileOpen = !this.mobileOpen;
                if (this.mobileOpen) { this.openedAt = Date.now(); }
            }
            else { this.open = !this.open; localStorage.setItem('sidebar-open', this.open); }
        },
        // 여는 탭의 잔여 합성클릭(ghost click)이 백드롭/링크에 떨어져 즉시 닫히는 것 방지(안드 크롬).
        // 실제 메뉴 탭은 연 뒤 한참 후라 영향 없음.
        closeMobile() {
            if (this.isMobile && Date.now() - this.openedAt < 400) { return; }
            this.mobileOpen = false;
        }
     }"
     x-on:resize.window.debounce.200ms="isMobile = window.innerWidth < 768">

    <div x-show="isMobile && mobileOpen" x-transition.opacity @click="closeMobile()" class="sidebar-backdrop" style="display:none;"></div>

    <aside class="app-sidebar flex flex-col text-white shrink-0"
           :class="isMobile ? 'sidebar-mobile' : 'sticky top-0 h-screen'"
           :style="isMobile ? '' : ('width: ' + (open ? '220px' : '48px'))"
           x-show="!isMobile || mobileOpen"
           x-transition:enter="sidebar-enter-active" x-transition:enter-start="sidebar-enter-from" x-transition:enter-end="sidebar-enter-to"
           x-transition:leave="sidebar-enter-active" x-transition:leave-start="sidebar-enter-to" x-transition:leave-end="sidebar-enter-from">

        {{-- 로고 --}}
        <div class="flex h-12 items-center border-b border-white/5 px-2 shrink-0">
            <a href="{{ route('dashboard') }}" wire:navigate @click="if(isMobile) closeMobile()"
               class="flex w-full items-center gap-2 overflow-hidden" :class="(isMobile || open) ? 'px-1.5' : 'justify-center'">
                <span class="flex h-7 w-7 items-center justify-center rounded-md text-[11px] font-bold text-white shrink-0" style="background-color:var(--color-primary);">{{ $sidebarBrandInitial }}</span>
                <span x-show="isMobile || open" x-transition.opacity class="min-w-0 flex-1 truncate text-[13px] font-medium text-white">{{ $sidebarBrand }}</span>
            </a>
        </div>

        {{-- 사용자 --}}
        <div class="border-b border-white/5 px-3 py-3 shrink-0 overflow-hidden">
            <div x-show="isMobile || open" x-transition.opacity>
                <div class="truncate text-[13px] font-medium text-white">{{ $user->name }}</div>
                <div class="text-[11px]" style="color:var(--color-sidebar-text);">{{ $permLabel }}</div>
            </div>
            <div x-show="!isMobile && !open" class="flex justify-center">
                <div class="flex h-7 w-7 items-center justify-center rounded-full bg-white/10 text-[11px] font-medium text-white" :title="'{{ addslashes($user->name) }} · {{ $permLabel }}'">{{ $user->initials() }}</div>
            </div>
        </div>

        {{-- 메뉴 --}}
        <nav class="flex-1 space-y-3 overflow-y-auto py-3">
            @foreach ($menuGroups as $group)
                @php $visibleItems = array_values(array_filter($group['items'], fn ($it) => $it['show'])); @endphp
                @if (count($visibleItems))
                    <div x-data="{ grpOpen: localStorage.getItem('navgrp-{{ $group['key'] }}') !== 'false' }">
                        <button type="button" x-show="isMobile || open" x-transition.opacity
                                @click="grpOpen = !grpOpen; localStorage.setItem('navgrp-{{ $group['key'] }}', grpOpen)"
                                class="sidebar-section-label flex w-full items-center justify-between hover:text-white">
                            <span>{{ $group['label'] }}</span>
                            <svg class="h-3 w-3 shrink-0 transition-transform" :class="{ '-rotate-90': !grpOpen }" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"/></svg>
                        </button>
                        <div x-show="!(isMobile || open) || grpOpen" x-transition.opacity>
                            @foreach ($visibleItems as $item)
                                <a href="{{ $item['href'] }}" wire:navigate @click="if(isMobile) closeMobile()"
                                   :title="(isMobile || open) ? '' : '{{ $item['label'] }}'"
                                   class="sidebar-item {{ $item['active'] ? 'is-active' : '' }}" :class="{ 'sidebar-item-collapsed': !isMobile && !open }">
                                    <svg class="sidebar-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">{!! $icons[$item['icon']] !!}</svg>
                                    <span x-show="isMobile || open" class="flex-1 truncate">{{ $item['label'] }}</span>
                                    @if (! empty($item['badge']))
                                        <span x-show="isMobile || open" class="ml-auto rounded-full bg-amber-500 px-1.5 py-0.5 text-[10px] font-bold text-white">{{ $item['badge'] }}</span>
                                    @endif
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endforeach
        </nav>

        {{-- 하단: 업무가이드 + 내 설정 + 로그아웃 --}}
        <div class="border-t border-white/5 py-2 shrink-0">
            @if ($workGuideUrl)
                <a href="{{ $workGuideUrl }}" target="_blank" rel="noopener noreferrer" @click="if(isMobile) closeMobile()"
                   :title="(isMobile || open) ? '' : '{{ __('nav.action.work_guide') }}'" class="sidebar-item" :class="{ 'sidebar-item-collapsed': !isMobile && !open }">
                    <svg class="sidebar-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">{!! $icons['book'] !!}</svg>
                    <span x-show="isMobile || open" class="flex-1 truncate">{{ __('nav.action.work_guide') }}</span>
                    <svg x-show="isMobile || open" class="ml-auto h-3 w-3 shrink-0 opacity-60" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5h5m0 0v5m0-5L10 14M9 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-3"/></svg>
                </a>
            @endif
            <a href="{{ route('settings.profile') }}" wire:navigate @click="if(isMobile) closeMobile()"
               :title="(isMobile || open) ? '' : '{{ __('nav.action.my_settings') }}'" class="sidebar-item {{ request()->routeIs('settings.profile') || request()->routeIs('settings.password') || request()->routeIs('settings.appearance') ? 'is-active' : '' }}" :class="{ 'sidebar-item-collapsed': !isMobile && !open }">
                <svg class="sidebar-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">{!! $icons['cog'] !!}</svg>
                <span x-show="isMobile || open" class="truncate">{{ __('nav.action.my_settings') }}</span>
            </a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" :title="(isMobile || open) ? '' : '{{ __('nav.action.logout') }}'"
                        class="sidebar-item w-[calc(100%-16px)] text-left" :class="{ 'sidebar-item-collapsed w-[calc(100%-12px)]': !isMobile && !open }">
                    <svg class="sidebar-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">{!! $icons['logout'] !!}</svg>
                    <span x-show="isMobile || open" class="truncate">{{ __('nav.action.logout') }}</span>
                </button>
            </form>
        </div>
    </aside>

    {{-- 메인 --}}
    <div class="flex min-w-0 flex-1 flex-col overflow-hidden">
        <header class="flex h-11 items-center border-b border-gray-200 bg-white px-3 shrink-0">
            <button type="button" @click="toggle()" class="flex h-8 w-8 items-center justify-center rounded text-gray-600 transition hover:bg-gray-100" aria-label="메뉴">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">{!! $icons['menu'] !!}</svg>
            </button>
            <div class="ml-2 truncate text-[13px] text-gray-700">{{ $breadcrumb }}</div>

            {{-- i18n Phase 0 — 언어 전환 (영어 활성 시만 노출) --}}
            @if ($localeEnEnabled)
                <form method="POST" action="{{ route('locale.update') }}" class="ml-auto flex items-center gap-0.5 rounded-md bg-gray-100 p-0.5">
                    @csrf
                    @foreach (['ko', 'en'] as $loc)
                        <button type="submit" name="locale" value="{{ $loc }}"
                                @class([
                                    'rounded px-2 py-0.5 text-[11px] font-medium transition',
                                    'text-white' => app()->getLocale() === $loc,
                                    'text-gray-500 hover:bg-gray-200' => app()->getLocale() !== $loc,
                                ])
                                @style(['background-color: var(--color-primary)' => app()->getLocale() === $loc])>
                            {{ __('nav.lang.'.$loc) }}
                        </button>
                    @endforeach
                </form>
            @endif
        </header>
        <main class="flex-1 overflow-auto bg-gray-50">
            {{ $slot }}
        </main>
    </div>
</div>

{{-- 전달 대기 인앱 알림 (영업·관리만) — 검차완료 새 도착 시 소리+토스트 --}}
@if ($user->isSuper() || $user->isSales() || $user->isManager())
    <livewire:notify.poll />
    <script>
        (function () {
            let ctx;
            function ensure() { if (!ctx) { try { ctx = new (window.AudioContext || window.webkitAudioContext)(); } catch (e) {} } return ctx; }
            // 브라우저 자동재생 정책: 첫 사용자 제스처에서 오디오 컨텍스트 활성화
            window.addEventListener('pointerdown', function once() {
                const c = ensure(); if (c && c.state === 'suspended') c.resume();
                window.removeEventListener('pointerdown', once);
            }, { once: true });
            window.__boardBeep = function () {
                // 중복 억제: wire:navigate 가 .window 리스너를 누적시켜 두 번 울리는 것 방지(정상 비프는 폴링당 ≤1회).
                const now = Date.now();
                if (window.__boardBeepLast && now - window.__boardBeepLast < 1000) return;
                window.__boardBeepLast = now;
                const c = ensure(); if (!c) return;
                if (c.state === 'suspended') c.resume();
                const o = c.createOscillator(), g = c.createGain();
                o.connect(g); g.connect(c.destination);
                o.type = 'sine'; o.frequency.value = 880;
                g.gain.setValueAtTime(0.0001, c.currentTime);
                g.gain.exponentialRampToValueAtTime(0.2, c.currentTime + 0.02);
                g.gain.exponentialRampToValueAtTime(0.0001, c.currentTime + 0.35);
                o.start(); o.stop(c.currentTime + 0.36);
            };
        })();
    </script>
@endif

{{-- 사진 확대 라이트박스 (전역) — 어떤 화면이든 이미지 클릭 시 open-lightbox 이벤트로 전체화면 확대 --}}
<div x-data="{ open: false, src: '' }"
     @open-lightbox.window="src = $event.detail.src; open = true"
     @keydown.escape.window="open = false"
     x-show="open" style="display:none; z-index:9999"
     class="fixed inset-0 flex items-center justify-center bg-black/80 p-4"
     @click="open = false">
    <img :src="src" @click.stop class="max-h-full max-w-full rounded object-contain shadow-2xl" alt="">
    <button type="button" @click="open = false" class="absolute right-4 top-4 rounded-full bg-white/15 px-3 py-1 text-lg font-bold text-white hover:bg-white/30">✕</button>
</div>

@fluxScripts
</body>
</html>

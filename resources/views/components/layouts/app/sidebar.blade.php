<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head')
</head>
<body class="min-h-screen bg-gray-50">
@php
    $user = auth()->user();
    $permLabel = $user->isSuper() ? '시스템관리자' : $user->roleLabel();
    $workGuideUrl = config('board.work_guide_url') ?: '';

    // 승격 대기 뱃지 (영업=본인 담당 / 관리·super=전체)
    $pendingPromo = 0;
    if ($user->isSuper() || $user->isSales() || $user->isManager()) {
        $pq = \App\Models\PromotionRequest::where('status', 'pending');
        if (! $user->canSeeAll()) {
            $pq->where('assigned_email', $user->respondAgentEmail());
        }
        $pendingPromo = $pq->count();
    }

    $routeName = request()->route()?->getName();
    $breadcrumb = match ($routeName) {
        'dashboard' => '대시보드',
        'listings' => '매입예정',
        'verdicts' => '바이어 회신',
        'portal' => '내 정산·미수·선적',
        'inspection' => '현지확인',
        'auction' => '경매/구매',
        'manage' => '관리자',
        'users' => '사용자 관리',
        'audit' => '감사 로그',
        'settings.profile' => '내 설정',
        'settings.password' => '비밀번호',
        'settings.appearance' => '화면 설정',
        default => '',
    };

    $can = fn ($roles) => $user->isSuper() || in_array($user->role, (array) $roles, true);
    $menuGroups = [
        ['key' => 'work', 'label' => '업무', 'items' => [
            ['label' => '매입예정 (영업)', 'href' => route('listings'), 'icon' => 'clipboard', 'active' => request()->routeIs('listings'), 'show' => $can(['sales', 'manager']), 'badge' => $pendingPromo ?: null],
            ['label' => '바이어 회신', 'href' => route('verdicts'), 'icon' => 'chat', 'active' => request()->routeIs('verdicts'), 'show' => $can(['sales', 'manager'])],
            ['label' => '내 정산·미수 (포털)', 'href' => route('portal'), 'icon' => 'wallet', 'active' => request()->routeIs('portal'), 'show' => $can(['sales', 'manager'])],
            ['label' => '현지확인', 'href' => route('inspection'), 'icon' => 'camera', 'active' => request()->routeIs('inspection'), 'show' => $can(['inspection', 'manager'])],
            ['label' => '경매/구매', 'href' => route('auction'), 'icon' => 'banknotes', 'active' => request()->routeIs('auction'), 'show' => $can(['auction', 'manager'])],
        ]],
        ['key' => 'manage', 'label' => '관리', 'items' => [
            ['label' => '관리자', 'href' => route('manage'), 'icon' => 'shield', 'active' => request()->routeIs('manage'), 'show' => $can(['manager'])],
        ]],
        ['key' => 'system', 'label' => '시스템', 'items' => [
            ['label' => '사용자 관리', 'href' => route('users'), 'icon' => 'user-group', 'active' => request()->routeIs('users'), 'show' => $user->isSuper()],
            ['label' => '감사 로그', 'href' => route('audit'), 'icon' => 'document', 'active' => request()->routeIs('audit'), 'show' => $user->isSuper()],
        ]],
    ];

    $icons = [
        'clipboard' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>',
        'chat' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.86 9.86 0 01-4-.8L3 20l1.3-3.9A7.96 7.96 0 013 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>',
        'wallet' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M21 12V7H5a2 2 0 010-4h14v4M3 5v14a2 2 0 002 2h16v-5M18 12a2 2 0 000 4h4v-4h-4z"/>',
        'camera' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/>',
        'banknotes' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 8h18v8H3V8zm9 4a2 2 0 11-4 0 2 2 0 014 0zm-9 0h.01M21 12h.01"/>',
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
        init() {
            const mq = window.matchMedia('(max-width: 767px)');
            this.isMobile = mq.matches;
            mq.addEventListener('change', e => { this.isMobile = e.matches; if (!e.matches) this.mobileOpen = false; });
        },
        toggle() {
            if (this.isMobile) { this.mobileOpen = !this.mobileOpen; }
            else { this.open = !this.open; localStorage.setItem('sidebar-open', this.open); }
        },
        closeMobile() { this.mobileOpen = false; }
     }">

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
                <span class="flex h-7 w-7 items-center justify-center rounded-md text-[11px] font-bold text-white shrink-0" style="background-color:var(--color-primary);">B</span>
                <span x-show="isMobile || open" x-transition.opacity class="min-w-0 flex-1 truncate text-[13px] font-medium text-white">매입·검차·경매</span>
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
                   :title="(isMobile || open) ? '' : '업무 가이드'" class="sidebar-item" :class="{ 'sidebar-item-collapsed': !isMobile && !open }">
                    <svg class="sidebar-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">{!! $icons['book'] !!}</svg>
                    <span x-show="isMobile || open" class="flex-1 truncate">업무 가이드</span>
                    <svg x-show="isMobile || open" class="ml-auto h-3 w-3 shrink-0 opacity-60" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5h5m0 0v5m0-5L10 14M9 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-3"/></svg>
                </a>
            @endif
            <a href="{{ route('settings.profile') }}" wire:navigate @click="if(isMobile) closeMobile()"
               :title="(isMobile || open) ? '' : '내 설정'" class="sidebar-item {{ request()->routeIs('settings.*') ? 'is-active' : '' }}" :class="{ 'sidebar-item-collapsed': !isMobile && !open }">
                <svg class="sidebar-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">{!! $icons['cog'] !!}</svg>
                <span x-show="isMobile || open" class="truncate">내 설정</span>
            </a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" :title="(isMobile || open) ? '' : '로그아웃'"
                        class="sidebar-item w-[calc(100%-16px)] text-left" :class="{ 'sidebar-item-collapsed w-[calc(100%-12px)]': !isMobile && !open }">
                    <svg class="sidebar-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">{!! $icons['logout'] !!}</svg>
                    <span x-show="isMobile || open" class="truncate">로그아웃</span>
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
        </header>
        <main class="flex-1 overflow-auto bg-gray-50">
            {{ $slot }}
        </main>
    </div>
</div>

@fluxScripts
</body>
</html>

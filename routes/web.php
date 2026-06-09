<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::get('/', fn () => redirect()->route('dashboard'))->name('home');

Route::middleware(['auth'])->group(function () {
    // 로그인 후 role 별 홈으로 분기
    Route::get('dashboard', function () {
        return redirect()->route(match (auth()->user()->role) {
            'inspection' => 'inspection',
            'auction' => 'auction',
            'manager' => 'manage',
            default => 'listings',
        });
    })->name('dashboard');

    // ── board 4뷰 ──
    Volt::route('listings', 'listings.index')->middleware('role:sales,manager')->name('listings');
    Volt::route('inspection', 'inspection.index')->middleware('role:inspection,manager')->name('inspection');
    Volt::route('auction', 'auction.index')->middleware('role:auction,manager')->name('auction');
    Volt::route('manage', 'manage.index')->middleware('role:manager')->name('manage');
    Volt::route('users', 'users.index')->middleware('role:manager')->name('users');

    // 설정
    Route::redirect('settings', 'settings/profile');
    Volt::route('settings/profile', 'settings.profile')->name('settings.profile');
    Volt::route('settings/password', 'settings.password')->name('settings.password');
    Volt::route('settings/appearance', 'settings.appearance')->name('settings.appearance');
});

require __DIR__.'/auth.php';

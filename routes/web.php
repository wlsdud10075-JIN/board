<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::get('/', fn () => redirect()->route('dashboard'))->name('home');

Route::middleware(['auth'])->group(function () {
    // 로그인 후 role 별 홈으로 분기
    Route::get('dashboard', function () {
        $user = auth()->user();
        if ($user->isSuper()) {
            return redirect()->route('manage');
        }

        return redirect()->route(match ($user->role) {
            'inspection' => 'inspection',
            'auction' => 'auction',
            'manager' => 'manage',
            default => 'listings',
        });
    })->name('dashboard');

    // ── board 4뷰 ──
    Volt::route('listings', 'listings.index')->middleware('role:sales,manager')->name('listings');
    Volt::route('verdicts', 'verdicts.index')->middleware('role:sales,manager')->name('verdicts');
    Volt::route('inspection', 'inspection.index')->middleware('role:inspection,manager')->name('inspection');
    Volt::route('auction', 'auction.index')->middleware('role:auction,manager')->name('auction');
    Volt::route('manage', 'manage.index')->middleware('role:manager')->name('manage');
    Volt::route('users', 'users.index')->middleware('super')->name('users');
    Volt::route('audit', 'audit.index')->middleware('super')->name('audit');

    // 설정
    Route::redirect('settings', 'settings/profile');
    Volt::route('settings/profile', 'settings.profile')->name('settings.profile');
    Volt::route('settings/password', 'settings.password')->name('settings.password');
    Volt::route('settings/appearance', 'settings.appearance')->name('settings.appearance');
});

require __DIR__.'/auth.php';

<?php

use App\Http\Controllers\BuyerViewController;
use App\Http\Controllers\PhotoController;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::get('/', fn () => redirect()->route('dashboard'))->name('home');

// 바이어 공개 차량 페이지 — 서명된(만료) 링크만(signed). 비인증. 영업이 "전체 보내기" 로 생성.
Route::get('v/{listing}', [BuyerViewController::class, 'show'])
    ->middleware('signed')
    ->name('buyer.view');

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
    // 전달 대기 — 검차완료(inspected) 차를 영업이 바이어에게 전달(→awaiting_buyer). SalesmanScope 자동격리.
    Volt::route('forwarding', 'forwarding.index')->middleware('role:sales,manager')->name('forwarding');
    Volt::route('verdicts', 'verdicts.index')->middleware('role:sales,manager')->name('verdicts');
    // 영업 포털 — car-erp 읽기 미러(재무) [연동: board-portal-api.md]
    Volt::route('portal', 'portal.index')->middleware('role:sales,manager')->name('portal');
    Volt::route('inspection', 'inspection.index')->middleware('role:inspection,manager')->name('inspection');
    // 구매확정/경매 집행 — 영업이 딜을 끝까지(accepted→won) 소유. SalesmanScope 가 영업은 본인 글만 자동격리.
    // auction role 은 데이터 호환용으로 잔존(경매역할 사실상 폐지, 2026-06-24 Jin).
    Volt::route('auction', 'auction.index')->middleware('role:sales,auction,manager')->name('auction');
    Volt::route('manage', 'manage.index')->middleware('role:manager')->name('manage');
    Volt::route('users', 'users.index')->middleware('super')->name('users');
    Volt::route('audit', 'audit.index')->middleware('super')->name('audit');
    Volt::route('admin/settings', 'admin.settings')->middleware('super')->name('admin.settings');

    // 사진 같은출처 스트리밍 (모바일 다중 공유 fetch 의 CORS 우회) — 스코프는 컨트롤러에서
    Route::get('photos/{photo}', [PhotoController::class, 'show'])->name('photos.show');

    // 설정
    Route::redirect('settings', 'settings/profile');
    Volt::route('settings/profile', 'settings.profile')->name('settings.profile');
    Volt::route('settings/password', 'settings.password')->name('settings.password');
    Volt::route('settings/appearance', 'settings.appearance')->name('settings.appearance');

    // i18n Phase 0 — 언어 전환 (영어는 기능설정에서 켜진 경우만 허용, users.locale 에 저장)
    Route::post('locale', function (Request $request) {
        $locale = $request->input('locale');
        if (in_array($locale, User::LOCALES, true)) {
            if ($locale === 'en' && ! Setting::get('locale_en_enabled', false)) {
                $locale = 'ko';
            }
            $user = $request->user();
            $user->locale = $locale;
            $user->save();
        }

        return back();
    })->name('locale.update');
});

require __DIR__.'/auth.php';

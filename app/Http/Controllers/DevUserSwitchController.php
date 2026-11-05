<?php

namespace App\Http\Controllers;

use App\Models\BoardAuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * 로컬 전용 「사용자 전환」(2026-11-04 Jin — 업무일지 결재를 일반·팀장·상무 계정으로 바꿔 가며 보려고 창 4개를 띄우던 불편).
 * 시스템관리자가 다른 계정으로 바로 들어가 보고, [원래 계정으로]로 돌아온다. 전환 뒤에도 원래 super 를 세션(`dev_switch_from`)에 들고 있어 계속 바꿀 수 있다.
 *
 * 🔒 `config('board.dev_user_switch')` 가 꺼져 있으면 404 — 기본값은 APP_ENV=local 일 때만 켜진다(운영 박스 = production → 없음).
 *    운영에서 남의 계정으로 들어가는 기능은 감사·책임 추적을 깨뜨리므로 켜지 말 것.
 * 전환·복귀는 감사 행(`dev_user_switch`, Jin 「board 모든 행위는 기록」).
 */
class DevUserSwitchController extends Controller
{
    private function guard(Request $request): User
    {
        abort_unless(config('board.dev_user_switch'), 404);
        $origin = User::find($request->session()->get('dev_switch_from')) ?? $request->user();
        abort_unless($origin && $origin->isSuper() && $origin->is_active, 403);

        return $origin;
    }

    public function switch(Request $request): RedirectResponse
    {
        $origin = $this->guard($request);
        $target = User::where('is_active', true)->findOrFail((int) $request->input('user_id'));

        if ($target->id === $origin->id) {
            return $this->back($request);
        }
        $from = $request->user();
        Auth::login($target);
        $request->session()->regenerate();
        $request->session()->put('dev_switch_from', $origin->id);
        $this->log($origin, $from, $target);

        return redirect()->route('dashboard');
    }

    public function back(Request $request): RedirectResponse
    {
        $origin = $this->guard($request);
        $from = $request->user();
        Auth::login($origin);
        $request->session()->regenerate();
        $request->session()->forget('dev_switch_from');
        if ($from && $from->id !== $origin->id) {
            $this->log($origin, $from, $origin);
        }

        return redirect()->route('dashboard');
    }

    private function log(User $origin, ?User $from, User $to): void
    {
        BoardAuditLog::create([
            'user_id' => $origin->id, 'purchase_listing_id' => null, 'action' => 'dev_user_switch', 'field' => 'dev_user_switch',
            'old_value' => $from?->name, 'new_value' => $to->name, 'ip_address' => BoardAuditLog::currentIp(),
        ]);
    }
}

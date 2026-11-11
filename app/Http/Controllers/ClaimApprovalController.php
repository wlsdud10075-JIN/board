<?php

namespace App\Http\Controllers;

use App\Models\Claim;
use App\Models\Setting;
use App\Models\User;
use App\Services\ClaimService;
use App\Services\SsancarMediaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * 클레임 — 대표가 알림톡 버튼(서명 링크)으로 보고 승인/반려 (2026-11-10 Jin, ERP `PayoutApprovalController` 미러).
 *
 * 인가 = URL 서명(`signed`). 로그인 없음 — 서명이 (claim id + 승인자 u + 회차 rev + 5일 만료)에 묶여 인가를 대신한다.
 * ① show(GET) = 표시만(상태 변경 X — 카톡 링크 프리페치가 승인 못 함) ② decide(POST) 만 처리, 폼 action 은 진입 때 새로 발급한 60분 서명 URL
 * ③ `Claim::canDecide`(pending_ceo + 승인자 + 회차) 가 1회용·재클릭·옛 링크 가드, 트랜잭션 lockForUpdate ④ 감사(user·IP).
 */
class ClaimApprovalController extends Controller
{
    public function show(Request $request, Claim $claim, ClaimService $svc, SsancarMediaService $ssancar)
    {
        return response()->view('claim-approval.show', $this->viewData($request, $claim, $ssancar, null));
    }

    public function decide(Request $request, Claim $claim, ClaimService $svc, SsancarMediaService $ssancar)
    {
        $user = User::find((int) $request->query('u'));
        $rev = (int) $request->query('rev');
        $action = (string) $request->input('action');
        $reason = trim((string) $request->input('reason', ''));

        if ($user === null || ! $claim->canDecide($user, $rev)) {
            return response()->view('claim-approval.result', ['claim' => $claim->fresh(), 'result' => 'error', 'message' => __('claims.link_not_decidable')], 410);
        }
        if (! in_array($action, ['approve', 'reject'], true)) {
            abort(422);
        }

        try {
            $after = $svc->decide($claim, $user, $rev, $action, $reason, $request->ip());
        } catch (\DomainException $e) {
            if ($e->getMessage() === 'reason_required') {
                return response()->view('claim-approval.show', $this->viewData($request, $claim, $ssancar, __('claims.reject_reason_required')), 422);
            }

            return response()->view('claim-approval.result', ['claim' => $claim->fresh(), 'result' => 'error', 'message' => __('claims.link_not_decidable')], 410);
        }

        // 중간 결재자 승인 = 다음 칸으로 넘어감(최종 아님) — 결과 화면에 누구에게 넘어갔는지.
        $message = $after->isPendingCeo() && ($n = $after->currentStep())
            ? __('claims.passed_to_next', ['title' => $n->title, 'name' => $n->approver?->name ?? '—'])
            : null;

        return view('claim-approval.result', ['claim' => $after, 'result' => $action, 'message' => $message]);
    }

    private function viewData(Request $request, Claim $claim, SsancarMediaService $ssancar, ?string $error): array
    {
        $user = User::find((int) $request->query('u'));
        $rev = (int) $request->query('rev');
        $decidable = $claim->canDecide($user, $rev);
        $decideUrl = $decidable
            ? URL::temporarySignedRoute('claim.approve.decide', now()->addMinutes(Claim::DECIDE_MINUTES), ['claim' => $claim->id, 'u' => $user->id, 'rev' => $rev])
            : null;

        return [
            'claim' => $claim->load(['files', 'salesman', 'requester', 'approver', 'listing', 'steps.approver', 'steps.actor']),
            'user' => $user,
            'decidable' => $decidable,
            'decideUrl' => $decideUrl,
            'media' => self::inspectionMedia($claim, $ssancar),
            'company' => Setting::get('sidebar_brand', 'HeymanBoard') ?: 'HeymanBoard',
            'error' => $error,
        ];
    }

    /**
     * 우리 검차 미디어 — board 검차 사진(그 차가 board 에 있으면) + ssancar.com CDN(검차글 영상·사진, 번호판/VIN 교차매칭).
     * ssancar 미설정·미매칭 = 빈 배열(가용성 우선). 워터마크 있는 그대로(원본 수신은 별도 미착수 항목).
     *
     * @return array{board: list<array{url:string,video:bool}>, videos: list<array<string,?string>>, photos: list<string>}
     */
    public static function inspectionMedia(Claim $claim, SsancarMediaService $ssancar): array
    {
        $board = [];
        $listing = $claim->listing;
        if ($listing) {
            $board = $listing->photos()->get()->map(fn ($p) => ['url' => $p->shareUrl(), 'video' => $p->isVideo()])->values()->all();
        }
        try {
            $s = $listing ? $ssancar->mediaFor($listing) : $ssancar->fetchByVehicle($claim->vin, $claim->vehicle_number);
        } catch (\Throwable) {
            $s = ['videos' => [], 'photos' => []];
        }

        return ['board' => $board, 'videos' => array_values($s['videos'] ?? []), 'photos' => array_values($s['photos'] ?? [])];
    }
}

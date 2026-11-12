<?php

namespace App\Http\Controllers;

use App\Models\TransportRequest;
use App\Models\TransportRequestRecipient;
use App\Services\TransportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * 탁송 배차 — 업체 기사가 알림톡 버튼(서명 링크)으로 열어 [배차완료]/[배차거절] (2026-11-12 Jin, ClaimApprovalController 미러).
 *
 * 인가 = URL 서명(`signed`, 수신자 r + 5일). 로그인 없음.
 * ① show(GET) = 표시만(카톡 프리페치가 결정 못 함) ② decide(POST) = 진입 때 새로 발급한 60분 서명 URL, 선착순(lockForUpdate)
 * ③ 영업·관리 수신자(sales|manager)는 같은 페이지를 **보기 전용**으로 ④ CSRF 제외 = bootstrap/app.php(a/transport/*).
 */
class TransportDecisionController extends Controller
{
    public function show(Request $request, TransportRequest $req)
    {
        return response()->view('transport.show', $this->viewData($request, $req, null));
    }

    public function decide(Request $request, TransportRequest $req, TransportService $svc)
    {
        $r = TransportRequestRecipient::find((int) $request->query('r'));
        $action = (string) $request->input('action');
        if (! $req->canDecide($r)) {
            return response()->view('transport.result', ['req' => $req->fresh(), 'result' => 'error', 'message' => $this->notDecidableMessage($req->fresh())], 410);
        }
        if (! in_array($action, ['accept', 'reject'], true)) {
            abort(422);
        }
        try {
            $after = $svc->decide($req, $r, $action, $request->input('reason'), $request->input('name'), $request->ip());
        } catch (\DomainException) {
            return response()->view('transport.result', ['req' => $req->fresh(), 'result' => 'error', 'message' => $this->notDecidableMessage($req->fresh())], 410);
        }

        return view('transport.result', ['req' => $after, 'result' => $action, 'message' => null]);
    }

    private function notDecidableMessage(TransportRequest $req): string
    {
        if ($req->isDecided()) {
            return __('transport.page.already', ['result' => $req->statusLabel(), 'who' => $req->decidedRecipient?->displayName() ?? '—', 'at' => $req->decided_at?->timezone(config('app.timezone'))->format('m-d H:i') ?? '—']);
        }

        return __('transport.page.expired');
    }

    private function viewData(Request $request, TransportRequest $req, ?string $error): array
    {
        $r = TransportRequestRecipient::find((int) $request->query('r'));
        $decidable = $req->canDecide($r);
        $decideUrl = $decidable
            ? URL::temporarySignedRoute('transport.decide', now()->addMinutes(TransportRequest::DECIDE_MINUTES), ['req' => $req->id, 'r' => $r->id])
            : null;

        return [
            'req' => $req->load(['files', 'listing', 'requester', 'decidedRecipient']),
            'recipient' => $r,
            'decidable' => $decidable,
            'readonly' => $r !== null && ! $r->canDecide(),
            'decideUrl' => $decideUrl,
            'company' => TransportService::companyLabel(),
            'error' => $error,
        ];
    }
}

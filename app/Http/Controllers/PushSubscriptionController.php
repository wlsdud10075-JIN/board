<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 웹푸시 구독 저장/해제 — 브라우저 `PushSubscription.toJSON()` 모양을 그대로 받는다.
 * 같은 endpoint 가 다시 오면 **그 행을 현재 사용자로 갱신**한다(기기 공용 PC 에서 다른 사람이 로그인한 경우 —
 * 이전 사용자의 결과가 그 기기로 가면 안 된다).
 */
class PushSubscriptionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'url', 'max:1000'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'contentEncoding' => ['nullable', 'string', 'max:20'],
        ]);

        $sub = PushSubscription::updateOrCreate(
            ['endpoint_hash' => PushSubscription::hashEndpoint($data['endpoint'])],
            [
                'user_id' => $request->user()->id,
                'endpoint' => $data['endpoint'],
                'p256dh' => $data['keys']['p256dh'],
                'auth_token' => $data['keys']['auth'],
                'content_encoding' => $data['contentEncoding'] ?? null,
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
            ]
        );

        return response()->json(['ok' => true, 'id' => $sub->id]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:1000']]);

        $deleted = PushSubscription::where('endpoint_hash', PushSubscription::hashEndpoint($data['endpoint']))
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->json(['ok' => true, 'deleted' => $deleted]);
    }
}

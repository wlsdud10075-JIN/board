<?php

namespace App\Services;

use App\Jobs\SendOfferToBuyer;
use App\Models\PurchaseListing;
use App\Models\Scopes\SalesmanScope;

/**
 * 바이어 전달(inspected → awaiting_buyer) 단일 경로 — 영업 /forwarding 화면에서 호출.
 *
 * 이전엔 검차 save() 안에 있던 로직. `inspected` 상태 도입으로 전달 주체를 영업으로 분리
 * (검차=검차완료까지, 영업=사진 확인 후 바이어 전달). respond.io 폴러·webhook 도 향후 같은 경로.
 *
 * 채널: respond_contact_id 있고 강제수동 아니면 auto(respond.io), 아니면 manual(영업이 외부 메신저).
 * 충돌가드: 같은 컨택트에 이미 auto 회신대기 차가 있으면 보류(auto=바이어당 1대 직렬화) → 'conflict' 반환.
 */
class OfferForwardService
{
    /**
     * @return array{status:string, conflict_vehicle:?string}
     *                                                        status = sent_auto | sent_manual | conflict | not_inspected
     */
    public function forward(int $listingId, bool $forceManual = false): array
    {
        // findOrFail 이 SalesmanScope 안에서 동작 → 영업은 본인 글만 전달(IDOR 차단).
        $l = PurchaseListing::findOrFail($listingId);

        if ($l->status !== 'inspected') {
            return ['status' => 'not_inspected', 'conflict_vehicle' => null];
        }

        $channel = 'auto';
        if (empty($l->respond_contact_id) || $forceManual) {
            $channel = 'manual';
        } else {
            // (가) 같은 바이어(컨택트)에 이미 auto 회신대기 차 → 전달 보류(직렬화).
            //     (자동은 한 바이어당 1대 — respond.io 폴링이 '어느 차'인지 명확하도록)
            $conflict = PurchaseListing::withoutGlobalScope(SalesmanScope::class)
                ->where('respond_contact_id', $l->respond_contact_id)
                ->where('status', 'awaiting_buyer')
                ->where('verdict_channel', 'auto')
                ->where('id', '!=', $l->id)
                ->first();
            if ($conflict) {
                return ['status' => 'conflict', 'conflict_vehicle' => $conflict->vehicle_number];
            }
        }

        $l->status = 'awaiting_buyer';
        $l->buyer_verdict = 'pending';
        $l->verdict_channel = $channel;
        $l->save();   // 가드: inspected→awaiting_buyer

        // outbound — 자동채널이면 바이어에게 최종금액(USD)+공개사진 전송 (Job 가드: 컨택트/설정).
        SendOfferToBuyer::dispatch($l->id)->afterCommit();

        return ['status' => 'sent_'.$channel, 'conflict_vehicle' => null];
    }
}

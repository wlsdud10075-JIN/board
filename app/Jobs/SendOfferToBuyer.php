<?php

namespace App\Jobs;

use App\Models\IntegrationEvent;
use App\Models\PurchaseListing;
use App\Models\Scopes\SalesmanScope;
use App\Services\ExchangeRateService;
use App\Services\RespondIoService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * 연동 A (outbound) — 바이어에게 최종금액(USD) + 외관 사진/영상 자동 전송.
 *
 * 트리거: 현지확인 "바이어 전달" 시 dispatch(afterCommit).
 * 가드: respond_contact_id 있어야 + respond.io 설정돼야(안전밸브 미설정 no-op).
 * 개인정보(§28): share_to_buyer=true 사진만 전송(외관만, 서류/번호판 제외).
 * 금액: 바이어는 KRW 안 봄 — final_price(KRW)를 USD 로 환산해 전송.
 */
class SendOfferToBuyer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $listingId) {}

    public function handle(RespondIoService $respond, ExchangeRateService $rates): void
    {
        if (! $respond->configured()) {
            return;   // 안전밸브
        }

        $l = PurchaseListing::withoutGlobalScope(SalesmanScope::class)
            ->with('photos')->find($this->listingId);

        if (! $l || empty($l->respond_contact_id)) {
            return;   // 컨택트 미연결이면 전송 불가
        }

        // 최종금액 → USD 환산 (바이어용, KRW 미노출)
        $rate = $rates->krwPerUsd() ?: (int) config('board.default_krw_per_usd');
        $usd = $l->final_price ? (int) round($l->final_price / max(1, $rate)) : null;

        $text = $usd
            ? '[SSANCAR] 견적 안내 — 최종 금액 USD '.number_format($usd).' (차량+배송). 차량 사진/영상 확인 부탁드립니다.'
            : '[SSANCAR] 차량 사진/영상 보내드립니다.';

        $okText = $respond->sendText($l->respond_contact_id, $text);

        // 외관 공개 사진만 전송
        $photos = $l->photos->where('share_to_buyer', true);
        $sentPhotos = 0;
        foreach ($photos as $p) {
            if ($respond->sendAttachment($l->respond_contact_id, $p->isVideo() ? 'video' : 'image', $p->shareUrl())) {
                $sentPhotos++;
            }
        }

        IntegrationEvent::create([
            'direction' => 'outbound',
            'target' => 'respond_io',
            'event_type' => 'send_offer',
            'purchase_listing_id' => $l->id,
            'request_payload' => ['contact_id' => $l->respond_contact_id, 'usd' => $usd, 'photos' => $sentPhotos],
            'response_status' => $okText ? 200 : 500,
            'response_body' => $okText ? "text+{$sentPhotos}p" : 'text_failed',
            'error' => $okText ? null : 'sendText failed',
        ]);
    }
}

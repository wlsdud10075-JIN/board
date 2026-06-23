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
 * 금액: 현지확인에서 확정한 offer_currency(USD/EUR/KRW)로 환산해 전송(미설정 시 USD 폴백).
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

        // 최종금액 → 확정 통화(offer_currency)로 환산 (바이어용)
        $offer = $l->offerAmount($rates->krwPerUsd(), $rates->krwPerEur());

        $sym = match ($offer['currency'] ?? null) {
            'EUR' => 'EUR ', 'KRW' => 'KRW ', 'USD' => 'USD ', default => '',
        };
        $text = $offer
            ? '[SSANCAR] 견적 안내 — 최종 금액 '.$sym.number_format($offer['amount']).' (차량+배송). 차량 사진/영상 확인 부탁드립니다.'
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
            'request_payload' => ['contact_id' => $l->respond_contact_id, 'offer' => $offer, 'photos' => $sentPhotos],
            'response_status' => $okText ? 200 : 500,
            'response_body' => $okText ? "text+{$sentPhotos}p" : 'text_failed',
            'error' => $okText ? null : 'sendText failed',
        ]);
    }
}

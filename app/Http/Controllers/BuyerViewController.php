<?php

namespace App\Http\Controllers;

use App\Models\PurchaseListing;
use App\Models\Scopes\SalesmanScope;
use App\Models\Setting;
use App\Services\ExchangeRateService;
use App\Services\SsancarMediaService;
use App\Support\QuoteCardImage;
use Illuminate\Support\Facades\URL;

/**
 * 바이어 공개 차량 페이지 — 서명된(만료) 링크로만 접근(signed 미들웨어).
 * 영업이 "전체 보내기" 로 만든 한 링크에 그 차의 사진·영상·견적이 모인다(영상 N개여도 링크 1개).
 * 노출 최소화(§28): 차량번호·견적·공유허용(share_to_buyer) 검차 사진/영상만. 서류·소유자·정산정보 제외.
 * SalesmanScope 우회: 서명이 곧 인가(비인증 바이어). 소프트삭제 차는 findOrFail 로 404.
 */
class BuyerViewController extends Controller
{
    public function show(int $listing, ExchangeRateService $rates, SsancarMediaService $ssancar)
    {
        $l = PurchaseListing::withoutGlobalScope(SalesmanScope::class)->findOrFail($listing);

        $rates->refreshIfStale();
        $usd = $rates->krwPerUsd() ?: (int) config('board.default_krw_per_usd');
        $eur = $rates->krwPerEur() ?: (int) config('board.default_krw_per_eur');
        $breakdown = $l->offerBreakdown($usd, $eur);   // 전달드로어 견적 카드와 동일 계산(가격 일치)

        // 공유 허용(share_to_buyer) 검차 사진/영상만. photos() 가 이미 kind=inspection → 서류 제외(§28).
        $media = $l->photos()->where('share_to_buyer', true)->get()
            ->map(fn ($p) => ['url' => $p->shareUrl(), 'video' => $p->isVideo()])
            ->values();

        // ssancar CDN 미디어 — 검차팀이 ssancar 에 올린 영상(Bunny embed)·사진을 링크째 첨부(용량문제 회피).
        // (A) ssancar id 보유 시 직접매칭 / (B) 없으면(엔카 등) vin·번호판 교차매칭 폴백.
        // 미설정/미매칭/실패 = 빈 배열(가용성 우선). §28 토글 없이 그대로 전송(2026-06-30 Jin).
        $ssancarMedia = $ssancar->mediaFor($l);

        return view('buyer.view', [
            'listing' => $l,
            'breakdown' => $breakdown,
            'media' => $media,
            'ssancarMedia' => $ssancarMedia,
            'company' => Setting::get('buyer_company_name', 'SSANCAR') ?: 'SSANCAR',
            // OG 미리보기 — 카톡/왓츠앱 링크 unfurl 시 견적카드 이미지. 만료없는 서명(재크롤 안 깨짐).
            // ⚠️ `v`(수정시각) = 캐시버스트. 카톡은 og:image 를 URL 단위로 캐시 → 통화·금액 바뀌어도
            // URL 고정이면 옛 카드 계속 노출. 견적 변경 시 updated_at 이 바뀌어 URL·카드가 갱신된다.
            'cardUrl' => URL::signedRoute('buyer.card', ['listing' => $l->id, 'v' => $l->updated_at?->timestamp ?? 0]),
        ]);
    }

    /** 견적 카드 PNG (OG 미리보기 이미지). 만료없는 서명 링크로만 접근. */
    public function card(int $listing, ExchangeRateService $rates, QuoteCardImage $card)
    {
        $l = PurchaseListing::withoutGlobalScope(SalesmanScope::class)->findOrFail($listing);

        $rates->refreshIfStale();
        $usd = $rates->krwPerUsd() ?: (int) config('board.default_krw_per_usd');
        $eur = $rates->krwPerEur() ?: (int) config('board.default_krw_per_eur');
        [$carStr, $shipStr, $totalStr] = $this->quoteStrings($l->offerBreakdown($usd, $eur));

        $company = Setting::get('buyer_company_name', 'SSANCAR') ?: 'SSANCAR';
        $png = $card->render($carStr, $shipStr, $totalStr, $company);

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=3600',   // 재견적 반영 위해 짧게(카톡은 URL 단위 자체캐시)
        ]);
    }

    /** breakdown → [Car, Shipping, Total] 통화기호 포함 문자열(바이어페이지 표시와 동일 규칙). */
    private function quoteStrings(?array $b): array
    {
        $sym = ['KRW' => '₩', 'USD' => '$', 'EUR' => '€'][$b['currency'] ?? 'USD'] ?? '';
        $fmt = fn ($n) => $n === null ? '—' : $sym.number_format($n);

        return [$fmt($b['car'] ?? null), $fmt($b['shipping'] ?? null), $fmt($b['total'] ?? null)];
    }
}

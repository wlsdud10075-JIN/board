<?php

namespace App\Jobs;

use App\Models\IntegrationEvent;
use App\Models\PurchaseListing;
use App\Models\Scopes\SalesmanScope;
use App\Services\ExchangeRateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

/**
 * 연동 B — board `won` 차량을 car-erp 로 단방향 push (HMAC 서명).
 *
 * 가드: won 상태 + car_erp_vehicle_id null + car_erp.base_url 설정(안전밸브).
 * 멱등: car_erp_vehicle_id null 가드 + car-erp 측 vehicle_number 사전조회(중복=스킵, 기존 id 반환).
 * 성공: 응답 vehicle_id → car_erp_vehicle_id 저장 → won→synced 전이.
 * 보내는 스펙(payload·HMAC 권위) = SKILLS.md §12. 받는 스펙 = car-erp docs.
 */
class SyncWonListingToCarErp implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /**
     * @param  bool  $resync  이미 동기화된 차를 **다시 밀어** 빈 판매 필드를 채우게 한다(2026-08-18).
     *                        car-erp 가 멱등 경로에서 fill-if-empty 를 하므로, 급하게 매입가만 보낸 차에
     *                        나중에 판매가·통화·환율을 넣어 보내면 그때 채워진다.
     *                        🚫 `car_erp_vehicle_id` 를 지워서 되돌리는 방식은 쓰지 말 것 —
     *                        전송이 실패하면 "미연동 + won" 상태로 남아 그 차가 /auction 목록에 되살아난다.
     * @param  bool  $attachmentsOnly  **사진만 추가**하러 가는 재전송(2026-09-11). 판매측·바이어를 비워 보내
     *                                 car-erp 가 fill-if-empty 를 건너뛰게 한다 — 사진 한 장 올렸을 뿐인데
     *                                 ERP 판매가가 채워지고 `sale_date=now()` 가 찍혀 **채권 유예 기산점이 오늘**이
     *                                 되는 걸 막는다. 첨부는 멱등 분기에서도 dedup 보강되므로 그대로 붙는다.
     *                                 ⚠️ 항상 `resync: true` 와 함께 쓴다(단독으로는 의미 없음).
     */
    public function __construct(public int $listingId, public bool $resync = false, public bool $attachmentsOnly = false) {}

    /** 재시도 백오프(초): 1분 → 5분 → 15분 → 30분 */
    public function backoff(): array
    {
        return [60, 300, 900, 1800];
    }

    public function handle(): void
    {
        $base = config('services.car_erp.base_url');
        $secret = config('services.car_erp.hmac_secret');

        // 안전밸브: 수신측(car-erp) 미설정이면 no-op — master 배포해도 안 터짐
        if (empty($base) || empty($secret)) {
            return;
        }

        // 큐 컨텍스트엔 Auth 없음 → SalesmanScope 명시 제거(방어)
        $l = PurchaseListing::withoutGlobalScope(SalesmanScope::class)->find($this->listingId);

        // 멱등/상태 가드: 이미 동기화됐거나 won 이 아니면 스킵.
        // resync 는 그 두 조건을 뚫는다 — 대신 **won/synced 만** 허용(draft 를 밀어 보내지 않게).
        if (! $l) {
            return;
        }
        if ($this->resync) {
            if (! in_array($l->status, ['won', 'synced'], true)) {
                return;
            }
        } elseif ($l->status !== 'won' || $l->car_erp_vehicle_id !== null) {
            return;
        }

        // 🚨 첨부 전용은 **이미 ERP 에 있는 차에만** 쓴다. 아직 없는 차에 쓰면 수신측이 멱등 분기가 아니라
        //    **신규 생성 경로**를 타서, 판매측이 비어 있는(= 「일반재고」로 앉는) 차량이 원장에 만들어진다.
        //    그 경우는 첨부를 늦게 올리더라도 정상 구매확정 전송을 기다리는 게 맞다.
        if ($this->attachmentsOnly && $l->car_erp_vehicle_id === null) {
            return;
        }

        // 영업이 board 에 올린 차량 첨부(외관 사진 + 서류) — 키만 전송(바이트 아님, 공유 S3).
        // car-erp 가 받아 차량 첨부탭(최대 10건)에 행 생성.
        // ℹ️ **1회 발사가 아니다**(2026-09-11 정정) — 수신측은 멱등 분기(이미 있는 차)에서도 첨부를 보강하고,
        //    target 키가 source 로 결정적이라 **목록을 통째로 다시 보내도 새 것만** 붙는다(중복 없음).
        //    그래서 synced 이후 추가는 `/listings` 드로어 [사진 추가] → `attachmentsOnly` 재전송으로 한다.
        //    ⚠️ **삭제는 전파되지 않는다** — board 에서 지워도 ERP 에 복사된 사진은 남는다(추가만 양방 일치).
        $attachments = $l->salesAttachments->map(fn ($p) => [
            's3_path' => $p->s3_path,
            'original_name' => $p->original_name,
            'kind' => $p->kind,
            'sort' => $p->sort,
        ])->values()->all();

        // ── v3 금액 분해 (매입=KRW 원장 / 판매=확정통화). 환율은 sync 시점 스냅샷(관리가 ERP서 미세조정). ──
        $snap = app(ExchangeRateService::class)->snapshot();
        $usdR = (int) ($snap['USD'] ?? 0) ?: (int) config('board.default_krw_per_usd');
        $eurR = (int) ($snap['EUR'] ?? 0) ?: (int) config('board.default_krw_per_eur');

        $carCostKrw = $l->carCostKrw($usdR, $eurR);
        // 매입가(구입금액) — Model A(2026-07-06): 원가 그대로(할인 미반영). car-erp 부가세마진 =
        //   purchase_price × 0.09 라 원가여야 정합하고, 할인은 sell-side(판매가)에만 태운다.
        //   ⚠️ 셀프검차매입만 예외 — 매도비가 차값에 포함돼 있어 빼야 이중계상이 안 된다(모델이 단일 판정).
        $purchasePriceKrw = $l->purchasePriceKrw($usdR, $eurR);
        $sellingFeeKrw = $l->sellingFeeKrw($usdR, $eurR);   // 입력값 우선, 없으면 고정값(회사 부담)
        $carPriceKrw = $l->carPriceKrw($usdR, $eurR);   // 판매가 = 원가 − 관례할인 − 차감액 (매도비 제외)

        // 판매 통화/환율 — 기존 경로는 `offerAmount()`(현지확인 확정, final_price 기반).
        // ⚠️ 셀프검차매입은 **자동계산을 안 해서 final_price 가 비어 있다** → offerAmount() 가 null 을 준다.
        //    그러면 sale_currency·sale_exchange_rate 가 안 실려 car-erp 가 판매 pre-fill 을 통째로 보류한다
        //    (수신측: `sale_price>0 && rate>0` 일 때만 저장). 컬럼에서 직접 읽는다.
        if ($l->isSelfInspection()) {
            $saleCurrency = $l->offer_currency ?: 'KRW';
            $saleRate = (int) ($l->offer_rate ?: 1);
        } else {
            $offer = $l->offerAmount($usdR, $eurR);         // 판매 통화/환율(현지확인 확정)
            $saleCurrency = $offer['currency'] ?? null;
            $saleRate = $offer['rate'] ?? null;
            // 급해서 매입가만 넣고 보낸 차 — 견적 씬을 안 타 final_price 가 없으면 offerAmount() 가 null 을 준다.
            // 나중에 영업이 매입예정 드로어에서 채운 컬럼으로 폴백한다(2026-08-18).
            // ⚠️ 환율이 없으면 car-erp 가 **판매가를 통째로 보류**한다(fill-if-empty 도 마찬가지) → 세트로 실어야 한다.
            if (! $saleCurrency || ! $saleRate) {
                $saleCurrency = $l->offer_currency ?: $saleCurrency;
                $saleRate = (int) ($l->offer_rate ?: 0) ?: $saleRate;
            }
        }
        // 판매가(차량 판매분) = 차량금액 → 판매통화. car-erp sale_price = 판매통화 기준.
        // 셀프검차매입은 견적 씬이 없어 파생계산의 근거(할인율·차감액)가 없다 → 영업이 적은 값을 그대로 쓴다.
        $salePrice = $l->sale_price !== null
            ? (float) $l->sale_price
            : (($carPriceKrw !== null && $saleRate)
                ? ($saleCurrency === 'KRW' ? $carPriceKrw : round($carPriceKrw / max(1, $saleRate), 2))
                : null);
        // 운임비 — 셀프검차매입은 판매통화로 직접 적으므로 환산 없이 그대로. 그 외는 shipping_usd(USD원가)를 환산.
        // car-erp `transport_fee` 는 **판매통화 기준**이라 USD raw 를 그냥 넣으면 EUR 딜에서 부풀어 오른다.
        $transportFee = $l->transport_fee !== null ? (float) $l->transport_fee : null;
        if ($transportFee === null && $l->shipping_usd !== null && $saleRate) {
            $transportKrw = $l->shipping_usd * $usdR;
            $transportFee = $saleCurrency === 'KRW' ? $transportKrw : round($transportKrw / max(1, $saleRate), 2);
        }

        // 재고매입(바이어 미정) — 판매측을 **전부 비운다**. 여기가 유일한 강제 지점이다.
        //
        // ① 판매가를 화면에서 안 적어도 위 파생식(`carPriceKrw ÷ 환율`)이 **차값을 판매가로 만들어** 보낸다.
        //    car-erp 재고 분류는 `sale_price` 하나로 갈리므로(> 0 이면 「선적전」), 그대로 두면
        //    바이어도 없는 차가 선적전 재고에 앉는다. 「일반재고」로 가려면 sale_price 가 비어야 한다.
        // ② `buyer_id` 도 여기서 지운다 — car-erp 재전송 경로(`fillEmptyFields`)는 **락 검사를 하지 않는다**.
        //    화면에서 바이어를 못 고르게 막는 것만으로는 부족하다: /manage 재전송·/listings 판매가 후보완·
        //    savePayee 재발사가 전부 이 Job 을 다시 태우기 때문에, **컬럼에 남아 있는 값**이 나중에 실려
        //    나갈 수 있다. 그러면 락 걸린 바이어가 재고매입을 우회로로 삼는다(car-erp 2026-09-08 회신 Q3).
        //    ⚠️ 재고매입 차에 바이어를 붙이는 건 **ERP 화면에서** 한다 — 거기엔 게이트가 있다.
        //
        // 🖼 **첨부 전용 재전송도 같은 처리를 쓴다**(2026-09-11) — 사진만 올렸는데 ERP 판매가가 채워지고
        //    `sale_date` 가 오늘로 찍히면(그래서 진행상태가 「판매중」이 되고 채권 독촉이 오늘부터 시작되면)
        //    버튼 이름과 하는 일이 달라진다. 비워 보내면 수신측이 `missing_exchange_rate`·`buyer_not_sent`
        //    로 건너뛰고 첨부만 붙인다 — 재고매입이 이미 쓰고 있는 길이라 검증돼 있다.
        if ($l->buyer_undecided || $this->attachmentsOnly) {
            $buyerId = null;
            $consigneeId = null;
            $salePrice = null;
            $saleCurrency = null;
            $saleRate = null;
            $transportFee = null;
        } else {
            $buyerId = $l->car_erp_buyer_id;
            $consigneeId = $l->car_erp_consignee_id;
        }

        // board 는 VIN 을 모른다(NICE 조회=car-erp). 매칭키 = vehicle_number, NICE 입력 = owner_name.
        $payload = [
            'contract_version' => 5,   // v5: v4 + buyer_undecided(재고매입). car-erp SUPPORTED_VERSIONS=[1..5], 2026-09-08 배포됨
            'vehicle_number' => $l->vehicle_number,
            'owner_name' => $l->owner_name,
            'source' => $l->source,
            'final_price' => $l->final_price,   // v2 호환 유지(car-erp: purchase_price_krw ?? final_price)
            // car-erp 영업 매칭 이메일 — 오버라이드(car_erp_salesman_email) 있으면 그걸, 없으면 로그인 이메일.
            'salesman_email' => $l->creator?->car_erp_salesman_email ?: $l->creator?->email,
            'car_erp_salesman_id' => $l->creator?->car_erp_salesman_id,
            'c_no' => $l->c_no,
            'payee_name' => $l->payee_name,
            'payee_bank' => $l->payee_bank,
            'payee_account' => $l->payee_account,
            // v4 매도비 계좌 (매입가 계좌와 별개 — 판매자와 다른 대상)
            'selling_fee_payee_name' => $l->selling_fee_payee_name,
            'selling_fee_payee_bank' => $l->selling_fee_payee_bank,
            'selling_fee_payee_account' => $l->selling_fee_payee_account,
            'attachments' => $attachments,
            // v3 매입측(KRW)
            'purchase_price_krw' => $purchasePriceKrw,
            'selling_fee_krw' => $sellingFeeKrw,
            // v3 판매측(판매통화 pre-fill — 관리 편집)
            'transport_fee' => $transportFee,
            'sale_price' => $salePrice,
            'sale_currency' => $saleCurrency,
            'sale_exchange_rate' => $saleRate,
            // v3 바이어/컨사이니(경매/구매 드롭다운 선택, 미선택=null)
            'buyer_id' => $buyerId,
            'consignee_id' => $consigneeId,
            // v5 재고매입 — car-erp `vehicles.buyer_undecided`(뱃지 「바이어 미정(투기 매입)」).
            // ⚠️ `buyer_id: null` 만으로는 car-erp 에서 **레거시 무바이어 차와 구분이 안 된다**(뱃지 없음).
            //    바이어가 실제로 붙으면 car-erp `Vehicle::saving` 이 알아서 내린다 → board 뒤처리 불필요.
            'buyer_undecided' => (bool) $l->buyer_undecided,
        ];

        // 서명 대상 = 직렬화된 raw body (car-erp 가 동일 바이트로 검증)
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha256', $body, $secret);

        $response = Http::timeout(20)
            ->withBody($body, 'application/json')
            ->withHeaders(['X-Board-Signature' => 'sha256='.$signature])
            ->post(rtrim($base, '/').'/api/internal/purchase-sync');

        // append-only 로그 — payee_account 는 민감값이라 마스킹 후 기록(§6e)
        $logged = $payload;
        if ($logged['payee_account'] !== null) {
            $logged['payee_account'] = '***';
        }
        if ($logged['selling_fee_payee_account'] !== null) {
            $logged['selling_fee_payee_account'] = '***';
        }
        IntegrationEvent::create([
            'direction' => 'outbound',
            'target' => 'car_erp',
            'event_type' => 'purchase_sync',
            'purchase_listing_id' => $l->id,
            'request_payload' => $logged,
            'response_status' => $response->status(),
            'response_body' => mb_substr((string) $response->body(), 0, 2000),
            'error' => $response->failed() ? 'HTTP '.$response->status() : null,
        ]);

        if ($response->failed()) {
            throw new \RuntimeException("purchase-sync 실패 (listing {$l->id}): HTTP ".$response->status());
        }

        $vehicleId = $response->json('vehicle_id');
        if (empty($vehicleId)) {
            throw new \RuntimeException("purchase-sync 응답에 vehicle_id 없음 (listing {$l->id})");
        }

        $l->car_erp_vehicle_id = (int) $vehicleId;
        if ($l->status === 'won') {
            $l->status = 'synced';   // won→synced (TRANSITIONS 허용). 이미 synced 면 그대로 둔다(resync).
        }
        $l->save();
    }
}

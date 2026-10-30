<?php

namespace App\Models;

use App\Jobs\SendInspectionQueueEvent;
use App\Jobs\SyncWonListingToCarErp;
use App\Models\Concerns\NormalizesRegion;
use App\Models\Scopes\SalesmanScope;
use App\Services\BoardAudit;
use App\Support\Money;
use App\Support\SsancarInspection;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

#[ScopedBy([SalesmanScope::class])]
class PurchaseListing extends Model
{
    use NormalizesRegion, SoftDeletes;

    protected $fillable = [
        'created_by_user_id', 'source', 'origin', 'region', 'c_no', 'ssancar_ref',
        'respond_conversation_id', 'respond_contact_id', 'encar_id',
        'vehicle_number', 'owner_name', 'vin',
        'expected_price', 'expected_price_currency', 'car_cost', 'discount_rate', 'sale_discount_amount',
        'selling_fee', 'sale_price', 'transport_fee', 'transport_fee_usd', 'offer_rate_usd', 'offer_rate_eur', 'shipping_usd',
        'final_price', 'offer_currency', 'offer_rate', 'encar_url', 'encar_dealer',
        'auction_venue', 'lot_number', 'status', 'buyer_verdict', 'verdict_channel',
        'buyer_name', 'payee_name', 'payee_bank', 'payee_account',
        'selling_fee_payee_name', 'selling_fee_payee_bank', 'selling_fee_payee_account',
        'payee_suggestions', 'payee_extraction_status', 'payee_extraction_at',
        'seller_company', 'seller_ceo', 'seller_phone',   // 매입처(딜러) — 2026-10-29 ④
        'remitter_name', 'remitter_id', 'remitter_synced_at', 'passport_ocr_status', 'passport_ocr_at', 'passport_suggestions',   // 송금인(여권/ID) — 2026-10-29 ②
        'inspection_memo', 'inspection_note', 'inspection_request', 'lock_at', 'car_erp_vehicle_id',
        'car_erp_buyer_id', 'car_erp_buyer_name', 'car_erp_consignee_id', 'buyer_undecided',
    ];

    protected function casts(): array
    {
        return [
            'expected_price' => 'integer',
            'car_cost' => 'integer',
            'discount_rate' => 'decimal:2',
            'sale_discount_amount' => 'integer',
            'selling_fee' => 'integer',
            'sale_price' => 'decimal:2',
            'transport_fee' => 'decimal:2',
            'transport_fee_usd' => 'decimal:2',
            'offer_rate_usd' => 'integer',
            'offer_rate_eur' => 'integer',
            'shipping_usd' => 'integer',
            'final_price' => 'integer',
            'offer_rate' => 'integer',
            'payee_account' => 'encrypted',   // 계좌번호 at-rest 암호화 (§6e)
            'selling_fee_payee_account' => 'encrypted',   // 매도비 계좌번호 at-rest 암호화
            'payee_suggestions' => 'encrypted:json',   // 계좌 후보(확정 전) — 실계좌라 같이 암호화(매입처·휴대폰 후보도 여기)
            'seller_phone' => 'encrypted',   // 딜러 휴대폰 at-rest 암호화 — 계좌와 같은 취급(2026-10-28 Jin, 전화 미보유 원칙의 범위한정 예외)
            'remitter_id' => 'encrypted',   // 여권/ID 카드 문서번호 — RRN 급(2026-10-29 ②)
            'passport_suggestions' => 'encrypted:json',   // OCR 후보(문서번호 포함)
            'remitter_synced_at' => 'datetime',
            'passport_ocr_at' => 'datetime',
            'payee_extraction_at' => 'datetime',
            'lock_at' => 'datetime',
            'inspection_started_at' => 'datetime',
            'inspection_skipped_at' => 'datetime',
            'inspection_work_date' => 'date',   // ssancar 가 정한 검차 예정일(P1, 2026-10-29) — board 는 계산하지 않는다
            'inspection_urgent_requested_at' => 'datetime',
            'inspection_urgent_decided_at' => 'datetime',
            'ssancar_media_seen_at' => 'datetime',
            'ssancar_checked_at' => 'datetime',
            'ssancar_insp_photos' => 'integer',
            'ssancar_insp_videos' => 'integer',
            'ssancar_stock_photos' => 'integer',
            'car_erp_vehicle_id' => 'integer',
            'car_erp_buyer_id' => 'integer',
            'car_erp_consignee_id' => 'integer',
            'buyer_undecided' => 'boolean',
        ];
    }

    // ─────────────────────── 금액 (§6) ───────────────────────

    /** 차값을 KRW 로 환산 — car_cost 는 expected_price_currency 통화(엔카=KRW). 차값 자체는 불변, 계산용. */
    public function carCostKrw(?int $krwPerUsd = null, ?int $krwPerEur = null): ?int
    {
        return Money::toKrw(
            $this->car_cost,
            $this->expected_price_currency,
            $krwPerUsd ?? (int) config('board.default_krw_per_usd'),
            $krwPerEur ?? (int) config('board.default_krw_per_eur'),
        );
    }

    /** 차값 표시(통화기호 포함) — 가져온 통화 그대로. */
    public function carCostDisplay(): string
    {
        return Money::display($this->car_cost, $this->expected_price_currency);
    }

    /**
     * 판매가(바이어 차값, KRW) = 차값(원가 KRW) − 관례할인(%) − 차감액.
     * 매도비 제외 = 회사 부담(엑셀·ERP Model A, 2026-07-06). 차감액=바이어 추가흥정(sell-side).
     * 입력 없으면 null.
     */
    /**
     * 매도비(KRW) — **사람이 적은 값만**. 안 적었으면 null(= ERP 로 안 보냄).
     *
     * 🚫 예전엔 안 적으면 고정값 440,000(`config('board.sales_fee')`)을 **차값 있는 모든 차에** 실어 보냈다
     *    (2026-09-11 Jin 폐지). 매도비가 없는 거래에도 붙어 나갔고, 영업은 그 숫자를 본 적이 없다.
     * ⚠️ `$krwPerUsd`·`$krwPerEur` 는 이제 안 쓰지만 **시그니처는 유지**한다 — 호출부가 여럿이고
     *    매도비는 언제나 KRW 입력이라 환산이 필요 없다.
     */
    public function sellingFeeKrw(?int $krwPerUsd = null, ?int $krwPerEur = null): ?int
    {
        return $this->selling_fee !== null ? (int) $this->selling_fee : null;
    }

    /**
     * ERP 로 보낼 매입가(KRW) — **연동 B `purchase_price_krw` 단일 출처**. **차값 그대로**다.
     *
     * 🚫 셀프검차매입에서 `차값 − 매도비` 를 하던 예외를 **제거**했다(2026-09-11 Jin).
     *    그 계산은 "차값 칸에 매도비가 포함돼 들어온다"는 전제에 기대고 있었는데, 전제 자체를 바꿨다 —
     *    **차값 칸엔 차값만, 매도비 칸엔 매도비만** 적고 둘을 각각 그대로 ERP 에 준다.
     *    ⚠️ 그래서 셀프검차 차값 칸에 **매도비까지 합친 금액을 적으면 안 된다**(매입가가 그만큼 커진다).
     */
    public function purchasePriceKrw(?int $krwPerUsd = null, ?int $krwPerEur = null): ?int
    {
        return $this->carCostKrw($krwPerUsd, $krwPerEur);
    }

    public function carPriceKrw(?int $krwPerUsd = null, ?int $krwPerEur = null): ?int
    {
        $cost = $this->carCostKrw($krwPerUsd, $krwPerEur);
        if ($cost === null) {
            return null;
        }
        $discount = (int) round($cost * ((float) $this->discount_rate / 100));

        return $cost - $discount - (int) $this->sale_discount_amount;
    }

    /** 배송금액을 KRW 로 환산 (임시환율 — 슬라이스2에서 라이브 환율로 대체). */
    public function shippingKrw(?int $krwPerUsd = null): ?int
    {
        if ($this->shipping_usd === null) {
            return null;
        }
        $rate = $krwPerUsd ?? (int) config('board.default_krw_per_usd');

        return $this->shipping_usd * $rate;
    }

    /** 최종금액(Total, KRW) = 차량금액 + 배송금액(KRW 환산). 둘 중 하나라도 없으면 차량금액만/ null. */
    public function totalKrw(?int $krwPerUsd = null, ?int $krwPerEur = null): ?int
    {
        $car = $this->carPriceKrw($krwPerUsd, $krwPerEur);
        if ($car === null) {
            return null;
        }

        return $car + (int) ($this->shippingKrw($krwPerUsd) ?? 0);
    }

    /**
     * 바이어 견적·판매가 — final_price(KRW)를 offer_currency 로 환산.
     * offer_rate(확정 시점 스냅샷) 우선, 없으면 인자 라이브 환율. KRW면 그대로.
     * 반환: ['currency'=>str, 'amount'=>int, 'rate'=>int] | null.
     */
    public function offerAmount(?int $krwPerUsd = null, ?int $krwPerEur = null): ?array
    {
        // 🚨 통화를 **고르지 않은 차는 null** — 종전 `?: 'USD'` 폴백이 「아무도 안 적은 판매가」를 USD 로 지어내
        //    ERP 로 보냈다(2026-10-16 heymanboard 17두0923: 8,700,000 ÷ 1,324 = 6,571 USD, 전수 12대가 ERP 에서 수기 정정됨).
        //    Jin: 기본 통화 없음 — 사람이 고른 통화만 쓴다. 미선택 = 견적 없음(판매 필드 비워 보냄).
        if ($this->final_price === null || ! $this->offer_currency) {
            return null;
        }
        $cur = $this->offer_currency;
        if ($cur === 'KRW') {
            return ['currency' => 'KRW', 'amount' => $this->final_price, 'rate' => 1];
        }
        $rate = $this->offer_rate ?: match ($cur) {
            'EUR' => $krwPerEur ?? (int) config('board.default_krw_per_eur'),
            default => $krwPerUsd ?? (int) config('board.default_krw_per_usd'),
        };

        return ['currency' => $cur, 'amount' => (int) round($this->final_price / max(1, $rate)), 'rate' => (int) $rate];
    }

    /**
     * 판매가 표시 문자열 — 확정 통화(offer_currency) 기준(바이어에게 전달된 그 통화·금액).
     * 통화 미확정이면 KRW(final_price). auction/구매 화면 표시용 — final_price(KRW)만 보이던 버그 교정.
     * 반환: "$8,500" / "€7,200" / "8,500,000원" | null(가격 미정).
     */
    public function offerDisplay(?int $krwPerUsd = null, ?int $krwPerEur = null): ?string
    {
        // 통화 미확정(offer_currency 없음) → 저장된 최종금액을 원화로 그대로 표시(파생 없음). KRW 도 원화 표시.
        if ($this->final_price !== null && (! $this->offer_currency || $this->offer_currency === 'KRW')) {
            return number_format($this->final_price).__('common.won_currency');
        }
        $offer = $this->offerAmount($krwPerUsd, $krwPerEur);
        if ($offer === null) {
            return null;
        }

        return Money::display($offer['amount'], $offer['currency']);
    }

    /**
     * 바이어 견적 3줄 분해(숫자) — 견적 카드/전달드로어/바이어 공개페이지 공용(단일 출처, drift 방지).
     * Total = offerAmount(final_price 스냅샷, 불변) / Car = carPriceKrw ÷ rate / Shipping = Total−Car(잔차 흡수).
     * 나눗셈 두 번 금지 → 합 == Total 보장. final_price 없으면 null(가격 협의중).
     * 반환: ['currency'=>str, 'car'=>?int, 'shipping'=>?int, 'total'=>int] | null.
     */
    public function offerBreakdown(?int $krwPerUsd = null, ?int $krwPerEur = null): ?array
    {
        $offer = $this->offerAmount($krwPerUsd, $krwPerEur);
        if ($offer === null) {
            return null;
        }
        $rate = max(1, (int) $offer['rate']);
        $total = (int) $offer['amount'];
        $carKrw = $this->carPriceKrw($krwPerUsd, $krwPerEur);
        $car = $carKrw === null ? null : (int) round($carKrw / $rate);
        $shipping = $car === null ? null : $total - $car;

        return ['currency' => $offer['currency'], 'car' => $car, 'shipping' => $shipping, 'total' => $total];
    }

    // ─────────────────────── 상태머신 ───────────────────────

    public const STATUSES = [
        'draft', 'inspected', 'awaiting_buyer', 'accepted', 'rejected', 'won', 'failed', 'synced',
    ];

    /**
     * 매입예정 목록 탭 (2026-09-11 Jin) — 상태를 묶어 **한 번에 한 덩어리만** 읽는다.
     * 예전엔 `latest()->get()` 으로 전량을 읽었다. `synced`(ERP 전환완료)는 **영원히 쌓이기만 하는** 통이라
     * 그대로 두면 랜딩이 매년 느려진다.
     *
     * 🚨 **여기 없는 상태는 「전체」 탭에서만 보인다** — 상태를 새로 만들면 반드시 한 탭에 넣을 것.
     *    가드 = `test_every_status_belongs_to_a_tab`.
     */
    public const TAB_STATUSES = [
        'active' => ['draft', 'inspected', 'awaiting_buyer', 'accepted', 'won'],   // 진행중(기본)
        'draft' => ['draft'],
        'inspected' => ['inspected'],
        'awaiting_buyer' => ['awaiting_buyer'],
        'accepted' => ['accepted'],
        'won' => ['won'],
        'synced' => ['synced'],
        'closed' => ['rejected', 'failed'],   // 종료 = 거절 + 유찰/취소
    ];

    /** 탭 순서 — 「전체」는 상태 필터가 없어 TAB_STATUSES 에 없다(맨 앞 고정). 라벨은 `listings.tabs.*`. */
    public const TABS = ['all', 'active', 'draft', 'inspected', 'awaiting_buyer', 'accepted', 'won', 'synced', 'closed'];

    /** 드롭다운/필터용 정적 라벨(출처 무관 통합). 출처별 표기는 statusLabel() 사용. */
    public const STATUS_LABELS = [
        'draft' => '현지확인 대기',
        'inspected' => '검차완료 (전달대기)',
        'awaiting_buyer' => '회신대기',
        'accepted' => '수락 (구매/경매대기)',
        'rejected' => '거절',
        'won' => '낙찰/구매확정',
        'failed' => '유찰/취소',
        'synced' => 'ERP 전환완료',
    ];

    // ─────────────────────── 유입 카테고리 (origin) ───────────────────────
    // 화면 표시·분류용. 내부 매입방법(source 엔카/경매)은 ORIGIN_SOURCE 로 도출.
    // ssancar 는 경매+즉시구매가 섞여 있어 단일 source 불가 → origin 으로 분리(연동B/car-erp 무영향).

    /** origin 값 → 한글 라벨 (추가폼 토글·리스트 뱃지) */
    public const ORIGIN_LABELS = [
        'ssancar_auction' => '싼카-경매',
        'ssancar_stock' => '싼카-재고',
        'ssancar_checking' => '싼카-체킹',
        'encar' => '엔카',
        'auction' => '경매',
        'self_inspection' => '셀프검차매입',
    ];

    /**
     * 셀프검차매입 — 영업이 직접 검차한 차. ssancar 검차글(영상)이 없어서
     * draft→inspected→awaiting_buyer 자동전이가 영영 안 걸린다(그래서 차가 갇혔다).
     * 등록 즉시 accepted 로 만들어 /auction(경매·구매)에서 바로 마무리한다.
     */
    public const ORIGIN_SELF_INSPECTION = 'self_inspection';

    /** origin → 내부 매입방법(source). 워크플로/시간잠금/연동B 는 이 source 로 동작. */
    public const ORIGIN_SOURCE = [
        'ssancar_auction' => 'auction',   // 싼카경매 = 경매(잠금·낙찰/유찰)
        'ssancar_stock' => 'encar',       // 싼카재고 = 즉시구매
        'ssancar_checking' => 'encar',    // 싼카체킹 = 즉시구매
        'encar' => 'encar',
        'auction' => 'auction',
        'self_inspection' => 'encar',     // 셀프검차매입 = 즉시구매(경매 10:00 잠금 대상 아님)
    ];

    public static function sourceForOrigin(string $origin): string
    {
        return self::ORIGIN_SOURCE[$origin] ?? 'encar';
    }

    /**
     * 현지확인대기(draft)인 차가 **왜 전달대기로 안 넘어갔는지** 한 줄 (jin 2026-10-13).
     * 현지확인 행과 매입예정 드로어가 같은 이 메서드를 쓴다 — 두 화면이 다르게 말하면 안 된다.
     * 재료는 폴러가 남긴 마지막 판정(`ssancar_checked_at`·소스별 개수)뿐이다. 화면에서 ssancar 를 직접 묻지 않는다.
     *
     * @return array{key:string, params:array, alert?:bool}|null lang 키(inspection.hold_*) + 치환값. draft 아니면 null.
     *   `alert` = 영업이 손을 써야 하는 경우(검차 보류·지역 미지정) — 화면이 빨간 굵은 글씨 + ⚠ 로 띄운다(2026-10-24 Jin).
     */
    public function ssancarHoldHint(): ?array
    {
        if ($this->status !== 'draft') {
            return null;
        }

        // 검차 큐 웹훅(ssancar.com, 2026-10-23)이 알려 준 진행이 폴러 문구보다 먼저다 — 보류(사유) > 완료 > 검차중.
        if ($this->inspection_skipped_at !== null) {
            $code = (string) $this->inspection_skip_reason;
            $label = __('inspection.skip_reason.'.$code);

            return ['key' => 'inspection.hold_skipped', 'alert' => true, 'params' => [
                'reason' => ($label === 'inspection.skip_reason.'.$code) ? $code : $label,   // 모르는 코드는 원문
                'memo' => $this->inspection_skip_memo ? ' · '.$this->inspection_skip_memo : '',
            ]];
        }
        if ($this->ssancar_inspected_wr_id !== null) {
            return ['key' => 'inspection.hold_inspected', 'params' => ['name' => $this->ssancar_inspector_name ?: '—']];
        }
        // 지역이 비면 ssancar 큐에서 검차원 배정이 안 된다(「지역 미지정」 버킷) — 영업이 지역을 넣게 안내(2026-10-23 Jin).
        //   검차 연동이 켜진 박스에서만(꺼진 박스는 지역이 배정과 무관하다). 지역을 넣으면 upsert 재발송으로 배정 대상이 된다.
        if (trim((string) $this->region) === '' && SsancarInspection::configured() && $this->inspection_started_at === null) {
            return ['key' => 'inspection.hold_no_region', 'alert' => true, 'params' => []];
        }
        if ($this->inspection_started_at !== null) {
            return ['key' => 'inspection.hold_started', 'params' => [
                'name' => $this->ssancar_inspector_name ?: '—',
                'at' => $this->inspection_started_at->timezone(config('app.timezone'))->format('m-d H:i'),
            ]];
        }

        $configured = (string) config('services.ssancar_media.base_url') !== '' && (string) config('services.ssancar_media.api_key') !== '';
        if (! $configured || ! config('board.ssancar_auto_forward')) {
            return ['key' => 'inspection.hold_auto_off', 'params' => []];
        }

        // 폴러 에이지아웃과 같은 식 — 등록 N일 지나도 미디어를 한 번도 못 봤으면 폴링 대상에서 빠진다.
        $maxAge = (int) config('board.ssancar_poll_max_age_days', 3);
        $agedOut = $this->ssancar_media_seen_at === null && $this->created_at !== null && $this->created_at->lt(now()->subDays($maxAge));
        $days = $this->created_at ? (int) $this->created_at->diffInDays(now()) : 0;

        if ($this->ssancar_checked_at === null) {
            return $agedOut
                ? ['key' => 'inspection.hold_aged_out', 'params' => ['days' => $days]]
                : ['key' => 'inspection.hold_pending', 'params' => []];
        }

        $at = $this->ssancar_checked_at->timezone(config('app.timezone'))->format('m-d H:i');
        $ip = (int) $this->ssancar_insp_photos;
        $iv = (int) $this->ssancar_insp_videos;
        $sp = (int) $this->ssancar_stock_photos;

        if ($ip > 0 && $iv === 0 && $sp === 0) {
            return ['key' => 'inspection.hold_photos_only', 'params' => ['photos' => $ip, 'at' => $at]];
        }
        if ($ip + $iv + $sp === 0) {
            return $agedOut
                ? ['key' => 'inspection.hold_aged_out', 'params' => ['days' => $days]]
                : ['key' => 'inspection.hold_no_media', 'params' => ['at' => $at]];
        }

        // 전이 조건(검차 영상 or 재고 사진)은 충족했는데 아직 draft — 다음 폴링(2분)에서 넘어간다.
        return ['key' => 'inspection.hold_advancing', 'params' => ['at' => $at]];
    }

    /** 바이어에게 보여줄 것이 하나라도 있나 — ssancar 사진·영상 + board 에 올린 검차 사진. 「사진만으로 전달대기」 버튼 조건. */
    public function hasAnyForwardMedia(): bool
    {
        return ((int) $this->ssancar_insp_photos + (int) $this->ssancar_insp_videos + (int) $this->ssancar_stock_photos) > 0
            || $this->photos()->exists();
    }

    /** 목록·카드에 보일 바이어 이름 — 전달 단계 이름 우선, 없으면 `/auction` 에서 고른 ERP 바이어 이름(2026-10-24). */
    public function buyerDisplay(): ?string
    {
        return ($this->buyer_name ?: null) ?? ($this->car_erp_buyer_name ?: null);
    }

    public function originLabel(): string
    {
        if (isset(self::ORIGIN_LABELS[$this->origin])) {
            return __('domain.origin.'.$this->origin);
        }

        return $this->isAuction() ? __('domain.origin.auction') : __('domain.origin.encar');
    }

    public function originBadge(): string
    {
        if ($this->isSelfInspection()) {
            return 'badge-purple';
        }

        return str_starts_with((string) $this->origin, 'ssancar')
            ? 'badge-blue'
            : ($this->isAuction() ? 'badge-auction' : 'badge-encar');
    }

    public function isSelfInspection(): bool
    {
        return $this->origin === self::ORIGIN_SELF_INSPECTION;
    }

    /** 계좌 추출 대기를 몇 분까지 인정할지 — 이 시간이 지나면 `pending` 이어도 확정을 막지 않는다. */
    public const PAYEE_EXTRACT_WAIT_MINUTES = 5;

    /**
     * 계좌 추출이 **아직 진행 중인가**.
     *
     * 🚨 status 만 보면 안 된다 — Job 이 조용히 죽으면 `pending` 이 영원히 남아 구매확정이 영영 막힌다.
     *    2026-09-10 운영에서 실제로 발생했다(배포 직후 켠 설정을 워커가 못 읽어 Job 이 무동작 종료).
     *    그래서 **시각으로도 끊는다** — 사람이 손을 못 쓰게 되는 상태를 만들지 않는 게 우선이다.
     */
    /** 화면용 — 큐에 들어가 아직 결과가 없다(시간 무관). 확정 잠금은 아래 `payeeExtractionInFlight()`(5분 창) 그대로. */
    /**
     * Invoice(송금인) 상태 — 목록 「Invoice」 칸·드로어 탭 점·상태 줄이 전부 이 판정을 쓴다(2026-10-29 ②).
     * none = 미입력 · entered = 이름 있음(ERP 미전송) · synced = ERP 전송됨. 추가 쿼리 0(컬럼 2개만 본다).
     */
    public function invoiceState(): string
    {
        if (trim((string) $this->remitter_name) === '') {
            return 'none';
        }

        return $this->remitter_synced_at !== null ? 'synced' : 'entered';
    }

    /** 여권 OCR 이 5분 넘게 pending — 화면 문구만 「오래 걸림」(계좌추출 `payeeExtractionSlow` 와 같은 규칙). */
    public function passportOcrSlow(): bool
    {
        return $this->passport_ocr_status === 'pending' && $this->passport_ocr_at !== null
            && $this->passport_ocr_at->lte(now()->subMinutes(self::PAYEE_EXTRACT_WAIT_MINUTES));
    }

    /** 송금인 이름 정규화 — 문서 표기(라틴 대문자), 연속 공백 1개, ≤100(ERP). 빈 값은 null. */
    public static function normalizeRemitterName(?string $s): ?string
    {
        $s = trim((string) preg_replace('/\s+/u', ' ', (string) $s));

        return $s === '' ? null : mb_substr(mb_strtoupper($s), 0, 100);
    }

    /** 문서번호 정규화 — 대문자 영숫자만(공백·하이픈 제거), ≤64(ERP). 빈 값은 null. */
    public static function normalizeRemitterId(?string $s): ?string
    {
        $s = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $s));

        return $s === '' ? null : substr($s, 0, 64);
    }

    /** 추출 후보 총수(계좌·소유자·매입처·연락처) — 드로어의 「후보 있음/없음」 판정은 전부 이 수를 본다. */
    public function payeeSuggestionCount(): int
    {
        $s = (array) ($this->payee_suggestions ?? []);

        return count((array) ($s['candidates'] ?? [])) + count((array) ($s['registration_owners'] ?? []))
            + count((array) ($s['seller_companies'] ?? [])) + count((array) ($s['seller_phones'] ?? []));
    }

    public function payeeExtractionPending(): bool
    {
        return $this->payee_extraction_status === 'pending';
    }

    /**
     * 화면용 — pending 인데 5분이 지났다 = 큐가 밀렸거나 워커가 느리다. 종전엔 이 상태를 「실패」로 그려 사용자가 포기했는데
     * Job 은 나중에 끝나 후보가 저장된다(2026-10-28 Jin 실사용: 새로고침해 드로어를 다시 여니 후보가 있었다).
     * 「실패」는 Job 이 실제로 failed 를 찍었을 때만.
     */
    public function payeeExtractionSlow(): bool
    {
        return $this->payeeExtractionPending()
            && ($this->payee_extraction_at === null || $this->payee_extraction_at->lte(now()->subMinutes(self::PAYEE_EXTRACT_WAIT_MINUTES)));
    }

    public function payeeExtractionInFlight(): bool
    {
        return $this->payee_extraction_status === 'pending'
            && $this->payee_extraction_at !== null
            && $this->payee_extraction_at->gt(now()->subMinutes(self::PAYEE_EXTRACT_WAIT_MINUTES));
    }

    /**
     * 셀프검차매입 제외 — 현지확인 화면처럼 "검차 대상" 만 봐야 하는 쿼리용.
     * origin 은 nullable 이라 `where('origin','!=',...)` 만 쓰면 NULL 행이 같이 사라진다.
     */
    public function scopeWhereNotSelfInspection(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereNull('origin')->orWhere('origin', '!=', self::ORIGIN_SELF_INSPECTION);
        });
    }

    /** 허용 전이: from => [to, ...] (manager override 는 우회) */
    public const TRANSITIONS = [
        'draft' => ['inspected'],
        'inspected' => ['awaiting_buyer'],
        'awaiting_buyer' => ['accepted', 'rejected', 'inspected'],   // inspected = 재견적(전달대기로 복귀)
        'accepted' => ['won', 'failed'],
        'won' => ['synced'],
        'rejected' => [],
        'failed' => [],
        'synced' => [],
    ];

    /** 등록 후 수정 불가한 식별값 (관리자도 불가) */
    public const IDENTITY_LOCKED = ['vehicle_number', 'vin'];

    /** manager override 허용 플래그 — 관리자 화면에서 저장 직전 set (try/finally) */
    public bool $allowManagerOverride = false;

    /** 감사 대상 필드 — 변경 시 board_audit_logs 자동 기록(옵저버). 출처 무관 단일 경로. */
    public const AUDITED = [
        'source', 'origin', 'status', 'buyer_verdict', 'verdict_channel', 'buyer_name', 'c_no', 'ssancar_ref', 'encar_id',
        'respond_conversation_id',
        'expected_price', 'expected_price_currency', 'final_price', 'car_cost', 'discount_rate', 'sale_discount_amount', 'shipping_usd',
        // 판매측 금액(셀프검차 드로어·판매가 후보완) — 2026-10-13 추가. 그 전엔 「판매가 누가 넣었나」를 board 가 답할 수 없었다(59오5691).
        'sale_price', 'offer_currency', 'offer_rate', 'selling_fee', 'transport_fee', 'transport_fee_usd', 'offer_rate_usd', 'offer_rate_eur', 'car_erp_buyer_id',
        'owner_name', 'payee_name', 'payee_bank', 'payee_account',
        // 매도비 입금 계좌·컨사이니 — 2026-10-24 ERP 감사 대조로 추가(돈이 나가는 계좌인데 기록이 없었다. 계좌는 *** 마스킹).
        'selling_fee_payee_name', 'selling_fee_payee_bank', 'selling_fee_payee_account', 'car_erp_consignee_id',
        // 매입처(딜러) — 2026-10-29 ④. 휴대폰은 *** 마스킹(BoardAudit::MASKED).
        'seller_company', 'seller_ceo', 'seller_phone',
        // 송금인(여권/ID) — 2026-10-29 ②. 문서번호는 *** 마스킹.
        'remitter_name', 'remitter_id',
        'vehicle_number', 'vin', 'car_erp_vehicle_id', 'region', 'inspection_note', 'inspection_request', 'inspection_memo',
        'encar_url', 'encar_dealer', 'auction_venue', 'lot_number',
        // 재고매입 = 바이어 없이 ERP 로 넘어간다 → 나중에 반드시 "누가 왜"를 묻게 된다.
        'buyer_undecided',
    ];

    protected static function booted(): void
    {
        static::updating(function (PurchaseListing $listing) {
            // (1) 식별값(차량번호·VIN) 변경 차단.
            //     예외: 관리자 override + 아직 car-erp 미연동(car_erp_vehicle_id null) 차량만 = 오타 정정 허용.
            foreach (self::IDENTITY_LOCKED as $col) {
                if ($listing->isDirty($col)) {
                    $canCorrect = $listing->allowManagerOverride && $listing->car_erp_vehicle_id === null;
                    if (! $canCorrect) {
                        throw new \RuntimeException(
                            "식별값({$col})은 수정할 수 없습니다.".
                            ($listing->car_erp_vehicle_id !== null ? ' (이미 car-erp 연동된 차량)' : '')
                        );
                    }
                }
            }

            // (2) 상태 전이 검증 (manager override 시 우회)
            if ($listing->isDirty('status') && ! $listing->allowManagerOverride) {
                $from = $listing->getOriginal('status');
                $to = $listing->status;

                if (! in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
                    throw new \RuntimeException("허용되지 않은 상태 전이: {$from} → {$to}");
                }

                // (3) accepted(경매/구매 진입)는 바이어 수락 전제
                if ($to === 'accepted' && $listing->buyer_verdict !== 'accepted') {
                    throw new \RuntimeException('바이어 수락 후에만 경매/구매로 진입할 수 있습니다.');
                }
            }
        });

        // 연동 B — won 진입 시 car-erp push Job 큐잉 (auction conclude + manage override 공통 단일 지점).
        // afterCommit: save 트랜잭션 커밋 후 dispatch. 멱등/안전밸브는 Job 내부 가드.
        static::updated(function (PurchaseListing $listing) {
            if ($listing->wasChanged('status') && $listing->status === 'won') {
                SyncWonListingToCarErp::dispatch($listing->id)->afterCommit();
            }
        });

        // 감사 — 등록 시점 초기값. 종전엔 updated 만 기록해 「등록할 때 넣은 판매가·환율·바이어」가 로그에 없었다
        // (59오5691, 2026-10-13 Jin). 등록 1행 + 채워진 AUDITED 필드 각 1행(∅ → 값), action='create'.
        static::created(function (PurchaseListing $listing) {
            BoardAudit::logCreation($listing, Auth::id());
        });

        // 감사 — 변경된 AUDITED 필드를 board_audit_logs 에 자동 기록(수정 출처 무관 단일 경로:
        // 관리자/검차/경매/연동 Job). user_id = 로그인 사용자, 없으면 null(시스템).
        static::updated(function (PurchaseListing $listing) {
            $changed = array_values(array_intersect(self::AUDITED, array_keys($listing->getChanges())));
            if ($changed === []) {
                return;
            }
            $original = [];
            foreach ($changed as $f) {
                $original[$f] = $listing->getOriginal($f);
            }
            BoardAudit::logChanges($listing, $original, $changed, Auth::id());
        });

        // 검차 큐 연동(ssancar.com, 2026-10-23) — **판정식 하나**로 등록·철회. draft 를 벗어나는 길이 화면 여러 곳
        // (영업 삭제·관리 삭제·셀프검차 전환·수동 검차완료·사진으로 전달대기·관리자 override)이라 버튼마다 붙이면 하나는 빠진다.
        // ⚠️ `saveQuietly()` 는 이 훅을 안 탄다(지금 5곳은 계좌추출 칸뿐이라 무관) — 어긋나면 일일 대사가 바로잡는다.
        static::created(function (PurchaseListing $listing) {
            if (self::isInspectionTarget($listing->status, $listing->origin, false)) {
                SendInspectionQueueEvent::dispatch($listing->id, 'upsert')->afterCommit();
            }
        });
        static::updated(function (PurchaseListing $listing) {
            $before = self::isInspectionTarget($listing->getOriginal('status'), $listing->getOriginal('origin'), false);
            $after = self::isInspectionTarget($listing->status, $listing->origin, false);
            if (! $before && $after) {
                SendInspectionQueueEvent::dispatch($listing->id, 'upsert')->afterCommit();
            } elseif ($before && ! $after) {
                $reason = $listing->wasChanged('origin') && $listing->origin === 'self_inspection'
                    ? 'not_inspection_target'
                    : ($listing->allowManagerOverride ? 'status_override' : 'closed_in_board');
                SendInspectionQueueEvent::dispatch($listing->id, 'withdraw', $reason)->afterCommit();
            } elseif ($after && $listing->wasChanged(self::INSPECTION_QUEUE_FIELDS)) {
                SendInspectionQueueEvent::dispatch($listing->id, 'upsert')->afterCommit();   // 큐 필드 변경 = 재발송
            }
        });
        static::deleted(function (PurchaseListing $listing) {
            if (self::isInspectionTarget($listing->status, $listing->origin, false)) {
                SendInspectionQueueEvent::dispatch($listing->id, 'withdraw', 'deleted')->afterCommit();
            }
        });
        static::restored(function (PurchaseListing $listing) {
            if (self::isInspectionTarget($listing->status, $listing->origin, false)) {
                SendInspectionQueueEvent::dispatch($listing->id, 'upsert')->afterCommit();
            }
        });
    }

    /** 검차 큐에 실리는 값 — 이 중 하나가 바뀌면 upsert 를 다시 보낸다. 🚫 소유자명·계좌·금액은 넣지 않는다. */
    public const INSPECTION_QUEUE_FIELDS = ['vehicle_number', 'vin', 'region', 'inspection_request', 'encar_url'];

    /**
     * 검차 큐 판정식 — `status='draft' AND origin≠self_inspection AND 미삭제` (스펙 §8-6). origin 은 nullable 이라
     * `!==` 로만 본다(NULL 행 = 검차 대상). 일일 대사(`board:inspection-reconcile`)도 같은 식을 쿼리로 쓴다.
     */
    public static function isInspectionTarget(?string $status, ?string $origin, bool $trashed): bool
    {
        return $status === 'draft' && $origin !== 'self_inspection' && ! $trashed;
    }

    /**
     * 검차 예정일이 **오늘 목록**인가 — ssancar 「그날 목록」 = `work_date <= 그날`(`iq_due`, 이월 차 포함)이라 `<=` 로 본다(P1, 2026-10-29).
     * 오늘 목록인 차에 긴급 요청은 뜻이 없다 → 드로어가 버튼 대신 「이미 오늘 목록」을 보인다.
     */
    public function inspectionDueToday(): bool
    {
        return $this->inspection_work_date !== null && $this->inspection_work_date->lte(today());
    }

    // ─────────────────────── 관계 ───────────────────────

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** 검차 사진/영상 (현지확인). 영업 첨부(sales_*)는 제외 — 기존 inspection/auction/바이어전송 동작 보존. */
    public function photos(): HasMany
    {
        return $this->hasMany(InspectionPhoto::class)
            ->where('kind', InspectionPhoto::KIND_INSPECTION)
            ->orderBy('sort');
    }

    /** 영업 차량 첨부(외관 사진 + 서류) — 연동 B 로 car-erp 첨부탭에 전달. */
    public function salesAttachments(): HasMany
    {
        return $this->hasMany(InspectionPhoto::class)
            ->whereIn('kind', InspectionPhoto::SALES_KINDS)
            ->orderBy('sort');
    }

    // ─────────────────────── 헬퍼 ───────────────────────

    public function isAuction(): bool
    {
        return $this->source === 'auction';
    }

    /**
     * ssancar 미디어 API 호출용 (type,id) — 저장된 식별자에서 도출. 없으면 null.
     *  - ssancar_ref "wr_id:N" → inspected(영상+사진) / "car_no:N" → auction(사진)
     *  - c_no → stock(사진)
     */
    public function ssancarMediaParams(): ?array
    {
        if ($this->ssancar_ref && str_contains($this->ssancar_ref, ':')) {
            [$kind, $id] = explode(':', $this->ssancar_ref, 2);
            $id = trim($id);
            if ($id !== '') {
                if ($kind === 'wr_id') {
                    return ['type' => 'inspected', 'id' => $id];
                }
                if ($kind === 'car_no') {
                    return ['type' => 'auction', 'id' => $id];
                }
            }
        }
        if ($this->c_no) {
            return ['type' => 'stock', 'id' => (string) $this->c_no];
        }

        return null;
    }

    /** 자동(C, respond.io 폴링) 회신 채널인지. false=수동(A, /verdicts 화면). */
    public function isAutoVerdict(): bool
    {
        return ($this->verdict_channel ?? 'auto') === 'auto';
    }

    /** 경매 차량이고 lock_at 이 지났으면 잠김 (서버시각 단일 판정) */
    public function isLocked(): bool
    {
        return $this->isAuction()
            && $this->lock_at !== null
            && now()->greaterThanOrEqualTo($this->lock_at);
    }

    // ─────────────────────── 표시용 라벨/뱃지 ───────────────────────

    public function statusLabel(): string
    {
        return match ($this->status) {
            'draft' => __('domain.status_live.draft'),
            'inspected' => __('domain.status_live.inspected'),
            'awaiting_buyer' => __('domain.status_live.awaiting_buyer'),
            'accepted' => $this->isAuction() ? __('domain.status_live.accepted_auction') : __('domain.status_live.accepted_encar'),
            'rejected' => __('domain.status_live.rejected'),
            'won' => $this->isAuction() ? __('domain.status_live.won_auction') : __('domain.status_live.won_encar'),
            'failed' => $this->isAuction() ? __('domain.status_live.failed_auction') : __('domain.status_live.failed_encar'),
            'synced' => __('domain.status_live.synced'),
            default => $this->status,
        };
    }

    /** 상태 드롭다운/필터 옵션 (번역). key=상태값, value=번역 라벨. */
    public static function statusOptions(): array
    {
        $out = [];
        foreach (array_keys(self::STATUS_LABELS) as $k) {
            $out[$k] = __('domain.status.'.$k);
        }

        return $out;
    }

    /** 유입(origin) 드롭다운/필터 옵션 (번역). */
    public static function originOptions(): array
    {
        $out = [];
        foreach (array_keys(self::ORIGIN_LABELS) as $k) {
            $out[$k] = __('domain.origin.'.$k);
        }

        return $out;
    }

    public function statusBadge(): string
    {
        return match ($this->status) {
            'draft' => 'badge-blue',
            'inspected' => 'badge-teal',
            'awaiting_buyer' => 'badge-amber',
            'accepted' => $this->isAuction() ? 'badge-purple' : 'badge-teal',
            'rejected' => 'badge-red',
            'won' => 'badge-green',
            'synced' => 'badge-gray',
            default => 'badge-gray',
        };
    }

    public function verdictLabel(): ?string
    {
        return match ($this->buyer_verdict) {
            'pending' => __('domain.verdict.pending'),
            'accepted' => __('domain.verdict.accepted'),
            'rejected' => __('domain.verdict.rejected'),
            default => null,
        };
    }

    public function verdictBadge(): string
    {
        return match ($this->buyer_verdict) {
            'accepted' => 'badge-green',
            'rejected' => 'badge-red',
            'pending' => 'badge-amber',
            default => 'badge-gray',
        };
    }
}

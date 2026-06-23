<?php

namespace App\Models;

use App\Jobs\SyncWonListingToCarErp;
use App\Models\Scopes\SalesmanScope;
use App\Services\BoardAudit;
use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

#[ScopedBy([SalesmanScope::class])]
class PurchaseListing extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'created_by_user_id', 'source', 'origin', 'region', 'c_no', 'ssancar_ref',
        'respond_conversation_id', 'respond_contact_id', 'encar_id',
        'vehicle_number', 'owner_name', 'vin',
        'expected_price', 'expected_price_currency', 'car_cost', 'discount_rate', 'shipping_usd',
        'final_price', 'encar_url', 'encar_dealer',
        'auction_venue', 'lot_number', 'status', 'buyer_verdict', 'verdict_channel',
        'buyer_name', 'payee_name', 'payee_bank', 'payee_account',
        'inspection_memo', 'inspection_note', 'lock_at', 'car_erp_vehicle_id',
    ];

    protected function casts(): array
    {
        return [
            'expected_price' => 'integer',
            'car_cost' => 'integer',
            'discount_rate' => 'decimal:2',
            'shipping_usd' => 'integer',
            'final_price' => 'integer',
            'payee_account' => 'encrypted',   // 계좌번호 at-rest 암호화 (§6e)
            'lock_at' => 'datetime',
            'car_erp_vehicle_id' => 'integer',
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

    /** 차량금액(Car Price, KRW) = 차값(KRW환산) − (×할인율%) + 매도비(고정). 입력 없으면 null. */
    public function carPriceKrw(?int $krwPerUsd = null, ?int $krwPerEur = null): ?int
    {
        $cost = $this->carCostKrw($krwPerUsd, $krwPerEur);
        if ($cost === null) {
            return null;
        }
        $discount = (int) round($cost * ((float) $this->discount_rate / 100));

        return $cost - $discount + (int) config('board.sales_fee');
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

    // ─────────────────────── 상태머신 ───────────────────────

    public const STATUSES = [
        'draft', 'awaiting_buyer', 'accepted', 'rejected', 'won', 'failed', 'synced',
    ];

    /** 드롭다운/필터용 정적 라벨(출처 무관 통합). 출처별 표기는 statusLabel() 사용. */
    public const STATUS_LABELS = [
        'draft' => '현지확인 대기',
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
    ];

    /** origin → 내부 매입방법(source). 워크플로/시간잠금/연동B 는 이 source 로 동작. */
    public const ORIGIN_SOURCE = [
        'ssancar_auction' => 'auction',   // 싼카경매 = 경매(잠금·낙찰/유찰)
        'ssancar_stock' => 'encar',       // 싼카재고 = 즉시구매
        'ssancar_checking' => 'encar',    // 싼카체킹 = 즉시구매
        'encar' => 'encar',
        'auction' => 'auction',
    ];

    public static function sourceForOrigin(string $origin): string
    {
        return self::ORIGIN_SOURCE[$origin] ?? 'encar';
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
        return str_starts_with((string) $this->origin, 'ssancar')
            ? 'badge-blue'
            : ($this->isAuction() ? 'badge-auction' : 'badge-encar');
    }

    /** 허용 전이: from => [to, ...] (manager override 는 우회) */
    public const TRANSITIONS = [
        'draft' => ['awaiting_buyer'],
        'awaiting_buyer' => ['accepted', 'rejected'],
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
        'expected_price', 'final_price', 'car_cost', 'discount_rate', 'shipping_usd',
        'owner_name', 'payee_name', 'payee_bank', 'payee_account',
        'vehicle_number', 'vin', 'car_erp_vehicle_id', 'region', 'inspection_note', 'inspection_memo',
        'encar_url', 'encar_dealer', 'auction_venue', 'lot_number',
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

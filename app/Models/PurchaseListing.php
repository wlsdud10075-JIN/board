<?php

namespace App\Models;

use App\Models\Scopes\SalesmanScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[ScopedBy([SalesmanScope::class])]
class PurchaseListing extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'created_by_user_id', 'source', 'vehicle_number', 'vin',
        'expected_price', 'final_price', 'encar_url', 'encar_dealer',
        'auction_venue', 'lot_number', 'status', 'buyer_verdict',
        'buyer_name', 'inspection_memo', 'lock_at', 'car_erp_vehicle_id',
    ];

    protected function casts(): array
    {
        return [
            'expected_price' => 'integer',
            'final_price' => 'integer',
            'lock_at' => 'datetime',
            'car_erp_vehicle_id' => 'integer',
        ];
    }

    // ─────────────────────── 상태머신 ───────────────────────

    public const STATUSES = [
        'draft', 'awaiting_buyer', 'accepted', 'rejected', 'won', 'failed', 'synced',
    ];

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
    }

    // ─────────────────────── 관계 ───────────────────────

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(InspectionPhoto::class)->orderBy('sort');
    }

    // ─────────────────────── 헬퍼 ───────────────────────

    public function isAuction(): bool
    {
        return $this->source === 'auction';
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
            'draft' => '현지확인 대기',
            'awaiting_buyer' => '회신대기',
            'accepted' => $this->isAuction() ? '경매대기' : '구매대기',
            'rejected' => '거절',
            'won' => $this->isAuction() ? '낙찰' : '구매확정',
            'failed' => $this->isAuction() ? '유찰' : '취소',
            'synced' => 'ERP 전환완료',
            default => $this->status,
        };
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
            'pending' => '회신대기',
            'accepted' => '수락',
            'rejected' => '거절',
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

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * 클레임(2026-11-10 Jin 착수, 정본 = meetings/plan-claims-2026-11.md) — 영업 접수 → 담당자 처리·보고서 → 대표 승인 → 종결.
 *
 * 상태머신(§3 초안 → 2026-11-10 확정):
 *   received(접수) → reviewing(검토) → pending_ceo(승인요청) → approved / rejected(사유)
 *   rejected → pending_ceo(담당자 수정 뒤 재요청) · approved → pending_ceo(합의액 변경 = 재승인, §9 #9) · approved → closed(종결)
 * 전이는 `updating` 가드가 강제한다(PurchaseListing 과 같은 꼴). 관리자 override 없음.
 *
 * 값 출처: 차량·바이어 칸은 접수 시점 **ERP 스냅샷**(ClaimService::snapshotErp) — 담당자가 [ERP 다시 불러오기]로 갱신.
 * 도착일+14 초과·주행거리 차이는 **표시만**(차단 ❌, §15 N·J).
 */
class Claim extends Model
{
    use SoftDeletes;

    public const RECEIVED = 'received';

    public const REVIEWING = 'reviewing';

    public const PENDING_CEO = 'pending_ceo';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const CLOSED = 'closed';

    public const STATUSES = [self::RECEIVED, self::REVIEWING, self::PENDING_CEO, self::APPROVED, self::REJECTED, self::CLOSED];

    public const TRANSITIONS = [
        self::RECEIVED => [self::REVIEWING, self::PENDING_CEO],
        self::REVIEWING => [self::PENDING_CEO],
        self::PENDING_CEO => [self::APPROVED, self::REJECTED],
        self::REJECTED => [self::PENDING_CEO],
        self::APPROVED => [self::PENDING_CEO, self::CLOSED],
        self::CLOSED => [],
    ];

    /** 도착일 기준 접수 기한(일) — 실무자 조건(§12 #1). 초과는 칩 + 담당자 사유, 차단 아님. */
    public const ARRIVAL_WINDOW_DAYS = 14;

    /** 클레임 통화 — ERP 바이어 화면 드롭다운과 같은 목록·순서(2026-11-11 Jin 「ERP 에 있는 통화 그대로」). */
    public const CURRENCIES = ['USD', 'JPY', 'EUR', 'GBP', 'CNY', 'KRW'];

    public const COMPENSATIONS = ['refund', 'credit'];

    /** 서명 링크 수명 — ERP 월정산과 동일(§17): 보기 5일 · 결정 POST 60분 · 재전송 연타 10분. */
    public const LINK_DAYS = 5;

    public const DECIDE_MINUTES = 60;

    public const RESEND_COOLDOWN_MINUTES = 10;

    protected $fillable = [
        'status', 'revision', 'current_step',
        'car_erp_vehicle_id', 'purchase_listing_id', 'vehicle_number', 'vin', 'brand', 'model_type',
        'car_erp_buyer_id', 'buyer_name', 'buyer_contact_name', 'sale_price', 'sale_currency', 'sale_date', 'eta_date',
        'total_purchase_count', 'year_purchase_count', 'buyer_credit_grade', 'buyer_credit_score', 'member_since', 'purchase_history', 'margin_rate', 'erp_snapshot_at',
        'salesman_user_id', 'created_by_user_id', 'arrived_at', 'received_km', 'content',
        'inspection_result', 'notes', 'review_notes',
        'claim_amount', 'claim_currency', 'refundable_amount', 'result', 'result_reason', 'compensation_type', 'overdue_reason',
        'bank_name', 'bank_iban', 'bank_swift', 'bank_phone', 'bank_address',
        'requested_at', 'requested_by_user_id', 'request_notified_at', 'approver_user_id', 'decided_at', 'decision', 'decision_reason', 'decision_ip',
        'closed_at', 'closed_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'purchase_history' => 'array',
            'sale_date' => 'date', 'eta_date' => 'date', 'arrived_at' => 'date',
            'erp_snapshot_at' => 'datetime', 'requested_at' => 'datetime', 'request_notified_at' => 'datetime',
            'decided_at' => 'datetime', 'closed_at' => 'datetime',
            // 2p 은행정보 = PII 범위한정 예외(payee 와 동일) — 화면엔 그대로, 감사·로그는 ***.
            'bank_name' => 'encrypted', 'bank_iban' => 'encrypted', 'bank_swift' => 'encrypted', 'bank_phone' => 'encrypted', 'bank_address' => 'encrypted',
        ];
    }

    /** 담당자가 수정하는 보고서 칸(저장 감사 대상). 민감 칸은 *** 로 기록. */
    public const EDITABLE = [
        'buyer_contact_name', 'arrived_at', 'received_km', 'content',
        'inspection_result', 'notes', 'review_notes',
        'claim_amount', 'claim_currency', 'refundable_amount', 'result', 'result_reason', 'compensation_type', 'overdue_reason',
        'bank_name', 'bank_iban', 'bank_swift', 'bank_phone', 'bank_address',
    ];

    public const MASKED = ['bank_name', 'bank_iban', 'bank_swift', 'bank_phone', 'bank_address'];

    protected static function booted(): void
    {
        static::updating(function (Claim $c) {
            if (! $c->isDirty('status')) {
                return;
            }
            $from = $c->getOriginal('status');
            $to = $c->status;
            if (! in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
                throw new \DomainException("claim transition {$from} → {$to} not allowed");
            }
        });
    }

    // ── 관계 ──

    public function files(): HasMany
    {
        return $this->hasMany(ClaimFile::class)->orderBy('id');
    }

    public function salesman(): BelongsTo
    {
        return $this->belongsTo(User::class, 'salesman_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /** 지금 차례 결재자(결재 중) 또는 마지막으로 결정한 사람(결재 끝). */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }

    /** 결재선 전 회차. 화면은 `lineSteps()`(현재 회차)만 쓴다. */
    public function steps(): HasMany
    {
        return $this->hasMany(ClaimStep::class)->orderBy('revision')->orderBy('seq');
    }

    /** 현재 회차 결재선(부장 → 상무 → 대표 중 지정한 칸). */
    public function lineSteps()
    {
        return $this->steps->where('revision', $this->revision)->sortBy('seq')->values();
    }

    public function currentStep(): ?ClaimStep
    {
        return $this->current_step ? $this->lineSteps()->firstWhere('seq', (int) $this->current_step) : null;
    }

    /**
     * 결재선 후보(2026-11-11 Jin) — 최고관리자 등급 + 결재 직급(부장·상무·대표), 직급당 1명(사용자관리 규칙). 순서 = 부장 → 상무 → 대표.
     *
     * @return array<string, ?User> 직급 => 그 사람(없으면 null)
     */
    public static function lineCandidates(): array
    {
        $out = [];
        foreach (User::TOP_TITLES as $title) {
            $out[$title] = User::where('approval_title', $title)->where('permission', 'admin')->where('is_active', true)->orderBy('id')->first();
        }

        return $out;
    }

    /**
     * 결재선 기본값 — 지난 클레임 결재선(그 사람이 아직 그 직급이면), 없으면 직급 보유자 전원(ERP 월정산과 같은 결).
     *
     * @return array<string, ?int> 직급 => user id|null
     */
    public static function defaultLine(): array
    {
        $cand = self::lineCandidates();
        $last = ClaimStep::query()->latest('id')->first();
        if ($last) {
            $prev = ClaimStep::where('claim_id', $last->claim_id)->where('revision', $last->revision)->pluck('approver_user_id', 'title')->all();

            return collect($cand)->map(fn ($u, $t) => ($u && (int) ($prev[$t] ?? 0) === $u->id) ? $u->id : null)->all();
        }

        return collect($cand)->map(fn ($u) => $u?->id)->all();
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(PurchaseListing::class, 'purchase_listing_id')->withoutGlobalScopes()->withTrashed();
    }

    // ── 판정 ──

    /** 도착일로부터 지난 일수(오늘 − 도착일). 도착일은 필수라 항상 숫자. */
    public function daysAfterArrival(): int
    {
        return (int) $this->arrived_at->startOfDay()->diffInDays(Carbon::today(), false);
    }

    /** 도착일+14 초과 — 표시·담당자 사유용(접수는 막지 않는다, §15 N). */
    public function isOverdue(): bool
    {
        return $this->daysAfterArrival() > self::ARRIVAL_WINDOW_DAYS;
    }

    public function isOpen(): bool
    {
        return $this->status !== self::CLOSED;
    }

    public function isPendingCeo(): bool
    {
        return $this->status === self::PENDING_CEO;
    }

    /** [대표 승인 요청] 을 누를 수 있는 상태 — 접수·검토·반려·승인(재승인). 요청 중·종결은 불가. */
    public function canRequestApproval(): bool
    {
        return in_array(self::PENDING_CEO, self::TRANSITIONS[$this->status] ?? [], true);
    }

    /**
     * 이 사람·이 회차 링크로 지금 결정할 수 있나 — 결재 중 + 링크 회차 = 현재 회차 + (지금 차례 결재자 본인 또는 super).
     * 링크는 사람에 묶이고 「차례」는 결정 시점에 판정(ERP 월정산 canDecide 와 같은 결) — 지난 단계 사람의 링크로 다음 단계를 못 누른다.
     * super = 어느 단계든 결정 가능(Jin 2026-11-11 「SUPER 는 무조건 전부 다 권한」).
     */
    public function canDecide(?User $u, int $revision): bool
    {
        if ($u === null || ! $this->isPendingCeo() || (int) $this->revision !== $revision || ! $u->is_active) {
            return false;
        }
        if ($u->isSuper()) {
            return true;
        }

        return (int) $this->currentStep()?->approver_user_id === (int) $u->id;
    }

    /** 재전송 연타 방지 — 마지막 알림 뒤 10분. */
    public function resendAvailableAt(): ?Carbon
    {
        return $this->request_notified_at?->copy()->addMinutes(self::RESEND_COOLDOWN_MINUTES);
    }

    public function canResend(): bool
    {
        return $this->isPendingCeo() && ($this->request_notified_at === null || $this->resendAvailableAt()->isPast());
    }

    /**
     * 대표 서명 링크(보기, 5일). 호스트는 **APP_URL 로 고정**해 만든다 — 서명은 호스트까지 포함되고, 카카오는 승인본 버튼 URL 접두와 대조한다(K108).
     * (ERP `SettlementPayoutBatch::approvalLinkFor` 교훈: 제출자가 www/IP 로 들어와 있으면 링크가 달라져 아무에게도 안 간다.)
     */
    public function approvalLinkFor(User $approver): string
    {
        URL::forceRootUrl(config('app.url'));
        try {
            return URL::temporarySignedRoute('claim.approve.show', now()->addDays(self::LINK_DAYS), [
                'claim' => $this->id, 'u' => $approver->id, 'rev' => $this->revision,
            ]);
        } finally {
            URL::forceRootUrl(null);
        }
    }

    /**
     * ssancar.com INSPECTED 검색 링크(2026-11-09 ssancar 세션 실측·Jin 결정 「차량번호로 충분, 개발 없음」) — `/bbs/board.php?bo_table=inspected&no=<차량번호>`
     * 가 `wr_facebook_user LIKE '%값%'` 부분일치. 검차앱 자유입력이라 `i30 16부5626` 처럼 모델명·공백이 섞여 있어 **공백 없는 숫자+한글만** 넘긴다. VIN 검색은 없다.
     * 호스트는 미디어 API URL(`services.ssancar_media.base_url`)의 origin — 미설정이면 null(링크 없음).
     */
    public static function ssancarInspectedUrl(?string $plate): ?string
    {
        $base = (string) config('services.ssancar_media.base_url');
        $plate = preg_replace('/\s+/u', '', (string) $plate);
        if ($base === '' || $plate === '' || ! ($p = parse_url($base)) || empty($p['host'])) {
            return null;
        }
        // 세 자리 앞자리는 앞에 숫자가 더 없을 때만(「i30 16부5626」에서 016부5626 이 잡히지 않게).
        if (preg_match('/(?:(?<!\d)\d)?\d{2}[가-힣]\d{4}/u', $plate, $m)) {
            $plate = $m[0];   // 모델명 등이 붙어 와도 번호판 부분만
        }

        return ($p['scheme'] ?? 'https').'://'.$p['host'].'/bbs/board.php?bo_table=inspected&no='.rawurlencode($plate);
    }

    // ── 표시 ──

    public function statusLabel(): string
    {
        $key = 'claims.status.'.$this->status;
        $l = __($key);

        return $l === $key ? $this->status : $l;
    }

    public function statusBadge(): string
    {
        return match ($this->status) {
            self::RECEIVED => 'badge-amber',
            self::REVIEWING => 'badge-blue',
            self::PENDING_CEO => 'badge-purple',
            self::APPROVED => 'badge-green',
            self::REJECTED => 'badge-red',
            default => 'badge-gray',
        };
    }

    /**
     * BUYER PRIORITY = ERP 바이어 신용도 **점수**(2026-11-11 Jin 「점수로」) — 「72점 (B)」. 이력 없음('-') = 「평가 불가」, 아직 못 받음 = 「—」.
     */
    public function creditDisplay(): string
    {
        if ($this->buyer_credit_grade === null) {
            return '—';
        }
        if ($this->buyer_credit_grade === '-') {
            return __('claims.credit_unavailable');
        }

        return __('claims.credit_score', ['score' => (int) $this->buyer_credit_score, 'grade' => $this->buyer_credit_grade]);
    }

    public function salePriceDisplay(): string
    {
        if ($this->sale_price === null) {
            return '—';
        }

        return trim(($this->sale_currency ?: '').' '.number_format((float) $this->sale_price, fmod((float) $this->sale_price, 1.0) === 0.0 ? 0 : 2));
    }

    public function claimAmountDisplay(): string
    {
        if ($this->claim_amount === null) {
            return '—';
        }

        return trim(($this->claim_currency ?: $this->sale_currency ?: '').' '.number_format((float) $this->claim_amount, fmod((float) $this->claim_amount, 1.0) === 0.0 ? 0 : 2));
    }

    /** 환급률(%) — REFUNDABLE ÷ CLAIM AMOUNT, 표시 전용. */
    public function refundRate(): ?float
    {
        if (! $this->claim_amount || $this->refundable_amount === null) {
            return null;
        }

        return round((float) $this->refundable_amount / (float) $this->claim_amount * 100, 1);
    }

    /** CLAIM HISTORY(1 YEAR) = 이 바이어의 board 클레임 자동 카운트(최근 12개월, 본 건 포함 · 수기 보정 없음 — 2026-11-11 Jin). */
    public function yearClaimCount(): int
    {
        $q = static::query()->where('created_at', '>=', now()->subMonths(12));
        $q = $this->car_erp_buyer_id ? $q->where('car_erp_buyer_id', $this->car_erp_buyer_id) : $q->where('buyer_name', $this->buyer_name);

        return (int) $q->count();
    }
}

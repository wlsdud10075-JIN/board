<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Concerns\NormalizesRegion;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable // implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, NormalizesRegion, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'region',
        'password',
        'role',
        'permission',
        'approval_title',
        'is_active',
        'locale',
        'car_erp_salesman_id',
        'car_erp_salesman_email',
        'respond_agent_email',
        'portal_seen_at',
    ];

    /** board 업무 역할 4종 (영업/현지확인/경매/관리) */
    public const ROLES = ['sales', 'inspection', 'auction', 'manager'];

    /** i18n Phase 0 — 지원 언어. 'ko' 기본(항상), 'en'은 super가 기능설정에서 활성화해야 노출. */
    public const LOCALES = ['ko', 'en'];

    /**
     * 권한 단계 (car-erp 미러 super > admin > manager > user) — super=시스템관리자, admin=최고관리자(2026-11-04 Jin), manager=업무관리자(2026-10-14 Jin), user=role 기반.
     * 업무관리자 = role 무관 전체 화면 + 감사로그 + 사용자관리(super 계정 제외). 기능설정·super 지정·
     * 포털 타인 명의 대리실행은 super 만. 관리자 페이지 삭제/재전송은 관리 이상(관리 role 포함, 2026-10-22 Jin).
     * 최고관리자 = 업무관리자 권한 + 등급 지정(super 제외)·결재 직급(부장·상무·대표). 기능설정·포털 대리실행은 못 한다(조회만).
     */
    public const PERMISSIONS = ['super', 'admin', 'manager', 'user'];

    /** 업무일지 결재선 순서(2026-11-04 Jin) — 팀장 = 업무관리자 중 지정 1명, 부장·상무·대표 = 최고관리자. 직급마다 1명. */
    public const JOURNAL_LINE = ['팀장', '부장', '상무', '대표'];

    /** 최고관리자에게만 붙는 결재 직급(ERP `APPROVAL_TITLES` 와 같은 값). */
    public const TOP_TITLES = ['부장', '상무', '대표'];

    public const TEAM_LEADER = '팀장';

    public const ROLE_LABELS = [
        'sales' => '영업', 'inspection' => '현지확인', 'auction' => '경매', 'manager' => '관리',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'car_erp_salesman_id' => 'integer',
            'portal_seen_at' => 'datetime',   // 상단 종(요청 결과) 마지막 열람 시각
            'push_notified_at' => 'datetime', // 웹푸시 스케줄러 커서(보낸 기준 시각) — 열람 시각과 별개 축
        ];
    }

    /** 웹푸시 구독(기기별). 발송 = WebPushService, 커서 = push_notified_at. */
    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class);
    }

    // ─────────────────────── 권한/역할 헬퍼 ───────────────────────

    /** 시스템관리자 — role 무관 전체 접근 + 사용자관리 + 기능설정 (car-erp super 대응) */
    public function isSuper(): bool
    {
        return $this->permission === 'super';
    }

    /** 업무관리자(permission=manager) — 전체 화면 + 감사로그, super 전용 기능은 제외. `isManager()`(role=관리) 와 별개. */
    public function isWorkManager(): bool
    {
        return $this->permission === 'manager';
    }

    /**
     * 최고관리자(permission=admin, 2026-11-04 Jin). 🚫 `isAdmin()` 으로 짓지 말 것 — car-erp `isAdmin()` 은 super 를 포함해 헷갈린다.
     */
    public function isTopManager(): bool
    {
        return $this->permission === 'admin';
    }

    /** 등급 순위 — super 4 · admin 3 · manager 2 · user 1. 「위 등급 계정은 아래 등급이 못 건드린다」 판정용. */
    public function tierRank(): int
    {
        return match ($this->permission) {
            'super' => 4, 'admin' => 3, 'manager' => 2, default => 1,
        };
    }

    /** 업무일지 작성자 — 등급 「일반」 활성 사용자(결재 직급·관리자 등급은 쓰지 않고 결재·조회만). */
    public function isJournalWriter(): bool
    {
        return $this->is_active && $this->permission === 'user';
    }

    /** 업무일지 결재선에 들어가는 사람 — 팀장 지정 업무관리자 또는 직급 있는 최고관리자. */
    public function isJournalApprover(): bool
    {
        return $this->is_active && (
            ($this->isWorkManager() && $this->approval_title === self::TEAM_LEADER)
            || ($this->isTopManager() && in_array($this->approval_title, self::TOP_TITLES, true))
        );
    }

    /**
     * role 바이패스 등급 = super·최고관리자·업무관리자. 라우트 role 가드·영업 격리·사이드바 메뉴가 이걸 본다.
     * ⚠️ super 전용 기능(기능설정·등급 지정·포털 대리실행·관리자 페이지 삭제/재전송)은 여기 말고 `isSuper()` 로 판정.
     */
    public function hasFullAccess(): bool
    {
        return $this->isSuper() || $this->isTopManager() || $this->isWorkManager();
    }

    public function roleLabel(): string
    {
        // i18n — 번역키 우선, 미정의면 한글 상수 폴백.
        $key = 'nav.role.'.$this->role;
        $label = __($key);

        return is_string($label) && $label !== $key ? $label : (self::ROLE_LABELS[$this->role] ?? (string) $this->role);
    }

    public function isSales(): bool
    {
        return $this->role === 'sales';
    }

    public function isInspection(): bool
    {
        return $this->role === 'inspection';
    }

    public function isAuction(): bool
    {
        return $this->role === 'auction';
    }

    public function isManager(): bool
    {
        return $this->role === 'manager';
    }

    /**
     * 사내 업무 도우미(챗봇) 사용 가능 — 영업·관리·시스템관리자만 (jin 2026-08-03).
     * 인프라 준비(.env)와 운영 토글(기능설정) 둘 다 켜져 있어야 한다.
     */
    public function canUseAssistant(): bool
    {
        if (! $this->is_active || ! config('assistant.enabled') || ! Setting::get('assistant_enabled', false)) {
            return false;
        }

        return $this->hasFullAccess() || $this->isSales() || $this->isManager();
    }

    /** 검차·경매·관리·시스템관리자·업무관리자는 전체 열람 (영업만 본인격리) */
    public function canSeeAll(): bool
    {
        return $this->hasFullAccess() || $this->role !== 'sales';
    }

    /**
     * 관리(role=manager)가 담당으로 지정받은 영업들 (2026-11-02 Jin, car-erp `manager_salesman` 미러).
     * 용도 = `/portal` 사용자별 조회(조회 전용·**정산 숨김**). 지정은 `/users` 에서 super·업무관리자만.
     */
    public function managedSalesmanUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'manager_salesman', 'manager_user_id', 'salesman_user_id')->withTimestamps();
    }

    /** 역방향 — 이 영업을 담당하는 관리들. */
    public function managingUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'manager_salesman', 'salesman_user_id', 'manager_user_id')->withTimestamps();
    }

    /**
     * 남의 포털을 **볼** 수 있나 — super·업무관리자(전체, 정산 포함) 또는 담당 영업이 지정된 관리(지정된 영업만, 정산 제외).
     * 대신 실행(쓰기)은 여전히 super 만(`proxyWriteBlocked`).
     */
    public function canViewOthersPortal(): bool
    {
        return $this->hasFullAccess() || ($this->isManager() && $this->managedSalesmanUsers()->exists());
    }

    /** respond.io 상담원 이메일 — 매핑값 우선, 없으면 로그인 이메일(연동 A 승격 라우팅). */
    public function respondAgentEmail(): string
    {
        return $this->respond_agent_email ?: $this->email;
    }

    public function listings(): HasMany
    {
        return $this->hasMany(PurchaseListing::class, 'created_by_user_id');
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->map(fn (string $name) => Str::of($name)->substr(0, 1))
            ->implode('');
    }
}

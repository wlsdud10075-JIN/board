<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Concerns\NormalizesRegion;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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
     * 권한 단계 (car-erp 미러 super > manager > user) — super=시스템관리자, manager=업무관리자(2026-10-14 Jin), user=role 기반.
     * 업무관리자 = role 무관 전체 화면 + 감사로그 + 사용자관리(super 계정 제외). 기능설정·업무관리자/super 지정·
     * 포털 타인 명의 대리실행·관리자 페이지 삭제/재전송은 super 만.
     */
    public const PERMISSIONS = ['super', 'manager', 'user'];

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
     * role 바이패스 등급 = super 또는 업무관리자. 라우트 role 가드·영업 격리·사이드바 메뉴가 이걸 본다.
     * ⚠️ super 전용 기능(기능설정·등급 지정·포털 대리실행·관리자 페이지 삭제/재전송)은 여기 말고 `isSuper()` 로 판정.
     */
    public function hasFullAccess(): bool
    {
        return $this->isSuper() || $this->isWorkManager();
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

<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable // implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'permission',
        'is_active',
        'locale',
        'car_erp_salesman_id',
        'car_erp_salesman_email',
        'respond_agent_email',
    ];

    /** board 업무 역할 4종 (영업/현지확인/경매/관리) */
    public const ROLES = ['sales', 'inspection', 'auction', 'manager'];

    /** i18n Phase 0 — 지원 언어. 'ko' 기본(항상), 'en'은 super가 기능설정에서 활성화해야 노출. */
    public const LOCALES = ['ko', 'en'];

    /** 권한 단계 (car-erp 미러) — super=시스템관리자, user=role 기반 */
    public const PERMISSIONS = ['super', 'user'];

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
        ];
    }

    // ─────────────────────── 권한/역할 헬퍼 ───────────────────────

    /** 시스템관리자 — role 무관 전체 접근 + 사용자관리 + 기능설정 (car-erp super 대응) */
    public function isSuper(): bool
    {
        return $this->permission === 'super';
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

    /** 검차·경매·관리·시스템관리자는 전체 열람 (영업만 본인격리) */
    public function canSeeAll(): bool
    {
        return $this->isSuper() || $this->role !== 'sales';
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

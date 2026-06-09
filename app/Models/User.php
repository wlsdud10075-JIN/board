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
        'is_active',
    ];

    /** board 역할 4종 */
    public const ROLES = ['sales', 'inspection', 'auction', 'manager'];

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
        ];
    }

    // ─────────────────────── 역할 헬퍼 ───────────────────────

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

    /** 검차·경매·관리는 전체 열람 (영업만 본인격리) */
    public function canSeeAll(): bool
    {
        return $this->role !== 'sales';
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

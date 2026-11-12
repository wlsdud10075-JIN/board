<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** 탁송 업체 — 관리 이상이 /transport-companies 에서 즉시 추가·비활성(2026-11-12). 번호는 TransportCompanyPhone. */
class TransportCompany extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'code', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function phones(): HasMany
    {
        return $this->hasMany(TransportCompanyPhone::class)->orderBy('id');
    }

    public function activePhones(): HasMany
    {
        return $this->phones()->where('is_active', true);
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true)->orderBy('name');
    }
}

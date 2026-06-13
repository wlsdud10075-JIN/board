<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExchangeRate extends Model
{
    protected $fillable = ['currency', 'krw_per_unit', 'fetched_at'];

    protected function casts(): array
    {
        return [
            'krw_per_unit' => 'decimal:2',
            'fetched_at' => 'datetime',
        ];
    }
}

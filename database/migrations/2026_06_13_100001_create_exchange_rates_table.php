<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 환율 캐시 (§6a) — 네이버/다음 조회값 보관. currency 당 1행.
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->string('currency', 3)->unique();        // USD, EUR
            $table->decimal('krw_per_unit', 12, 2);          // 1단위당 원화
            $table->timestamp('fetched_at')->nullable();     // 마지막 조회 시각
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};

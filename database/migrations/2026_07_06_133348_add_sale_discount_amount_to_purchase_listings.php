<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 차감액(sale_discount_amount) — 바이어가 총액에서 추가로 깎아달라 할 때의 절대금액(KRW).
 * 관례 할인율(discount_rate, %)과 별개의 sell-side 차감. 견적·전달(forwarding)에서 입력.
 * 매입원가(car_cost)는 불변 — 차감은 판매가에만 반영(Model A, 2026-07-06).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_listings', function (Blueprint $table) {
            $table->unsignedBigInteger('sale_discount_amount')->nullable()->after('discount_rate');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_listings', function (Blueprint $table) {
            $table->dropColumn('sale_discount_amount');
        });
    }
};

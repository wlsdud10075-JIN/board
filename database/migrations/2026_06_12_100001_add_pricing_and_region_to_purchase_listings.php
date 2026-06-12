<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_listings', function (Blueprint $table) {
            // 검사지역 (도+시) + 추가검사사항 — 현지확인에서 입력
            $table->string('region')->nullable()->after('source');
            $table->string('inspection_note')->nullable()->after('inspection_memo');

            // 금액 재설계 (§6) — additive. expected_price/final_price 는 유지.
            //  차량금액(Car Price) = car_cost − (car_cost × discount_rate%) + 매도비(config 고정)
            $table->unsignedBigInteger('car_cost')->nullable()->after('expected_price');   // 차값 (KRW)
            $table->decimal('discount_rate', 5, 2)->nullable()->after('car_cost');          // 할인율 (%)
            $table->unsignedInteger('shipping_usd')->nullable()->after('discount_rate');    // 배송금액 (USD 고정 택1)

            $table->index('region');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_listings', function (Blueprint $table) {
            $table->dropIndex(['region']);
            $table->dropColumn(['region', 'inspection_note', 'car_cost', 'discount_rate', 'shipping_usd']);
        });
    }
};

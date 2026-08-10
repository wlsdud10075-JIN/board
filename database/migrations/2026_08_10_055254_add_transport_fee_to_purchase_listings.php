<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 운임비 — **판매통화 기준** (2026-08-10 Jin). 셀프검차매입에서 판매가·환율과 같은 통화로 적는다.
 *
 * ⚠️ 기존 `shipping_usd`(USD 정수, 선택형)를 재사용하면 안 된다 — `shippingKrw()` 가 USD 환율을
 *    곱하도록 되어 있어, 거기에 EUR·KRW 금액을 넣으면 `totalKrw()` 가 조용히 틀어진다.
 *    그래서 컬럼을 나눈다: 기존 경로 = `shipping_usd`, 셀프검차 = `transport_fee`.
 *
 * car-erp 수신측 `transport_fee` 도 **판매통화 기준**이라 이 값은 환산 없이 그대로 나간다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_listings', function (Blueprint $table) {
            $table->decimal('transport_fee', 15, 2)->nullable()->after('sale_price');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_listings', function (Blueprint $table) {
            $table->dropColumn('transport_fee');
        });
    }
};

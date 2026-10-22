<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 셀프검차매입 금액 — 운임비 USD 고정 + USD·EUR 환율 둘 다(2026-10-22 Jin).
 * 운임비는 USD 로 정해지는데(예: RORO 알바니아 세단 2040) 판매는 EUR 일 수 있다 → 견적통화 운임비 =
 * USD 운임비 × USD 환율 ÷ 견적통화 환율, 판매가 = 총판매가 − 운임비. 계산 결과는 기존 `sale_price`·`transport_fee` 에 남는다.
 * ⚠️ `shipping_usd`(비-셀프 선택형 USD 정수)와 별개 — `shippingKrw()`·Job 폴백이 그 칸을 읽는다(SKILLS §14-5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_listings', function (Blueprint $table) {
            $table->decimal('transport_fee_usd', 15, 2)->nullable()->after('transport_fee');
            $table->unsignedInteger('offer_rate_usd')->nullable()->after('offer_rate');
            $table->unsignedInteger('offer_rate_eur')->nullable()->after('offer_rate_usd');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_listings', function (Blueprint $table) {
            $table->dropColumn(['transport_fee_usd', 'offer_rate_usd', 'offer_rate_eur']);
        });
    }
};

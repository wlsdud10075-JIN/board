<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 셀프검차매입 금액 직접입력 (2026-08-10 Jin) — 그 경로는 검차·견적 씬을 건너뛰어
 * 파생계산의 근거(할인율·차감액)가 없다. 영업이 이미 아는 값을 그대로 적게 한다.
 *
 * - `selling_fee` — 매도비 **금액**. 지금까지는 `config('board.sales_fee')` 고정값(440,000)이
 *   자동으로 실려 나갔다. 셀프검차는 매도비가 **차값에 포함**돼 있어 따로 적어야 분리된다.
 *   null = 기존 동작(고정값) 유지 → 다른 출처는 무영향.
 * - `sale_price` — 판매가 **원화 아님**. `offer_currency` 기준 raw(예: USD 8,590).
 *   기존 경로는 차값에서 파생계산하므로 null 로 남고, 이 컬럼이 있을 때만 그 값을 쓴다.
 *
 * ⚠️ 운임비는 새 컬럼을 안 만든다 — 기존 `shipping_usd`(USD) 를 그대로 쓰고 화면만
 *    선택형→직접입력으로 바꾼다. 통화를 바꾸면 `shippingKrw()`(USD 환율 곱셈)가 깨진다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_listings', function (Blueprint $table) {
            $table->unsignedInteger('selling_fee')->nullable()->after('sale_discount_amount');
            $table->decimal('sale_price', 15, 2)->nullable()->after('selling_fee');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_listings', function (Blueprint $table) {
            $table->dropColumn(['selling_fee', 'sale_price']);
        });
    }
};

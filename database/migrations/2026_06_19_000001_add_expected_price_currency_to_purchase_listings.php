<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 매물 표시가(expected_price)의 통화 — KRW/USD/EUR. enrichment 가 소스별로 채움
 * (encar=KRW, ssancar 재고=USD), 영업이 토글 가능. expected_price 는 참고가라
 * car_cost(가격계산)·final_price(연동 B)와 무관 → 통화 추가가 그쪽 오염 안 함.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_listings', function (Blueprint $table) {
            $table->string('expected_price_currency', 3)->default('KRW')->after('expected_price');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_listings', function (Blueprint $table) {
            $table->dropColumn('expected_price_currency');
        });
    }
};

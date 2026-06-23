<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 판매 통화 확정 (§6 금액매핑) — 현지확인에서 USD/EUR/KRW 로 최종금액 확정.
     * offer_currency = 바이어 견적·연동B 판매가 통화. offer_rate = 확정 시점 KRW/단위 환율 스냅샷.
     * final_price(KRW)는 유지 — 표시·연동B 매입가 호환.
     */
    public function up(): void
    {
        Schema::table('purchase_listings', function (Blueprint $table) {
            $table->string('offer_currency', 3)->nullable()->after('final_price');
            $table->unsignedInteger('offer_rate')->nullable()->after('offer_currency');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_listings', function (Blueprint $table) {
            $table->dropColumn(['offer_currency', 'offer_rate']);
        });
    }
};

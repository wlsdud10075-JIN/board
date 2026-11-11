<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CS 제시금액(2026-11-11 Jin) — 금액 3종: claim_amount = 바이어 청구(수리비 등) · refundable_amount = 영업 제시 ·
 * cs_proposed_amount = 클레임 담당자가 사실확인 후 제안. 통화는 셋 다 claim_currency.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('claims', function (Blueprint $table) {
            $table->decimal('cs_proposed_amount', 14, 2)->nullable()->after('refundable_amount');
        });
    }

    public function down(): void
    {
        Schema::table('claims', function (Blueprint $table) {
            $table->dropColumn('cs_proposed_amount');
        });
    }
};

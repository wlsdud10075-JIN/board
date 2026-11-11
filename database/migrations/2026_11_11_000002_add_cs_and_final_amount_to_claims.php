<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 클레임 금액(2026-11-11 Jin) — 제시 금액 3종 + 확정 금액 1개.
 *  - 제시(판단 자료): claim_amount = 바이어 청구(수리비 등) · refundable_amount = 영업 제시 · cs_proposed_amount = 담당자 사실확인 후 제안.
 *  - 확정: confirmed_amount = **최종 결재권자가 승인하며 정한 금액 하나**(기본값 = CS 제시). ERP 로 가는 건 이것 하나(ERP 키 이름도 confirmed_amount).
 * 통화는 전부 claim_currency.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('claims', function (Blueprint $table) {
            $table->decimal('cs_proposed_amount', 14, 2)->nullable()->after('refundable_amount');
            $table->decimal('confirmed_amount', 14, 2)->nullable()->after('cs_proposed_amount');
        });
    }

    public function down(): void
    {
        Schema::table('claims', function (Blueprint $table) {
            $table->dropColumn(['cs_proposed_amount', 'confirmed_amount']);
        });
    }
};

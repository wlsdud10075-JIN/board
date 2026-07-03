<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 매도비 계좌 (매입 정산계좌 2개 분리 — 판매자와 별개 대상, 영업 직접입력).
        // 기존 payee_*(매입가/판매자 계좌)와 짝. 연동 B v4 로 car-erp 전달.
        Schema::table('purchase_listings', function (Blueprint $table) {
            $table->string('selling_fee_payee_name')->nullable()->after('payee_account');      // 매도비 예금주
            $table->string('selling_fee_payee_bank')->nullable()->after('selling_fee_payee_name'); // 매도비 은행
            $table->text('selling_fee_payee_account')->nullable()->after('selling_fee_payee_bank'); // 매도비 계좌번호 (암호화 저장)
        });
    }

    public function down(): void
    {
        Schema::table('purchase_listings', function (Blueprint $table) {
            $table->dropColumn(['selling_fee_payee_name', 'selling_fee_payee_bank', 'selling_fee_payee_account']);
        });
    }
};

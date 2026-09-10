<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_listings', function (Blueprint $table) {
            // 첨부사진에서 읽어낸 계좌 후보(확정 전). 확정하면 비운다.
            // 확정 전이라도 실제 계좌번호이므로 payee_account 와 같이 암호화(모델 cast encrypted:json).
            $table->text('payee_suggestions')->nullable()->after('selling_fee_payee_account');
            // pending / done / failed / none — 구매확정 가드가 이걸 본다.
            $table->string('payee_extraction_status', 12)->nullable()->after('payee_suggestions');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_listings', function (Blueprint $table) {
            $table->dropColumn(['payee_suggestions', 'payee_extraction_status']);
        });
    }
};

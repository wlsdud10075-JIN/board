<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 매입 정산 입금정보 (§6e 개정) — 판매자/경매장 계좌. won 단계 입력 → 연동 B 로 car-erp 전달.
        Schema::table('purchase_listings', function (Blueprint $table) {
            $table->string('payee_name')->nullable()->after('buyer_name');     // 예금주
            $table->string('payee_bank')->nullable()->after('payee_name');     // 은행
            $table->text('payee_account')->nullable()->after('payee_bank');    // 계좌번호 (암호화 저장)
        });
    }

    public function down(): void
    {
        Schema::table('purchase_listings', function (Blueprint $table) {
            $table->dropColumn(['payee_name', 'payee_bank', 'payee_account']);
        });
    }
};

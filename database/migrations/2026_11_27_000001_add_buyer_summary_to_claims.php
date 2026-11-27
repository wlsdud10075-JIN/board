<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 클레임 「바이어 추가 정보」(2026-11-27 Jin) — ERP `GET /buyers/{id}/summary`(바이어 전체 기준: 구매 대수·통화별 미수·입금률·이 차의 입금 상태)를
 * 접수·[ERP 다시 불러오기] 때 받은 그대로 보관한다. board 는 계산하지 않는다(표시 전용).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('claims', function (Blueprint $table) {
            $table->json('buyer_summary')->nullable()->after('purchase_history');
            $table->timestamp('buyer_summary_at')->nullable()->after('buyer_summary');
        });
    }

    public function down(): void
    {
        Schema::table('claims', function (Blueprint $table) {
            $table->dropColumn(['buyer_summary', 'buyer_summary_at']);
        });
    }
};

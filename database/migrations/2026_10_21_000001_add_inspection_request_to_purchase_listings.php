<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 검차 요청사항 — 영업이 등록할 때 검차원에게 「이것도 봐 달라」고 남기는 칸(2026-10-21 Jin).
 * `inspection_note`(추가검사사항)는 검차원·관리자가 적는 **검차 결과 메모**라 따로 둔다 — 한 칸에 섞이면 누가 쓴 말인지 모른다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_listings', function (Blueprint $table) {
            $table->string('inspection_request', 255)->nullable()->after('inspection_note');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_listings', function (Blueprint $table) {
            $table->dropColumn('inspection_request');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 유입 카테고리 origin (화면 표시·분류). source(매입방법 엔카/경매)와 분리:
 *   origin ∈ ssancar_auction|ssancar_stock|ssancar_checking|encar|auction
 *   → source 도출: ssancar_auction→auction / 나머지 ssancar·encar→encar / auction→auction
 * source 는 워크플로(라벨·시간잠금)·연동B(car-erp) 전송용으로 그대로 유지(car-erp 무변경).
 * (설계 = meetings/integration-A-design.md)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_listings', function (Blueprint $table) {
            $table->string('origin')->nullable()->after('source');
            $table->index('origin');
        });

        // 기존 행 backfill: origin = source (encar→encar, auction→auction).
        DB::statement('UPDATE purchase_listings SET origin = source WHERE origin IS NULL');
    }

    public function down(): void
    {
        Schema::table('purchase_listings', function (Blueprint $table) {
            $table->dropIndex(['origin']);
            $table->dropColumn('origin');
        });
    }
};

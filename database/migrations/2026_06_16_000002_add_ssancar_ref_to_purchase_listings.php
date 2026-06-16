<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 연동 A — ssancar 비-c_no 식별자 저장. ssancar 링크는 페이지별 3종(c_no/wr_id/car_no).
 * c_no 는 기존 컬럼(스파인·연동B 전송). wr_id/car_no 는 여기 generic 으로 "wr_id:786" 형태 저장.
 * (설계 = meetings/integration-A-design.md ssancar 다형 섹션)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_listings', function (Blueprint $table) {
            $table->string('ssancar_ref')->nullable()->after('c_no');
            $table->index('ssancar_ref');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_listings', function (Blueprint $table) {
            $table->dropIndex(['ssancar_ref']);
            $table->dropColumn('ssancar_ref');
        });
    }
};

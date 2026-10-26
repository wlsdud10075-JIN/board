<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `/auction` 에서 고른 ERP 바이어의 **이름 스냅샷** (2026-10-24 Jin — 매입예정 목록에 바이어가 안 보였다).
 * `buyer_name` 은 전달(견적) 단계에서 적는 칸이라, 그 단계를 건너뛰는 셀프검차매입 등은 ERP 바이어(`car_erp_buyer_id`)만 있고
 * 이름이 없었다. 목록 표시 = `buyer_name ?: car_erp_buyer_name`. 저장 때마다 id 와 같이 갱신(이름만 따로 바뀌지 않게).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_listings', function (Blueprint $table) {
            $table->string('car_erp_buyer_name', 120)->nullable()->after('car_erp_buyer_id');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_listings', function (Blueprint $table) {
            $table->dropColumn('car_erp_buyer_name');
        });
    }
};

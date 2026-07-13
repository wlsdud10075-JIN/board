<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 지역 검차 알림톡 중복방지 — 이 차량이 지역 검차 안내로 이미 통보됐으면 stamp.
 * 스케줄 사전알림이 검차 전까지 매일 같은 차량을 재발송(스팸)하는 것 차단(차량당 1회).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_listings', function (Blueprint $table) {
            $table->timestamp('region_notified_at')->nullable()->after('region');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_listings', function (Blueprint $table) {
            $table->dropColumn('region_notified_at');
        });
    }
};

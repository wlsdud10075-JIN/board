<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 검차원 담당 지역(고정 로스터) — 지역 검차 안내 알림톡 수신자 해석 기준.
 * 검차원 1명 = 1지역(N명당 1지역=다대일). per-date InspectionAssignment 가 있으면 그 날은 override.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('region', 60)->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('region');
        });
    }
};

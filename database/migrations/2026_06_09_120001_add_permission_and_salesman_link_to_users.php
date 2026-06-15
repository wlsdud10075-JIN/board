<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // 권한 단계 (car-erp 미러) — super=시스템관리자, user=role 기반
            $table->enum('permission', ['super', 'user'])->default('user')->after('role');
            // 연동 B 영업 매칭 — car-erp salesmen.id (없으면 car-erp에서 수동 지정)
            $table->unsignedBigInteger('car_erp_salesman_id')->nullable()->after('permission');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['permission', 'car_erp_salesman_id']);
        });
    }
};

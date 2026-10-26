<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 감사 로그에 접속 IP (2026-10-24 Jin — car-erp `audit_logs.ip_address` 대조로 남은 차이 반영).
 * 화면 요청이면 요청 IP, 큐 Job·스케줄러·웹훅 처리 같은 시스템 기록은 null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('board_audit_logs', function (Blueprint $table) {
            $table->string('ip_address', 45)->nullable()->after('new_value');
        });
    }

    public function down(): void
    {
        Schema::table('board_audit_logs', function (Blueprint $table) {
            $table->dropColumn('ip_address');
        });
    }
};

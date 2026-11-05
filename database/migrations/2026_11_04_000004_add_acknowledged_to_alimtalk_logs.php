<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 알림톡 로그 화면 [확인] 처리(2026-11-04 Jin, car-erp `/admin/alimtalk-logs` 미러) — 실패 건을 시스템관리자가 봤다고 표시.
 * 「주의 필요」 = 실패 ∧ 미확인 → 사이드바 배지. 확인은 지우는 게 아니라 표시만(기록은 그대로).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alimtalk_logs', function (Blueprint $table) {
            $table->timestamp('acknowledged_at')->nullable()->after('error');
            $table->foreignId('acknowledged_by')->nullable()->after('acknowledged_at')->constrained('users')->nullOnDelete();
            $table->index(['status', 'acknowledged_at']);
        });
    }

    public function down(): void
    {
        Schema::table('alimtalk_logs', function (Blueprint $table) {
            $table->dropIndex(['status', 'acknowledged_at']);
            $table->dropConstrainedForeignId('acknowledged_by');
            $table->dropColumn('acknowledged_at');
        });
    }
};

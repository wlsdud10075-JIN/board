<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 업무일지 반려(2026-11-04 Jin) — 팀장만, 팀장 승인 전에만, 사람별·사유 필수. 반려된 일지는 내용 그대로 status=rejected 로 작성자에게 돌아가
 * 사유를 보고 고쳐 다시 상신한다(다시 상신하면 이 칸들은 비운다 — 이력은 감사 로그 work_journal_reject).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_journals', function (Blueprint $table) {
            $table->timestamp('rejected_at')->nullable()->after('snapshot');
            $table->foreignId('rejected_by')->nullable()->after('rejected_at')->constrained('users')->nullOnDelete();
            $table->string('reject_reason', 500)->nullable()->after('rejected_by');
        });
    }

    public function down(): void
    {
        Schema::table('work_journals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rejected_by');
            $table->dropColumn(['rejected_at', 'reject_reason']);
        });
    }
};

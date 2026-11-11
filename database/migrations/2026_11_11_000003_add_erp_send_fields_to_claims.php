<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 클레임 → ERP 바이어 「클레임」 탭 미러(2026-11-11 Jin, 정본 meetings/plan-claims-2026-11.md §25) — 담당자가 [ERP 로 보내기]를 누른 기록.
 * ERP `POST /api/internal/board/claims`(멱등키 = 인스턴스 + board claim id, revision 이 클 때만 갱신).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('claims', function (Blueprint $table) {
            $table->unsignedBigInteger('erp_claim_id')->nullable()->after('closed_by_user_id');
            $table->timestamp('erp_sent_at')->nullable()->after('erp_claim_id');
            $table->unsignedSmallInteger('erp_sent_revision')->nullable()->after('erp_sent_at');   // 보낸 회차 — 재승인(회차+1) 뒤엔 다시 보내기
            $table->foreignId('erp_sent_by_user_id')->nullable()->after('erp_sent_revision')->constrained('users')->nullOnDelete();
            $table->string('erp_send_error', 500)->nullable()->after('erp_sent_by_user_id');      // 마지막 실패 사유(ERP 422 원문 등) — 성공하면 비움
        });
    }

    public function down(): void
    {
        Schema::table('claims', function (Blueprint $table) {
            $table->dropConstrainedForeignId('erp_sent_by_user_id');
            $table->dropColumn(['erp_claim_id', 'erp_sent_at', 'erp_sent_revision', 'erp_send_error']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 클레임 결재트리(2026-11-11 Jin) — 대표에게 바로 가던 승인 요청을 「결재선 지정 → 부장→상무→대표 차례로」(ERP 사내직원 월정산 steps 모드 미러).
 * 결재선은 요청(회차)마다 새로 만든다 — 반려 뒤 재요청 = 1단계부터 다시. 정본 = meetings/plan-claims-2026-11.md §24.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claim_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('claim_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('revision');                 // 그 결재선을 만든 요청 회차(claims.revision)
            $table->unsignedSmallInteger('seq');                      // 1 = 첫 결재자
            $table->string('title', 10);                              // 부장 · 상무 · 대표
            $table->foreignId('approver_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 12)->default('pending');         // pending · approved · rejected
            $table->timestamp('acted_at')->nullable();
            $table->foreignId('acted_by_user_id')->nullable()->constrained('users')->nullOnDelete();   // 결재자 본인 또는 super 대행
            $table->text('note')->nullable();                         // 승인 메모 · 반려 사유
            $table->timestamps();
            $table->unique(['claim_id', 'revision', 'seq']);
        });

        Schema::table('claims', function (Blueprint $table) {
            $table->unsignedSmallInteger('current_step')->nullable()->after('revision');   // 지금 차례 claim_steps.seq(결재 중일 때만)
        });
    }

    public function down(): void
    {
        Schema::table('claims', function (Blueprint $table) {
            $table->dropColumn('current_step');
        });
        Schema::dropIfExists('claim_steps');
    }
};

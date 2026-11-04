<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 업무일지 + 업무일지결재(2026-11-04 Jin, 정본 = meetings/plan-work-journal-2026-11.md).
 * 일지(사람·날짜 1개) → 상신하면 그날 묶음(batch, (date, seq))에 들어가고 → 결재선 단계(steps)를 탄다. 의견은 인원별(journal) + 전체(null).
 * 반려 없음 — 의견만. 상신 순간 표 + board 활동을 snapshot 으로 고정(결재 화면은 snapshot 만 그린다).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_journal_batches', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->unsignedSmallInteger('seq')->default(1);   // 팀장 승인 뒤 늦게 상신한 사람 = 같은 날 「추가 기안」(seq 2…)
            $table->string('status', 12)->default('pending');   // pending · approved
            $table->unsignedSmallInteger('current_step')->nullable();   // 지금 차례 steps.seq (approved 면 null)
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['date', 'seq']);
        });

        Schema::create('work_journal_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('work_journal_batches')->cascadeOnDelete();
            $table->unsignedSmallInteger('seq');
            $table->string('title', 10);   // 팀장 · 부장 · 상무 · 대표
            $table->foreignId('approver_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 12)->default('pending');   // pending · approved · skipped(그 직급 사람 없음)
            $table->timestamp('acted_at')->nullable();
            $table->timestamps();
            $table->unique(['batch_id', 'seq']);
        });

        Schema::create('work_journals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('status', 12)->default('draft');   // draft · submitted · approved
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('batch_id')->nullable()->constrained('work_journal_batches')->nullOnDelete();
            $table->json('snapshot')->nullable();   // 상신 순간 {entries:[], activities:[]}
            $table->timestamps();
            $table->unique(['user_id', 'date']);
            $table->index(['date', 'status']);
        });

        Schema::create('work_journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_journal_id')->constrained()->cascadeOnDelete();
            $table->string('time', 5)->nullable();   // HH:MM — 줄 추가 때 지금 시각, 수정 가능
            $table->text('task')->nullable();
            $table->string('note', 500)->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('work_journal_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('work_journal_batches')->cascadeOnDelete();
            $table->foreignId('step_id')->constrained('work_journal_steps')->cascadeOnDelete();
            $table->foreignId('work_journal_id')->nullable()->constrained()->cascadeOnDelete();   // null = 전체 의견
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_journal_comments');
        Schema::dropIfExists('work_journal_entries');
        Schema::dropIfExists('work_journals');
        Schema::dropIfExists('work_journal_steps');
        Schema::dropIfExists('work_journal_batches');
    }
};

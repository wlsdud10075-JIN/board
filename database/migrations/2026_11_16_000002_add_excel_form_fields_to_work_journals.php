<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 업무일지 — 해외영업부 엑셀 양식 반영(2026-11-16 Jin 「추천 순서 전부」, 정본 = meetings/plan-work-journal-2026-11.md 끝 절).
 *  - 줄마다 「위치」(사무실·화물단지·경매장·외근·직접 입력)
 *  - 일지마다 「내일 예정업무」·「특이사항」(글 칸)
 *  - 사람마다 「고정 업무」(요일·시간·위치·내용) — 그 요일 [하루 틀 만들기] 때 미리 채운다
 * 「금일 계약 차량」은 칸이 아니다 — 그날 본인 구매확정(감사 로그 status_change→won)에서 만들고 상신 때 snapshot 에 고정.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_journal_entries', function (Blueprint $table) {
            $table->string('location', 40)->nullable()->after('time');
        });
        Schema::table('work_journals', function (Blueprint $table) {
            $table->text('plan_tomorrow')->nullable()->after('snapshot');
            $table->text('remarks')->nullable()->after('plan_tomorrow');
        });
        Schema::create('work_journal_routines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday');   // 1=월 … 5=금 (ISO, 주말 없음 — 업무일지는 평일 기준)
            $table->string('time', 5)->nullable();
            $table->string('location', 40)->nullable();
            $table->string('task', 500);
            $table->timestamps();
            $table->index(['user_id', 'weekday']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_journal_routines');
        Schema::table('work_journals', function (Blueprint $table) {
            $table->dropColumn(['plan_tomorrow', 'remarks']);
        });
        Schema::table('work_journal_entries', function (Blueprint $table) {
            $table->dropColumn('location');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 업무일지 결재 이력(스레드) — 상신 의견·결재의견·반려 사유(2026-11-19 Jin · 영업팀장 요청).
 *
 * 종전 `work_journal_comments` 는 기안(batch)·단계(step)에 묶여 있어 ① 반려되면 일지가 기안에서 빠져 안 보이고
 * ② 빈 기안이 지워지면 같이 지워지고 ③ 팀장이 결재선을 다시 깔면 앞 회차 단계와 함께 사라진다.
 * 이 표는 **일지(work_journal_id)·날짜**에만 묶고 직급·이름을 글자로 남긴다 — 재상신·재결재를 몇 번 해도 남는다.
 *   work_journal_id = null → 기안 전체에 대한 줄(사람을 고르지 않은 반려 사유 등) — **결재자에게만** 보인다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_journal_notes', function (Blueprint $t) {
            $t->id();
            $t->date('date')->index();
            $t->foreignId('work_journal_id')->nullable()->constrained()->cascadeOnDelete();
            $t->unsignedInteger('batch_seq')->nullable();
            $t->string('kind', 20);   // submit(상신 의견) | opinion(결재의견) | reject(반려)
            $t->string('title', 20)->nullable();   // 기안·팀장·부장·상무·대표
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('user_name', 100)->nullable();
            $t->text('body');
            $t->string('targets', 500)->nullable();   // 반려 때 돌려보낸 사람 이름들(기안 전체 줄에만)
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_journal_notes');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 「오늘 할 일」 [확인] — 사람별로 그 줄을 숨긴다(2026-11-26 Jin · 현장 요청 「13개가 안 없어진다」).
 * signature = 줄을 만든 사실(상태 진입 시각·전송 행·탁송 요청 등)의 지문. 새 일이 생겨 지문이 바뀌면 줄이 다시 뜬다.
 * (사용자·차·줄 종류) 당 1행 — 다시 확인하면 지문만 덮어쓴다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('today_dismissals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('purchase_listing_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 30);
            $t->string('signature', 64);
            $t->timestamps();
            $t->unique(['user_id', 'purchase_listing_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('today_dismissals');
    }
};

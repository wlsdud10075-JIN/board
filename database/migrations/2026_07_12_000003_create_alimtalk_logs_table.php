<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 카카오 알림톡(BizM) 발송 감사 로그 (car-erp AlimtalkLog 미러).
 * status: sent(성공, msgid) / failed(BizM 오류·예외) / skipped(게이트 off·미설정).
 * board 는 digest(지역별 다중차량 1통)라 per-vehicle FK 대신 region 맥락만 — 차량당 dedup 은
 * purchase_listings.region_notified_at 로 별도 관리.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alimtalk_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();   // 수신자(검차원)
            $table->string('template_code', 40);
            $table->string('phone', 20);
            $table->string('region', 60)->nullable();     // 지역 digest 맥락
            $table->text('message')->nullable();           // 변수 치환 후 실제 발송 본문
            $table->string('msgid')->nullable();           // BizM 응답 msgid
            $table->string('status', 10);                  // sent | failed | skipped
            $table->string('error', 500)->nullable();
            $table->timestamps();

            $table->index(['template_code', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alimtalk_logs');
    }
};

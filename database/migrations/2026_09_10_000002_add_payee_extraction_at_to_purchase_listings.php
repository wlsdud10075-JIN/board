<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_listings', function (Blueprint $table) {
            // 추출을 시작한 시각. 구매확정 가드가 **언제까지** 기다릴지 판단하는 근거다.
            // 이게 없으면 Job 이 조용히 죽었을 때 pending 이 영원히 남아 확정이 영영 막힌다
            // (2026-09-10 운영에서 실제로 발생 — 워커가 옛 설정을 들고 있어 Job 이 무동작 종료).
            $table->timestamp('payee_extraction_at')->nullable()->after('payee_extraction_status');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_listings', function (Blueprint $table) {
            $table->dropColumn('payee_extraction_at');
        });
    }
};

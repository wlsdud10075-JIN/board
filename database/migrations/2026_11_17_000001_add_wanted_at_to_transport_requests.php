<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 탁송 희망 배차 시각(2026-11-17 Jin — 「예약 보내기」(알림톡 발송 시각) 대신 「몇 시에 와 달라」를 요청 내용에).
 * 알림톡 문구는 그대로(BizM 재승인 없음) — 업체 링크 페이지·이력·종·푸시·매입잔금 메모에만 보인다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transport_requests', function (Blueprint $t) {
            $t->timestamp('wanted_at')->nullable()->after('send_at');
        });
    }

    public function down(): void
    {
        Schema::table('transport_requests', function (Blueprint $t) {
            $t->dropColumn('wanted_at');
        });
    }
};

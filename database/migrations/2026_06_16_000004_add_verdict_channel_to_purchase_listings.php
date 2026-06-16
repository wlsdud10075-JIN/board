<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 연동 A (C) — 바이어 회신 처리 채널 분리.
 *   verdict_channel = auto   : respond.io 폴링(C)이 자동 처리. 한 바이어당 회신대기 ≤ 1대(직렬화).
 *                   = manual : 사람이 /verdicts 화면(A)에서 처리. 폴러는 안 건드림.
 * 자동/수동 작업집합을 분리해 서로 간섭 없게(Jin 결정). 기본 auto.
 * (설계 = meetings/integration-A-design.md "verdict 자동화 전략")
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_listings', function (Blueprint $table) {
            $table->string('verdict_channel')->default('auto')->after('buyer_verdict');
            $table->index(['verdict_channel', 'status']);   // 폴러 조회 (auto + awaiting_buyer)
        });
    }

    public function down(): void
    {
        Schema::table('purchase_listings', function (Blueprint $table) {
            $table->dropIndex(['verdict_channel', 'status']);
            $table->dropColumn('verdict_channel');
        });
    }
};

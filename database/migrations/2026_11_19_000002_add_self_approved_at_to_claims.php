<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 클레임 자체승인(2026-11-19 Jin) — 결재자가 버튼 대신 답장으로 승인한 건을 담당자가 캡처를 붙여 승인 처리한 시각. 재요청하면 비운다. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('claims', function (Blueprint $table) {
            $table->timestamp('self_approved_at')->nullable()->after('decision_ip');
        });
    }

    public function down(): void
    {
        Schema::table('claims', function (Blueprint $table) {
            $table->dropColumn('self_approved_at');
        });
    }
};

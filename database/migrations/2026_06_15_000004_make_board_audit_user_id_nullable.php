<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * board_audit_logs.user_id 를 nullable 로 — 시스템(연동 B Job 등 비로그인) 변경도 기록.
 * null = 시스템(자동). 사람 변경은 그대로 user_id 채움.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('board_audit_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('board_audit_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });
    }
};

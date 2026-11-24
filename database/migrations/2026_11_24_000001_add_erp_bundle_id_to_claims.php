<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 클레임 묶음 ERP 전송(2026-11-24 Jin) — 같은 바이어 승인 건을 한 번에. ERP `vehicle_claims.board_bundle_id`(문자열 40자)와 같은 값. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('claims', function (Blueprint $table) {
            $table->string('erp_bundle_id', 40)->nullable()->index()->after('erp_send_error');
        });
    }

    public function down(): void
    {
        Schema::table('claims', function (Blueprint $table) {
            $table->dropIndex(['erp_bundle_id']);
            $table->dropColumn('erp_bundle_id');
        });
    }
};

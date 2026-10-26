<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 검차 큐 연동(ssancar.com) — ssancar 가 웹훅으로 알려 주는 진행·완료·보류 값 (2026-10-23 Jin 착수, 스펙 = ssancar
 * `_ops/dev/INSPECTION_PLAN.md` §8). 상태머신은 건드리지 않는다 — 「검차중」·「보류」는 칸이고 전이는 board 소유 그대로.
 * 🚫 `ssancar_ref` 재사용 금지 — 그 칸은 미디어 조회 모드(wr_id:/car_no:)를 정한다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_listings', function (Blueprint $table) {
            $table->timestamp('inspection_started_at')->nullable()->after('ssancar_stock_photos');
            $table->string('ssancar_inspector_name', 60)->nullable()->after('inspection_started_at');   // 표시용(mb_id 는 저장 안 함)
            $table->unsignedBigInteger('ssancar_inspected_wr_id')->nullable()->after('ssancar_inspector_name');   // completed 의 검차글
            $table->timestamp('inspection_skipped_at')->nullable()->after('ssancar_inspected_wr_id');
            $table->string('inspection_skip_reason', 40)->nullable()->after('inspection_skipped_at');   // ssancar 코드 원문
            $table->string('inspection_skip_memo', 255)->nullable()->after('inspection_skip_reason');
            $table->string('inspection_skip_photo_url', 500)->nullable()->after('inspection_skip_memo');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_listings', function (Blueprint $table) {
            $table->dropColumn([
                'inspection_started_at', 'ssancar_inspector_name', 'ssancar_inspected_wr_id',
                'inspection_skipped_at', 'inspection_skip_reason', 'inspection_skip_memo', 'inspection_skip_photo_url',
            ]);
        });
    }
};

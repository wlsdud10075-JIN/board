<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 차 위치 = 매매단지(2026-11-05 Jin, ssancar 검차 「매매단지 목록」 연동 — ssancar `_ops/dev/INSPECTION_PLAN.md` §10-22).
 * 엔카 링크 없는 차는 ssancar 가 좌표를 못 만들어 「경로 밖」에 남았다 → 등록 화면에서 단지를 고르거나 주소를 직접 적어 큐에 싣는다.
 * - yard_id   = ssancar 단지 id(`yard.list`). yard_name = 고른 때의 이름 스냅샷(화면·감사용 — 목록이 바뀌어도 남는다).
 * - location_address = 「직접 입력」 주소(→ upsert `address`, ssancar 가 지오코딩).
 * - yard_status = upsert 응답 `yard`(ok|unknown). unknown = 없는/숨긴 단지라 ssancar 가 무시 → 「단지 미확인 — 다시 고르기」.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_listings', function (Blueprint $table) {
            $table->unsignedInteger('yard_id')->nullable()->after('region');
            $table->string('yard_name', 100)->nullable()->after('yard_id');
            $table->string('location_address', 255)->nullable()->after('yard_name');
            $table->string('yard_status', 10)->nullable()->after('location_address');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_listings', function (Blueprint $table) {
            $table->dropColumn(['yard_id', 'yard_name', 'location_address', 'yard_status']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 검차 스케줄 자동화 P1 — board 몫 (2026-10-29 Jin 승인, 스펙 = ssancar `_ops/dev/INSPECTION_PLAN.md` §10-10).
 *
 * - `inspection_work_date` : ssancar 가 정한 검차 예정일(upsert 응답·모든 웹훅의 `work_date`). **board 는 계산하지 않는다**(규칙은 ssancar 소유 —
 *   07:00 전 접수 = 오늘, 그 뒤·확정 뒤 = 다음 영업일, 미완 차는 07:00 이월).
 * - `inspection_urgent_*` : 영업의 「긴급 검차 요청」(드로어 버튼 → upsert `urgent`·`urgent_note`) 과 ssancar 업무관리자의 결정
 *   (`inspection.urgent_decided` 웹훅). 승인·기각은 ssancar 에서만 — board 엔 승인 UI 가 없다.
 *   `decision` = ssancar 값 원문(pending|approved|rejected). `decided_by` 는 이름만(mb_id 미보관 — 검차원과 같은 취급).
 * 상태머신은 건드리지 않는다 — 전부 칸.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_listings', function (Blueprint $table) {
            $table->date('inspection_work_date')->nullable()->after('inspection_skip_photo_url');
            $table->timestamp('inspection_urgent_requested_at')->nullable()->after('inspection_work_date');
            $table->string('inspection_urgent_note', 255)->nullable()->after('inspection_urgent_requested_at');
            $table->string('inspection_urgent_decision', 10)->nullable()->after('inspection_urgent_note');   // pending|approved|rejected (ssancar 원문)
            $table->timestamp('inspection_urgent_decided_at')->nullable()->after('inspection_urgent_decision');
            $table->string('inspection_urgent_decided_by', 60)->nullable()->after('inspection_urgent_decided_at');   // 결정자 이름(표시용)
            $table->string('inspection_urgent_decision_note', 255)->nullable()->after('inspection_urgent_decided_by');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_listings', function (Blueprint $table) {
            $table->dropColumn([
                'inspection_work_date', 'inspection_urgent_requested_at', 'inspection_urgent_note', 'inspection_urgent_decision',
                'inspection_urgent_decided_at', 'inspection_urgent_decided_by', 'inspection_urgent_decision_note',
            ]);
        });
    }
};

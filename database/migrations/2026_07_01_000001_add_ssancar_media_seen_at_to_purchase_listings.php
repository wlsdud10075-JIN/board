<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ssancar.com 미디어 감지 폴러의 "연결" 표식.
 * 폴러가 draft 매물에서 ssancar 미디어(사진/영상)를 처음 본 시각. null=아직 미매칭.
 * 에이지아웃 유예에 사용 — created_at 3일 경과라도 이 값이 있으면(연결됨) 계속 폴링,
 * 없으면(죽은 draft = 엔카 등) 폴링 제외. 도메인 필드 아님(감사 대상 X).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_listings', function (Blueprint $table) {
            $table->timestamp('ssancar_media_seen_at')->nullable()->after('car_erp_vehicle_id');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_listings', function (Blueprint $table) {
            $table->dropColumn('ssancar_media_seen_at');
        });
    }
};

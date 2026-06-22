<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 차량 첨부 연동 — inspection_photos 를 첨부 종류로 분기(additive).
 *  - inspection      : 기존 검차 사진/영상(현지확인, 바이어 전송 §28 파이프)
 *  - sales_photo     : 영업이 올리는 차량 외관 사진(→ 연동 B 로 car-erp 첨부탭)
 *  - sales_document  : 영업이 올리는 서류(차량등록증 등, 마스킹본 — 바이어 전송 제외)
 * 레거시 행은 default 로 'inspection' 백필. photos() 관계는 inspection 한정(기존 동작 보존).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inspection_photos', function (Blueprint $table) {
            $table->string('kind', 20)->default('inspection')->after('share_to_buyer')->index();
            $table->foreignId('uploaded_by_user_id')->nullable()->after('kind');
        });
    }

    public function down(): void
    {
        Schema::table('inspection_photos', function (Blueprint $table) {
            $table->dropColumn(['kind', 'uploaded_by_user_id']);
        });
    }
};

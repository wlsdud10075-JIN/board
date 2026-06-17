<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 연동 A (outbound) — 바이어에게 보낼 사진 선별 플래그.
 * §28 개인정보 레드라인: 외관 사진만 바이어 전송(서류/번호판 제외).
 * 현지확인 담당자가 사진별로 "바이어 공개"를 켠 것만 outbound 로 전송.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inspection_photos', function (Blueprint $table) {
            $table->boolean('share_to_buyer')->default(false)->after('sort');
        });
    }

    public function down(): void
    {
        Schema::table('inspection_photos', function (Blueprint $table) {
            $table->dropColumn('share_to_buyer');
        });
    }
};

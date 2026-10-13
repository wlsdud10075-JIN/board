<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ssancar 미디어 폴러의 **마지막 판정 결과**를 매물에 남긴다 (jin 2026-10-13).
 *
 * 종전엔 「미디어를 처음 본 시각」(ssancar_media_seen_at) 하나뿐이라, 현지확인에 남아 있는 draft 가
 * *왜* 전달대기로 안 넘어갔는지(사진만 있고 영상이 없는지 / 아예 없는지 / 3일 지나 폴링에서 빠졌는지)를
 * 화면이 말해 줄 수 없었다. 이 네 컬럼으로 현지확인 행 + 매입예정 드로어가 **같은 한 줄**을 그린다
 * (`PurchaseListing::ssancarHoldHint`). 화면이 ssancar 를 실시간으로 묻지 않게 하기 위한 저장이다(외부 API 절제).
 *
 * null = 폴러가 한 번도 안 봤다(신규 or 에이지아웃). 전이가 일어나면 그 시점 값이 남는다(이력 참고용).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_listings', function (Blueprint $table) {
            $table->timestamp('ssancar_checked_at')->nullable()->after('ssancar_media_seen_at');
            $table->unsignedSmallInteger('ssancar_insp_photos')->nullable()->after('ssancar_checked_at');   // 검차글 사진 수
            $table->unsignedSmallInteger('ssancar_insp_videos')->nullable()->after('ssancar_insp_photos');  // 검차글 영상 수
            $table->unsignedSmallInteger('ssancar_stock_photos')->nullable()->after('ssancar_insp_videos'); // 재고글 사진 수
        });
    }

    public function down(): void
    {
        Schema::table('purchase_listings', function (Blueprint $table) {
            $table->dropColumn(['ssancar_checked_at', 'ssancar_insp_photos', 'ssancar_insp_videos', 'ssancar_stock_photos']);
        });
    }
};

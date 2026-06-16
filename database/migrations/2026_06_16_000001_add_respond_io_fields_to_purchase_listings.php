<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 연동 A (respond.io) — inbound webhook 매칭/추출용 필드.
 *   - respond_conversation_id : 스파인(고객/방 식별, respond.io 부여). verdict webhook 매칭키. indexed.
 *   - respond_contact_id      : opaque 외부 컨택트 ID (buyer_name 문자열 보조).
 *   - encar_id                : Encar 차 식별 (URL 정규식 추출). c_no 와 별도 의미. indexed.
 * c_no(ssancar) 는 기존 컬럼 재사용. wr_id/car_no 저장모델은 A2(승격)에서 확정.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_listings', function (Blueprint $table) {
            $table->string('respond_conversation_id')->nullable()->after('c_no');
            $table->string('respond_contact_id')->nullable()->after('respond_conversation_id');
            $table->string('encar_id')->nullable()->after('respond_contact_id');

            $table->index('respond_conversation_id');
            $table->index('encar_id');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_listings', function (Blueprint $table) {
            $table->dropIndex(['respond_conversation_id']);
            $table->dropIndex(['encar_id']);
            $table->dropColumn(['respond_conversation_id', 'respond_contact_id', 'encar_id']);
        });
    }
};

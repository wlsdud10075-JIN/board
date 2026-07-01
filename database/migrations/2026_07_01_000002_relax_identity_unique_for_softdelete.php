<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * vin / (venue,lot) 유일성을 DB unique(삭제행 포함) → 앱 활성체크로 이관.
 * 소프트삭제된 매물이 vin·출품번호를 계속 점유해 "삭제 후 재등록 시 raw 500(1062)" 나던 문제 해소.
 * 활성 중복 방지는 listings save() 의 앱 체크가 담당(vehicle_number 와 동일 방식 — 삭제행은 안 막음).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_listings', function (Blueprint $table) {
            $table->dropUnique(['vin']);
            $table->dropUnique(['auction_venue', 'lot_number']);
            $table->index('vin');   // unique 제거 대신 조회용 인덱스 유지(dup 체크·enrich)
        });
    }

    public function down(): void
    {
        Schema::table('purchase_listings', function (Blueprint $table) {
            $table->dropIndex(['vin']);
            $table->unique('vin');
            $table->unique(['auction_venue', 'lot_number']);
        });
    }
};

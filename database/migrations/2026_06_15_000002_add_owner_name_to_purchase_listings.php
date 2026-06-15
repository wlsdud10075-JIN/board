<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * owner_name(소유자/차주명) 추가 — 연동 B 매칭키 보강.
 *
 * board 는 VIN 을 모른다(VIN 은 NICE 조회로만 나옴 = car-erp 책임). board 가 가진 건
 * 차량번호 + 소유자명뿐. 연동 B 는 (vehicle_number, owner_name)을 보내고, car-erp 가
 * NICE 로 VIN 을 조회해 채운다. 매칭/멱등 키 = vehicle_number (vin 아님).
 * 입력 UX = payee 와 동일(매입예정 영업 선택입력 → 이후 단계 보정).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_listings', function (Blueprint $table) {
            $table->string('owner_name')->nullable()->after('vehicle_number');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_listings', function (Blueprint $table) {
            $table->dropColumn('owner_name');
        });
    }
};

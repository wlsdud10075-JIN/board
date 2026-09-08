<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 재고매입(바이어 미정) — 차값이 쌀 때 바이어 없이 미리 사두는 매입 (2026-09-08 Jin).
 *
 * ⚠️ **`car_erp_buyer_id IS NULL` 로 대신하지 않는다.** 그러면 "실수로 안 고른 것"과 구분이 안 된다
 *    (car-erp `vehicles.buyer_undecided` 도 같은 이유로 별도 컬럼 — 그쪽 주석: "빈 채로 두는 것만으로
 *    통과시키지 않는다"). 사람이 **명시적으로 켠 것**이라는 사실 자체가 정보다.
 *
 * 연동 B `contract_version: 5` 로 car-erp `vehicles.buyer_undecided` 에 그대로 전달된다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_listings', function (Blueprint $table) {
            $table->boolean('buyer_undecided')->default(false)->after('car_erp_consignee_id');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_listings', function (Blueprint $table) {
            $table->dropColumn('buyer_undecided');
        });
    }
};

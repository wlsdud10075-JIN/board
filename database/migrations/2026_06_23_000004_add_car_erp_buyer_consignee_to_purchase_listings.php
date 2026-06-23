<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 연동B v3 — 경매/구매에서 car-erp 바이어/컨사이니를 드롭다운 선택한 값 보관.
     * payload(buyer_id/consignee_id)로 전송 → car-erp vehicles FK 세팅. 미선택=null(car-erp 수동).
     */
    public function up(): void
    {
        Schema::table('purchase_listings', function (Blueprint $table) {
            $table->unsignedInteger('car_erp_buyer_id')->nullable()->after('car_erp_vehicle_id');
            $table->unsignedInteger('car_erp_consignee_id')->nullable()->after('car_erp_buyer_id');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_listings', function (Blueprint $table) {
            $table->dropColumn(['car_erp_buyer_id', 'car_erp_consignee_id']);
        });
    }
};

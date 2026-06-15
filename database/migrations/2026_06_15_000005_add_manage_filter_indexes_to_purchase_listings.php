<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * /manage 필터·정렬용 인덱스 — 운영 수천 건에서 필터/페이지네이션 성능.
 * status·source·region·c_no 는 기존 인덱스 있음. 여기서 보강.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_listings', function (Blueprint $table) {
            $table->index('created_by_user_id');
            $table->index('buyer_verdict');
            $table->index('car_erp_vehicle_id');
            $table->index('created_at');   // latest() 정렬 + 오늘 필터
        });
    }

    public function down(): void
    {
        Schema::table('purchase_listings', function (Blueprint $table) {
            $table->dropIndex(['created_by_user_id']);
            $table->dropIndex(['buyer_verdict']);
            $table->dropIndex(['car_erp_vehicle_id']);
            $table->dropIndex(['created_at']);
        });
    }
};

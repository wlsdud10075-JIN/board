<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 관리(role=manager) ↔ 영업 담당 지정 (2026-11-02 Jin — 「ERP 처럼 영업을 관리에게 지정하면 그 영업의 정보를 볼 수 있게, 정산 관련은 전부 제외」).
 * car-erp `manager_salesman` pivot 과 같은 이름·방향(manager_user_id → salesman_user_id, N:N).
 * 용도 = `/portal` 사용자별 조회 대상(조회 전용, 정산 숨김). 지정은 super·업무관리자만(`/users`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manager_salesman', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manager_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('salesman_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['manager_user_id', 'salesman_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manager_salesman');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 현지검차 지역 배정 (§6c) — 관리가 그날치 인원을 지역에 분배. 지역×날짜에 최대 3인(앱에서 강제).
        Schema::create('inspection_assignments', function (Blueprint $table) {
            $table->id();
            $table->date('date');                                   // 배정 날짜
            $table->string('region');                               // 검사지역 (purchase_listings.region 과 동일 표기)
            $table->foreignId('user_id')->constrained('users');     // 현지확인(inspection) role
            $table->timestamps();

            $table->unique(['date', 'region', 'user_id']);          // 같은 날·지역·사람 중복 배정 방지
            $table->index(['date', 'region']);
            $table->index(['user_id', 'date']);                     // "내 오늘 배정" 조회
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_assignments');
    }
};

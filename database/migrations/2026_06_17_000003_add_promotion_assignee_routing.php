<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 연동 A 승격 라우팅 — 승격 대기를 담당 영업에게만(SalesmanScope 격리 일치).
 *   - promotion_requests.assigned_email : respond.io 대화 담당 상담원(assignee.email) 캡처(폴 시).
 *   - users.respond_agent_email         : board 영업 ↔ respond.io 상담원 매핑(없으면 로그인 이메일 폴백).
 * 매칭: 영업은 assigned_email = 본인 respond 이메일인 대기만. 미배정/무매칭 = 관리자 풀(canSeeAll).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotion_requests', function (Blueprint $table) {
            $table->string('assigned_email')->nullable()->after('label')->index();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->string('respond_agent_email')->nullable()->after('car_erp_salesman_email');
        });
    }

    public function down(): void
    {
        Schema::table('promotion_requests', function (Blueprint $table) {
            $table->dropIndex(['assigned_email']);
            $table->dropColumn('assigned_email');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('respond_agent_email');
        });
    }
};

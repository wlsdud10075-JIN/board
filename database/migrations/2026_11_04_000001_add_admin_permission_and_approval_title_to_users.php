<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 최고관리자 등급(`admin`) + 결재 직급(`approval_title`) — 2026-11-04 Jin, car-erp 미러(super > admin > manager > user).
 * 결재 직급 = 업무일지 결재선: 업무관리자 중 지정 1명 = 「팀장」, 최고관리자 = 「부장」·「상무」·「대표」.
 * `permission` 이 ENUM 이라 값을 넓히려면 컬럼 정의를 바꾼다(선례 2026_10_14_000001).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('permission', ['super', 'admin', 'manager', 'user'])->default('user')->change();
            $table->string('approval_title', 10)->nullable()->after('permission');
        });
    }

    public function down(): void
    {
        // admin 을 업무관리자로 내린 뒤 좁힌다(안 내리면 ENUM 축소가 실패한다).
        DB::table('users')->where('permission', 'admin')->update(['permission' => 'manager']);
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('approval_title');
            $table->enum('permission', ['super', 'manager', 'user'])->default('user')->change();
        });
    }
};

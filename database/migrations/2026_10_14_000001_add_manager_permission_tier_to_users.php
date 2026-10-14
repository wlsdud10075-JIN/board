<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 권한 등급에 업무관리자(`manager`) 추가 — car-erp 미러(super > manager > user). 2026-10-14 Jin.
 * `permission` 이 ENUM 이라 값 하나를 더 받으려면 컬럼 정의를 바꿔야 한다(문자열 컬럼이었다면 마이그 불필요).
 * 기존 행 값(super/user)은 그대로 유효.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('permission', ['super', 'manager', 'user'])->default('user')->change();
        });
    }

    public function down(): void
    {
        // manager 를 user 로 내린 뒤 좁힌다(안 내리면 ENUM 축소가 실패한다).
        DB::table('users')->where('permission', 'manager')->update(['permission' => 'user']);
        Schema::table('users', function (Blueprint $table) {
            $table->enum('permission', ['super', 'user'])->default('user')->change();
        });
    }
};

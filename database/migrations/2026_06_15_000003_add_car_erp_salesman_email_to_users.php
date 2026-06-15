<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * car_erp_salesman_email — 연동 B 영업 매칭 오버라이드(이메일).
 *
 * 매칭은 car-erp 가 salesman_email 로 함(salesmen.email/users.email). board 로그인
 * 이메일이 car-erp 영업 이메일과 다를 때, 관리자가 car-erp 이메일을 여기 적으면
 * board 가 push 시 그 이메일을 salesman_email 로 보낸다. (숫자 id 는 DB 봐야 알아서 폐기)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('car_erp_salesman_email')->nullable()->after('car_erp_salesman_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('car_erp_salesman_email');
        });
    }
};

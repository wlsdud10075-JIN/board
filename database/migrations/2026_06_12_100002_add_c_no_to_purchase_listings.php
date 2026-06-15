<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_listings', function (Blueprint $table) {
            // ssancar.com 매물번호 = 전 시스템 조인키(c_no). nullable·index·NON-unique.
            // 지금은 영업 수동 입력, 추후 연동 A(respond.io)에서 자동 캡처.
            $table->string('c_no')->nullable()->after('region');
            $table->index('c_no');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_listings', function (Blueprint $table) {
            $table->dropIndex(['c_no']);
            $table->dropColumn('c_no');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 상단 종(요청 결과 알림) 읽음 시각 (jin 2026-10-12).
 *
 * board 영업이 보낸 §11 요청(계약금·매입잔금·판매대금확인)의 **결과**(ERP 확인·거절)는 ERP 가 갖고 있고
 * board 는 `GET /requests` 로 읽기만 한다. 「안 읽은 결과 N건」을 세려면 **이 사용자가 마지막으로 패널을
 * 연 시각** 하나만 board 에 있으면 된다 — 결과 자체를 board 에 복제하지 않는다(판정 지점이 둘로 갈린다).
 *
 * null = 아직 한 번도 안 봄 → 컴포넌트가 첫 마운트에 now() 로 채운다(과거 이력 전부를 미읽음으로 세지 않게).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('portal_seen_at')->nullable()->after('locale');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('portal_seen_at');
        });
    }
};

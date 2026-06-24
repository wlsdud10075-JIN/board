<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 상태머신에 `inspected`(검차완료·전달대기) 추가.
 * draft → inspected → awaiting_buyer. 검차는 검차완료까지, 바이어 전달은 영업(/forwarding)이.
 * enum 확장만 — 데이터 무변(additive). MySQL=ALTER MODIFY, SQLite(test)=테이블 재빌드(change()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_listings', function (Blueprint $table) {
            $table->enum('status', [
                'draft', 'inspected', 'awaiting_buyer', 'accepted', 'rejected', 'won', 'failed', 'synced',
            ])->default('draft')->change();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_listings', function (Blueprint $table) {
            $table->enum('status', [
                'draft', 'awaiting_buyer', 'accepted', 'rejected', 'won', 'failed', 'synced',
            ])->default('draft')->change();
        });
    }
};

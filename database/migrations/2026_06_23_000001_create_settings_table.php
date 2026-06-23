<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 런타임 전역 설정 key-value 저장소 (car-erp settings 미러).
     * 관리자(super)가 화면에서 바꾸는 설정. 정적 config(config/board.php)와 별개.
     */
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('value');
            $table->enum('type', ['boolean', 'string', 'integer'])->default('string');
            $table->string('description')->nullable();
            $table->timestamps();
        });

        // 사이드바 + 로그인 화면 공통 브랜드 텍스트 (최대 12자). 한 곳에서 바꾸면 둘 다 반영.
        DB::table('settings')->updateOrInsert(
            ['key' => 'sidebar_brand'],
            [
                'value' => 'HeymanBoard',
                'type' => 'string',
                'description' => '사이드바·로그인 브랜드 텍스트 (최대 12자)',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};

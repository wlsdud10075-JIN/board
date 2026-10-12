<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 웹푸시 구독 (jin 2026-10-12) — "영업은 운전 중이라 화면을 못 본다" → 화면이 꺼져 있어도 폰이 울리게.
 *
 * 브라우저(PWA)가 발급한 push 구독 1건 = 1행. 한 사용자가 폰·PC 여러 기기를 쓰면 여러 행.
 * `endpoint` 는 500자를 넘기도 해서 유일성은 **sha256 해시 컬럼**에 건다(utf8mb4 varchar(500) 인덱스 길이 함정 회피).
 * 만료(410/404)된 구독은 발송 측(`WebPushService`)이 지운다.
 *
 * `users.push_notified_at` = 이 사용자에게 **마지막으로 결과를 밀어 보낸 기준 시각**(스케줄러 커서). 상단 종의
 * `portal_seen_at`(사람이 읽은 시각)과 **다른 축**이다 — 섞으면 "읽었으니 안 보냄"/"보냈으니 읽은 셈"이 된다.
 * null = 아직 한 번도 안 돌았다 → 첫 실행은 now 로 찍고 과거 이력은 보내지 않는다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('endpoint', 1000);
            $table->char('endpoint_hash', 64)->unique();
            $table->string('p256dh', 255);
            $table->string('auth_token', 255);
            $table->string('content_encoding', 20)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('push_notified_at')->nullable()->after('portal_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('push_notified_at');
        });
        Schema::dropIfExists('push_subscriptions');
    }
};

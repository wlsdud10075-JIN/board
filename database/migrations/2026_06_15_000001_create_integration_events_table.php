<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * integration_events — 외부 연동(B: car-erp / A: respond.io)의 push/콜백 append-only 로그.
 * board_audit_logs(도메인 필드·상태변경)와 별개. updated_at 없음(불변).
 * 연동 B 에서 신설, 연동 A inbound 가 external_event_id 로 멱등성 확보 시 재사용.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_events', function (Blueprint $table) {
            $table->id();
            $table->string('direction');            // outbound | inbound
            $table->string('target');               // car_erp | respond_io
            $table->string('event_type');           // purchase_sync | buyer_verdict | ...
            $table->foreignId('purchase_listing_id')->nullable()->index();
            $table->string('external_event_id')->nullable();  // inbound 중복제거 키
            $table->json('request_payload')->nullable();      // payee_account 등 민감값은 마스킹 후 기록
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('response_body')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['target', 'external_event_id']);   // inbound dedupe 조회
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_events');
    }
};

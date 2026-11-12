<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 탁송 배차 요청(2026-11-12 Jin, 정본 = meetings/plan-transport-2026-11.md).
 *  - transport_companies / transport_company_phones : 업체·번호 n개(관리 이상이 화면에서 즉시 추가)
 *  - transport_requests      : 매물 1대 = 요청 1건(회차 revision, 거절·만료 뒤 재요청은 새 회차)
 *  - transport_request_recipients : 발송 수신자 스냅샷(업체 번호·임의 번호·영업·관리) — 서명 링크의 `r`, 누가 눌렀는지
 *  - transport_request_files : 명함·차량 외관 ≤5장. ⚠️ inspection_photos 에 끼우지 않는다(그 표는 연동 B 첨부로 ERP 에 흘러간다).
 * 전화는 전부 encrypted(seller_phone 선례). 감사는 board_audit_logs(action transport_*).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transport_companies', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->string('code', 30)->nullable();   // 선택 — ERP 탁송비 명세서 파서명(wika/gucheonyuk/hyundai_a1)과 맞추면 비용 매칭이 쉽다
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('transport_company_phones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transport_company_id')->constrained()->cascadeOnDelete();
            $table->text('phone');                      // encrypted
            $table->string('label', 30)->nullable();    // 기사 이름·사무실 등
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('transport_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_listing_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('revision')->default(1);
            $table->foreignId('transport_company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('company_name', 60)->nullable();   // 발송 시점 스냅샷(업체 이름이 바뀌어도 이력은 그대로)
            $table->text('extra_phone')->nullable();          // encrypted — 임의 번호 1개
            $table->string('address', 255)->nullable();
            $table->string('message', 255);
            $table->string('stock_location', 20)->nullable(); // 도착 위치(홈플/화물/야드 — config board.stock_locations)
            $table->timestamp('send_at');                     // 예약 발송 시각(지금 = now)
            $table->string('status', 16)->index();            // scheduled | sent | accepted | rejected | expired | cancelled
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->unsignedBigInteger('decided_recipient_id')->nullable();
            $table->string('decided_name', 60)->nullable();   // 기사가 페이지에서 적은 이름(선택)
            $table->string('reject_reason', 200)->nullable();
            $table->foreignId('requested_by_user_id')->constrained('users');
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('balance_requested_at')->nullable();      // §11 매입잔금 요청을 이 배차로 보낸 시각
            $table->string('balance_request_error', 500)->nullable();
            $table->timestamp('erp_location_synced_at')->nullable();    // ERP 보관 위치 전송(ERP 경로 생기면)
            $table->string('erp_location_error', 500)->nullable();
            $table->timestamps();
            $table->unique(['purchase_listing_id', 'revision']);
            $table->index(['status', 'send_at']);
        });

        Schema::create('transport_request_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transport_request_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);                 // company_phone | extra | sales | manager
            $table->text('phone');                      // encrypted
            $table->string('label', 60)->nullable();    // 기사 이름(업체 번호 라벨) · 사용자 이름
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('alimtalk_log_id')->nullable();   // 요청 알림톡 로그
            $table->string('sent_status', 10)->nullable();               // sent | failed | skipped
            $table->unsignedBigInteger('result_log_id')->nullable();     // 결과 알림톡 로그(영업·관리)
            $table->timestamps();
            $table->index(['transport_request_id', 'kind']);
        });

        Schema::create('transport_request_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transport_request_id')->constrained()->cascadeOnDelete();
            $table->string('s3_path', 512);
            $table->string('original_name', 255);
            $table->string('mime', 100)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transport_request_files');
        Schema::dropIfExists('transport_request_recipients');
        Schema::dropIfExists('transport_requests');
        Schema::dropIfExists('transport_company_phones');
        Schema::dropIfExists('transport_companies');
    }
};

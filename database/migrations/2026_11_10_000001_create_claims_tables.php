<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 클레임 탭 1차(2026-11-10 Jin 착수, 정본 = meetings/plan-claims-2026-11.md).
 * 영업 접수(포털 판매내역 행) → 지정 담당자 처리·보고서(양식 docx 칸을 그대로) → 대표 승인(서명 링크) → 수동 통지.
 * 차량·바이어 값은 접수 시점 ERP **스냅샷**(키 = car_erp_vehicle_id — board 를 안 거친 차도 클레임이 온다).
 * 금액 상계·ERP 연동은 2차(§15) — 여기엔 그 칸이 없다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claims', function (Blueprint $table) {
            $table->id();
            $table->string('status', 16)->default('received');   // received · reviewing · pending_ceo · approved · rejected · closed
            $table->unsignedSmallInteger('revision')->default(0);   // 승인요청 회차 — 재승인(합의액 변경)마다 +1, 서명 링크에 묶인다

            // ── 차량·바이어 스냅샷(ERP, 접수 시) ──
            $table->unsignedBigInteger('car_erp_vehicle_id')->index();
            $table->foreignId('purchase_listing_id')->nullable()->constrained()->nullOnDelete();   // board 에 있는 차면(검차 사진 연결)
            $table->string('vehicle_number', 32);
            $table->string('vin', 32)->nullable();
            $table->string('brand', 64)->nullable();
            $table->string('model_type', 64)->nullable();
            $table->unsignedBigInteger('car_erp_buyer_id')->nullable();
            $table->string('buyer_name', 120)->nullable();           // COMPANY = ERP buyers.name
            $table->string('buyer_contact_name', 120)->nullable();   // BUYER NAME = 영업 수기(§22 Q-Q)
            $table->decimal('sale_price', 14, 2)->nullable();
            $table->string('sale_currency', 3)->nullable();
            $table->date('sale_date')->nullable();
            $table->date('eta_date')->nullable();
            $table->unsignedInteger('total_purchase_count')->nullable();   // by-buyer vehicle_count
            $table->unsignedInteger('year_purchase_count')->nullable();    // /sales 최근 12개월 건수
            $table->unsignedSmallInteger('member_since')->nullable();      // 첫 판매 연도
            $table->json('purchase_history')->nullable();                  // NOTES 표(최근 12개월, 판매통화)
            $table->decimal('margin_rate', 8, 2)->nullable();              // ERP /settlements margin_rate(그 차량) — PROFIT 칸
            $table->timestamp('erp_snapshot_at')->nullable();

            // ── 접수(영업) ──
            $table->foreignId('salesman_user_id')->constrained('users');      // SALES AGENT(명의 영업)
            $table->foreignId('created_by_user_id')->constrained('users');    // 실제 누른 사람(대리 접수 구분)
            $table->date('arrived_at');                                       // 차량 도착일(기본 ETA, 영업 수정) — +14 기준
            $table->unsignedInteger('received_km');                           // 접수 당시 주행거리(바이어 제공)
            $table->text('content');                                          // 클레임 내용

            // ── 담당자 처리(양식 1p·2p 칸) ──
            $table->string('buyer_priority', 8)->nullable();                  // high · medium · low
            $table->integer('claim_history_adjust')->default(0);              // CLAIM HISTORY(1 YEAR) 수기 보정(기능 시작 전 건수)
            $table->text('inspection_result')->nullable();                    // INSPECTION REPORT RESULT 요약
            $table->text('notes')->nullable();                                // 1p NOTES 수기
            $table->boolean('evidence_verified')->default(false);             // EVIDENCE (VERIFIED)
            $table->text('review_notes')->nullable();                         // CLAIM 블록 NOTES
            $table->decimal('claim_amount', 14, 2)->nullable();               // CLAIM AMOUNT
            $table->string('claim_currency', 3)->nullable();
            $table->decimal('refundable_amount', 14, 2)->nullable();          // REFUNDABLE
            $table->text('result')->nullable();                               // RESULT(처리안)
            $table->text('result_reason')->nullable();                        // REASON
            $table->string('compensation_type', 8)->nullable();               // refund · credit
            $table->text('overdue_reason')->nullable();                       // 도착일+14 초과 처리 사유
            $table->text('bank_name')->nullable();                            // 2p RECIPIENT'S DETAILS — 선택·encrypted(payee 와 같은 예외)
            $table->text('bank_iban')->nullable();
            $table->text('bank_swift')->nullable();
            $table->text('bank_phone')->nullable();
            $table->text('bank_address')->nullable();

            // ── 대표 승인 ──
            $table->timestamp('requested_at')->nullable();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('request_notified_at')->nullable();             // 알림톡 재전송 10분 연타 방지
            $table->foreignId('approver_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision', 10)->nullable();                       // approved · rejected
            $table->text('decision_reason')->nullable();
            $table->string('decision_ip', 45)->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'created_at']);
            $table->index('salesman_user_id');
            $table->index('decided_at');
        });

        Schema::create('claim_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('claim_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 12);                 // odometer(계기판) · damage(손상 사진·영상) · invoice(정비소 INVOICE) · other
            $table->string('s3_path', 512);
            $table->string('original_name', 255);
            $table->string('mime', 100)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['claim_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claim_files');
        Schema::dropIfExists('claims');
    }
};

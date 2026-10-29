<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 송금인(여권/ID 카드) Name·문서번호 + board 전용 COMMERCIAL INVOICE (2026-10-28 Jin 결정 ②, 기획서 plan-2026-11-weekend §②).
 *
 * - `remitter_name`  : 문서 표기 그대로(라틴 대문자 `SURNAME GIVEN`) — ERP `vehicles.remitter_name`(검색 「송금증이름」) 과 같은 값, 평문.
 * - `remitter_id`    : 문서 번호(여권 = passport no., ID 카드 = card no. — 개인번호 ❌). 여권번호는 RRN 급 → cast `encrypted`(text), 감사 ***.
 * - `remitter_synced_at` : 전용 엔드포인트/purchase-sync 로 ERP 에 넘어간 시각(이름·ID 를 바꾸면 null 로 되돌린다).
 * - `passport_ocr_status`·`passport_ocr_at`·`passport_suggestions` : OCR 후보(계좌추출 `payee_*` 와 같은 틀). 후보에도 여권번호가 들어가 encrypted:json.
 *   **이미지는 OCR 뒤 폐기** — 컬럼도 S3 경로도 없다(Jin 2026-10-28 「이미지는 버려도 되고」).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_listings', function (Blueprint $table) {
            $table->string('remitter_name', 100)->nullable()->after('seller_phone');
            $table->text('remitter_id')->nullable()->after('remitter_name');            // 암호화 저장
            $table->timestamp('remitter_synced_at')->nullable()->after('remitter_id');
            $table->string('passport_ocr_status', 12)->nullable()->after('remitter_synced_at');   // pending|done|none|failed
            $table->timestamp('passport_ocr_at')->nullable()->after('passport_ocr_status');
            $table->text('passport_suggestions')->nullable()->after('passport_ocr_at');          // 암호화 json
        });
    }

    public function down(): void
    {
        Schema::table('purchase_listings', function (Blueprint $table) {
            $table->dropColumn(['remitter_name', 'remitter_id', 'remitter_synced_at', 'passport_ocr_status', 'passport_ocr_at', 'passport_suggestions']);
        });
    }
};

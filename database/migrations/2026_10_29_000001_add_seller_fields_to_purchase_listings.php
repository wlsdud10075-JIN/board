<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 매입처(딜러) 3칸 — 사업자등록증 법인명·대표자 + 명함 휴대폰 (2026-10-28 Jin 결정 ④, 기획서 plan-2026-11-weekend §④).
 *
 * 구매확정 첨부사진에서 계좌와 같은 Job 이 읽어 **후보로 제안**하고, 사람이 /auction 드로어에서 적용한다.
 * 연동 B 로 `seller_company`·`seller_ceo`·`seller_phone`(숫자만) 동봉 → ERP `purchase_from`(「법인명/대표자」 합성은 ERP)·
 * `deregistration_notice_phone`(국내딜러 알림톡) **빈 칸만** 채움.
 *
 * ⚠️ `seller_phone` 은 계좌번호(`payee_account`)와 같은 취급 — 모델 cast `encrypted` 라 text 컬럼(암호문이 20자를 훨씬 넘는다).
 *    감사로그·integration_events 로그는 `***` 마스킹. board 「전화 미보유 원칙」의 범위한정 예외(Jin 2026-10-28).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_listings', function (Blueprint $table) {
            $table->string('seller_company', 100)->nullable()->after('selling_fee_payee_account');   // 법인명(상호) — ERP ≤100
            $table->string('seller_ceo', 50)->nullable()->after('seller_company');                   // 대표자 — ERP ≤50
            $table->text('seller_phone')->nullable()->after('seller_ceo');                           // 휴대폰 (암호화 저장, 전송 시 숫자만 ≤20)
        });
    }

    public function down(): void
    {
        Schema::table('purchase_listings', function (Blueprint $table) {
            $table->dropColumn(['seller_company', 'seller_ceo', 'seller_phone']);
        });
    }
};

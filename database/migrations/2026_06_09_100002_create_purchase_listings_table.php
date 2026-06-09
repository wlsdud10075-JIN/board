<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by_user_id')->constrained('users');

            $table->enum('source', ['encar', 'auction']);

            // 식별값 — 등록 후 수정 불가 (모델 가드)
            $table->string('vehicle_number');
            $table->string('vin')->nullable();

            // 금액
            $table->unsignedBigInteger('expected_price')->nullable();   // 예상가(매물 표시가)
            $table->unsignedBigInteger('final_price')->nullable();      // 현지 최종금액

            // 엔카 전용
            $table->string('encar_url')->nullable();
            $table->string('encar_dealer')->nullable();

            // 경매 전용
            $table->string('auction_venue')->nullable();
            $table->string('lot_number')->nullable();

            // 상태머신
            $table->enum('status', [
                'draft', 'awaiting_buyer', 'accepted', 'rejected', 'won', 'failed', 'synced',
            ])->default('draft');
            $table->enum('buyer_verdict', ['none', 'pending', 'accepted', 'rejected'])->default('none');

            $table->string('buyer_name')->nullable();      // respond.io 연락처 (추후 contact_id)
            $table->text('inspection_memo')->nullable();   // 차 상태 메모

            $table->dateTime('lock_at')->nullable();       // 경매만 — 서버측 잠금 시각 (주말 NULL)
            $table->unsignedBigInteger('car_erp_vehicle_id')->nullable(); // 연동 B 역참조 (1차 예약)

            $table->timestamps();
            $table->softDeletes();

            // 중복 방지 식별키 — NULL 다중 허용 (엔카는 venue/lot NULL, 경매는 vin)
            $table->unique('vin');
            $table->unique(['auction_venue', 'lot_number']);
            $table->index('status');
            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_listings');
    }
};

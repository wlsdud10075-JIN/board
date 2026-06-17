<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 연동 A — 승격 자동연결(promotion auto-link). respond.io `board_promote=Yes` 폴링 결과 보관.
 *
 * verdict 폴러와 다른 점: 붙일 listing 이 아직 없음(승격 전) → integration_events(append-only)로
 * 못 담음 → "승격 대기" 를 담을 durable 테이블이 필요. 영업이 이 대기건을 보고 링크+차번호로 승격.
 *   - status: pending(대기) → consumed(listing 생성됨) | dismissed(영업 무시) | expired(7일 방치).
 *   - 멱등: 같은 contact 에 pending 1건만(폴러가 코드 가드로 강제). DB unique 는 안 검 — consumed/
 *     dismissed 는 같은 contact 에 여러 건 정상(바이어 1 : listing N).
 *   - respond_contact_id = 스파인. label = 사람이 알아볼 이름/전화(메시지 본문은 API 로 못 읽음).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotion_requests', function (Blueprint $table) {
            $table->id();
            $table->string('respond_contact_id')->index();   // 바이어(스파인)
            $table->string('label')->nullable();             // 표시용 이름/전화(자동연결 식별 보조)
            $table->string('status')->default('pending');    // pending|consumed|dismissed|expired
            $table->foreignId('purchase_listing_id')->nullable();   // consumed 시 연결된 listing
            $table->foreignId('handled_by_user_id')->nullable();    // consume/dismiss 한 영업
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_requests');
    }
};

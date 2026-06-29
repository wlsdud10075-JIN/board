<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 검차 미디어 기본 공유(opt-out) 전환 — 업로드 기본값을 "공유"로 바꾼 결정(2026-06-29 Jin)에 맞춰
 * 기존 미토글(share_to_buyer=false) 검차 사진/영상도 바이어 공개 페이지에 노출되도록 일괄 전환.
 * 근거: 기존 "사진 공유시트"가 이미 검차 사진 전부를 바이어에게 보내고 있어 §28 노출은 동일(parity).
 * 서류(kind=sales_document)는 대상 아님 — §28 강제 false 유지(바이어 전송 제외).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('inspection_photos')
            ->where('kind', 'inspection')
            ->where('share_to_buyer', false)
            ->update(['share_to_buyer' => true]);
    }

    public function down(): void
    {
        // 원래 false/true 구분 정보가 소실되므로 역전 불가 — no-op.
    }
};

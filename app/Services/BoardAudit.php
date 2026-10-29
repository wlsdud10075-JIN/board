<?php

namespace App\Services;

use App\Models\BoardAuditLog;
use App\Models\PurchaseListing;

/**
 * 돈·상태 변경 + 관리자 override 의 단일 기록 경로.
 * 변경 전 스냅샷과 비교해 필드별 append-only 로그 생성.
 */
class BoardAudit
{
    /** 민감 필드(계좌번호 등) — 감사로그엔 값 대신 마스킹만 기록(§6e). */
    private const MASKED = ['payee_account', 'selling_fee_payee_account', 'seller_phone'];

    /**
     * 진짜 바뀌었나 — 숫자는 숫자로 비교한다(「12100.00」→「12100」 재저장은 변경 아님). ERP 는 이런 소음이 감사의 83% 였다
     * (car-erp `AuditLog::isRealChange`, 2026-10-24 대조). board 는 decimal 판매가·운임비(2026-10-22 쪼개기)부터 같은 위험이 생겼다.
     */
    public static function isRealChange(mixed $old, mixed $new): bool
    {
        if (($old === null || $old === '') && ($new === null || $new === '')) {
            return false;
        }
        if (is_numeric($old) && is_numeric($new)) {
            return (float) $old !== (float) $new;
        }

        return (string) $old !== (string) $new;
    }

    /**
     * 등록 시점 기록 — 「등록」 1행(field=created) + 등록 때 이미 채워진 AUDITED 필드 각 1행(∅ → 값).
     * 수정 기록만으론 등록할 때 넣은 값(판매가·환율·바이어)이 누구 손인지 영영 안 남는다.
     */
    public static function logCreation(PurchaseListing $listing, ?int $userId): void
    {
        $rows = [[
            'user_id' => $userId,
            'purchase_listing_id' => $listing->id,
            'action' => 'create',
            'field' => 'created',
            'old_value' => null,
            'new_value' => $listing->vehicle_number,
            'created_at' => now(),
            'ip_address' => BoardAuditLog::currentIp(),
        ]];

        foreach (PurchaseListing::AUDITED as $field) {
            $new = $listing->$field;
            // 비어 있는 칸은 행을 만들지 않는다. 0 도 — 기본값 0(매도비 등)이 매 등록마다 「∅ → 0」으로 찍히면 소음.
            if ($new === null || $new === '' || $new === false || (is_numeric($new) && (float) $new === 0.0)) {
                continue;
            }
            // 스키마 기본값(회신 none · 차값통화 KRW)은 「넣은 값」이 아니다 — 매 등록마다 찍히면 등록 블록이 안 읽힌다.
            if (($field === 'buyer_verdict' && $new === 'none') || ($field === 'expected_price_currency' && $new === 'KRW')) {
                continue;
            }
            $masked = in_array($field, self::MASKED, true);
            $rows[] = [
                'user_id' => $userId,
                'purchase_listing_id' => $listing->id,
                'action' => 'create',
                'field' => $field,
                'old_value' => null,
                'new_value' => $masked ? '***' : (string) $new,
                'created_at' => now(),
                'ip_address' => BoardAuditLog::currentIp(),
            ];
        }

        BoardAuditLog::insert($rows);
    }

    /** @param  ?int  $userId  null = 시스템(연동 Job 등 비로그인) */
    public static function logChanges(PurchaseListing $listing, array $original, array $fields, ?int $userId): void
    {
        foreach ($fields as $field) {
            $old = $original[$field] ?? null;
            $new = $listing->$field;

            if (! self::isRealChange($old, $new)) {
                continue;
            }

            $masked = in_array($field, self::MASKED, true);

            BoardAuditLog::create([
                'user_id' => $userId,
                'purchase_listing_id' => $listing->id,
                'action' => $field === 'status' ? 'status_change' : 'field_edit',
                'field' => $field,
                'old_value' => $masked ? ($old === null ? null : '***') : ($old === null ? null : (string) $old),
                'new_value' => $masked ? ($new === null ? null : '***') : ($new === null ? null : (string) $new),
            ]);
        }
    }
}

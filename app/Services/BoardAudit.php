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
    public static function logChanges(PurchaseListing $listing, array $original, array $fields, int $userId): void
    {
        foreach ($fields as $field) {
            $old = $original[$field] ?? null;
            $new = $listing->$field;

            if ((string) $old === (string) $new) {
                continue;
            }

            BoardAuditLog::create([
                'user_id' => $userId,
                'purchase_listing_id' => $listing->id,
                'action' => $field === 'status' ? 'status_change' : 'field_edit',
                'field' => $field,
                'old_value' => $old === null ? null : (string) $old,
                'new_value' => $new === null ? null : (string) $new,
            ]);
        }
    }
}

<?php

namespace App\Support;

use App\Models\BoardAuditLog;
use App\Models\PurchaseListing;
use App\Models\User;
use App\Services\CarErpReadService;
use Illuminate\Support\Carbon;

/**
 * 매입 락 승인 결과(ERP 승인큐의 승인·거절)를 종·웹푸시로 알리기 위한 조회(2026-11-19, car-erp `board-portal-api.md` §17-3).
 *
 * ERP 추가 호출을 **최근에 요청을 보낸 사람에게만** 건다 — 종은 30초마다, 푸시는 매분 도는데 `/api/internal/board` 는 분당 120 공유 한도다.
 * 누구에게 = 요청을 누른 사람 + 그 차를 등록한 영업. 근거 = board 감사 행 `erp_purchase_lock_request`(최근 8일 = 승인 유효 7일 + 여유).
 * 조회 명의 = 그 요청을 보낸 등록 영업 이메일(감사 행의 `as`) — 대리로 누른 관리의 이메일은 ERP 스코프에 없다.
 */
class PurchaseLockResults
{
    public const WINDOW_DAYS = 8;

    /** @return list<string> 이 사용자가 결과를 받아야 하는 요청들의 등록 영업 이메일(없으면 빈 배열 = ERP 호출 없음) */
    public static function emailsFor(User $u): array
    {
        $mine = PurchaseListing::withoutGlobalScopes()->withTrashed()->where('created_by_user_id', $u->id)->select('id');

        return BoardAuditLog::query()
            ->where('action', 'erp_purchase_lock_request')
            ->where('created_at', '>=', now()->subDays(self::WINDOW_DAYS))
            ->where(fn ($w) => $w->where('user_id', $u->id)->orWhereIn('purchase_listing_id', $mine))
            ->pluck('new_value')
            ->map(fn ($v) => (string) data_get(json_decode((string) $v, true), 'as', ''))
            ->filter()->unique()->values()->all();
    }

    /**
     * `$since` 이후 결정된(승인·거절) 건 — 결정 시각 최신순.
     *
     * @return list<array{id: int, approved: bool, buyer: string, requested: ?int, count: ?int, note: string, by: string, at: Carbon}>
     */
    public static function decided(User $u, Carbon $since): array
    {
        $out = [];
        foreach (self::emailsFor($u) as $email) {
            $r = app(CarErpReadService::class)->purchaseLockDecided($email, $since->toIso8601String());
            if (! ($r['ok'] ?? false)) {
                continue;
            }
            foreach ((array) data_get($r['data'], 'data', []) as $row) {
                $status = (string) data_get($row, 'status');
                $at = data_get($row, 'decided_at');
                // exhausted·expired 도 한 번은 승인됐던 건 — 결정 알림으로는 승인.
                if (! $at || ! in_array($status, ['approved', 'rejected', 'exhausted', 'expired'], true)) {
                    continue;
                }
                $at = Carbon::parse($at);
                if ($at->lt($since)) {
                    continue;
                }
                $out[(int) data_get($row, 'id')] = [
                    'id' => (int) data_get($row, 'id'),
                    'approved' => $status !== 'rejected',
                    'buyer' => (string) (data_get($row, 'buyer_name') ?: '—'),
                    'requested' => is_numeric(data_get($row, 'requested_count')) ? (int) data_get($row, 'requested_count') : null,
                    'count' => is_numeric(data_get($row, 'approved_count')) ? (int) data_get($row, 'approved_count') : null,
                    'note' => trim((string) data_get($row, 'decision_note')),
                    'by' => (string) (data_get($row, 'decided_by_name') ?: ''),
                    'at' => $at,
                ];
            }
        }
        usort($out, fn ($a, $b) => $b['at'] <=> $a['at']);

        return array_values($out);
    }

    /** 종·푸시 본문 한 줄 — 「승인 3대(요청 5대) · 홍부장」 / 「거절 · 사유」. */
    public static function line(array $r): string
    {
        if ($r['approved']) {
            $s = __('auction.lock_bell_approved', ['count' => $r['count'] ?? '—']);
            if ($r['requested'] !== null && $r['count'] !== null && $r['requested'] !== $r['count']) {
                $s .= ' '.__('auction.lock_bell_requested', ['count' => $r['requested']]);
            }
            $s .= $r['note'] !== '' ? ' — '.$r['note'] : '';   // 승인 메모도(2026-11-24 Jin)
        } else {
            $s = __('auction.lock_bell_rejected').($r['note'] !== '' ? ' — '.$r['note'] : '');
        }

        return $s.($r['by'] !== '' ? ' · '.$r['by'] : '');
    }
}

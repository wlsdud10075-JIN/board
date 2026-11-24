<?php

namespace App\Support;

use App\Models\BoardAuditLog;
use App\Models\Claim;
use App\Models\IntegrationEvent;
use App\Models\PurchaseListing;
use App\Models\TransportRequest;
use App\Models\User;
use App\Services\WorkJournalService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 「오늘 할 일」(2026-11-24 Jin — (나)안: 화면은 그대로, 줄 버튼이 지금 쓰는 화면을 그 차 상세 창이 열린 채로 띄운다).
 *
 * 줄은 **board DB 가 이미 아는 사실로만** 만든다 — ERP·ssancar 를 부르지 않는다(분당 120 공유 한도·매핑 없는 계정 403).
 * 락 승인·포털 요청 결과처럼 ERP 에서 오는 것은 상단 종이 맡는다.
 * 판정은 기존 화면이 쓰는 것을 그대로 부른다 — 화면마다 다르게 말하면 안 된다:
 *   첨부 없음 = `/auction` 구매확정이 막는 조건(salesAttachments 0) · 검차 막힘 = `ssancarHoldHint()` 의 alert ·
 *   ERP 재고 상한 = `erpStockCapRejection()` · 배차 = `latestTransport` + `balance_requested_at`.
 * 격리 = 기본 스코프(SalesmanScope) 그대로 — 영업은 본인, 관리 role 은 본인 + 공유 영업, 전체 접근 등급은 전체.
 * 🚫 `withoutGlobalScopes()` 금지(소프트 삭제 행까지 섞이고 격리가 뚫린다).
 * 「N일째」는 상태 전이 감사 행 시각으로 잰다(updated_at 은 아무 저장에나 바뀐다).
 */
class TodayList
{
    /** 줄 종류 — 표시 순서 = 급한 순서. 팀 표 칸도 이 순서(transport_* 는 한 칸). */
    public const KINDS = ['erp_fail', 'no_attach', 'insp_alert', 'transport_rejected', 'balance', 'verdict', 'forward', 'confirm'];

    public const BLOCKED = ['erp_fail', 'no_attach', 'insp_alert', 'transport_rejected'];

    /** 팀 표 칸 — 배차 거절·잔금 대기는 「배차」 한 칸. */
    public const TEAM_COLUMNS = ['erp_fail', 'no_attach', 'insp_alert', 'transport', 'verdict', 'forward', 'confirm'];

    /** 구매확정 뒤 ERP 응답이 없을 때 「안 넘어갔다」로 볼 때까지 기다리는 시간(분) — Job 은 보통 수 초. */
    public const ERP_WAIT_MINUTES = 30;

    /** 배차 결과 줄을 띄우는 기간(일) — 오래된 거절·잔금 미요청까지 끝없이 남지 않게. */
    public const TRANSPORT_DAYS = 14;

    private const TONE = [
        'erp_fail' => 'red', 'no_attach' => 'red', 'insp_alert' => 'red', 'transport_rejected' => 'red',
        'balance' => 'amber', 'verdict' => 'amber', 'forward' => 'blue', 'confirm' => 'blue',
    ];

    private const ICON = [
        'erp_fail' => '⛔', 'no_attach' => '📎', 'insp_alert' => '⚠️', 'transport_rejected' => '🚚',
        'balance' => '🚚', 'verdict' => '💬', 'forward' => '🎬', 'confirm' => '🛒',
    ];

    /**
     * 지금 로그인한 사용자가 볼 수 있는 차의 줄 전부(스코프 적용).
     *
     * @return Collection<int, array{kind:string,tone:string,icon:string,listing_id:int,plate:string,owner_id:?int,owner_name:string,title:string,detail:string,since:?Carbon,href:string,btn:string,blocked:bool}>
     */
    public static function rows(): Collection
    {
        $listings = PurchaseListing::with(['creator:id,name', 'latestTransport'])
            ->withCount('salesAttachments')
            ->where(fn ($q) => $q
                ->whereIn('status', ['inspected', 'awaiting_buyer', 'accepted'])
                ->orWhere(fn ($w) => $w->where('status', 'won')->whereNull('car_erp_vehicle_id'))
                ->orWhere(fn ($w) => $w->where('status', 'draft')->where(fn ($d) => $d->whereNotNull('inspection_skipped_at')->orWhereNull('region')->orWhere('region', '')))
                ->orWhereHas('latestTransport', fn ($t) => $t->where('decided_at', '>=', now()->subDays(self::TRANSPORT_DAYS))
                    ->where(fn ($s) => $s->where('status', TransportRequest::REJECTED)
                        ->orWhere(fn ($a) => $a->where('status', TransportRequest::ACCEPTED)->whereNull('balance_requested_at')))))
            ->get();

        $since = self::statusSince($listings->pluck('id')->all());
        $lastSync = self::lastSyncEvents($listings->where('status', 'won')->pluck('id')->all());

        $rows = collect();
        foreach ($listings as $l) {
            $at = $since[$l->id][$l->status] ?? $l->created_at;
            foreach (self::kindsFor($l, $lastSync[$l->id] ?? null, $at) as [$kind, $detail, $when]) {
                $rows->push(self::row($l, $kind, $detail, $when));
            }
        }

        return $rows->sortBy(fn ($r) => [array_search($r['kind'], self::KINDS, true), $r['since']?->getTimestamp() ?? PHP_INT_MAX])->values();
    }

    /**
     * 차 1대에서 나오는 줄 — [kind, 설명, 기준 시각].
     *
     * @return list<array{0:string,1:string,2:?Carbon}>
     */
    private static function kindsFor(PurchaseListing $l, ?IntegrationEvent $ev, ?Carbon $at): array
    {
        $out = [];
        switch ($l->status) {
            case 'draft':
                $hint = $l->ssancarHoldHint();
                if ($hint && ($hint['alert'] ?? false)) {
                    $out[] = ['insp_alert', __($hint['key'], $hint['params']), $l->inspection_skipped_at ?? $at];
                }
                break;
            case 'inspected':
                $out[] = ['forward', __('today.d.forward'), $at];
                break;
            case 'awaiting_buyer':
                $out[] = ['verdict', __('today.d.verdict', ['buyer' => $l->buyer_name ?: '—']), $at];
                break;
            case 'accepted':
                $out[] = $l->sales_attachments_count === 0
                    ? ['no_attach', __('today.d.no_attach'), $at]
                    : ['confirm', __('today.d.confirm', ['n' => $l->sales_attachments_count]), $at];
                break;
            case 'won':
                if ($cap = $l->erpStockCapRejection()) {
                    $out[] = ['erp_fail', __('today.d.erp_cap', ['used' => number_format((int) ($cap['used'] ?? 0)), 'cap' => number_format((int) ($cap['cap'] ?? 0))]), $at];
                } elseif ($ev && ($ev->error !== null || $ev->response_status === null || $ev->response_status < 200 || $ev->response_status >= 300)) {
                    $out[] = ['erp_fail', __('today.d.erp_error', ['status' => $ev->response_status ?: '—']), $at];
                } elseif (! $ev && $at && $at->lte(now()->subMinutes(self::ERP_WAIT_MINUTES))) {
                    $out[] = ['erp_fail', __('today.d.erp_none'), $at];
                }
                break;
        }

        $t = $l->latestTransport;
        if ($t && $t->decided_at && $t->decided_at->gte(now()->subDays(self::TRANSPORT_DAYS))) {
            if ($t->status === TransportRequest::REJECTED) {
                $out[] = ['transport_rejected', __('today.d.transport_rejected', ['company' => $t->company_name ?: '—', 'reason' => $t->reject_reason ?: '—']), $t->decided_at];
            } elseif ($t->status === TransportRequest::ACCEPTED && $t->balance_requested_at === null) {
                $out[] = ['balance', __('today.d.balance', ['line' => $t->dispatchLine()]), $t->decided_at];
            }
        }

        return $out;
    }

    private static function row(PurchaseListing $l, string $kind, string $detail, ?Carbon $since): array
    {
        [$route, $params, $btn] = match ($kind) {
            'forward' => ['forwarding', ['open' => $l->id], 'today.btn.forward'],
            'verdict' => ['verdicts', ['open' => $l->id], 'today.btn.verdict'],
            'erp_fail', 'no_attach', 'confirm' => ['auction', ['open' => $l->id], 'today.btn.auction'],
            'transport_rejected', 'balance' => ['listings', ['open' => $l->id, 'dtab' => 'transport'], 'today.btn.transport'],
            default => ['listings', ['open' => $l->id], 'today.btn.listings'],
        };

        return [
            'kind' => $kind,
            'tone' => self::TONE[$kind],
            'icon' => self::ICON[$kind],
            'listing_id' => $l->id,
            'plate' => (string) Plate::show($l->vehicle_number),
            'owner_id' => $l->created_by_user_id,
            'owner_name' => $l->creator?->name ?? '—',
            'title' => __('today.t.'.$kind),
            'detail' => $detail,
            'since' => $since,
            'href' => route($route, $params + ['from' => 'today']),
            'btn' => __($btn),
            'blocked' => in_array($kind, self::BLOCKED, true),
        ];
    }

    /**
     * 차 하나 없는 내 일 — 클레임 담당(처리할 클레임)·업무일지 결재 차례. 사이드바 배지와 같은 판정.
     *
     * @return list<array{kind:string,count:int,href:string}>
     */
    public static function personal(User $u): array
    {
        $out = [];
        if ($u->isClaimHandler() && ($n = Claim::needsHandler()->count()) > 0) {
            $out[] = ['kind' => 'claims', 'count' => $n, 'href' => route('claims')];
        }
        if ($u->isJournalApprover() && ($n = app(WorkJournalService::class)->myTurnCount($u)) > 0) {
            $out[] = ['kind' => 'journal', 'count' => $n, 'href' => route('work-journal.approvals')];
        }

        return $out;
    }

    /** 사이드바 배지 — 내가 등록한 차의 줄 + 차 없는 내 일. */
    public static function badgeCount(User $u): int
    {
        return self::rows()->where('owner_id', $u->id)->count() + array_sum(array_column(self::personal($u), 'count'));
    }

    /** 팀 표 칸 이름 — 배차 두 종류는 한 칸. */
    public static function teamColumn(string $kind): string
    {
        return in_array($kind, ['transport_rejected', 'balance'], true) ? 'transport' : $kind;
    }

    /**
     * 차마다 상태별 마지막 진입 시각(감사 행 status_change).
     *
     * @param  list<int>  $ids
     * @return array<int, array<string, Carbon>>
     */
    private static function statusSince(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        BoardAuditLog::whereIn('purchase_listing_id', $ids)->where('field', 'status')
            ->selectRaw('purchase_listing_id, new_value, MAX(created_at) as at')
            ->groupBy('purchase_listing_id', 'new_value')
            ->get()
            ->each(function ($r) use (&$out) {
                $out[(int) $r->purchase_listing_id][(string) $r->new_value] = Carbon::parse($r->at);
            });

        return $out;
    }

    /**
     * won 차마다 마지막 purchase_sync 전송 행.
     *
     * @param  list<int>  $ids
     * @return array<int, IntegrationEvent>
     */
    private static function lastSyncEvents(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $last = IntegrationEvent::whereIn('purchase_listing_id', $ids)->where('event_type', 'purchase_sync')
            ->selectRaw('MAX(id) as id')->groupBy('purchase_listing_id')->pluck('id');

        return IntegrationEvent::whereIn('id', $last)->get()->keyBy('purchase_listing_id')->all();
    }
}

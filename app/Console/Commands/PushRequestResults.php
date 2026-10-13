<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\CarErpReadService;
use App\Services\WebPushService;
use App\Support\BoardRequestLines;
use App\Support\Instance;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * §11 요청 결과(ERP 확인·거절) → 웹푸시 (jin 2026-10-12 「알림톡 말고 웹푸시로 진동이든 소리든」).
 *
 * ERP → board 역방향 호출 채널이 없으므로 board 가 **매분** 사용자별로 `GET /requests?status=all&since=` 를 읽는다.
 * 대상 = 푸시 구독이 있는 활성 사용자(포털 역할). 사용자당 1호출/분 — ERP throttle(분당 120, salesman_email 별)에 여유.
 *
 * 커서 = `users.push_notified_at`(**보낸 기준 시각**, 상단 종의 열람 시각과 별개 축).
 *   - null = 첫 실행 → now 로 찍고 **과거 이력은 보내지 않는다**(켜는 순간 수십 건이 울리면 바로 끈다).
 *   - 조회 실패 시 커서를 **안 옮긴다** — 다음 분에 같은 구간을 다시 본다(ERP 잠깐 죽었다고 결과를 잃지 않는다).
 *   - since 는 커서보다 2분 앞에서 끊고, 실제 판정은 `resolved_at > 커서` 로 한다(ERP 박스와 시계가 다를 수 있다).
 */
class PushRequestResults extends Command
{
    protected $signature = 'board:push-request-results';

    protected $description = '내 §11 요청의 새 결과(ERP 확인·거절)를 웹푸시로 보낸다 (매분)';

    public function handle(CarErpReadService $erp, WebPushService $push): int
    {
        if (! WebPushService::configured() || ! $erp->configured()) {
            $this->info('웹푸시 또는 car-erp 미설정 — skip');

            return self::SUCCESS;
        }

        $users = User::query()->where('is_active', true)->whereHas('pushSubscriptions')->get()
            ->filter(fn (User $u) => $u->isSuper() || $u->isSales() || $u->isManager());

        $sentTotal = 0;
        foreach ($users as $u) {
            $started = now();
            if ($u->push_notified_at === null) {
                $u->forceFill(['push_notified_at' => $started])->save();

                continue;
            }

            $cursor = $u->push_notified_at;
            $env = $erp->boardRequests($u->car_erp_salesman_email ?: $u->email, 'all', $cursor->copy()->subMinutes(2)->toIso8601String());
            if (! ($env['ok'] ?? false)) {
                Log::warning('push-request-results: ERP 조회 실패 — 커서 유지', ['user' => $u->id, 'status' => $env['status'] ?? 0]);

                continue;
            }

            $fresh = array_filter(
                BoardRequestLines::flatten($env['data']),
                fn ($l) => BoardRequestLines::isResult($l) && BoardRequestLines::resolvedAfter($l, $cursor->toIso8601String())
            );
            // 푸시 문구는 **받는 사람의 언어**(users.locale) — 스케줄러는 요청 로캘이 없어 기본값(ko)으로 돈다.
            //   거절 사유 원문은 ERP 가 준 한글 그대로(전자서명 422 와 같은 구조).
            $prevLocale = app()->getLocale();
            app()->setLocale($u->locale ?: $prevLocale);
            try {
                foreach ($fresh as $line) {
                    $sentTotal += $push->sendToUser($u, $this->payload($line));
                }
            } finally {
                app()->setLocale($prevLocale);
            }

            $u->forceFill(['push_notified_at' => $started])->save();
        }

        $this->info("웹푸시 발송 {$sentTotal}건 (사용자 {$users->count()}명)");

        return self::SUCCESS;
    }

    /** 알림 한 장 — 제목에 결과·차량, 본문에 종류·금액·처리자·사유 원문. 클릭 = 포털. */
    private function payload(array $l): array
    {
        $rejected = $l['status'] === 'rejected';
        $type = match ($l['type']) {
            CarErpReadService::REQ_PURCHASE_DEPOSIT => __('portal.req_deposit_btn'),
            CarErpReadService::REQ_PURCHASE_BALANCE => __('portal.req_balance_btn'),
            CarErpReadService::REQ_PURCHASE_DEPOSIT_CEO => __('portal.req_deposit_ceo_btn'),
            CarErpReadService::REQ_SALE_CONFIRM => __('portal.req_sale_btn'),
            CarErpReadService::REQ_PURCHASE_LEGACY => __('portal.req_purchase_btn'),
            default => $l['type'],
        };

        $body = $type;
        if (is_numeric($l['amount'])) {
            $body .= ' '.number_format((float) $l['amount']).__('common.won_currency');
        }
        if ($l['buyer_name']) {
            $body .= ' · '.$l['buyer_name'];
        }
        if ($l['resolved_by']) {
            $body .= ' · '.__('nav.bell.resolved_by', ['name' => $l['resolved_by']]);
        }
        if ($rejected && trim((string) $l['reason']) !== '') {
            $body .= "\n".__('portal.req_reject_reason', ['reason' => trim((string) $l['reason'])]);
        }

        $assets = Instance::assets();

        return [
            'title' => ($rejected ? __('nav.bell.push_title_rejected') : __('nav.bell.push_title_done')).' — '.($l['vehicle_number'] ?: '—'),
            'body' => $body,
            'url' => route('portal'),
            'tag' => 'board-req-'.md5($l['key'].'|'.$l['status']),
            'sticky' => $rejected,
            'icon' => $assets ? asset($assets['apple_icon']) : null,
        ];
    }
}

<?php

namespace App\Console\Commands;

use App\Models\IntegrationEvent;
use App\Models\PromotionRequest;
use App\Services\RespondIoService;
use Illuminate\Console\Command;

/**
 * 연동 A — 승격 자동연결. respond.io `board_promote=Yes` 폴링 → "승격 대기" 캡처.
 *
 * 멱등(바이어당 1대기): 같은 contact 에 pending 이 이미 있으면 새로 안 만듦(리셋 PUT 실패에도 안전).
 * 7일(config) 방치 pending → expired. 캡처/이미존재는 필드 reset(다음 폴 재매칭 방지).
 * 미설정이면 no-op(안전밸브). 스케줄 = withoutOverlapping(verdict 폴러와 동일 패턴).
 */
class PollRespondPromotions extends Command
{
    protected $signature = 'board:poll-promotions';

    protected $description = '연동 A — respond.io 승격 플래그 폴링 → 승격 대기 캡처';

    public function handle(RespondIoService $respond): int
    {
        // 방치 대기건 만료 (respond.io 설정과 무관 — 항상 정리).
        $expired = PromotionRequest::where('status', PromotionRequest::PENDING)
            ->where('created_at', '<', now()->subDays((int) config('board.promotion_ttl_days')))
            ->update(['status' => PromotionRequest::EXPIRED]);
        if ($expired) {
            $this->info("만료 정리: {$expired}건");
        }

        if (! $respond->configured()) {
            $this->info('respond.io 미설정 — 스킵(no-op)');

            return self::SUCCESS;
        }

        $entries = $respond->pendingPromotions();
        $captured = 0;

        foreach ($entries as $e) {
            $contactId = $e['contact_id'];
            if ($contactId === '') {
                continue;
            }

            // 멱등: 미소비 대기가 이미 있으면 새로 안 만듦(바이어당 1).
            $exists = PromotionRequest::where('respond_contact_id', $contactId)
                ->where('status', PromotionRequest::PENDING)
                ->exists();

            if (! $exists) {
                $req = PromotionRequest::create([
                    'respond_contact_id' => $contactId,
                    'label' => $e['label'],
                    'assigned_email' => $e['assigned_email'],
                    'status' => PromotionRequest::PENDING,
                ]);
                $captured++;

                IntegrationEvent::create([
                    'direction' => 'inbound',
                    'target' => 'respond_io',
                    'event_type' => 'promote_poll',
                    'request_payload' => ['contact_id' => $contactId, 'label' => $e['label'], 'assigned_email' => $e['assigned_email'], 'promotion_request_id' => $req->id],
                    'response_status' => 200,
                    'response_body' => 'captured',
                ]);
            }

            // 캡처했든 이미 있든 필드는 reset → 다음 폴에서 같은 신호 재매칭 방지.
            $respond->resetPromote($contactId);
        }

        $this->info("승격 폴링 완료: {$captured}건 신규 캡처 / 수신 ".count($entries).'건');

        return self::SUCCESS;
    }
}

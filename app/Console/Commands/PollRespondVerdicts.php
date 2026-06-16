<?php

namespace App\Console\Commands;

use App\Models\IntegrationEvent;
use App\Services\RespondIoService;
use App\Services\VerdictService;
use Illuminate\Console\Command;

/**
 * 연동 A (C) — respond.io 회신 필드 폴링 → 자동 verdict 적용(직렬화).
 *
 * 자동 채널(verdict_channel=auto) + 회신대기 1대일 때만 적용(applyByConversation).
 * 다중(ambiguous)은 사람이 /verdicts(A)로 → 필드 리셋 안 함. 적용/무매칭은 '대기'로 리셋.
 * 미설정이면 no-op(안전밸브). 스케줄 = withoutOverlapping(락②).
 */
class PollRespondVerdicts extends Command
{
    protected $signature = 'board:poll-verdicts';

    protected $description = '연동 A(C) — respond.io 회신 폴링 → 자동 verdict 적용';

    public function handle(RespondIoService $respond, VerdictService $verdicts): int
    {
        if (! $respond->configured()) {
            $this->info('respond.io 미설정 — 스킵(no-op)');

            return self::SUCCESS;
        }

        $entries = $respond->pendingVerdicts();
        $applied = 0;

        foreach ($entries as $e) {
            $convId = $e['conversation_id'];
            if (empty($convId)) {
                continue;
            }

            // 자동 채널 + 회신대기 1대만 적용(다중=ambiguous=사람이 A로)
            $res = $verdicts->applyByConversation($convId, $e['verdict'], null, 'auto');

            IntegrationEvent::create([
                'direction' => 'inbound',
                'target' => 'respond_io',
                'event_type' => 'verdict_poll',
                'purchase_listing_id' => $res['listing_id'],
                'request_payload' => ['conversation_id' => $convId, 'verdict' => $e['verdict'], 'result' => $res['status']],
                'response_status' => 200,
                'response_body' => $res['status'],
                'error' => str_starts_with($res['status'], 'applied') ? null : $res['status'],
            ]);

            // 적용/무매칭 → 필드 리셋. ambiguous(다중) → 사람이 처리하도록 남겨둠.
            if ($res['status'] !== 'ambiguous') {
                $respond->resetVerdict($e['contact_id']);
            }
            if (str_starts_with($res['status'], 'applied')) {
                $applied++;
            }
        }

        $this->info("폴링 완료: {$applied}건 적용 / 수신 ".count($entries).'건');

        return self::SUCCESS;
    }
}

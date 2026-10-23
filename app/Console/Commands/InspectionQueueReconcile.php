<?php

namespace App\Console\Commands;

use App\Jobs\SendInspectionQueueEvent;
use App\Models\IntegrationEvent;
use App\Models\PurchaseListing;
use App\Support\SsancarInspection;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * 검차 큐 일일 대사(ssancar.com, 2026-10-23, 스펙 §8-6) — board 의 「판정식 true」 listing_id 전부를 보내
 * ssancar 열린 행과 맞춘다. 아웃박스·재시도가 실패해도(또는 `saveQuietly()` 로 판정식이 바뀌어도) 다음 날 바로잡힌다.
 *
 * - 목록에 없는 열린 행 = ssancar 가 withdrawn(reason=reconcile) · skipped 행은 「있음」으로 센다.
 * - 응답 `missing_listing_ids[]` = ssancar 에 없는 차 → 그 차만 upsert 재발송.
 * - 🚫 **빈 배열은 보내지 않는다** — 「못 봤다 ≠ 없다」. ssancar 도 빈 배열·30% 이상 철회는 보류(held)한다.
 */
class InspectionQueueReconcile extends Command
{
    protected $signature = 'board:inspection-reconcile';

    protected $description = '검차 큐(ssancar.com) 일일 대사 — 판정식 true 목록을 보내고 누락분 재발송';

    public function handle(): int
    {
        if (! SsancarInspection::configured()) {
            $this->info('미설정 — 건너뜀');

            return self::SUCCESS;
        }

        // 모델 판정식(`PurchaseListing::isInspectionTarget`)과 같은 식. origin 은 nullable 이라 NULL 도 대상이다.
        $ids = PurchaseListing::withoutGlobalScopes()->whereNull('deleted_at')->where('status', 'draft')
            ->where(fn ($q) => $q->whereNull('origin')->orWhere('origin', '!=', 'self_inspection'))
            ->orderBy('id')->pluck('id')->all();
        if ($ids === []) {
            $this->info('대상 0대 — 빈 목록은 보내지 않음');

            return self::SUCCESS;
        }

        $payload = [
            'event' => 'queue.reconcile',
            'event_id' => (string) Str::uuid(),
            'listing_ids' => $ids,
            'count' => count($ids),
            'generated_at' => now()->timezone('Asia/Seoul')->toIso8601String(),
        ];
        try {
            $response = SsancarInspection::post($payload);
        } catch (\Throwable $e) {
            $this->error('호출 실패: '.class_basename($e));

            return self::SUCCESS;   // 다음 날 다시 — 스케줄러를 실패로 물들이지 않는다
        }

        IntegrationEvent::create([
            'direction' => 'outbound',
            'target' => 'ssancar_inspection',
            'event_type' => 'queue.reconcile',
            'external_event_id' => $payload['event_id'],
            'request_payload' => ['count' => $payload['count'], 'generated_at' => $payload['generated_at']],   // id 목록은 길어 안 남긴다
            'response_status' => $response->status(),
            'response_body' => mb_substr((string) $response->body(), 0, 2000),
            'error' => $response->failed() ? 'HTTP '.$response->status() : null,
        ]);
        if ($response->failed()) {
            $this->error('HTTP '.$response->status());

            return self::SUCCESS;
        }

        // ssancar 가 「없다」고 한 것 중 **지금도 대상인 차만** 재발송(응답이 이상해도 엉뚱한 차를 보내지 않게).
        $missing = array_values(array_intersect(array_map('intval', (array) $response->json('missing_listing_ids', [])), $ids));
        foreach ($missing as $id) {
            SendInspectionQueueEvent::dispatch($id, 'upsert');
        }
        $this->info(sprintf('대상 %d대 · ssancar 철회 %s · 누락 재발송 %d대%s', count($ids),
            (string) ($response->json('withdrawn') ?? '?'), count($missing), $response->json('held') ? ' · ⚠️ ssancar 보류(held)' : ''));

        return self::SUCCESS;
    }
}

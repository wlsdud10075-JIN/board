<?php

namespace App\Console\Commands;

use App\Models\IntegrationEvent;
use App\Models\PurchaseListing;
use App\Services\SsancarMediaService;
use Illuminate\Console\Command;

/**
 * ssancar 검차영상 자동감지 — draft 매물의 vin·번호판으로 api_car_media.php 조회,
 * 검차영상(videos[])이 뜨면 draft → inspected(전달대기) 자동 전이.
 *
 * 검차팀이 ssancar 에 영상 올리면 board 가 감지해 전달대기로 넘김(현지확인 수동입력 대체).
 * 영상 없는 차(엔카 유입 등)는 draft 유지 → board 현지확인 화면(수동 폴백).
 * 미설정이면 no-op(안전밸브). 스케줄 = withoutOverlapping. 인계 = meetings/handoff-ssancar-media-poll.md.
 */
class PollSsancarMedia extends Command
{
    protected $signature = 'board:poll-ssancar-media';

    protected $description = 'ssancar 검차영상 감지 → draft 매물 자동 전달대기(inspected) 전이';

    public function handle(SsancarMediaService $ssancar): int
    {
        // 미디어 설정 + 자동전이 플래그 둘 다여야 동작. 플래그(기본 off)는 ssancar 폴링 계약 확인 후 opt-in.
        if (! $ssancar->configured() || ! config('board.ssancar_auto_forward')) {
            $this->info('ssancar 자동전이 미활성(미설정/플래그 off) — 스킵(no-op)');

            return self::SUCCESS;
        }

        // draft + (vin 또는 번호판) 보유 매물만. 비인증 콘솔이라 SalesmanScope 전체 노출.
        $listings = PurchaseListing::where('status', 'draft')
            ->where(function ($q) {
                $q->whereNotNull('vin')->orWhereNotNull('vehicle_number');
            })
            ->get();

        $advanced = 0;

        foreach ($listings as $listing) {
            $media = $ssancar->mediaFor($listing);
            if (empty($media['videos'])) {
                continue;   // 영상 아직 없음 → draft 유지
            }

            // draft → inspected(전달대기). 허용 전이 + 감사로그는 모델 옵저버가 자동(user_id=null 시스템).
            $listing->status = 'inspected';
            $listing->save();

            IntegrationEvent::create([
                'direction' => 'inbound',
                'target' => 'ssancar_media',
                'event_type' => 'auto_forward_ready',
                'purchase_listing_id' => $listing->id,
                'request_payload' => [
                    'vin' => $listing->vin,
                    'car_no' => $listing->vehicle_number,
                    'videos' => count($media['videos']),
                ],
                'response_status' => 200,
                'response_body' => 'advanced_to_inspected',
            ]);

            $advanced++;
        }

        $this->info("ssancar 미디어 폴링: {$advanced}건 전달대기 전환 / draft 후보 ".count($listings).'건');

        return self::SUCCESS;
    }
}

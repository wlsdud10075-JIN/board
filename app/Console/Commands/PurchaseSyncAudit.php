<?php

namespace App\Console\Commands;

use App\Models\IntegrationEvent;
use App\Models\PurchaseListing;
use App\Models\Scopes\SalesmanScope;
use App\Services\CarErpReadService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * 연동 B 감사 — 「낙찰됐는데 ERP 로 안 넘어간 차」와 정합성 오류를 매일 아침 세서 JSON 으로 남긴다.
 * (야간 배치 실행기 1단계, car-erp `docs/design/agent-virtual-office-analysis.md` §11·§12, Jin 승인 2026-09-28)
 *
 * 판정은 SQL, **수정 0·재전송 0**. 알림은 ERP 08:00 아침 보고가 이 JSON 을 읽어서 한다 → 07:40 에 돈다.
 *
 *   stalled        = won 인데 car_erp_vehicle_id 가 없고 60분 넘게 그대로.
 *                    Job 재시도 5회·백오프 1·5·15·30분(SyncWonListingToCarErp) 창을 다 지난 것만 센다.
 *   integrity      = synced 인데 erp id 없음 / erp id 있는데 synced 아님 (둘 다 0 이어야 정상).
 *   missing_in_erp = synced 매물의 erp id 를 ERP `GET /vehicles/exists` 로 대조해 ERP 쪽에 없는 것.
 *                    ERP 호출이 실패하면 이 항목만 null + errors[] — **명령 전체를 실패시키지 않는다**
 *                    (보고 도구가 죽으면 침묵이 되고, 침묵은 "이상 없음" 으로 읽힌다).
 *
 * exit code 는 항상 0(보고 전용, cron 이 계속 돌아야 한다).
 * ⚠️ stalled 의 시계는 `updated_at` 이다 — won 행을 다른 이유로 저장하면(드로어 편집 등) 60분이 다시 시작된다.
 */
class PurchaseSyncAudit extends Command
{
    protected $signature = 'board:purchase-sync-audit {--json= : 출력 파일 경로 (기본 storage/app/integration/purchase-sync-audit.json)}';

    protected $description = '연동 B 감사 — won 정체·정합성·ERP 부재 차량을 JSON 으로 기록(읽기 전용)';

    private const STALL_MINUTES = 60;

    private const EXISTS_CHUNK = 100;

    public function handle(CarErpReadService $erp): int
    {
        $errors = [];

        $stalled = $this->listings()
            ->where('status', 'won')
            ->whereNull('car_erp_vehicle_id')
            ->where('updated_at', '<', now()->subMinutes(self::STALL_MINUTES))
            ->orderBy('updated_at')
            ->get(['id', 'vehicle_number', 'updated_at'])
            ->map(function (PurchaseListing $l) {
                $ev = IntegrationEvent::where('direction', 'outbound')
                    ->where('target', 'car_erp')
                    ->where('event_type', 'purchase_sync')
                    ->where('purchase_listing_id', $l->id)
                    ->orderByDesc('id')
                    ->first(['response_status', 'error', 'created_at']);

                return [
                    'listing_id' => $l->id,
                    'vehicle_number' => $l->vehicle_number,
                    'updated_at' => $l->updated_at?->toIso8601String(),
                    'last_event' => $ev ? [
                        'response_status' => $ev->response_status,
                        'error' => $ev->error,
                        'created_at' => $ev->created_at?->toIso8601String(),
                    ] : null,
                ];
            })->values()->all();

        $syncedWithoutErpId = $this->listings()->where('status', 'synced')->whereNull('car_erp_vehicle_id')->count();
        $erpIdWithoutSynced = $this->listings()->where('status', '<>', 'synced')->whereNotNull('car_erp_vehicle_id')->count();

        $missing = $this->missingInErp($erp, $errors);

        $report = [
            'generated_at' => now()->toIso8601String(),
            'counts' => [
                'stalled' => count($stalled),
                'synced_without_erp_id' => $syncedWithoutErpId,
                'erp_id_without_synced' => $erpIdWithoutSynced,
                'missing_in_erp' => $missing === null ? null : count($missing),
            ],
            'stalled' => $stalled,
            'missing_in_erp' => $missing,
            'errors' => $errors,
        ];

        $json = json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $path = (string) ($this->option('json') ?: storage_path('app/integration/purchase-sync-audit.json'));
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $json);

        $this->line($json);

        return self::SUCCESS;
    }

    /** 콘솔엔 Auth 가 없어 전체가 보이지만, Job 들과 같이 SalesmanScope 를 명시 제거(방어). */
    private function listings()
    {
        return PurchaseListing::withoutGlobalScope(SalesmanScope::class);
    }

    /**
     * @param  list<string>  $errors
     * @return list<array{listing_id:int,vehicle_number:?string,car_erp_vehicle_id:int}>|null
     */
    private function missingInErp(CarErpReadService $erp, array &$errors): ?array
    {
        $synced = $this->listings()
            ->where('status', 'synced')
            ->whereNotNull('car_erp_vehicle_id')
            ->get(['id', 'vehicle_number', 'car_erp_vehicle_id']);

        if ($synced->isEmpty()) {
            return [];
        }

        $missingIds = [];
        foreach ($synced->pluck('car_erp_vehicle_id')->unique()->chunk(self::EXISTS_CHUNK) as $chunk) {
            $env = $erp->vehiclesExist($chunk->values()->all());
            if (! ($env['ok'] ?? false)) {
                $errors[] = 'vehicles/exists: '.($env['reason'] ?? 'unknown')
                    .(isset($env['status']) && $env['status'] ? " HTTP {$env['status']}" : '')
                    .(isset($env['message']) ? " — {$env['message']}" : '');

                return null;   // 부분 결과를 "없음" 으로 보고하면 멀쩡한 차가 부재로 찍힌다.
            }
            $missingIds = array_merge($missingIds, array_map('intval', (array) data_get($env['data'], 'missing', [])));
        }

        return $synced
            ->whereIn('car_erp_vehicle_id', $missingIds)
            ->map(fn (PurchaseListing $l) => [
                'listing_id' => $l->id,
                'vehicle_number' => $l->vehicle_number,
                'car_erp_vehicle_id' => (int) $l->car_erp_vehicle_id,
            ])->values()->all();
    }
}

<?php

namespace App\Services\Ops;

use App\Models\AlimtalkLog;
use App\Models\Claim;
use App\Models\ExchangeRate;
use App\Models\IntegrationEvent;
use App\Models\PurchaseListing;
use App\Models\Scopes\SalesmanScope;
use App\Models\Setting;
use App\Support\Instance;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * 관제센터 요약(2026-11-12) — ssancarerp /admin/control 허브가 5분마다 읽는다.
 * 계약 권위 = car-erp `docs/design/ops-control-center.md` §4. 서명 검증 = `VerifyOpsHmac`.
 *
 * 🚫 키 원문·개인정보·회사 돈 숫자를 싣지 않는다(keys 는 있음/없음만). 차량번호 예시는 5개까지(ERP 이상 감지와 같은 수준).
 * ⏱️ 허브 timeout 4초 — 요청 안에서 외부(GPU PC·ERP)를 부르지 않는다. GPU 응답 여부는 스케줄러가 남긴 캐시만 읽는다.
 * 각 감지는 따로 try — 하나가 터져도 요약 전체가 500 이 되지 않게(터진 것은 level 1 「점검 오류」로 보인다).
 */
class OpsSummary
{
    public const HEARTBEAT_KEY = 'ops:scheduler_heartbeat';

    public const GPU_PING_KEY = 'ops:gpu_ping';

    /** 스케줄러 멈춤(분) — 매분 하트비트라 5분 비면 cron 이 안 도는 것. */
    private const HEARTBEAT_STALE_MIN = 5;

    /** 큐 워커 멈춤(분) — 가장 오래 기다린 Job. */
    private const QUEUE_STALE_MIN = 10;

    /** DB 백업(시간) — 03:00 일일 백업 + 여유. */
    private const BACKUP_STALE_HOURS = 26;

    /** 구매확정 정체(분) — `board:purchase-sync-audit` 와 같은 기준. */
    private const STALL_MINUTES = 60;

    /** 환율 갱신 멈춤(시간) — 매시 갱신. */
    private const RATES_STALE_HOURS = 3;

    /** GPU PC 응답 없음(분) — 5분 핑이 연달아 실패하는 수준. */
    private const GPU_STALE_MIN = 15;

    /** @return array<string, mixed> */
    public static function collect(): array
    {
        return [
            'generated_at' => now()->toIso8601String(),
            'company' => Instance::assets()['tenant'] ?? (string) config('app.name'),
            'server' => self::server(),
            'routines' => [],
            'health' => self::health(),
            'links' => self::links(),
            'anomalies' => self::anomalies(),
        ];
    }

    // ─────────── 스케줄러가 부르는 것 ───────────

    /** 매분 — 스케줄러가 살아 있다는 표시. */
    public static function beat(): void
    {
        Cache::forever(self::HEARTBEAT_KEY, now()->timestamp);
    }

    /** 5분마다 — GPU PC(Ollama, OCR·챗봇 공용 호스트) 응답 여부. 요약 요청 안에서는 부르지 않는다. */
    public static function pingGpu(): void
    {
        if (! self::gpuInUse()) {
            return;
        }
        try {
            $ok = Http::timeout(5)->connectTimeout(5)
                ->get(rtrim((string) config('assistant.ollama'), '/').'/api/tags')->successful();
        } catch (\Throwable) {
            $ok = false;
        }
        $prev = Cache::get(self::GPU_PING_KEY, []);
        Cache::forever(self::GPU_PING_KEY, [
            'ok' => $ok,
            'at' => now()->timestamp,
            'last_ok_at' => $ok ? now()->timestamp : ($prev['last_ok_at'] ?? null),
        ]);
    }

    private static function gpuInUse(): bool
    {
        return (bool) config('board.payee_extract.enabled') || (bool) config('assistant.enabled');
    }

    // ─────────── server ───────────

    /** @return array<string, mixed> */
    private static function server(): array
    {
        return [
            'version' => self::gitSha(),
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
            'env' => (string) config('app.env'),
            'pending_migrations' => self::safe(fn () => self::pendingMigrations()),
            'disk_used_pct' => self::safe(function () {
                $total = @disk_total_space(base_path());
                $free = @disk_free_space(base_path());

                return ($total && $free !== false) ? round(100 * (1 - $free / $total), 1) : null;
            }),
            'db_mb' => self::safe(function () {
                if (DB::connection()->getDriverName() !== 'mysql') {
                    return null;
                }
                $bytes = DB::selectOne('SELECT SUM(data_length + index_length) AS b FROM information_schema.tables WHERE table_schema = DATABASE()')->b ?? null;

                return $bytes === null ? null : round($bytes / 1048576, 1);
            }),
            'errors_today' => null,
        ];
    }

    /** 박스는 `git reset --hard origin/master` 로 배포된다 — .git 을 파일로 읽는다(exec·safe.directory 회피). */
    private static function gitSha(): ?string
    {
        $head = @file_get_contents(base_path('.git/HEAD'));
        if ($head === false) {
            return null;
        }
        $head = trim($head);
        if (! str_starts_with($head, 'ref: ')) {
            return substr($head, 0, 7);
        }
        $ref = substr($head, 5);
        $sha = @file_get_contents(base_path('.git/'.$ref));
        if ($sha === false) {
            $packed = @file_get_contents(base_path('.git/packed-refs'));
            if ($packed !== false && preg_match('/^([0-9a-f]{40}) '.preg_quote($ref, '/').'$/m', $packed, $m)) {
                $sha = $m[1];
            }
        }

        return $sha ? substr(trim($sha), 0, 7) : null;
    }

    private static function pendingMigrations(): int
    {
        $files = collect(glob(database_path('migrations/*.php')) ?: [])->map(fn ($f) => basename($f, '.php'));

        return $files->diff(DB::table('migrations')->pluck('migration'))->count();
    }

    // ─────────── health · links ───────────

    /** @return list<array{key:string, ok:bool, detail:string}> */
    private static function health(): array
    {
        $out = [];
        $beat = Cache::get(self::HEARTBEAT_KEY);
        $out[] = ['key' => 'scheduler', 'ok' => $beat && now()->timestamp - $beat <= self::HEARTBEAT_STALE_MIN * 60,
            'detail' => $beat ? self::ago($beat) : '기록 없음'];
        if (self::gpuInUse()) {
            $p = Cache::get(self::GPU_PING_KEY);
            $out[] = ['key' => 'gpu', 'ok' => (bool) ($p['ok'] ?? false), 'detail' => $p ? self::ago($p['at']).' 확인' : '확인 기록 없음'];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private static function links(): array
    {
        return self::safe(function () {
            $today = AlimtalkLog::where('created_at', '>=', today());

            return [
                'alimtalk_sent_today' => (clone $today)->where('status', 'sent')->count(),
                'alimtalk_failed_today' => (clone $today)->where('status', 'failed')->count(),
                'claude_cost_today_krw' => null,
                'keys' => [
                    'car_erp' => (string) config('services.car_erp.hmac_secret') !== '',
                    'car_erp_read' => (string) config('services.car_erp.read_hmac_secret') !== '',
                    'ssancar_inspection' => (string) config('services.ssancar_inspection.hmac_secret') !== '',
                    'web_push' => (string) config('board.push.vapid_public') !== '',
                ],
            ];
        }) ?? [];
    }

    // ─────────── anomalies ───────────

    /** @return list<array{level:int, kind:string, count:int, text:string}> */
    public static function anomalies(): array
    {
        $checks = [
            'schedulerStopped', 'queueStalled', 'backupStale', 'erpSyncFailed', 'wonStalled',
            'failedJobs', 'gpuDown', 'inspectionQueueFailed', 'ratesStale', 'alimtalkFailed', 'claimErpFailed',
            'assistantIndexOld',
        ];
        $a = [];
        foreach ($checks as $c) {
            try {
                if ($row = self::$c()) {
                    $a[] = $row;
                }
            } catch (\Throwable $e) {
                $a[] = ['level' => 1, 'kind' => '점검 오류', 'count' => 1, 'text' => $c.' — '.class_basename($e)];
            }
        }
        usort($a, fn ($x, $y) => $y['level'] <=> $x['level']);

        return $a;
    }

    private static function schedulerStopped(): ?array
    {
        $beat = Cache::get(self::HEARTBEAT_KEY);
        if ($beat && now()->timestamp - $beat <= self::HEARTBEAT_STALE_MIN * 60) {
            return null;
        }

        return ['level' => 3, 'kind' => '스케줄러 멈춤', 'count' => 1,
            'text' => $beat ? '정기 작업이 '.self::ago($beat).' 마지막으로 돌았다 — cron(schedule:run) 확인'
                : '정기 작업이 돈 기록이 없다 — cron(schedule:run) 등록 확인'];
    }

    private static function queueStalled(): ?array
    {
        if (config('queue.default') !== 'database' || ! Schema::hasTable('jobs')) {
            return null;
        }
        $q = DB::table('jobs')->whereNull('reserved_at')
            ->where('available_at', '<', now()->subMinutes(self::QUEUE_STALE_MIN)->timestamp);
        $n = (clone $q)->count();
        if ($n === 0) {
            return null;
        }
        $oldest = (int) (clone $q)->min('available_at');

        return ['level' => 3, 'kind' => '큐 워커 멈춤', 'count' => $n,
            'text' => "처리 안 된 작업 {$n}건(가장 오래된 것 ".self::ago($oldest).') — 워커 상태·재시작(queue:restart) 확인'];
    }

    private static function backupStale(): ?array
    {
        $files = glob(storage_path('backups/db/*.sql*')) ?: [];
        $latest = $files ? max(array_map('filemtime', $files)) : null;
        if ($latest && time() - $latest <= self::BACKUP_STALE_HOURS * 3600) {
            return null;
        }

        return ['level' => 3, 'kind' => 'DB 백업 실패', 'count' => 1,
            'text' => $latest ? '마지막 백업이 '.self::ago($latest).' — 03:00 db:backup 확인(폴더 권한 등)'
                : '백업 파일이 없다 — db:backup 확인'];
    }

    /** 최근 24시간 매입 연동 — 매물별 **마지막 시도**가 실패인 것만(재시도로 성공했으면 세지 않는다). */
    private static function erpSyncFailed(): ?array
    {
        $latestIds = IntegrationEvent::where('direction', 'outbound')->where('target', 'car_erp')
            ->where('event_type', 'purchase_sync')->where('created_at', '>=', now()->subDay())
            ->whereNotNull('purchase_listing_id')
            ->groupBy('purchase_listing_id')->selectRaw('MAX(id) AS id')->pluck('id');
        $failed = IntegrationEvent::whereIn('id', $latestIds)
            ->where(fn ($w) => self::failedWhere($w))->pluck('purchase_listing_id');
        if ($failed->isEmpty()) {
            return null;
        }

        return ['level' => 3, 'kind' => 'ERP 매입 연동 실패', 'count' => $failed->count(),
            'text' => '최근 24시간 ERP 전송이 실패·거절로 끝난 차 — '.self::plates($failed)];
    }

    private static function wonStalled(): ?array
    {
        $ids = PurchaseListing::withoutGlobalScope(SalesmanScope::class)
            ->where('status', 'won')->whereNull('car_erp_vehicle_id')
            ->where('updated_at', '<', now()->subMinutes(self::STALL_MINUTES))->pluck('id');
        if ($ids->isEmpty()) {
            return null;
        }

        return ['level' => 3, 'kind' => '구매확정 정체', 'count' => $ids->count(),
            'text' => '구매확정 뒤 '.self::STALL_MINUTES.'분 넘게 ERP 로 안 넘어간 차 — '.self::plates($ids)];
    }

    private static function failedJobs(): ?array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return null;
        }
        $n = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();

        return $n ? ['level' => 2, 'kind' => '큐 작업 실패', 'count' => $n, 'text' => "최근 24시간 실패한 큐 작업 {$n}건(failed_jobs)"] : null;
    }

    private static function gpuDown(): ?array
    {
        if (! self::gpuInUse()) {
            return null;
        }
        $p = Cache::get(self::GPU_PING_KEY);
        if (! $p) {
            return null;   // 아직 한 번도 확인 안 함 — 스케줄러가 멈췄다면 그쪽이 따로 잡는다
        }
        $lastOk = $p['last_ok_at'] ?? null;
        if ($p['ok'] || ($lastOk && now()->timestamp - $lastOk <= self::GPU_STALE_MIN * 60)) {
            return null;
        }

        return ['level' => 2, 'kind' => 'OCR·챗봇 GPU 응답 없음', 'count' => 1,
            'text' => 'GPU PC(Ollama) 응답 없음 — 마지막 정상 '.($lastOk ? self::ago($lastOk) : '기록 없음').'. 계좌·여권 읽기와 챗봇이 멈춘다'];
    }

    private static function inspectionQueueFailed(): ?array
    {
        $n = IntegrationEvent::where('direction', 'outbound')->where('target', 'ssancar_inspection')
            ->where('created_at', '>=', now()->subDay())->where(fn ($w) => self::failedWhere($w))->count();

        return $n ? ['level' => 2, 'kind' => 'ssancar.com 검차 큐 전송 실패', 'count' => $n,
            'text' => "최근 24시간 검차 큐 등록·철회·대사 실패 {$n}건 — 검차원에게 차가 안 보일 수 있다"] : null;
    }

    private static function ratesStale(): ?array
    {
        $latest = ExchangeRate::whereNotNull('fetched_at')->max('fetched_at');
        $ts = $latest ? strtotime((string) $latest) : null;
        if ($ts && time() - $ts < self::RATES_STALE_HOURS * 3600) {
            return null;
        }

        return ['level' => 2, 'kind' => '환율 갱신 멈춤', 'count' => 1,
            'text' => $ts ? '환율이 '.self::ago($ts).' 마지막 갱신 — 원화 환산이 옛 환율로 계산된다'
                : '받아 둔 환율이 없다 — board:refresh-rates 확인'];
    }

    private static function alimtalkFailed(): ?array
    {
        $n = AlimtalkLog::where('status', 'failed')->whereNull('acknowledged_at')->count();

        return $n ? ['level' => 2, 'kind' => '알림톡 실패', 'count' => $n, 'text' => "확인 안 한 알림톡 실패 {$n}건(알림톡 발송 기록)"] : null;
    }

    private static function claimErpFailed(): ?array
    {
        $n = Claim::whereNotNull('erp_send_error')->count();

        return $n ? ['level' => 2, 'kind' => '클레임 ERP 전송 실패', 'count' => $n, 'text' => "[ERP 로 보내기]가 실패로 남은 클레임 {$n}건"] : null;
    }

    /** 기능설정 토글(운영 on/off)이 꺼져 있으면 안 낸다 — OCR 과 VRAM 을 같이 못 써 챗봇을 꺼둔 동안 색인 push 도 멈춘다(Jin 2026-11-12). */
    private static function assistantIndexOld(): ?array
    {
        if (! config('assistant.enabled') || ! Setting::get('assistant_enabled', false)) {
            return null;
        }
        $path = (string) config('assistant.index_path');
        $mtime = ($path !== '' && is_file($path)) ? filemtime($path) : null;
        $maxDays = (int) config('assistant.index_max_age_days', 3);
        if ($mtime && time() - $mtime < $maxDays * 86400) {
            return null;
        }

        return ['level' => 1, 'kind' => '챗봇 색인 오래됨', 'count' => 1,
            'text' => $mtime ? '챗봇 색인이 '.self::ago($mtime).' 그대로 — GPU PC 03:00 scp 배포 확인' : '챗봇 색인 파일이 없다'];
    }

    // ─────────── 도우미 ───────────

    /** `/audit` 전송 표의 「실패」와 같은 판정. */
    private static function failedWhere($w): void
    {
        $w->whereNull('response_status')->orWhere('response_status', '<', 200)->orWhere('response_status', '>', 299);
    }

    private static function plates($ids): string
    {
        $ids = collect($ids);
        $plates = PurchaseListing::withoutGlobalScope(SalesmanScope::class)->withTrashed()
            ->whereIn('id', $ids->take(5))->pluck('vehicle_number')->filter()->implode(', ');
        $more = $ids->count() - 5;

        return ($plates ?: '차량번호 없음').($more > 0 ? " 외 {$more}대" : '');
    }

    private static function ago(int $ts): string
    {
        $min = intdiv(max(0, now()->timestamp - $ts), 60);

        return match (true) {
            $min < 60 => "{$min}분 전",
            $min < 1440 => intdiv($min, 60).'시간 전',
            default => intdiv($min, 1440).'일 전',
        };
    }

    private static function safe(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (\Throwable) {
            return null;
        }
    }
}

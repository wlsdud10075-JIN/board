<?php

namespace App\Console\Commands;

use App\Support\Region;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 기존 지역명 일괄 정규화 — 예전에 저장된 축약형("안산")을 정식 라벨("경기 안산시")로 맞춘다.
 *
 * 왜: region 은 세 테이블에서 **문자열 그대로 조인**된다. 한쪽만 정리하면 오히려 더 어긋나므로
 * purchase_listings · users · inspection_assignments 를 한 번에 처리한다.
 *
 * 기본은 **dry-run**(아무것도 안 바꾸고 변경안만 출력). 실제 반영은 `--apply`.
 * ⚠️ 인스턴스마다 DB 가 다르다 — heymanboard·ssancarboard 박스에서 **각각** 실행할 것.
 *
 * 애매한 값(광주=광역시/경기, 고성=강원/경남)은 추측하지 않고 건너뛰며 목록으로 보고한다 → 사람이 판단.
 * 감사로그는 남기지 않는다(쿼리빌더 직접 update) — 표기 통일은 업무상 변경이 아니라 데이터 정리다.
 */
class RegionNormalize extends Command
{
    protected $signature = 'board:region-normalize {--apply : 실제로 반영(미지정=변경안만 출력)}';

    protected $description = '지역명을 정식 라벨로 일괄 정규화 (기본 dry-run)';

    /** region 컬럼을 가진 테이블 — 셋 다 같은 문자열로 조인되므로 함께 처리. */
    private const TABLES = ['purchase_listings', 'users', 'inspection_assignments'];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $changed = 0;
        $ambiguous = [];

        foreach (self::TABLES as $table) {
            $rows = DB::table($table)
                ->whereNotNull('region')->where('region', '!=', '')
                ->select('region', DB::raw('count(*) as cnt'))
                ->groupBy('region')->orderBy('region')->get();

            foreach ($rows as $row) {
                $from = (string) $row->region;
                $to = Region::canonical($from);

                if ($to === null || $to === $from) {
                    continue;   // 이미 정식형
                }
                if (Region::isAmbiguous($from)) {
                    $ambiguous[] = "{$table}: {$from} ({$row->cnt}건)";

                    continue;   // 추측 금지 — 사람이 정한다
                }

                $this->line(sprintf('  %-22s %s → %s (%d건)', $table, $from, $to, $row->cnt));
                $changed += $row->cnt;

                if ($apply) {
                    DB::table($table)->where('region', $from)->update(['region' => $to]);
                }
            }
        }

        if ($ambiguous) {
            $this->newLine();
            $this->warn('두 지역으로 읽혀 건너뜀 — 화면에서 직접 지정하세요:');
            foreach ($ambiguous as $line) {
                $this->line('  '.$line);
            }
        }

        $this->newLine();
        $this->info($apply
            ? "정규화 완료: {$changed}건 반영"
            : "변경 예정 {$changed}건 (dry-run — 반영하려면 --apply)");

        return self::SUCCESS;
    }
}

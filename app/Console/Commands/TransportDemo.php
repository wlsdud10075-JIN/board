<?php

namespace App\Console\Commands;

use App\Models\TransportCompany;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * 탁송 로컬 시험 준비(2026-11-12) — **local 전용**. 업체 2개(위카·현대A1)와 번호를 심고 다음 단계를 안내한다.
 * 알림톡은 tmplId 가 없어 skipped 로 남지만 요청은 sent 로 가고, 시스템관리자는 탭 이력의 「링크 열기」로 업체 페이지를 밟을 수 있다.
 *
 *   php artisan board:transport-demo                 # 업체·번호 시드 + 절차 출력
 *   php artisan board:transport-demo --phone=010-1234-5678   # 시스템관리자(admin@board.test)·영업 계정에 전화 심기(함께 받는 사람 미리보기용)
 */
class TransportDemo extends Command
{
    protected $signature = 'board:transport-demo {--phone= : 로컬 계정(admin·kim·lee·manager)에 심을 전화번호}';

    protected $description = '탁송 로컬 시험 — 업체·번호 시드 + 절차 안내(local 전용)';

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('local 환경에서만 실행합니다.');

            return self::FAILURE;
        }
        foreach (['위카' => ['01011110001' => '김기사', '01011110002' => '이기사', '0212345678' => '사무실'], '현대A1' => ['01022220001' => '박기사']] as $name => $phones) {
            $c = TransportCompany::withTrashed()->firstOrCreate(['name' => $name], ['is_active' => true]);
            if ($c->trashed()) {
                $c->restore();
            }
            foreach ($phones as $p => $label) {
                if (! $c->phones()->get()->contains(fn ($x) => $x->phone === $p)) {
                    $c->phones()->create(['phone' => $p, 'label' => $label, 'is_active' => true]);
                }
            }
            $this->info("업체 {$name} · 번호 ".count($phones).'개');
        }
        if ($phone = preg_replace('/\D/', '', (string) $this->option('phone'))) {
            $n = User::whereIn('email', ['admin@board.test', 'kim@board.test', 'lee@board.test', 'manager@board.test'])->update(['phone' => $phone]);
            $this->info("전화 {$phone} → 계정 {$n}명");
        }
        $this->line('');
        $this->line('다음 단계(씬 흐름):');
        $this->line(' 1) /listings 드로어 → 「탁송 배차」 탭 → 업체 선택·주소·도착 위치·사진 → [배차 요청 보내기] (지금)');
        $this->line('    예약으로 보냈으면 → php artisan board:transport-send (cron 없음, 손으로 1회)');
        $this->line(' 2) 이력의 업체 수신자 옆 [링크 열기](시스템관리자) → 업체 페이지 → [배차완료] 또는 [배차거절]');
        $this->line(' 3) 상단 종 → 「배차완료」 항목 → 드로어 탁송 탭 → [매입잔금 요청] (ERP 연동 로컬이면 ERP 로 감)');
        $this->line(' 4) 알림톡은 기능설정 tmplId 없이는 skipped(알림톡 발송 기록에 남음) — 흐름은 그대로 진행된다.');

        return self::SUCCESS;
    }
}

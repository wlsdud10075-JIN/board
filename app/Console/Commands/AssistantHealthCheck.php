<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 챗봇 인프라 감시 — 색인은 회사 GPU PC 가 03:00 에 만들어 scp 로 밀어넣는데(handoff-assistant-index-push.md),
 * 호스트별 scp 실패는 그쪽 스크립트에서 한 줄 찍고 넘어간다. 그러면 board 는 **에러 없이 옛 색인으로 계속 답한다**.
 * 추론(Ollama)이 죽어도 마찬가지로 조용하다. 두 지표를 여기서 잡는다.
 *
 *   ① 추론  : Ollama /api/tags 200 인가
 *   ② 신선도: 색인 mtime. 매일 갱신이므로 config('assistant.index_max_age_days') 넘으면 이상.
 *
 * mtime 비교는 epoch 기준이라 서버 시간대와 무관하다.
 */
class AssistantHealthCheck extends Command
{
    protected $signature = 'board:assistant-health';

    protected $description = '챗봇 색인 신선도 + Ollama 응답 점검 (이상이면 로그 경고)';

    public function handle(): int
    {
        // 챗봇 인프라가 없는 환경(로컬·미설정 서버)에는 감시할 것이 없다.
        // ⚠️ 기능설정(Setting)으로는 막지 않는다 — 토글이 꺼져 있어도 색인은 계속 배포되므로.
        if (! config('assistant.enabled')) {
            $this->info('assistant 비활성 환경 — skip');

            return self::SUCCESS;
        }

        $problems = [];

        if (! $this->pingOllama()) {
            $problems[] = '챗봇 추론 서버(Ollama) 응답 없음';
        }

        $path = (string) config('assistant.index_path');
        $mtime = ($path !== '' && is_file($path)) ? filemtime($path) : null;
        $maxDays = (int) config('assistant.index_max_age_days', 3);

        if ($mtime === null) {
            $problems[] = '챗봇 색인 파일이 없음'.($path !== '' ? " ({$path})" : ' (경로 미설정)');
        } else {
            $ageHours = (time() - $mtime) / 3600;
            if ($ageHours >= $maxDays * 24) {
                $problems[] = sprintf('챗봇 색인이 %d시간째 그대로 — scp 배포가 멈췄을 수 있음', (int) $ageHours);
            }
        }

        if ($problems) {
            // 화면을 안 보는 시간대 대비 로그에도 남긴다(알림 수단이 아니라 사후 추적용).
            Log::warning('[board:assistant-health] '.implode(' / ', $problems));
            foreach ($problems as $p) {
                $this->warn('⚠️ '.$p);
            }

            return self::SUCCESS;   // 이상은 "알릴 상태"이지 커맨드 실패가 아니다(cron 이 계속 돌아야 한다).
        }

        $this->info(sprintf('정상 — Ollama OK, 색인 %.1f시간 전', $mtime ? (time() - $mtime) / 3600 : 0));

        return self::SUCCESS;
    }

    private function pingOllama(): bool
    {
        try {
            return Http::timeout(5)->connectTimeout(5)
                ->get(rtrim((string) config('assistant.ollama'), '/').'/api/tags')
                ->successful();
        } catch (\Throwable) {
            return false;   // 연결 실패·타임아웃 — 그 자체가 신호다
        }
    }
}

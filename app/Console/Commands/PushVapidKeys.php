<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/**
 * 웹푸시 VAPID 키쌍 생성 — 출력된 두 줄을 그 박스의 `.env` 에 넣는다(인스턴스마다 다른 키 OK, 공유 불필요).
 * ⚠️ 운영 `.env` 변경 규칙: 백업 동기화 → config:cache(ubuntu) → 큐 워커 재시작.
 * ⚠️ 로컬 XAMPP 는 `OPENSSL_CONF=C:\xampp\apache\conf\openssl.cnf php artisan board:push-vapid-keys` 로(없으면 EC 키 생성 실패).
 * 키를 바꾸면 **기존 구독이 전부 무효**가 된다(브라우저가 옛 공개키로 구독) — 사용자가 토글을 다시 켜야 한다.
 */
class PushVapidKeys extends Command
{
    protected $signature = 'board:push-vapid-keys';

    protected $description = '웹푸시 VAPID 키쌍 생성 (.env BOARD_VAPID_* 값 출력)';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();
        $this->line('BOARD_VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('BOARD_VAPID_PRIVATE_KEY='.$keys['privateKey']);
        $this->line('BOARD_VAPID_SUBJECT=mailto:admin@example.com');

        return self::SUCCESS;
    }
}

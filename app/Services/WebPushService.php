<?php

namespace App\Services;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * 웹푸시 발송 — VAPID 는 박스별 `.env`(BOARD_VAPID_*). 미설정이면 전부 no-op(configured()=false).
 *
 * 수신 쪽은 `public/sw.js`(알림 표시·클릭 시 포털 열기), 구독 저장은 `PushSubscriptionController`.
 * 만료 구독(푸시 서비스가 404/410)은 여기서 지운다 — 안 지우면 영영 실패하는 행에 매분 요청을 보낸다.
 *
 * ⚠️ 로컬(XAMPP) 은 openssl.cnf 경로가 없어 EC 키 생성이 죽는다(`OPENSSL_CONF=C:\xampp\apache\conf\openssl.cnf` 로 실행).
 *    운영(Ubuntu) 은 해당 없음.
 */
class WebPushService
{
    public static function configured(): bool
    {
        $c = config('board.push');

        return ! empty($c['vapid_public']) && ! empty($c['vapid_private']) && ! empty($c['vapid_subject']);
    }

    /**
     * 한 사용자의 모든 기기에 보낸다. 반환 = 성공 건수.
     *
     * @param  array{title:string,body:string,url?:string,tag?:string,sticky?:bool,icon?:?string}  $payload
     */
    public function sendToUser(User $user, array $payload): int
    {
        if (! self::configured()) {
            return 0;
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $sent = 0;
        foreach ($user->pushSubscriptions()->get() as $sub) {
            $ok = $this->deliver($sub, $json);
            if ($ok === true) {
                $sent++;
            } elseif ($ok === false) {
                $sub->delete();   // 만료 — 기기에서 앱을 지웠거나 구독이 갱신됐다
            }
        }

        return $sent;
    }

    /**
     * 1건 발송. true=성공 / false=구독 만료(지울 것) / null=일시 실패(남겨 둔다).
     * 테스트는 이 메서드를 가짜로 바꿔 끼운다(외부 푸시 서비스 호출 없이).
     */
    protected function deliver(PushSubscription $sub, string $json): ?bool
    {
        try {
            $report = $this->client()->sendOneNotification(
                Subscription::create([
                    'endpoint' => $sub->endpoint,
                    'publicKey' => $sub->p256dh,
                    'authToken' => $sub->auth_token,
                    'contentEncoding' => $sub->content_encoding ?: 'aes128gcm',
                ]),
                $json,
                // 거절은 급한 소식 — 푸시 서비스가 하루는 들고 있다가 폰이 켜지면 준다.
                ['TTL' => 86400, 'urgency' => 'high'],
            );
        } catch (\Throwable $e) {
            Log::warning('web push failed', ['subscription' => $sub->id, 'error' => $e->getMessage()]);

            return null;
        }

        if ($report->isSuccess()) {
            return true;
        }
        if ($report->isSubscriptionExpired()) {
            return false;
        }
        Log::warning('web push rejected', ['subscription' => $sub->id, 'reason' => $report->getReason()]);

        return null;
    }

    private ?WebPush $client = null;

    private function client(): WebPush
    {
        return $this->client ??= new WebPush([
            'VAPID' => [
                'subject' => config('board.push.vapid_subject'),
                'publicKey' => config('board.push.vapid_public'),
                'privateKey' => config('board.push.vapid_private'),
            ],
        ]);
    }
}

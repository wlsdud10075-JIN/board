<?php

namespace App\Services;

use App\Models\AlimtalkLog;
use App\Support\AlimtalkConfig;
use App\Support\AlimtalkTemplates;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * 카카오 알림톡(BizM/스윗트래커) 발송 — car-erp BizmAlimtalkService 미러(board 맥락으로 적응).
 *
 * ⚠️ fire-and-forget: 호출측(스케줄 커맨드·배정 저장 훅)으로 **절대 예외를 던지지 않는다**.
 *    알림톡 실패(네트워크·미설정·BizM 오류)가 업무 저장/배정을 깨면 안 되므로 결과를 AlimtalkLog 로만 남긴다.
 *    게이트(enabled)·미설정·개별 off 는 status='skipped' 로 기록(배포 ≠ 작동).
 *
 * BizM v2 발송: POST /v2/sender/send, 헤더 userid, 바디 = 배열[ {message_type,phn,profile,tmplId,msg} ].
 */
class BizmAlimtalkService
{
    private const SEND_URL = 'https://alimtalk-api.bizmsg.kr/v2/sender/send';

    public function __construct(private AlimtalkConfig $config) {}

    public static function active(): self
    {
        return new self(AlimtalkConfig::active());
    }

    /**
     * 알림톡 1건 발송.
     *
     * @param  string  $code  템플릿 코드(board_*)
     * @param  string  $phone  수신 번호(하이픈 무관 — 숫자만 정규화)
     * @param  array  $vars  `#{변수}` 치환값
     * @param  array  $context  ['user_id'=>, 'region'=>] 로그 맥락(선택)
     * @return AlimtalkLog status: sent|failed|skipped
     */
    public function send(string $code, string $phone, array $vars = [], array $context = []): AlimtalkLog
    {
        $phone = $this->normalizePhone($phone);

        // 🚨 로컬 테스트 안전장치 — local 환경에서 ALIMTALK_TEST_PHONE 설정 시 모든 발송 수신자를 그 번호로 강제.
        //    운영 크리덴셜을 로컬에서 쓰는 구조라, 로컬 테스트가 실수신자에게 실제 카톡을 보내는 사고를 차단.
        //    ⚠️ production 은 절대 override 안 함.
        if (app()->environment('local')) {
            $override = preg_replace('/[^0-9]/', '', (string) config('services.alimtalk.test_phone', ''));
            if ($override !== '') {
                $phone = $override;
            }
        }

        $base = [
            'user_id' => $context['user_id'] ?? null,
            'region' => $context['region'] ?? null,
            'template_code' => $code,
            'phone' => $phone,
        ];

        if (! isset(AlimtalkTemplates::TEMPLATES[$code])) {
            return AlimtalkLog::create($base + ['status' => 'skipped', 'error' => 'unknown_template']);
        }
        if ($phone === '') {
            return AlimtalkLog::create($base + ['status' => 'skipped', 'error' => 'no_phone']);
        }
        if (! $this->config->canSend($code)) {
            return AlimtalkLog::create($base + ['status' => 'skipped', 'error' => 'disabled_or_unconfigured']);
        }

        $message = AlimtalkTemplates::render($code, $vars);
        $base['message'] = $message;

        try {
            $item = [
                'message_type' => 'AT',
                'phn' => $phone,
                'profile' => $this->config->profile,
                'tmplId' => $this->config->tmplId($code),
                'msg' => $message,
            ];

            $response = Http::timeout(15)
                ->withHeaders(['userid' => $this->config->userid])
                ->post(self::SEND_URL, [$item]);

            if ($response->failed()) {
                return AlimtalkLog::create($base + [
                    'status' => 'failed',
                    'error' => Str::limit('HTTP '.$response->status().' '.$response->body(), 480, ''),
                ]);
            }

            $body = $response->json();
            $first = is_array($body) ? ($body[0] ?? $body) : [];
            // BizM v2 실응답: [{"code":"success","data":{"msgid":"WEB..."},"message":"K000"}].
            $resCode = is_array($first) ? ($first['code'] ?? null) : null;
            $msgid = is_array($first) ? ($first['data']['msgid'] ?? $first['msgid'] ?? null) : null;

            // ⚠️ bizmsg 는 실패여도 data.msgid 를 반환 → 반드시 code==='success' 확인(오기록 방지).
            if ($resCode === 'success' && $msgid) {
                return AlimtalkLog::create($base + ['status' => 'sent', 'msgid' => (string) $msgid]);
            }

            return AlimtalkLog::create($base + [
                'status' => 'failed',
                'error' => Str::limit('bizmsg fail — '.($first['message'] ?? '').' — '.json_encode($body, JSON_UNESCAPED_UNICODE), 480, ''),
            ]);
        } catch (\Throwable $e) {
            Log::warning('alimtalk send failed', ['code' => $code, 'error' => $e->getMessage()]);

            return AlimtalkLog::create($base + ['status' => 'failed', 'error' => Str::limit($e->getMessage(), 480, '')]);
        }
    }

    /**
     * 테스트 발송 — 지역 검차 안내 템플릿을 지정 번호로 보낸다(크리덴셜/승인 검증용, 기능설정 버튼).
     * 마스터/개별 게이트가 꺼져 있어도 테스트는 확인이 목적이라, 계정 설정 + 해당 tmplId 만 있으면 보낸다.
     */
    public function sendTest(string $phone): AlimtalkLog
    {
        $phone = $this->normalizePhone($phone);
        $code = 'board_region_inspection';
        $base = [
            'user_id' => auth()->id(),
            'template_code' => $code,
            'phone' => $phone,
        ];

        if ($phone === '') {
            return AlimtalkLog::create($base + ['status' => 'skipped', 'error' => 'no_phone']);
        }
        if (! $this->config->isConfigured()) {
            return AlimtalkLog::create($base + ['status' => 'skipped', 'error' => 'unconfigured']);
        }
        if ($this->config->tmplId($code) === '') {
            return AlimtalkLog::create($base + ['status' => 'skipped', 'error' => 'no_test_tmplid']);
        }

        // 게이트를 일시 우회한 임시 config 로 실제 발송(테스트 목적).
        $testConfig = new AlimtalkConfig(
            $this->config->userid, $this->config->profile, $this->config->userkey,
            true, $this->config->tmplIds, [$code => true] + $this->config->toggles,
        );

        return (new self($testConfig))->send($code, $phone, [
            '지역' => '테스트지역',
            '건수' => '2',
            '차량목록' => "12가3456\n34나5678",
        ], ['user_id' => auth()->id()]);
    }

    private function normalizePhone(string $phone): string
    {
        return (string) preg_replace('/[^0-9]/', '', $phone);
    }
}

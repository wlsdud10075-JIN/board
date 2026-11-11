<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;

/**
 * 카카오 알림톡(BizM) 발송 설정 단일 출처 — 기능설정(admin/settings) 에 저장된 계정
 * (userid·발신프로필·userkey)·템플릿ID·on/off 를 읽는다. car-erp AlimtalkConfig 미러.
 *
 * ⚠️ board 는 박스당 1회사(car-erp 의 회사별 set 개념 없음) → 키에 set 접미사 없음.
 * ⚠️ 발신프로필(profile)·userid 는 car-erp 와 **공유**(같은 Bizm 계정, Jin 2026-07-12).
 *
 * - userid    : BizM 계정 아이디(발송 헤더). 필수.
 * - profile   : 발신프로필키. 필수(car-erp 와 동일).
 * - userkey   : 잔액조회 등 부가 API용(발송엔 불필요) — 암호화 저장.
 * - tmplIds   : 템플릿의 BizM 발급 코드. 있어야 해당 알림 발송 가능.
 * - enabled   : 마스터 on/off (배포 ≠ 작동 — 기본 off).
 * - toggles   : 알림 개별 on/off (기본 on).
 */
class AlimtalkConfig
{
    /** 개별 토글 기본값이 OFF 인 템플릿 — 클레임 2종(2026-11-11 Jin 「알림톡은 아직 승인 안 됐으니 OFF 로 기본」). 기능설정에서 켜야 나간다. */
    public const DEFAULT_OFF = ['board_claim_approval_request', 'board_claim_decision'];

    public function __construct(
        public string $userid,
        public string $profile,
        public ?string $userkey,
        public bool $enabled,
        public array $tmplIds,
        public array $toggles,
    ) {}

    public static function active(): self
    {
        $userid = (string) (Setting::get('alimtalk_userid', '') ?: '');
        $profile = (string) (Setting::get('alimtalk_profile', '') ?: '');
        $enabled = (bool) Setting::get('alimtalk_enabled', false);

        $userkey = null;
        if ($enc = Setting::get('alimtalk_userkey')) {
            try {
                $userkey = Crypt::decryptString($enc);
            } catch (\Throwable $e) {
                $userkey = null;
            }
        }

        $tmplIds = [];
        $toggles = [];
        foreach (array_keys(AlimtalkTemplates::TEMPLATES) as $code) {
            $tmplIds[$code] = (string) (Setting::get("alimtalk_tmpl_{$code}", '') ?: '');
            // 기본 켜짐 — 단 BizM 승인 전에 배포된 템플릿은 기본 꺼짐(DEFAULT_OFF): 마스터를 다른 알림 때문에 켜도 같이 나가지 않게.
            $toggles[$code] = (bool) Setting::get("alimtalk_toggle_{$code}", ! in_array($code, self::DEFAULT_OFF, true));
        }

        return new self($userid, $profile, $userkey, $enabled, $tmplIds, $toggles);
    }

    /** 발송 계정 설정 여부 — userid + profile 필수(userkey 는 잔액조회 전용). */
    public function isConfigured(): bool
    {
        return $this->userid !== '' && $this->profile !== '';
    }

    public function tmplId(string $code): string
    {
        return $this->tmplIds[$code] ?? '';
    }

    /** 이 알림을 실제 보낼 수 있는가 — 마스터 on + 계정 설정 + 개별 on + 해당 tmplId 존재. */
    public function canSend(string $code): bool
    {
        return $this->enabled
            && $this->isConfigured()
            && ($this->toggles[$code] ?? false)
            && $this->tmplId($code) !== '';
    }
}

<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * 알림톡 「추가로 받을 사람」 — 알림마다 역할·사람을 체크해 둔다(2026-11-23 Jin «ERP 알림톡 안내처럼 인원별로 체크», car-erp AlimtalkRecipients 의 역할 피커만 미러).
 *
 * board 알림톡은 전부 **그 건의 당사자**(담당자·접수 영업·등록 영업·지금 차례 결재자·업체)에게 자동으로 간다 — 그건 그대로다(AUTO).
 * 여기서 정하는 건 **그 위에 더 받을 사람**뿐이고, 기본은 아무도 없음(설정이 없으면 발송 대상이 종전과 글자 그대로 같다).
 *
 * - 저장 = Setting `alimtalk_roles_{알림}` 콤마 토큰: 역할 키(GROUPS) · `user:{id}`(개별 지정) · `approval_line`(그 클레임 결재선 전원).
 *   🔑 사람을 번호가 아니라 계정으로 가리킨다 — 비활성·삭제되면 자동으로 빠진다. 번호 직접 입력은 두지 않는다.
 * - 키는 **템플릿 코드가 아니라 알림**이다: 「클레임 결재 결과」 템플릿 하나를 최종 결재 때(자동 + 체크)와 ERP 전송 완료 때(체크한 사람만) 두 번 쓴다.
 * - 서명 링크가 붙는 두 알림(클레임 결재요청·탁송 배차 요청)은 받는 사람이 곧 처리 권한이라 추가 수신을 열지 않는다(PICKABLE 에 없음).
 * - board 는 박스당 1회사 → ERP 의 회사(set) 접미사 없음. 시각 규칙·단계별 확대·차량 스코프도 없음(체크한 사람은 그 알림을 전부 받는다).
 */
class AlimtalkRecipients
{
    /** 역할 그룹 key => lang 키. 등급 3종 + 등급 「일반」의 role 4종(ERP 와 같은 결 — 등급이 있는 사람은 등급 줄에만 뜬다). */
    public const GROUPS = [
        'admin' => 'alimtalk_catalog.group.admin',
        'manager' => 'alimtalk_catalog.group.manager',
        'role_manager' => 'alimtalk_catalog.group.role_manager',
        'role_sales' => 'alimtalk_catalog.group.role_sales',
        'role_inspection' => 'alimtalk_catalog.group.role_inspection',
        'role_auction' => 'alimtalk_catalog.group.role_auction',
        'super' => 'alimtalk_catalog.group.super',
    ];

    /** 🪜 그 클레임의 결재선 전원 — 사용자 집합이 아니라 건마다 다르다(호출부가 결재선 사용자를 넘긴다). */
    public const APPROVAL_LINE = 'approval_line';

    /** ERP 전송 완료 알림 — 템플릿은 `board_claim_decision` 을 그대로 쓴다(확정 금액 = ERP 전송 금액, 새 BizM 승인 없음). */
    public const CLAIM_ERP_SENT = 'board_claim_erp_sent';

    /** 알림 => 실제 템플릿 코드(다를 때만). */
    public const TEMPLATE_OF = [self::CLAIM_ERP_SENT => 'board_claim_decision'];

    /** 알림 => 자동으로 받는 사람 설명(lang 키). 체크와 무관하게 항상 간다. null = 자동 수신자 없음(체크한 사람만). */
    public const AUTO = [
        'board_region_inspection' => 'alimtalk_catalog.auto.region_inspection',
        'board_forward_ready' => 'alimtalk_catalog.auto.forward_ready',
        'board_claim_approval_request' => 'alimtalk_catalog.auto.claim_approval_request',
        'board_claim_decision' => 'alimtalk_catalog.auto.claim_decision',
        self::CLAIM_ERP_SENT => null,
        'board_transport_request' => 'alimtalk_catalog.auto.transport_request',
        'board_transport_result' => 'alimtalk_catalog.auto.transport_result',
    ];

    /** 추가 수신을 고를 수 있는 알림. */
    public const PICKABLE = ['board_region_inspection', 'board_forward_ready', 'board_claim_decision', self::CLAIM_ERP_SENT, 'board_transport_result'];

    /** 「결재선 전원」 줄이 뜨는 알림(클레임 건에 묶인 것). */
    public const LINE_NOTICES = ['board_claim_decision', self::CLAIM_ERP_SENT];

    /** 안내 화면에 그리는 순서. */
    public static function notices(): array
    {
        return array_keys(self::AUTO);
    }

    public static function templateOf(string $notice): string
    {
        return self::TEMPLATE_OF[$notice] ?? $notice;
    }

    public static function isPickable(string $notice): bool
    {
        return in_array($notice, self::PICKABLE, true);
    }

    public static function supportsLine(string $notice): bool
    {
        return in_array($notice, self::LINE_NOTICES, true);
    }

    public static function userIdOf(string $token): ?int
    {
        return preg_match('/^user:(\d+)$/', trim($token), $m) ? (int) $m[1] : null;
    }

    /** 저장된 토큰. 미설정 = 빈 배열(추가 수신자 없음 — 종전 동작). */
    public static function selected(string $notice): array
    {
        if (! self::isPickable($notice)) {
            return [];
        }
        $raw = (string) (Setting::get('alimtalk_roles_'.$notice, '') ?: '');

        return collect(explode(',', $raw))->map(fn ($t) => trim($t))->filter()->unique()->values()->all();
    }

    /** 이 알림에 저장해도 되는 토큰인가 — 화면 저장 검증과 발송 해석이 같은 판정을 쓴다. */
    public static function isValidToken(string $notice, string $token): bool
    {
        if (! self::isPickable($notice)) {
            return false;
        }
        if (isset(self::GROUPS[$token])) {
            return true;
        }
        if ($token === self::APPROVAL_LINE) {
            return self::supportsLine($notice);
        }
        $id = self::userIdOf($token);

        return $id !== null && self::userIsPickable($id);
    }

    /** 역할 그룹 → 활성 사용자 쿼리(단일 출처). role 줄은 등급 「일반」만 — 등급 줄과 겹치지 않게. */
    private static function groupQuery(string $group)
    {
        $q = User::query()->where('is_active', true);

        return match ($group) {
            'super' => $q->where('permission', 'super'),
            'admin' => $q->where('permission', 'admin'),
            'manager' => $q->where('permission', 'manager'),
            'role_manager' => $q->where('permission', 'user')->where('role', 'manager'),
            'role_sales' => $q->where('permission', 'user')->where('role', 'sales'),
            'role_inspection' => $q->where('permission', 'user')->where('role', 'inspection'),
            'role_auction' => $q->where('permission', 'user')->where('role', 'auction'),
            default => null,
        };
    }

    /**
     * 피커용 명단 — 번호가 없어도 버리지 않는다(화면이 「번호 없음」을 보여줘야 「체크했는데 안 온다」가 안 생긴다).
     *
     * @return array<int, array{id:int, name:string, phone:string}>
     */
    public static function groupMembers(string $group): array
    {
        $q = self::groupQuery($group);

        return $q === null ? [] : $q->orderBy('name')->get(['id', 'name', 'email', 'phone'])
            ->map(fn (User $u) => ['id' => (int) $u->id, 'name' => (string) ($u->name ?: $u->email), 'phone' => trim((string) $u->phone)])
            ->all();
    }

    public static function userIsPickable(int $id): bool
    {
        return User::query()->whereKey($id)->where('is_active', true)->exists();
    }

    /**
     * 체크된 사람들(활성 계정만, 중복 제거). `$line` = 그 건의 결재선 사용자(「결재선 전원」이 켜져 있을 때만 쓰인다).
     *
     * @param  iterable<User|null>  $line
     * @return Collection<int, User>
     */
    public static function users(string $notice, iterable $line = []): Collection
    {
        $out = collect();
        foreach (self::selected($notice) as $token) {
            if ($token === self::APPROVAL_LINE) {
                if (self::supportsLine($notice)) {
                    $out = $out->concat(collect($line)->filter(fn ($u) => $u instanceof User && $u->is_active));
                }
            } elseif (isset(self::GROUPS[$token])) {
                $out = $out->concat(self::groupQuery($token)->get());
            } elseif (($id = self::userIdOf($token)) !== null) {
                $out = $out->concat(User::query()->whereKey($id)->where('is_active', true)->get());
            }
        }

        return $out->unique('id')->values();
    }

    /**
     * 추가로 보낼 대상 — 번호 있는 사람만, 자동 수신자(`$already` 번호)와 겹치면 뺀다(숫자만 비교).
     *
     * @param  iterable<string|null>  $already
     * @param  iterable<User|null>  $line
     * @return Collection<int, User>
     */
    public static function extras(string $notice, iterable $already = [], iterable $line = []): Collection
    {
        $seen = [];
        foreach ($already as $p) {
            $seen[self::digits($p)] = true;
        }
        unset($seen['']);

        return self::users($notice, $line)->filter(function (User $u) use (&$seen) {
            $d = self::digits($u->phone);
            if ($d === '' || isset($seen[$d])) {
                return false;
            }

            return $seen[$d] = true;
        })->values();
    }

    /** 안내 화면 「지금 추가로 N명」 — 결재선 전원은 건마다 달라 세지 않는다. */
    public static function countExtras(string $notice): int
    {
        return self::extras($notice)->count();
    }

    private static function digits(mixed $phone): string
    {
        return (string) preg_replace('/[^0-9]/', '', (string) $phone);
    }
}

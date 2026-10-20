<?php

namespace App\Support;

use App\Models\IntegrationEvent;
use Illuminate\Support\Facades\Lang;

/**
 * 감사 로그 「car-erp 전송」 표의 사람 말 표시(2026-10-20 Jin) — 방향/대상·이벤트·응답·내용이
 * `outbound / car_erp`·`purchase_sync`·`201`·JSON 원문으로 떠 실무자가 읽을 수 없었다.
 *
 * 🔑 **표시 전용.** 필터·검색은 원래 값(event_type·response_status)으로 돈다. 라벨은 lang(`audit.ev.*`)이라 KO/EN 을 탄다.
 * 🚫 모르는 값은 **원문 그대로** — 새 이벤트·새 사유를 버리면 추적할 길이 없다(매입예정 재전송 문구와 같은 원칙).
 */
class IntegrationEventView
{
    /** lang 키가 있으면 번역, 없으면 원문. */
    private static function t(string $key, string $raw): string
    {
        return Lang::has($key) && is_string(__($key)) ? __($key) : $raw;
    }

    public static function direction(?string $direction): string
    {
        return self::t('audit.ev.direction.'.$direction, (string) $direction);
    }

    public static function target(?string $target): string
    {
        return self::t('audit.ev.target.'.$target, (string) $target);
    }

    public static function event(?string $type): string
    {
        return self::t('audit.ev.event.'.$type, (string) $type);
    }

    /**
     * 응답 — [문구, 성공여부(true/false/null=판정 없음)]. 숫자 코드는 화면 title(툴팁)로만 남긴다.
     * 계좌 추출(ollama)은 HTTP 호출 기록이 아니라 응답 코드가 없다 → 결과 상태로 말한다.
     */
    public static function response(IntegrationEvent $ev): array
    {
        $code = $ev->response_status;

        if ($ev->event_type === 'payee_extract') {
            $st = (string) data_get($ev->request_payload, 'status');

            return [self::t('audit.ev.payee_status.'.$st, $st !== '' ? $st : '—'), match ($st) {
                'done' => true,
                'failed' => false,
                default => null,
            }];
        }

        if ($code === null) {
            return [__('audit.ev.resp.none'), false];
        }

        $ok = $code >= 200 && $code < 300;
        // 이벤트별 문구가 있으면 그것(purchase_sync 201 = 새로 등록 / 200 = 이미 있어 보강), 없으면 계열별.
        $specific = 'audit.ev.resp_by.'.$ev->event_type.'.'.$code;
        if (Lang::has($specific)) {
            return [__($specific), $ok];
        }

        $class = match (true) {
            $ok => 'ok',
            $code === 401 || $code === 403 => 'auth',
            $code === 422 => 'rejected',
            $code >= 500 => 'server',
            default => 'fail',
        };

        return [__('audit.ev.resp.'.$class), $ok];
    }

    /** 내용 한 줄 요약 — 그 전송이 실제로 한 일. 해석할 게 없으면 빈 배열(화면은 원본 펼침만). */
    public static function summary(IntegrationEvent $ev): array
    {
        $p = (array) ($ev->request_payload ?? []);
        $body = json_decode((string) $ev->response_body, true);
        $body = is_array($body) ? $body : [];
        $out = [];

        switch ($ev->event_type) {
            case 'purchase_sync':
                if (isset($body['vehicle_id'])) {
                    $out[] = __('audit.ev.sum.erp_vehicle', ['id' => $body['vehicle_id']]);
                }
                if (isset($body['attachments_added'])) {
                    $out[] = __('audit.ev.sum.attachments', ['n' => (int) $body['attachments_added']]);
                    if ((int) ($body['attachments_failed'] ?? 0) > 0) {
                        $out[] = __('audit.ev.sum.attachments_failed', ['n' => (int) $body['attachments_failed']]);
                    }
                }
                if (! empty($body['fields_filled'])) {
                    $out[] = __('audit.ev.sum.filled', ['fields' => implode(', ', array_map(fn ($f) => self::fieldLabel((string) $f), (array) $body['fields_filled']))]);
                }
                if (! empty($body['fields_skipped']) && is_array($body['fields_skipped'])) {
                    $parts = [];
                    foreach ($body['fields_skipped'] as $f => $why) {
                        $parts[] = self::fieldLabel((string) $f).' — '.self::t('listings.resync.why.'.$why, (string) $why);
                    }
                    $out[] = __('audit.ev.sum.skipped', ['fields' => implode(', ', $parts)]);
                }
                if (! empty($body['errors']) && is_array($body['errors'])) {
                    $out[] = __('audit.ev.sum.invalid', ['fields' => implode(', ', array_map(fn ($f) => self::fieldLabel((string) $f), array_keys($body['errors'])))]);
                }
                break;

            case 'payee_extract':
                $out[] = __('audit.ev.sum.payee', ['n' => (int) ($p['candidates'] ?? 0)]);
                if ((int) ($p['photo_errors'] ?? 0) > 0) {
                    $out[] = __('audit.ev.sum.photo_errors', ['n' => (int) $p['photo_errors']]);
                }
                break;

            case 'auto_forward_ready':
                $r = (string) ($p['reason'] ?? '');
                if ($r !== '') {
                    $out[] = self::t('audit.ev.reason.'.$r, $r);
                }
                break;

            case 'verdict_poll':
            case 'buyer_verdict':
                if (isset($p['verdict'])) {
                    $out[] = __('audit.ev.sum.verdict', ['v' => self::t('audit.ev.verdict.'.$p['verdict'], (string) $p['verdict'])]);
                }
                $r = (string) ($p['result'] ?? $ev->response_body ?? '');
                if ($r !== '') {
                    $out[] = self::t('audit.ev.result.'.str_replace(':', '_', $r), $r);
                }
                break;

            case 'promote_poll':
                if (! empty($p['label'])) {
                    $out[] = __('audit.ev.sum.label', ['label' => $p['label']]);
                }
                break;

            case 'send_offer':
                $out[] = __('audit.ev.sum.photos_sent', ['n' => (int) ($p['photos'] ?? 0)]);
                break;
        }

        return $out;
    }

    /** 원본 펼침 — [라벨, 값] 목록. 키는 감사 항목 이름표를 재사용하고, 모르는 키는 원문. */
    public static function payloadRows(IntegrationEvent $ev): array
    {
        $rows = [];
        foreach ((array) ($ev->request_payload ?? []) as $k => $v) {
            $rows[] = [self::fieldLabel((string) $k), self::value((string) $k, $v)];
        }

        return $rows;
    }

    private static function fieldLabel(string $k): string
    {
        foreach (['audit.ev.key.'.$k, 'audit.field.'.$k, 'listings.resync.field.'.$k] as $key) {
            if (Lang::has($key) && is_string(__($key))) {
                return __($key);
            }
        }

        return $k;
    }

    private static function value(string $k, mixed $v): string
    {
        if ($v === null || $v === '') {
            return '—';
        }
        if (is_bool($v)) {
            return $v ? __('audit.ev.yes') : __('audit.ev.no');
        }
        if (is_array($v)) {
            return array_is_list($v)
                ? __('audit.ev.items', ['n' => count($v)])
                : (string) json_encode($v, JSON_UNESCAPED_UNICODE);
        }
        if (in_array($k, ['vehicle_number', 'car_no'], true)) {
            return (string) Plate::show((string) $v);
        }
        if ($k === 'source') {
            return self::t('domain.source.'.$v, (string) $v);
        }
        if (is_int($v) || is_float($v)) {
            return number_format($v, is_float($v) && floor($v) != $v ? 2 : 0);
        }

        return (string) $v;
    }
}

<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * 연동 A (C) — respond.io Developer API 클라이언트 (Growth 플랜).
 *
 * 용도: 회신 커스텀필드(수락/거절/대기) 폴링 + 처리 후 '대기' 리셋.
 * 안전밸브: base_url/token 미설정이면 모든 호출 no-op → 운영 배포해도 안 터짐(연동 B 패턴).
 *
 * ⚠️ API 엔드포인트/응답 모양은 **가정 계약** — respond.io 워크스페이스 연결(필드 생성·토큰 발급)
 *    시점에 실제 스펙으로 확정/조정한다. 변경 지점은 이 파일 1곳에 격리됨.
 */
class RespondIoService
{
    private ?string $base;

    private ?string $token;

    private string $field;

    public function __construct()
    {
        $this->base = config('services.respond_io.base_url');
        $this->token = config('services.respond_io.api_token');
        $this->field = (string) config('services.respond_io.verdict_field');
    }

    public function configured(): bool
    {
        return ! empty($this->base) && ! empty($this->token);
    }

    /**
     * 회신 필드가 수락/거절로 찍힌 컨택트 → 정규화 목록.
     * (대기/미설정은 제외)
     *
     * @return array<int,array{conversation_id:?string, contact_id:?string, verdict:string}>
     */
    public function pendingVerdicts(): array
    {
        if (! $this->configured()) {
            return [];
        }

        $res = Http::withToken($this->token)->acceptJson()
            ->get(rtrim($this->base, '/').'/v2/contact', ['field' => $this->field]);

        if ($res->failed()) {
            return [];
        }

        $out = [];
        foreach ((array) $res->json('items', []) as $c) {
            $raw = $c['custom_fields'][$this->field] ?? ($c[$this->field] ?? null);
            $verdict = $this->mapVerdict(is_string($raw) ? $raw : null);
            if ($verdict === null) {
                continue;
            }
            $out[] = [
                'conversation_id' => isset($c['conversation_id']) ? (string) $c['conversation_id'] : null,
                'contact_id' => isset($c['id']) ? (string) $c['id'] : null,
                'verdict' => $verdict,
            ];
        }

        return $out;
    }

    /** 처리 후 회신 필드를 '대기'로 리셋 → 같은 바이어의 다음 차를 받을 준비(직렬화). */
    public function resetVerdict(?string $contactId): void
    {
        if (! $this->configured() || empty($contactId)) {
            return;
        }

        Http::withToken($this->token)->acceptJson()
            ->put(rtrim($this->base, '/')."/v2/contact/{$contactId}", [
                'custom_fields' => [['name' => $this->field, 'value' => '대기']],
            ]);
    }

    /** respond.io 필드값(한글/영문) → board verdict. */
    private function mapVerdict(?string $v): ?string
    {
        return match ($v) {
            '수락', 'accepted' => 'accepted',
            '거절', 'rejected' => 'rejected',
            default => null,
        };
    }
}

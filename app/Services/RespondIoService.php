<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * 연동 A (C) — respond.io Developer API 클라이언트 (Growth 플랜).
 *
 * 용도: 회신 커스텀필드(수락/거절/대기) 폴링 + 처리 후 '대기' 리셋.
 * 안전밸브: base_url/token 미설정이면 모든 호출 no-op → 운영 배포해도 안 터짐(연동 B 패턴).
 *
 * 계약 출처: respond.io API v2 (공식 client 소스로 확인).
 *  ✅ 확인됨: base=https://api.respond.io/v2/ · 'Authorization: Bearer <token>'
 *            목록 = POST contact/list?limit=N (body=filter) · 식별자 = {key}:{value}
 *            수정 = PUT contact/id:{id}
 *  ⚠️ 라이브 확인 잔여(워크스페이스 토큰으로): ① 커스텀필드 필터 category 명('customField' 가정)
 *     ② 응답 컨택트 객체의 커스텀필드 표현(custom_fields[name] 가정) ③ 컨택트에 conversation_id
 *     포함 여부(없으면 매칭키=respond_contact_id 로 전환 + A2 에서 contact_id 캡처 보강).
 *     이 셋만 실제 응답 1건 보고 이 파일에서 조정.
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

    private function url(string $path): string
    {
        return rtrim((string) $this->base, '/').'/v2/'.ltrim($path, '/');
    }

    /**
     * 회신 필드가 '대기'가 아닌(=수락/거절) 컨택트 → 정규화 목록.
     *
     * @return array<int,array{conversation_id:?string, contact_id:?string, verdict:string}>
     */
    public function pendingVerdicts(): array
    {
        if (! $this->configured()) {
            return [];
        }

        // POST contact/list — 커스텀필드(회신) != '대기' 인 컨택트만.
        $res = Http::withToken($this->token)->acceptJson()
            ->post($this->url('contact/list?limit=100'), [
                'search' => '',
                'timezone' => 'UK/London',
                'filter' => [
                    '$and' => [[
                        'category' => 'customField',     // ⚠️ 라이브 확인
                        'field' => $this->field,
                        'operator' => 'isNotEqualTo',
                        'value' => '대기',
                    ]],
                ],
            ]);

        if ($res->failed()) {
            return [];
        }

        // 응답 컨테이너 키는 구현차 대비 다중 시도(items/data/list).
        $items = $res->json('items') ?? $res->json('data') ?? $res->json('list') ?? [];

        $out = [];
        foreach ((array) $items as $c) {
            $verdict = $this->mapVerdict($this->extractField($c));
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

        // PUT contact/id:{id} — 커스텀필드 '대기'로. ⚠️ 커스텀필드 body 모양 라이브 확인.
        Http::withToken($this->token)->acceptJson()
            ->put($this->url('contact/id:'.$contactId), [
                'custom_fields' => [['name' => $this->field, 'value' => '대기']],
            ]);
    }

    /** 컨택트 객체에서 회신 커스텀필드 값 추출 (표현 방식 다중 대비). */
    private function extractField(array $c): ?string
    {
        // (a) custom_fields[name] 맵
        if (isset($c['custom_fields'][$this->field]) && is_string($c['custom_fields'][$this->field])) {
            return $c['custom_fields'][$this->field];
        }
        // (b) custom_fields = [{name,value}, ...] 리스트
        foreach ((array) ($c['custom_fields'] ?? []) as $f) {
            if (is_array($f) && ($f['name'] ?? null) === $this->field) {
                return is_string($f['value'] ?? null) ? $f['value'] : null;
            }
        }

        // (c) 최상위 평면
        return isset($c[$this->field]) && is_string($c[$this->field]) ? $c[$this->field] : null;
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

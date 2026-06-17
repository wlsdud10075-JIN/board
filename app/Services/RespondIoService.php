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
 *  ✅ 라이브 확인 완료(2026-06-17 실호출):
 *     - base=https://api.respond.io/v2/ · 'Authorization: Bearer <token>'
 *     - 목록 = POST contact/list?limit=N (body=filter) · 응답 = {items[], pagination}
 *     - 필터 category = **'contactField'** (커스텀필드도 동일. 'customField' 는 400)
 *     - custom_fields = [{name,value}, ...] 리스트, 필드명 = 'buyer_verdict'
 *     - 컨택트에 **conversation_id 없음** → 매칭키 = contact **id**(정수). 식별자 {key}:{value} (id:469…)
 *     - 수정 = PUT contact/id:{id}
 */
class RespondIoService
{
    private ?string $base;

    private ?string $token;

    private string $field;

    private string $vAccept;

    private string $vRefuse;

    private string $vHold;

    public function __construct()
    {
        $this->base = config('services.respond_io.base_url');
        $this->token = config('services.respond_io.api_token');
        $this->field = (string) config('services.respond_io.verdict_field');
        $this->vAccept = (string) config('services.respond_io.verdict_values.accept');
        $this->vRefuse = (string) config('services.respond_io.verdict_values.refuse');
        $this->vHold = (string) config('services.respond_io.verdict_values.hold');
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
     * 회신 필드 = Accept/Refuse 인 컨택트 → [contact_id, verdict] 목록 (Hold/빈값 제외).
     *
     * @return array<int,array{contact_id:string, verdict:string}>
     */
    public function pendingVerdicts(): array
    {
        if (! $this->configured()) {
            return [];
        }

        // POST contact/list — buyer_verdict = Accept 또는 Refuse 인 컨택트만.
        $res = Http::withToken($this->token)->acceptJson()
            ->post($this->url('contact/list?limit=100'), [
                'search' => '',
                'timezone' => 'UK/London',
                'filter' => [
                    '$or' => [
                        ['category' => 'contactField', 'field' => $this->field, 'operator' => 'isEqualTo', 'value' => $this->vAccept],
                        ['category' => 'contactField', 'field' => $this->field, 'operator' => 'isEqualTo', 'value' => $this->vRefuse],
                    ],
                ],
            ]);

        if ($res->failed()) {
            return [];
        }

        $out = [];
        foreach ((array) $res->json('items', []) as $c) {
            $verdict = $this->mapVerdict($this->extractField($c));
            $id = $c['id'] ?? null;
            if ($verdict === null || $id === null) {
                continue;
            }
            $out[] = ['contact_id' => (string) $id, 'verdict' => $verdict];
        }

        return $out;
    }

    /** outbound — 바이어에게 텍스트 전송. POST contact/id:{id}/message {message:{type:text}}. */
    public function sendText(?string $contactId, string $text): bool
    {
        if (! $this->configured() || empty($contactId) || $text === '') {
            return false;
        }

        return Http::withToken($this->token)->acceptJson()
            ->post($this->url('contact/id:'.$contactId.'/message'), [
                'message' => ['type' => 'text', 'text' => $text],
            ])->successful();
    }

    /** outbound — 바이어에게 미디어(image/video/file) 전송 (presigned URL). */
    public function sendAttachment(?string $contactId, string $type, string $url): bool
    {
        if (! $this->configured() || empty($contactId) || $url === '') {
            return false;
        }

        return Http::withToken($this->token)->acceptJson()
            ->post($this->url('contact/id:'.$contactId.'/message'), [
                'message' => ['type' => 'attachment', 'attachment' => ['type' => $type, 'url' => $url]],
            ])->successful();
    }

    /** 처리 후 회신 필드를 '대기'로 리셋 → 같은 바이어의 다음 차를 받을 준비(직렬화). */
    public function resetVerdict(?string $contactId): void
    {
        if (! $this->configured() || empty($contactId)) {
            return;
        }

        // PUT contact/id:{id} — 커스텀필드 Hold(중립)로. ⚠️ 커스텀필드 body 모양 라이브 확인.
        Http::withToken($this->token)->acceptJson()
            ->put($this->url('contact/id:'.$contactId), [
                'custom_fields' => [['name' => $this->field, 'value' => $this->vHold]],
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

    /** respond.io 드롭다운값 → board verdict. Hold/기타 = null(폴러 무시). */
    private function mapVerdict(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }
        if ($v === $this->vAccept || $v === '수락' || $v === 'accepted') {
            return 'accepted';
        }
        if ($v === $this->vRefuse || $v === '거절' || $v === 'rejected') {
            return 'rejected';
        }

        return null;
    }
}

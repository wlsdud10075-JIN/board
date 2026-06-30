<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * ssancar.com 차량 미디어 API (api_car_media.php) 클라이언트.
 *
 * 검차 영상(Bunny embed/hls)·사진을 **다운로드/재업로드 없이 링크로** 받아 바이어 페이지에 첨부
 * → board 영상 용량/업로드 문제 회피. 권위 = ssancar 전달문서(2026-06-30).
 *
 * 매칭 = type+id 직접모드 (board 가 c_no / ssancar_ref(wr_id·car_no) 를 보유 — raw URL 미저장).
 * 헤더 X-Api-Key. 미설정/미매칭/실패 = 빈 배열 (절대 throw 안 함 — 공개 바이어페이지 가용성 우선).
 *
 * 응답: {ok, mode, sources, videos[], photos[]}.
 *  - photos[] = URL 문자열 배열. videos[] = 객체(embed_url=iframe·권장 / hls_url / thumbnail / url=source:local).
 *  - 영상은 검차(inspected)에만 존재. 재고·옥션은 사진만.
 */
class SsancarMediaService
{
    /** @return array{videos: list<array<string,?string>>, photos: list<string>} */
    public function fetch(string $type, string $id): array
    {
        $empty = ['videos' => [], 'photos' => []];
        $base = (string) config('services.ssancar_media.base_url');
        $key = (string) config('services.ssancar_media.api_key');
        if ($base === '' || $key === '' || $id === '') {
            return $empty;
        }

        $cacheKey = "ssancar_media:{$type}:{$id}";
        if (($hit = Cache::get($cacheKey)) !== null) {
            return $hit;
        }

        try {
            $res = Http::withHeaders(['X-Api-Key' => $key])
                ->timeout(4)   // 공개 바이어페이지 — ssancar 지연 시 미디어 없이 빠른 렌더 우선
                ->get($base, ['type' => $type, 'id' => $id]);
        } catch (\Throwable) {
            return $empty;   // 일시장애는 캐시 안 함(다음 조회에서 재시도)
        }
        if ($res->failed()) {
            return $empty;
        }

        $j = $res->json();
        if (! is_array($j) || (int) ($j['ok'] ?? 0) !== 1) {
            return $empty;
        }

        $videos = collect($j['videos'] ?? [])
            ->filter(fn ($v) => is_array($v))
            ->map(fn ($v) => [
                'embed_url' => $v['embed_url'] ?? null,   // iframe (권장)
                'hls_url' => $v['hls_url'] ?? null,
                'thumbnail' => $v['thumbnail'] ?? null,
                'url' => $v['url'] ?? null,               // source=local 일 때
            ])
            ->filter(fn ($v) => $v['embed_url'] || $v['url'])
            ->values()->all();

        $photos = collect($j['photos'] ?? [])
            ->filter(fn ($p) => is_string($p) && $p !== '')
            ->values()->all();

        $out = ['videos' => $videos, 'photos' => $photos];
        Cache::put($cacheKey, $out, now()->addMinutes(10));

        return $out;
    }
}

<?php

namespace App\Services;

use App\Models\PurchaseListing;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * ssancar.com 차량 미디어 API (api_car_media.php) 클라이언트.
 *
 * 검차 영상(Bunny embed/hls)·사진을 **다운로드/재업로드 없이 링크로** 받아 바이어 페이지에 첨부
 * → board 영상 용량/업로드 문제 회피. 권위 = ssancar 전달문서(2026-06-30).
 *
 * 매칭 2종:
 *  (A) type+id 직접모드 — board 가 c_no / ssancar_ref(wr_id·car_no) 보유 시(raw URL 미저장).
 *  (B) vin/번호판 교차매칭 — ssancar id 없는 차(엔카 등) 폴백. inspected+stock 합산(옥션 제외).
 * 헤더 X-Api-Key. 미설정/미매칭/실패 = 빈 배열 (절대 throw 안 함 — 공개 바이어페이지 가용성 우선).
 *
 * 응답: {ok, mode, sources, videos[], photos[]}.
 *  - photos[] = URL 문자열 배열. videos[] = 객체(embed_url=iframe·권장 / hls_url / thumbnail / url=source:local).
 *  - 영상은 검차(inspected)에만 존재. 재고·옥션은 사진만.
 */
class SsancarMediaService
{
    private const EMPTY = ['videos' => [], 'photos' => [], 'sources' => []];

    /** 설정(base_url·api_key) 되어 있나 — 미설정이면 폴러/호출 no-op. */
    public function configured(): bool
    {
        return (string) config('services.ssancar_media.base_url') !== ''
            && (string) config('services.ssancar_media.api_key') !== '';
    }

    /**
     * 매물 1건의 ssancar 미디어 — (A) ssancar id 있으면 직접모드 / 없으면 (B) vin·번호판 교차매칭.
     * BuyerViewController·전달드로어·폴러 공용 단일 경로(로직 분기 중복 제거).
     */
    public function mediaFor(PurchaseListing $l): array
    {
        $params = $l->ssancarMediaParams();
        $direct = $params ? $this->fetch($params['type'], $params['id']) : self::EMPTY;

        // 직접 엔트리(inspected)에 이미 영상 있으면 그대로. 없으면(auction/stock/무 ref) 검차영상은
        // inspected 에만 있으므로 번호판 교차매칭으로 합류 — 영상은 교차매칭 것, 사진은 합집합(auction 사진 보존).
        if (! empty($direct['videos'])) {
            return $direct;
        }
        $cross = $this->fetchByVehicle($l->vin, $l->vehicle_number);

        return [
            'videos' => $cross['videos'],
            'photos' => array_values(array_unique(array_merge($direct['photos'], $cross['photos']))),
        ];
    }

    /**
     * 폴러 전이 판정 (Jin 규칙, 2026-07-01) — 번호판/vin 교차매칭의 소스별 미디어로 결정.
     *  - inspected 에 영상 있으면 → 전이 (검차완료).
     *  - stock 에 사진 있으면 → 전이 (재고 차량은 사진이 전부).
     *  - inspected 에 사진만(영상 0) → 대기 (영상 올 때까지). = 검차 진행중.
     * (auction 은 제외 — 낙찰 전 경매매물, 전달 대상 아님.)
     *
     * @return array{advance: bool, has_media: bool, reason: string}
     */
    public function pollDecision(PurchaseListing $l): array
    {
        $src = $this->fetchByVehicle($l->vin, $l->vehicle_number)['sources'] ?? [];
        $inspVideos = count($src['inspected']['videos'] ?? []);
        $inspPhotos = count($src['inspected']['photos'] ?? []);
        $stockPhotos = count($src['stock']['photos'] ?? []);

        return [
            'advance' => $inspVideos > 0 || $stockPhotos > 0,
            'has_media' => ($inspVideos + $inspPhotos + $stockPhotos) > 0,   // 연결 표식(에이지아웃 유예)용
            'reason' => $inspVideos > 0 ? 'inspected_video' : ($stockPhotos > 0 ? 'stock_photos' : 'none'),
        ];
    }

    /** (A) type+id 직접모드. */
    public function fetch(string $type, string $id): array
    {
        if ($id === '') {
            return self::EMPTY;
        }

        return $this->request("a:{$type}:{$id}", ['type' => $type, 'id' => $id]);
    }

    /**
     * (B) vin/번호판 교차매칭 — ssancar id 없는 차(엔카 등) 폴백.
     * 둘 다 전송(ssancar OR 매칭) — 검차 entry 는 vin 이 비어 번호판으로만 잡히는 경우가 있어 양쪽 다 넘김.
     */
    public function fetchByVehicle(?string $vin, ?string $carNo): array
    {
        $vin = trim((string) $vin);
        $carNo = trim((string) $carNo);
        $query = [];
        if ($vin !== '') {
            $query['vin'] = $vin;
        }
        if ($carNo !== '') {
            $query['car_no'] = $carNo;
        }
        if (! $query) {
            return self::EMPTY;
        }

        return $this->request('b:'.md5($vin.'|'.$carNo), $query);
    }

    /** @return array{videos: list<array<string,?string>>, photos: list<string>} */
    private function request(string $cacheSuffix, array $query): array
    {
        $base = (string) config('services.ssancar_media.base_url');
        $key = (string) config('services.ssancar_media.api_key');
        if ($base === '' || $key === '') {
            return self::EMPTY;
        }

        $cacheKey = "ssancar_media:{$cacheSuffix}";
        if (($hit = Cache::get($cacheKey)) !== null) {
            return $hit;
        }

        try {
            $res = Http::withHeaders(['X-Api-Key' => $key])
                ->timeout(4)   // 공개 바이어페이지 — ssancar 지연 시 미디어 없이 빠른 렌더 우선
                ->get($base, $query);
        } catch (\Throwable) {
            return self::EMPTY;   // 일시장애는 캐시 안 함(다음 조회에서 재시도)
        }
        if ($res->failed()) {
            return self::EMPTY;
        }

        $j = $res->json();
        if (! is_array($j) || (int) ($j['ok'] ?? 0) !== 1) {
            return self::EMPTY;
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

        // sources = 타입별(stock/inspected/auction) 매칭·미디어 breakdown. 폴러 전이 판정(pollDecision)에 사용.
        $out = [
            'videos' => $videos,
            'photos' => $photos,
            'sources' => is_array($j['sources'] ?? null) ? $j['sources'] : [],
        ];
        Cache::put($cacheKey, $out, now()->addMinutes(10));

        return $out;
    }
}

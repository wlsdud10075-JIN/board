<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * 매물 자동채움(enrichment) — encar JSON API + ssancar 페이지 파싱 → 차량번호·차값·지역·VIN.
 *
 * 권위 = meetings/encar-ssancar-enrichment-design.md. 전부 board PHP(외부 스크래퍼 불필요).
 * 실패/미해당이면 [] (prefill 없음, 절대 throw 안 함).
 *
 * 반환: {vehicle_number?, region?, vin?, prices?:{KRW?,USD?,EUR?}}.
 *  - 차값은 **통화별 금액 맵 `prices`** — 영업이 통화 토글하면 해당 금액으로 바뀜.
 *  - encar = KRW 1종. ssancar 페이지 = `<p class="money">₩/$/€` 3종.
 */
class ListingEnrichment
{
    /** 엔카진단 부위코드(name) → 한글 라벨. 미매칭은 원본 name 그대로 표시. */
    private const DIAGNOSIS_LABELS = [
        'HOOD' => '후드',
        'ROOF' => '루프', 'ROOF_PANEL' => '루프',
        'TRUNK_LID' => '트렁크', 'TRUNK' => '트렁크',
        'FRONT_FENDER_LEFT' => '앞펜더(좌)', 'FRONT_FENDER_RIGHT' => '앞펜더(우)',
        'FRONT_DOOR_LEFT' => '앞문(좌)', 'FRONT_DOOR_RIGHT' => '앞문(우)',
        'BACK_DOOR_LEFT' => '뒷문(좌)', 'BACK_DOOR_RIGHT' => '뒷문(우)',
        'QUARTER_PANEL_LEFT' => '쿼터패널(좌)', 'QUARTER_PANEL_RIGHT' => '쿼터패널(우)',
        'SIDE_SILL_LEFT' => '사이드실(좌)', 'SIDE_SILL_RIGHT' => '사이드실(우)',
    ];

    /** ListingLink::parse 결과(+ 원본 URL)로 enrich. encar_id=API / ssancar=페이지 파싱. */
    public function enrich(array $parsed, string $url = ''): array
    {
        if (! empty($parsed['encar_id'])) {
            return $this->byEncarId((string) $parsed['encar_id']);
        }
        if ($url !== '' && str_contains(mb_strtolower($url), 'ssancar.com')
            && (! empty($parsed['c_no']) || ! empty($parsed['ssancar_ref']))) {
            return $this->fromSsancar($url);
        }

        return [];
    }

    /**
     * ssancar 페이지 파싱.
     *  - inspected(검차): 원본 encar 링크 → encar API 로 차량번호·지역·VIN. ⭐
     *  - stock(재고): VIN(<em id="copy_txt">) + 차량번호(번호판 패턴).
     *  - 차값 = 페이지 `<p class="money">` 의 ₩/$/€ 3종(stock·inspected 공통). 없으면 USD 텍스트 폴백.
     */
    public function fromSsancar(string $url): array
    {
        try {
            $res = Http::timeout(8)->get($url);
        } catch (\Throwable) {
            return [];
        }
        if ($res->failed()) {
            return [];
        }

        $html = $res->body();
        $out = [];

        // 차량번호·지역·VIN — 검차매물은 원본 encar 링크로 우회(차량번호·지역·VIN·KRW).
        if (preg_match('#encar\.com/cars/detail/(\d+)#i', $html, $m)) {
            $out = $this->byEncarId($m[1]);
        }
        // encar 우회가 비었으면(=검차매물 엔카 내려감/404) 또는 링크 없으면 → 페이지 자체 패턴으로 빈 필드만 폴백.
        if (empty($out['vin']) && preg_match('/id=["\']copy_txt["\'][^>]*>\s*([^<\s][^<]*?)\s*</u', $html, $m)) {
            $out['vin'] = trim($m[1]);
        }
        if (empty($out['vehicle_number']) && preg_match('/(\d{2,3}\s?[가-힣]\s?\d{4})/u', $html, $m)) {
            $out['vehicle_number'] = preg_replace('/\s+/u', '', $m[1]);
        }

        // 차값 = money 블록 3통화
        $prices = $this->parseMoneyBlock($html);
        if (! $prices && preg_match('/([\d,]+)\s*USD/', $html, $m)) {   // 폴백(USD 텍스트)
            $usd = (int) str_replace(',', '', $m[1]);
            if ($usd > 0) {
                $prices = ['USD' => $usd];
            }
        }
        if ($prices) {
            $out['prices'] = $prices;
        }

        // 차종명(title) — board 저장필드 없음, 추출 힌트용(경매는 차량번호·VIN 미노출이라 이거라도).
        if (preg_match('/<title>\s*([^<|]+?)\s*[|<]/u', $html, $m)) {
            $name = trim($m[1]);
            if ($name !== '') {
                $out['name'] = $name;
            }
        }

        return $out;
    }

    /**
     * `<p class="money">… ₩ <b>79,900,000</b> $ <b>52,473</b> € <b>45,746</b>` → {KRW,USD,EUR}.
     * 금액 태그는 페이지별로 <b>(재고)/<span>(검차) 제각각 → 기호 뒤 임의 태그 1개로 일반화.
     */
    private function parseMoneyBlock(string $html): array
    {
        if (! preg_match('/class=["\']money["\'][^>]*>(.*?)<\/p>/su', $html, $b)) {
            return [];
        }
        $block = $b[1];
        $out = [];
        foreach (['KRW' => '₩', 'USD' => '\$', 'EUR' => '€'] as $code => $sym) {
            if (preg_match('/'.$sym.'\s*<[^>]+>\s*([\d,]+)/u', $block, $m)) {
                $v = (int) str_replace(',', '', $m[1]);
                if ($v > 0) {
                    $out[$code] = $v;
                }
            }
        }

        return $out;
    }

    /** encar 공개 API → {vehicle_number, region(시), vin, prices:{KRW}}. 실패=[]. */
    public function byEncarId(string $id): array
    {
        $base = rtrim((string) config('services.encar.base_url', 'https://api.encar.com'), '/');
        try {
            $res = Http::timeout(8)->get($base.'/v1/readside/vehicle/'.$id);
        } catch (\Throwable) {
            return [];
        }
        if ($res->failed()) {
            return [];
        }

        $j = (array) $res->json();
        $price = data_get($j, 'advertisement.price');   // 만원 단위 → ×10000

        $out = array_filter([
            'vehicle_number' => data_get($j, 'vehicleNo'),
            'region' => $this->city(data_get($j, 'contact.address')),
            'vin' => data_get($j, 'vin'),
        ], fn ($v) => $v !== null && $v !== '');
        if (is_numeric($price)) {
            $out['prices'] = ['KRW' => (int) round(((float) $price) * 10000)];
        }

        return $out;
    }

    /** 주소 → 시 단위. "대구 서구 …" → 대구 / "경기 안산시 …" → 안산. */
    public function city(?string $addr): ?string
    {
        $addr = trim((string) $addr);
        if ($addr === '') {
            return null;
        }
        $parts = preg_split('/\s+/', $addr);
        $provinces = ['경기', '강원', '충북', '충남', '전북', '전남', '경북', '경남', '제주', '세종', '충청북도', '충청남도', '전라북도', '전라남도', '경상북도', '경상남도'];
        if (in_array($parts[0], $provinces, true) && isset($parts[1])) {
            return preg_replace('/(시|군|구)$/u', '', $parts[1]);   // 안산시 → 안산
        }

        return preg_replace('/(특별자치시|특별자치도|특별시|광역시|시)$/u', '', $parts[0]);   // 대구광역시 → 대구
    }

    /**
     * 엔카 차량이력 3종 on-demand 조회 — **조회 전용, board 저장 안 함**(PII 최소보유).
     *  - record     = 보험이력(카히스토리): 사고건수·보험금·소유자변경·전손/침수. `vehicleNo` 필요 → base 로 먼저 획득.
     *  - inspection = 성능점검 + 성능점검내역(엔드포인트 하나: master=요약, inners/outers=내역).
     *  - diagnosis  = 엔카진단(엔카가 진단한 차만 존재 — 미진단차는 null).
     * base 실패면 [](전체 없음). 각 항목은 실패/미존재 시 개별 null(다른 항목엔 영향 없음).
     */
    public function encarHistory(string $id): array
    {
        $base = $this->fetchJson('/v1/readside/vehicle/'.$id);
        if ($base === null) {
            return [];
        }
        $vehicleNo = (string) data_get($base, 'vehicleNo', '');

        return [
            'vehicle_number' => $vehicleNo,
            'record' => $vehicleNo !== '' ? $this->encarRecord($id, $vehicleNo) : null,
            'inspection' => $this->encarInspection($id),
            'diagnosis' => $this->encarDiagnosis($id),
        ];
    }

    /** 보험이력(카히스토리). 미공개(openData=false)/실패 = null. 엔카 원본 키 그대로 미러. */
    public function encarRecord(string $id, string $vehicleNo): ?array
    {
        $j = $this->fetchJson('/v1/readside/record/vehicle/'.$id.'/open?vehicleNo='.urlencode($vehicleNo));
        if ($j === null || ! data_get($j, 'openData')) {
            return null;
        }

        $accidents = [];
        foreach ((array) data_get($j, 'accidents', []) as $a) {
            $accidents[] = [
                'date' => data_get($a, 'date'),
                'insuranceBenefit' => (int) data_get($a, 'insuranceBenefit', 0),
                'partCost' => (int) data_get($a, 'partCost', 0),
                'laborCost' => (int) data_get($a, 'laborCost', 0),
                'paintingCost' => (int) data_get($a, 'paintingCost', 0),
            ];
        }

        return [
            'title' => trim(implode(' ', array_filter([data_get($j, 'year'), data_get($j, 'maker'), data_get($j, 'model')]))),
            'myAccidentCnt' => (int) data_get($j, 'myAccidentCnt', 0),
            'myAccidentCost' => (int) data_get($j, 'myAccidentCost', 0),
            'otherAccidentCnt' => (int) data_get($j, 'otherAccidentCnt', 0),
            'otherAccidentCost' => (int) data_get($j, 'otherAccidentCost', 0),
            'ownerChangeCnt' => (int) data_get($j, 'ownerChangeCnt', 0),
            'totalLossCnt' => (int) data_get($j, 'totalLossCnt', 0),
            'floodCnt' => (int) data_get($j, 'floodTotalLossCnt', 0) + (int) data_get($j, 'floodPartLossCnt', 0),
            'robberCnt' => (int) data_get($j, 'robberCnt', 0),
            'specialUse' => (int) data_get($j, 'government', 0) + (int) data_get($j, 'business', 0),
            'accidents' => $accidents,
        ];
    }

    /** 성능점검(+내역). master 없으면(비-표준 포맷/실패) null. `accdient` 는 엔카 원본 오타 키. */
    public function encarInspection(string $id): ?array
    {
        $j = $this->fetchJson('/v1/readside/inspection/vehicle/'.$id);
        if ($j === null || ! data_get($j, 'master')) {
            return null;
        }

        // 자기진단·내부: 미점검(status null)은 생략, 정상은 초록·이상만 눈에 띄게(엔카식).
        $goodStates = ['양호', '적정', '없음'];   // '없음' = 누유/누수 없음 = 정상
        $inners = [];
        foreach ((array) data_get($j, 'inners', []) as $sec) {
            $children = [];
            foreach ((array) data_get($sec, 'children', []) as $c) {
                $status = data_get($c, 'statusType.title');
                if ($status === null || $status === '') {
                    continue;   // 미점검 — 표시 안 함
                }
                $children[] = [
                    'title' => data_get($c, 'type.title'),
                    'status' => $status,
                    'ok' => in_array($status, $goodStates, true),   // false = 실제 이상(불량·누수·부족 등)
                ];
            }
            if ($children) {
                $inners[] = ['title' => data_get($sec, 'type.title'), 'children' => $children];
            }
        }

        $outers = [];
        foreach ((array) data_get($j, 'outers', []) as $o) {
            $titles = [];
            $codes = [];
            foreach ((array) data_get($o, 'statusTypes', []) as $s) {
                $titles[] = data_get($s, 'title');
                $codes[] = (string) data_get($s, 'code');
            }
            $outers[] = [
                'title' => data_get($o, 'type.title'),
                'status' => implode(', ', array_filter($titles)),
                'color' => $this->inspectionColor($codes),   // 엔카 상태부호 심각도 색
            ];
        }

        return [
            'mileage' => (int) data_get($j, 'master.detail.mileage', 0),
            'accident' => (bool) data_get($j, 'master.accdient', false),
            'simpleRepair' => (bool) data_get($j, 'master.simpleRepair', false),
            'waterlog' => (bool) data_get($j, 'master.detail.waterlog', false),
            'recall' => (bool) data_get($j, 'master.detail.recall', false),
            'recallStatus' => data_get($j, 'master.detail.recallFullFillTypes.0.title'),
            'transmission' => data_get($j, 'master.detail.transmissionType.title'),
            'inspName' => data_get($j, 'master.detail.inspName'),
            'comments' => data_get($j, 'master.detail.comments'),
            'inners' => $inners,
            'outers' => $outers,
        ];
    }

    /**
     * 엔카진단. 미진단차(items 없음)/실패 = null.
     *  - *_COMMENT 항목(CHECKER_COMMENT=무사고 판정 등)은 부위가 아니라 **판정문구** → verdicts 로 분리(첫 줄만, 표준 고지문 제외).
     *  - 부위 항목은 **정상(NORMAL) 숨기고 이상만** items 에(해당없는 부위 노출 안 함).
     */
    public function encarDiagnosis(string $id): ?array
    {
        $j = $this->fetchJson('/v1/readside/diagnosis/vehicle/'.$id);
        $items = data_get($j, 'items');
        if ($j === null || ! is_array($items) || $items === []) {
            return null;
        }

        $verdicts = [];
        $panels = [];
        foreach ($items as $it) {
            $name = (string) data_get($it, 'name');
            $result = trim((string) data_get($it, 'result'));
            if (str_ends_with($name, 'COMMENT')) {
                $line = trim(explode("\n", $result)[0]);   // 첫 줄 = 판정, 뒤 표준 고지문은 버림
                if ($line !== '') {
                    $verdicts[] = $line;
                }

                continue;
            }
            if (data_get($it, 'resultCode') === 'NORMAL') {
                continue;   // 정상 부위 숨김 — 이상만
            }
            $panels[] = [
                'name' => self::DIAGNOSIS_LABELS[$name] ?? $name,
                'result' => $result,
            ];
        }

        return [
            'date' => substr((string) data_get($j, 'diagnosisDate'), 0, 10),
            'center' => data_get($j, 'reservationCenterName'),
            'verdicts' => $verdicts,   // 무사고 판정 등 헤드라인
            'items' => $panels,        // 이상 부위만
        ];
    }

    /**
     * 엔카 외판·골격 상태부호(X교환·W판금용접·C부식·A흠집·U요철·T손상) → 뱃지 색(Jin 지정).
     * 한 부위에 상태 여러 개면 심각도 우선순위(교환>손상>부식>판금용접>요철>흠집)로 대표색 1개.
     */
    private const OUTER_COLORS = [
        'X' => 'red',    // 교환 = 빨강
        'T' => 'brown',  // 손상 = 갈색
        'C' => 'amber',  // 부식 = 노랑
        'W' => 'blue',   // 판금/용접 = 파랑
        'U' => 'khaki',  // 요철 = 카키
        'A' => 'gray',   // 흠집 = 회색
    ];

    private function inspectionColor(array $codes): string
    {
        foreach (self::OUTER_COLORS as $code => $color) {   // 배열 순서 = 심각도 우선순위
            if (in_array($code, $codes, true)) {
                return $color;
            }
        }

        return 'gray';   // 미지의 코드 = 회색
    }

    /** 엔카 API GET → 배열 or null(예외·실패·비-JSON). byEncarId 와 동일 정책, 이력 메서드 공용. */
    private function fetchJson(string $path): ?array
    {
        $base = rtrim((string) config('services.encar.base_url', 'https://api.encar.com'), '/');
        try {
            $res = Http::timeout(8)->get($base.$path);
        } catch (\Throwable) {
            return null;
        }
        if ($res->failed()) {
            return null;
        }
        $j = $res->json();

        return is_array($j) ? $j : null;
    }
}

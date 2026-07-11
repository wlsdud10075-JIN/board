<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * 영업 포털 — car-erp 읽기 API(HMAC GET) + 선적요청 client.
 *
 * 권위 계약 = car-erp `docs/integration/board-portal-api.md`. board 는 표시만(재무로직 재현 금지=drift).
 * 안전밸브: base_url/read_hmac_secret 미설정이면 모든 호출 no-op → 화면은 "조회 불가" 표시(0/완납 금지).
 *
 * 반환 = degrade 봉투: ['ok'=>bool, 'status'=>int, 'data'=>?array, 'reason'=>?string].
 *   ok=false(not_configured/http_error/exception) → 화면 "조회 불가". ok=true 라도 개별 필드 null 은
 *   그대로 보존(예: 미수금 KRW null = "환율 미입력", 절대 0/완납으로 coerce 금지).
 */
class CarErpReadService
{
    /** 계약 prefix(canonical PATH 에 그대로 들어감). */
    private const PREFIX = '/api/internal/board';

    /**
     * 서류 화이트리스트 — board 측에서도 강제(car-erp 403 에만 의존 X). 말소서류 등 = PII.
     * 선적 4종 + 판매계약서(sales_contract, 수출/바이어측·다중차량·동일바이어 필수, 2026-07-01 car-erp 추가).
     * ⚠️ car-erp 의 board 화이트리스트(InternalDocumentController::BOARD_ALLOWED_TYPES)에도 있어야 실제 200.
     */
    public const ALLOWED_DOC_TYPES = [
        'roro_invoice_packing', 'roro_contract', 'container_invoice_packing', 'container_contract',
        'sales_contract',
    ];

    private ?string $base;

    private ?string $secret;

    public function __construct()
    {
        $this->base = config('services.car_erp.base_url');
        $this->secret = config('services.car_erp.read_hmac_secret');
    }

    public function configured(): bool
    {
        return ! empty($this->base) && ! empty($this->secret);
    }

    // ── 공개 읽기 메서드 (전부 salesman_email 스코프, 쿼리=서명 포함) ──

    public function finance(string $email): array
    {
        return $this->get('/finance', ['salesman_email' => $email]);
    }

    public function receivables(string $email): array
    {
        return $this->get('/receivables', ['salesman_email' => $email]);
    }

    public function purchases(string $email): array
    {
        return $this->get('/purchases', ['salesman_email' => $email]);
    }

    public function sales(string $email): array
    {
        return $this->get('/sales', ['salesman_email' => $email]);
    }

    public function settlements(string $email): array
    {
        return $this->get('/settlements', ['salesman_email' => $email]);
    }

    public function shippable(string $email): array
    {
        return $this->get('/shippable', ['salesman_email' => $email]);
    }

    /** 바이어별 집계 — {buyer_id, buyer, vehicle_count, sales_by_currency{통화:합}, payout_total_krw, payout_paid_krw}. payout 내림차순. */
    public function byBuyer(string $email): array
    {
        return $this->get('/by-buyer', ['salesman_email' => $email]);
    }

    /**
     * 환율 — car-erp 전신환 매입률(네이버 "송금 받으실 때") 원본 그대로. ⚠️반올림 안 됨(board와 어긋남 방지).
     * 스코프 없음(전역값). data = {rates:{USD,JPY,EUR,GBP,CNY}, fetched_at, source}. JPY는 100엔 기준.
     * 권위 = car-erp board-portal-api.md §4-1.
     */
    public function rates(): array
    {
        return $this->get('/rates', []);
    }

    /** v3 — 바이어 드롭다운(경매/구매). 본인 스코프(car-erp 결정: IDOR 격리). {count,data:[{id,name,country}]}. */
    public function buyers(string $email): array
    {
        return $this->get('/buyers', ['salesman_email' => $email]);
    }

    /** v3 — 선택 바이어 하위 컨사이니 드롭다운. {count,data:[{id,name}]}. */
    public function consignees(string $email, int $buyerId): array
    {
        return $this->get('/consignees', ['salesman_email' => $email, 'buyer_id' => $buyerId]);
    }

    /**
     * ③ 선적요청 (v1 단발) — DEPRECATED: v2 syncShippingRequests 로 교체 예정.
     * board 미가동이라 병존 없이 제거 대상(UI rework 시 삭제).
     */
    public function shippingRequest(string $email, array $payload): array
    {
        $payload['salesman_email'] = $email;

        return $this->post('/shipping-request', ['salesman_email' => $email], $payload);
    }

    // ── §5 v2 선적·B/L 묶음 (영속 묶음 + 선언형 sync + B/L요청 + 변경요청) ──

    /**
     * GET /bundles — 영업 본인 묶음 전체(전 상태, 안 사라짐) + 묶음별 재무집계.
     * 묶음: batch_id·buyer·consignee·shipping_method·bl_type·status·bl_status·vehicles[]
     *       + unpaid_total_krw·fx_missing_count·fully_paid·unpaid_ratio·sales_by_currency·change_requested.
     * ⚠️ 값 그대로 표시 — 0/완납 coerce·재계산 금지(§5-4).
     */
    public function bundles(string $email): array
    {
        return $this->get('/bundles', ['salesman_email' => $email]);
    }

    /**
     * POST /shipping-requests/sync — 선언형 재동기화. body = 영업의 "지금 원하는 묶음 전체(desired)".
     * ⚠️ 반드시 전체 묶음 전송 — 일부만 보내면 빠진 requested 차가 자동취소됨(§5-2).
     * 응답 {created,updated,cancelled,skipped,locked}.
     *
     * @param  list<array{buyer_id:int,consignee_id:?int,shipping_method:string,bl_type:?string,vehicle_ids:list<int>}>  $bundles
     */
    public function syncShippingRequests(string $email, array $bundles): array
    {
        return $this->post('/shipping-requests/sync', ['salesman_email' => $email], [
            'salesman_email' => $email,
            'bundles' => $bundles,
        ]);
    }

    /**
     * POST /bundles/{batch}/bl-request — 기존 묶음의 B/L요청(같은 묶음 상태전이). bl_type=original|surrender.
     * → bl_type 확정 + bl_status='requested' + 관리 알람.
     */
    public function blRequest(string $email, string $batchId, string $blType): array
    {
        return $this->post('/bundles/'.$batchId.'/bl-request', ['salesman_email' => $email], [
            'salesman_email' => $email,
            'bl_type' => $blType,
        ]);
    }

    /**
     * POST /bundles/{batch}/bl-request — 기존 묶음의 B/L요청 무름(오발송 취소). bl_status requested→none.
     * 이미 issued 면 car-erp 409 {ok:false, reason:"already_issued"} → 봉투 status=409 로 board 가 분기.
     */
    public function blCancel(string $email, string $batchId): array
    {
        return $this->post('/bundles/'.$batchId.'/bl-cancel', ['salesman_email' => $email], [
            'salesman_email' => $email,
        ]);
    }

    /**
     * POST /shipping-requests/change-request — in_progress(관리 착수) 차의 명시적 변경/취소 요청.
     * 자동적용 안 함 — 관리가 화면에서 수락/거절(§5-2). omission 으로 취소 추론 금지.
     */
    public function changeRequest(string $email, int $vehicleId, string $note): array
    {
        return $this->post('/shipping-requests/change-request', ['salesman_email' => $email], [
            'salesman_email' => $email,
            'vehicle_id' => $vehicleId,
            'note' => $note,
        ]);
    }

    /**
     * §10 판매계약서 전자서명 세션 발급 (POST). car-erp 가 서명 URL 을 반환 → board 는 그대로 바이어에게 전달만.
     * body = {salesman_email, vehicle_ids, recipient_email?}. recipient_email 미전송 시 car-erp 가 바이어 contact_email 기본.
     * 응답 data = {signed_url, contract_no, buyer{id,name}, currency, vehicle_count, status, expires_at}.
     * 미설정/401/422/5xx → ok=false degrade("발급 불가"). ⚠️ 409 없음(재발급은 항상 성공 — 겹치는 pending revoke 후 새 세션).
     * vehicle_ids = 한 계약 묶음 전체(all-or-nothing) — 전부 동일 바이어·단일 통화·export 아니면 car-erp 422.
     * 권위 = car-erp board-portal-api.md §10-1.
     *
     * @param  list<int>  $vehicleIds
     */
    public function requestSigningSession(string $email, array $vehicleIds, ?string $recipientEmail = null): array
    {
        $payload = [
            'salesman_email' => $email,
            'vehicle_ids' => array_values(array_map('intval', $vehicleIds)),
        ];
        if ($recipientEmail !== null && $recipientEmail !== '') {
            $payload['recipient_email'] = $recipientEmail;
        }

        return $this->post('/signing-requests', ['salesman_email' => $email], $payload);
    }

    /**
     * ①② 서류 프록시 — xlsx 바이트 스트림. 4종 화이트리스트 board 측 강제.
     *
     * @return array{ok:bool, status:int, body:?string, content_type:?string, reason:?string}
     */
    public function document(string $type, array $ids, string $email): array
    {
        if (! in_array($type, self::ALLOWED_DOC_TYPES, true)) {
            return ['ok' => false, 'status' => 0, 'body' => null, 'content_type' => null, 'reason' => 'type_not_allowed'];
        }

        $query = ['ids' => implode(',', $ids), 'salesman_email' => $email];
        $path = self::PREFIX.'/documents/'.$type;

        if (! $this->configured()) {
            return ['ok' => false, 'status' => 0, 'body' => null, 'content_type' => null, 'reason' => 'not_configured'];
        }

        [$headers] = $this->sign('GET', $path, $query, '');

        try {
            // acceptJson: 오류(403/422 등) 시 car-erp 가 웹 리다이렉트 대신 JSON 상태코드를 반환하게(성공 xlsx 스트림은 무영향).
            $res = Http::timeout(60)->withHeaders($headers)->acceptJson()->get($this->base.$path, $query);
        } catch (\Throwable) {
            return ['ok' => false, 'status' => 0, 'body' => null, 'content_type' => null, 'reason' => 'exception'];
        }

        if ($res->failed()) {
            return ['ok' => false, 'status' => $res->status(), 'body' => null, 'content_type' => null, 'reason' => 'http_error'];
        }

        return ['ok' => true, 'status' => $res->status(), 'body' => $res->body(), 'content_type' => $res->header('Content-Type'), 'reason' => null];
    }

    // ── 내부 ──

    private function get(string $endpoint, array $query): array
    {
        return $this->send('GET', self::PREFIX.$endpoint, $query, null);
    }

    private function post(string $endpoint, array $query, array $payload): array
    {
        return $this->send('POST', self::PREFIX.$endpoint, $query, $payload);
    }

    private function send(string $method, string $path, array $query, ?array $payload): array
    {
        if (! $this->configured()) {
            return ['ok' => false, 'status' => 0, 'data' => null, 'reason' => 'not_configured'];
        }

        $body = $payload === null ? '' : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        [$headers] = $this->sign($method, $path, $query, $body);

        try {
            // acceptJson: 검증/오류 시 car-erp 가 웹 302 리다이렉트 대신 JSON(422 등)을 반환하게.
            // (Accept 없으면 ValidationException 이 back()302 → 클라가 GET 으로 따라가 HTML 200 → ok=true/데이터 null 로 조용히 삼켜짐)
            $req = Http::timeout(20)->withHeaders($headers)->acceptJson();
            $res = $method === 'GET'
                ? $req->get($this->base.$path, $query)
                : $req->withBody($body, 'application/json')->post($this->base.$path.'?'.http_build_query($query));
        } catch (\Throwable) {
            return ['ok' => false, 'status' => 0, 'data' => null, 'reason' => 'exception'];
        }

        if ($res->failed()) {
            return ['ok' => false, 'status' => $res->status(), 'data' => null, 'reason' => 'http_error'];
        }

        return ['ok' => true, 'status' => $res->status(), 'data' => $res->json(), 'reason' => null];
    }

    /**
     * 서명 헤더 생성. canonical = METHOD\nPATH?SORTED_QUERY\nX-Timestamp\nBODY (계약 §1, 바이트 일치).
     *
     * @return array{0:array<string,string>, 1:string} [헤더, canonical] — canonical 은 테스트 핀고정용.
     */
    public function sign(string $method, string $path, array $query, string $body): array
    {
        $ts = (string) time();
        $nonce = (string) Str::uuid();
        $canonical = $this->canonical($method, $path, $query, $ts, $body);
        $sig = hash_hmac('sha256', $canonical, (string) $this->secret);

        return [[
            'X-Board-Signature' => 'sha256='.$sig,
            'X-Timestamp' => $ts,
            'X-Nonce' => $nonce,
        ], $canonical];
    }

    /**
     * canonical 문자열 — car-erp `VerifyBoardReadHmac` 구현과 바이트 일치.
     * ksort 후 **http_build_query**(urlencode) — 스펙 §1 텍스트의 "k=v&"는 모호, 실검증은 http_build_query.
     */
    public function canonical(string $method, string $path, array $query, string $timestamp, string $body): string
    {
        ksort($query);

        return $method."\n".$path.'?'.http_build_query($query)."\n".$timestamp."\n".$body;
    }
}

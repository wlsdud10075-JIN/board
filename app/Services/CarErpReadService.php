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
    /**
     * §11 신호 type — car-erp 와의 **계약 문자열**이라 여기 한 곳에만 둔다(오타 하나면 422).
     *
     * 매입은 계약금·잔금이 **별개 type** 이다. 하위구분(subtype)으로는 안 된다 —
     * ERP 멱등키가 `(vehicle_id, type)` 이고 구 `purchase_payment` 는 "매입 미지급 0" 이면 소멸이라,
     * 계약금을 지급해도 잔금이 남아 안 닫히고 → 잔금 요청이 `already_open` 으로 **조용히 버려진다**.
     * 경위·ERP 요청사항 = `meetings/handoff-carerp-payment-request-split.md`.
     */
    public const REQ_PURCHASE_DEPOSIT = 'purchase_deposit';

    public const REQ_PURCHASE_BALANCE = 'purchase_balance';

    public const REQ_SALE_CONFIRM = 'sale_payment_confirm';

    /** 구 단일 입금요청 — 신규 생성은 안 하지만 **기존 open 행의 칩은 계속 그려야 한다**(요청 이력이 사라지면 재요청을 부른다). */
    public const REQ_PURCHASE_LEGACY = 'purchase_payment';

    /** 금액을 싣는 type(= 매입 2종). 판매대금확인은 금액 없음. */
    public const PURCHASE_REQUEST_TYPES = [self::REQ_PURCHASE_DEPOSIT, self::REQ_PURCHASE_BALANCE];

    /** 계약 prefix(canonical PATH 에 그대로 들어감). */
    private const PREFIX = '/api/internal/board';

    /**
     * 서류 화이트리스트 — board 측에서도 강제(car-erp 403 에만 의존 X). 말소서류 등 = PII.
     * 선적 4종 + 판매계약서(sales_contract) + 프로포마 인보이스(**타입명이 `invoice`** — `proforma_invoice` 아님).
     *
     * ⚠️ car-erp 의 board 화이트리스트(InternalDocumentController::BOARD_ALLOWED_TYPES)에도 있어야 실제 200.
     *    2026-07-31 car-erp 가 sales_contract·invoice 를 개방(master `4d3959e`, 3사 배포). 그 전까지 이 목록에
     *    sales_contract 가 있어도 car-erp 는 403 이었다 — **여기 있다고 되는 게 아니라 양쪽에 다 있어야 한다.**
     * ⚠️ sales_contract·invoice 는 car-erp HOMOGENEOUS_TYPES = 1바이어·단일통화만 함께 발급(혼합이면 422).
     *    매핑이 바이어블록·환율을 primary 로만 채워서, 막지 않으면 조용히 틀린 서류가 나간다.
     */
    public const ALLOWED_DOC_TYPES = [
        'roro_invoice_packing', 'roro_contract', 'container_invoice_packing', 'container_contract',
        'sales_contract', 'invoice',
    ];

    /**
     * §12 운항 축 — car-erp `Vehicle::SAILING_PHASES` 와 **같은 값**. 판정은 ERP `scopeSailing` 단일출처다.
     * board 가 선적일·ETA 로 자체 판정하면 "ERP 엔 운항중인데 board 엔 아님"이 생긴다(§12-5 흡수 금지).
     */
    public const SAILING_PHASES = ['in_transit', 'arrived'];

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

    /**
     * 재고 4분류 — 포털 「매입내역」(무필터·무페이징 전량조회) 대체. car-erp `erp/inventory` 미러.
     *
     * ⚠️ **`awaiting_payment` 이 [입금요청] 대상이다.** `inStock()` 이 출고일뿐 아니라 **매입 완납까지**
     *    보기 때문에(`purchaseUnpaidRawExpr() <= 0`), 미지급이 남은 차는 재고 3분류 어디에도 없다.
     *    즉 재고만 미러하면 입금요청 버튼을 달 곳이 사라진다(2026-08-09 car-erp 지적, 실측 확인).
     *
     * `shipped_out` 만 영원히 누적되므로 limit/offset 으로 끊어 받는다. 나머지 3분류는 유한(영업당 20~50대).
     * 검색은 ERP 로 넘긴다 — 최근 N건만 받아놓고 board 에서 거르면 옛날 차를 영영 못 찾는다.
     */
    public function inventory(string $email, string $category, string $search = '', ?int $limit = null, int $offset = 0): array
    {
        $query = ['salesman_email' => $email, 'category' => $category];
        if ($search !== '') {
            $query['search'] = $search;
        }
        if ($limit !== null) {
            $query['limit'] = $limit;
            $query['offset'] = $offset;
        }

        return $this->get('/inventory', $query);
    }

    /**
     * §12 운항 상태 — 필터를 받는 건 **`/sales` 뿐이다.** `/inventory` 는 필드만 실어 보내고
     * `sailing` 쿼리는 안 읽는다(car-erp `InternalPortalController::inventory` 실측 2026-08-09).
     * 거기에 파라미터를 얹으면 서버가 조용히 무시해 "운항중만 보기인데 전부 보이는" 화면이 된다.
     *
     * @param  list<string>  $excludeStatus  제외할 진행상태(예: ['거래완료']). **서버에서** whereNotIn 으로 거른다
     *                                       — 받아놓고 화면에서 감추면 트래픽이 그대로라 의미가 없다.
     * @param  string  $sailing  ''|in_transit|arrived. 진행상태와 **직교**하는 축이라 excludeStatus 와 동시에 걸린다.
     *                           ⚠️ 영문 키만 — 쿼리는 HMAC canonical 대상이라 한글 라벨을 실으면 인코딩 차이로 서명이 깨진다.
     */
    public function sales(string $email, array $excludeStatus = [], string $sailing = ''): array
    {
        $query = ['salesman_email' => $email];
        if ($excludeStatus !== []) {
            $query['exclude_status'] = implode(',', $excludeStatus);
        }
        if (in_array($sailing, self::SAILING_PHASES, true)) {
            $query['sailing'] = $sailing;
        }

        return $this->get('/sales', $query);
    }

    public function settlements(string $email): array
    {
        return $this->get('/settlements', ['salesman_email' => $email]);
    }

    /**
     * GET /shippable — 새로 묶을 차 후보. 2026-08-12 확대: **미완납 차도 온다**(`sale_price>0` + 반입지·B/L 없음).
     *
     * ⚠️ **출고일(`warehouse_out_date`)이 찍힌 차도 후보에 있다** — 출고일과 반입지는 독립된 축이라
     *    한쪽만 찍힌 차가 흔하다(heymanerp 실측). "출고 전만 온다" 전제로 화면을 짜면 어긋난다.
     * ⚠️ 행의 `unpaid_krw` 는 **null 이 올 수 있다** = 환율 미입력이라 완납 판정 불가.
     *    **0 으로 바꿔 그리지 말 것**(가짜 완납). 그 경우 `fully_paid` 도 false 다.
     */
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

    /**
     * v3 — 바이어 드롭다운(경매/구매). 본인 스코프(car-erp 결정: IDOR 격리).
     * `{count, data:[{id, name, country, purchase_locked, purchase_lock{locked,mode,basis{kind,current,limit},reference{…}}}]}`
     *
     * **매입 등록 락(§4-0, 2026-08-10)** — car-erp 의 매입 락 4겹은 전부 차량관리 화면 `save()` 안이라
     * 연동 B(`purchase-sync`)는 **어느 락도 안 거친다**. 수신 시점 거부도 안 된다(board 는 이미 낙찰=지출 후에
     * 보내므로, 거부하면 회사 소유 차가 ERP 에 없는 상태가 될 뿐). **막을 수 있는 유일한 지점 = 바이어를 고르는 상류.**
     *
     * 🚫 판정 조건을 board 에 옮겨 적지 말 것 — `purchase_locked` 를 **그대로 신뢰**한다.
     *    갈리면 영업은 board 에서 "가능"을 보고 돈을 쓴 뒤 ERP 에서 막힌다.
     * 🚫 `basis` 와 `reference` 를 나란히 렌더하지 말 것 — ratio 모드에서 분모·분자가 달라
     *    "여력 0원인데 등록 가능"·"락인데 여력 1천만"이 **둘 다 정상**이다. 근거는 `basis` 하나뿐.
     */
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
     * GET /forwarding-companies — 포워딩사 명부(활성만, `{id,name}`). **스코프 없음 = HMAC 만**.
     * 담당자·연락처는 안 온다. 실패하면 board 는 드롭다운을 통째로 숨긴다(degrade — 있는 척 금지).
     */
    public function forwardingCompanies(): array
    {
        return $this->get('/forwarding-companies', []);
    }

    /**
     * POST /shipping-requests/sync — 선언형 재동기화. body = 영업의 "지금 원하는 묶음 전체(desired)".
     * ⚠️ 반드시 전체 묶음 전송 — 일부만 보내면 빠진 requested 차가 자동취소됨(§5-2).
     * 응답 {created,updated,cancelled,skipped,locked}.
     *
     * 2026-08-12 추가:
     *  - `forwarding_company_id` — 활성 명부에 없으면 **422**(부분 적용 없음). 드롭다운 값이라도 서버가 재검증한다.
     *    ERP 는 **값이 실제로 바뀌었을 때만** 차량에 반영한다 → "보냈는데 차량 값 그대로"가 **정상**이다
     *    (관리가 ERP 에서 고친 걸 board 재전송이 되돌리지 않는다).
     *  - `transport_fee_usd_total` — **CONTAINER 에서만** 받는다. RORO 로 보내면 서버가 조용히 버린다(에러 아님).
     *    ERP 가 1/N 로 쪼개 `vehicles.transport_fee_usd` 에 넣되 **이미 값이 있는 차는 건너뛴다**
     *    ⇒ 합계가 총액과 안 맞을 수 있다(의도된 결과). 🚫 화면에서 "총액이 그대로 기록된다"고 안내하지 말 것.
     *
     * @param  list<array{buyer_id:int,consignee_id:?int,shipping_method:string,bl_type:?string,vehicle_ids:list<int>,forwarding_company_id?:?int,transport_fee_usd_total?:?int}>  $bundles
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
     * §11 요청·확인 신호 — 카톡으로 하던 "해주세요" 두 마디를 옮긴 것.
     *   purchase_deposit      = "이 차 계약금 N원 보내주세요"   (차량 1대 단위)
     *   purchase_balance      = "이 차 잔금 N원 보내주세요"     (차량 1대 단위)
     *   sale_payment_confirm  = "이 바이어 차 N대 확인해주세요" (바이어 1 + 차량 N = 한 묶음, buyer_id 필수)
     *
     * 💰 **금액은 매입 2종에만 싣는다**(2026-08-11 Jin — §11-2 개정). 받는 사람이 얼마를 보낼지 알아야 한다.
     *    ERP 는 이 값을 **표시 전용**으로만 보관한다 — 🚫 회계 컬럼(final_payments·purchase_balance_payments)
     *    반영은 여전히 금지(§11-5 흡수금지 유효). 자동기입은 은행 API 연동 이후의 일이다.
     *    판매대금확인(sale_payment_confirm)에는 **금액을 싣지 않는다**(Jin 확정 — 분리는 입금요청만).
     *
     * 응답 201 = {batch_id, created[], skipped[]}.
     *  ⚠️ `batch_id` 는 **sale_payment_confirm 에서만** 채워진다(매입 2종은 null — 차량마다 별개 묶음).
     *  ⚠️ `skipped[]` 는 항목마다 키가 다르다 — forbidden 은 `vehicle_id`, already_open 은 `vehicle_number`.
     *    (둘 다 §11-3 문서와 어긋난 실제 구현. 권위는 구현 — car-erp `BoardRequestController::store`.)
     *
     * @param  list<int>  $vehicleIds
     */
    public function sendBoardRequest(string $email, string $type, array $vehicleIds, ?int $buyerId = null, ?string $note = null, ?int $amountKrw = null): array
    {
        $payload = [
            'salesman_email' => $email,
            'type' => $type,
            'vehicle_ids' => array_values($vehicleIds),
        ];
        if ($buyerId !== null) {
            $payload['buyer_id'] = $buyerId;
        }
        if ($note !== null && $note !== '') {
            $payload['note'] = $note;
        }
        // 금액은 매입 2종 전용 — 판매대금확인에 실리면 ERP 가 버린다(그리고 보낼 이유도 없다).
        if ($amountKrw !== null && in_array($type, self::PURCHASE_REQUEST_TYPES, true)) {
            $payload['amount_krw'] = $amountKrw;
        }

        return $this->post('/requests', ['salesman_email' => $email], $payload);
    }

    /**
     * GET /requests — 상태 폴링(칩 갱신). 응답 = {count, requests[{batch_id,type,status,buyer_name,requested_at,vehicles[]}]}.
     * 묶음 status = open | partial | done | cancelled (ERP 집계값). **board 가 재계산·완료 coerce 금지**(§11-4 항목 4).
     */
    public function boardRequests(string $email, string $status = 'open'): array
    {
        return $this->get('/requests', ['salesman_email' => $email, 'status' => $status]);
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
     * §10-2 서명 상태 조회 (GET) — 그 묶음 차량 set 의 현 세션 상태(signed 우선, revoked 제외). 본인 차만(403).
     * vehicle_ids = 콤마구분(document() 의 ids 패턴 동일 — http_build_query 로 urlencode 서명, 검증됨).
     * data = {status: none|pending|viewed|signed, contract_no?, vehicle_count?, sent_at?, viewed_at?, signed_at?}.
     * PII·서명본 파일 미포함(상태 메타만). 미설정/401/403/5xx → ok=false degrade(칩 미표시). 권위 = §10-2.
     *
     * @param  list<int>  $vehicleIds
     */
    public function signingStatus(string $email, array $vehicleIds): array
    {
        return $this->get('/signing-requests', [
            'salesman_email' => $email,
            'vehicle_ids' => implode(',', array_values(array_map('intval', $vehicleIds))),
        ]);
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
            // ⚠️ 실패 본문을 버리면 안 된다 — car-erp 는 422 에 **왜 안 되는지 + 어느 차량인지**를 담아 준다
            //    (`No buyer: 12가3456` / `No sale price: …` / `Mixed buyers` / `Mixed currencies`).
            //    예전엔 null 로 지워서 board 가 전부 "동일 바이어" 로 뭉뚱그려 안내했다(원인을 잘못 짚게 만듦).
            return [
                'ok' => false, 'status' => $res->status(), 'body' => null, 'content_type' => null,
                'reason' => 'http_error', 'message' => Str::limit((string) $res->body(), 300, ''),
            ];
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
            // 실패 본문을 살린다 — car-erp 422 는 **사유 + 어느 차량인지**를 담아 준다
            //   (`No buyer: 12가3456` · `No sale price: …` · `Mixed buyers` · `buyer_mismatch` …).
            //   버리면 화면이 전부 "동일 바이어" 로 뭉뚱그려져 영업이 엉뚱한 곳을 고친다.
            return [
                'ok' => false, 'status' => $res->status(), 'data' => null,
                'reason' => 'http_error', 'message' => Str::limit((string) $res->body(), 300, ''),
            ];
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

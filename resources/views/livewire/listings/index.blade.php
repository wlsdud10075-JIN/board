<?php

use App\Models\BoardAuditLog;
use App\Models\InspectionPhoto;
use App\Models\PromotionRequest;
use App\Jobs\ExtractPassportFromImage;
use App\Jobs\SyncWonListingToCarErp;
use App\Services\CarErpReadService;
use App\Services\CommercialInvoiceBuilder;
use App\Models\PurchaseListing;
use App\Services\ExchangeRateService;
use App\Support\TimeGate;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithFileUploads, WithPagination;

    // ─────── 목록 탭 + 페이지네이션 (2026-09-11 Jin) ───────
    // 예전엔 `latest()->get()` 으로 **전량**을 읽었다. synced(ERP 전환완료)는 영원히 쌓이기만 하는 통이라
    // 그대로 두면 랜딩이 매년 느려진다.
    // 기본 = **「전체」 탭 + 30건**(2026-09-11 Jin) — 목록의 성격은 그대로 두고(전과 같은 화면),
    // 느려지는 원인이었던 **전량 로드만** 페이지네이션으로 끊는다. 좁혀 보고 싶으면 탭을 누른다.

    /** 목록 탭 — `PurchaseListing::TAB_STATUSES` 의 키(또는 'all'). */
    #[Url]
    public string $tab = 'all';

    /** 페이지당 건수. 0 = 「건수만」(행을 안 그린다 — car-erp 차량관리와 같은 규칙). */
    #[Url]
    public int $perPage = 30;

    public const PER_PAGE_COUNT_ONLY = 0;

    public const PER_PAGE_OPTIONS = [self::PER_PAGE_COUNT_ONLY, 10, 20, 30, 50, 100];

    /**
     * 등록자(영업) 필터 — **관리·super 만**(Jin 2026-10-13 「인원별로도」). 0 = 전체.
     * 영업은 SalesmanScope 가 본인 것만 주므로 이 값이 와도 무의미(URL 로 들어와도 무시).
     * 목록·탭 건수가 **같은 조건**(tabQuery)을 보게 해 숫자가 어긋나지 않는다.
     */
    #[Url]
    public int $owner = 0;

    public function updatedOwner(): void
    {
        if ($this->owner !== 0 && ! $this->owners->contains('id', $this->owner)) {
            $this->owner = 0;
        }
        unset($this->listings, $this->tabCounts);
        $this->resetPage();
    }

    /** 필터 후보 = 매입예정을 등록한 적 있는 사용자(이름순). 관리·super 가 아니면 빈 목록. */
    #[Computed]
    public function owners()
    {
        if (! Auth::user()->canSeeAll()) {
            return collect();
        }

        return \App\Models\User::whereIn('id', PurchaseListing::query()->select('created_by_user_id')->distinct())
            ->orderBy('name')->get(['id', 'name']);
    }

    /** 실제로 적용할 등록자 id — 권한 없음·목록 밖이면 null(필터 없음). */
    private function ownerFilter(): ?int
    {
        if ($this->owner === 0 || ! Auth::user()->canSeeAll() || ! $this->owners->contains('id', $this->owner)) {
            return null;
        }

        return $this->owner;
    }

    public function setTab(string $tab): void
    {
        $this->tab = ($tab === 'all' || array_key_exists($tab, PurchaseListing::TAB_STATUSES)) ? $tab : 'all';
        unset($this->listings);
        $this->resetPage();
    }

    /** ⚠️ `#[Url]` 이라 `?perPage=` 로 아무 값이나 들어온다 — 화이트리스트 밖이면 되돌린다. */
    public function updatedPerPage(): void
    {
        if (! in_array($this->perPage, self::PER_PAGE_OPTIONS, true)) {
            $this->perPage = 30;
        }
        unset($this->listings);
        $this->resetPage();
    }

    public bool $showAdd = false;

    // 차량 첨부 (영업 업로드 → 연동 B 로 car-erp 첨부탭). 첨부파일 1칸 — 이미지=사진/그 외=서류 자동분류.
    public array $salesFiles = [];         // 추가 폼
    public array $eSalesFiles = [];        // 편집 드로어

    public string $origin = '';             // 유입 카테고리(화면) — source 는 여기서 도출. 기본 선택 없음(링크 추출이 정하거나 사람이 고름, 2026-10-21 Jin)
    public string $source = 'encar';        // 매입방법(내부) — 워크플로·연동B
    public string $vehicle_number = '';
    public string $owner_name = '';         // 소유자/차주명 (연동 B: car-erp NICE 조회 입력값)
    public string $vin = '';
    public string $region = '';             // 지역 (검사지역, 자동완성)
    public string $c_no = '';               // 매물번호 (ssancar c_no, 조인키)
    public string $ssancar_ref = '';        // ssancar wr_id/car_no (비-c_no, "wr_id:786")
    public string $encar_id = '';           // Encar 차 식별 (URL 자동추출)
    public string $respond_contact_id = ''; // respond.io 대화 연결(스파인, 영업이 채팅에서 복사)
    public string $link = '';               // 매물 링크 1칸 — 사이트 판별은 ListingLink::parse 가 주소로 한다(칸이 사이트를 정하지 않음)
    public ?int $promotingId = null;        // 승격 대기에서 시작한 경우 — 저장 시 consume
    public ?string $expected_price = null;  // 매물 표시가 (enrichment 자동채움 · 표시용, KRW 값은 car_cost 에도 자동 매핑)
    public string $expected_price_currency = 'KRW';   // 매물 표시가 통화 (원/미/유로 토글)
    public array $priceOptions = [];        // enrichment 통화별 금액 {KRW,USD,EUR} — 토글 시 금액 변경
    public array $encarHistory = [];        // 엔카 차량이력(보험/성능점검/진단) 조회결과 — 조회전용, 저장 안 함
    public bool $showHistory = false;       // 이력 패널 표시
    public ?string $car_cost = null;        // 차값 (KRW)
    public ?string $discount_rate = null;   // 할인율 (%)
    public ?int $shipping_usd = null;       // 배송금액 (USD 고정 택1)
    public string $encar_url = '';
    public string $encar_dealer = '';
    public string $auction_venue = '';
    public string $lot_number = '';
    public string $inspection_request = '';  // 검차 요청사항 — 영업 → 검차원(2026-10-21 Jin). inspection_note(검차 결과 메모)와 별개
    // 입금정보 (선택 — 영업이 미리 알면 입력, 모르면 구매단계에서) §6e
    public string $payee_name = '';
    public string $payee_bank = '';
    public string $payee_account = '';
    // 매도비 계좌 (선택 — 판매자와 다른 대상, 영업 직접입력) — 매입가 계좌와 별개
    public string $selling_fee_payee_name = '';
    public string $selling_fee_payee_bank = '';
    public string $selling_fee_payee_account = '';

    // ── 편집 (본인 글 수정) ──
    public ?int $editingId = null;
    public string $e_region = '';
    public string $e_c_no = '';
    public string $e_respond_contact_id = '';   // 연동 A: 대화 연결 (나중에 붙일 수 있게 수정 가능)
    public string $e_owner_name = '';
    public string $e_payee_name = '';
    public string $e_payee_bank = '';
    public string $e_payee_account = '';
    public string $e_selling_fee_payee_name = '';
    public string $e_selling_fee_payee_bank = '';
    public string $e_selling_fee_payee_account = '';
    public ?string $e_car_cost = null;
    public ?string $e_discount_rate = null;
    public ?int $e_shipping_usd = null;
    public string $e_encar_url = '';
    public string $e_encar_dealer = '';
    public string $e_auction_venue = '';
    public string $e_lot_number = '';
    public string $e_inspection_request = '';
    // ── 드로어 탭(2026-10-29 ②) — 기본·검차·첨부·Invoice. 비활성 탭도 DOM 에 두고 hidden 으로만 가린다.
    public string $drawerTab = 'basic';
    // ── Invoice 탭 — 송금인(여권/ID) Name·문서번호. 전 상태 편집(update() 의 editable() 락 밖).
    public string $e_remitter_name = '';
    public string $e_remitter_id = '';
    public ?string $invoiceNote = null;
    /** 여권/ID 사진 1장(Livewire 임시 업로드) — OCR 로 넘긴 직후 지운다. */
    public $passportFile = null;

    // ── 환율 (§6a 라이브) ──
    public int $krwPerUsd = 0;
    public int $krwPerEur = 0;
    public string $krwPerUsdDisplay = '';   // 표시용 2자리(car-erp 일치)
    public string $krwPerEurDisplay = '';
    public ?string $rateFetchedAt = null;
    public bool $rateLive = false;

    public function mount(ExchangeRateService $rates): void
    {
        $rates->refreshIfStale();   // 오래됐을 때만 갱신(lazy, cron 불필요)
        $this->loadRates($rates);
    }

    private function loadRates(ExchangeRateService $rates): void
    {
        $snap = $rates->snapshot();
        $this->krwPerUsd = $snap['USD'];
        $this->krwPerEur = $snap['EUR'];
        $this->krwPerUsdDisplay = $snap['USD_display'];
        $this->krwPerEurDisplay = $snap['EUR_display'];
        $this->rateFetchedAt = $snap['fetched_at'];
        $this->rateLive = $snap['is_live'];
    }

    public function refreshRate(ExchangeRateService $rates): void
    {
        $rates->refresh();
        $this->loadRates($rates);
        session()->flash('ok', __('listings.rate.refreshed'));
    }

    /** 배송 USD→KRW 환산에 쓸 환율 (라이브 우선, 없으면 config 폴백). */
    private function usdRate(): int
    {
        return $this->krwPerUsd ?: (int) config('board.default_krw_per_usd');
    }

    private function eurRate(): int
    {
        return $this->krwPerEur ?: (int) config('board.default_krw_per_eur');
    }

    // ── 표시통화 토글 (KRW/USD/EUR) ──
    public string $displayCurrency = 'KRW';

    /** KRW 금액을 표시통화로 변환+포맷. 차량(KRW)·배송(USD×환율=KRW)·합계 모두 KRW 로 정규화 후 변환. */
    public function fmt(?int $krw): string
    {
        if ($krw === null) {
            return '—';
        }

        return match ($this->displayCurrency) {
            'USD' => '$'.number_format($krw / max(1, $this->usdRate()), 2),
            'EUR' => '€'.number_format($krw / max(1, $this->eurRate()), 2),
            default => number_format($krw).__('common.won_currency'),
        };
    }

    /**
     * 차량금액(KRW) = 차값(통화 KRW환산) − (×할인율%). $cur=차값 통화(엔카=KRW).
     *
     * 🚨 예전엔 여기서 **고정 매도비 440,000 을 더해** 보여줬는데, 저장되는 `final_price`(모델 `totalKrw()`)
     *    에는 매도비가 없다 — **화면 숫자와 원장 숫자가 매도비만큼 달랐다**(2026-09-11 발견).
     *    Model A 기준으로 판매가는 매도비 제외(회사 부담)가 맞으므로 **화면을 원장에 맞춘다**.
     */
    public function calcCarPrice($cost, $rate, string $cur = 'KRW'): ?int
    {
        $krw = \App\Support\Money::toKrw($cost, $cur, $this->usdRate(), $this->eurRate());
        if ($krw === null) {
            return null;
        }
        $discount = (int) round($krw * ((float) $rate / 100));

        return $krw - $discount;
    }

    /** 최종금액(KRW) = 차량금액 + 배송(USD→KRW, 임시환율). */
    public function calcTotal($cost, $rate, $usd, string $cur = 'KRW'): ?int
    {
        $car = $this->calcCarPrice($cost, $rate, $cur);
        if ($car === null) {
            return null;
        }
        $shipKrw = $usd ? (int) $usd * $this->usdRate() : 0;

        return $car + $shipKrw;
    }

    /** 현재 탭의 상태 필터를 건 쿼리(정렬 전). 카운트와 목록이 **같은 조건**을 보게 하는 단일 지점. */
    private function tabQuery()
    {
        $statuses = PurchaseListing::TAB_STATUSES[$this->tab] ?? null;
        $owner = $this->ownerFilter();

        return PurchaseListing::query()
            ->when($statuses !== null, fn ($q) => $q->whereIn('status', $statuses))
            ->when($owner !== null, fn ($q) => $q->where('created_by_user_id', $owner));
    }

    #[Computed]
    public function listings()
    {
        // ⚠️ `#[Url]` 둘 다 — `?tab=nope&perPage=7` 로 바로 들어오면 `setTab`·`updated*` 훅을 안 거친다.
        //    탭이 모르는 값이면 조건 없이 전량이 뜨는데 **칩은 아무것도 안 켜져** 왜 그런지 알 수가 없다.
        if ($this->tab !== 'all' && ! array_key_exists($this->tab, PurchaseListing::TAB_STATUSES)) {
            $this->tab = 'all';
        }
        if (! in_array($this->perPage, self::PER_PAGE_OPTIONS, true)) {
            $this->perPage = 30;
        }

        // 「건수만」 — 행을 안 불러오면 creator eager load 와 행마다 도는 금액 계산이 통째로 빠진다.
        //   빈 paginator 를 돌려주므로 total()·links() 를 쓰는 뷰가 그대로 동작한다(car-erp 와 같은 수법).
        if ($this->perPage === self::PER_PAGE_COUNT_ONLY) {
            return new \Illuminate\Pagination\LengthAwarePaginator(
                [], $this->tabQuery()->count(), 10, 1, ['path' => request()->url()]
            );
        }

        return $this->tabQuery()->with('creator')->latest()->paginate($this->perPage);
    }

    /**
     * 탭별 건수 — **쿼리 1번**(상태별 group by)으로 낸다. 탭마다 COUNT 를 돌리지 말 것.
     * SalesmanScope 가 그대로 걸리므로 영업은 본인 것만 세어진다(= 목록과 같은 모수).
     */
    #[Computed]
    public function tabCounts(): array
    {
        $owner = $this->ownerFilter();
        $byStatus = PurchaseListing::query()
            ->when($owner !== null, fn ($q) => $q->where('created_by_user_id', $owner))   // 목록과 같은 모수
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $out = ['all' => (int) $byStatus->sum()];
        foreach (PurchaseListing::TAB_STATUSES as $tab => $statuses) {
            $out[$tab] = (int) collect($statuses)->sum(fn ($st) => (int) ($byStatus[$st] ?? 0));
        }

        return $out;
    }

    #[Computed]
    public function editing(): ?PurchaseListing
    {
        return $this->editingId ? PurchaseListing::find($this->editingId) : null;
    }

    /** 승격 대기 — 담당 영업(respond.io assignee)에게만. 관리/super 는 전체(미배정 포함=관리자 풀). */
    #[Computed]
    public function promotions()
    {
        $me = Auth::user();
        $q = PromotionRequest::where('status', PromotionRequest::PENDING);
        if (! $me->canSeeAll()) {
            $q->where('assigned_email', $me->respondAgentEmail());
        }

        return $q->latest()->get();
    }

    /** 승격 대기 조회 — 본인 담당만(관리/super 전체). 가시성=조작권한 일치(IDOR 방지). */
    private function visiblePromotion(int $id): PromotionRequest
    {
        $me = Auth::user();
        $q = PromotionRequest::where('status', PromotionRequest::PENDING)->where('id', $id);
        if (! $me->canSeeAll()) {
            $q->where('assigned_email', $me->respondAgentEmail());
        }

        return $q->firstOrFail();
    }

    /** 승격 대기건에서 시작 — 컨택트 자동주입 + 추가 폼 열기. 영업은 링크+차번호만 입력. */
    public function promoteFrom(int $id): void
    {
        $req = $this->visiblePromotion($id);
        $this->resetForm();
        $this->respond_contact_id = $req->respond_contact_id;
        $this->promotingId = $req->id;
        $this->showAdd = true;
        session()->flash('ok', __('listings.promo.promoted_flash', ['label' => $req->label]));
    }

    /** 승격 대기 무시 — 전환 안 되는 바이어(구경만) 정리. 본인 담당만(관리/super 전체). */
    public function dismissPromotion(int $id): void
    {
        $req = $this->visiblePromotion($id);
        $req->update(['status' => PromotionRequest::DISMISSED, 'handled_by_user_id' => Auth::id()]);
        unset($this->promotions);
    }

    /** 경매가 시간잠금됐으면 영업은 수정 불가 (관리자는 우회). 엔카·잠금 전은 가능. */
    public function editable(PurchaseListing $l): bool
    {
        return ! ($l->isAuction() && $l->isLocked()) || Auth::user()->isManager();
    }

    /**
     * 본인이 등록한 매입예정을 지울 수 있는 조건.
     * 남의 글은 못 지운다 — 전체 대상 삭제는 /manage 의 관리 이상 경로.
     * ERP 로 넘어간 차(car_erp_vehicle_id)와 won/synced(구매확정 이후)는 제외 — ERP 전송분은 되돌릴 수 없다.
     * accepted(구매대기)는 지울 수 있다(2026-10-22 Jin) — 셀프검차매입은 등록 즉시 accepted 라 종전엔 버튼이 아예 안 보였다.
     * editable() 을 그대로 태워 경매 시간잠금 차는 삭제도 막는다(잠기면 읽기전용 = 삭제 포함).
     */
    public function deletable(PurchaseListing $l): bool
    {
        return $l->created_by_user_id === Auth::id()
            && $l->car_erp_vehicle_id === null
            && ! in_array($l->status, ['won', 'synced'], true)
            && $this->editable($l);
    }

    /**
     * 매입예정 삭제 — 영업 본인 건(SalesmanScope 가 남의 글 로드를 막는다). soft delete(복구 가능) + 감사기록.
     * 용도 = 검차 사진/영상이 안 올라와 현지확인대기에 갇힌 차, 거절로 끝난 차의 재등록(중복차단은 활성 행만 본다).
     */
    public function deleteListing(): void
    {
        $l = PurchaseListing::findOrFail($this->editingId);
        abort_unless($this->deletable($l), 403);

        // soft delete 는 update 이벤트를 안 거쳐 옵저버 감사가 안 잡힘 → 여기서 직접 기록(/manage 와 동일).
        BoardAuditLog::create([
            'user_id' => Auth::id(),
            'purchase_listing_id' => $l->id,
            'action' => 'delete',
            'field' => 'deleted',
            'old_value' => $l->statusLabel(),
            'new_value' => null,
        ]);

        $vehicle = $l->vehicle_number;
        $l->delete();

        unset($this->listings, $this->tabCounts);
        session()->flash('ok', __('listings.drawer.deleted_flash', ['number' => $vehicle]));
        $this->closeEdit();
    }

    /**
     * 「사진만으로 전달대기로 넘기기」 조건 (Jin 2026-10-13) — 검차 영상이 안 올라와도 바이어와 바로 얘기하고 싶을 때.
     * 본인 차(관리·super 는 전체) + 현지확인대기(draft) + 시간잠금 아님 + **보여줄 사진·영상이 하나라도 있을 때**.
     * 미디어 0 이면 빈 바이어 페이지를 보내게 되므로 버튼 대신 안내만.
     */
    public function forwardable(PurchaseListing $l): bool
    {
        $u = Auth::user();

        return $l->status === 'draft'
            && ($l->created_by_user_id === $u->id || $u->isManager() || $u->hasFullAccess())
            && $this->editable($l)
            && $l->hasAnyForwardMedia();
    }

    /**
     * 「재검차 요청」(검차 큐 연동, 2026-10-23 Jin (다)) — ssancar 검차원이 패스(보류)한 차를 다시 큐에 넣는다.
     * 조건 = 사진만으로 넘기기와 같은 사람·상태(본인 또는 관리 이상 · draft · 시간잠금 아님) + 보류 상태.
     * board 의 패스 칸을 비우고 `queue.upsert`(reason=reinspect)를 보낸다. ssancar 쪽 「되돌리기」(→ reopened 웹훅)와 같은 결과.
     */
    public function reinspectable(PurchaseListing $l): bool
    {
        $u = Auth::user();

        return $l->status === 'draft'
            && $l->inspection_skipped_at !== null
            && ($l->created_by_user_id === $u->id || $u->isManager() || $u->hasFullAccess())
            && $this->editable($l);
    }

    public function requestReinspection(): void
    {
        $l = PurchaseListing::findOrFail($this->editingId);
        abort_unless($this->reinspectable($l), 403);

        // 다시 대기 — 지난 검차 시작·검차원도 비운다(안 비우면 「검차중 · 검차원」이 남는다, 2026-10-23 데모 D6).
        $l->forceFill(['inspection_skipped_at' => null, 'inspection_skip_reason' => null,
            'inspection_skip_memo' => null, 'inspection_skip_photo_url' => null,
            'inspection_started_at' => null, 'ssancar_inspector_name' => null])->save();
        \App\Jobs\SendInspectionQueueEvent::dispatch($l->id, 'upsert', 'reinspect')->afterCommit();

        unset($this->listings, $this->tabCounts);
        session()->flash('ok', __('listings.drawer.reinspect_flash', ['number' => \App\Support\Plate::show($l->vehicle_number)]));
        $this->closeEdit();
    }

    /**
     * draft → inspected(전달대기) 수동 전이. 현지확인 「검차완료」와 같은 전이(TRANSITIONS 허용)라 가드를 그대로 탄다.
     * 감사로그는 모델 옵저버가 status_change 로 남긴다(user_id = 누른 영업). 전달대기 알림톡(B)은 자동 전이와 같은 조건으로 발사.
     * 넘긴 차는 현지확인 목록과 폴러 대상에서 빠진다 — 전달 화면이 ssancar 미디어를 실시간으로 다시 가져오므로
     * 나중에 올라온 영상은 바이어 링크 재열람에 그대로 들어간다.
     */
    public function forwardWithPhotos(): void
    {
        $l = PurchaseListing::findOrFail($this->editingId);
        abort_unless($this->forwardable($l), 403);

        $l->status = 'inspected';
        $l->save();

        $creator = $l->creator;
        if ($creator && trim((string) $creator->phone) !== '') {
            app(\App\Services\BizmAlimtalkService::class)->send('board_forward_ready', $creator->phone,
                ['차량번호' => $l->vehicle_number ?: '(번호미정)'], ['user_id' => $creator->id]);
        }

        unset($this->listings, $this->tabCounts);
        session()->flash('ok', __('listings.drawer.forward_photos_flash', ['number' => \App\Support\Plate::show($l->vehicle_number)]));
        $this->closeEdit();
    }

    public function openEdit(int $id): void
    {
        $l = PurchaseListing::findOrFail($id);   // SalesmanScope: 영업은 본인 것만 로드 가능
        $this->editingId = $l->id;
        $this->e_region = $l->region ?? '';
        $this->e_c_no = $l->c_no ?? '';
        $this->e_respond_contact_id = $l->respond_contact_id ?? '';
        $this->e_owner_name = $l->owner_name ?? '';
        $this->e_payee_name = $l->payee_name ?? '';
        $this->e_payee_bank = $l->payee_bank ?? '';
        $this->e_payee_account = $l->payee_account ?? '';
        $this->e_selling_fee_payee_name = $l->selling_fee_payee_name ?? '';
        $this->e_selling_fee_payee_bank = $l->selling_fee_payee_bank ?? '';
        $this->e_selling_fee_payee_account = $l->selling_fee_payee_account ?? '';
        $this->e_car_cost = $l->car_cost !== null ? (string) $l->car_cost : null;
        $this->e_discount_rate = $l->discount_rate !== null ? (string) $l->discount_rate : null;
        $this->e_shipping_usd = $l->shipping_usd;
        $this->e_encar_url = $l->encar_url ?? '';
        $this->e_encar_dealer = $l->encar_dealer ?? '';
        $this->e_auction_venue = $l->auction_venue ?? '';
        $this->e_lot_number = $l->lot_number ?? '';
        $this->e_inspection_request = $l->inspection_request ?? '';
        $this->drawerTab = 'basic';
        $this->e_remitter_name = $l->remitter_name ?? '';
        $this->e_remitter_id = $l->remitter_id ?? '';
        $this->invoiceNote = null;
        // 금액 칸 프리필 — /auction openDetail 과 같은 규칙(총판매가 = 판매가 + 운임비, 환율은 저장값 ?: 견적통화면 offer_rate).
        $this->e_sale_currency = $l->offer_currency ?: null;
        $this->e_sale_total = $l->sale_price !== null ? (string) (0 + round((float) $l->sale_price + (float) $l->transport_fee, 2)) : null;
        $this->e_transport_fee_usd = $l->transport_fee_usd !== null ? (string) (0 + $l->transport_fee_usd) : null;
        $this->e_rate_usd = $l->offer_rate_usd !== null ? (string) $l->offer_rate_usd : ($l->offer_currency === 'USD' && $l->offer_rate ? (string) $l->offer_rate : null);
        $this->e_rate_eur = $l->offer_rate_eur !== null ? (string) $l->offer_rate_eur : ($l->offer_currency === 'EUR' && $l->offer_rate ? (string) $l->offer_rate : null);
        $this->resyncResult = null;
        $this->attachResult = null;   // 앞 차의 결과 카드가 다음 차 드로어에 남으면 안 된다
        $this->syncSince = null;
        $this->reset(['eSalesFiles']);
        $this->resetErrorBag();
    }

    // ─────── 판매 금액 보완 + ERP 재전송 (2026-08-18 Jin) ───────
    // 급해서 차량정보·매입가만 넣고 보낸 차는 ERP 판매탭이 빈 채로 생긴다.
    // 나중에 여기서 판매가·통화·환율을 채워 다시 밀면 car-erp 가 **빈 칸만** 채운다(fill-if-empty).
    // 🚫 이미 값이 있으면 ERP 가 덮지 않는다 — 고치려면 ERP 에서. board 가 원장을 되돌리지 않는다.

    // 2026-10-29 Jin — /auction 구매 탭과 **같은 칸·같은 공식**: 사람이 적는 건 총판매가·운임비 USD·환율 USD/EUR·통화, 판매가·운임비(견적통화)는 계산(resendSplit).
    //    종전(판매가·환율·운임비 직접 입력)은 구매 탭과 칸이 달라 같은 차를 두 화면에서 다르게 적게 됐다. payload 키는 그대로(sale_price·transport_fee·transport_fee_usd·usd_exchange_rate).
    public ?string $e_sale_total = null;

    public ?string $e_sale_currency = null;

    public ?string $e_transport_fee_usd = null;

    public ?string $e_rate_usd = null;

    public ?string $e_rate_eur = null;

    /** 마지막 재전송 결과 — car-erp 응답의 fields_filled/fields_skipped. 200 만 보고 "반영됨"이라 하지 않는다. */
    public ?array $resyncResult = null;

    /** 마지막 [사진 추가] 결과 — 보낸 장수 + car-erp 가 실제로 붙인 장수(cap 에 잘렸는지 이걸로 안다). */
    public ?array $attachResult = null;

    /** 직전 전송의 기준 이벤트 id — 비동기 큐(운영)에서 응답이 도착했는지 폴링으로 다시 볼 때 쓴다. */
    public ?int $syncSince = null;

    /**
     * ERP 로 다시 보내기 — 판매 3종을 저장한 뒤 resync 발사.
     * ⚠️ 환율이 없으면 car-erp 가 판매가를 **통째로 보류**한다 → 세트로 받고, 빠지면 여기서 막는다.
     */
    public function resendToErp(): void
    {
        $l = PurchaseListing::findOrFail($this->editingId);
        $this->resyncResult = null;
        $this->resetErrorBag();   // 고쳐서 다시 눌렀는데 옛 에러가 남아 있으면 뭘 고쳤는지 알 수 없다

        if (! $l->car_erp_vehicle_id) {
            $this->addError('e_sale_total', __('listings.resync.not_synced_yet'));

            return;
        }
        // 재고매입은 이 경로를 아예 안 탄다 — Job 이 판매측을 강제로 비우므로 보내봐야 **조용한 no-op** 이고
        // (200 인데 fields_filled 가 빈 채로 돌아온다), board 컬럼에만 판매가가 남아 화면과 원장이 갈린다.
        // 판매가·바이어는 ERP 차량관리에서 지정한다 — 거기엔 매입 등록 락 게이트가 있고 여기엔 없다.
        if ($l->buyer_undecided) {
            $this->addError('e_sale_total', __('listings.resync.stock_purchase'));

            return;
        }

        if ($this->applyResendAmounts($l) === null) {
            return;   // 검증 에러는 applyResendAmounts 가 붙였다
        }
        if ($l->isDirty()) {
            $l->save();   // 옵저버가 감사기록
        }

        $this->syncSince = $this->lastSyncEventId($l->id);
        SyncWonListingToCarErp::dispatch($l->id, resync: true);
        $this->resyncResult = $this->latestSyncFields($l->id, $this->syncSince);
    }

    /**
     * 쪼개기 계산 — /auction `selfSplit()` 과 같은 공식(2026-10-22 Jin: 운임비 USD·총판매가 고정, 환율 차이는 판매가가 흡수).
     *   운임비(견적통화) = 운임비 USD(USD 견적이면 그대로, 아니면 × 환율 USD ÷ 견적통화 환율) · 판매가 = 총판매가 − 운임비 · 환율 = 견적통화의 환율(KRW=1).
     * 화면 표시(읽기 전용 두 칸)와 적용(applyResendAmounts)이 이 하나를 쓴다.
     *
     * @return array{sale: ?float, freight: ?float, rate: ?int, total: ?float, fusd: ?int, usd: ?int, eur: ?int, currency: ?string}
     */
    public function resendSplit(): array
    {
        $num = fn ($v) => ($v === null || $v === '') ? null : (float) preg_replace('/[^0-9.]/', '', (string) $v);
        $int = fn ($v) => ($v === null || $v === '') ? null : (int) preg_replace('/[^0-9]/', '', (string) $v);
        $total = $num($this->e_sale_total);
        $fUsd = $int($this->e_transport_fee_usd);
        $usd = $int($this->e_rate_usd);
        $eur = $int($this->e_rate_eur);
        $currency = $this->e_sale_currency ?: null;
        $rate = match ($currency) {
            'KRW' => 1,
            'USD' => $usd ?: null,
            'EUR' => $eur ?: null,
            default => null,
        };
        $freight = null;
        if ($fUsd !== null) {
            if ($currency === 'USD') {
                $freight = round($fUsd, 2);
            } elseif ($usd && $rate) {
                $freight = round($fUsd * $usd / $rate, 2);
            }
        }

        return [
            'sale' => $total === null ? null : round($total - ($freight ?? 0), 2),
            'freight' => $freight, 'rate' => $rate, 'total' => $total, 'fusd' => $fUsd, 'usd' => $usd, 'eur' => $eur, 'currency' => $currency,
        ];
    }

    /**
     * 「금액 다시 보내기」 칸(총판매가·통화·운임비 USD·환율 USD/EUR)을 모델에 적용한다 — 버튼과 「저장」이 **같은 규칙**을 쓴다(2026-10-16 Jin).
     * 2026-10-29 Jin — /auction 구매 탭과 같은 칸·같은 계산(resendSplit). 저장하지 않는다(호출자가 save).
     * 반환 = 바뀐 컬럼 목록(빈 배열 = 보낼 금액 변경 없음) / null = 검증 실패(에러 붙임).
     * ⚠️ 환율이 없으면 car-erp 가 판매가를 **통째로 보류**한다 → 총판매가가 있으면 통화·(KRW 외)그 통화 환율을 세트로 요구한다.
     *    운임비 USD 를 비-USD 견적으로 환산하려면 환율 USD 와 견적통화 환율이 둘 다 있어야 한다.
     */
    private function applyResendAmounts(PurchaseListing $l): ?array
    {
        $s = $this->resendSplit();
        if ($s['total'] === null && $s['fusd'] === null && $s['usd'] === null && $s['eur'] === null) {
            return [];   // 금액 칸을 아무것도 안 적었다
        }
        if ($s['total'] !== null && ! $s['currency']) {
            $this->addError('e_sale_currency', __('listings.resync.currency_required'));

            return null;
        }
        if ($s['currency'] && $s['currency'] !== 'KRW' && ! $s['rate']) {
            $this->addError($s['currency'] === 'USD' ? 'e_rate_usd' : 'e_rate_eur', __('listings.resync.rate_required'));

            return null;
        }
        if ($s['fusd'] !== null && $s['freight'] === null) {
            // 운임비 USD 는 적었는데 환산할 환율이 없다(통화 미선택 또는 환율 USD 없음)
            $this->addError($s['currency'] ? 'e_rate_usd' : 'e_sale_currency', __($s['currency'] ? 'listings.resync.usd_rate_required_for_freight' : 'listings.resync.currency_required'));

            return null;
        }
        if ($s['sale'] !== null && $s['sale'] < 0) {
            $this->addError('e_sale_total', __('listings.resync.freight_over_total'));

            return null;
        }

        if ($s['total'] !== null) {
            $l->sale_price = $s['sale'];
            $l->offer_currency = $s['currency'];
            $l->offer_rate = $s['rate'];
        }
        if ($s['freight'] !== null) {
            $l->transport_fee = $s['freight'];
        }
        if ($s['fusd'] !== null) {
            $l->transport_fee_usd = $s['fusd'];
        }
        if ($s['usd'] !== null) {
            $l->offer_rate_usd = $s['usd'];
        }
        if ($s['eur'] !== null) {
            $l->offer_rate_eur = $s['eur'];
        }

        return array_values(array_intersect(array_keys($l->getDirty()), ['sale_price', 'offer_currency', 'offer_rate', 'transport_fee', 'transport_fee_usd', 'offer_rate_usd', 'offer_rate_eur']));
    }

    /**
     * ERP 로 넘어간 차에 **사진·서류를 나중에 추가**한다 (2026-09-11 Jin).
     *
     * 딜러가 사진을 늦게 주면 board 에는 올릴 데가 없었다 — 업로드 화면은 `/auction` 하나뿐인데
     * 그 화면은 synced 를 안 다룬다. 여기(전 상태를 여는 유일한 화면)에서 올리고 ERP 로 민다.
     *
     * 🚨 **첨부 전용 재전송**(`attachmentsOnly`)이다 — 평범한 `resync` 는 판매 금액도 같이 보내서
     *    ERP 빈 판매가가 채워지고 `sale_date=now()` 가 찍힌다(→ 「판매중」 + 채권 독촉 기산점이 오늘).
     *    "사진 추가" 버튼이 차 상태를 바꾸면 안 된다.
     * ℹ️ 재고매입(바이어 미정) 차도 **막지 않는다** — Job 이 어차피 판매측을 비우므로 첨부만 간다.
     */
    public function addAttachments(): void
    {
        $l = PurchaseListing::findOrFail($this->editingId);   // SalesmanScope: 영업은 본인 것만
        $this->attachResult = null;
        $this->resetErrorBag();

        // 아직 ERP 에 없는 차는 여기서 보내지 않는다 — 수신측이 신규 생성 경로를 타 버린다(Job 도 막는다).
        if (! $l->car_erp_vehicle_id) {
            $this->addError('eSalesFiles', __('listings.attach_add.not_synced_yet'));

            return;
        }

        $files = array_values(array_filter($this->eSalesFiles));
        if (empty($files)) {
            $this->addError('eSalesFiles', __('listings.attach_add.none_selected'));

            return;
        }

        $this->validate(['eSalesFiles.*' => 'file|max:204800']);
        if (! $this->checkSalesFiles($files, $l->salesAttachments()->count(), 'eSalesFiles')) {
            return;
        }

        // ⚠️ 저장이 **먼저**, 전송이 나중 (§14-13 재발방지) — 순서가 바뀌면 방금 올린 파일이 payload 에서 빠진다.
        $stored = $this->storeSalesFiles($l, $files);
        $this->reset(['eSalesFiles']);

        if ($stored['stored'] === 0) {
            // 전부 같은 이름이라 건너뜀 — 보낼 게 없다(ERP 는 경로 기준이라 다시 보내면 중복이 붙는다).
            $this->addError('eSalesFiles', __('listings.attach.dup_skipped', ['count' => count($stored['skipped']), 'names' => implode(', ', $stored['skipped'])]));

            return;
        }

        $this->syncSince = $this->lastSyncEventId($l->id);
        SyncWonListingToCarErp::dispatch($l->id, resync: true, attachmentsOnly: true);

        $this->attachResult = $this->latestSyncFields($l->id, $this->syncSince) + ['sent' => $stored['stored'], 'skipped' => $stored['skipped']];
        unset($this->editing);   // 첨부 목록 새로고침
    }

    /**
     * 비동기 큐(운영)의 응답이 도착했는지 다시 본다 — **pending 카드가 떠 있는 동안만** 폴링한다.
     * 이게 없으면 화면이 "결과는 곧"이라고 해 놓고 영영 안 바뀐다(드로어를 다시 열면 결과가 초기화된다).
     */
    public function refreshSyncResult(): void
    {
        if ($this->editingId === null || $this->syncSince === null) {
            return;
        }
        if ($this->attachResult !== null && ($this->attachResult['pending'] ?? false)) {
            $sent = (int) ($this->attachResult['sent'] ?? 0);
            $this->attachResult = $this->latestSyncFields($this->editingId, $this->syncSince) + ['sent' => $sent];
        }
        if ($this->resyncResult !== null && ($this->resyncResult['pending'] ?? false)) {
            $this->resyncResult = $this->latestSyncFields($this->editingId, $this->syncSince);
        }
    }

    /** 전송 **직전**의 마지막 응답 id — 이 값보다 커야 "이번 전송의 결과"다(운영 큐는 비동기라 늦게 온다). */
    private function lastSyncEventId(int $listingId): int
    {
        return (int) \App\Models\IntegrationEvent::where('purchase_listing_id', $listingId)
            ->where('event_type', 'purchase_sync')->max('id');
    }

    /**
     * 직전 purchase_sync 응답에서 무엇이 채워졌는지 읽는다.
     * 🚨 car-erp 는 **200 이어도 아무것도 안 채웠을 수 있다**(이미 값 있음/환율 없음) — `fields_filled` 가
     *    비면 반영 안 된 것이다. 첨부가 조용히 실패하던 것과 같은 부류라 화면에 그대로 편다.
     */
    private function latestSyncFields(int $listingId, int $since = 0): array
    {
        $ev = \App\Models\IntegrationEvent::where('purchase_listing_id', $listingId)
            ->where('event_type', 'purchase_sync')->latest('id')->first();

        // 운영 큐는 비동기(database)라 버튼을 누른 직후엔 아직 응답이 없다. 그때 **직전 전송 결과**를
        // 이번 것처럼 보여주면 조용한 오표시가 된다 → "보냈고, 결과는 곧" 이라고 말한다.
        if ($ev === null || (int) $ev->id <= $since) {
            return ['pending' => true, 'status' => null, 'filled' => [], 'overwritten' => [], 'skipped' => [], 'added' => null, 'failed' => null];
        }

        $body = json_decode((string) ($ev->response_body ?? ''), true);

        return [
            'pending' => false,
            'status' => $ev->response_status ?? null,
            'filled' => (array) ($body['fields_filled'] ?? []),
            'overwritten' => (array) ($body['fields_overwritten'] ?? []),   // 2026-10-29 덮어쓰기 — 값이 있었는데 바뀐 칸(ERP 가 돌려준다)
            'skipped' => (array) ($body['fields_skipped'] ?? []),
            // 🚨 **cap 초과는 실패로 안 잡힌다** — 수신측은 10건에 닿으면 조용히 `break` 한다(`failed` 증가 없음).
            //    그래서 "보낸 장수 vs 붙은 장수"를 비교해야 잘린 걸 알 수 있다.
            'added' => isset($body['attachments_added']) ? (int) $body['attachments_added'] : null,
            'failed' => isset($body['attachments_failed']) ? (int) $body['attachments_failed'] : null,
        ];
    }

    /** ERP 호출용 영업 이메일 — 연동 B 와 같은 규칙(등록자 기준: 매핑 오버라이드 ?: 로그인 이메일). 관리·super 가 열어도 그 영업의 차로 묻는다. */
    private function erpEmailFor(PurchaseListing $l): string
    {
        return $l->creator?->car_erp_salesman_email ?: ($l->creator?->email ?? '');
    }

    /**
     * 📷 여권/ID 사진 → OCR Job. 이미지는 로컬 `local` 디스크(`passport-ocr/{id}/…`)에 잠깐 두고 Job 이 읽은 뒤 지운다 — S3·첨부에 올리지 않는다.
     * Livewire 임시 파일(운영은 S3 일 수 있다)도 **복사 직후 즉시 삭제**(자동 정리는 24시간 뒤).
     */
    public function extractPassport(): void
    {
        $l = PurchaseListing::findOrFail($this->editingId);
        if (! config('board.payee_extract.enabled')) {
            try {
                $this->passportFile?->delete();   // 꺼진 박스에서도 여권 사진을 임시 디스크에 남기지 않는다
            } catch (\Throwable) {
            }
            $this->passportFile = null;
            $this->addError('passportFile', __('listings.invoice.ocr_disabled'));

            return;
        }
        $this->validate(['passportFile' => 'required|image|max:20480']);
        $ext = strtolower($this->passportFile->getClientOriginalExtension() ?: 'jpg');
        $rel = 'passport-ocr/'.$l->id.'/'.\Illuminate\Support\Str::uuid().'.'.$ext;
        Storage::disk(ExtractPassportFromImage::DISK)->put($rel, $this->passportFile->get());
        try {
            $this->passportFile->delete();
        } catch (\Throwable) {
            // 임시 파일 삭제 실패는 치명이 아니다(Livewire 가 24시간 뒤 지운다)
        }
        $this->passportFile = null;
        $l->forceFill(['passport_ocr_status' => 'pending', 'passport_ocr_at' => now(), 'passport_suggestions' => null])->saveQuietly();
        ExtractPassportFromImage::dispatch($l->id, $rel);
        $this->invoiceNote = null;
        unset($this->editing);
    }

    /** OCR 후보 → 입력칸(저장 아님). */
    public function applyPassportName(int $i): void
    {
        $l = PurchaseListing::findOrFail($this->editingId);
        $v = data_get($l->passport_suggestions, "names.$i.value");
        if (is_string($v) && $v !== '') {
            $this->e_remitter_name = $v;
        }
    }

    public function applyPassportId(int $i): void
    {
        $l = PurchaseListing::findOrFail($this->editingId);
        $v = data_get($l->passport_suggestions, "ids.$i.value");
        if (is_string($v) && $v !== '') {
            $this->e_remitter_id = $v;
        }
    }

    public function dismissPassportSuggestions(): void
    {
        $l = PurchaseListing::findOrFail($this->editingId);
        $l->forceFill(['passport_suggestions' => null, 'passport_ocr_status' => null])->saveQuietly();
        unset($this->editing);
    }

    /**
     * [ERP 로 전송] — **저장된** 송금인을 전용 엔드포인트로(덮어쓰기 + ERP 감사). ERP 차량이 없으면(won 전) 안 보낸다 — 구매확정 때 purchase-sync 가 싣는다.
     * 응답 `updated[]`·`had_previous` 를 사람 말로. 403 = ERP 가 이 차를 이 영업의 차로 보지 않는다(매핑·담당 불일치).
     */
    public function sendRemitter(): void
    {
        $l = PurchaseListing::findOrFail($this->editingId);
        if ($l->car_erp_vehicle_id === null) {
            $this->invoiceNote = __('listings.invoice.state_no_erp_vehicle');

            return;
        }
        if (trim((string) $l->remitter_name) === '') {
            $this->invoiceNote = __('listings.invoice.send_needs_save');

            return;
        }
        $r = app(CarErpReadService::class)->setRemitter($this->erpEmailFor($l), (int) $l->car_erp_vehicle_id, $l->remitter_name, $l->remitter_id);
        if (! ($r['ok'] ?? false)) {
            $this->invoiceNote = __('listings.invoice.send_failed', ['reason' => ($r['status'] ?? 0) === 403 ? __('listings.invoice.send_403') : (string) ($r['message'] ?? $r['reason'] ?? 'HTTP '.($r['status'] ?? 0))]);

            return;
        }
        $l->forceFill(['remitter_synced_at' => now()])->saveQuietly();
        $updated = (array) data_get($r['data'], 'updated', []);
        $this->invoiceNote = $updated === []
            ? __('listings.invoice.send_same')
            : __(data_get($r['data'], 'had_previous') ? 'listings.invoice.send_overwritten' : 'listings.invoice.send_done');
        unset($this->editing, $this->listings);
    }

    /**
     * [인보이스 다운로드] — board 전용 COMMERCIAL INVOICE(xlsx). Address·Phone 은 ERP `GET /buyers/{id}`, Brand/Model 은 `GET /vehicles/{id}` 를
     * **그 순간만** 읽고 저장하지 않는다(실패·404 = 빈 칸, 세관 서류라 「-」 금지). 다운로드도 감사 1행(action=download).
     */
    public function downloadInvoice()
    {
        $l = PurchaseListing::findOrFail($this->editingId);
        if (CommercialInvoiceBuilder::templatePath() === null) {
            $this->invoiceNote = __('listings.invoice.no_template');

            return null;
        }
        if (trim((string) $l->remitter_name) === '') {
            $this->invoiceNote = __('listings.invoice.download_needs_name');

            return null;
        }
        $erp = [];
        $svc = app(CarErpReadService::class);
        $email = $this->erpEmailFor($l);
        if ($l->car_erp_buyer_id) {
            $b = $svc->buyer($email, (int) $l->car_erp_buyer_id, Auth::user()->isSuper());
            if ($b['ok'] ?? false) {
                $erp['address'] = data_get($b['data'], 'address');
                $erp['phone'] = data_get($b['data'], 'phone');
            }
        }
        if ($l->car_erp_vehicle_id) {
            $v = $svc->vehicleMeta($email, (int) $l->car_erp_vehicle_id);
            if ($v['ok'] ?? false) {
                $erp['brand'] = data_get($v['data'], 'brand');
                $erp['model'] = data_get($v['data'], 'model_type');
            }
        }
        $bytes = app(CommercialInvoiceBuilder::class)->build($l, $erp);
        $name = CommercialInvoiceBuilder::fileName($l);
        BoardAuditLog::create([
            'user_id' => Auth::id(), 'purchase_listing_id' => $l->id, 'action' => 'download', 'field' => 'commercial_invoice',
            'old_value' => null, 'new_value' => $name, 'ip_address' => BoardAuditLog::currentIp(),
        ]);
        $this->invoiceNote = __('listings.invoice.downloaded', ['name' => $name]);

        return response()->streamDownload(fn () => print ($bytes), $name, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * 송금인 저장 — **전 상태**(Jin 2026-10-28: ERP 로 보낸 차·구매대기 차 다 가능). `update()` 는 editable() 락을 타므로 별도 경로.
     * 소유권은 SalesmanScope(findOrFail). 정규화는 모델 static 한 곳(이름 대문자·공백 1 / ID 대문자 영숫자). 값이 바뀌면 ERP 전송 시각을 비운다.
     * 비우기는 ERP 에 전파되지 않는다(스펙: 빈 문자열 무시) — 그 경우 안내만.
     */
    /** 입력칸 → 모델(정규화·변경 시 전송 시각 리셋·입력칸 되쓰기). 저장은 호출자가. 반환 = 바뀌었나. `update()`·`saveRemitter()` 공용. */
    private function applyRemitter(PurchaseListing $l): bool
    {
        $name = PurchaseListing::normalizeRemitterName($this->e_remitter_name);
        $id = PurchaseListing::normalizeRemitterId($this->e_remitter_id);
        $changed = $name !== $l->remitter_name || $id !== $l->remitter_id;
        if ($changed) {
            $l->remitter_name = $name;
            $l->remitter_id = $id;
            $l->remitter_synced_at = null;
        }
        $this->e_remitter_name = $name ?? '';
        $this->e_remitter_id = $id ?? '';

        return $changed;
    }

    public function saveRemitter(): void
    {
        $l = PurchaseListing::findOrFail($this->editingId);
        $this->validate(['e_remitter_name' => 'nullable|string|max:100', 'e_remitter_id' => 'nullable|string|max:64']);
        $wasSynced = $l->remitter_synced_at !== null;
        $name = PurchaseListing::normalizeRemitterName($this->e_remitter_name);
        $id = PurchaseListing::normalizeRemitterId($this->e_remitter_id);
        $changed = $this->applyRemitter($l);
        if ($changed) {
            $l->save();
        }
        $this->invoiceNote = $changed
            ? (($wasSynced && ($name === null || $id === null)) ? __('listings.invoice.saved_cleared_note') : __('listings.invoice.saved'))
            : __('listings.invoice.saved_unchanged');
        unset($this->editing, $this->listings);
    }

    public function closeEdit(): void
    {
        $this->reset(['editingId', 'drawerTab', 'e_remitter_name', 'e_remitter_id', 'invoiceNote', 'passportFile', 'e_sale_total', 'e_transport_fee_usd', 'e_rate_usd', 'e_rate_eur', 'e_region', 'e_c_no', 'e_respond_contact_id', 'e_owner_name', 'e_payee_name', 'e_payee_bank', 'e_payee_account', 'e_selling_fee_payee_name', 'e_selling_fee_payee_bank', 'e_selling_fee_payee_account', 'e_car_cost', 'e_discount_rate', 'e_shipping_usd', 'e_encar_url', 'e_encar_dealer', 'e_auction_venue', 'e_lot_number', 'e_inspection_request', 'eSalesFiles', 'e_sale_currency', 'resyncResult', 'attachResult', 'syncSince']);
        unset($this->editing);
    }

    public function update(): void
    {
        $l = PurchaseListing::findOrFail($this->editingId);

        if (! $this->editable($l)) {
            $this->addError('e_car_cost', __('listings.drawer.locked_error'));

            return;
        }

        // 영업이 드로어에 직접 붙여넣는 링크도 같은 정리를 거친다(추가 폼과 동일 이유).
        $this->e_encar_url = \App\Support\ListingLink::canonicalEncarUrl($this->e_encar_url);

        $this->validate([
            'e_region' => 'nullable|string|max:60',
            'e_c_no' => 'nullable|string|max:50',
            'e_respond_contact_id' => 'nullable|string|max:80',
            'e_owner_name' => 'nullable|string|max:60',
            'e_payee_name' => 'nullable|string|max:60',
            'e_payee_bank' => 'nullable|string|max:40',
            'e_payee_account' => 'nullable|string|max:40',
            'e_selling_fee_payee_name' => 'nullable|string|max:60',
            'e_selling_fee_payee_bank' => 'nullable|string|max:40',
            'e_selling_fee_payee_account' => 'nullable|string|max:40',
            'e_car_cost' => 'nullable|numeric|min:0',
            'e_discount_rate' => 'nullable|numeric|min:0|max:100',
            'e_shipping_usd' => 'nullable|integer|in:'.implode(',', config('board.shipping_options')),
            'e_encar_url' => 'nullable|string|max:255',
            'e_encar_dealer' => 'nullable|string|max:100',
            'e_auction_venue' => 'nullable|string|max:100',
            'e_lot_number' => 'nullable|string|max:50',
            'e_inspection_request' => 'nullable|string|max:255',
            'e_remitter_name' => 'nullable|string|max:100',
            'e_remitter_id' => 'nullable|string|max:64',
            'eSalesFiles.*' => 'file|max:204800',
        ]);

        if (! $this->checkSalesFiles($this->eSalesFiles, $l->salesAttachments()->count(), 'eSalesFiles')) {
            return;
        }

        // 「금액 다시 보내기」 칸도 저장에 함께 적용(2026-10-16 Jin — 버튼을 따로 안 눌러도 바뀐 금액은 ERP 로 가야 한다).
        //    ERP 에 있는 차(미연동·재고매입 제외)만. 넷이 다 비었거나 안 바뀌었으면 금액은 보내지 않는다.
        $amountChanged = [];
        if ($l->car_erp_vehicle_id && ! $l->buyer_undecided) {
            $amountChanged = $this->applyResendAmounts($l);
            if ($amountChanged === null) {
                return;   // 통화·환율 세트 검증 실패 — 에러가 칸에 붙어 있다
            }
        }

        $l->region = $this->e_region ?: null;
        $l->c_no = $this->e_c_no ?: null;
        $l->respond_contact_id = $this->e_respond_contact_id ?: null;
        $l->owner_name = $this->e_owner_name ?: null;
        $l->payee_name = $this->e_payee_name ?: null;
        $l->payee_bank = $this->e_payee_bank ?: null;
        $l->payee_account = $this->e_payee_account ?: null;
        $l->selling_fee_payee_name = $this->e_selling_fee_payee_name ?: null;
        $l->selling_fee_payee_bank = $this->e_selling_fee_payee_bank ?: null;
        $l->selling_fee_payee_account = $this->e_selling_fee_payee_account ?: null;
        $l->car_cost = ($this->e_car_cost === null || $this->e_car_cost === '') ? null : (int) $this->e_car_cost;
        $l->discount_rate = ($this->e_discount_rate === null || $this->e_discount_rate === '') ? null : (float) $this->e_discount_rate;
        $l->shipping_usd = $this->e_shipping_usd ?: null;
        $l->inspection_request = $this->e_inspection_request ?: null;
        $this->applyRemitter($l);   // Invoice 탭 칸도 기본 「저장」에 같이(별도 버튼만 저장하면 사용자가 입력을 잃는다)
        $l->final_price = $l->totalKrw($this->usdRate(), $this->eurRate()) ?? $l->final_price;
        if ($l->source === 'encar') {
            $l->encar_url = $this->e_encar_url ?: null;
            $l->encar_dealer = $this->e_encar_dealer ?: null;
        } else {
            $l->auction_venue = $this->e_auction_venue ?: null;
            $l->lot_number = $this->e_lot_number ?: null;
        }
        $l->save();
        $stored = $this->storeSalesFiles($l, $this->eSalesFiles);

        // 2026-10-16 Jin — 「저장」만 눌러도 바뀐 금액·새 사진이 ERP 로 가야 한다(별도 버튼을 안 누르면 안 가서 두 번 올리게 됐다).
        //    금액이 하나라도 바뀌었으면 **금액+첨부 재전송**(ERP 는 빈 칸만 채운다), 금액 변경 없이 사진만 새로 있으면 **첨부 전용**(금액 불변).
        //    둘 다 없으면 보내지 않는다. ERP 에 없는 차(미연동)는 보내지 않는다(낙찰 때 같이 간다).
        $flash = __('listings.drawer.updated_flash', ['number' => \App\Support\Plate::show($l->vehicle_number)]);
        if ($l->car_erp_vehicle_id && $amountChanged !== []) {
            SyncWonListingToCarErp::dispatch($l->id, resync: true);
            $labels = array_map(fn ($f) => __('listings.resync.changed_field.'.$f), $amountChanged);
            $flash .= ' '.__('listings.resync.auto_sent', ['fields' => implode('·', $labels), 'count' => $stored['stored']]);
        } elseif ($l->car_erp_vehicle_id && $stored['stored'] > 0) {
            SyncWonListingToCarErp::dispatch($l->id, resync: true, attachmentsOnly: true);
            $flash .= ' '.__('listings.attach_add.auto_sent', ['count' => $stored['stored']]);
        }
        if ($stored['skipped'] !== []) {
            $flash .= ' '.__('listings.attach.dup_skipped', ['count' => count($stored['skipped']), 'names' => implode(', ', $stored['skipped'])]);
        }

        unset($this->listings, $this->tabCounts);
        session()->flash('ok', $flash);
        $this->closeEdit();
    }

    public function toggleAdd(): void
    {
        $this->showAdd = ! $this->showAdd;
        if (! $this->showAdd) {
            $this->resetForm();
        }
    }

    /** 유입 카테고리 선택 → 매입방법(source) 자동 도출. */
    public function setOrigin(string $o): void
    {
        $this->origin = $o;
        $this->source = PurchaseListing::sourceForOrigin($o);
    }

    /** 승격: 붙인 링크에서 식별자 자동추출(encar_id/c_no/ssancar_ref) + 유입카테고리/출처 세팅. */
    public function parseLink(): void
    {
        $url = trim($this->link);
        if ($url === '') {
            return;
        }
        $r = \App\Support\ListingLink::parse($url);

        if ($r === []) {
            // 아는 사이트인데 식별값이 없으면 링크 형식 문제, 모르는 사이트면 아직 미지원 — 둘 다 출처는 사람이 고른다.
            $this->addError('link', \App\Support\ListingLink::isKnownSite($url)
                ? __('listings.links.parse_error')
                : __('listings.links.unsupported'));

            return;
        }

        $this->resetErrorBag(['link', 'origin']);
        // 셀프검차매입은 영업이 명시적으로 고른 카테고리다 — 링크 파싱이 덮어쓰면(엔카 URL → origin=encar)
        // 방금 누른 토글이 조용히 풀린다. 식별자만 받고 카테고리는 선택을 유지.
        $keys = $this->origin === PurchaseListing::ORIGIN_SELF_INSPECTION
            ? ['encar_id', 'encar_url', 'c_no', 'ssancar_ref']
            : ['origin', 'source', 'encar_id', 'encar_url', 'c_no', 'ssancar_ref'];
        foreach ($keys as $k) {
            if (isset($r[$k])) {
                $this->{$k} = $r[$k];
            }
        }

        // 매물 자동채움(enrichment) — 빈 칸만 prefill, 영업이 확인 후 저장(IDENTITY_LOCKED 자동확정 금지).
        $e = app(\App\Services\ListingEnrichment::class)->enrich($r, $url);
        // 검차 싼카 링크는 원본 엔카 id 를 품고 있음 → 폼에 채워 '이력 조회' 가능하게.
        if (! empty($e['encar_id']) && $this->encar_id === '') {
            $this->encar_id = (string) $e['encar_id'];
        }
        $filled = [];
        if (! empty($e['vehicle_number']) && $this->vehicle_number === '') {
            $this->vehicle_number = $e['vehicle_number'];
            $filled[] = __('listings.links.fill_vehicle_number');
        }
        $prices = $e['prices'] ?? [];
        if ($prices && ($this->expected_price === null || $this->expected_price === '')) {
            $this->priceOptions = $prices;   // {KRW,USD,EUR} — 토글 시 금액 변경
            $cur = isset($prices['KRW']) ? 'KRW' : array_key_first($prices);
            $this->expected_price_currency = $cur;
            $this->expected_price = (string) $prices[$cur];
            $filled[] = __('listings.links.fill_price', ['currencies' => implode('/', array_keys($prices))]);
            // 차값 = 선택통화 금액 그대로(외화 그대로 보관). 빈 칸만(영업 입력 보존).
            if ($this->car_cost === null || $this->car_cost === '') {
                $this->car_cost = (string) $prices[$cur];
                $filled[] = __('listings.links.fill_car_cost', ['currency' => $cur]);
            }
        }
        if (! empty($e['region']) && $this->region === '') {
            $this->region = $e['region'];
            $filled[] = __('listings.links.fill_region');
        }
        if (! empty($e['vin']) && $this->vin === '') {
            $this->vin = $e['vin'];
            $filled[] = __('listings.links.fill_vin');
        }

        $bits = array_filter([
            isset($r['encar_id']) ? 'encar #'.$r['encar_id'] : null,
            isset($r['c_no']) ? 'c_no '.$r['c_no'] : null,
            $r['ssancar_ref'] ?? null,
        ]);
        $cat = PurchaseListing::originOptions()[$this->origin] ?? '';
        $name = ! empty($e['name']) ? __('listings.links.enrich_name', ['name' => $e['name']]) : '';
        $auto = $filled ? __('listings.links.enrich_auto', ['fields' => implode('/', $filled)]) : '';
        session()->flash('ok', __('listings.links.enrich_category', ['cat' => $cat]).implode(' · ', $bits).$name.$auto.__('listings.links.enrich_suffix'));
        $this->link = '';   // 추출한 값은 아래 칩·칸에 남는다 — 다른 링크를 이어서 넣을 수 있게 비운다
    }

    /** 엔카 차량이력(보험이력/성능점검/엔카진단) on-demand 조회 — 조회전용, board 저장 안 함. */
    public function loadEncarHistory(): void
    {
        $id = $this->encar_id !== '' ? $this->encar_id
            : (string) (\App\Support\ListingLink::parse(trim($this->link))['encar_id'] ?? '');
        if ($id === '') {
            $this->addError('link', __('listings.history.need_link'));

            return;
        }
        $this->encarHistory = app(\App\Services\ListingEnrichment::class)->encarHistory($id);
        $this->showHistory = true;
        if ($this->encarHistory === []) {
            session()->flash('ok', __('listings.history.none'));
        }
    }

    /** 통화 토글(매물표시가) — 그 통화로 차값을 "그대로" 가져옴(외화 그대로 고정). 추출된 통화만. */
    public function pickCurrency(string $cur): void
    {
        if (! in_array($cur, ['KRW', 'USD', 'EUR'], true)) {
            return;
        }
        // 링크에서 추출된 통화만 선택 가능(엔카=원화만). 링크 전(빈 priceOptions)엔 라벨 토글 허용.
        if (! empty($this->priceOptions) && ! isset($this->priceOptions[$cur])) {
            return;
        }
        $this->expected_price_currency = $cur;
        if (isset($this->priceOptions[$cur])) {
            $this->expected_price = (string) $this->priceOptions[$cur];
            $this->car_cost = (string) $this->priceOptions[$cur];   // 차값 = 선택통화 금액 그대로(환산X)
        }
    }

    public function save(): void
    {
        // 매입방법(source)은 유입 카테고리(origin)에서 도출 — 단일 소스 오브 트루스.
        $this->source = PurchaseListing::sourceForOrigin($this->origin);
        // 추적 파라미터가 붙은 모바일 공유 링크는 255자를 넘는다 → 검증 전에 정리(ListingLink 주석 참조).
        $this->encar_url = \App\Support\ListingLink::canonicalEncarUrl($this->encar_url);

        $this->validate([
            'origin' => 'required|in:'.implode(',', array_keys(PurchaseListing::ORIGIN_LABELS)),
            'source' => 'required|in:encar,auction',
            'vehicle_number' => 'required|string|max:20',
            'owner_name' => 'nullable|string|max:60',
            'region' => 'nullable|string|max:60',
            'c_no' => 'nullable|string|max:50',
            'ssancar_ref' => 'nullable|string|max:50',
            'encar_id' => 'nullable|string|max:50',
            'respond_contact_id' => 'nullable|string|max:80',
            'expected_price' => 'nullable|numeric|min:0',
            'payee_name' => 'nullable|string|max:60',
            'payee_bank' => 'nullable|string|max:40',
            'payee_account' => 'nullable|string|max:40',
            'selling_fee_payee_name' => 'nullable|string|max:60',
            'selling_fee_payee_bank' => 'nullable|string|max:40',
            'selling_fee_payee_account' => 'nullable|string|max:40',
            'car_cost' => 'nullable|numeric|min:0',
            'discount_rate' => 'nullable|numeric|min:0|max:100',
            'shipping_usd' => 'nullable|integer|in:'.implode(',', config('board.shipping_options')),
            'encar_url' => 'nullable|string|max:255',
            'encar_dealer' => 'nullable|string|max:100',
            'auction_venue' => 'nullable|string|max:100',
            'lot_number' => 'nullable|string|max:50',
            'inspection_request' => 'nullable|string|max:255',
            'salesFiles.*' => 'file|max:204800',
        ], messages: [
            'origin.required' => __('listings.add_form.origin_required'),
        ], attributes: [
            'vehicle_number' => __('listings.add_form.attr_vehicle_number'),
            'vin' => __('listings.add_form.attr_vin'),
        ]);

        // 첨부 사전검증(실행파일·건수) — listing 생성 전
        if (! $this->checkSalesFiles($this->salesFiles, 0, 'salesFiles')) {
            return;
        }

        // 중복 차량 차단 (차량번호·VIN = 식별값, 활성만 — 삭제된 행은 재등록 허용). 본인격리 무시 전역 조회.
        // first() 는 소프트삭제 제외 → 지웠던 차 재등록 시 안 막힘(DB vin unique 제거분을 여기서 대체).
        $dup = PurchaseListing::withoutGlobalScope(\App\Models\Scopes\SalesmanScope::class)
            ->where(function ($q) {
                $q->where('vehicle_number', $this->vehicle_number);
                if ($this->vin) {
                    $q->orWhere('vin', $this->vin);
                }
            })
            ->first();
        if ($dup) {
            $field = ($this->vin && $dup->vin === $this->vin && $dup->vehicle_number !== $this->vehicle_number)
                ? 'vin' : 'vehicle_number';
            $this->addError($field, __('listings.add_form.dup_error', ['id' => $dup->id]));

            return;
        }

        // 경매 출품번호 중복 차단 ((venue, lot) = 식별값, 활성만). DB unique 제거분 대체.
        if ($this->source === 'auction' && $this->auction_venue && $this->lot_number) {
            $lotDup = PurchaseListing::withoutGlobalScope(\App\Models\Scopes\SalesmanScope::class)
                ->where('auction_venue', $this->auction_venue)
                ->where('lot_number', $this->lot_number)
                ->first();
            if ($lotDup) {
                $this->addError('lot_number', __('listings.add_form.dup_error', ['id' => $lotDup->id]));

                return;
            }
        }

        // 경매 등록 시간잠금 (관리자 우회)
        if ($this->source === 'auction' && TimeGate::auctionRegistrationLocked() && ! Auth::user()->isManager()) {
            $this->addError('source', __('listings.add_form.auction_locked_error', ['time' => config('board.auction_lock_time')]));

            return;
        }

        $selfInspection = $this->origin === PurchaseListing::ORIGIN_SELF_INSPECTION;
        $carCost = ($this->car_cost === null || $this->car_cost === '') ? null : (int) $this->car_cost;
        $discount = ($this->discount_rate === null || $this->discount_rate === '') ? null : (float) $this->discount_rate;
        $shipping = $this->shipping_usd ?: null;

        $listing = new PurchaseListing([
            'created_by_user_id' => Auth::id(),
            'source' => $this->source,
            'origin' => $this->origin,
            'vehicle_number' => $this->vehicle_number,
            'owner_name' => $this->owner_name ?: null,
            'vin' => $this->vin ?: null,
            'region' => $this->region ?: null,
            'c_no' => $this->c_no ?: null,
            'ssancar_ref' => $this->ssancar_ref ?: null,
            'encar_id' => $this->encar_id ?: null,
            'respond_contact_id' => $this->respond_contact_id ?: null,
            'payee_name' => $this->payee_name ?: null,
            'payee_bank' => $this->payee_bank ?: null,
            'payee_account' => $this->payee_account ?: null,
            'selling_fee_payee_name' => $this->selling_fee_payee_name ?: null,
            'selling_fee_payee_bank' => $this->selling_fee_payee_bank ?: null,
            'selling_fee_payee_account' => $this->selling_fee_payee_account ?: null,
            'expected_price' => ($this->expected_price === null || $this->expected_price === '') ? null : (int) $this->expected_price,
            'expected_price_currency' => $this->expected_price_currency,
            'car_cost' => $carCost,
            'discount_rate' => $discount,
            'shipping_usd' => $shipping,
            'encar_url' => $this->source === 'encar' ? ($this->encar_url ?: null) : null,
            'encar_dealer' => $this->source === 'encar' ? ($this->encar_dealer ?: null) : null,
            'auction_venue' => $this->source === 'auction' ? ($this->auction_venue ?: null) : null,
            'lot_number' => $this->source === 'auction' ? ($this->lot_number ?: null) : null,
            'lock_at' => $this->source === 'auction' ? TimeGate::auctionLockAt() : null,
            // 셀프검차는 검차 씬을 건너뛰므로 요청을 받을 사람이 없다 — 저장하지 않는다(입력칸도 숨김).
            'inspection_request' => $selfInspection ? null : ($this->inspection_request ?: null),
            // 셀프검차매입 = 현지확인·전달·회신 씬을 건너뛰고 바로 경매/구매로.
            // 생성 시점이라 상태전이 가드(updating)를 타지 않는다 — TRANSITIONS 는 손대지 않음.
            // buyer_verdict=accepted 는 "accepted 면 회신도 accepted" 불변식 유지용,
            // verdict_channel=manual 은 respond.io 폴러(auto 만 조회)가 이 차를 집어가지 않게.
            'status' => $selfInspection ? 'accepted' : 'draft',
            'buyer_verdict' => $selfInspection ? 'accepted' : 'none',
            'verdict_channel' => $selfInspection ? 'manual' : 'auto',
        ]);
        $listing->final_price = $listing->totalKrw($this->usdRate(), $this->eurRate());   // 최종금액(KRW) 스냅샷(차값통화 환산)
        $listing->save();

        $this->storeSalesFiles($listing, $this->salesFiles);

        // 승격 대기에서 시작했으면 consume(대기 목록서 제거 + listing 연결).
        if ($this->promotingId) {
            PromotionRequest::where('status', PromotionRequest::PENDING)
                ->where('id', $this->promotingId)
                ->update(['status' => PromotionRequest::CONSUMED, 'purchase_listing_id' => $listing->id, 'handled_by_user_id' => Auth::id()]);
            unset($this->promotions);
        }

        $this->resetForm();
        $this->showAdd = false;
        unset($this->listings, $this->tabCounts);
        $this->resetPage();   // 2페이지를 보다 등록하면 새 행(=1페이지 맨 위)이 안 보인다

        // 셀프검차매입은 저장 즉시 경매/구매 탭에서 마무리한다 — 화면까지 데려다 준다.
        if ($selfInspection) {
            session()->flash('ok', __('listings.add_form.saved_self_flash', ['number' => \App\Support\Plate::show($listing->vehicle_number)]));
            $this->redirect(route('auction'), navigate: true);

            return;
        }

        session()->flash('ok', __('listings.add_form.saved_flash'));
    }

    /** 첨부 사전검증 — 실행파일 차단 + 최대건수(car-erp 첨부탭 10건 cap). 통과=true. */
    private function checkSalesFiles(array $files, int $existing, string $errKey): bool
    {
        $files = array_values(array_filter($files));
        if (empty($files)) {
            return true;
        }

        foreach ($files as $f) {
            if (\App\Support\UploadGuard::isExecutable($f->getClientOriginalName())) {
                $this->addError($errKey, __('listings.attach.exec_error', ['name' => $f->getClientOriginalName()]));

                return false;
            }
        }

        $max = (int) config('board.attachment_max');
        if ($existing + count($files) > $max) {
            $this->addError($errKey, __('listings.attach.max_error', ['max' => $max, 'existing' => $existing]));

            return false;
        }

        return true;
    }

    /**
     * 첨부 저장 — 이미지=사진(sales_photo)/그 외=서류(sales_document) 자동분류. 서류는 바이어 전송 금지(§28).
     *
     * ⚠️ **같은 이름의 파일이 이미 붙어 있으면 건너뛴다**(2026-10-16 Jin — 같은 5장을 두 번 올려 ERP 에 10장이 붙었다.
     *    ERP 는 경로 기준 중복제거라 board 가 두 번 저장하면 두 번 붙는다). 판정은 원본 파일명만(크기 컬럼 없음) — 다른 사진이
     *    같은 이름이면 걸린다는 한계가 있어, 건너뛴 이름을 화면에 알린다.
     *
     * @return array{stored:int, skipped:list<string>}
     */
    private function storeSalesFiles(PurchaseListing $l, array $files): array
    {
        $files = array_values(array_filter($files));
        if (empty($files)) {
            return ['stored' => 0, 'skipped' => []];
        }

        $disk = config('board.photo_disk');
        $sort = (int) $l->salesAttachments()->max('sort');
        $existing = $l->salesAttachments()->pluck('original_name')->map(fn ($n) => mb_strtolower((string) $n))->flip()->all();
        $stored = 0;
        $skipped = [];

        foreach ($files as $f) {
            $name = $f->getClientOriginalName();
            if (isset($existing[mb_strtolower($name)])) {
                $skipped[] = $name;

                continue;
            }
            $existing[mb_strtolower($name)] = true;
            $stored++;
            $isImage = str_starts_with((string) $f->getMimeType(), 'image/');
            $prefix = $isImage ? config('board.sales_photo_prefix') : config('board.sales_document_prefix');
            $path = $f->store($prefix.'/'.$l->id, $disk);
            $l->salesAttachments()->create([
                's3_path' => $path,
                'original_name' => $f->getClientOriginalName(),
                'sort' => ++$sort,
                'kind' => $isImage ? InspectionPhoto::KIND_SALES_PHOTO : InspectionPhoto::KIND_SALES_DOCUMENT,
                'uploaded_by_user_id' => Auth::id(),
                'share_to_buyer' => false,   // 영업 첨부는 바이어 자동전송 안 함(§28)
            ]);
        }

        return ['stored' => $stored, 'skipped' => $skipped];
    }

    /** 저장 전 선택파일 빼기 — 추가폼. */
    public function removeSalesFile(int $i): void
    {
        unset($this->salesFiles[$i]);
        $this->salesFiles = array_values($this->salesFiles);
    }

    /** 저장 전 선택파일 빼기 — 편집 드로어. */
    public function removeESalesFile(int $i): void
    {
        unset($this->eSalesFiles[$i]);
        $this->eSalesFiles = array_values($this->eSalesFiles);
    }

    /** 편집 드로어에서 첨부 삭제 — 본인 글(SalesmanScope)·편집가능·sales_* 한정(IDOR 방지). */
    public function deleteSalesAttachment(int $id): void
    {
        $l = PurchaseListing::findOrFail($this->editingId);
        if (! $this->editable($l)) {
            return;
        }
        $p = $l->salesAttachments()->whereKey($id)->firstOrFail();
        Storage::disk(config('board.photo_disk'))->delete($p->s3_path);
        $p->delete();
        unset($this->editing);
    }

    private function resetForm(): void
    {
        $this->reset(['vehicle_number', 'owner_name', 'vin', 'region', 'c_no', 'ssancar_ref', 'encar_id', 'respond_contact_id', 'link', 'promotingId', 'expected_price', 'expected_price_currency', 'priceOptions', 'encarHistory', 'showHistory', 'payee_name', 'payee_bank', 'payee_account', 'selling_fee_payee_name', 'selling_fee_payee_bank', 'selling_fee_payee_account', 'car_cost', 'discount_rate', 'shipping_usd', 'encar_url', 'encar_dealer', 'auction_venue', 'lot_number', 'inspection_request', 'salesFiles']);
        $this->origin = '';
        $this->source = 'encar';
        $this->resetErrorBag();
    }

    public function with(): array
    {
        return [
            'auctionLocked' => TimeGate::auctionRegistrationLocked(),
        ];
    }
}; ?>

<div class="p-3 md:p-6">
    {{-- 헤더 --}}
    <div class="mb-4 flex items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-gray-800">{{ __('listings.heading') }}</h1>
            <p class="mt-0.5 text-xs text-gray-500">{{ __('listings.own_only_note', ['name' => auth()->user()->name]) }}</p>
        </div>
        {{-- 환율 (car-erp 전신환 매입률 · 실패 시 폴백) — car-erp 표시(소수 2자리)와 일치 --}}
        <div class="card-sm shrink-0 text-right text-[13px]" style="background:#f5f8ff;border-color:#dbeafe">
            <div class="flex items-center justify-end gap-1 text-[11px] text-gray-500">
                {{ __('listings.rate.label') }}
                <span class="font-semibold {{ $rateLive ? 'text-green-600' : 'text-amber-600' }}">{{ $rateLive ? __('listings.rate.live') : __('listings.rate.temp') }}</span>
                <button wire:click="refreshRate" wire:loading.attr="disabled" class="text-blue-500 hover:text-blue-700" title="{{ __('listings.rate.refresh_title') }}">↻</button>
            </div>
            <div class="font-bold text-gray-800">{{ __('listings.rate.usd_line', ['amount' => $krwPerUsdDisplay]) }}</div>
            <div class="font-bold text-gray-800">{{ __('listings.rate.eur_line', ['amount' => $krwPerEurDisplay]) }}</div>
            @if ($rateFetchedAt)<div class="text-[10px] text-gray-400">{{ __('listings.rate.as_of', ['time' => $rateFetchedAt]) }}</div>@endif
        </div>
    </div>

    {{-- 시간잠금 안내 --}}
    <div class="card-sm mb-3 flex items-center gap-2 text-[13px]"
         style="border-color:{{ $auctionLocked ? '#fecaca' : '#bbf7d0' }};background:{{ $auctionLocked ? '#fef2f2' : '#f0fdf4' }}">
        <span>⏰</span>
        <span class="font-semibold {{ $auctionLocked ? 'text-red-700' : 'text-green-700' }}">
            {{ $auctionLocked ? __('listings.timegate.auction_closed', ['time' => config('board.auction_lock_time')]) : __('listings.timegate.auction_open', ['time' => config('board.auction_lock_time')]) }}
        </span>
        <span class="text-gray-500">{{ __('listings.timegate.encar_always') }}</span>
    </div>

    @if (session('ok'))
        <div class="card-sm mb-3 border-green-200 bg-green-50 text-[13px] text-green-700">✓ {{ session('ok') }}</div>
    @endif

    {{-- 승격 대기 (연동 A · respond.io 에서 바이어가 board 처리 의사 표시 → 담당 영업에게 라우팅). respond.io 미설정이면 숨김 --}}
    @if (config('services.respond_io.api_token') && $this->promotions->isNotEmpty())
        <div class="card mb-4" style="border-color:#fde68a;background:#fffbeb">
            <h2 class="mb-2 font-bold text-amber-800">{{ __('listings.promo.heading') }} <span class="text-amber-500">· {{ __('listings.promo.count', ['count' => $this->promotions->count()]) }}</span></h2>
            <p class="mb-3 text-[11px] text-amber-700/80">{{ __('listings.promo.intro') }}</p>
            <div class="space-y-2">
                @foreach ($this->promotions as $p)
                    <div class="flex items-center justify-between gap-2 rounded-md border border-amber-200 bg-white px-3 py-2">
                        <div class="min-w-0">
                            <div class="truncate font-semibold text-gray-800">{{ $p->label ?: __('listings.promo.contact_fallback', ['id' => $p->respond_contact_id]) }}</div>
                            <div class="text-[11px] text-gray-400">{{ __('listings.promo.contact_meta', ['id' => $p->respond_contact_id]) }} · {{ $p->created_at->diffForHumans() }}</div>
                            @if (auth()->user()->canSeeAll())
                                <div class="text-[11px] {{ $p->assigned_email ? 'text-gray-400' : 'text-amber-600' }}">{{ __('listings.promo.assignee', ['name' => $p->assigned_email ?: __('listings.promo.unassigned')]) }}</div>
                            @endif
                        </div>
                        <div class="flex shrink-0 gap-1.5">
                            <button class="btn-primary btn-sm" wire:click="promoteFrom({{ $p->id }})">{{ __('listings.promo.promote') }}</button>
                            <button class="btn-ghost btn-sm" wire:click="dismissPromotion({{ $p->id }})"
                                    wire:confirm="{{ __('listings.promo.dismiss_confirm') }}">{{ __('listings.promo.dismiss') }}</button>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <div class="card">
        <div class="mb-3 flex items-center justify-between">
            <h2 class="font-bold text-gray-800">{{ __('listings.list.heading') }} <span class="text-gray-400">· {{ __('listings.list.count', ['count' => $this->listings->total()]) }}</span></h2>
            <button class="btn-primary" wire:click="toggleAdd">{{ __('listings.list.add') }}</button>
        </div>

        {{-- 상태 탭 + 페이지당 건수 (2026-09-11) — 예전엔 전량을 읽어 그렸다. 기본 = 전체 + 30건.
             ⚠️ **모바일이 주 사용처다**(Jin). 탭은 줄바꿈이 아니라 **가로 스크롤 칩** — 9개가 줄바꿈하면
                목록이 화면 두 줄 아래로 밀린다.
             🚨 스크롤 컨테이너에 **`min-w-0` 필수** — flex 자식은 기본 `min-width:auto` 라 칩 9개가
                줄어들 줄을 모르고 **부모를 넘쳐** overflow-x-auto 가 죽는다(= 페이지 전체가 가로로 밀린다).
             ⚠️ 좁은 폭에선 셀렉트를 **아래 줄**로 내린다 — 한 줄에 같이 두면 탭이 보일 폭이 90px 남짓 남는다. --}}
        <div class="mb-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div class="-mx-1 flex min-w-0 gap-1 overflow-x-auto whitespace-nowrap px-1 pb-1">
                @foreach (\App\Models\PurchaseListing::TABS as $t)
                    <button type="button" wire:click="setTab('{{ $t }}')"
                        class="shrink-0 rounded-md border px-2.5 py-1.5 text-[12px] font-semibold {{ $tab === $t ? 'border-[var(--color-primary)] bg-[var(--color-primary)] text-white' : 'border-gray-300 bg-white text-gray-600' }}">
                        {{ __('listings.tabs.'.$t) }}
                        <span class="{{ $tab === $t ? 'text-white/80' : 'text-gray-400' }}">{{ $this->tabCounts[$t] ?? 0 }}</span>
                    </button>
                @endforeach
            </div>
            <div class="flex shrink-0 items-center gap-1.5 self-end sm:self-auto">
                {{-- 등록자 필터 — 관리·super 만(영업은 본인 것만 보이므로 의미 없음). 2026-10-13 Jin --}}
                @if ($this->owners->isNotEmpty())
                    <select wire:model.live="owner" class="input-filter shrink-0">
                        <option value="0">{{ __('listings.list.owner_all') }}</option>
                        @foreach ($this->owners as $o)
                            <option value="{{ $o->id }}">{{ $o->name }}</option>
                        @endforeach
                    </select>
                @endif
                <select wire:model.live="perPage" class="input-filter shrink-0">
                    @foreach ([10, 20, 30, 50, 100] as $n)
                        <option value="{{ $n }}">{{ __('listings.list.per_page', ['count' => $n]) }}</option>
                    @endforeach
                    <option value="0">{{ __('listings.list.count_only') }}</option>
                </select>
            </div>
        </div>

        {{-- 추가 폼 --}}
        @if ($showAdd)
            <div class="card-sm mb-4" style="background:#f8f9fb">
                <label class="label-base">{{ __('listings.add_form.origin_label') }} <span class="text-red-500">*</span></label>
                <div class="mb-1 flex flex-wrap gap-1">
                    @foreach (\App\Models\PurchaseListing::originOptions() as $key => $lbl)
                        <button type="button" wire:click="setOrigin('{{ $key }}')"
                            class="rounded-md border px-3 py-1.5 text-[13px] font-semibold {{ $origin === $key ? 'border-[var(--color-primary)] bg-[var(--color-primary)] text-white' : 'border-gray-300 bg-white text-gray-600' }}">{{ $lbl }}</button>
                    @endforeach
                </div>
                @if ($origin === '')
                    <p class="mb-3 text-[11px] text-gray-400">{{ __('listings.add_form.origin_pick') }}</p>
                @elseif ($origin === \App\Models\PurchaseListing::ORIGIN_SELF_INSPECTION)
                    <p class="mb-3 text-[11px] text-gray-400">{{ __('listings.add_form.method_prefix') }}<b>{{ __('listings.add_form.method_self') }}</b></p>
                @else
                    <p class="mb-3 text-[11px] text-gray-400">{{ __('listings.add_form.method_prefix') }}<b>{{ $source === 'auction' ? __('listings.add_form.method_auction') : __('listings.add_form.method_encar') }}</b>{{ __('listings.add_form.method_suffix') }}</p>
                @endif
                @error('origin') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                {{-- 승격: 링크 1칸 → 주소로 사이트 판별(ListingLink::parse) → 식별자 자동추출 + 출처 자동 + 자동채움.
                     추출 후 칸을 비워 다른 링크를 이어 넣으면 식별값이 합쳐진다(2026-10-21 Jin — 사이트별 칸 폐지, 링크 종류는 늘어난다). --}}
                <div class="card-sm mb-3" style="background:#f0f7ff;border-color:#dbeafe">
                    <label class="label-base">{{ __('listings.links.label') }} <span class="text-gray-400">{{ __('listings.links.hint') }}</span></label>
                    <div class="flex gap-2">
                        <input class="input-base flex-1" wire:model="link" wire:keydown.enter.prevent="parseLink"
                               placeholder="{{ __('listings.links.ph') }}">
                        <button type="button" class="btn-primary btn-sm shrink-0" wire:click="parseLink" wire:loading.attr="disabled" wire:target="parseLink">{{ __('listings.links.extract') }}</button>
                    </div>
                    @error('link') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    @if ($encar_id || $c_no || $ssancar_ref)
                        <p class="mt-1 text-[11px] text-gray-400">{{ __('listings.links.more_hint') }}</p>
                    @endif
                    @if ($encar_id || $c_no || $ssancar_ref)
                        <div class="mt-2 flex flex-wrap items-center gap-1.5 text-[11px]">
                            <span class="text-gray-400">{{ __('listings.links.extracted') }}</span>
                            @if ($encar_id)<span class="badge badge-encar">encar #{{ $encar_id }}</span>@endif
                            @if ($c_no)<span class="badge badge-blue">c_no {{ $c_no }}</span>@endif
                            @if ($ssancar_ref)<span class="badge badge-gray">{{ $ssancar_ref }}</span>@endif
                            {{-- 이력 조회 = 엔카/싼카 어느 링크로 추출했든 엔카 id 잡히면 노출 --}}
                            @if ($encar_id)
                                <button type="button" class="btn-outline btn-sm shrink-0" wire:click="loadEncarHistory" wire:loading.attr="disabled" wire:target="loadEncarHistory">{{ __('listings.history.view') }}</button>
                            @endif
                        </div>
                    @endif

                    @include('livewire.listings._encar-history')
                    <label class="label-base mt-2">{{ __('listings.links.price_label') }} <span class="text-gray-400">{{ __('listings.links.price_hint') }}</span></label>
                    <div class="flex gap-2">
                        <input class="input-base flex-1" wire:model="expected_price" inputmode="numeric" placeholder="{{ __('listings.links.price_ph') }}">
                        <div class="inline-flex shrink-0 overflow-hidden rounded-md border border-gray-300">
                            @foreach (['KRW', 'USD', 'EUR'] as $cur)
                                {{-- 추출된 통화만 활성 — 엔카=원화만, ssancar=3통화. 링크 전(빈 priceOptions)엔 전부 허용. --}}
                                @php $curOff = ! empty($priceOptions) && ! isset($priceOptions[$cur]); @endphp
                                <button type="button" wire:click="pickCurrency('{{ $cur }}')" @disabled($curOff)
                                    class="px-2.5 py-1.5 text-[13px] font-semibold {{ $expected_price_currency === $cur ? 'bg-[var(--color-primary)] text-white' : 'bg-white text-gray-600' }} {{ $curOff ? 'cursor-not-allowed opacity-40' : '' }}">{{ \App\Support\Money::symbol($cur) }}</button>
                            @endforeach
                        </div>
                    </div>
                    @if ($priceOptions)
                        <p class="mt-1 text-[11px] text-gray-500">{{ __('listings.links.price_options_prefix') }}@foreach ($priceOptions as $cur => $amt)<span class="mr-2 whitespace-nowrap">{{ \App\Support\Money::symbol($cur) }} {{ number_format($amt) }}</span>@endforeach{{ __('listings.links.price_options_suffix') }}</p>
                    @else
                        <p class="mt-1 text-[11px] text-gray-400">{{ __('listings.links.price_help') }}</p>
                    @endif
                    @error('expected_price') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    @if (config('services.respond_io.api_token'))
                        <label class="label-base mt-2">{{ __('listings.links.contact_label') }} <span class="text-gray-400">{{ __('listings.links.contact_hint') }}</span></label>
                        <input class="input-base" wire:model="respond_contact_id" placeholder="{{ __('listings.links.contact_ph') }}">
                        @error('respond_contact_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    @endif
                </div>

                {{-- 차량번호 · 소유자 · 지역 · 매물번호 (한 행) — 차값/할인/배송/계좌/첨부는 이후 씬(견적·구매확정)에서 입력 --}}
                <div class="grid gap-3 sm:grid-cols-4">
                    <div>
                        <label class="label-base">{{ __('listings.add_form.vehicle_number') }} <span class="text-red-500">*</span></label>
                        <input class="input-base" wire:model="vehicle_number" placeholder="{{ __('listings.add_form.vehicle_number_ph') }}">
                        @error('vehicle_number') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label-base">{{ __('listings.add_form.owner') }} <span class="text-gray-400">{{ __('listings.add_form.owner_hint') }}</span></label>
                        <input class="input-base" wire:model="owner_name" placeholder="{{ __('listings.add_form.owner_ph') }}">
                        @error('owner_name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label-base">{{ __('listings.add_form.region') }}</label>
                        <x-region-input model="region" :placeholder="__('listings.add_form.region_ph')" />
                        @error('region') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label-base">{{ __('listings.add_form.c_no') }} <span class="text-gray-400">{{ __('listings.add_form.c_no_hint') }}</span></label>
                        <input class="input-base" wire:model="c_no" placeholder="{{ __('listings.add_form.c_no_ph') }}">
                        @error('c_no') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                {{-- 경매 전용 식별 정보 --}}
                @if ($source === 'auction')
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <div><label class="label-base">{{ __('listings.add_form.auction_venue') }}</label><input class="input-base" wire:model="auction_venue" placeholder="{{ __('listings.add_form.auction_venue_ph') }}"></div>
                        <div><label class="label-base">{{ __('listings.add_form.lot_number') }}</label><input class="input-base" wire:model="lot_number" placeholder="{{ __('listings.add_form.lot_number_ph') }}"></div>
                    </div>
                @endif

                {{-- 검차 요청사항 — 검차원에게 남기는 말(선택). 셀프검차는 검차 씬이 없어 숨김 --}}
                @if ($origin !== \App\Models\PurchaseListing::ORIGIN_SELF_INSPECTION)
                    <div class="mt-3">
                        <label class="label-base">{{ __('listings.add_form.inspection_request') }} <span class="text-gray-400">{{ __('listings.add_form.inspection_request_hint') }}</span></label>
                        <input class="input-base" wire:model="inspection_request" maxlength="255" placeholder="{{ __('listings.add_form.inspection_request_ph') }}">
                        @error('inspection_request') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                @endif

                <p class="mt-3 text-xs text-gray-500">{!! __('listings.add_form.note') !!}</p>
                @error('source') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                {{-- 링크에서 자동으로 채워지는 값(출처·지역·금액·엔카 URL 등)은 입력칸이 없어 개별 에러가 안 보인다.
                     그 경우 저장만 조용히 실패하므로, 저장 버튼 옆에서 모든 검증 에러를 모아 보여준다. --}}
                @if ($errors->any())
                    <div class="card-sm mt-3 border-red-200 bg-red-50 text-[13px] text-red-700">
                        @foreach ($errors->all() as $message)
                            <p>⚠ {{ $message }}</p>
                        @endforeach
                    </div>
                @endif

                <div class="mt-3 flex gap-2">
                    <button class="btn-primary btn-sm" wire:click="save">{{ __('common.save') }}</button>
                    <button class="btn-ghost btn-sm" wire:click="toggleAdd">{{ __('common.cancel') }}</button>
                </div>
            </div>
        @endif

        @if ($perPage === 0)
            {{-- 「건수만」 — 행을 안 불러온다. 빈 목록의 「없습니다」와 구분되게 문구를 따로 준다. --}}
            <div class="py-8 text-center text-[13px] text-gray-500">{{ __('listings.list.count_only_hint', ['count' => $this->listings->total()]) }}</div>
        @else
        {{-- 리스트 (데스크톱: 표) --}}
        <div class="hidden overflow-x-auto sm:block">
            {{-- 가운데 정렬(tbl-center). 칸 = 차량 · 출처 · [담당자] · 금액 · 추가검사사항 · 바이어(이름) · 바이어 회신(칩) · 상태 · 검차상태(ssancar 폴러 한 줄) (Jin 2026-10-13)
                 담당자(등록자) = 전체가 보이는 사람(관리·super 등 canSeeAll)에게만(2026-10-23 Jin). 영업은 본인 것만 보여 칸이 뜻이 없다. --}}
            @php $showOwner = auth()->user()->canSeeAll(); @endphp
            <table class="tbl tbl-center">
                <thead>
                    <tr><th class="w-px whitespace-nowrap">{{ __('listings.table.vehicle') }}</th><th>{{ __('listings.table.source') }}</th>@if ($showOwner)<th>{{ __('listings.table.owner') }}</th>@endif<th>{{ __('listings.table.total') }}</th><th>{{ __('listings.table.inspection_request') }}</th><th>{{ __('listings.table.inspection_note') }}</th><th>{{ __('listings.table.buyer') }}</th><th>{{ __('listings.table.verdict') }}</th><th>{{ __('listings.table.status') }}</th><th>{{ __('listings.table.inspection_state') }}</th><th>{{ __('listings.table.invoice') }}</th></tr>
                </thead>
                <tbody>
                    @forelse ($this->listings as $l)
                        <tr class="cursor-pointer hover:bg-gray-50" wire:click="openEdit({{ $l->id }})">
                            <td class="w-px whitespace-nowrap">
                                <div class="font-semibold text-gray-800">{{ \App\Support\Plate::show($l->vehicle_number) }}</div>
                                <div class="text-xs text-gray-400">VIN ·{{ \Illuminate\Support\Str::limit($l->vin, 10, '') }}</div>
                            </td>
                            <td><span class="badge {{ $l->originBadge() }}">{{ $l->originLabel() }}</span></td>
                            @if ($showOwner)<td class="whitespace-nowrap text-gray-700">{{ $l->creator?->name ?? '—' }}</td>@endif
                            <td class="font-semibold {{ $l->final_price ? 'text-[var(--color-primary-text)]' : 'text-gray-400' }}">{{ $l->final_price ? number_format($l->final_price).__('common.won_currency') : '—' }}</td>
                            {{-- 추가 검사 요청(영업 → 검차원) / 검차 메모(검차 결과) — 이름이 헷갈려 두 칸으로 나눴다(2026-10-23 Jin). --}}
                            <td class="max-w-[180px] truncate text-xs text-gray-600" title="{{ $l->inspection_request }}">{{ $l->inspection_request ?: '—' }}</td>
                            <td class="max-w-[180px] truncate text-xs text-gray-500" title="{{ $l->inspection_note }}">{{ $l->inspection_note ?: '—' }}</td>
                            <td class="max-w-[160px] truncate text-gray-700" title="{{ $l->buyerDisplay() }}">{{ $l->buyerDisplay() ?: '—' }}</td>
                            <td>@if ($l->verdictLabel())<span class="badge {{ $l->verdictBadge() }}">{{ $l->verdictLabel() }}</span>@else<span class="text-gray-300">—</span>@endif</td>
                            <td><span class="badge {{ $l->statusBadge() }}">{{ $l->statusLabel() }}</span></td>
                            {{-- 검차상태 — 현지확인대기 차가 왜 아직 전달대기로 안 넘어갔나(ssancar 폴러 마지막 판정). 그 외 상태는 빈 칸. 상태 칸 오른쪽(Jin). --}}
                            <td class="max-w-[260px] whitespace-normal text-[11px] leading-snug text-gray-500">@if ($hold = $l->ssancarHoldHint())@if (! empty($hold['alert']))<span class="font-semibold text-red-600">⚠ {{ __($hold['key'], $hold['params']) }}</span>@else{{ __($hold['key'], $hold['params']) }}@endif @else<span class="text-gray-300">—</span>@endif</td>
                            {{-- Invoice(송금인) — 미입력 / 입력됨 / ERP 전송됨 (2026-10-29 ②, 추가 쿼리 0). --}}
                            @php $inv = $l->invoiceState(); @endphp
                            <td class="whitespace-nowrap"><span class="badge {{ ['none' => 'badge-gray', 'entered' => 'badge-blue', 'synced' => 'badge-green'][$inv] }}">{{ __('listings.invoice_state.'.$inv) }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ $showOwner ? 11 : 10 }}" class="py-8 text-center text-gray-400">{{ __('listings.list.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- 리스트 (모바일: 카드) --}}
        <div class="space-y-2 sm:hidden">
            @forelse ($this->listings as $l)
                <div class="card-tight cursor-pointer" wire:click="openEdit({{ $l->id }})">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <div class="font-semibold text-gray-800">{{ \App\Support\Plate::show($l->vehicle_number) }}</div>
                            <div class="text-xs text-gray-400">VIN ·{{ \Illuminate\Support\Str::limit($l->vin, 12, '') }}</div>
                        </div>
                        <span class="badge {{ $l->originBadge() }} shrink-0">{{ $l->originLabel() }}</span>
                    </div>
                    <div class="mt-2 flex items-center justify-between gap-2">
                        <div class="flex flex-wrap items-center gap-1">
                            <span class="badge {{ $l->statusBadge() }}">{{ $l->statusLabel() }}</span>
                            @if ($l->verdictLabel())<span class="badge {{ $l->verdictBadge() }}">{{ $l->verdictLabel() }}</span>@endif
                            @php $inv = $l->invoiceState(); @endphp<span class="badge {{ ['none' => 'badge-gray', 'entered' => 'badge-blue', 'synced' => 'badge-green'][$inv] }}">📄 {{ __('listings.invoice_state.'.$inv) }}</span>
                            {{-- 바이어는 항상 한 줄(없으면 —) — 모바일에서 「안 보인다」로 읽혔다(2026-10-24 Jin). --}}
                            <span class="min-w-0 max-w-full truncate text-xs text-gray-600">🧑 {{ __('listings.table.buyer') }}: {{ $l->buyerDisplay() ?: '—' }}</span>
                            @if (auth()->user()->canSeeAll() && $l->creator)<span class="truncate text-xs text-gray-500">👤 {{ $l->creator->name }}</span>@endif
                        </div>
                        <span class="shrink-0 text-sm font-semibold {{ $l->final_price ? 'text-[var(--color-primary-text)]' : 'text-gray-400' }}">{{ $l->final_price ? number_format($l->final_price).__('common.won_currency') : '—' }}</span>
                    </div>
                    @if ($l->inspection_request)
                        <div class="mt-1 truncate text-xs text-gray-600" title="{{ $l->inspection_request }}">🔍 {{ __('listings.table.inspection_request') }}: {{ $l->inspection_request }}</div>
                    @endif
                    @if ($l->inspection_note)
                        <div class="mt-1 truncate text-xs text-gray-500" title="{{ $l->inspection_note }}">📝 {{ __('listings.table.inspection_note') }}: {{ $l->inspection_note }}</div>
                    @endif
                    @if ($hold = $l->ssancarHoldHint())
                        {{-- 영업이 손을 써야 하는 문구(보류·지역 미지정)는 빨간 굵은 글씨 + ⚠ — 폰 카드에서 회색 문구와 구분이 안 됐다(2026-10-24 Jin). --}}
                        <div class="mt-1 text-[11px] leading-snug {{ ! empty($hold['alert']) ? 'font-semibold text-red-600' : 'text-gray-400' }}">{{ ! empty($hold['alert']) ? '⚠' : '↳' }} {{ __($hold['key'], $hold['params']) }}</div>
                    @endif
                </div>
            @empty
                <div class="py-8 text-center text-gray-400">{{ __('listings.list.empty') }}</div>
            @endforelse
        </div>
        <div class="mt-3">{{ $this->listings->links() }}</div>
        @endif
        <p class="mt-2 text-xs text-gray-400">{{ __('listings.list.row_hint') }}</p>
    </div>

    {{-- 편집 드로어 (본인 글 수정) — 2026-10-29 ② 탭 4개(기본·검차·첨부·Invoice, Jin 「내용 자체에 각 탭을 나눠서」).
         ⚠️ 비활성 탭도 DOM 에 두고 `hidden` 으로만 가린다 — wire:model 칸이 마운트돼 있어야 검증 에러가 칸에 붙고,
            update() 는 네 탭의 칸을 한 번에 저장한다. 저장·닫기·삭제 버튼과 오류 묶음은 탭 밖(맨 아래) 공통. --}}
    @if ($this->editing)
        @php $e = $this->editing; $canEdit = $this->editable($e); @endphp
        <div class="fixed inset-0 z-40 bg-black/40" wire:click="closeEdit"></div>
        <div class="fixed inset-y-0 right-0 z-50 w-full overflow-y-auto bg-white shadow-xl sm:w-[440px]">
            <div class="flex items-center justify-between border-b border-gray-200 px-5 py-4">
                <h3 class="font-bold text-gray-800">{{ __('listings.drawer.title', ['number' => \App\Support\Plate::show($e->vehicle_number)]) }}</h3>
                <button class="text-gray-400 hover:text-gray-600" wire:click="closeEdit">✕</button>
            </div>
            <div class="px-5 py-4">
                <div class="card-sm mb-3 bg-gray-50 text-xs text-gray-500">
                    {{ __('listings.add_form.vehicle_number') }} <b>{{ \App\Support\Plate::show($e->vehicle_number) }}</b> · VIN <b>{{ $e->vin }}</b>
                    · <span class="badge {{ $e->originBadge() }}">{{ $e->originLabel() }}</span>
                    <span class="text-gray-400">({{ $e->isAuction() ? __('listings.drawer.method_auction') : __('listings.drawer.method_encar') }})</span><br>
                    <span class="text-gray-400">{{ __('listings.drawer.summary_locked') }}</span>
                </div>

                @unless ($canEdit)
                    <div class="card-sm mb-3 border-amber-200 bg-amber-50 text-[13px] text-amber-800">{{ __('listings.drawer.locked_notice') }}</div>
                @endunless

                {{-- 탭 pill — 폰은 가로 스크롤. Invoice 미입력이면 빨간 점. --}}
                <div class="-mx-1 mb-3 flex gap-1 overflow-x-auto px-1 pb-1" role="tablist">
                    @foreach (['basic', 'inspection', 'attach', 'invoice'] as $t)
                        <button type="button" role="tab" wire:click="$set('drawerTab', '{{ $t }}')" aria-selected="{{ $drawerTab === $t ? 'true' : 'false' }}"
                            class="shrink-0 whitespace-nowrap rounded-md border px-3 py-1.5 text-[12px] font-semibold {{ $drawerTab === $t ? 'border-[var(--color-primary)] bg-[var(--color-primary)] text-white' : 'border-gray-300 bg-white text-gray-600' }}">
                            {{ __('listings.drawer.tab.'.$t) }}@if ($t === 'invoice' && $e->invoiceState() === 'none')<span class="ml-1 inline-block h-1.5 w-1.5 rounded-full bg-red-500 align-middle" title="{{ __('listings.invoice_state.none') }}"></span>@endif
                        </button>
                    @endforeach
                </div>

                {{-- ① 기본 — 차량·출처·링크·검차 요청사항 + 금액 다시 보내기(금액 계열) + 진행 정보 --}}
                <div class="{{ $drawerTab === 'basic' ? '' : 'hidden' }}" role="tabpanel">
                    <div class="card-sm mb-3 border-blue-100 bg-blue-50/50 text-[11px] text-gray-500">{{ __('listings.drawer.money_moved') }}</div>

                    {{-- 급해서 매입가만 보낸 차의 판매 금액 보완 + ERP 재전송(이미 연동된 차만). --}}
                    @include('livewire.listings._erp-resend')

                    <label class="label-base mt-3">{{ __('listings.add_form.region') }}</label>
                    <x-region-input model="e_region" :placeholder="__('listings.add_form.region_ph')" :disabled="! $canEdit" />
                    @error('e_region') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                    <label class="label-base mt-3">{{ __('listings.add_form.owner') }} <span class="text-gray-400">{{ __('listings.add_form.owner_hint') }}</span></label>
                    <input class="input-base" wire:model="e_owner_name" placeholder="{{ __('listings.add_form.owner_ph') }}" maxlength="60" @unless ($canEdit) disabled @endunless>
                    @error('e_owner_name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                    @if (config('services.respond_io.api_token'))
                        <label class="label-base mt-3">{{ __('listings.drawer.contact_label') }} <span class="text-gray-400">{{ __('listings.drawer.contact_hint') }}</span></label>
                        <input class="input-base" wire:model="e_respond_contact_id" placeholder="{{ __('listings.drawer.contact_ph') }}" @unless ($canEdit) disabled @endunless>
                        @error('e_respond_contact_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    @endif
                    @if ($e->encar_id || $e->c_no || $e->ssancar_ref)
                        <div class="mt-1 flex flex-wrap gap-1.5 text-[11px]">
                            <span class="text-gray-400">{{ __('listings.drawer.origin_prefix') }}</span>
                            @if ($e->encar_id)<span class="badge badge-encar">encar #{{ $e->encar_id }}</span>@endif
                            @if ($e->c_no)<span class="badge badge-blue">c_no {{ $e->c_no }}</span>@endif
                            @if ($e->ssancar_ref)<span class="badge badge-gray">{{ $e->ssancar_ref }}</span>@endif
                        </div>
                    @endif

                    @if ($e->source === 'encar')
                        <label class="label-base mt-3">{{ __('listings.drawer.encar_url') }}</label>
                        <input class="input-base" wire:model="e_encar_url" placeholder="{{ __('listings.drawer.encar_url_ph') }}" @unless ($canEdit) disabled @endunless>
                        <label class="label-base mt-3">{{ __('listings.add_form.c_no') }} <span class="text-gray-400">{{ __('listings.add_form.c_no_hint') }}</span></label>
                        <input class="input-base" wire:model="e_c_no" placeholder="{{ __('listings.drawer.c_no_ph') }}" @unless ($canEdit) disabled @endunless>
                        @error('e_c_no') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    @else
                        <label class="label-base mt-3">{{ __('listings.add_form.auction_venue') }}</label>
                        <input class="input-base" wire:model="e_auction_venue" @unless ($canEdit) disabled @endunless>
                        <label class="label-base mt-3">{{ __('listings.add_form.lot_number') }}</label>
                        <input class="input-base" wire:model="e_lot_number" @unless ($canEdit) disabled @endunless>
                    @endif

                    @if ($e->origin !== \App\Models\PurchaseListing::ORIGIN_SELF_INSPECTION)
                        <label class="label-base mt-3">{{ __('listings.add_form.inspection_request') }} <span class="text-gray-400">{{ __('listings.add_form.inspection_request_hint') }}</span></label>
                        <input class="input-base" wire:model="e_inspection_request" maxlength="255" placeholder="{{ __('listings.add_form.inspection_request_ph') }}" @unless ($canEdit) disabled @endunless>
                        @error('e_inspection_request') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    @endif

                    {{-- 읽기전용 진행 정보 (현지확인·경매에서 채워짐) --}}
                    <div class="mt-4 grid grid-cols-2 gap-3 text-xs text-gray-500">
                        <div>{{ __('listings.drawer.local_total') }}<br><b class="text-sm text-gray-800">{{ $e->final_price ? number_format($e->final_price).__('common.won_currency') : __('listings.drawer.local_total_pending') }}</b></div>
                        <div>{{ __('listings.drawer.status') }}<br><span class="badge {{ $e->statusBadge() }}">{{ $e->statusLabel() }}</span></div>
                        <div>{{ __('listings.drawer.buyer') }}<br>@if ($e->verdictLabel())<span class="badge {{ $e->verdictBadge() }}">{{ $e->verdictLabel() }}</span>@else<span class="text-gray-300">—</span>@endif</div>
                        <div>{{ __('listings.drawer.buyer_name') }}<br><b class="text-gray-800">{{ $e->buyer_name ?: '—' }}</b></div>
                    </div>
                </div>

                {{-- ② 검차 — 현지확인대기 차가 왜 아직 안 넘어갔나(폴러 마지막 판정) + 사진만으로 넘기기 + 검차 결과 메모(읽기) --}}
                <div class="{{ $drawerTab === 'inspection' ? '' : 'hidden' }}" role="tabpanel">
                    @if ($e->status === 'draft' && ($hold = $e->ssancarHoldHint()))
                        <div class="card-sm border-gray-200 bg-gray-50 text-[12px] text-gray-600">
                            <div class="{{ ! empty($hold['alert']) ? 'font-semibold text-red-600' : '' }}">{{ ! empty($hold['alert']) ? '⚠' : '🔎' }} {{ __($hold['key'], $hold['params']) }}</div>
                            @if ($e->inspection_skip_photo_url)
                                <a href="{{ $e->inspection_skip_photo_url }}" target="_blank" rel="noopener" class="mt-1 inline-block text-[11px] text-blue-600 underline">{{ __('listings.drawer.skip_photo') }}</a>
                            @endif
                            @if ($this->reinspectable($e))
                                <button type="button" class="btn-outline btn-sm mt-2 w-full justify-center"
                                    wire:click="requestReinspection" wire:confirm="{{ __('listings.drawer.reinspect_confirm') }}">
                                    🔁 {{ __('listings.drawer.reinspect_btn') }}
                                </button>
                            @endif
                            @if ($this->forwardable($e))
                                <button type="button" class="btn-outline btn-sm mt-2 w-full justify-center"
                                    wire:click="forwardWithPhotos" wire:confirm="{{ __('listings.drawer.forward_photos_confirm') }}">
                                    📨 {{ __('listings.drawer.forward_photos_btn') }}
                                </button>
                            @elseif ($canEdit && ! $e->hasAnyForwardMedia())
                                <div class="mt-1 text-[11px] text-gray-400">{{ __('listings.drawer.forward_no_media') }}</div>
                            @endif
                        </div>
                    @else
                        <p class="text-[12px] text-gray-500">{{ __('listings.drawer.inspection_past', ['status' => $e->statusLabel()]) }}</p>
                    @endif
                    @if ($e->inspection_note)
                        <div class="mt-3 text-[11px] text-gray-400">{{ __('listings.table.inspection_note') }}</div>
                        <div class="text-[13px] text-gray-700">{{ $e->inspection_note }}</div>
                    @endif
                </div>

                {{-- ③ 첨부 — 구매·경매에서 올린 딜러 첨부(전 상태 — synced 포함). 읽기 + 추가. --}}
                <div class="{{ $drawerTab === 'attach' ? '' : 'hidden' }}" role="tabpanel">
                    @include('livewire.listings._sales-attachments')
                </div>

                {{-- ④ Invoice — 송금인(여권/ID) Name·문서번호 → ERP · COMMERCIAL INVOICE --}}
                <div class="{{ $drawerTab === 'invoice' ? '' : 'hidden' }}" role="tabpanel">
                    @include('livewire.listings._invoice')
                </div>

                @if ($errors->any())
                    <div class="card-sm mt-4 border-red-200 bg-red-50 text-[13px] text-red-700">
                        @foreach ($errors->all() as $message)
                            <p>⚠ {{ $message }}</p>
                        @endforeach
                    </div>
                @endif

                <div class="mt-5 flex gap-2">
                    @if ($canEdit)
                        <button class="btn-primary flex-1 justify-center" wire:click="update">{{ __('common.save') }}</button>
                    @endif
                    <button class="btn-ghost" wire:click="closeEdit">{{ $canEdit ? __('common.cancel') : __('common.close') }}</button>
                </div>

                @if ($this->deletable($e))
                    <button class="btn-ghost mt-2 w-full justify-center text-red-600 hover:bg-red-50"
                        wire:click="deleteListing" wire:confirm="{{ __('listings.drawer.delete_confirm') }}">🗑️ {{ __('listings.drawer.delete') }}</button>
                @endif
            </div>
        </div>
    @endif
</div>

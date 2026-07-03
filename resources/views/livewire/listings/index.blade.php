<?php

use App\Models\InspectionPhoto;
use App\Models\PromotionRequest;
use App\Models\PurchaseListing;
use App\Services\ExchangeRateService;
use App\Support\TimeGate;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('components.layouts.app')] class extends Component {
    use WithFileUploads;

    public bool $showAdd = false;

    // 차량 첨부 (영업 업로드 → 연동 B 로 car-erp 첨부탭). 첨부파일 1칸 — 이미지=사진/그 외=서류 자동분류.
    public array $salesFiles = [];         // 추가 폼
    public array $eSalesFiles = [];        // 편집 드로어

    public string $origin = 'encar';        // 유입 카테고리(화면) — source 는 여기서 도출
    public string $source = 'encar';        // 매입방법(내부) — 워크플로·연동B
    public string $vehicle_number = '';
    public string $owner_name = '';         // 소유자/차주명 (연동 B: car-erp NICE 조회 입력값)
    public string $vin = '';
    public string $region = '';             // 지역 (검사지역, 자동완성)
    public string $c_no = '';               // 매물번호 (ssancar c_no, 조인키)
    public string $ssancar_ref = '';        // ssancar wr_id/car_no (비-c_no, "wr_id:786")
    public string $encar_id = '';           // Encar 차 식별 (URL 자동추출)
    public string $respond_contact_id = ''; // respond.io 대화 연결(스파인, 영업이 채팅에서 복사)
    public string $encarLink = '';          // 엔카 링크 → JSON API enrich
    public string $ssancarLink = '';        // ssancar 링크 → 페이지 파싱 enrich
    public ?int $promotingId = null;        // 승격 대기에서 시작한 경우 — 저장 시 consume
    public ?string $expected_price = null;  // 매물 표시가 (enrichment 자동채움 · 표시용, KRW 값은 car_cost 에도 자동 매핑)
    public string $expected_price_currency = 'KRW';   // 매물 표시가 통화 (원/미/유로 토글)
    public array $priceOptions = [];        // enrichment 통화별 금액 {KRW,USD,EUR} — 토글 시 금액 변경
    public ?string $car_cost = null;        // 차값 (KRW)
    public ?string $discount_rate = null;   // 할인율 (%)
    public ?int $shipping_usd = null;       // 배송금액 (USD 고정 택1)
    public string $encar_url = '';
    public string $encar_dealer = '';
    public string $auction_venue = '';
    public string $lot_number = '';
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

    /** 차량금액(KRW) = 차값(통화 KRW환산) − (×할인율%) + 매도비(고정). $cur=차값 통화(엔카=KRW). */
    public function calcCarPrice($cost, $rate, string $cur = 'KRW'): ?int
    {
        $krw = \App\Support\Money::toKrw($cost, $cur, $this->usdRate(), $this->eurRate());
        if ($krw === null) {
            return null;
        }
        $discount = (int) round($krw * ((float) $rate / 100));

        return $krw - $discount + (int) config('board.sales_fee');
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

    #[Computed]
    public function listings()
    {
        return PurchaseListing::with('creator')->latest()->get();
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
        $this->reset(['eSalesFiles']);
        $this->resetErrorBag();
    }

    public function closeEdit(): void
    {
        $this->reset(['editingId', 'e_region', 'e_c_no', 'e_respond_contact_id', 'e_owner_name', 'e_payee_name', 'e_payee_bank', 'e_payee_account', 'e_selling_fee_payee_name', 'e_selling_fee_payee_bank', 'e_selling_fee_payee_account', 'e_car_cost', 'e_discount_rate', 'e_shipping_usd', 'e_encar_url', 'e_encar_dealer', 'e_auction_venue', 'e_lot_number', 'eSalesFiles']);
        unset($this->editing);
    }

    public function update(): void
    {
        $l = PurchaseListing::findOrFail($this->editingId);

        if (! $this->editable($l)) {
            $this->addError('e_car_cost', __('listings.drawer.locked_error'));

            return;
        }

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
            'eSalesFiles.*' => 'file|max:204800',
        ]);

        if (! $this->checkSalesFiles($this->eSalesFiles, $l->salesAttachments()->count(), 'eSalesFiles')) {
            return;
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
        $l->final_price = $l->totalKrw($this->usdRate(), $this->eurRate()) ?? $l->final_price;
        if ($l->source === 'encar') {
            $l->encar_url = $this->e_encar_url ?: null;
            $l->encar_dealer = $this->e_encar_dealer ?: null;
        } else {
            $l->auction_venue = $this->e_auction_venue ?: null;
            $l->lot_number = $this->e_lot_number ?: null;
        }
        $l->save();
        $this->storeSalesFiles($l, $this->eSalesFiles);

        unset($this->listings);
        session()->flash('ok', __('listings.drawer.updated_flash', ['number' => $l->vehicle_number]));
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
    public function parseLink(string $which = 'encar'): void
    {
        $field = $which.'Link';
        $url = trim($which === 'ssancar' ? $this->ssancarLink : $this->encarLink);
        if ($url === '') {
            return;
        }
        $r = \App\Support\ListingLink::parse($url);

        if ($r === []) {
            $this->addError($field, __('listings.links.parse_error'));

            return;
        }

        $this->resetErrorBag($field);
        foreach (['origin', 'source', 'encar_id', 'encar_url', 'c_no', 'ssancar_ref'] as $k) {
            if (isset($r[$k])) {
                $this->{$k} = $r[$k];
            }
        }

        // 매물 자동채움(enrichment) — 빈 칸만 prefill, 영업이 확인 후 저장(IDENTITY_LOCKED 자동확정 금지).
        $e = app(\App\Services\ListingEnrichment::class)->enrich($r, $url);
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
            'salesFiles.*' => 'file|max:204800',
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
            'status' => 'draft',
            'buyer_verdict' => 'none',
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
        unset($this->listings);
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

    /** 첨부 저장 — 이미지=사진(sales_photo)/그 외=서류(sales_document) 자동분류. 서류는 바이어 전송 금지(§28). */
    private function storeSalesFiles(PurchaseListing $l, array $files): void
    {
        $files = array_values(array_filter($files));
        if (empty($files)) {
            return;
        }

        $disk = config('board.photo_disk');
        $sort = (int) $l->salesAttachments()->max('sort');

        foreach ($files as $f) {
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
        $this->reset(['vehicle_number', 'owner_name', 'vin', 'region', 'c_no', 'ssancar_ref', 'encar_id', 'respond_contact_id', 'encarLink', 'ssancarLink', 'promotingId', 'expected_price', 'expected_price_currency', 'priceOptions', 'payee_name', 'payee_bank', 'payee_account', 'selling_fee_payee_name', 'selling_fee_payee_bank', 'selling_fee_payee_account', 'car_cost', 'discount_rate', 'shipping_usd', 'encar_url', 'encar_dealer', 'auction_venue', 'lot_number', 'salesFiles']);
        $this->origin = 'encar';
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
            <h2 class="font-bold text-gray-800">{{ __('listings.list.heading') }} <span class="text-gray-400">· {{ __('listings.list.count', ['count' => $this->listings->count()]) }}</span></h2>
            <button class="btn-primary" wire:click="toggleAdd">{{ __('listings.list.add') }}</button>
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
                <p class="mb-3 text-[11px] text-gray-400">{{ __('listings.add_form.method_prefix') }}<b>{{ $source === 'auction' ? __('listings.add_form.method_auction') : __('listings.add_form.method_encar') }}</b>{{ __('listings.add_form.method_suffix') }}</p>
                @error('origin') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                {{-- 승격: 링크 붙여넣기 → 식별자 자동추출 + 자동채움. 엔카/ssancar 분리(둘 다 넣으면 합쳐서 채움). --}}
                <div class="card-sm mb-3" style="background:#f0f7ff;border-color:#dbeafe">
                    <label class="label-base">{{ __('listings.links.encar_label') }} <span class="text-gray-400">{{ __('listings.links.encar_hint') }}</span></label>
                    <div class="flex gap-2">
                        <input class="input-base flex-1" wire:model="encarLink" wire:keydown.enter.prevent="parseLink('encar')"
                               placeholder="{{ __('listings.links.encar_ph') }}">
                        <button type="button" class="btn-primary btn-sm shrink-0" wire:click="parseLink('encar')">{{ __('listings.links.extract') }}</button>
                    </div>
                    @error('encarLink') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                    <label class="label-base mt-2">{{ __('listings.links.ssancar_label') }} <span class="text-gray-400">{{ __('listings.links.ssancar_hint') }}</span></label>
                    <div class="flex gap-2">
                        <input class="input-base flex-1" wire:model="ssancarLink" wire:keydown.enter.prevent="parseLink('ssancar')"
                               placeholder="{{ __('listings.links.ssancar_ph') }}">
                        <button type="button" class="btn-primary btn-sm shrink-0" wire:click="parseLink('ssancar')">{{ __('listings.links.extract') }}</button>
                    </div>
                    @error('ssancarLink') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    @if ($encar_id || $c_no || $ssancar_ref)
                        <div class="mt-2 flex flex-wrap items-center gap-1.5 text-[11px]">
                            <span class="text-gray-400">{{ __('listings.links.extracted') }}</span>
                            @if ($encar_id)<span class="badge badge-encar">encar #{{ $encar_id }}</span>@endif
                            @if ($c_no)<span class="badge badge-blue">c_no {{ $c_no }}</span>@endif
                            @if ($ssancar_ref)<span class="badge badge-gray">{{ $ssancar_ref }}</span>@endif
                        </div>
                    @endif
                    <label class="label-base mt-2">{{ __('listings.links.price_label') }} <span class="text-gray-400">{{ __('listings.links.price_hint') }}</span></label>
                    <div class="flex gap-2">
                        <input class="input-base flex-1" wire:model="expected_price" inputmode="numeric" placeholder="{{ __('listings.links.price_ph') }}">
                        <div class="inline-flex shrink-0 overflow-hidden rounded-md border border-gray-300">
                            @foreach (['KRW' => '원', 'USD' => '$', 'EUR' => '€'] as $cur => $sym)
                                {{-- 추출된 통화만 활성 — 엔카=원화만, ssancar=3통화. 링크 전(빈 priceOptions)엔 전부 허용. --}}
                                @php $curOff = ! empty($priceOptions) && ! isset($priceOptions[$cur]); @endphp
                                <button type="button" wire:click="pickCurrency('{{ $cur }}')" @disabled($curOff)
                                    class="px-2.5 py-1.5 text-[13px] font-semibold {{ $expected_price_currency === $cur ? 'bg-[var(--color-primary)] text-white' : 'bg-white text-gray-600' }} {{ $curOff ? 'cursor-not-allowed opacity-40' : '' }}">{{ $sym }}</button>
                            @endforeach
                        </div>
                    </div>
                    @if ($priceOptions)
                        <p class="mt-1 text-[11px] text-gray-500">{{ __('listings.links.price_options_prefix') }}@foreach ($priceOptions as $cur => $amt)<span class="mr-2 whitespace-nowrap">{{ ['KRW' => '원', 'USD' => '$', 'EUR' => '€'][$cur] ?? $cur }} {{ number_format($amt) }}</span>@endforeach{{ __('listings.links.price_options_suffix') }}</p>
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

                {{-- 차량번호 · 차값 · 할인율 · 지역 (한 행) --}}
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
                        <label class="label-base">{{ __('listings.add_form.car_cost') }} ({{ \App\Support\Money::SYMBOLS[$expected_price_currency] ?? '원' }})</label>
                        <input class="input-base" wire:model.live.debounce.400ms="car_cost" inputmode="numeric" placeholder="{{ __('listings.add_form.car_cost_ph') }}">
                        @error('car_cost') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label-base">{{ __('listings.add_form.discount_rate') }}</label>
                        <input class="input-base" wire:model.live.debounce.400ms="discount_rate" inputmode="decimal" placeholder="{{ __('listings.add_form.discount_rate_ph') }}">
                        @error('discount_rate') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label-base">{{ __('listings.add_form.region') }}</label>
                        <input class="input-base" wire:model="region" list="regionList" placeholder="{{ __('listings.add_form.region_ph') }}">
                        @error('region') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label-base">{{ __('listings.add_form.c_no') }} <span class="text-gray-400">{{ __('listings.add_form.c_no_hint') }}</span></label>
                        <input class="input-base" wire:model="c_no" placeholder="{{ __('listings.add_form.c_no_ph') }}">
                        @error('c_no') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>
                <datalist id="regionList">
                    @foreach (config('board.regions') as $r)<option value="{{ $r }}">@endforeach
                </datalist>

                {{-- 경매 전용 식별 정보 --}}
                @if ($source === 'auction')
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <div><label class="label-base">{{ __('listings.add_form.auction_venue') }}</label><input class="input-base" wire:model="auction_venue" placeholder="{{ __('listings.add_form.auction_venue_ph') }}"></div>
                        <div><label class="label-base">{{ __('listings.add_form.lot_number') }}</label><input class="input-base" wire:model="lot_number" placeholder="{{ __('listings.add_form.lot_number_ph') }}"></div>
                    </div>
                @endif

                {{-- 금액 산정 (§6) --}}
                @php
                    $carPrice = $this->calcCarPrice($car_cost, $discount_rate, $expected_price_currency);
                    $total = $this->calcTotal($car_cost, $discount_rate, $shipping_usd, $expected_price_currency);
                    $shipKrw = $shipping_usd ? (int) $shipping_usd * $this->usdRate() : null;
                @endphp
                <div class="mt-3 flex items-center justify-between">
                    <span class="text-xs font-semibold text-gray-600">{{ __('listings.pricing.heading') }}</span>
                    <div class="inline-flex overflow-hidden rounded-md border border-gray-300 text-xs">
                        @foreach (['KRW' => '원', 'USD' => '$', 'EUR' => '€'] as $cur => $sym)
                            <button type="button" wire:click="$set('displayCurrency', '{{ $cur }}')"
                                class="px-2 py-1 font-semibold {{ $displayCurrency === $cur ? 'bg-[var(--color-primary)] text-white' : 'bg-white text-gray-600' }}">{{ $sym }}</button>
                        @endforeach
                    </div>
                </div>
                <div class="mt-1 flex items-center justify-between text-xs text-gray-500">
                    <span>{{ __('listings.pricing.sales_fee') }}</span><span class="font-semibold text-gray-700">{{ number_format((int) config('board.sales_fee')) }}원</span>
                </div>
                <div class="mt-1 flex items-center justify-between rounded-md bg-gray-50 px-3 py-2 text-sm">
                    <span class="text-gray-600">{{ __('listings.pricing.car_price') }}</span>
                    <span class="font-bold text-gray-800">{{ $this->fmt($carPrice) }}</span>
                </div>
                <label class="label-base mt-3">{{ __('listings.pricing.shipping_label') }}</label>
                <div class="inline-flex overflow-hidden rounded-md border border-gray-300">
                    @foreach (config('board.shipping_options') as $opt)
                        <button type="button" wire:click="$set('shipping_usd', {{ $opt }})"
                            class="px-3 py-1.5 text-[13px] font-semibold {{ (int) $shipping_usd === $opt ? 'bg-[var(--color-primary)] text-white' : 'bg-white text-gray-600' }}">${{ number_format($opt) }}</button>
                    @endforeach
                </div>
                @error('shipping_usd') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                @if ($shipKrw !== null)<div class="mt-1 text-right text-xs text-gray-500">{{ __('listings.pricing.shipping_prefix') }}{{ $this->fmt($shipKrw) }}</div>@endif
                <div class="mt-2 flex items-center justify-between rounded-md border border-[var(--color-primary)] bg-[#f5f8ff] px-3 py-2.5">
                    <span class="text-sm font-semibold text-gray-700">{{ __('listings.pricing.total') }}</span>
                    <span class="text-base font-bold text-[var(--color-primary-text)]">{{ $this->fmt($total) }}</span>
                </div>

                {{-- 입금정보 (선택 — 알면 미리, 모르면 구매단계에서) §6e · car-erp 형식(은행 자동완성 + 계좌 마스킹) --}}
                <label class="label-base mt-3">{{ __('listings.payee.label') }} <span class="text-gray-400">{{ __('listings.payee.hint') }}</span></label>
                <div x-data class="grid gap-2 sm:grid-cols-3">
                    <div>
                        <input x-ref="bankAdd" wire:model.blur="payee_bank" list="korean-banks-add" autocomplete="off"
                               class="input-base" placeholder="{{ __('listings.payee.bank_ph') }}" maxlength="100"
                               x-on:input="$refs.acctAdd.value = $store.koreanBanks.applyMask($el.value, $refs.acctAdd.value)">
                        <datalist id="korean-banks-add"><template x-for="b in $store.koreanBanks.names()" :key="b"><option :value="b"></option></template></datalist>
                    </div>
                    <div><input wire:model.blur="payee_name" class="input-base" placeholder="{{ __('listings.payee.name_ph') }}" maxlength="60"></div>
                    <div><input x-ref="acctAdd" wire:model.blur="payee_account" autocomplete="off"
                               class="input-base font-mono" placeholder="{{ __('listings.payee.account_ph') }}"
                               x-on:input="$el.value = $store.koreanBanks.applyMask($refs.bankAdd.value, $el.value)"></div>
                </div>
                @error('payee_account') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                <p class="mt-1 text-[11px] text-gray-400">{{ __('listings.payee.help') }}</p>

                {{-- 매도비 계좌 (선택 · 판매자와 다른 대상) — 매입가 계좌와 별개 --}}
                <label class="label-base mt-3">{{ __('listings.selling_fee_payee.label') }} <span class="text-gray-400">{{ __('listings.selling_fee_payee.hint') }}</span></label>
                <div x-data class="grid gap-2 sm:grid-cols-3">
                    <div>
                        <input x-ref="feeBankAdd" wire:model.blur="selling_fee_payee_bank" list="korean-banks-fee-add" autocomplete="off"
                               class="input-base" placeholder="{{ __('listings.payee.bank_ph') }}" maxlength="100"
                               x-on:input="$refs.feeAcctAdd.value = $store.koreanBanks.applyMask($el.value, $refs.feeAcctAdd.value)">
                        <datalist id="korean-banks-fee-add"><template x-for="b in $store.koreanBanks.names()" :key="b"><option :value="b"></option></template></datalist>
                    </div>
                    <div><input wire:model.blur="selling_fee_payee_name" class="input-base" placeholder="{{ __('listings.payee.name_ph') }}" maxlength="60"></div>
                    <div><input x-ref="feeAcctAdd" wire:model.blur="selling_fee_payee_account" autocomplete="off"
                               class="input-base font-mono" placeholder="{{ __('listings.payee.account_ph') }}"
                               x-on:input="$el.value = $store.koreanBanks.applyMask($refs.feeBankAdd.value, $el.value)"></div>
                </div>
                @error('selling_fee_payee_account') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                <p class="mt-1 text-[11px] text-gray-400">{{ __('listings.selling_fee_payee.help') }}</p>

                {{-- 차량 첨부 (영업 자료 → 낙찰 시 연동 B 로 car-erp 첨부탭) · 첨부파일 1칸 통합 --}}
                <label class="label-base mt-3">{{ __('listings.attach.add_label') }} <span class="text-gray-400">{{ __('listings.attach.add_hint', ['max' => config('board.attachment_max')]) }}</span></label>
                <label class="flex cursor-pointer items-center justify-center rounded-lg border-2 border-dashed border-gray-300 py-3 text-[13px] text-gray-500 hover:border-[var(--color-primary)]">
                    {{ __('listings.attach.dropzone') }}
                    <input type="file" multiple wire:model="salesFiles" class="hidden">
                </label>
                <div wire:loading wire:target="salesFiles" class="mt-1 text-xs text-gray-400">{{ __('listings.attach.uploading') }}</div>
                @if (count($salesFiles))
                    <div class="mt-2 grid grid-cols-4 gap-2 sm:grid-cols-6">
                        @foreach ($salesFiles as $i => $f)
                            <div class="relative overflow-hidden rounded-md border border-gray-200" wire:key="newfile-{{ $i }}">
                                @if ($f->isPreviewable() && str_starts_with((string) $f->getMimeType(), 'image/'))
                                    <img src="{{ $f->temporaryUrl() }}" class="aspect-square w-full object-cover" alt="">
                                @else
                                    <div class="flex aspect-square w-full flex-col items-center justify-center bg-gray-50 p-1 text-center text-[10px] text-gray-500">
                                        <span class="text-lg">📄</span><span class="line-clamp-2 break-all">{{ $f->getClientOriginalName() }}</span>
                                    </div>
                                @endif
                                <button type="button" wire:click="removeSalesFile({{ $i }})"
                                    class="absolute right-0.5 top-0.5 rounded bg-black/55 px-1 text-[10px] font-semibold text-white hover:bg-red-600">✕</button>
                            </div>
                        @endforeach
                    </div>
                    <p class="mt-1 text-[11px] text-gray-500">{{ __('listings.attach.selected', ['count' => count($salesFiles)]) }}</p>
                @endif
                @error('salesFiles') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                @error('salesFiles.*') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                <p class="mt-1 text-[11px] text-gray-400">{{ __('listings.attach.help') }}</p>

                <p class="mt-2 text-xs text-gray-500">{!! __('listings.add_form.note') !!}</p>
                @error('source') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                <div class="mt-3 flex gap-2">
                    <button class="btn-primary btn-sm" wire:click="save">{{ __('common.save') }}</button>
                    <button class="btn-ghost btn-sm" wire:click="toggleAdd">{{ __('common.cancel') }}</button>
                </div>
            </div>
        @endif

        {{-- 리스트 (데스크톱: 표) --}}
        <div class="hidden overflow-x-auto sm:block">
            <table class="tbl">
                <thead>
                    <tr><th class="w-px whitespace-nowrap">{{ __('listings.table.vehicle') }}</th><th>{{ __('listings.table.source') }}</th><th>{{ __('listings.table.total') }}</th><th>{{ __('listings.table.inspection_note') }}</th><th>{{ __('listings.table.buyer') }}</th><th>{{ __('listings.table.status') }}</th></tr>
                </thead>
                <tbody>
                    @forelse ($this->listings as $l)
                        <tr class="cursor-pointer hover:bg-gray-50" wire:click="openEdit({{ $l->id }})">
                            <td class="w-px whitespace-nowrap">
                                <div class="font-semibold text-gray-800">{{ $l->vehicle_number }}</div>
                                <div class="text-xs text-gray-400">VIN ·{{ \Illuminate\Support\Str::limit($l->vin, 10, '') }}</div>
                            </td>
                            <td><span class="badge {{ $l->originBadge() }}">{{ $l->originLabel() }}</span></td>
                            <td class="font-semibold {{ $l->final_price ? 'text-[var(--color-primary-text)]' : 'text-gray-400' }}">{{ $l->final_price ? number_format($l->final_price).__('common.won_currency') : '—' }}</td>
                            <td class="max-w-[200px] truncate text-xs text-gray-500" title="{{ $l->inspection_note }}">{{ $l->inspection_note ?: '—' }}</td>
                            <td>@if ($l->verdictLabel())<span class="badge {{ $l->verdictBadge() }}">{{ $l->verdictLabel() }}</span>@else<span class="text-gray-300">—</span>@endif</td>
                            <td><span class="badge {{ $l->statusBadge() }}">{{ $l->statusLabel() }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-8 text-center text-gray-400">{{ __('listings.list.empty') }}</td></tr>
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
                            <div class="font-semibold text-gray-800">{{ $l->vehicle_number }}</div>
                            <div class="text-xs text-gray-400">VIN ·{{ \Illuminate\Support\Str::limit($l->vin, 12, '') }}</div>
                        </div>
                        <span class="badge {{ $l->originBadge() }} shrink-0">{{ $l->originLabel() }}</span>
                    </div>
                    <div class="mt-2 flex items-center justify-between gap-2">
                        <div class="flex flex-wrap items-center gap-1">
                            <span class="badge {{ $l->statusBadge() }}">{{ $l->statusLabel() }}</span>
                            @if ($l->verdictLabel())<span class="badge {{ $l->verdictBadge() }}">{{ $l->verdictLabel() }}</span>@endif
                        </div>
                        <span class="shrink-0 text-sm font-semibold {{ $l->final_price ? 'text-[var(--color-primary-text)]' : 'text-gray-400' }}">{{ $l->final_price ? number_format($l->final_price).__('common.won_currency') : '—' }}</span>
                    </div>
                    @if ($l->inspection_note)
                        <div class="mt-1 truncate text-xs text-gray-500" title="{{ $l->inspection_note }}">📝 {{ $l->inspection_note }}</div>
                    @endif
                </div>
            @empty
                <div class="py-8 text-center text-gray-400">{{ __('listings.list.empty') }}</div>
            @endforelse
        </div>
        <p class="mt-2 text-xs text-gray-400">{{ __('listings.list.row_hint') }}</p>
    </div>

    {{-- 편집 드로어 (본인 글 수정) --}}
    @if ($this->editing)
        @php $e = $this->editing; $canEdit = $this->editable($e); @endphp
        <div class="fixed inset-0 z-40 bg-black/40" wire:click="closeEdit"></div>
        <div class="fixed inset-y-0 right-0 z-50 w-full overflow-y-auto bg-white shadow-xl sm:w-[440px]">
            <div class="flex items-center justify-between border-b border-gray-200 px-5 py-4">
                <h3 class="font-bold text-gray-800">{{ __('listings.drawer.title', ['number' => $e->vehicle_number]) }}</h3>
                <button class="text-gray-400 hover:text-gray-600" wire:click="closeEdit">✕</button>
            </div>
            <div class="px-5 py-4">
                <div class="card-sm mb-3 bg-gray-50 text-xs text-gray-500">
                    {{ __('listings.add_form.vehicle_number') }} <b>{{ $e->vehicle_number }}</b> · VIN <b>{{ $e->vin }}</b>
                    · <span class="badge {{ $e->originBadge() }}">{{ $e->originLabel() }}</span>
                    <span class="text-gray-400">({{ $e->isAuction() ? __('listings.drawer.method_auction') : __('listings.drawer.method_encar') }})</span><br>
                    <span class="text-gray-400">{{ __('listings.drawer.summary_locked') }}</span>
                </div>

                @unless ($canEdit)
                    <div class="card-sm mb-3 border-amber-200 bg-amber-50 text-[13px] text-amber-800">{{ __('listings.drawer.locked_notice') }}</div>
                @endunless

                {{-- 금액 산정 (§6) --}}
                @php
                    $eCar = $this->calcCarPrice($e_car_cost, $e_discount_rate, $e->expected_price_currency);
                    $eTotal = $this->calcTotal($e_car_cost, $e_discount_rate, $e_shipping_usd, $e->expected_price_currency);
                    $eShipKrw = $e_shipping_usd ? (int) $e_shipping_usd * $this->usdRate() : null;
                @endphp
                <div class="mb-2 flex justify-end">
                    <div class="inline-flex overflow-hidden rounded-md border border-gray-300 text-xs">
                        @foreach (['KRW' => '원', 'USD' => '$', 'EUR' => '€'] as $cur => $sym)
                            <button type="button" wire:click="$set('displayCurrency', '{{ $cur }}')"
                                class="px-2 py-1 font-semibold {{ $displayCurrency === $cur ? 'bg-[var(--color-primary)] text-white' : 'bg-white text-gray-600' }}">{{ $sym }}</button>
                        @endforeach
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="label-base">{{ __('listings.add_form.car_cost') }} ({{ \App\Support\Money::SYMBOLS[$e->expected_price_currency] ?? '원' }})</label>
                        <input class="input-base" wire:model.live.debounce.400ms="e_car_cost" inputmode="numeric" @unless ($canEdit) disabled @endunless>
                        @error('e_car_cost') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label-base">{{ __('listings.add_form.discount_rate') }}</label>
                        <input class="input-base" wire:model.live.debounce.400ms="e_discount_rate" inputmode="decimal" @unless ($canEdit) disabled @endunless>
                        @error('e_discount_rate') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="mt-2 flex items-center justify-between text-xs text-gray-500">
                    <span>{{ __('listings.pricing.sales_fee') }}</span><span class="font-semibold text-gray-700">{{ number_format((int) config('board.sales_fee')) }}원</span>
                </div>
                <div class="mt-1 flex items-center justify-between rounded-md bg-gray-50 px-3 py-2 text-sm">
                    <span class="text-gray-600">{{ __('listings.pricing.car_price_short') }}</span><span class="font-bold text-gray-800">{{ $this->fmt($eCar) }}</span>
                </div>
                <label class="label-base mt-3">{{ __('listings.pricing.shipping_label') }}</label>
                <div class="inline-flex overflow-hidden rounded-md border border-gray-300">
                    @foreach (config('board.shipping_options') as $opt)
                        <button type="button" @if ($canEdit) wire:click="$set('e_shipping_usd', {{ $opt }})" @else disabled @endif
                            class="px-3 py-1.5 text-[13px] font-semibold {{ (int) $e_shipping_usd === $opt ? 'bg-[var(--color-primary)] text-white' : 'bg-white text-gray-600' }}">${{ number_format($opt) }}</button>
                    @endforeach
                </div>
                @error('e_shipping_usd') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                @if ($eShipKrw !== null)<div class="mt-1 text-right text-xs text-gray-500">{{ __('listings.pricing.shipping_prefix') }}{{ $this->fmt($eShipKrw) }}</div>@endif
                <div class="mt-2 flex items-center justify-between rounded-md border border-[var(--color-primary)] bg-[#f5f8ff] px-3 py-2.5">
                    <span class="text-sm font-semibold text-gray-700">{{ __('listings.pricing.total_short') }}</span><span class="text-base font-bold text-[var(--color-primary-text)]">{{ $this->fmt($eTotal) }}</span>
                </div>

                {{-- 입금정보 (선택) §6e · car-erp 형식 --}}
                <label class="label-base mt-3">{{ __('listings.payee.label') }} <span class="text-gray-400">{{ __('listings.payee.hint') }}</span></label>
                <div x-data class="grid gap-2 sm:grid-cols-3">
                    <div>
                        <input x-ref="bankEdit" wire:model.blur="e_payee_bank" list="korean-banks-edit" autocomplete="off"
                               class="input-base" placeholder="{{ __('listings.payee.bank_ph') }}" maxlength="100" @unless ($canEdit) disabled @endunless
                               x-on:input="$refs.acctEdit.value = $store.koreanBanks.applyMask($el.value, $refs.acctEdit.value)">
                        <datalist id="korean-banks-edit"><template x-for="b in $store.koreanBanks.names()" :key="b"><option :value="b"></option></template></datalist>
                    </div>
                    <div><input wire:model.blur="e_payee_name" class="input-base" placeholder="{{ __('listings.payee.name_ph') }}" maxlength="60" @unless ($canEdit) disabled @endunless></div>
                    <div><input x-ref="acctEdit" wire:model.blur="e_payee_account" autocomplete="off"
                               class="input-base font-mono" placeholder="{{ __('listings.payee.account_ph') }}" @unless ($canEdit) disabled @endunless
                               x-on:input="$el.value = $store.koreanBanks.applyMask($refs.bankEdit.value, $el.value)"></div>
                </div>
                @error('e_payee_account') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                {{-- 매도비 계좌 (선택 · 판매자와 다른 대상) --}}
                <label class="label-base mt-3">{{ __('listings.selling_fee_payee.label') }} <span class="text-gray-400">{{ __('listings.selling_fee_payee.hint') }}</span></label>
                <div x-data class="grid gap-2 sm:grid-cols-3">
                    <div>
                        <input x-ref="feeBankEdit" wire:model.blur="e_selling_fee_payee_bank" list="korean-banks-fee-edit" autocomplete="off"
                               class="input-base" placeholder="{{ __('listings.payee.bank_ph') }}" maxlength="100" @unless ($canEdit) disabled @endunless
                               x-on:input="$refs.feeAcctEdit.value = $store.koreanBanks.applyMask($el.value, $refs.feeAcctEdit.value)">
                        <datalist id="korean-banks-fee-edit"><template x-for="b in $store.koreanBanks.names()" :key="b"><option :value="b"></option></template></datalist>
                    </div>
                    <div><input wire:model.blur="e_selling_fee_payee_name" class="input-base" placeholder="{{ __('listings.payee.name_ph') }}" maxlength="60" @unless ($canEdit) disabled @endunless></div>
                    <div><input x-ref="feeAcctEdit" wire:model.blur="e_selling_fee_payee_account" autocomplete="off"
                               class="input-base font-mono" placeholder="{{ __('listings.payee.account_ph') }}" @unless ($canEdit) disabled @endunless
                               x-on:input="$el.value = $store.koreanBanks.applyMask($refs.feeBankEdit.value, $el.value)"></div>
                </div>
                @error('e_selling_fee_payee_account') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                <label class="label-base mt-3">{{ __('listings.add_form.region') }}</label>
                <input class="input-base" wire:model="e_region" list="regionListEdit" placeholder="{{ __('listings.add_form.region_ph') }}" @unless ($canEdit) disabled @endunless>
                <datalist id="regionListEdit">
                    @foreach (config('board.regions') as $r)<option value="{{ $r }}">@endforeach
                </datalist>
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

                {{-- 차량 첨부 (영업 자료 → 연동 B car-erp 첨부탭) --}}
                <label class="label-base mt-4">{{ __('listings.attach.drawer_label') }} <span class="text-gray-400">{{ __('listings.attach.drawer_hint', ['max' => config('board.attachment_max')]) }}</span></label>
                @if ($e->salesAttachments->count())
                    <div class="mt-1 grid grid-cols-4 gap-2">
                        @foreach ($e->salesAttachments as $p)
                            <div class="relative overflow-hidden rounded-md border border-gray-200" wire:key="att-{{ $p->id }}">
                                @if ($p->isDocument())
                                    <a href="{{ $p->shareUrl() }}" target="_blank" class="flex aspect-square w-full flex-col items-center justify-center bg-gray-50 p-1 text-center text-[10px] text-gray-500 hover:bg-gray-100">
                                        <span class="text-lg">📄</span><span class="line-clamp-2 break-all">{{ $p->original_name }}</span>
                                    </a>
                                @else
                                    @php $u = $p->shareUrl(); @endphp
                                    <img src="{{ $u }}" @click="$dispatch('open-lightbox', { src: '{{ $u }}' })" class="aspect-square w-full cursor-zoom-in object-cover" alt="">
                                @endif
                                @if ($canEdit)
                                    <button type="button" wire:click="deleteSalesAttachment({{ $p->id }})" wire:confirm="{{ __('listings.attach.delete_confirm') }}"
                                        class="absolute right-0.5 top-0.5 rounded bg-black/55 px-1 text-[10px] font-semibold text-white hover:bg-red-600">✕</button>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="mt-1 text-[11px] text-gray-400">{{ __('listings.attach.drawer_empty') }}</p>
                @endif
                @if ($canEdit)
                    <label class="mt-2 flex cursor-pointer items-center justify-center rounded-lg border-2 border-dashed border-gray-300 py-2.5 text-[13px] text-gray-500 hover:border-[var(--color-primary)]">
                        {{ __('listings.attach.drawer_add') }}
                        <input type="file" multiple wire:model="eSalesFiles" class="hidden">
                    </label>
                    <div wire:loading wire:target="eSalesFiles" class="mt-1 text-xs text-gray-400">{{ __('listings.attach.uploading') }}</div>
                    @if (count($eSalesFiles))
                        <div class="mt-2 grid grid-cols-4 gap-2">
                            @foreach ($eSalesFiles as $i => $f)
                                <div class="relative overflow-hidden rounded-md border border-gray-200" wire:key="enewfile-{{ $i }}">
                                    @if ($f->isPreviewable() && str_starts_with((string) $f->getMimeType(), 'image/'))
                                        <img src="{{ $f->temporaryUrl() }}" class="aspect-square w-full object-cover" alt="">
                                    @else
                                        <div class="flex aspect-square w-full flex-col items-center justify-center bg-gray-50 p-1 text-center text-[10px] text-gray-500">
                                            <span class="text-lg">📄</span><span class="line-clamp-2 break-all">{{ $f->getClientOriginalName() }}</span>
                                        </div>
                                    @endif
                                    <button type="button" wire:click="removeESalesFile({{ $i }})"
                                        class="absolute right-0.5 top-0.5 rounded bg-black/55 px-1 text-[10px] font-semibold text-white hover:bg-red-600">✕</button>
                                </div>
                            @endforeach
                        </div>
                        <p class="mt-1 text-[11px] text-gray-500">{{ __('listings.attach.selected', ['count' => count($eSalesFiles)]) }}</p>
                    @endif
                    @error('eSalesFiles') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    @error('eSalesFiles.*') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                @endif

                {{-- 읽기전용 진행 정보 (현지확인·경매에서 채워짐) --}}
                <div class="mt-4 grid grid-cols-2 gap-3 text-xs text-gray-500">
                    <div>{{ __('listings.drawer.local_total') }}<br><b class="text-sm text-gray-800">{{ $e->final_price ? number_format($e->final_price).__('common.won_currency') : __('listings.drawer.local_total_pending') }}</b></div>
                    <div>{{ __('listings.drawer.status') }}<br><span class="badge {{ $e->statusBadge() }}">{{ $e->statusLabel() }}</span></div>
                    <div>{{ __('listings.drawer.buyer') }}<br>@if ($e->verdictLabel())<span class="badge {{ $e->verdictBadge() }}">{{ $e->verdictLabel() }}</span>@else<span class="text-gray-300">—</span>@endif</div>
                    <div>{{ __('listings.drawer.buyer_name') }}<br><b class="text-gray-800">{{ $e->buyer_name ?: '—' }}</b></div>
                </div>

                <div class="mt-5 flex gap-2">
                    @if ($canEdit)
                        <button class="btn-primary flex-1 justify-center" wire:click="update">{{ __('common.save') }}</button>
                    @endif
                    <button class="btn-ghost" wire:click="closeEdit">{{ $canEdit ? __('common.cancel') : __('common.close') }}</button>
                </div>
            </div>
        </div>
    @endif
</div>

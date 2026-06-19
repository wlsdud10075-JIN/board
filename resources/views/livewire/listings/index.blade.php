<?php

use App\Models\PromotionRequest;
use App\Models\PurchaseListing;
use App\Services\ExchangeRateService;
use App\Support\TimeGate;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public bool $showAdd = false;

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
    public ?string $expected_price = null;  // 매물 표시가 = 차값 (enrichment 자동채움 · 참고가, car_cost 와 별개)
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

    // ── 편집 (본인 글 수정) ──
    public ?int $editingId = null;
    public string $e_region = '';
    public string $e_c_no = '';
    public string $e_respond_contact_id = '';   // 연동 A: 대화 연결 (나중에 붙일 수 있게 수정 가능)
    public string $e_owner_name = '';
    public string $e_payee_name = '';
    public string $e_payee_bank = '';
    public string $e_payee_account = '';
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
        $this->rateFetchedAt = $snap['fetched_at'];
        $this->rateLive = $snap['is_live'];
    }

    public function refreshRate(ExchangeRateService $rates): void
    {
        $rates->refresh();
        $this->loadRates($rates);
        session()->flash('ok', '환율을 갱신했습니다.');
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
            default => number_format($krw).'원',
        };
    }

    /** 차량금액(KRW) = 차값 − (차값 × 할인율%) + 매도비(고정). */
    public function calcCarPrice($cost, $rate): ?int
    {
        if ($cost === null || $cost === '') {
            return null;
        }
        $cost = (int) $cost;
        $discount = (int) round($cost * ((float) $rate / 100));

        return $cost - $discount + (int) config('board.sales_fee');
    }

    /** 최종금액(KRW) = 차량금액 + 배송(USD→KRW, 임시환율). */
    public function calcTotal($cost, $rate, $usd): ?int
    {
        $car = $this->calcCarPrice($cost, $rate);
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
        session()->flash('ok', '['.$req->label.'] 바이어 연결됨 — 링크와 차량번호만 입력하면 됩니다.');
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
        $this->e_car_cost = $l->car_cost !== null ? (string) $l->car_cost : null;
        $this->e_discount_rate = $l->discount_rate !== null ? (string) $l->discount_rate : null;
        $this->e_shipping_usd = $l->shipping_usd;
        $this->e_encar_url = $l->encar_url ?? '';
        $this->e_encar_dealer = $l->encar_dealer ?? '';
        $this->e_auction_venue = $l->auction_venue ?? '';
        $this->e_lot_number = $l->lot_number ?? '';
        $this->resetErrorBag();
    }

    public function closeEdit(): void
    {
        $this->reset(['editingId', 'e_region', 'e_c_no', 'e_respond_contact_id', 'e_owner_name', 'e_payee_name', 'e_payee_bank', 'e_payee_account', 'e_car_cost', 'e_discount_rate', 'e_shipping_usd', 'e_encar_url', 'e_encar_dealer', 'e_auction_venue', 'e_lot_number']);
        unset($this->editing);
    }

    public function update(): void
    {
        $l = PurchaseListing::findOrFail($this->editingId);

        if (! $this->editable($l)) {
            $this->addError('e_car_cost', '시간잠금된 경매 차량은 수정할 수 없습니다. (관리자 문의)');

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
            'e_car_cost' => 'nullable|numeric|min:0',
            'e_discount_rate' => 'nullable|numeric|min:0|max:100',
            'e_shipping_usd' => 'nullable|integer|in:'.implode(',', config('board.shipping_options')),
            'e_encar_url' => 'nullable|string|max:255',
            'e_encar_dealer' => 'nullable|string|max:100',
            'e_auction_venue' => 'nullable|string|max:100',
            'e_lot_number' => 'nullable|string|max:50',
        ]);

        $l->region = $this->e_region ?: null;
        $l->c_no = $this->e_c_no ?: null;
        $l->respond_contact_id = $this->e_respond_contact_id ?: null;
        $l->owner_name = $this->e_owner_name ?: null;
        $l->payee_name = $this->e_payee_name ?: null;
        $l->payee_bank = $this->e_payee_bank ?: null;
        $l->payee_account = $this->e_payee_account ?: null;
        $l->car_cost = ($this->e_car_cost === null || $this->e_car_cost === '') ? null : (int) $this->e_car_cost;
        $l->discount_rate = ($this->e_discount_rate === null || $this->e_discount_rate === '') ? null : (float) $this->e_discount_rate;
        $l->shipping_usd = $this->e_shipping_usd ?: null;
        $l->final_price = $l->totalKrw($this->usdRate()) ?? $l->final_price;
        if ($l->source === 'encar') {
            $l->encar_url = $this->e_encar_url ?: null;
            $l->encar_dealer = $this->e_encar_dealer ?: null;
        } else {
            $l->auction_venue = $this->e_auction_venue ?: null;
            $l->lot_number = $this->e_lot_number ?: null;
        }
        $l->save();

        unset($this->listings);
        session()->flash('ok', $l->vehicle_number.' 수정되었습니다.');
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
            $this->addError($field, '링크에서 식별값을 찾지 못했습니다. (직접 입력 가능)');

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
            $filled[] = '차량번호';
        }
        $prices = $e['prices'] ?? [];
        if ($prices && ($this->expected_price === null || $this->expected_price === '')) {
            $this->priceOptions = $prices;   // {KRW,USD,EUR} — 토글 시 금액 변경
            $cur = isset($prices['KRW']) ? 'KRW' : array_key_first($prices);
            $this->expected_price_currency = $cur;
            $this->expected_price = (string) $prices[$cur];
            $filled[] = '차값('.implode('/', array_keys($prices)).')';
        }
        if (! empty($e['region']) && $this->region === '') {
            $this->region = $e['region'];
            $filled[] = '지역';
        }
        if (! empty($e['vin']) && $this->vin === '') {
            $this->vin = $e['vin'];
            $filled[] = 'VIN';
        }

        $bits = array_filter([
            isset($r['encar_id']) ? 'encar #'.$r['encar_id'] : null,
            isset($r['c_no']) ? 'c_no '.$r['c_no'] : null,
            $r['ssancar_ref'] ?? null,
        ]);
        $cat = PurchaseListing::ORIGIN_LABELS[$this->origin] ?? '';
        $auto = $filled ? ' · 자동채움: '.implode('/', $filled) : '';
        session()->flash('ok', '['.$cat.'] 추출: '.implode(' · ', $bits).$auto.' — 확인 후 저장하세요.');
    }

    /** 통화 토글 — enrichment 통화별 금액이 있으면 그 금액으로 바꿈(없으면 라벨만). */
    public function pickCurrency(string $cur): void
    {
        if (! in_array($cur, ['KRW', 'USD', 'EUR'], true)) {
            return;
        }
        $this->expected_price_currency = $cur;
        if (isset($this->priceOptions[$cur])) {
            $this->expected_price = (string) $this->priceOptions[$cur];
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
            'car_cost' => 'nullable|numeric|min:0',
            'discount_rate' => 'nullable|numeric|min:0|max:100',
            'shipping_usd' => 'nullable|integer|in:'.implode(',', config('board.shipping_options')),
            'encar_url' => 'nullable|string|max:255',
            'encar_dealer' => 'nullable|string|max:100',
            'auction_venue' => 'nullable|string|max:100',
            'lot_number' => 'nullable|string|max:50',
        ], attributes: [
            'vehicle_number' => '차량번호',
            'vin' => '차대번호(VIN)',
        ]);

        // 중복 차량 차단 (차량번호 = 식별값). 본인격리 무시하고 전역 조회(타 영업 중복도 차단).
        $dup = PurchaseListing::withoutGlobalScope(\App\Models\Scopes\SalesmanScope::class)
            ->where('vehicle_number', $this->vehicle_number)->first();
        if ($dup) {
            $this->addError('vehicle_number', '이미 등록된 차량번호입니다 (#'.$dup->id.').');

            return;
        }

        // 경매 등록 시간잠금 (관리자 우회)
        if ($this->source === 'auction' && TimeGate::auctionRegistrationLocked() && ! Auth::user()->isManager()) {
            $this->addError('source', '경매 차량 등록은 '.config('board.auction_lock_time').' 에 마감되었습니다. 관리자 해제가 필요합니다.');

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
        $listing->final_price = $listing->totalKrw($this->usdRate());   // 금액 입력 시 최종금액(KRW) 스냅샷
        $listing->save();

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
        session()->flash('ok', '매입예정이 등록되었습니다.');
    }

    private function resetForm(): void
    {
        $this->reset(['vehicle_number', 'owner_name', 'vin', 'region', 'c_no', 'ssancar_ref', 'encar_id', 'respond_contact_id', 'encarLink', 'ssancarLink', 'promotingId', 'expected_price', 'expected_price_currency', 'priceOptions', 'payee_name', 'payee_bank', 'payee_account', 'car_cost', 'discount_rate', 'shipping_usd', 'encar_url', 'encar_dealer', 'auction_venue', 'lot_number']);
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
            <h1 class="text-xl font-bold text-gray-800">매입예정 (영업)</h1>
            <p class="mt-0.5 text-xs text-gray-500">🔒 본인({{ auth()->user()->name }}) 리스트만 표시 — 서버/DB 레벨 격리</p>
        </div>
        {{-- 환율 (네이버/다음 라이브 · 실패 시 폴백) --}}
        <div class="card-sm shrink-0 text-right text-[13px]" style="background:#f5f8ff;border-color:#dbeafe">
            <div class="flex items-center justify-end gap-1 text-[11px] text-gray-500">
                💱 적용 환율
                <span class="font-semibold {{ $rateLive ? 'text-green-600' : 'text-amber-600' }}">{{ $rateLive ? 'LIVE' : '임시' }}</span>
                <button wire:click="refreshRate" wire:loading.attr="disabled" class="text-blue-500 hover:text-blue-700" title="환율 갱신">↻</button>
            </div>
            <div class="font-bold text-gray-800">USD 1 = {{ number_format($krwPerUsd) }}원</div>
            <div class="font-bold text-gray-800">EUR 1 = {{ number_format($krwPerEur) }}원</div>
            @if ($rateFetchedAt)<div class="text-[10px] text-gray-400">{{ $rateFetchedAt }} 기준</div>@endif
        </div>
    </div>

    {{-- 시간잠금 안내 --}}
    <div class="card-sm mb-3 flex items-center gap-2 text-[13px]"
         style="border-color:{{ $auctionLocked ? '#fecaca' : '#bbf7d0' }};background:{{ $auctionLocked ? '#fef2f2' : '#f0fdf4' }}">
        <span>⏰</span>
        <span class="font-semibold {{ $auctionLocked ? 'text-red-700' : 'text-green-700' }}">
            🔨 경매 등록 {{ $auctionLocked ? '마감됨 ('.config('board.auction_lock_time').' 이후 · 관리자 해제 필요)' : '가능 ('.config('board.auction_lock_time').' 마감)' }}
        </span>
        <span class="text-gray-500">· 🛒 엔카는 상시 등록</span>
    </div>

    @if (session('ok'))
        <div class="card-sm mb-3 border-green-200 bg-green-50 text-[13px] text-green-700">✓ {{ session('ok') }}</div>
    @endif

    {{-- 승격 대기 (연동 A · respond.io 에서 바이어가 board 처리 의사 표시 → 담당 영업에게 라우팅) --}}
    @if ($this->promotions->isNotEmpty())
        <div class="card mb-4" style="border-color:#fde68a;background:#fffbeb">
            <h2 class="mb-2 font-bold text-amber-800">📥 승격 대기 <span class="text-amber-500">· {{ $this->promotions->count() }}명</span></h2>
            <p class="mb-3 text-[11px] text-amber-700/80">바이어가 채팅에서 board 처리를 요청했습니다. “승격”을 누르면 컨택트가 자동 연결됩니다 — 링크와 차량번호만 입력하세요.</p>
            <div class="space-y-2">
                @foreach ($this->promotions as $p)
                    <div class="flex items-center justify-between gap-2 rounded-md border border-amber-200 bg-white px-3 py-2">
                        <div class="min-w-0">
                            <div class="truncate font-semibold text-gray-800">{{ $p->label ?: '컨택트 '.$p->respond_contact_id }}</div>
                            <div class="text-[11px] text-gray-400">컨택트 {{ $p->respond_contact_id }} · {{ $p->created_at->diffForHumans() }}</div>
                            @if (auth()->user()->canSeeAll())
                                <div class="text-[11px] {{ $p->assigned_email ? 'text-gray-400' : 'text-amber-600' }}">담당: {{ $p->assigned_email ?: '미배정' }}</div>
                            @endif
                        </div>
                        <div class="flex shrink-0 gap-1.5">
                            <button class="btn-primary btn-sm" wire:click="promoteFrom({{ $p->id }})">승격</button>
                            <button class="btn-ghost btn-sm" wire:click="dismissPromotion({{ $p->id }})"
                                    wire:confirm="이 승격 대기를 무시하시겠습니까?">무시</button>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <div class="card">
        <div class="mb-3 flex items-center justify-between">
            <h2 class="font-bold text-gray-800">내 매입예정 리스트 <span class="text-gray-400">· {{ $this->listings->count() }}건</span></h2>
            <button class="btn-primary" wire:click="toggleAdd">+ 매입예정 추가</button>
        </div>

        {{-- 추가 폼 --}}
        @if ($showAdd)
            <div class="card-sm mb-4" style="background:#f8f9fb">
                <label class="label-base">출처 (유입 카테고리) <span class="text-red-500">*</span></label>
                <div class="mb-1 flex flex-wrap gap-1">
                    @foreach (\App\Models\PurchaseListing::ORIGIN_LABELS as $key => $lbl)
                        <button type="button" wire:click="setOrigin('{{ $key }}')"
                            class="rounded-md border px-3 py-1.5 text-[13px] font-semibold {{ $origin === $key ? 'border-[var(--color-primary)] bg-[var(--color-primary)] text-white' : 'border-gray-300 bg-white text-gray-600' }}">{{ $lbl }}</button>
                    @endforeach
                </div>
                <p class="mb-3 text-[11px] text-gray-400">💡 매입방법: <b>{{ $source === 'auction' ? '경매 (시간잠금·낙찰/유찰)' : '엔카 즉시구매 (구매대기/확정)' }}</b> — 카테고리에서 자동 결정</p>
                @error('origin') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                {{-- 승격: 링크 붙여넣기 → 식별자 자동추출 + 자동채움. 엔카/ssancar 분리(둘 다 넣으면 합쳐서 채움). --}}
                <div class="card-sm mb-3" style="background:#f0f7ff;border-color:#dbeafe">
                    <label class="label-base">🔗 엔카 링크 <span class="text-gray-400">(차량번호·차값·지역·VIN 자동)</span></label>
                    <div class="flex gap-2">
                        <input class="input-base flex-1" wire:model="encarLink" wire:keydown.enter.prevent="parseLink('encar')"
                               placeholder="https://fem.encar.com/cars/detail/42176484">
                        <button type="button" class="btn-primary btn-sm shrink-0" wire:click="parseLink('encar')">추출</button>
                    </div>
                    @error('encarLink') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                    <label class="label-base mt-2">🔗 ssancar 링크 <span class="text-gray-400">(차량번호·VIN · 검차매물은 엔카가격까지)</span></label>
                    <div class="flex gap-2">
                        <input class="input-base flex-1" wire:model="ssancarLink" wire:keydown.enter.prevent="parseLink('ssancar')"
                               placeholder="https://www.ssancar.com/...?c_no= / ?wr_id=">
                        <button type="button" class="btn-primary btn-sm shrink-0" wire:click="parseLink('ssancar')">추출</button>
                    </div>
                    @error('ssancarLink') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    @if ($encar_id || $c_no || $ssancar_ref)
                        <div class="mt-2 flex flex-wrap items-center gap-1.5 text-[11px]">
                            <span class="text-gray-400">추출됨:</span>
                            @if ($encar_id)<span class="badge badge-encar">encar #{{ $encar_id }}</span>@endif
                            @if ($c_no)<span class="badge badge-blue">c_no {{ $c_no }}</span>@endif
                            @if ($ssancar_ref)<span class="badge badge-gray">{{ $ssancar_ref }}</span>@endif
                        </div>
                    @endif
                    <label class="label-base mt-2">매물 표시가 (= 차값) <span class="text-gray-400">(링크 추출 시 자동 · 통화 선택)</span></label>
                    <div class="flex gap-2">
                        <input class="input-base flex-1" wire:model="expected_price" inputmode="numeric" placeholder="링크 추출 시 자동 · 직접 입력 가능">
                        <div class="inline-flex shrink-0 overflow-hidden rounded-md border border-gray-300">
                            @foreach (['KRW' => '원', 'USD' => '$', 'EUR' => '€'] as $cur => $sym)
                                <button type="button" wire:click="pickCurrency('{{ $cur }}')"
                                    class="px-2.5 py-1.5 text-[13px] font-semibold {{ $expected_price_currency === $cur ? 'bg-[var(--color-primary)] text-white' : 'bg-white text-gray-600' }} {{ isset($priceOptions[$cur]) ? '' : 'opacity-60' }}">{{ $sym }}</button>
                            @endforeach
                        </div>
                    </div>
                    @if ($priceOptions)
                        <p class="mt-1 text-[11px] text-gray-500">💱 링크 표시가: @foreach ($priceOptions as $cur => $amt)<span class="mr-2 whitespace-nowrap">{{ ['KRW' => '원', 'USD' => '$', 'EUR' => '€'][$cur] ?? $cur }} {{ number_format($amt) }}</span>@endforeach— 버튼으로 통화 선택(금액 자동 변경)</p>
                    @else
                        <p class="mt-1 text-[11px] text-gray-400">💡 엔카=원화 / ssancar=3통화 자동. 통화 버튼으로 변경. (가격계산용 ‘차값’과 별개 참고가)</p>
                    @endif
                    @error('expected_price') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    <label class="label-base mt-2">respond.io 컨택트 ID <span class="text-gray-400">(선택 · 바이어 식별 · 자동회신 매칭키)</span></label>
                    <input class="input-base" wire:model="respond_contact_id" placeholder="respond.io 바이어 컨택트 ID (예: 469733036)">
                    @error('respond_contact_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                {{-- 차량번호 · 차값 · 할인율 · 지역 (한 행) --}}
                <div class="grid gap-3 sm:grid-cols-4">
                    <div>
                        <label class="label-base">차량번호 <span class="text-red-500">*</span></label>
                        <input class="input-base" wire:model="vehicle_number" placeholder="12가3456">
                        @error('vehicle_number') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label-base">소유자 <span class="text-gray-400">(차주명)</span></label>
                        <input class="input-base" wire:model="owner_name" placeholder="등록 소유자명">
                        @error('owner_name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label-base">차값 (원)</label>
                        <input class="input-base" wire:model.live.debounce.400ms="car_cost" inputmode="numeric" placeholder="13000000">
                        @error('car_cost') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label-base">할인율 (%)</label>
                        <input class="input-base" wire:model.live.debounce.400ms="discount_rate" inputmode="decimal" placeholder="0">
                        @error('discount_rate') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label-base">지역</label>
                        <input class="input-base" wire:model="region" list="regionList" placeholder="수원 입력 → 자동완성">
                        @error('region') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label-base">매물번호 <span class="text-gray-400">(c_no)</span></label>
                        <input class="input-base" wire:model="c_no" placeholder="링크 추출 시 자동">
                        @error('c_no') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>
                <datalist id="regionList">
                    @foreach (config('board.regions') as $r)<option value="{{ $r }}">@endforeach
                </datalist>

                {{-- 경매 전용 식별 정보 --}}
                @if ($source === 'auction')
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <div><label class="label-base">경매장</label><input class="input-base" wire:model="auction_venue" placeholder="롯데 / 현대 글로비스"></div>
                        <div><label class="label-base">출품번호</label><input class="input-base" wire:model="lot_number" placeholder="A-1024"></div>
                    </div>
                @endif

                {{-- 금액 산정 (§6) --}}
                @php
                    $carPrice = $this->calcCarPrice($car_cost, $discount_rate);
                    $total = $this->calcTotal($car_cost, $discount_rate, $shipping_usd);
                    $shipKrw = $shipping_usd ? (int) $shipping_usd * $this->usdRate() : null;
                @endphp
                <div class="mt-3 flex items-center justify-between">
                    <span class="text-xs font-semibold text-gray-600">금액 산정</span>
                    <div class="inline-flex overflow-hidden rounded-md border border-gray-300 text-xs">
                        @foreach (['KRW' => '원', 'USD' => '$', 'EUR' => '€'] as $cur => $sym)
                            <button type="button" wire:click="$set('displayCurrency', '{{ $cur }}')"
                                class="px-2 py-1 font-semibold {{ $displayCurrency === $cur ? 'bg-[var(--color-primary)] text-white' : 'bg-white text-gray-600' }}">{{ $sym }}</button>
                        @endforeach
                    </div>
                </div>
                <div class="mt-1 flex items-center justify-between text-xs text-gray-500">
                    <span>＋ 매도비 (고정)</span><span class="font-semibold text-gray-700">{{ number_format((int) config('board.sales_fee')) }}원</span>
                </div>
                <div class="mt-1 flex items-center justify-between rounded-md bg-gray-50 px-3 py-2 text-sm">
                    <span class="text-gray-600">차량금액 (Car Price)</span>
                    <span class="font-bold text-gray-800">{{ $this->fmt($carPrice) }}</span>
                </div>
                <label class="label-base mt-3">배송금액 (USD 고정)</label>
                <div class="inline-flex overflow-hidden rounded-md border border-gray-300">
                    @foreach (config('board.shipping_options') as $opt)
                        <button type="button" wire:click="$set('shipping_usd', {{ $opt }})"
                            class="px-3 py-1.5 text-[13px] font-semibold {{ (int) $shipping_usd === $opt ? 'bg-[var(--color-primary)] text-white' : 'bg-white text-gray-600' }}">${{ number_format($opt) }}</button>
                    @endforeach
                </div>
                @error('shipping_usd') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                @if ($shipKrw !== null)<div class="mt-1 text-right text-xs text-gray-500">배송 {{ $this->fmt($shipKrw) }}</div>@endif
                <div class="mt-2 flex items-center justify-between rounded-md border border-[var(--color-primary)] bg-[#f5f8ff] px-3 py-2.5">
                    <span class="text-sm font-semibold text-gray-700">최종금액 (Total)</span>
                    <span class="text-base font-bold text-[var(--color-primary-text)]">{{ $this->fmt($total) }}</span>
                </div>

                {{-- 입금정보 (선택 — 알면 미리, 모르면 구매단계에서) §6e · car-erp 형식(은행 자동완성 + 계좌 마스킹) --}}
                <label class="label-base mt-3">입금정보 <span class="text-gray-400">(선택 · 정산계좌)</span></label>
                <div x-data class="grid gap-2 sm:grid-cols-3">
                    <div>
                        <input x-ref="bankAdd" wire:model.blur="payee_bank" list="korean-banks-add" autocomplete="off"
                               class="input-base" placeholder="은행" maxlength="100"
                               x-on:input="$refs.acctAdd.value = $store.koreanBanks.applyMask($el.value, $refs.acctAdd.value)">
                        <datalist id="korean-banks-add"><template x-for="b in $store.koreanBanks.names()" :key="b"><option :value="b"></option></template></datalist>
                    </div>
                    <div><input wire:model.blur="payee_name" class="input-base" placeholder="예금주" maxlength="60"></div>
                    <div><input x-ref="acctAdd" wire:model.blur="payee_account" autocomplete="off"
                               class="input-base font-mono" placeholder="계좌번호"
                               x-on:input="$el.value = $store.koreanBanks.applyMask($refs.bankAdd.value, $el.value)"></div>
                </div>
                @error('payee_account') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                <p class="mt-1 text-[11px] text-gray-400">💡 지금 알면 미리 입력 → 구매단계 자동 표시. 비워두면 구매담당자가 입력. (은행 선택 시 계좌 자동 하이픈 · 계좌번호 암호화)</p>

                <p class="mt-2 text-xs text-gray-500"><b>차량번호</b> 필수. 금액은 선택 입력이며 현지 차상태 확인 후 조정될 수 있습니다.</p>
                @error('source') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                <div class="mt-3 flex gap-2">
                    <button class="btn-primary btn-sm" wire:click="save">저장</button>
                    <button class="btn-ghost btn-sm" wire:click="toggleAdd">취소</button>
                </div>
            </div>
        @endif

        {{-- 리스트 --}}
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead>
                    <tr><th class="w-px whitespace-nowrap">차량</th><th>출처</th><th>최종금액</th><th>추가검사사항</th><th>바이어</th><th>상태</th></tr>
                </thead>
                <tbody>
                    @forelse ($this->listings as $l)
                        <tr class="cursor-pointer hover:bg-gray-50" wire:click="openEdit({{ $l->id }})">
                            <td class="w-px whitespace-nowrap">
                                <div class="font-semibold text-gray-800">{{ $l->vehicle_number }}</div>
                                <div class="text-xs text-gray-400">VIN ·{{ \Illuminate\Support\Str::limit($l->vin, 10, '') }}</div>
                            </td>
                            <td><span class="badge {{ $l->originBadge() }}">{{ $l->originLabel() }}</span></td>
                            <td class="font-semibold {{ $l->final_price ? 'text-[var(--color-primary-text)]' : 'text-gray-400' }}">{{ $l->final_price ? number_format($l->final_price).'원' : '—' }}</td>
                            <td class="max-w-[200px] truncate text-xs text-gray-500" title="{{ $l->inspection_note }}">{{ $l->inspection_note ?: '—' }}</td>
                            <td>@if ($l->verdictLabel())<span class="badge {{ $l->verdictBadge() }}">{{ $l->verdictLabel() }}</span>@else<span class="text-gray-300">—</span>@endif</td>
                            <td><span class="badge {{ $l->statusBadge() }}">{{ $l->statusLabel() }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-8 text-center text-gray-400">매입예정이 없습니다. “+ 매입예정 추가”로 등록하세요.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="mt-2 text-xs text-gray-400">💡 행을 클릭하면 내용을 보고 수정할 수 있습니다 (시간잠금된 경매 차량 제외).</p>
    </div>

    {{-- 편집 드로어 (본인 글 수정) --}}
    @if ($this->editing)
        @php $e = $this->editing; $canEdit = $this->editable($e); @endphp
        <div class="fixed inset-0 z-40 bg-black/40" wire:click="closeEdit"></div>
        <div class="fixed inset-y-0 right-0 z-50 w-full overflow-y-auto bg-white shadow-xl sm:w-[440px]">
            <div class="flex items-center justify-between border-b border-gray-200 px-5 py-4">
                <h3 class="font-bold text-gray-800">{{ $e->vehicle_number }} · 매입예정 수정</h3>
                <button class="text-gray-400 hover:text-gray-600" wire:click="closeEdit">✕</button>
            </div>
            <div class="px-5 py-4">
                <div class="card-sm mb-3 bg-gray-50 text-xs text-gray-500">
                    차량번호 <b>{{ $e->vehicle_number }}</b> · VIN <b>{{ $e->vin }}</b>
                    · <span class="badge {{ $e->originBadge() }}">{{ $e->originLabel() }}</span>
                    <span class="text-gray-400">({{ $e->isAuction() ? '경매' : '엔카 즉시구매' }})</span><br>
                    <span class="text-gray-400">식별값(차량번호·VIN)·출처는 수정 불가</span>
                </div>

                @unless ($canEdit)
                    <div class="card-sm mb-3 border-amber-200 bg-amber-50 text-[13px] text-amber-800">🔒 시간잠금된 경매 차량입니다. 수정은 관리자에게 문의하세요.</div>
                @endunless

                {{-- 금액 산정 (§6) --}}
                @php
                    $eCar = $this->calcCarPrice($e_car_cost, $e_discount_rate);
                    $eTotal = $this->calcTotal($e_car_cost, $e_discount_rate, $e_shipping_usd);
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
                        <label class="label-base">차값 (원)</label>
                        <input class="input-base" wire:model.live.debounce.400ms="e_car_cost" inputmode="numeric" @unless ($canEdit) disabled @endunless>
                        @error('e_car_cost') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label-base">할인율 (%)</label>
                        <input class="input-base" wire:model.live.debounce.400ms="e_discount_rate" inputmode="decimal" @unless ($canEdit) disabled @endunless>
                        @error('e_discount_rate') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="mt-2 flex items-center justify-between text-xs text-gray-500">
                    <span>＋ 매도비 (고정)</span><span class="font-semibold text-gray-700">{{ number_format((int) config('board.sales_fee')) }}원</span>
                </div>
                <div class="mt-1 flex items-center justify-between rounded-md bg-gray-50 px-3 py-2 text-sm">
                    <span class="text-gray-600">차량금액</span><span class="font-bold text-gray-800">{{ $this->fmt($eCar) }}</span>
                </div>
                <label class="label-base mt-3">배송금액 (USD 고정)</label>
                <div class="inline-flex overflow-hidden rounded-md border border-gray-300">
                    @foreach (config('board.shipping_options') as $opt)
                        <button type="button" @if ($canEdit) wire:click="$set('e_shipping_usd', {{ $opt }})" @else disabled @endif
                            class="px-3 py-1.5 text-[13px] font-semibold {{ (int) $e_shipping_usd === $opt ? 'bg-[var(--color-primary)] text-white' : 'bg-white text-gray-600' }}">${{ number_format($opt) }}</button>
                    @endforeach
                </div>
                @error('e_shipping_usd') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                @if ($eShipKrw !== null)<div class="mt-1 text-right text-xs text-gray-500">배송 {{ $this->fmt($eShipKrw) }}</div>@endif
                <div class="mt-2 flex items-center justify-between rounded-md border border-[var(--color-primary)] bg-[#f5f8ff] px-3 py-2.5">
                    <span class="text-sm font-semibold text-gray-700">최종금액</span><span class="text-base font-bold text-[var(--color-primary-text)]">{{ $this->fmt($eTotal) }}</span>
                </div>

                {{-- 입금정보 (선택) §6e · car-erp 형식 --}}
                <label class="label-base mt-3">입금정보 <span class="text-gray-400">(선택 · 정산계좌)</span></label>
                <div x-data class="grid gap-2 sm:grid-cols-3">
                    <div>
                        <input x-ref="bankEdit" wire:model.blur="e_payee_bank" list="korean-banks-edit" autocomplete="off"
                               class="input-base" placeholder="은행" maxlength="100" @unless ($canEdit) disabled @endunless
                               x-on:input="$refs.acctEdit.value = $store.koreanBanks.applyMask($el.value, $refs.acctEdit.value)">
                        <datalist id="korean-banks-edit"><template x-for="b in $store.koreanBanks.names()" :key="b"><option :value="b"></option></template></datalist>
                    </div>
                    <div><input wire:model.blur="e_payee_name" class="input-base" placeholder="예금주" maxlength="60" @unless ($canEdit) disabled @endunless></div>
                    <div><input x-ref="acctEdit" wire:model.blur="e_payee_account" autocomplete="off"
                               class="input-base font-mono" placeholder="계좌번호" @unless ($canEdit) disabled @endunless
                               x-on:input="$el.value = $store.koreanBanks.applyMask($refs.bankEdit.value, $el.value)"></div>
                </div>
                @error('e_payee_account') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                <label class="label-base mt-3">지역</label>
                <input class="input-base" wire:model="e_region" list="regionListEdit" placeholder="수원 입력 → 자동완성" @unless ($canEdit) disabled @endunless>
                <datalist id="regionListEdit">
                    @foreach (config('board.regions') as $r)<option value="{{ $r }}">@endforeach
                </datalist>
                @error('e_region') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                <label class="label-base mt-3">소유자 <span class="text-gray-400">(차주명)</span></label>
                <input class="input-base" wire:model="e_owner_name" placeholder="등록 소유자명" maxlength="60" @unless ($canEdit) disabled @endunless>
                @error('e_owner_name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                <label class="label-base mt-3">respond.io 컨택트 ID <span class="text-gray-400">(바이어 식별 · 자동회신 매칭키)</span></label>
                <input class="input-base" wire:model="e_respond_contact_id" placeholder="respond.io 바이어 컨택트 ID" @unless ($canEdit) disabled @endunless>
                @error('e_respond_contact_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                @if ($e->encar_id || $e->c_no || $e->ssancar_ref)
                    <div class="mt-1 flex flex-wrap gap-1.5 text-[11px]">
                        <span class="text-gray-400">유입:</span>
                        @if ($e->encar_id)<span class="badge badge-encar">encar #{{ $e->encar_id }}</span>@endif
                        @if ($e->c_no)<span class="badge badge-blue">c_no {{ $e->c_no }}</span>@endif
                        @if ($e->ssancar_ref)<span class="badge badge-gray">{{ $e->ssancar_ref }}</span>@endif
                    </div>
                @endif

                @if ($e->source === 'encar')
                    <label class="label-base mt-3">엔카 매물 URL</label>
                    <input class="input-base" wire:model="e_encar_url" placeholder="https://encar.com/..." @unless ($canEdit) disabled @endunless>
                    <label class="label-base mt-3">매물번호 <span class="text-gray-400">(c_no)</span></label>
                    <input class="input-base" wire:model="e_c_no" placeholder="예: 6797296" @unless ($canEdit) disabled @endunless>
                    @error('e_c_no') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                @else
                    <label class="label-base mt-3">경매장</label>
                    <input class="input-base" wire:model="e_auction_venue" @unless ($canEdit) disabled @endunless>
                    <label class="label-base mt-3">출품번호</label>
                    <input class="input-base" wire:model="e_lot_number" @unless ($canEdit) disabled @endunless>
                @endif

                {{-- 읽기전용 진행 정보 (현지확인·경매에서 채워짐) --}}
                <div class="mt-4 grid grid-cols-2 gap-3 text-xs text-gray-500">
                    <div>현지 최종금액<br><b class="text-sm text-gray-800">{{ $e->final_price ? number_format($e->final_price).'원' : '— (현지확인 후)' }}</b></div>
                    <div>상태<br><span class="badge {{ $e->statusBadge() }}">{{ $e->statusLabel() }}</span></div>
                    <div>바이어<br>@if ($e->verdictLabel())<span class="badge {{ $e->verdictBadge() }}">{{ $e->verdictLabel() }}</span>@else<span class="text-gray-300">—</span>@endif</div>
                    <div>바이어명<br><b class="text-gray-800">{{ $e->buyer_name ?: '—' }}</b></div>
                </div>

                <div class="mt-5 flex gap-2">
                    @if ($canEdit)
                        <button class="btn-primary flex-1 justify-center" wire:click="update">저장</button>
                    @endif
                    <button class="btn-ghost" wire:click="closeEdit">{{ $canEdit ? '취소' : '닫기' }}</button>
                </div>
            </div>
        </div>
    @endif
</div>

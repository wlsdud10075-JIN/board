<?php

use App\Models\PurchaseListing;
use App\Services\ExchangeRateService;
use App\Support\TimeGate;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public bool $showAdd = false;

    public string $source = 'encar';
    public string $vehicle_number = '';
    public string $vin = '';
    public string $region = '';             // 지역 (검사지역, 자동완성)
    public string $c_no = '';               // 매물번호 (ssancar c_no, 조인키)
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
        $this->reset(['editingId', 'e_region', 'e_c_no', 'e_payee_name', 'e_payee_bank', 'e_payee_account', 'e_car_cost', 'e_discount_rate', 'e_shipping_usd', 'e_encar_url', 'e_encar_dealer', 'e_auction_venue', 'e_lot_number']);
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

    public function setSource(string $s): void
    {
        $this->source = $s;
    }

    public function save(): void
    {
        $this->validate([
            'source' => 'required|in:encar,auction',
            'vehicle_number' => 'required|string|max:20',
            'region' => 'nullable|string|max:60',
            'c_no' => 'nullable|string|max:50',
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
            'vehicle_number' => $this->vehicle_number,
            'vin' => $this->vin ?: null,
            'region' => $this->region ?: null,
            'c_no' => $this->c_no ?: null,
            'payee_name' => $this->payee_name ?: null,
            'payee_bank' => $this->payee_bank ?: null,
            'payee_account' => $this->payee_account ?: null,
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

        $this->resetForm();
        $this->showAdd = false;
        unset($this->listings);
        session()->flash('ok', '매입예정이 등록되었습니다.');
    }

    private function resetForm(): void
    {
        $this->reset(['vehicle_number', 'vin', 'region', 'c_no', 'payee_name', 'payee_bank', 'payee_account', 'car_cost', 'discount_rate', 'shipping_usd', 'encar_url', 'encar_dealer', 'auction_venue', 'lot_number']);
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

    <div class="card">
        <div class="mb-3 flex items-center justify-between">
            <h2 class="font-bold text-gray-800">내 매입예정 리스트 <span class="text-gray-400">· {{ $this->listings->count() }}건</span></h2>
            <button class="btn-primary" wire:click="toggleAdd">+ 매입예정 추가</button>
        </div>

        {{-- 추가 폼 --}}
        @if ($showAdd)
            <div class="card-sm mb-4" style="background:#f8f9fb">
                <label class="label-base">출처 선택 <span class="text-red-500">*</span></label>
                <div class="mb-3 inline-flex overflow-hidden rounded-md border border-gray-300">
                    <button type="button" wire:click="setSource('encar')"
                        class="px-3 py-1.5 text-[13px] font-semibold {{ $source === 'encar' ? 'bg-[var(--color-encar)] text-white' : 'bg-white text-gray-600' }}">🛒 엔카 (즉시구매)</button>
                    <button type="button" wire:click="setSource('auction')"
                        class="px-3 py-1.5 text-[13px] font-semibold {{ $source === 'auction' ? 'bg-[var(--color-auction)] text-white' : 'bg-white text-gray-600' }}">🔨 경매</button>
                </div>

                {{-- 차량번호 · 차값 · 할인율 · 지역 (한 행) --}}
                <div class="grid gap-3 sm:grid-cols-4">
                    <div>
                        <label class="label-base">차량번호 <span class="text-red-500">*</span></label>
                        <input class="input-base" wire:model="vehicle_number" placeholder="12가3456">
                        @error('vehicle_number') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
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
                </div>
                <datalist id="regionList">
                    @foreach (config('board.regions') as $r)<option value="{{ $r }}">@endforeach
                </datalist>

                {{-- 엔카 매물 URL + 매물번호(c_no) --}}
                @if ($source === 'encar')
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="label-base">엔카 매물 URL</label>
                            <input class="input-base" wire:model="encar_url" placeholder="https://encar.com/...">
                            @error('encar_url') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="label-base">매물번호 <span class="text-gray-400">(c_no)</span></label>
                            <input class="input-base" wire:model="c_no" placeholder="예: 6797296">
                            @error('c_no') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <p class="mt-1 text-[11px] text-gray-400">💡 매물번호(c_no)는 ssancar 매물 식별값 — 추후 respond.io 연동 시 자동 입력.</p>
                @endif

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

                {{-- 입금정보 (선택 — 알면 미리, 모르면 구매단계에서) §6e --}}
                <label class="label-base mt-3">입금정보 <span class="text-gray-400">(선택 · 정산계좌)</span></label>
                <div class="grid gap-2 sm:grid-cols-3">
                    <input class="input-base" wire:model="payee_name" placeholder="예금주">
                    <input class="input-base" wire:model="payee_bank" placeholder="은행">
                    <input class="input-base" wire:model="payee_account" placeholder="계좌번호" inputmode="numeric">
                </div>
                @error('payee_account') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                <p class="mt-1 text-[11px] text-gray-400">💡 지금 알면 미리 입력 → 구매단계에 자동 표시. 비워두면 구매담당자가 입력. (계좌번호 암호화 저장)</p>

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
                            <td><span class="badge {{ $l->isAuction() ? 'badge-auction' : 'badge-encar' }}">{{ $l->isAuction() ? '경매' : '엔카' }}</span></td>
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
                    · <span class="badge {{ $e->isAuction() ? 'badge-auction' : 'badge-encar' }}">{{ $e->isAuction() ? '경매' : '엔카' }}</span><br>
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

                {{-- 입금정보 (선택) §6e --}}
                <label class="label-base mt-3">입금정보 <span class="text-gray-400">(선택 · 정산계좌)</span></label>
                <div class="grid gap-2 sm:grid-cols-3">
                    <input class="input-base" wire:model="e_payee_name" placeholder="예금주" @unless ($canEdit) disabled @endunless>
                    <input class="input-base" wire:model="e_payee_bank" placeholder="은행" @unless ($canEdit) disabled @endunless>
                    <input class="input-base" wire:model="e_payee_account" placeholder="계좌번호" inputmode="numeric" @unless ($canEdit) disabled @endunless>
                </div>
                @error('e_payee_account') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                <label class="label-base mt-3">지역</label>
                <input class="input-base" wire:model="e_region" list="regionListEdit" placeholder="수원 입력 → 자동완성" @unless ($canEdit) disabled @endunless>
                <datalist id="regionListEdit">
                    @foreach (config('board.regions') as $r)<option value="{{ $r }}">@endforeach
                </datalist>
                @error('e_region') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

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

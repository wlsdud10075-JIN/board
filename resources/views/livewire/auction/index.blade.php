<?php

use App\Models\PurchaseListing;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public ?int $detailId = null;

    // 소유자/차주명 (연동 B: car-erp NICE 조회 입력값) — 매입예정에서 미리 입력, 여기서 보정.
    public string $owner_name = '';

    // 매입 정산 입금정보 (§6e) — 판매자/경매장 계좌. won 단계 입력 → 연동 B 전달.
    public string $payee_name = '';
    public string $payee_bank = '';
    public string $payee_account = '';
    // 매도비 계좌 (판매자와 다른 대상, 영업 직접입력) — 매입가 계좌와 별개
    public string $selling_fee_payee_name = '';
    public string $selling_fee_payee_bank = '';
    public string $selling_fee_payee_account = '';

    // v3 — car-erp 바이어/컨사이니 (드롭다운 선택 → 연동B buyer_id/consignee_id). 본인 스코프.
    public ?int $buyerId = null;
    public ?int $consigneeId = null;
    public array $buyerOpts = [];
    public array $consigneeOpts = [];

    private function salesmanEmail(): string
    {
        // 바이어/컨사이니는 '딜 작성자(영업)'의 car-erp 스코프로 조회 — 운영자(관리자 대행)가 아닌 작성자 기준.
        // car-erp /buyers 는 본인격리(IDOR)라, 작성자 기준이어야 그 영업의 바이어가 뜬다.
        // 또 연동 B 송신의 salesman_email(작성자 기준)과 일치 → 교차-FK 오배정 차단.
        $creator = $this->detail?->creator;

        return $creator?->car_erp_salesman_email ?: ($creator?->email ?? '');
    }

    private function loadBuyers(): void
    {
        $r = app(\App\Services\CarErpReadService::class)->buyers($this->salesmanEmail());
        $this->buyerOpts = $r['ok'] ? (array) ($r['data']['data'] ?? []) : [];
    }

    private function loadConsignees(): void
    {
        if (! $this->buyerId) {
            $this->consigneeOpts = [];

            return;
        }
        $r = app(\App\Services\CarErpReadService::class)->consignees($this->salesmanEmail(), $this->buyerId);
        $this->consigneeOpts = $r['ok'] ? (array) ($r['data']['data'] ?? []) : [];
    }

    /** 바이어 변경 시 컨사이니 목록 갱신 + 선택 초기화. */
    public function updatedBuyerId(): void
    {
        $this->buyerId = $this->buyerId ?: null;
        $this->consigneeId = null;
        $this->loadConsignees();
    }

    private function payeeRules(): array
    {
        return [
            'owner_name' => 'nullable|string|max:60',
            'payee_name' => 'nullable|string|max:60',
            'payee_bank' => 'nullable|string|max:40',
            'payee_account' => 'nullable|string|max:40',
            'selling_fee_payee_name' => 'nullable|string|max:60',
            'selling_fee_payee_bank' => 'nullable|string|max:40',
            'selling_fee_payee_account' => 'nullable|string|max:40',
        ];
    }

    #[Computed]
    public function listings()
    {
        return PurchaseListing::with('creator')
            ->whereIn('status', ['accepted', 'won', 'failed'])
            ->latest()
            ->get();
    }

    #[Computed]
    public function detail(): ?PurchaseListing
    {
        return $this->detailId ? PurchaseListing::with(['creator', 'photos'])->find($this->detailId) : null;
    }

    public function openDetail(int $id): void
    {
        $this->detailId = $id;
        $l = PurchaseListing::findOrFail($id);
        $this->owner_name = $l->owner_name ?? '';
        $this->payee_name = $l->payee_name ?? '';
        $this->payee_bank = $l->payee_bank ?? '';
        $this->payee_account = $l->payee_account ?? '';
        $this->selling_fee_payee_name = $l->selling_fee_payee_name ?? '';
        $this->selling_fee_payee_bank = $l->selling_fee_payee_bank ?? '';
        $this->selling_fee_payee_account = $l->selling_fee_payee_account ?? '';
        $this->buyerId = $l->car_erp_buyer_id;
        $this->consigneeId = $l->car_erp_consignee_id;
        $this->loadBuyers();
        $this->loadConsignees();
        $this->resetErrorBag();
    }

    public function closeDetail(): void
    {
        $this->reset(['detailId', 'owner_name', 'payee_name', 'payee_bank', 'payee_account',
            'selling_fee_payee_name', 'selling_fee_payee_bank', 'selling_fee_payee_account',
            'buyerId', 'consigneeId', 'buyerOpts', 'consigneeOpts']);
        unset($this->detail);
    }

    private function applyPayee(PurchaseListing $l): void
    {
        $l->owner_name = $this->owner_name ?: null;
        $l->payee_name = $this->payee_name ?: null;
        $l->payee_bank = $this->payee_bank ?: null;
        $l->payee_account = $this->payee_account ?: null;
        $l->selling_fee_payee_name = $this->selling_fee_payee_name ?: null;
        $l->selling_fee_payee_bank = $this->selling_fee_payee_bank ?: null;
        $l->selling_fee_payee_account = $this->selling_fee_payee_account ?: null;
        $l->car_erp_buyer_id = $this->buyerId ?: null;
        $l->car_erp_consignee_id = $this->consigneeId ?: null;
    }

    /** 입금정보만 저장(이미 won 인 차량 보정용). */
    public function savePayee(): void
    {
        $this->validate($this->payeeRules());
        $l = PurchaseListing::findOrFail($this->detailId);
        $this->applyPayee($l);
        $l->save();
        unset($this->detail, $this->listings);
        session()->flash('ok', __('auction.flash_payee_saved'));
    }

    public function photoUrl(string $path): string
    {
        $disk = config('board.photo_disk');
        if ($disk !== 's3') {
            return Storage::disk($disk)->url($path);
        }

        // presigned URL — 렌더링마다 재서명되면 영상 재생이 리셋되므로 캐시로 문자열 고정 (TTL < 만료)
        return Cache::remember(
            "photo_url:{$path}",
            now()->addMinutes(20),
            fn () => Storage::disk('s3')->temporaryUrl($path, now()->addMinutes(30)),
        );
    }

    public function conclude(int $id, string $result): void
    {
        if (! in_array($result, ['won', 'failed'], true)) {
            return;
        }

        $l = PurchaseListing::findOrFail($id);
        if ($l->status !== 'accepted') {
            session()->flash('err', __('auction.flash_only_accepted'));

            return;
        }

        if ($result === 'won') {
            $this->validate($this->payeeRules());
            $this->applyPayee($l);   // 낙찰/구매확정 시 입금정보 함께 저장
        }

        $l->status = $result;
        $l->save();
        $this->reset(['detailId', 'owner_name', 'payee_name', 'payee_bank', 'payee_account',
            'selling_fee_payee_name', 'selling_fee_payee_bank', 'selling_fee_payee_account',
            'buyerId', 'consigneeId', 'buyerOpts', 'consigneeOpts']);
        unset($this->listings, $this->detail);
        session()->flash('ok', __('auction.flash_processed', ['no' => $l->vehicle_number, 'label' => $l->statusLabel()]));
    }
}; ?>

<div class="p-3 md:p-6">
    <div class="mb-4">
        <h1 class="text-xl font-bold text-gray-800">{{ __('auction.title') }}</h1>
        <p class="mt-0.5 text-xs text-gray-500">{!! __('auction.subtitle') !!}</p>
    </div>

    @if (session('ok'))
        <div class="card-sm mb-3 border-green-200 bg-green-50 text-[13px] text-green-700">✓ {{ session('ok') }}</div>
    @endif
    @if (session('err'))
        <div class="card-sm mb-3 border-red-200 bg-red-50 text-[13px] text-red-700">⚠ {{ session('err') }}</div>
    @endif

    <div class="card">
        <div class="mb-3 flex items-center gap-2">
            <h2 class="font-bold text-gray-800">{{ __('auction.panel_title') }}</h2>
            <span class="pill-count">{{ __('auction.accepted_count', ['count' => $this->listings->where('status', 'accepted')->count()]) }}</span>
        </div>

        {{-- 데스크톱: 표 --}}
        <div class="hidden overflow-x-auto sm:block">
            <table class="tbl">
                <thead>
                    <tr><th>{{ __('auction.col_vehicle') }}</th><th>{{ __('auction.col_source') }}</th><th>{{ __('auction.col_salesman') }}</th><th>{{ __('auction.col_final_price') }}</th><th>{{ __('auction.col_process') }}</th></tr>
                </thead>
                <tbody>
                    @forelse ($this->listings as $l)
                        <tr class="cursor-pointer hover:bg-gray-50" wire:click="openDetail({{ $l->id }})">
                            <td class="font-semibold text-gray-800">{{ $l->vehicle_number }}</td>
                            <td><span class="badge {{ $l->isAuction() ? 'badge-auction' : 'badge-encar' }}">{{ $l->isAuction() ? __('domain.source.auction') : __('domain.source.encar') }}</span></td>
                            <td class="text-gray-600">{{ $l->creator->name }}</td>
                            <td class="font-semibold text-[var(--color-primary-text)]">{{ $l->final_price ? number_format($l->final_price).__('common.won_currency') : '—' }}</td>
                            <td>
                                @if ($l->status === 'accepted')
                                    <span class="badge badge-amber">{{ __('auction.pending_click') }}</span>
                                @else
                                    <span class="badge {{ $l->statusBadge() }}">{{ $l->statusLabel() }} ✓</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-8 text-center text-gray-400">{{ __('auction.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- 모바일: 카드 --}}
        <div class="space-y-2 sm:hidden">
            @forelse ($this->listings as $l)
                <div class="card-tight cursor-pointer" wire:click="openDetail({{ $l->id }})">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <div class="font-semibold text-gray-800">{{ $l->vehicle_number }}</div>
                            <div class="text-xs text-gray-400">{{ __('auction.salesman_label') }} {{ $l->creator->name }}</div>
                        </div>
                        <span class="badge {{ $l->isAuction() ? 'badge-auction' : 'badge-encar' }} shrink-0">{{ $l->isAuction() ? __('domain.source.auction') : __('domain.source.encar') }}</span>
                    </div>
                    <div class="mt-2 flex items-center justify-between gap-2">
                        @if ($l->status === 'accepted')
                            <span class="badge badge-amber">{{ __('auction.pending_tap') }}</span>
                        @else
                            <span class="badge {{ $l->statusBadge() }}">{{ $l->statusLabel() }} ✓</span>
                        @endif
                        <span class="shrink-0 text-sm font-semibold text-[var(--color-primary-text)]">{{ $l->final_price ? number_format($l->final_price).__('common.won_currency') : '—' }}</span>
                    </div>
                </div>
            @empty
                <div class="py-8 text-center text-gray-400">{{ __('auction.empty') }}</div>
            @endforelse
        </div>
        <p class="mt-2 text-xs text-gray-400">{{ __('auction.row_click_hint') }}</p>
    </div>

    {{-- ─────────── 상세 드로어 (읽기전용 + 집행) ─────────── --}}
    @if ($this->detail)
        @php $d = $this->detail; @endphp
        <div class="fixed inset-0 z-40 bg-black/40" wire:click="closeDetail"></div>
        <div class="fixed inset-y-0 right-0 z-50 w-full overflow-y-auto bg-white shadow-xl sm:w-[440px]">
            <div class="flex items-center justify-between border-b border-gray-200 px-5 py-4">
                <h3 class="font-bold text-gray-800">{{ $d->vehicle_number }} · {{ __('common.detail') }}
                    <span class="badge {{ $d->statusBadge() }} ml-1">{{ $d->statusLabel() }}</span>
                </h3>
                <button class="text-gray-400 hover:text-gray-600" wire:click="closeDetail">✕</button>
            </div>

            <div class="px-5 py-4 text-sm">
                <div class="card-sm mb-3 bg-gray-50 text-xs text-gray-600">
                    <span class="badge {{ $d->isAuction() ? 'badge-auction' : 'badge-encar' }}">{{ $d->isAuction() ? __('domain.source.auction') : __('domain.source.encar') }}</span>
                    · {{ __('auction.salesman_label') }} <b>{{ $d->creator->name }}</b> · {{ __('auction.region_label') }} <b>{{ $d->region ?: '—' }}</b><br>
                    VIN <b>{{ $d->vin ?: __('auction.vin_pending') }}</b>
                    @if ($d->isAuction())· {{ $d->auction_venue }} {{ $d->lot_number }}@else· {{ $d->encar_dealer ?: '' }} {{ $d->c_no ? __('auction.listing_no', ['no' => $d->c_no]) : '' }}@endif
                </div>

                {{-- 금액 --}}
                <div class="grid grid-cols-2 gap-2 text-xs text-gray-500">
                    <div>{{ __('auction.car_cost') }}<br><b class="text-sm text-gray-800">{{ $d->carCostDisplay() }}</b></div>
                    <div>{{ __('auction.discount_rate') }}<br><b class="text-sm text-gray-800">{{ $d->discount_rate !== null ? $d->discount_rate.'%' : '—' }}</b></div>
                    <div>{{ __('auction.shipping') }}<br><b class="text-sm text-gray-800">{{ $d->shipping_usd ? '$'.number_format($d->shipping_usd) : '—' }}</b></div>
                    <div>{{ __('auction.buyer') }}<br><b class="text-sm text-gray-800">{{ $d->buyer_name ?: '—' }}</b></div>
                </div>
                <div class="mt-3 flex items-center justify-between rounded-md border border-[var(--color-primary)] bg-[#f5f8ff] px-3 py-2.5">
                    <span class="font-semibold text-gray-700">{{ __('auction.final_price') }}</span>
                    <span class="text-base font-bold text-[var(--color-primary-text)]">{{ $d->final_price ? number_format($d->final_price).__('common.won_currency') : '—' }}</span>
                </div>

                @if ($d->inspection_memo || $d->inspection_note)
                    <div class="section-title-sm">{{ __('auction.inspection_memo') }}</div>
                    <p class="text-xs text-gray-600">{{ $d->inspection_memo }}{{ $d->inspection_note ? ' · '.$d->inspection_note : '' }}</p>
                @endif

                @if ($d->photos->count())
                    <div class="section-title-sm">{{ __('auction.vehicle_photos') }}</div>
                    <div class="grid grid-cols-4 gap-2">
                        @foreach ($d->photos as $p)
                            @if ($p->isVideo())
                                <video src="{{ $this->photoUrl($p->s3_path) }}" class="aspect-square w-full rounded-md object-cover" controls preload="metadata"></video>
                            @else
                                <img src="{{ $this->photoUrl($p->s3_path) }}" class="aspect-square w-full rounded-md object-cover" alt="">
                            @endif
                        @endforeach
                    </div>
                @endif

                {{-- 소유자(차주) — accepted·won 에서 입력/보정 (car-erp NICE 조회 입력값) --}}
                @if (in_array($d->status, ['accepted', 'won'], true))
                    <div class="section-title-sm">{{ __('auction.owner') }} <span class="text-[11px] font-normal text-gray-400">{{ __('auction.owner_hint') }}</span></div>
                    <input wire:model.blur="owner_name" class="input-base" placeholder="{{ __('auction.owner_placeholder') }}" maxlength="60">
                    @error('owner_name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                @endif

                {{-- 바이어/컨사이니 (car-erp 목록 드롭다운) — accepted·won, 본인 스코프. 미구성/무목록=수동 --}}
                @if (in_array($d->status, ['accepted', 'won'], true))
                    <div class="section-title-sm">{{ __('auction.buyer') }} <span class="text-[11px] font-normal text-gray-400">{{ __('auction.buyer_hint') }}</span></div>
                    @if (empty($buyerOpts))
                        <p class="text-xs text-gray-400">{{ __('auction.buyer_unavailable') }}</p>
                    @else
                        <select wire:model.live="buyerId" class="input-base">
                            <option value="">{{ __('auction.buyer_select') }}</option>
                            @foreach ($buyerOpts as $b)
                                <option value="{{ $b['id'] }}">{{ $b['name'] }}{{ !empty($b['country']) ? ' ('.$b['country'].')' : '' }}</option>
                            @endforeach
                        </select>
                        @if ($buyerId)
                            <select wire:model="consigneeId" class="input-base mt-2">
                                <option value="">{{ __('auction.consignee_select') }}</option>
                                @foreach ($consigneeOpts as $c)
                                    <option value="{{ $c['id'] }}">{{ $c['name'] }}</option>
                                @endforeach
                            </select>
                        @endif
                    @endif
                @endif

                {{-- 입금정보 (정산 = 판매자/경매장 계좌) — accepted·won 에서 입력/수정 --}}
                @if (in_array($d->status, ['accepted', 'won'], true))
                    <div class="section-title-sm">{{ __('auction.payment_info') }} <span class="text-[11px] font-normal text-gray-400">{{ __('auction.payment_info_hint') }}</span></div>
                    <div x-data>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <input x-ref="bankAuc" wire:model.blur="payee_bank" list="korean-banks-auction" autocomplete="off"
                                       class="input-base" placeholder="{{ __('auction.bank_placeholder') }}" maxlength="100"
                                       x-on:input="$refs.acctAuc.value = $store.koreanBanks.applyMask($el.value, $refs.acctAuc.value)">
                                <datalist id="korean-banks-auction"><template x-for="b in $store.koreanBanks.names()" :key="b"><option :value="b"></option></template></datalist>
                            </div>
                            <div><input wire:model.blur="payee_name" class="input-base" placeholder="{{ __('auction.payee_placeholder') }}" maxlength="60"></div>
                        </div>
                        <input x-ref="acctAuc" wire:model.blur="payee_account" autocomplete="off"
                               class="input-base mt-2 font-mono" placeholder="{{ __('auction.account_placeholder') }}"
                               x-on:input="$el.value = $store.koreanBanks.applyMask($refs.bankAuc.value, $el.value)">
                    </div>
                    @error('payee_name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    @error('payee_bank') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    @error('payee_account') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                    {{-- 매도비 계좌 (판매자와 다른 대상) --}}
                    <div class="section-title-sm mt-3">{{ __('auction.selling_fee_info') }} <span class="text-[11px] font-normal text-gray-400">{{ __('auction.selling_fee_info_hint') }}</span></div>
                    <div x-data>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <input x-ref="feeBankAuc" wire:model.blur="selling_fee_payee_bank" list="korean-banks-fee-auction" autocomplete="off"
                                       class="input-base" placeholder="{{ __('auction.bank_placeholder') }}" maxlength="100"
                                       x-on:input="$refs.feeAcctAuc.value = $store.koreanBanks.applyMask($el.value, $refs.feeAcctAuc.value)">
                                <datalist id="korean-banks-fee-auction"><template x-for="b in $store.koreanBanks.names()" :key="b"><option :value="b"></option></template></datalist>
                            </div>
                            <div><input wire:model.blur="selling_fee_payee_name" class="input-base" placeholder="{{ __('auction.payee_placeholder') }}" maxlength="60"></div>
                        </div>
                        <input x-ref="feeAcctAuc" wire:model.blur="selling_fee_payee_account" autocomplete="off"
                               class="input-base mt-2 font-mono" placeholder="{{ __('auction.account_placeholder') }}"
                               x-on:input="$el.value = $store.koreanBanks.applyMask($refs.feeBankAuc.value, $el.value)">
                    </div>
                    @error('selling_fee_payee_account') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                @endif

                {{-- 집행 --}}
                @if ($d->status === 'accepted')
                    <div class="section-title-sm">{{ __('auction.execute') }}</div>
                    <p class="mb-1 text-[11px] text-gray-400">{{ __('auction.execute_hint') }}</p>
                    <div class="flex gap-2">
                        <button class="btn-green flex-1 justify-center" wire:click="conclude({{ $d->id }}, 'won')">{{ $d->isAuction() ? __('auction.won_auction') : __('auction.won_encar') }}</button>
                        <button class="btn-ghost flex-1 justify-center" wire:click="conclude({{ $d->id }}, 'failed')">{{ $d->isAuction() ? __('auction.failed_auction') : __('auction.failed_encar') }}</button>
                    </div>
                @elseif ($d->status === 'won')
                    <button class="btn-primary mt-3 w-full justify-center" wire:click="savePayee">{{ __('auction.save_payment_info') }}</button>
                @endif
            </div>
        </div>
    @endif
</div>

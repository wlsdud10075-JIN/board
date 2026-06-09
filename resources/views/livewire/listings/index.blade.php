<?php

use App\Models\PurchaseListing;
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
    public ?string $expected_price = null;
    public string $encar_url = '';
    public string $encar_dealer = '';
    public string $auction_venue = '';
    public string $lot_number = '';

    #[Computed]
    public function listings()
    {
        return PurchaseListing::with('creator')->latest()->get();
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
            'vin' => 'required|string|max:32|unique:purchase_listings,vin',
            'expected_price' => 'nullable|numeric|min:0',
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

        PurchaseListing::create([
            'created_by_user_id' => Auth::id(),
            'source' => $this->source,
            'vehicle_number' => $this->vehicle_number,
            'vin' => $this->vin,
            'expected_price' => ($this->expected_price === null || $this->expected_price === '') ? null : (int) $this->expected_price,
            'encar_url' => $this->source === 'encar' ? ($this->encar_url ?: null) : null,
            'encar_dealer' => $this->source === 'encar' ? ($this->encar_dealer ?: null) : null,
            'auction_venue' => $this->source === 'auction' ? ($this->auction_venue ?: null) : null,
            'lot_number' => $this->source === 'auction' ? ($this->lot_number ?: null) : null,
            'lock_at' => $this->source === 'auction' ? TimeGate::auctionLockAt() : null,
            'status' => 'draft',
            'buyer_verdict' => 'none',
        ]);

        $this->resetForm();
        $this->showAdd = false;
        unset($this->listings);
        session()->flash('ok', '매입예정이 등록되었습니다.');
    }

    private function resetForm(): void
    {
        $this->reset(['vehicle_number', 'vin', 'expected_price', 'encar_url', 'encar_dealer', 'auction_venue', 'lot_number']);
        $this->source = 'encar';
        $this->resetErrorBag();
    }

    public function with(): array
    {
        return ['auctionLocked' => TimeGate::auctionRegistrationLocked()];
    }
}; ?>

<div class="p-3 md:p-6">
    {{-- 헤더 --}}
    <div class="mb-4 flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-gray-800">매입예정 (영업)</h1>
            <p class="mt-0.5 text-xs text-gray-500">🔒 본인({{ auth()->user()->name }}) 리스트만 표시 — 서버/DB 레벨 격리</p>
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

                <div class="grid gap-3 sm:grid-cols-3">
                    <div>
                        <label class="label-base">차량번호 <span class="text-red-500">*</span></label>
                        <input class="input-base" wire:model="vehicle_number" placeholder="12가3456">
                        @error('vehicle_number') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label-base">차대번호 VIN <span class="text-red-500">*</span></label>
                        <input class="input-base" wire:model="vin" placeholder="KMHxxxxxxxxxxxxxx">
                        @error('vin') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label-base">예상가 (선택)</label>
                        <input class="input-base" wire:model="expected_price" placeholder="13500000" inputmode="numeric">
                        @error('expected_price') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                @if ($source === 'encar')
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <div><label class="label-base">엔카 매물 URL / 매물번호</label><input class="input-base" wire:model="encar_url" placeholder="encar.com/... 또는 매물번호"></div>
                        <div><label class="label-base">판매 딜러 / 지역</label><input class="input-base" wire:model="encar_dealer" placeholder="예: 강남지점"></div>
                    </div>
                    <p class="mt-2 text-xs text-gray-500">💡 엔카는 공식 API가 없습니다. 매물 URL/번호를 식별용으로만 기록.</p>
                @else
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <div><label class="label-base">경매장</label><input class="input-base" wire:model="auction_venue" placeholder="롯데 / 현대 글로비스"></div>
                        <div><label class="label-base">출품번호</label><input class="input-base" wire:model="lot_number" placeholder="A-1024"></div>
                    </div>
                @endif

                <p class="mt-2 text-xs text-gray-500">차량번호·VIN은 중복 방지 식별키라 <b>필수</b>이며 등록 후 수정 불가. 예상가는 선택 — 현지 차상태 확인 후 <b>최종금액으로 확정</b>됩니다.</p>
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
                    <tr><th>차량</th><th>출처</th><th>예상가</th><th>현지 최종금액</th><th>바이어</th><th>상태</th></tr>
                </thead>
                <tbody>
                    @forelse ($this->listings as $l)
                        <tr>
                            <td>
                                <div class="font-semibold text-gray-800">{{ $l->vehicle_number }}</div>
                                <div class="text-xs text-gray-400">VIN ·{{ \Illuminate\Support\Str::limit($l->vin, 10, '') }}{{ $l->isAuction() && $l->lot_number ? ' · '.$l->auction_venue.' '.$l->lot_number : '' }}</div>
                            </td>
                            <td><span class="badge {{ $l->isAuction() ? 'badge-auction' : 'badge-encar' }}">{{ $l->isAuction() ? '경매' : '엔카' }}</span></td>
                            <td class="text-gray-700">{{ $l->expected_price ? number_format($l->expected_price).'원' : '—' }}</td>
                            <td class="font-semibold {{ $l->final_price ? 'text-[var(--color-primary-text)]' : 'text-gray-400' }}">{{ $l->final_price ? number_format($l->final_price).'원' : '—' }}</td>
                            <td>@if ($l->verdictLabel())<span class="badge {{ $l->verdictBadge() }}">{{ $l->verdictLabel() }}</span>@else<span class="text-gray-300">—</span>@endif</td>
                            <td><span class="badge {{ $l->statusBadge() }}">{{ $l->statusLabel() }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-8 text-center text-gray-400">매입예정이 없습니다. “+ 매입예정 추가”로 등록하세요.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

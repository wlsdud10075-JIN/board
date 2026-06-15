<?php

use App\Models\PurchaseListing;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    // ── 필터 (DB 레벨 — 수천 건에서도 인덱스 + 페이지네이션) ──
    public string $fStatus = '';
    public string $fSource = '';
    public string $fVerdict = '';
    public bool $fToday = false;
    public string $search = '';

    public ?int $editingId = null;
    public string $vehicle_number = '';
    public string $vin = '';
    public string $owner_name = '';
    public string $c_no = '';
    public string $source = 'encar';
    public string $region = '';
    public ?string $expected_price = null;
    public ?string $car_cost = null;
    public ?string $discount_rate = null;
    public ?int $shipping_usd = null;
    public ?string $final_price = null;
    public string $status = 'draft';
    public string $buyer_verdict = 'none';
    public string $buyer_name = '';
    public string $inspection_memo = '';
    public string $inspection_note = '';
    public string $payee_name = '';
    public string $payee_bank = '';
    public string $payee_account = '';
    public string $encar_url = '';
    public string $encar_dealer = '';
    public string $auction_venue = '';
    public string $lot_number = '';

    /** 드로어 편집 대상 전체 — openEdit/closeEdit/save 공통. */
    private const EDIT_FIELDS = [
        'vehicle_number', 'vin', 'owner_name', 'c_no', 'source', 'region',
        'expected_price', 'car_cost', 'discount_rate', 'shipping_usd', 'final_price',
        'status', 'buyer_verdict', 'buyer_name', 'inspection_memo', 'inspection_note',
        'payee_name', 'payee_bank', 'payee_account',
        'encar_url', 'encar_dealer', 'auction_venue', 'lot_number',
    ];

    /** 필터 변경 시 1페이지로 (wire:model.live 항목 공통) */
    public function updated($prop): void
    {
        if (in_array($prop, ['fStatus', 'fSource', 'fVerdict', 'fToday', 'search'], true)) {
            $this->resetPage();
        }
    }

    /** KPI 클릭 = 그 차원 필터 토글(다시 누르면 해제). */
    public function kpiFilter(string $key): void
    {
        match ($key) {
            'today' => $this->fToday = ! $this->fToday,
            'encar' => $this->fSource = $this->fSource === 'encar' ? '' : 'encar',
            'auction' => $this->fSource = $this->fSource === 'auction' ? '' : 'auction',
            'accepted' => $this->fVerdict = $this->fVerdict === 'accepted' ? '' : 'accepted',
            'won' => $this->fStatus = $this->fStatus === 'won' ? '' : 'won',
            default => null,
        };
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['fStatus', 'fSource', 'fVerdict', 'fToday', 'search']);
        $this->resetPage();
    }

    /** KPI = 전체 개요 카운트(인덱스 COUNT, 목록 로드 안 함). */
    #[Computed]
    public function kpi(): array
    {
        return [
            'today' => PurchaseListing::whereDate('created_at', today())->count(),
            'encar' => PurchaseListing::where('source', 'encar')->count(),
            'auction' => PurchaseListing::where('source', 'auction')->count(),
            'accepted' => PurchaseListing::where('buyer_verdict', 'accepted')->count(),
            'won' => PurchaseListing::where('status', 'won')->count(),
        ];
    }

    #[Computed]
    public function listings()
    {
        return PurchaseListing::with('creator')
            ->when($this->fStatus !== '', fn ($q) => $q->where('status', $this->fStatus))
            ->when($this->fSource !== '', fn ($q) => $q->where('source', $this->fSource))
            ->when($this->fVerdict !== '', fn ($q) => $q->where('buyer_verdict', $this->fVerdict))
            ->when($this->fToday, fn ($q) => $q->whereDate('created_at', today()))
            ->when($this->search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('vehicle_number', 'like', '%'.$this->search.'%')
                ->orWhere('c_no', 'like', '%'.$this->search.'%')
                ->orWhere('owner_name', 'like', '%'.$this->search.'%')))
            ->latest()
            ->paginate(20);
    }

    #[Computed]
    public function editing(): ?PurchaseListing
    {
        return $this->editingId ? PurchaseListing::find($this->editingId) : null;
    }

    public function openEdit(int $id): void
    {
        $l = PurchaseListing::findOrFail($id);
        $this->editingId = $l->id;
        $this->vehicle_number = $l->vehicle_number;
        $this->vin = $l->vin ?? '';
        $this->owner_name = $l->owner_name ?? '';
        $this->c_no = $l->c_no ?? '';
        $this->source = $l->source;
        $this->region = $l->region ?? '';
        $this->expected_price = $l->expected_price !== null ? (string) $l->expected_price : null;
        $this->car_cost = $l->car_cost !== null ? (string) $l->car_cost : null;
        $this->discount_rate = $l->discount_rate !== null ? (string) $l->discount_rate : null;
        $this->shipping_usd = $l->shipping_usd;
        $this->final_price = $l->final_price !== null ? (string) $l->final_price : null;
        $this->status = $l->status;
        $this->buyer_verdict = $l->buyer_verdict;
        $this->buyer_name = $l->buyer_name ?? '';
        $this->inspection_memo = $l->inspection_memo ?? '';
        $this->inspection_note = $l->inspection_note ?? '';
        $this->payee_name = $l->payee_name ?? '';
        $this->payee_bank = $l->payee_bank ?? '';
        $this->payee_account = $l->payee_account ?? '';
        $this->encar_url = $l->encar_url ?? '';
        $this->encar_dealer = $l->encar_dealer ?? '';
        $this->auction_venue = $l->auction_venue ?? '';
        $this->lot_number = $l->lot_number ?? '';
        $this->resetErrorBag();
    }

    public function closeEdit(): void
    {
        $this->reset(self::EDIT_FIELDS);
        $this->editingId = null;
        unset($this->editing, $this->listings, $this->kpi);
    }

    public function save(): void
    {
        $this->validate([
            'vehicle_number' => 'required|string|max:20',
            'vin' => ['nullable', 'string', 'max:32', Rule::unique('purchase_listings', 'vin')->ignore($this->editingId)],
            'owner_name' => 'nullable|string|max:60',
            'c_no' => 'nullable|string|max:50',
            'source' => 'required|in:encar,auction',
            'region' => 'nullable|string|max:60',
            'expected_price' => 'nullable|numeric|min:0',
            'car_cost' => 'nullable|numeric|min:0',
            'discount_rate' => 'nullable|numeric|min:0|max:100',
            'shipping_usd' => 'nullable|integer|in:'.implode(',', config('board.shipping_options')),
            'final_price' => 'nullable|numeric|min:0',
            'status' => 'required|in:'.implode(',', PurchaseListing::STATUSES),
            'buyer_verdict' => 'required|in:none,pending,accepted,rejected',
            'buyer_name' => 'nullable|string|max:100',
            'inspection_memo' => 'nullable|string|max:500',
            'inspection_note' => 'nullable|string|max:255',
            'payee_name' => 'nullable|string|max:60',
            'payee_bank' => 'nullable|string|max:40',
            'payee_account' => 'nullable|string|max:40',
            'encar_url' => 'nullable|string|max:255',
            'encar_dealer' => 'nullable|string|max:100',
            'auction_venue' => 'nullable|string|max:100',
            'lot_number' => 'nullable|string|max:50',
        ]);

        $l = PurchaseListing::findOrFail($this->editingId);

        // 식별값(차량번호·VIN)은 car-erp 미연동 차량만 정정 가능 (오타 수정). 연동 후엔 잠금 유지.
        if ($l->car_erp_vehicle_id === null) {
            $l->vehicle_number = $this->vehicle_number;
            $l->vin = $this->vin ?: null;
        }
        $l->owner_name = $this->owner_name ?: null;
        $l->c_no = $this->c_no ?: null;
        $l->source = $this->source;
        $l->region = $this->region ?: null;
        $l->expected_price = ($this->expected_price === null || $this->expected_price === '') ? null : (int) $this->expected_price;
        $l->car_cost = ($this->car_cost === null || $this->car_cost === '') ? null : (int) $this->car_cost;
        $l->discount_rate = ($this->discount_rate === null || $this->discount_rate === '') ? null : (float) $this->discount_rate;
        $l->shipping_usd = $this->shipping_usd ?: null;
        $l->final_price = ($this->final_price === null || $this->final_price === '') ? null : (int) $this->final_price;
        $l->status = $this->status;
        $l->buyer_verdict = $this->buyer_verdict;
        $l->buyer_name = $this->buyer_name ?: null;
        $l->inspection_memo = $this->inspection_memo ?: null;
        $l->inspection_note = $this->inspection_note ?: null;
        $l->payee_name = $this->payee_name ?: null;
        $l->payee_bank = $this->payee_bank ?: null;
        $l->payee_account = $this->payee_account ?: null;
        if ($this->source === 'encar') {
            $l->encar_url = $this->encar_url ?: null;
            $l->encar_dealer = $this->encar_dealer ?: null;
        } else {
            $l->auction_venue = $this->auction_venue ?: null;
            $l->lot_number = $this->lot_number ?: null;
        }

        // 시간잠금·상태전이 무관 수정 (식별값은 미연동 차량만 모델이 허용)
        // 변경 감사기록은 모델 옵저버가 자동 처리(BoardAudit).
        $l->allowManagerOverride = true;
        $l->save();

        session()->flash('ok', $l->vehicle_number.' 수정 완료 — 변경 내역이 감사로그에 기록됐습니다.');
        $this->closeEdit();
    }
}; ?>

<div class="p-3 md:p-6">
    <div class="mb-4">
        <h1 class="text-xl font-bold text-gray-800">관리자</h1>
        <p class="mt-0.5 text-xs text-gray-500">✏️ 시간잠금 무관 수정 (예상가·최종금액·출처·상태) — 단 <b>차량번호·VIN은 수정 불가</b>. 모든 변경은 감사로그 기록.</p>
    </div>

    @if (session('ok'))
        <div class="card-sm mb-3 border-green-200 bg-green-50 text-[13px] text-green-700">✓ {{ session('ok') }}</div>
    @endif

    {{-- KPI (클릭 = 그 차원 필터 토글) --}}
    @php $k = $this->kpi; @endphp
    <div class="mb-4 grid grid-cols-2 gap-3 md:grid-cols-5">
        <button type="button" wire:click="kpiFilter('today')" class="kpi text-left {{ $fToday ? 'ring-2 ring-[var(--color-primary)]' : '' }}"><div class="k">오늘 매입예정</div><div class="v">{{ $k['today'] }}</div></button>
        <button type="button" wire:click="kpiFilter('encar')" class="kpi text-left {{ $fSource === 'encar' ? 'ring-2 ring-[var(--color-encar)]' : '' }}"><div class="k">엔카</div><div class="v" style="color:var(--color-encar)">{{ $k['encar'] }}</div></button>
        <button type="button" wire:click="kpiFilter('auction')" class="kpi text-left {{ $fSource === 'auction' ? 'ring-2 ring-[var(--color-auction)]' : '' }}"><div class="k">경매</div><div class="v" style="color:var(--color-auction)">{{ $k['auction'] }}</div></button>
        <button type="button" wire:click="kpiFilter('accepted')" class="kpi text-left {{ $fVerdict === 'accepted' ? 'ring-2 ring-green-500' : '' }}"><div class="k">바이어 수락</div><div class="v" style="color:#16a34a">{{ $k['accepted'] }}</div></button>
        <button type="button" wire:click="kpiFilter('won')" class="kpi text-left {{ $fStatus === 'won' ? 'ring-2 ring-[var(--color-primary)]' : '' }}"><div class="k">ERP 전환대기</div><div class="v" style="color:var(--color-primary)">{{ $k['won'] }}</div></button>
    </div>

    {{-- 전체 현황 --}}
    <div class="card">
        <div class="mb-3 flex flex-wrap items-center gap-2">
            <h2 class="font-bold text-gray-800">전체 현황</h2>
            <span class="pill-count">{{ number_format($this->listings->total()) }}건</span>
            <div class="ml-auto flex flex-wrap items-center gap-2">
                <input class="input-base !w-44 !py-1 text-sm" wire:model.live.debounce.400ms="search" placeholder="차량번호·매물번호·소유자">
                <select class="input-base !w-28 !py-1 text-sm" wire:model.live="fStatus">
                    <option value="">상태 전체</option>
                    @foreach (\App\Models\PurchaseListing::STATUS_LABELS as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach
                </select>
                <select class="input-base !w-24 !py-1 text-sm" wire:model.live="fSource">
                    <option value="">출처</option><option value="encar">엔카</option><option value="auction">경매</option>
                </select>
                @if ($fStatus || $fSource || $fVerdict || $fToday || $search)
                    <button class="btn-ghost btn-sm" wire:click="clearFilters">필터해제 ✕</button>
                @endif
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead>
                    <tr><th>차량</th><th>출처</th><th>영업</th><th>예상가</th><th>최종금액</th><th>바이어</th><th>상태</th><th></th></tr>
                </thead>
                <tbody>
                    @forelse ($this->listings as $l)
                        <tr>
                            <td class="font-semibold text-gray-800">{{ $l->vehicle_number }}</td>
                            <td><span class="badge {{ $l->isAuction() ? 'badge-auction' : 'badge-encar' }}">{{ $l->isAuction() ? '경매' : '엔카' }}</span></td>
                            <td class="text-gray-600">{{ $l->creator->name }}</td>
                            <td class="text-gray-700">{{ $l->expected_price ? number_format($l->expected_price) : '—' }}</td>
                            <td class="font-semibold text-[var(--color-primary-text)]">{{ $l->final_price ? number_format($l->final_price) : '—' }}</td>
                            <td>@if ($l->verdictLabel())<span class="badge {{ $l->verdictBadge() }}">{{ $l->verdictLabel() }}</span>@else<span class="text-gray-300">—</span>@endif</td>
                            <td><span class="badge {{ $l->statusBadge() }}">{{ $l->statusLabel() }}</span></td>
                            <td><button class="btn-outline btn-sm" wire:click="openEdit({{ $l->id }})">✏️ 수정</button></td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="py-8 text-center text-gray-400">조건에 맞는 데이터가 없습니다.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $this->listings->links() }}</div>
    </div>

    {{-- 수정 드로어 --}}
    @if ($this->editing)
        @php $e = $this->editing; @endphp
        <div class="fixed inset-0 z-40 bg-black/40" wire:click="closeEdit"></div>
        <div class="fixed inset-y-0 right-0 z-50 w-full overflow-y-auto bg-white shadow-xl sm:w-[440px]">
            <div class="flex items-center justify-between border-b border-gray-200 px-5 py-4">
                <h3 class="font-bold text-gray-800">{{ $e->vehicle_number }} · 수정</h3>
                <button class="text-gray-400 hover:text-gray-600" wire:click="closeEdit">✕</button>
            </div>
            <div class="px-5 py-4">
                @if ($e->car_erp_vehicle_id === null)
                    <label class="label-base">차량번호 <span class="text-xs font-normal text-amber-600">· 오타 정정 가능</span></label>
                    <input class="input-base" wire:model="vehicle_number">
                    @error('vehicle_number') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    <label class="label-base mt-3">차대번호 VIN</label>
                    <input class="input-base" wire:model="vin">
                    @error('vin') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                @else
                    <div class="card-sm bg-gray-50 text-xs text-gray-500">
                        차량번호 <b>{{ $e->vehicle_number }}</b> · VIN <b>{{ $e->vin }}</b><br>
                        <span class="text-gray-400">🔗 이미 car-erp 연동된 차량 — 식별값 수정 불가</span>
                    </div>
                @endif

                <label class="label-base mt-3">소유자 (차주명)</label>
                <input class="input-base" wire:model="owner_name">
                @error('owner_name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                <label class="label-base mt-3">매물번호 (c_no)</label>
                <input class="input-base" wire:model="c_no">
                @error('c_no') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                <label class="label-base mt-3">출처</label>
                <select class="input-base" wire:model="source">
                    <option value="encar">엔카</option>
                    <option value="auction">경매</option>
                </select>

                <label class="label-base mt-3">지역</label>
                <input class="input-base" wire:model="region" list="regionListManage" placeholder="검사지역">
                <datalist id="regionListManage">@foreach (config('board.regions') as $r)<option value="{{ $r }}">@endforeach</datalist>
                @error('region') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                <div class="mt-3 grid grid-cols-3 gap-2">
                    <div><label class="label-base">차값</label><input class="input-base" wire:model="car_cost" inputmode="numeric"></div>
                    <div><label class="label-base">할인율%</label><input class="input-base" wire:model="discount_rate" inputmode="decimal"></div>
                    <div><label class="label-base">배송$</label>
                        <select class="input-base" wire:model="shipping_usd">
                            <option value="">—</option>
                            @foreach (config('board.shipping_options') as $opt)<option value="{{ $opt }}">{{ $opt }}</option>@endforeach
                        </select>
                    </div>
                </div>
                @error('car_cost') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                @error('discount_rate') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                @error('shipping_usd') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                <label class="label-base mt-3">예상가</label>
                <input class="input-base" wire:model="expected_price" inputmode="numeric">
                @error('expected_price') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                <label class="label-base mt-3">현지 최종금액</label>
                <input class="input-base" wire:model="final_price" inputmode="numeric">
                @error('final_price') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                <label class="label-base mt-3">상태</label>
                <select class="input-base" wire:model="status">
                    @foreach (\App\Models\PurchaseListing::STATUS_LABELS as $val => $label)
                        <option value="{{ $val }}">{{ $label }}</option>
                    @endforeach
                </select>

                <label class="label-base mt-3">바이어 회신</label>
                <select class="input-base" wire:model="buyer_verdict">
                    <option value="none">없음</option>
                    <option value="pending">회신대기</option>
                    <option value="accepted">수락</option>
                    <option value="rejected">거절</option>
                </select>

                <label class="label-base mt-3">바이어명</label>
                <input class="input-base" wire:model="buyer_name">

                <label class="label-base mt-3">메모 (차상태)</label>
                <input class="input-base" wire:model="inspection_memo">
                @error('inspection_memo') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                <label class="label-base mt-3">추가검사사항</label>
                <input class="input-base" wire:model="inspection_note">
                @error('inspection_note') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                {{-- 출처별 식별값 --}}
                @if ($source === 'encar')
                    <label class="label-base mt-3">엔카 매물 URL</label>
                    <input class="input-base" wire:model="encar_url">
                    <label class="label-base mt-3">엔카 딜러</label>
                    <input class="input-base" wire:model="encar_dealer">
                @else
                    <label class="label-base mt-3">경매장</label>
                    <input class="input-base" wire:model="auction_venue">
                    <label class="label-base mt-3">출품번호</label>
                    <input class="input-base" wire:model="lot_number">
                @endif

                {{-- 입금정보 (계좌는 암호화 저장, 감사로그엔 마스킹) --}}
                <div class="section-title-sm mt-4">입금정보 <span class="text-[11px] font-normal text-gray-400">(정산 계좌)</span></div>
                <div class="grid grid-cols-2 gap-2">
                    <input class="input-base" wire:model="payee_bank" placeholder="은행">
                    <input class="input-base" wire:model="payee_name" placeholder="예금주">
                </div>
                <input class="input-base mt-2 font-mono" wire:model="payee_account" placeholder="계좌번호 (암호화)">
                @error('payee_account') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                <div class="mt-5 flex gap-2">
                    <button class="btn-primary flex-1 justify-center" wire:click="save">저장 (감사로그 기록)</button>
                    <button class="btn-ghost" wire:click="closeEdit">취소</button>
                </div>
            </div>
        </div>
    @endif
</div>

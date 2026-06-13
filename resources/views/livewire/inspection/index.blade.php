<?php

use App\Models\InspectionAssignment;
use App\Models\PurchaseListing;
use App\Models\User;
use App\Services\ExchangeRateService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('components.layouts.app')] class extends Component {
    use WithFileUploads;

    public ?int $editingId = null;
    public ?string $final_price = null;
    public string $inspection_memo = '';
    public string $buyer_name = '';
    public array $photos = [];

    // ── 지역 배정 (§6c) ──
    public string $assignDate = '';
    public string $assignRegion = '';
    public ?int $assignUserId = null;

    // ── 환율 (§6a 라이브) + 표시통화 토글 ──
    public int $krwPerUsd = 0;
    public int $krwPerEur = 0;
    public string $displayCurrency = 'KRW';

    private function usdRate(): int
    {
        return $this->krwPerUsd ?: (int) config('board.default_krw_per_usd');
    }

    private function eurRate(): int
    {
        return $this->krwPerEur ?: (int) config('board.default_krw_per_eur');
    }

    /** KRW 금액을 표시통화로 변환+포맷. */
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

    // ── 검사지역 + 금액 재설계 (§6) ──
    public string $region = '';
    public string $inspection_note = '';
    public ?string $car_cost = null;       // 차값 (KRW)
    public ?string $discount_rate = null;  // 할인율 (%)
    public ?int $shipping_usd = null;      // 배송금액 (USD 고정 택1)

    /** 입력값 기준 차량금액(KRW) 미리보기 = 차값 − (차값 × 할인율%) + 매도비. */
    public function carPricePreview(): ?int
    {
        if ($this->car_cost === null || $this->car_cost === '') {
            return null;
        }
        $cost = (int) $this->car_cost;
        $discount = (int) round($cost * ((float) $this->discount_rate / 100));

        return $cost - $discount + (int) config('board.sales_fee');
    }

    /** 최종금액(KRW) 미리보기 = 차량금액 + 배송(USD→KRW, 임시환율). */
    public function totalPreview(): ?int
    {
        $car = $this->carPricePreview();
        if ($car === null) {
            return null;
        }
        $shipKrw = $this->shipping_usd ? $this->shipping_usd * $this->usdRate() : 0;

        return $car + $shipKrw;
    }

    public function mount(ExchangeRateService $rates): void
    {
        $this->assignDate = now()->toDateString();
        $rates->refreshIfStale();   // 오래됐을 때만 갱신(lazy)
        $this->krwPerUsd = $rates->krwPerUsd();
        $this->krwPerEur = $rates->krwPerEur();
    }

    /** 관리/시스템관리자만 인원 배정 가능. 현지확인 담당자는 본인 배정만 열람. */
    public function canAssign(): bool
    {
        return Auth::user()->isManager() || Auth::user()->isSuper();
    }

    /** 배정 대상 = 활성 현지확인(inspection) 계정. */
    #[Computed]
    public function inspectors()
    {
        return User::where('role', 'inspection')->where('is_active', true)->orderBy('name')->get();
    }

    /** 오늘(assignDate) 배정 — 지역별 그룹. */
    #[Computed]
    public function assignmentsByRegion()
    {
        return InspectionAssignment::with('user')
            ->where('date', $this->assignDate)
            ->get()
            ->groupBy('region');
    }

    /** 현지확인 대상 차량을 지역별로 그룹. 담당자(비관리)는 본인 오늘 배정 지역만. */
    #[Computed]
    public function regionGroups()
    {
        $listings = PurchaseListing::with(['creator', 'photos'])
            ->whereIn('status', ['draft', 'awaiting_buyer', 'accepted'])
            ->latest()
            ->get();

        if (! $this->canAssign()) {
            $myRegions = InspectionAssignment::where('date', $this->assignDate)
                ->where('user_id', Auth::id())
                ->pluck('region')->all();
            $listings = $listings->filter(fn ($l) => in_array($l->region, $myRegions, true));
        }

        return $listings->groupBy(fn ($l) => $l->region ?: '지역 미지정');
    }

    /** 배정 대상 지역 후보 = 현재 검차대기 차량이 있는 지역(미지정 제외). */
    #[Computed]
    public function pendingRegions()
    {
        return PurchaseListing::whereIn('status', ['draft', 'awaiting_buyer', 'accepted'])
            ->whereNotNull('region')
            ->distinct()->orderBy('region')->pluck('region');
    }

    // ── 배정 현황 요약 정렬 ──
    public string $sortBy = 'region';   // region | cars | people
    public string $sortDir = 'asc';

    public function sortByCol(string $col): void
    {
        if ($this->sortBy === $col) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $col;
            $this->sortDir = 'asc';
        }
    }

    /** 관리용 배정 현황 요약: 지역별 [배정인원 · 차량수] (정렬 가능). */
    #[Computed]
    public function assignmentSummary()
    {
        $cars = $this->regionGroups;            // 관리/super = 전체 지역
        $assign = $this->assignmentsByRegion;
        $regions = $cars->keys()->merge($assign->keys())->unique()->values();

        $rows = $regions->map(fn ($r) => [
            'region' => $r,
            'cars' => $cars->get($r)?->count() ?? 0,
            'people' => $assign->get($r)?->pluck('user.name')->all() ?? [],
            'peopleCount' => $assign->get($r)?->count() ?? 0,
        ]);

        return $rows->sortBy(
            fn ($x) => $this->sortBy === 'cars' ? $x['cars'] : ($this->sortBy === 'people' ? $x['peopleCount'] : $x['region']),
            SORT_REGULAR,
            $this->sortDir === 'desc',
        )->values();
    }

    public function assign(): void
    {
        abort_unless($this->canAssign(), 403);
        $this->validate([
            'assignRegion' => 'required|string|max:60',
            'assignUserId' => 'required|integer|exists:users,id',
        ], attributes: ['assignRegion' => '지역', 'assignUserId' => '담당자']);

        $count = InspectionAssignment::where('date', $this->assignDate)->where('region', $this->assignRegion)->count();
        if ($count >= InspectionAssignment::MAX_PER_REGION) {
            $this->addError('assignUserId', '지역당 최대 '.InspectionAssignment::MAX_PER_REGION.'인까지 배정할 수 있습니다.');

            return;
        }

        $u = User::find($this->assignUserId);
        if (! $u || $u->role !== 'inspection') {
            $this->addError('assignUserId', '현지확인 담당자만 배정할 수 있습니다.');

            return;
        }

        InspectionAssignment::firstOrCreate([
            'date' => $this->assignDate,
            'region' => $this->assignRegion,
            'user_id' => $this->assignUserId,
        ]);
        $this->assignUserId = null;
        unset($this->assignmentsByRegion, $this->regionGroups, $this->assignmentSummary);
        session()->flash('ok', '배정되었습니다.');
    }

    public function unassign(int $id): void
    {
        abort_unless($this->canAssign(), 403);
        InspectionAssignment::whereKey($id)->delete();
        unset($this->assignmentsByRegion, $this->regionGroups, $this->assignmentSummary);
    }

    #[Computed]
    public function editing(): ?PurchaseListing
    {
        return $this->editingId ? PurchaseListing::with('photos')->find($this->editingId) : null;
    }

    public function openDrawer(int $id): void
    {
        $l = PurchaseListing::findOrFail($id);
        $this->editingId = $l->id;
        $this->final_price = $l->final_price !== null ? (string) $l->final_price : null;
        $this->inspection_memo = $l->inspection_memo ?? '';
        $this->buyer_name = $l->buyer_name ?? '';
        $this->region = $l->region ?? '';
        $this->inspection_note = $l->inspection_note ?? '';
        $this->car_cost = $l->car_cost !== null ? (string) $l->car_cost : null;
        $this->discount_rate = $l->discount_rate !== null ? (string) $l->discount_rate : null;
        $this->shipping_usd = $l->shipping_usd;
        $this->photos = [];
        $this->resetErrorBag();
    }

    public function closeDrawer(): void
    {
        $this->reset(['editingId', 'final_price', 'inspection_memo', 'buyer_name', 'photos',
            'region', 'inspection_note', 'car_cost', 'discount_rate', 'shipping_usd']);
        unset($this->editing, $this->regionGroups);
    }

    /** 입력된 검사지역·금액 필드를 모델에 반영 + final_price 에 최종금액(KRW) 스냅샷. */
    private function applyInspectionFields(PurchaseListing $l): void
    {
        $l->region = $this->region ?: null;
        $l->inspection_note = $this->inspection_note ?: null;
        $l->car_cost = ($this->car_cost === null || $this->car_cost === '') ? null : (int) $this->car_cost;
        $l->discount_rate = ($this->discount_rate === null || $this->discount_rate === '') ? null : (float) $this->discount_rate;
        $l->shipping_usd = $this->shipping_usd ?: null;
        // 최종금액(KRW) 스냅샷 — 공식 입력이 있으면 자동 계산, 없으면 수동 final_price 유지.
        $computed = $l->totalKrw($this->usdRate());
        if ($computed !== null) {
            $l->final_price = $computed;
        } elseif ($this->final_price !== null && $this->final_price !== '') {
            $l->final_price = (int) $this->final_price;
        }
    }

    private function persistPhotos(PurchaseListing $l): void
    {
        if (empty($this->photos)) {
            return;
        }
        $disk = config('board.photo_disk');
        $prefix = config('board.inspection_photo_prefix').'/'.$l->id;
        $start = (int) $l->photos()->max('sort');

        foreach (array_values($this->photos) as $i => $file) {
            $path = $file->store($prefix, $disk);
            $l->photos()->create([
                's3_path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'sort' => $start + $i + 1,
            ]);
        }
        $this->photos = [];
    }

    private function pricingRules(): array
    {
        return [
            'final_price' => 'nullable|numeric|min:0',
            'region' => 'nullable|string|max:60',
            'inspection_note' => 'nullable|string|max:255',
            'car_cost' => 'nullable|numeric|min:0',
            'discount_rate' => 'nullable|numeric|min:0|max:100',
            'shipping_usd' => 'nullable|integer|in:'.implode(',', config('board.shipping_options')),
        ];
    }

    public function saveDraft(): void
    {
        $this->validate($this->pricingRules());
        $l = PurchaseListing::findOrFail($this->editingId);
        $l->inspection_memo = $this->inspection_memo ?: null;
        if ($this->buyer_name !== '') {
            $l->buyer_name = $this->buyer_name;
        }
        $this->applyInspectionFields($l);
        $l->save();
        $this->persistPhotos($l);
        session()->flash('ok', '저장되었습니다.');
        $this->closeDrawer();
    }

    public function sendToBuyer(): void
    {
        $this->validate($this->pricingRules() + [
            'buyer_name' => 'required|string|max:100',
        ], attributes: ['buyer_name' => '바이어명']);

        // 최종금액 = 공식(차값) 또는 수동 final_price 중 하나는 있어야 전달 가능.
        if ($this->carPricePreview() === null && ($this->final_price === null || $this->final_price === '')) {
            $this->addError('car_cost', '차값(또는 최종금액)을 입력해야 바이어에게 전달할 수 있습니다.');

            return;
        }

        $l = PurchaseListing::findOrFail($this->editingId);
        $l->inspection_memo = $this->inspection_memo ?: null;
        $l->buyer_name = $this->buyer_name;
        $this->applyInspectionFields($l);
        if ($l->status === 'draft') {
            $l->status = 'awaiting_buyer';
            $l->buyer_verdict = 'pending';
        }
        $l->save();
        $this->persistPhotos($l);
        session()->flash('ok', '사진+최종금액을 바이어에게 전달했습니다 (회신대기).');
        $this->closeDrawer();
    }

    public function setVerdict(string $v): void
    {
        $l = PurchaseListing::findOrFail($this->editingId);

        if ($l->status !== 'awaiting_buyer') {
            $this->addError('verdict', '먼저 바이어에게 전달한 뒤 회신을 기록할 수 있습니다.');

            return;
        }

        if ($v === 'accepted') {
            $l->buyer_verdict = 'accepted';
            $l->status = 'accepted';
        } elseif ($v === 'rejected') {
            $l->buyer_verdict = 'rejected';
            $l->status = 'rejected';
        }
        $l->save();
        session()->flash('ok', '바이어 회신을 반영했습니다.');
        $this->closeDrawer();
    }

    public function photoUrl(string $path): string
    {
        return Storage::disk(config('board.photo_disk'))->url($path);
    }
}; ?>

<div class="p-3 md:p-6">
    <div class="mb-4">
        <h1 class="text-xl font-bold text-gray-800">현지확인</h1>
        <p class="mt-0.5 text-xs text-gray-500">📍 지역별 그룹 · {{ $this->canAssign() ? '관리: 그날치 인원 배정' : '본인 배정 지역만 표시' }} → 차 상태 확인 → 최종금액 산정 → 바이어 전달</p>
    </div>

    @if (session('ok'))
        <div class="card-sm mb-3 border-green-200 bg-green-50 text-[13px] text-green-700">✓ {{ session('ok') }}</div>
    @endif

    {{-- ─────────── 지역 배정 패널 (관리/super) ─────────── --}}
    @if ($this->canAssign())
        <div class="card mb-3" style="background:#f8f9fb">
            <div class="mb-2 flex items-center justify-between">
                <h2 class="font-bold text-gray-800">📋 오늘 지역 배정 <span class="text-xs font-normal text-gray-400">({{ $assignDate }})</span></h2>
                <span class="text-xs text-gray-400">지역당 최대 {{ \App\Models\InspectionAssignment::MAX_PER_REGION }}인</span>
            </div>
            <div class="flex flex-wrap items-end gap-2">
                <div class="min-w-[160px] flex-1">
                    <label class="label-base">지역</label>
                    <select class="input-base" wire:model="assignRegion">
                        <option value="">지역 선택</option>
                        @foreach ($this->pendingRegions as $r)<option value="{{ $r }}">{{ $r }}</option>@endforeach
                    </select>
                </div>
                <div class="min-w-[140px] flex-1">
                    <label class="label-base">담당자 (현지확인)</label>
                    <select class="input-base" wire:model="assignUserId">
                        <option value="">담당자 선택</option>
                        @foreach ($this->inspectors as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach
                    </select>
                </div>
                <button class="btn-primary btn-sm" wire:click="assign">+ 배정</button>
            </div>
            @error('assignRegion') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            @error('assignUserId') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            @if ($this->pendingRegions->isEmpty())
                <p class="mt-2 text-xs text-gray-400">검차대기 차량에 <b>지역</b>이 지정되면 여기서 배정할 수 있습니다. (매입예정에서 지역 입력)</p>
            @endif

            {{-- 배정 현황 요약 (정렬 가능) --}}
            @if ($this->assignmentSummary->isNotEmpty())
                @php $arrow = fn ($c) => $sortBy === $c ? ($sortDir === 'asc' ? ' ▲' : ' ▼') : ''; @endphp
                <div class="mt-3 overflow-x-auto">
                    <table class="tbl text-[13px]">
                        <thead>
                            <tr>
                                <th class="cursor-pointer select-none" wire:click="sortByCol('region')">지역{{ $arrow('region') }}</th>
                                <th class="cursor-pointer select-none" wire:click="sortByCol('people')">배정 인원{{ $arrow('people') }}</th>
                                <th class="cursor-pointer select-none" wire:click="sortByCol('cars')">차량 수{{ $arrow('cars') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->assignmentSummary as $row)
                                <tr>
                                    <td class="font-semibold text-gray-800">📍 {{ $row['region'] }}</td>
                                    <td>
                                        @forelse ($row['people'] as $name)
                                            <span class="badge badge-blue">🧑‍🔧 {{ $name }}</span>
                                        @empty
                                            <span class="text-xs text-amber-600">미배정</span>
                                        @endforelse
                                    </td>
                                    <td class="{{ $row['cars'] ? 'font-semibold text-gray-700' : 'text-gray-300' }}">{{ $row['cars'] }}건</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endif

    {{-- ─────────── 지역별 차량 ─────────── --}}
    @forelse ($this->regionGroups as $region => $items)
        @php $assigned = $this->assignmentsByRegion->get($region, collect()); @endphp
        <div class="card mb-3" x-data="{ open: false }">
            <div class="flex flex-wrap items-center gap-2">
                <button type="button" class="flex items-center gap-2" @click="open = !open">
                    <span class="w-3 text-gray-400" x-text="open ? '▼' : '▶'"></span>
                    <h2 class="font-bold text-gray-800">📍 {{ $region }}</h2>
                    <span class="pill-count">{{ $items->count() }}건</span>
                </button>
                <span class="ml-1 flex flex-wrap items-center gap-1">
                    @forelse ($assigned as $a)
                        <span class="badge badge-blue inline-flex items-center gap-1">
                            🧑‍🔧 {{ $a->user->name }}
                            @if ($this->canAssign())<button wire:click="unassign({{ $a->id }})" class="text-blue-400 hover:text-blue-700">✕</button>@endif
                        </span>
                    @empty
                        <span class="text-xs text-gray-400">{{ $this->canAssign() ? '미배정' : '' }}</span>
                    @endforelse
                </span>
            </div>
            <div class="mt-2 flex flex-col gap-2" x-show="open" x-cloak>
                @foreach ($items as $l)
                    <div class="flex cursor-pointer items-center gap-3 rounded-lg border border-gray-200 px-3 py-2.5 hover:bg-gray-50" wire:click="openDrawer({{ $l->id }})">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <span class="font-semibold text-gray-800">{{ $l->vehicle_number }}</span>
                                <span class="badge {{ $l->isAuction() ? 'badge-auction' : 'badge-encar' }}">{{ $l->isAuction() ? '경매' : '엔카' }}</span>
                                <span class="text-xs text-gray-400">{{ $l->creator->name }}</span>
                            </div>
                            <div class="mt-0.5 text-xs text-gray-500">
                                {{ $l->final_price ? '최종 '.number_format($l->final_price).'원' : '금액 미정' }}
                                {{ $l->inspection_note ? '· '.$l->inspection_note : '' }}
                            </div>
                        </div>
                        <span class="badge {{ $l->statusBadge() }}">{{ $l->statusLabel() }}</span>
                        <span class="text-gray-300">›</span>
                    </div>
                @endforeach
            </div>
        </div>
    @empty
        <div class="card text-center text-gray-400">{{ $this->canAssign() ? '현지확인 대상 차량이 없습니다.' : '오늘 배정된 지역이 없습니다. (관리자 배정 대기)' }}</div>
    @endforelse

    {{-- ─────────── 드로어 ─────────── --}}
    @if ($this->editing)
        @php $e = $this->editing; @endphp
        <div class="fixed inset-0 z-40 bg-black/40" wire:click="closeDrawer"></div>
        <div class="fixed inset-y-0 right-0 z-50 w-full overflow-y-auto bg-white shadow-xl sm:w-[460px]">
            <div class="flex items-center justify-between border-b border-gray-200 px-5 py-4">
                <h3 class="font-bold text-gray-800">{{ $e->vehicle_number }} · 현지 확인 <span class="text-xs text-gray-400">({{ $e->isAuction() ? '경매' : '엔카' }})</span></h3>
                <button class="text-gray-400 hover:text-gray-600" wire:click="closeDrawer">✕</button>
            </div>

            <div class="px-5 py-4">
                <p class="text-xs text-gray-500">예상가 {{ $e->expected_price ? number_format($e->expected_price).'원' : '—' }} · 차 상태 보고 최종금액 산정</p>

                {{-- 사진 --}}
                <div class="section-title-sm">차량 사진 (외관만 · 서류/번호판 제외)</div>
                <label class="flex cursor-pointer items-center justify-center rounded-lg border-2 border-dashed border-gray-300 py-5 text-sm text-gray-500 hover:border-[var(--color-primary)]">
                    📷 후면카메라 촬영 / 업로드
                    <input type="file" accept="image/*" capture="environment" multiple wire:model="photos" class="hidden">
                </label>
                <div wire:loading wire:target="photos" class="mt-1 text-xs text-gray-400">업로드 중…</div>
                @error('photos.*') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                @if (count($photos))
                    <p class="mt-2 text-xs text-gray-500">새 사진 {{ count($photos) }}장 — 저장 시 반영</p>
                @endif

                @if ($e->photos->count())
                    <div class="mt-2 grid grid-cols-4 gap-2">
                        @foreach ($e->photos as $p)
                            <img src="{{ $this->photoUrl($p->s3_path) }}" class="aspect-square w-full rounded-md object-cover" alt="">
                        @endforeach
                    </div>
                @endif

                {{-- 검사지역 --}}
                <div class="section-title-sm">검사지역</div>
                <input class="input-base" wire:model="region" list="regionList" placeholder="예: 경기 수원시 (입력 시 자동완성)">
                <datalist id="regionList">
                    @foreach (config('board.regions') as $r)<option value="{{ $r }}">@endforeach
                </datalist>
                @error('region') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                {{-- 메모 --}}
                <div class="section-title-sm">차 상태 메모</div>
                <input class="input-base" wire:model="inspection_memo" placeholder="예: 운전석 시트 사용감, 앞범퍼 미세 스크래치">

                {{-- 추가검사사항 (listings 표에 표시) --}}
                <div class="section-title-sm">추가검사사항</div>
                <input class="input-base" wire:model="inspection_note" placeholder="예: 보증서 미비, 타이어 교체 권장">
                @error('inspection_note') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                {{-- 금액 산정 (§6) --}}
                <div class="section-title-sm flex items-center justify-between">
                    <span>금액 산정</span>
                    <div class="inline-flex overflow-hidden rounded-md border border-gray-300 text-xs font-normal">
                        @foreach (['KRW' => '원', 'USD' => '$', 'EUR' => '€'] as $cur => $sym)
                            <button type="button" wire:click="$set('displayCurrency', '{{ $cur }}')"
                                class="px-2 py-1 font-semibold {{ $displayCurrency === $cur ? 'bg-[var(--color-primary)] text-white' : 'bg-white text-gray-600' }}">{{ $sym }}</button>
                        @endforeach
                    </div>
                </div>
                @php
                    $carPrice = $this->carPricePreview();
                    $total = $this->totalPreview();
                    $rate = $this->usdRate();
                    $shipKrw = $shipping_usd ? (int) $shipping_usd * $rate : null;
                @endphp
                <div class="grid grid-cols-2 gap-3">
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
                </div>
                <div class="mt-2 flex items-center justify-between text-xs text-gray-500">
                    <span>＋ 매도비 (고정)</span>
                    <span class="font-semibold text-gray-700">{{ number_format((int) config('board.sales_fee')) }}원</span>
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
                <p class="mt-1 text-[11px] text-gray-400">배송 ${{ number_format((int) $shipping_usd) }} × {{ number_format($rate) }}원 적용</p>

                {{-- 바이어 --}}
                <div class="section-title-sm">바이어에게 전달 / 회신</div>
                <input class="input-base" wire:model="buyer_name" placeholder="바이어명 (respond.io 연락처)">
                @error('buyer_name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                <button class="btn-primary btn-sm mt-2 w-full justify-center" wire:click="sendToBuyer">📤 사진 + 최종금액 바이어에게 전달</button>
                <p class="mt-1 text-xs text-gray-400">⏱ 전달 후 "회신대기"로 두고 다른 차량 진행.</p>

                {{-- 회신 결과 --}}
                <div class="section-title-sm">바이어 회신 결과</div>
                <div class="flex gap-2">
                    <button class="btn-outline btn-sm flex-1 justify-center {{ $e->buyer_verdict === 'pending' ? 'border-amber-400 text-amber-700' : '' }}" wire:click="setVerdict('pending')">⏳ 회신대기</button>
                    <button class="btn-outline btn-sm flex-1 justify-center {{ $e->buyer_verdict === 'accepted' ? 'border-green-500 text-green-700' : '' }}" wire:click="setVerdict('accepted')">👍 수락</button>
                    <button class="btn-outline btn-sm flex-1 justify-center {{ $e->buyer_verdict === 'rejected' ? 'border-red-500 text-red-700' : '' }}" wire:click="setVerdict('rejected')">👎 거절</button>
                </div>
                @error('verdict') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                <p class="mt-1 text-xs text-gray-400">수락한 차량만 경매/구매로 진입합니다.</p>

                <div class="mt-5 flex gap-2">
                    <button class="btn-primary flex-1 justify-center" wire:click="saveDraft">저장</button>
                    <button class="btn-ghost" wire:click="closeDrawer">취소</button>
                </div>
            </div>
        </div>
    @endif
</div>

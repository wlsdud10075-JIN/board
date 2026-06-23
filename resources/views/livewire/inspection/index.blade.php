<?php

use App\Models\InspectionAssignment;
use App\Models\PurchaseListing;
use App\Models\Scopes\SalesmanScope;
use App\Models\User;
use App\Services\ExchangeRateService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
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

    // 드로어 내 "선택 → 저장" 스테이징 (클릭=선택만, 저장 눌러야 커밋).
    // 회신(수락/거절)은 "바이어 회신" 화면으로 일원화 → 현지확인은 전달까지만.
    public bool $sendSelected = false;        // draft: 바이어 전달 예정 선택
    public bool $forceManualSend = false;     // (가) 가드: 수동 채널로 강제 전달
    public ?string $sendConflictWith = null;  // 같은 바이어 자동 회신대기 차(차량번호) — 알림용

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
    public ?string $car_cost = null;       // 차값 (가져온 통화 그대로 — costCurrency)
    public string $costCurrency = 'KRW';   // 차값 통화 (listing.expected_price_currency, 엔카=KRW)
    public ?string $discount_rate = null;  // 할인율 (%)
    public ?int $shipping_usd = null;      // 배송금액 (USD 고정 택1)

    /** 입력값 기준 차량금액(KRW) 미리보기 = 차값(통화 KRW환산) − (×할인율%) + 매도비. */
    public function carPricePreview(): ?int
    {
        $krw = \App\Support\Money::toKrw($this->car_cost, $this->costCurrency, $this->usdRate(), $this->eurRate());
        if ($krw === null) {
            return null;
        }
        $discount = (int) round($krw * ((float) $this->discount_rate / 100));

        return $krw - $discount + (int) config('board.sales_fee');
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

        return $listings->groupBy(fn ($l) => $l->region ?: __('inspection.region_unset'));
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
        ], attributes: ['assignRegion' => __('inspection.attr_region'), 'assignUserId' => __('inspection.attr_assignee')]);

        $count = InspectionAssignment::where('date', $this->assignDate)->where('region', $this->assignRegion)->count();
        if ($count >= InspectionAssignment::MAX_PER_REGION) {
            $this->addError('assignUserId', __('inspection.max_per_region_error', ['max' => InspectionAssignment::MAX_PER_REGION]));

            return;
        }

        $u = User::find($this->assignUserId);
        if (! $u || $u->role !== 'inspection') {
            $this->addError('assignUserId', __('inspection.only_inspection_assignable'));

            return;
        }

        InspectionAssignment::firstOrCreate([
            'date' => $this->assignDate,
            'region' => $this->assignRegion,
            'user_id' => $this->assignUserId,
        ]);
        $this->assignUserId = null;
        unset($this->assignmentsByRegion, $this->regionGroups, $this->assignmentSummary);
        session()->flash('ok', __('inspection.assigned_ok'));
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
        $this->costCurrency = $l->expected_price_currency ?: 'KRW';
        $this->discount_rate = $l->discount_rate !== null ? (string) $l->discount_rate : null;
        $this->shipping_usd = $l->shipping_usd;
        // 이전에 확정한 판매통화가 있으면 그대로 보여줌(없으면 KRW). 처음 정한 통화가 이어짐.
        $this->displayCurrency = $l->offer_currency ?: 'KRW';
        $this->photos = [];
        // 스테이징 선택 초기화 (전달은 draft 에서만 / 회신은 바이어 회신 화면)
        $this->sendSelected = false;
        $this->forceManualSend = false;
        $this->sendConflictWith = null;
        $this->resetErrorBag();
    }

    public function closeDrawer(): void
    {
        $this->reset(['editingId', 'final_price', 'inspection_memo', 'buyer_name', 'photos',
            'region', 'inspection_note', 'car_cost', 'discount_rate', 'shipping_usd',
            'sendSelected', 'forceManualSend', 'sendConflictWith']);
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
        $computed = $l->totalKrw($this->usdRate(), $this->eurRate());
        if ($computed !== null) {
            $l->final_price = $computed;
        } elseif ($this->final_price !== null && $this->final_price !== '') {
            $l->final_price = (int) $this->final_price;
        }
        // 판매 통화 확정 — 현지확인에서 고른 표시통화를 굳힘(바이어 견적·연동B 판매가 통화).
        $l->offer_currency = $this->displayCurrency;
        $l->offer_rate = match ($this->displayCurrency) {
            'USD' => $this->usdRate(),
            'EUR' => $this->eurRate(),
            default => 1,
        };
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
            if (\App\Support\UploadGuard::isExecutable($file->getClientOriginalName())) {
                continue;   // 실행파일 차단(검차는 사진/영상만)
            }
            $path = $file->store($prefix, $disk);
            $l->photos()->create([
                's3_path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'sort' => $start + $i + 1,
                'kind' => \App\Models\InspectionPhoto::KIND_INSPECTION,
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

    /**
     * 통합 저장 — 입력(금액·메모·사진) + 스테이징한 상태변경(전달/회신)을 한 번에 커밋.
     * 드로어의 전달/회신 버튼은 선택(색강조)만 하고 실제 반영은 여기서. (수동씬 = 선택 후 저장)
     */
    public function save(): void
    {
        $l = PurchaseListing::findOrFail($this->editingId);
        $sending = $l->status === 'draft' && $this->sendSelected;

        $rules = $this->pricingRules();
        if ($sending) {
            $rules['buyer_name'] = 'required|string|max:100';
        }
        $this->validate($rules, attributes: ['buyer_name' => __('inspection.attr_buyer_name')]);

        // 전달하려면 최종금액(공식 차값 또는 수동 final_price) 중 하나는 있어야 함.
        if ($sending && $this->carPricePreview() === null && ($this->final_price === null || $this->final_price === '')) {
            $this->addError('car_cost', __('inspection.need_amount_to_forward'));

            return;
        }

        $l->inspection_memo = $this->inspection_memo ?: null;
        if ($this->buyer_name !== '') {
            $l->buyer_name = $this->buyer_name;
        }
        $this->applyInspectionFields($l);

        $msg = __('inspection.saved_ok');
        if ($sending) {
            // 회신 채널 결정: 컨택트 미연결이면 자동 불가→수동. 강제수동(=수동 전환 선택) 시 수동.
            $channel = 'auto';
            if (empty($l->respond_contact_id) || $this->forceManualSend) {
                $channel = 'manual';
            } else {
                // (가) 가드: 같은 바이어(컨택트)에 이미 '자동' 회신대기 차가 있으면 전달 보류 + 선택지 알림.
                // (자동은 한 바이어당 1대 직렬화 — respond.io 폴링이 '어느 차'인지 명확하도록)
                $conflict = PurchaseListing::withoutGlobalScope(SalesmanScope::class)
                    ->where('respond_contact_id', $l->respond_contact_id)
                    ->where('status', 'awaiting_buyer')
                    ->where('verdict_channel', 'auto')
                    ->where('id', '!=', $l->id)
                    ->first();
                if ($conflict) {
                    // 입력값(금액·메모)은 저장하되 '전달'만 보류 → 사용자가 대기/수동 선택
                    $l->save();
                    $this->persistPhotos($l);
                    $this->sendConflictWith = $conflict->vehicle_number;

                    return;
                }
            }
            $l->status = 'awaiting_buyer';
            $l->buyer_verdict = 'pending';
            $l->verdict_channel = $channel;
            $msg = $channel === 'manual'
                ? __('inspection.forwarded_manual')
                : __('inspection.forwarded_auto');
        }

        $l->save();
        $this->persistPhotos($l);

        // outbound — 전달 시 바이어에게 최종금액(USD)+공개사진 자동 전송 (Job 가드: 컨택트/설정)
        if ($sending) {
            \App\Jobs\SendOfferToBuyer::dispatch($l->id)->afterCommit();
        }

        session()->flash('ok', $msg);
        $this->closeDrawer();
    }

    /** 사진별 "바이어 공개" 토글 (§28 외관만 전송 — 담당자가 선별). */
    public function toggleShare(int $photoId): void
    {
        $p = \App\Models\InspectionPhoto::where('purchase_listing_id', $this->editingId)->findOrFail($photoId);
        $p->share_to_buyer = ! $p->share_to_buyer;
        $p->save();
        unset($this->editing);
    }

    /** (가) 선택지 ① 수동으로 전환해 전달 — 자동 1대 제한을 우회, 이 차는 수동 트랙으로. */
    public function sendAsManual(): void
    {
        $this->forceManualSend = true;
        $this->sendSelected = true;
        $this->sendConflictWith = null;
        $this->save();
    }

    /** (가) 선택지 ② 앞 차 처리 후 진행 — 지금은 전달 안 함(금액은 저장됨). */
    public function cancelSend(): void
    {
        $this->sendSelected = false;
        $this->sendConflictWith = null;
        session()->flash('ok', __('inspection.forward_held'));
        $this->closeDrawer();
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
}; ?>

<div class="p-3 md:p-6">
    <div class="mb-4">
        <h1 class="text-xl font-bold text-gray-800">{{ __('inspection.title') }}</h1>
        <p class="mt-0.5 text-xs text-gray-500">📍 {{ __('inspection.subtitle_flow', ['mode' => $this->canAssign() ? __('inspection.subtitle_manage') : __('inspection.subtitle_mine')]) }}</p>
    </div>

    @if (session('ok'))
        <div class="card-sm mb-3 border-green-200 bg-green-50 text-[13px] text-green-700">✓ {{ session('ok') }}</div>
    @endif

    {{-- ─────────── 지역 배정 패널 (관리/super) ─────────── --}}
    @if ($this->canAssign())
        <div class="card mb-3" style="background:#f8f9fb">
            <div class="mb-2 flex items-center justify-between">
                <h2 class="font-bold text-gray-800">📋 {{ __('inspection.assign_panel_title') }} <span class="text-xs font-normal text-gray-400">({{ $assignDate }})</span></h2>
                <span class="text-xs text-gray-400">{{ __('inspection.max_per_region', ['max' => \App\Models\InspectionAssignment::MAX_PER_REGION]) }}</span>
            </div>
            <div class="flex flex-wrap items-end gap-2">
                <div class="min-w-[160px] flex-1">
                    <label class="label-base">{{ __('inspection.region') }}</label>
                    <select class="input-base" wire:model="assignRegion">
                        <option value="">{{ __('inspection.region_select') }}</option>
                        @foreach ($this->pendingRegions as $r)<option value="{{ $r }}">{{ $r }}</option>@endforeach
                    </select>
                </div>
                <div class="min-w-[140px] flex-1">
                    <label class="label-base">{{ __('inspection.assignee_inspection') }}</label>
                    <select class="input-base" wire:model="assignUserId">
                        <option value="">{{ __('inspection.assignee_select') }}</option>
                        @foreach ($this->inspectors as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach
                    </select>
                </div>
                <button class="btn-primary btn-sm" wire:click="assign">{{ __('inspection.assign_button') }}</button>
            </div>
            @error('assignRegion') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            @error('assignUserId') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            @if ($this->pendingRegions->isEmpty())
                <p class="mt-2 text-xs text-gray-400">{!! __('inspection.assign_hint', ['region' => '<b>'.__('inspection.assign_hint_region_word').'</b>']) !!}</p>
            @endif

            {{-- 배정 현황 요약 (정렬 가능) --}}
            @if ($this->assignmentSummary->isNotEmpty())
                @php $arrow = fn ($c) => $sortBy === $c ? ($sortDir === 'asc' ? ' ▲' : ' ▼') : ''; @endphp
                <div class="mt-3 overflow-x-auto">
                    <table class="tbl text-[13px]">
                        <thead>
                            <tr>
                                <th class="cursor-pointer select-none" wire:click="sortByCol('region')">{{ __('inspection.col_region') }}{{ $arrow('region') }}</th>
                                <th class="cursor-pointer select-none" wire:click="sortByCol('people')">{{ __('inspection.col_people') }}{{ $arrow('people') }}</th>
                                <th class="cursor-pointer select-none" wire:click="sortByCol('cars')">{{ __('inspection.col_cars') }}{{ $arrow('cars') }}</th>
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
                                            <span class="text-xs text-amber-600">{{ __('inspection.unassigned') }}</span>
                                        @endforelse
                                    </td>
                                    <td class="{{ $row['cars'] ? 'font-semibold text-gray-700' : 'text-gray-300' }}">{{ __('inspection.cars_count', ['count' => $row['cars']]) }}</td>
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
                    <span class="pill-count">{{ __('inspection.items_count', ['count' => $items->count()]) }}</span>
                </button>
                <span class="ml-1 flex flex-wrap items-center gap-1">
                    @forelse ($assigned as $a)
                        <span class="badge badge-blue inline-flex items-center gap-1">
                            🧑‍🔧 {{ $a->user->name }}
                            @if ($this->canAssign())<button wire:click="unassign({{ $a->id }})" class="text-blue-400 hover:text-blue-700">✕</button>@endif
                        </span>
                    @empty
                        <span class="text-xs text-gray-400">{{ $this->canAssign() ? __('inspection.no_assignment_label') : '' }}</span>
                    @endforelse
                </span>
            </div>
            <div class="mt-2 flex flex-col gap-2" x-show="open" x-cloak>
                @foreach ($items as $l)
                    <div class="flex cursor-pointer items-center gap-3 rounded-lg border border-gray-200 px-3 py-2.5 hover:bg-gray-50" wire:click="openDrawer({{ $l->id }})">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                <span class="font-semibold text-gray-800">{{ $l->vehicle_number }}</span>
                                <span class="badge {{ $l->isAuction() ? 'badge-auction' : 'badge-encar' }}">{{ $l->isAuction() ? __('domain.source.auction') : __('domain.source.encar') }}</span>
                                <span class="text-xs text-gray-400">{{ $l->creator->name }}</span>
                            </div>
                            <div class="mt-0.5 text-xs text-gray-500">
                                {{ $l->final_price ? __('inspection.final_amount_prefix', ['amount' => number_format($l->final_price)]) : __('inspection.amount_undecided') }}
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
        <div class="card text-center text-gray-400">{{ $this->canAssign() ? __('inspection.empty_for_manager') : __('inspection.empty_for_inspector') }}</div>
    @endforelse

    {{-- ─────────── 드로어 ─────────── --}}
    @if ($this->editing)
        @php $e = $this->editing; @endphp
        <div class="fixed inset-0 z-40 bg-black/40" wire:click="closeDrawer"></div>
        <div class="fixed inset-y-0 right-0 z-50 w-full overflow-y-auto bg-white shadow-xl sm:w-[460px]">
            <div class="flex items-center justify-between border-b border-gray-200 px-5 py-4">
                <h3 class="font-bold text-gray-800">{{ $e->vehicle_number }} · {{ __('inspection.drawer_title') }} <span class="text-xs text-gray-400">({{ $e->isAuction() ? __('domain.source.auction') : __('domain.source.encar') }})</span></h3>
                <button class="text-gray-400 hover:text-gray-600" wire:click="closeDrawer">✕</button>
            </div>

            <div class="px-5 py-4">
                <p class="text-xs text-gray-500">{{ __('inspection.expected_price_line', ['price' => $e->expected_price ? number_format($e->expected_price).__('common.won_currency') : '—']) }}</p>

                {{-- 사진/영상 --}}
                <div class="section-title-sm">{{ __('inspection.photos_section') }}</div>
                <label class="flex cursor-pointer items-center justify-center rounded-lg border-2 border-dashed border-gray-300 py-5 text-sm text-gray-500 hover:border-[var(--color-primary)]">
                    📷 {{ __('inspection.photo_upload_label') }}
                    <input type="file" accept="image/*,video/*" capture="environment" multiple wire:model="photos" class="hidden">
                </label>
                <div wire:loading wire:target="photos" class="mt-1 text-xs text-gray-400">{{ __('inspection.uploading') }}</div>
                @error('photos.*') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                @if (count($photos))
                    <p class="mt-2 text-xs text-gray-500">{{ __('inspection.new_files_count', ['count' => count($photos)]) }}</p>
                @endif

                @if ($e->photos->count())
                    <div class="mt-2 grid grid-cols-4 gap-2">
                        @foreach ($e->photos as $p)
                            <div class="relative overflow-hidden rounded-md">
                                @if ($p->isVideo())
                                    <video src="{{ $this->photoUrl($p->s3_path) }}" class="aspect-square w-full object-cover" controls preload="metadata"></video>
                                @else
                                    <img src="{{ $this->photoUrl($p->s3_path) }}" class="aspect-square w-full object-cover" alt="">
                                @endif
                                <button type="button" wire:click="toggleShare({{ $p->id }})"
                                    class="absolute inset-x-0 bottom-0 py-0.5 text-[10px] font-semibold {{ $p->share_to_buyer ? 'bg-green-600 text-white' : 'bg-black/55 text-white' }}">
                                    {{ $p->share_to_buyer ? __('inspection.share_to_buyer_on') : __('inspection.share_to_buyer') }}
                                </button>
                            </div>
                        @endforeach
                    </div>
                    <p class="mt-1 text-[11px] text-gray-400">💡 {!! __('inspection.photo_share_hint', ['exterior' => '<b>'.__('inspection.photo_share_hint_exterior').'</b>']) !!}</p>
                @endif

                {{-- 검사지역 --}}
                <div class="section-title-sm">{{ __('inspection.inspection_region_section') }}</div>
                <input class="input-base" wire:model="region" list="regionList" placeholder="{{ __('inspection.region_placeholder') }}">
                <datalist id="regionList">
                    @foreach (config('board.regions') as $r)<option value="{{ $r }}">@endforeach
                </datalist>
                @error('region') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                {{-- 메모 --}}
                <div class="section-title-sm">{{ __('inspection.memo_section') }}</div>
                <input class="input-base" wire:model="inspection_memo" placeholder="{{ __('inspection.memo_placeholder') }}">

                {{-- 추가검사사항 (listings 표에 표시) --}}
                <div class="section-title-sm">{{ __('inspection.note_section') }}</div>
                <input class="input-base" wire:model="inspection_note" placeholder="{{ __('inspection.note_placeholder') }}">
                @error('inspection_note') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                {{-- 금액 산정 (§6) --}}
                <div class="section-title-sm flex items-center justify-between">
                    <span>{{ __('inspection.pricing_section') }}</span>
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
                        <label class="label-base">{{ __('inspection.car_cost_label', ['symbol' => \App\Support\Money::SYMBOLS[$costCurrency] ?? '원']) }}</label>
                        <input class="input-base" wire:model.live.debounce.400ms="car_cost" inputmode="numeric" placeholder="{{ __('inspection.car_cost_placeholder') }}">
                        @error('car_cost') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label-base">{{ __('inspection.discount_rate_label') }}</label>
                        <input class="input-base" wire:model.live.debounce.400ms="discount_rate" inputmode="decimal" placeholder="0">
                        @error('discount_rate') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="mt-2 flex items-center justify-between text-xs text-gray-500">
                    <span>{{ __('inspection.sales_fee_label') }}</span>
                    <span class="font-semibold text-gray-700">{{ number_format((int) config('board.sales_fee')) }}{{ __('common.won_currency') }}</span>
                </div>
                <div class="mt-1 flex items-center justify-between rounded-md bg-gray-50 px-3 py-2 text-sm">
                    <span class="text-gray-600">{{ __('inspection.car_price_label') }}</span>
                    <span class="font-bold text-gray-800">{{ $this->fmt($carPrice) }}</span>
                </div>

                <label class="label-base mt-3">{{ __('inspection.shipping_label') }}</label>
                <div class="inline-flex overflow-hidden rounded-md border border-gray-300">
                    @foreach (config('board.shipping_options') as $opt)
                        <button type="button" wire:click="$set('shipping_usd', {{ $opt }})"
                            class="px-3 py-1.5 text-[13px] font-semibold {{ (int) $shipping_usd === $opt ? 'bg-[var(--color-primary)] text-white' : 'bg-white text-gray-600' }}">${{ number_format($opt) }}</button>
                    @endforeach
                </div>
                @error('shipping_usd') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                @if ($shipKrw !== null)<div class="mt-1 text-right text-xs text-gray-500">{{ __('inspection.shipping_line', ['amount' => $this->fmt($shipKrw)]) }}</div>@endif

                <div class="mt-2 flex items-center justify-between rounded-md border border-[var(--color-primary)] bg-[#f5f8ff] px-3 py-2.5">
                    <span class="text-sm font-semibold text-gray-700">{{ __('inspection.total_label') }}</span>
                    <span class="text-base font-bold text-[var(--color-primary-text)]">{{ $this->fmt($total) }}</span>
                </div>
                <p class="mt-1 text-[11px] text-gray-400">{{ __('inspection.shipping_rate_note', ['usd' => number_format((int) $shipping_usd), 'rate' => number_format($rate)]) }}</p>

                {{-- 바이어 전달 --}}
                <div class="section-title-sm">{{ __('inspection.forward_section') }}</div>
                <input class="input-base" wire:model="buyer_name" placeholder="{{ __('inspection.buyer_name_placeholder') }}">
                @error('buyer_name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                {{-- 전달 (draft 단계) — 클릭=선택만, 저장 눌러야 전달 --}}
                @if ($e->status === 'draft')
                    <button type="button" wire:click="$toggle('sendSelected')" class="btn-outline btn-sm mt-2 w-full justify-center"
                            @style(['background-color:var(--color-primary);border-color:var(--color-primary);color:#fff;font-weight:700' => $sendSelected])>
                        📤 {{ __('inspection.forward_button') }} {{ $sendSelected ? __('inspection.forward_button_selected') : '' }}
                    </button>
                    <p class="mt-1 text-xs text-gray-400">{!! __('inspection.forward_hint', ['save' => '<b>'.__('inspection.forward_hint_save').'</b>', 'verdicts' => '<b>'.__('inspection.forward_hint_verdicts').'</b>']) !!}</p>

                    {{-- (가) 가드: 같은 바이어 자동 회신대기 1대 초과 시 선택지 --}}
                    @if ($sendConflictWith)
                        <div class="card-sm mt-2 border-amber-300 bg-amber-50 text-amber-800">
                            <p class="text-[13px] font-semibold">⚠️ {!! __('inspection.conflict_title', ['vehicle' => '<b>'.__('inspection.conflict_auto_word', ['vehicle' => e($sendConflictWith)]).'</b>']) !!}</p>
                            <p class="mt-0.5 text-xs">{{ __('inspection.conflict_desc') }}</p>
                            <div class="mt-2 flex flex-col gap-2 sm:flex-row">
                                <button type="button" class="btn-outline btn-sm flex-1 justify-center" wire:click="cancelSend">{{ __('inspection.conflict_wait') }}</button>
                                <button type="button" class="btn-primary btn-sm flex-1 justify-center" wire:click="sendAsManual">{{ __('inspection.conflict_manual') }}</button>
                            </div>
                            <p class="mt-1 text-[11px] text-amber-600">{{ __('inspection.conflict_manual_note') }}</p>
                        </div>
                    @endif
                @elseif ($e->status === 'awaiting_buyer')
                    <p class="mt-2 rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-700">⏳ {!! __('inspection.already_forwarded', ['verdicts' => '<b>'.__('inspection.already_forwarded_verdicts').'</b>']) !!}</p>
                @endif

                <div class="mt-5 flex gap-2">
                    <button class="btn-primary flex-1 justify-center" wire:click="save">{{ __('common.save') }}</button>
                    <button class="btn-ghost" wire:click="closeDrawer">{{ __('common.cancel') }}</button>
                </div>
            </div>
        </div>
    @endif
</div>

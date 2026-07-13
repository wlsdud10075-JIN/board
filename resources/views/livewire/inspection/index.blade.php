<?php

use App\Models\InspectionAssignment;
use App\Models\PurchaseListing;
use App\Models\User;
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
    public string $inspection_memo = '';
    public string $buyer_name = '';
    public array $photos = [];

    // 드로어 내 "선택 → 저장" 스테이징 (클릭=선택만, 저장 눌러야 커밋).
    // 회신(수락/거절)은 "바이어 회신" 화면으로 일원화 → 현지확인은 전달까지만.
    public bool $sendSelected = false;        // draft: "검차완료" 선택 → inspected(전달대기)

    // ── 지역 배정 (§6c) ──
    public string $assignDate = '';
    public string $assignRegion = '';
    public ?int $assignUserId = null;

    // ── 검사지역 + 추가검사사항 (금액·통화는 견적·전달 단계로 이동, 2026-07-06) ──
    public string $region = '';
    public string $inspection_note = '';

    public function mount(): void
    {
        $this->assignDate = now()->toDateString();
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
            ->whereIn('status', ['draft', 'inspected', 'awaiting_buyer', 'accepted'])
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

    /**
     * 수동 발송 — 관리가 배정 후 그 날짜(assignDate) 기준 지역 검차 안내 알림톡을 지금 발송.
     * 대상 = 미통보 draft 차량(스케줄과 동일 dedup). 알림톡 off/미승인이면 skipped(no-op).
     */
    public function sendRegionInspectionAlimtalk(): void
    {
        abort_unless($this->canAssign(), 403);
        $r = app(\App\Services\RegionInspectionNotifier::class)->run($this->assignDate);
        unset($this->regionGroups);
        session()->flash('ok', __('inspection.alimtalk_sent', ['sent' => $r['sent'], 'regions' => $r['regions'], 'skipped' => $r['skipped']]));
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
        $this->inspection_memo = $l->inspection_memo ?? '';
        $this->buyer_name = $l->buyer_name ?? '';
        $this->region = $l->region ?? '';
        $this->inspection_note = $l->inspection_note ?? '';
        $this->photos = [];
        // 검차완료 선택 초기화 (검차완료는 draft 에서만; 전달/회신은 영업 화면)
        $this->sendSelected = false;
        $this->resetErrorBag();
    }

    public function closeDrawer(): void
    {
        $this->reset(['editingId', 'inspection_memo', 'buyer_name', 'photos',
            'region', 'inspection_note', 'sendSelected']);
        unset($this->editing, $this->regionGroups);
    }

    /** 입력된 검사지역·추가검사사항을 모델에 반영. 금액·통화는 견적·전달(forwarding) 단계 소관. */
    private function applyInspectionFields(PurchaseListing $l): void
    {
        $l->region = $this->region ?: null;
        $l->inspection_note = $this->inspection_note ?: null;
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
                'share_to_buyer' => true,   // 기본 공유(opt-out) — 민감한 사진은 인스펙터가 토글로 해제(§28). 2026-06-29 Jin.
            ]);
        }
        $this->photos = [];
    }

    private function pricingRules(): array
    {
        return [
            'region' => 'nullable|string|max:60',
            'inspection_note' => 'nullable|string|max:255',
        ];
    }

    /**
     * 통합 저장 — 입력(금액·메모·사진) + 스테이징한 상태변경(전달/회신)을 한 번에 커밋.
     * 드로어의 전달/회신 버튼은 선택(색강조)만 하고 실제 반영은 여기서. (수동씬 = 선택 후 저장)
     */
    public function save(): void
    {
        $l = PurchaseListing::findOrFail($this->editingId);
        // 검차완료 = draft 차에 사진/금액 입력 마치고 "검차완료" 선택 → inspected(전달대기).
        // 바이어 전달(awaiting_buyer)은 영업이 /forwarding 에서 사진 확인 후 누른다.
        $completing = $l->status === 'draft' && $this->sendSelected;

        $this->validate($this->pricingRules());

        $l->inspection_memo = $this->inspection_memo ?: null;
        if ($this->buyer_name !== '') {
            $l->buyer_name = $this->buyer_name;
        }
        $this->applyInspectionFields($l);

        $msg = __('inspection.saved_ok');
        if ($completing) {
            $l->status = 'inspected';   // draft→inspected (전이 가드)
            $msg = __('inspection.inspected_ok');
        }

        $l->save();
        $this->persistPhotos($l);

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

    /** 잘못 올린 검차사진 삭제 — 편집 중인 차의 사진만(파일+행). */
    public function deletePhoto(int $photoId): void
    {
        $p = \App\Models\InspectionPhoto::where('purchase_listing_id', $this->editingId)->findOrFail($photoId);
        Storage::disk(config('board.photo_disk'))->delete($p->s3_path);
        $p->delete();
        unset($this->editing);
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
            <div class="mb-2 flex items-center justify-between gap-2">
                <h2 class="font-bold text-gray-800">📋 {{ __('inspection.assign_panel_title') }} <span class="text-xs font-normal text-gray-400">({{ $assignDate }})</span></h2>
                <div class="flex items-center gap-2">
                    <span class="hidden text-xs text-gray-400 sm:inline">{{ __('inspection.max_per_region', ['max' => \App\Models\InspectionAssignment::MAX_PER_REGION]) }}</span>
                    <button class="btn-ghost btn-sm shrink-0" wire:click="sendRegionInspectionAlimtalk"
                        wire:confirm="{{ __('inspection.alimtalk_confirm', ['date' => $assignDate]) }}">📨 {{ __('inspection.alimtalk_send_btn') }}</button>
                </div>
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
                <div x-data="{
                        uploading: false, progress: 0, startedAt: 0, etaText: '', wakeLock: null,
                        start() { this.uploading = true; this.progress = 0; this.startedAt = Date.now(); this.etaText = ''; this.lockScreen(); },
                        prog(p) { this.progress = p; const el = (Date.now() - this.startedAt) / 1000; if (p > 0 && p < 100 && el > 0.5) { this.etaText = Math.ceil(el * (100 - p) / p) + '{{ __('inspection.upload_sec_left') }}'; } },
                        finish() { this.progress = 100; this.etaText = ''; this.unlockScreen(); setTimeout(() => { this.uploading = false; }, 500); },
                        fail() { this.uploading = false; this.etaText = ''; this.unlockScreen(); },
                        /* 업로드 동안만 화면 켜둠(자동 절전 방지). 지원 안 하면 조용히 무시(iOS<16.4 등). 화면 끄거나 앱 전환 시 OS가 자동 해제 → visibility 복귀 때 재획득. */
                        async lockScreen() {
                            try { if ('wakeLock' in navigator) { this.wakeLock = await navigator.wakeLock.request('screen'); } } catch (e) {}
                            if (!this._visHandler) { this._visHandler = () => { if (document.visibilityState === 'visible' && this.uploading && !this.wakeLock) this.lockScreen(); }; document.addEventListener('visibilitychange', this._visHandler); }
                        },
                        async unlockScreen() {
                            if (this._visHandler) { document.removeEventListener('visibilitychange', this._visHandler); this._visHandler = null; }
                            if (this.wakeLock) { try { await this.wakeLock.release(); } catch (e) {} this.wakeLock = null; }
                        }
                     }"
                     x-on:livewire-upload-start.window="start()"
                     x-on:livewire-upload-progress.window="prog($event.detail.progress)"
                     x-on:livewire-upload-finish.window="finish()"
                     x-on:livewire-upload-error.window="fail()">
                    <label class="flex cursor-pointer items-center justify-center rounded-lg border-2 border-dashed border-gray-300 py-5 text-sm text-gray-500 hover:border-[var(--color-primary)]">
                        📷 {{ __('inspection.photo_upload_label') }}
                        <input type="file" accept="image/*,video/*" capture="environment" multiple wire:model="photos" class="hidden">
                    </label>
                    {{-- 업로드 진행률 게이지 (Livewire 업로드 이벤트 → %·대략 남은 초). 영상 등 큰 파일 LTE 업로드 체감용. --}}
                    <div x-show="uploading" x-cloak class="mt-2">
                        <div class="h-2 w-full overflow-hidden rounded-full bg-gray-200">
                            <div class="h-full rounded-full bg-[var(--color-primary)] transition-all duration-150" :style="`width:${progress}%`"></div>
                        </div>
                        <div class="mt-1 flex justify-between text-[11px] text-gray-500">
                            <span x-text="progress + '%'"></span>
                            <span x-text="etaText"></span>
                        </div>
                    </div>
                    <div wire:loading wire:target="photos" class="mt-1 text-xs text-gray-400">{{ __('inspection.uploading') }}</div>
                    @error('photos.*') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                @if (count($photos))
                    <p class="mt-2 text-xs text-gray-500">{{ __('inspection.new_files_count', ['count' => count($photos)]) }}</p>
                @endif

                @if ($e->photos->count())
                    <div class="mt-2 grid grid-cols-4 gap-2">
                        @foreach ($e->photos as $p)
                            @php $u = $this->photoUrl($p->s3_path); @endphp
                            <div class="relative overflow-hidden rounded-md" wire:key="insp-photo-{{ $p->id }}">
                                @if ($p->isVideo())
                                    <video src="{{ $u }}" class="aspect-square w-full object-cover" controls preload="metadata"></video>
                                @else
                                    <img src="{{ $u }}" @click="$dispatch('open-lightbox', { src: '{{ $u }}' })" class="aspect-square w-full cursor-zoom-in object-cover" alt="">
                                @endif
                                <button type="button" wire:click="deletePhoto({{ $p->id }})" wire:confirm="{{ __('inspection.photo_delete_confirm') }}"
                                    class="absolute right-0.5 top-0.5 rounded bg-black/55 px-1 text-[10px] font-semibold text-white hover:bg-red-600">✕</button>
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

                {{-- 검차완료 --}}
                <div class="section-title-sm">{{ __('inspection.complete_section') }}</div>

                {{-- 검차완료 (draft 단계) — 클릭=선택만, 저장 눌러야 inspected 전환. 바이어 전달은 영업이. --}}
                @if ($e->status === 'draft')
                    <button type="button" wire:click="$toggle('sendSelected')" class="btn-outline btn-sm mt-2 w-full justify-center"
                            @style(['background-color:var(--color-primary);border-color:var(--color-primary);color:#fff;font-weight:700' => $sendSelected])>
                        ✅ {{ __('inspection.complete_button') }} {{ $sendSelected ? __('inspection.complete_button_selected') : '' }}
                    </button>
                    <p class="mt-1 text-xs text-gray-400">{{ __('inspection.complete_hint') }}</p>
                @elseif ($e->status === 'inspected')
                    <p class="mt-2 rounded-md bg-teal-50 px-3 py-2 text-xs text-teal-700">✅ {{ __('inspection.already_inspected') }}</p>
                @else
                    <p class="mt-2 rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-700">⏳ {{ __('inspection.already_forwarded_simple') }}</p>
                @endif

                <div class="mt-5 flex gap-2">
                    <button class="btn-primary flex-1 justify-center" wire:click="save">{{ __('common.save') }}</button>
                    <button class="btn-ghost" wire:click="closeDrawer">{{ __('common.cancel') }}</button>
                </div>
            </div>
        </div>
    @endif
</div>

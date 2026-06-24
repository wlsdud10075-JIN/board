<?php

use App\Models\PurchaseListing;
use App\Services\OfferForwardService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * 전달 대기 — 검차완료(inspected) 차를 영업이 사진 확인 후 바이어에게 전달(→ awaiting_buyer).
 * 검차/영업 분리: 검차는 검차완료까지, 전달은 딜 주인(영업)이 사진 보고 누른다.
 * SalesmanScope: 영업은 본인 글만(크로스영업 노출 없음 = IDOR 차단).
 */
new #[Layout('components.layouts.app')] class extends Component {
    public ?int $detailId = null;

    public string $buyer_name = '';

    public bool $forceManual = false;

    public ?string $conflictVehicle = null;

    /** 검차완료(전달 대기) 차 — 영업 본인 것만(SalesmanScope 전역). */
    #[Computed]
    public function items()
    {
        return PurchaseListing::with('creator')
            ->where('status', 'inspected')
            ->orderBy('updated_at')
            ->get();
    }

    /** 드로어 대상 (검차 사진 포함). status=inspected 한정 + SalesmanScope → IDOR 차단. */
    #[Computed]
    public function detail(): ?PurchaseListing
    {
        return $this->detailId
            ? PurchaseListing::with(['creator', 'photos'])->where('status', 'inspected')->find($this->detailId)
            : null;
    }

    public function openDetail(int $id): void
    {
        $l = PurchaseListing::where('status', 'inspected')->findOrFail($id);
        $this->detailId = $l->id;
        $this->buyer_name = $l->buyer_name ?? '';
        $this->forceManual = false;
        $this->conflictVehicle = null;
        $this->resetErrorBag();
    }

    public function closeDetail(): void
    {
        $this->reset(['detailId', 'buyer_name', 'forceManual', 'conflictVehicle']);
        unset($this->detail);
    }

    public function photoUrl(string $path): string
    {
        $disk = config('board.photo_disk');
        if ($disk !== 's3') {
            return Storage::disk($disk)->url($path);
        }

        return Cache::remember(
            "photo_url:{$path}",
            now()->addMinutes(20),
            fn () => Storage::disk('s3')->temporaryUrl($path, now()->addMinutes(30)),
        );
    }

    /** 바이어 전달 → awaiting_buyer. buyer_name 필수(누구에게). 충돌 시 보류 + 수동 옵션. */
    public function forward(): void
    {
        $this->validate(['buyer_name' => 'required|string|max:100'], attributes: ['buyer_name' => __('forwarding.attr_buyer_name')]);

        // findOrFail 이 SalesmanScope 안 → 본인 글만(IDOR)
        $l = PurchaseListing::where('status', 'inspected')->findOrFail($this->detailId);
        if ($l->buyer_name !== $this->buyer_name) {
            $l->buyer_name = $this->buyer_name;
            $l->save();
        }

        $r = app(OfferForwardService::class)->forward($l->id, $this->forceManual);

        if ($r['status'] === 'conflict') {
            $this->conflictVehicle = $r['conflict_vehicle'];

            return;
        }

        $this->reset(['detailId', 'buyer_name', 'forceManual', 'conflictVehicle']);
        unset($this->items, $this->detail);
        session()->flash('ok', __('forwarding.flash_forwarded', ['vehicle' => $l->vehicle_number]));
    }

    /** 충돌 시: 이 차를 수동 채널로 전달(자동 1대 제한 우회). */
    public function forwardManual(): void
    {
        $this->forceManual = true;
        $this->forward();
    }
}; ?>

<div class="p-3 md:p-6">
    <div class="mb-4">
        <h1 class="text-xl font-bold text-gray-800">{{ __('forwarding.title') }}</h1>
        <p class="mt-0.5 text-xs text-gray-500">{{ __('forwarding.subtitle') }}</p>
    </div>

    @if (session('ok'))
        <div class="card-sm mb-3 border-green-200 bg-green-50 text-[13px] text-green-700">✓ {{ session('ok') }}</div>
    @endif

    <div class="card">
        <div class="mb-3 flex items-center gap-2">
            <h2 class="font-bold text-gray-800">{{ __('forwarding.panel_title') }}</h2>
            <span class="pill-count">{{ __('forwarding.count', ['count' => $this->items->count()]) }}</span>
        </div>

        {{-- 데스크톱: 표 --}}
        <div class="hidden overflow-x-auto sm:block">
            <table class="tbl">
                <thead>
                    <tr><th>{{ __('forwarding.th_vehicle') }}</th><th>{{ __('forwarding.th_origin') }}</th><th>{{ __('forwarding.th_final_price') }}</th><th>{{ __('forwarding.th_inspection_note') }}</th><th style="text-align:right">{{ __('common.detail') }}</th></tr>
                </thead>
                <tbody>
                    @forelse ($this->items as $l)
                        <tr class="cursor-pointer hover:bg-gray-50" wire:click="openDetail({{ $l->id }})">
                            <td class="whitespace-nowrap align-middle">
                                <div class="font-semibold text-gray-800">{{ $l->vehicle_number }}</div>
                                <div class="text-xs text-gray-400">{{ $l->owner_name ?: '—' }}</div>
                            </td>
                            <td class="align-middle"><span class="badge {{ $l->originBadge() }}">{{ $l->originLabel() }}</span></td>
                            <td class="align-middle font-semibold {{ $l->final_price ? 'text-[var(--color-primary-text)]' : 'text-gray-400' }}">{{ $l->final_price ? number_format($l->final_price).__('common.won_currency') : '—' }}</td>
                            <td class="max-w-[200px] truncate align-middle text-xs text-gray-500" title="{{ $l->inspection_note }}">{{ $l->inspection_note ?: '—' }}</td>
                            <td class="whitespace-nowrap align-middle text-right font-medium text-[var(--color-primary-text)]">{{ __('common.detail') }} ›</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-8 text-center text-gray-400">{{ __('forwarding.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- 모바일: 카드 --}}
        <div class="space-y-2 sm:hidden">
            @forelse ($this->items as $l)
                <div class="card-tight cursor-pointer" wire:click="openDetail({{ $l->id }})">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <div class="font-semibold text-gray-800">{{ $l->vehicle_number }}</div>
                            <div class="text-xs text-gray-400">{{ $l->owner_name ?: '—' }}</div>
                        </div>
                        <div class="shrink-0 text-right">
                            <span class="badge {{ $l->originBadge() }}">{{ $l->originLabel() }}</span>
                            <div class="mt-1 text-sm font-semibold {{ $l->final_price ? 'text-[var(--color-primary-text)]' : 'text-gray-400' }}">{{ $l->final_price ? number_format($l->final_price).__('common.won_currency') : '—' }}</div>
                        </div>
                    </div>
                    @if ($l->inspection_note)
                        <div class="mt-1 truncate text-xs text-gray-500" title="{{ $l->inspection_note }}">📝 {{ $l->inspection_note }}</div>
                    @endif
                    <div class="mt-2 text-right text-xs font-medium text-[var(--color-primary-text)]">{{ __('common.detail') }} ›</div>
                </div>
            @empty
                <div class="py-8 text-center text-gray-400">{{ __('forwarding.empty') }}</div>
            @endforelse
        </div>
    </div>

    {{-- ─────────── 상세 드로어 (검차 사진/금액 읽기전용 → 전달) ─────────── --}}
    @if ($this->detail)
        @php $d = $this->detail; @endphp
        <div class="fixed inset-0 z-40 bg-black/40" wire:click="closeDetail"></div>
        <div class="fixed inset-y-0 right-0 z-50 w-full overflow-y-auto bg-white shadow-xl sm:w-[440px]">
            <div class="flex items-center justify-between border-b border-gray-200 px-5 py-4">
                <h3 class="font-bold text-gray-800">{{ $d->vehicle_number }} · {{ __('common.detail') }}</h3>
                <button class="text-gray-400 hover:text-gray-600" wire:click="closeDetail">✕</button>
            </div>

            <div class="px-5 py-4 text-sm">
                <div class="card-sm mb-3 bg-gray-50 text-xs text-gray-600">
                    <span class="badge {{ $d->originBadge() }}">{{ $d->originLabel() }}</span>
                    · {{ __('auction.salesman_label') }} <b>{{ $d->creator->name }}</b>
                </div>

                {{-- 금액 --}}
                <div class="grid grid-cols-2 gap-2 text-xs text-gray-500">
                    <div>{{ __('auction.car_cost') }}<br><b class="text-sm text-gray-800">{{ $d->carCostDisplay() }}</b></div>
                    <div>{{ __('auction.discount_rate') }}<br><b class="text-sm text-gray-800">{{ $d->discount_rate !== null ? $d->discount_rate.'%' : '—' }}</b></div>
                    <div>{{ __('auction.shipping') }}<br><b class="text-sm text-gray-800">{{ $d->shipping_usd ? '$'.number_format($d->shipping_usd) : '—' }}</b></div>
                    <div>{{ __('forwarding.th_inspection_note') }}<br><b class="text-sm text-gray-800">{{ $d->inspection_note ?: '—' }}</b></div>
                </div>
                <div class="mt-3 flex items-center justify-between rounded-md border border-[var(--color-primary)] bg-[#f5f8ff] px-3 py-2.5">
                    <span class="font-semibold text-gray-700">{{ __('auction.final_price') }}</span>
                    <span class="text-base font-bold text-[var(--color-primary-text)]">{{ $d->final_price ? number_format($d->final_price).__('common.won_currency') : '—' }}</span>
                </div>

                @if ($d->inspection_memo)
                    <div class="section-title-sm">{{ __('auction.inspection_memo') }}</div>
                    <p class="text-xs text-gray-600">{{ $d->inspection_memo }}</p>
                @endif

                <div class="section-title-sm">{{ __('auction.vehicle_photos') }}</div>
                @if ($d->photos->count())
                    <div class="grid grid-cols-4 gap-2">
                        @foreach ($d->photos as $p)
                            @if ($p->isVideo())
                                <video src="{{ $this->photoUrl($p->s3_path) }}" class="aspect-square w-full rounded-md object-cover" controls preload="metadata"></video>
                            @else
                                <img src="{{ $this->photoUrl($p->s3_path) }}" class="aspect-square w-full rounded-md object-cover" alt="">
                            @endif
                        @endforeach
                    </div>
                @else
                    <p class="text-xs text-gray-400">—</p>
                @endif

                {{-- 바이어 + 전달 --}}
                <div class="section-title-sm">{{ __('forwarding.forward_section') }}</div>
                <input class="input-base" wire:model="buyer_name" placeholder="{{ __('forwarding.buyer_placeholder') }}" maxlength="100">
                @error('buyer_name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                @if ($conflictVehicle)
                    <div class="card-sm mt-2 border-amber-300 bg-amber-50 text-amber-800">
                        <p class="text-[13px] font-semibold">⚠️ {{ __('forwarding.conflict_title', ['vehicle' => $conflictVehicle]) }}</p>
                        <p class="mt-0.5 text-xs">{{ __('forwarding.conflict_desc') }}</p>
                        <button type="button" class="btn-primary btn-sm mt-2 w-full justify-center" wire:click="forwardManual">{{ __('forwarding.conflict_manual') }}</button>
                    </div>
                @else
                    <button class="btn-primary mt-2 w-full justify-center" wire:click="forward">📤 {{ __('forwarding.forward_button') }}</button>
                    <p class="mt-1 text-xs text-gray-400">{{ __('forwarding.forward_hint') }}</p>
                @endif
            </div>
        </div>
    @endif
</div>

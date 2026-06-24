<?php

use App\Models\PurchaseListing;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * 연동 A (A) — 바이어 회신: 회신대기(awaiting_buyer) 차를 바이어(대화)별로 묶어
 * 차마다 수락/거절. 한 바이어가 여러 대 검토하는 현실(다중차) 대응 — 차별 귀속.
 * respond.io 자동화(B, Advanced)는 후속: 같은 차별 적용 로직을 webhook 이 호출.
 * SalesmanScope: 영업은 본인 글만(크로스영업 노출 없음).
 */
new #[Layout('components.layouts.app')] class extends Component {
    /** 상세 드로어 — 영업이 검차 사진/금액을 보고 바이어와 협의 후 회신(읽기전용 + 수락/거절). */
    public ?int $detailId = null;

    /** 회신대기 차를 바이어(respond_contact_id ?: buyer_name)별로 그룹 */
    #[Computed]
    public function groups()
    {
        return PurchaseListing::with('creator')
            ->where('status', 'awaiting_buyer')
            ->orderBy('updated_at')
            ->get()
            ->groupBy(fn ($l) => $l->respond_contact_id
                ? 'ct:'.$l->respond_contact_id
                : ($l->buyer_name ? 'name:'.$l->buyer_name : 'unassigned'));
    }

    /** 드로어 대상 차 1대 (검차 사진 포함). SalesmanScope 가 영업은 본인 글만 자동격리 → IDOR 차단. */
    #[Computed]
    public function detail(): ?PurchaseListing
    {
        return $this->detailId
            ? PurchaseListing::with(['creator', 'photos'])
                ->where('status', 'awaiting_buyer')
                ->find($this->detailId)
            : null;
    }

    public function openDetail(int $id): void
    {
        $this->detailId = $id;
    }

    public function closeDetail(): void
    {
        $this->reset('detailId');
        unset($this->detail);
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

    public function accept(int $id): void
    {
        $this->apply($id, 'accepted');
    }

    public function reject(int $id): void
    {
        $this->apply($id, 'rejected');
    }

    /** 차 1대에 verdict 적용. SalesmanScope(본인 것만=IDOR 차단) + VerdictService(race-safe 적용). */
    private function apply(int $id, string $verdict): void
    {
        // findOrFail 이 SalesmanScope 안에서 동작 → 영업은 본인 글만 처리 가능(IDOR 방지)
        $l = PurchaseListing::where('status', 'awaiting_buyer')->findOrFail($id);
        $ok = app(\App\Services\VerdictService::class)->apply($l->id, $verdict);

        $this->reset('detailId');
        unset($this->groups, $this->detail);
        session()->flash('ok', $ok
            ? __('verdicts.flash_processed', [
                'vehicle' => $l->vehicle_number,
                'verdict' => $verdict === 'accepted'
                    ? __('verdicts.flash_accepted_note')
                    : __('verdicts.flash_rejected_note'),
            ])
            : __('verdicts.flash_already', ['vehicle' => $l->vehicle_number]));
    }
}; ?>

<div class="p-3 md:p-6">
    <div class="mb-4">
        <h1 class="text-xl font-bold text-gray-800">{{ __('verdicts.title') }}</h1>
        <p class="mt-0.5 text-xs text-gray-500">{!! __('verdicts.subtitle', ['accept' => '<b>'.__('verdicts.accept').'</b>', 'reject' => '<b>'.__('verdicts.reject').'</b>']) !!}</p>
    </div>

    @if (session('ok'))
        <div class="card-sm mb-3 border-green-200 bg-green-50 text-[13px] text-green-700">✓ {{ session('ok') }}</div>
    @endif

    @forelse ($this->groups as $key => $items)
        @php $head = $items->first(); @endphp
        <div class="card mb-4">
            <div class="mb-3 flex items-center justify-between border-b border-gray-100 pb-2">
                <div>
                    <span class="font-bold text-gray-800">🧑 {{ $head->buyer_name ?: __('verdicts.buyer_unassigned') }}</span>
                    <span class="ml-1 text-gray-400">· {{ __('verdicts.count_awaiting', ['count' => $items->count()]) }}</span>
                    @if ($head->respond_contact_id)
                        <span class="ml-2 text-[11px] text-gray-400">{{ __('verdicts.contact') }} {{ $head->respond_contact_id }}</span>
                    @endif
                </div>
            </div>

            {{-- 데스크톱: 표 --}}
            <div class="hidden overflow-x-auto sm:block">
                <table class="tbl">
                    <thead>
                        <tr><th>{{ __('verdicts.th_vehicle') }}</th><th>{{ __('verdicts.th_origin') }}</th><th>{{ __('verdicts.th_final_price') }}</th><th>{{ __('verdicts.th_inspection_note') }}</th><th style="text-align:right">{{ __('verdicts.th_process') }}</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($items as $l)
                            <tr class="cursor-pointer hover:bg-gray-50" wire:click="openDetail({{ $l->id }})">
                                <td class="whitespace-nowrap align-middle">
                                    <div class="font-semibold text-gray-800">{{ $l->vehicle_number }}</div>
                                    <div class="text-xs text-gray-400">{{ $l->owner_name ?: __('verdicts.owner_empty') }}</div>
                                </td>
                                <td class="align-middle"><span class="badge {{ $l->originBadge() }}">{{ $l->originLabel() }}</span></td>
                                <td class="align-middle font-semibold {{ $l->final_price ? 'text-[var(--color-primary-text)]' : 'text-gray-400' }}">{{ $l->final_price ? number_format($l->final_price).__('common.won_currency') : '—' }}</td>
                                <td class="max-w-[200px] truncate align-middle text-xs text-gray-500" title="{{ $l->inspection_note }}">{{ $l->inspection_note ?: '—' }}</td>
                                <td class="whitespace-nowrap align-middle text-right font-medium text-[var(--color-primary-text)]">{{ __('common.detail') }} ›</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- 모바일: 카드 --}}
            <div class="space-y-2 sm:hidden">
                @foreach ($items as $l)
                    <div class="card-tight cursor-pointer" wire:click="openDetail({{ $l->id }})">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <div class="font-semibold text-gray-800">{{ $l->vehicle_number }}</div>
                                <div class="text-xs text-gray-400">{{ $l->owner_name ?: __('verdicts.owner_empty') }}</div>
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
                @endforeach
            </div>
        </div>
    @empty
        <div class="card py-10 text-center text-gray-400">{{ __('verdicts.empty') }}</div>
    @endforelse

    {{-- ─────────── 상세 드로어 (검차 사진/금액 읽기전용 → 회신) ─────────── --}}
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
                    · 🧑 <b>{{ $d->buyer_name ?: __('verdicts.buyer_unassigned') }}</b>
                    · {{ __('auction.salesman_label') }} <b>{{ $d->creator->name }}</b>
                </div>

                {{-- 금액 --}}
                <div class="grid grid-cols-2 gap-2 text-xs text-gray-500">
                    <div>{{ __('auction.car_cost') }}<br><b class="text-sm text-gray-800">{{ $d->carCostDisplay() }}</b></div>
                    <div>{{ __('auction.discount_rate') }}<br><b class="text-sm text-gray-800">{{ $d->discount_rate !== null ? $d->discount_rate.'%' : '—' }}</b></div>
                    <div>{{ __('auction.shipping') }}<br><b class="text-sm text-gray-800">{{ $d->shipping_usd ? '$'.number_format($d->shipping_usd) : '—' }}</b></div>
                    <div>{{ __('verdicts.th_inspection_note') }}<br><b class="text-sm text-gray-800">{{ $d->inspection_note ?: '—' }}</b></div>
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

                {{-- 회신 (사진 확인 후 결정) --}}
                <div class="section-title-sm">{{ __('verdicts.th_process') }}</div>
                <div class="flex gap-2">
                    <button class="btn-green flex-1 justify-center" wire:click="accept({{ $d->id }})"
                            wire:confirm="{{ __('verdicts.confirm_accept', ['vehicle' => $d->vehicle_number]) }}">{{ __('verdicts.accept') }}</button>
                    <button class="btn-red flex-1 justify-center" wire:click="reject({{ $d->id }})"
                            wire:confirm="{{ __('verdicts.confirm_reject', ['vehicle' => $d->vehicle_number]) }}">{{ __('verdicts.reject') }}</button>
                </div>
            </div>
        </div>
    @endif
</div>

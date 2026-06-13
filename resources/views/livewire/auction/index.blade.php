<?php

use App\Models\PurchaseListing;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public ?int $detailId = null;

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
    }

    public function closeDetail(): void
    {
        $this->detailId = null;
        unset($this->detail);
    }

    public function photoUrl(string $path): string
    {
        return Storage::disk(config('board.photo_disk'))->url($path);
    }

    public function conclude(int $id, string $result): void
    {
        if (! in_array($result, ['won', 'failed'], true)) {
            return;
        }

        $l = PurchaseListing::findOrFail($id);
        if ($l->status !== 'accepted') {
            session()->flash('err', '바이어 수락 상태의 차량만 집행할 수 있습니다.');

            return;
        }

        $l->status = $result;
        $l->save();
        unset($this->listings, $this->detail);
        session()->flash('ok', $l->vehicle_number.' — '.$l->statusLabel().' 처리되었습니다.');
    }
}; ?>

<div class="p-3 md:p-6">
    <div class="mb-4">
        <h1 class="text-xl font-bold text-gray-800">경매/구매</h1>
        <p class="mt-0.5 text-xs text-gray-500">🏁 바이어가 <b>수락</b>한 차량만 진입 — 경매=낙찰/유찰 · 엔카=구매확정/취소 · 현지 최종금액으로 집행</p>
    </div>

    @if (session('ok'))
        <div class="card-sm mb-3 border-green-200 bg-green-50 text-[13px] text-green-700">✓ {{ session('ok') }}</div>
    @endif
    @if (session('err'))
        <div class="card-sm mb-3 border-red-200 bg-red-50 text-[13px] text-red-700">⚠ {{ session('err') }}</div>
    @endif

    <div class="card">
        <div class="mb-3 flex items-center gap-2">
            <h2 class="font-bold text-gray-800">경매/구매 컨트롤창</h2>
            <span class="pill-count">수락 {{ $this->listings->where('status', 'accepted')->count() }}건</span>
        </div>

        <div class="overflow-x-auto">
            <table class="tbl">
                <thead>
                    <tr><th>차량</th><th>출처</th><th>영업</th><th>현지 최종금액</th><th>처리</th></tr>
                </thead>
                <tbody>
                    @forelse ($this->listings as $l)
                        <tr class="cursor-pointer hover:bg-gray-50" wire:click="openDetail({{ $l->id }})">
                            <td class="font-semibold text-gray-800">{{ $l->vehicle_number }}</td>
                            <td><span class="badge {{ $l->isAuction() ? 'badge-auction' : 'badge-encar' }}">{{ $l->isAuction() ? '경매' : '엔카' }}</span></td>
                            <td class="text-gray-600">{{ $l->creator->name }}</td>
                            <td class="font-semibold text-[var(--color-primary-text)]">{{ $l->final_price ? number_format($l->final_price).'원' : '—' }}</td>
                            <td>
                                @if ($l->status === 'accepted')
                                    @if ($l->isAuction())
                                        <div class="flex gap-2">
                                            <button class="btn-green btn-sm" wire:click.stop="conclude({{ $l->id }}, 'won')">낙찰</button>
                                            <button class="btn-ghost btn-sm" wire:click.stop="conclude({{ $l->id }}, 'failed')">유찰</button>
                                        </div>
                                    @else
                                        <div class="flex gap-2">
                                            <button class="btn-green btn-sm" wire:click.stop="conclude({{ $l->id }}, 'won')">구매확정</button>
                                            <button class="btn-ghost btn-sm" wire:click.stop="conclude({{ $l->id }}, 'failed')">취소</button>
                                        </div>
                                    @endif
                                @else
                                    <span class="badge {{ $l->statusBadge() }}">{{ $l->statusLabel() }} ✓</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-8 text-center text-gray-400">수락된 차량이 없습니다.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="mt-2 text-xs text-gray-400">💡 행을 클릭하면 차량 상세를 볼 수 있습니다.</p>
    </div>

    {{-- ─────────── 상세 드로어 (읽기전용 + 집행) ─────────── --}}
    @if ($this->detail)
        @php $d = $this->detail; @endphp
        <div class="fixed inset-0 z-40 bg-black/40" wire:click="closeDetail"></div>
        <div class="fixed inset-y-0 right-0 z-50 w-full overflow-y-auto bg-white shadow-xl sm:w-[440px]">
            <div class="flex items-center justify-between border-b border-gray-200 px-5 py-4">
                <h3 class="font-bold text-gray-800">{{ $d->vehicle_number }} · 상세
                    <span class="badge {{ $d->statusBadge() }} ml-1">{{ $d->statusLabel() }}</span>
                </h3>
                <button class="text-gray-400 hover:text-gray-600" wire:click="closeDetail">✕</button>
            </div>

            <div class="px-5 py-4 text-sm">
                <div class="card-sm mb-3 bg-gray-50 text-xs text-gray-600">
                    <span class="badge {{ $d->isAuction() ? 'badge-auction' : 'badge-encar' }}">{{ $d->isAuction() ? '경매' : '엔카' }}</span>
                    · 영업 <b>{{ $d->creator->name }}</b> · 지역 <b>{{ $d->region ?: '—' }}</b><br>
                    VIN <b>{{ $d->vin ?: '— (NICE 조회 예정)' }}</b>
                    @if ($d->isAuction())· {{ $d->auction_venue }} {{ $d->lot_number }}@else· {{ $d->encar_dealer ?: '' }} {{ $d->c_no ? '· 매물 '.$d->c_no : '' }}@endif
                </div>

                {{-- 금액 --}}
                <div class="grid grid-cols-2 gap-2 text-xs text-gray-500">
                    <div>차값<br><b class="text-sm text-gray-800">{{ $d->car_cost ? number_format($d->car_cost).'원' : '—' }}</b></div>
                    <div>할인율<br><b class="text-sm text-gray-800">{{ $d->discount_rate !== null ? $d->discount_rate.'%' : '—' }}</b></div>
                    <div>배송<br><b class="text-sm text-gray-800">{{ $d->shipping_usd ? '$'.number_format($d->shipping_usd) : '—' }}</b></div>
                    <div>바이어<br><b class="text-sm text-gray-800">{{ $d->buyer_name ?: '—' }}</b></div>
                </div>
                <div class="mt-3 flex items-center justify-between rounded-md border border-[var(--color-primary)] bg-[#f5f8ff] px-3 py-2.5">
                    <span class="font-semibold text-gray-700">현지 최종금액</span>
                    <span class="text-base font-bold text-[var(--color-primary-text)]">{{ $d->final_price ? number_format($d->final_price).'원' : '—' }}</span>
                </div>

                @if ($d->inspection_memo || $d->inspection_note)
                    <div class="section-title-sm">검사 메모</div>
                    <p class="text-xs text-gray-600">{{ $d->inspection_memo }}{{ $d->inspection_note ? ' · '.$d->inspection_note : '' }}</p>
                @endif

                @if ($d->photos->count())
                    <div class="section-title-sm">차량 사진</div>
                    <div class="grid grid-cols-4 gap-2">
                        @foreach ($d->photos as $p)
                            <img src="{{ $this->photoUrl($p->s3_path) }}" class="aspect-square w-full rounded-md object-cover" alt="">
                        @endforeach
                    </div>
                @endif

                {{-- 집행 (accepted 일 때만) --}}
                @if ($d->status === 'accepted')
                    <div class="section-title-sm">집행</div>
                    <div class="flex gap-2">
                        <button class="btn-green flex-1 justify-center" wire:click="conclude({{ $d->id }}, 'won')">{{ $d->isAuction() ? '낙찰' : '구매확정' }}</button>
                        <button class="btn-ghost flex-1 justify-center" wire:click="conclude({{ $d->id }}, 'failed')">{{ $d->isAuction() ? '유찰' : '취소' }}</button>
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>

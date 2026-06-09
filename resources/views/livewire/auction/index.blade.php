<?php

use App\Models\PurchaseListing;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    #[Computed]
    public function listings()
    {
        return PurchaseListing::with('creator')
            ->whereIn('status', ['accepted', 'won', 'failed'])
            ->latest()
            ->get();
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
        unset($this->listings);
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
                        <tr>
                            <td class="font-semibold text-gray-800">{{ $l->vehicle_number }}</td>
                            <td><span class="badge {{ $l->isAuction() ? 'badge-auction' : 'badge-encar' }}">{{ $l->isAuction() ? '경매' : '엔카' }}</span></td>
                            <td class="text-gray-600">{{ $l->creator->name }}</td>
                            <td class="font-semibold text-[var(--color-primary-text)]">{{ $l->final_price ? number_format($l->final_price).'원' : '—' }}</td>
                            <td>
                                @if ($l->status === 'accepted')
                                    @if ($l->isAuction())
                                        <div class="flex gap-2">
                                            <button class="btn-green btn-sm" wire:click="conclude({{ $l->id }}, 'won')">낙찰</button>
                                            <button class="btn-ghost btn-sm" wire:click="conclude({{ $l->id }}, 'failed')">유찰</button>
                                        </div>
                                    @else
                                        <div class="flex gap-2">
                                            <button class="btn-green btn-sm" wire:click="conclude({{ $l->id }}, 'won')">구매확정</button>
                                            <button class="btn-ghost btn-sm" wire:click="conclude({{ $l->id }}, 'failed')">취소</button>
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
    </div>
</div>

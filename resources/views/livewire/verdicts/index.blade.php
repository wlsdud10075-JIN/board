<?php

use App\Models\PurchaseListing;
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
    /** 회신대기 차를 바이어(respond_conversation_id ?: buyer_name)별로 그룹 */
    #[Computed]
    public function groups()
    {
        return PurchaseListing::with('creator')
            ->where('status', 'awaiting_buyer')
            ->orderBy('updated_at')
            ->get()
            ->groupBy(fn ($l) => $l->respond_conversation_id
                ? 'conv:'.$l->respond_conversation_id
                : ($l->buyer_name ? 'name:'.$l->buyer_name : 'unassigned'));
    }

    public function accept(int $id): void
    {
        $this->apply($id, 'accepted');
    }

    public function reject(int $id): void
    {
        $this->apply($id, 'rejected');
    }

    /** 차 1대에 verdict 적용 (awaiting_buyer 가드 + SalesmanScope = 본인 것만). 옵저버가 감사기록. */
    private function apply(int $id, string $verdict): void
    {
        $l = PurchaseListing::where('status', 'awaiting_buyer')->findOrFail($id);
        $l->buyer_verdict = $verdict;
        $l->status = $verdict === 'accepted' ? 'accepted' : 'rejected';
        $l->save();   // awaiting_buyer→accepted 가드(verdict=accepted 충족) / →rejected 허용

        unset($this->groups);
        session()->flash('ok', $l->vehicle_number.' → '.($verdict === 'accepted' ? '수락 (구매/경매 대기로 이동)' : '거절').' 처리됨');
    }
}; ?>

<div class="p-3 md:p-6">
    <div class="mb-4">
        <h1 class="text-xl font-bold text-gray-800">바이어 회신</h1>
        <p class="mt-0.5 text-xs text-gray-500">회신대기 차량을 바이어별로 묶어 표시 · 차마다 <b>수락/거절</b>을 처리하세요 (한 바이어가 여러 대 검토 가능)</p>
    </div>

    @if (session('ok'))
        <div class="card-sm mb-3 border-green-200 bg-green-50 text-[13px] text-green-700">✓ {{ session('ok') }}</div>
    @endif

    @forelse ($this->groups as $key => $items)
        @php $head = $items->first(); @endphp
        <div class="card mb-4">
            <div class="mb-3 flex items-center justify-between border-b border-gray-100 pb-2">
                <div>
                    <span class="font-bold text-gray-800">🧑 {{ $head->buyer_name ?: '바이어 미지정' }}</span>
                    <span class="ml-1 text-gray-400">· {{ $items->count() }}대 회신대기</span>
                    @if ($head->respond_conversation_id)
                        <span class="ml-2 text-[11px] text-gray-400">대화 {{ $head->respond_conversation_id }}</span>
                    @endif
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="tbl">
                    <thead>
                        <tr><th>차량</th><th>출처</th><th>최종금액</th><th>추가검사사항</th><th style="text-align:right">회신 처리</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($items as $l)
                            <tr>
                                <td class="whitespace-nowrap align-middle">
                                    <div class="font-semibold text-gray-800">{{ $l->vehicle_number }}</div>
                                    <div class="text-xs text-gray-400">{{ $l->owner_name ?: '소유자 —' }}</div>
                                </td>
                                <td class="align-middle"><span class="badge {{ $l->originBadge() }}">{{ $l->originLabel() }}</span></td>
                                <td class="align-middle font-semibold {{ $l->final_price ? 'text-[var(--color-primary-text)]' : 'text-gray-400' }}">{{ $l->final_price ? number_format($l->final_price).'원' : '—' }}</td>
                                <td class="max-w-[200px] truncate align-middle text-xs text-gray-500" title="{{ $l->inspection_note }}">{{ $l->inspection_note ?: '—' }}</td>
                                <td class="align-middle whitespace-nowrap">
                                    <div class="flex justify-end gap-2">
                                        <button class="btn-green" wire:click="accept({{ $l->id }})"
                                                wire:confirm="{{ $l->vehicle_number }} — 바이어 수락으로 처리할까요? (구매/경매 대기로 이동)">수락</button>
                                        <button class="btn-red" wire:click="reject({{ $l->id }})"
                                                wire:confirm="{{ $l->vehicle_number }} — 바이어 거절로 처리할까요?">거절</button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @empty
        <div class="card py-10 text-center text-gray-400">회신대기 중인 차량이 없습니다. (현지확인에서 바이어에게 전달되면 여기 표시됩니다.)</div>
    @endforelse
</div>

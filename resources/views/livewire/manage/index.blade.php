<?php

use App\Models\BoardAuditLog;
use App\Models\PurchaseListing;
use App\Services\BoardAudit;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public ?int $editingId = null;
    public string $source = 'encar';
    public ?string $expected_price = null;
    public ?string $final_price = null;
    public string $status = 'draft';
    public string $buyer_verdict = 'none';
    public string $buyer_name = '';
    public string $inspection_memo = '';

    public function fieldLabel(?string $f): string
    {
        return [
            'source' => '출처', 'expected_price' => '예상가', 'final_price' => '최종금액',
            'status' => '상태', 'buyer_verdict' => '바이어회신', 'buyer_name' => '바이어', 'inspection_memo' => '메모',
        ][$f] ?? (string) $f;
    }

    #[Computed]
    public function listings()
    {
        return PurchaseListing::with('creator')->latest()->get();
    }

    #[Computed]
    public function editing(): ?PurchaseListing
    {
        return $this->editingId ? PurchaseListing::find($this->editingId) : null;
    }

    #[Computed]
    public function recentLogs()
    {
        return BoardAuditLog::with('user')->latest('id')->limit(8)->get();
    }

    public function openEdit(int $id): void
    {
        $l = PurchaseListing::findOrFail($id);
        $this->editingId = $l->id;
        $this->source = $l->source;
        $this->expected_price = $l->expected_price !== null ? (string) $l->expected_price : null;
        $this->final_price = $l->final_price !== null ? (string) $l->final_price : null;
        $this->status = $l->status;
        $this->buyer_verdict = $l->buyer_verdict;
        $this->buyer_name = $l->buyer_name ?? '';
        $this->inspection_memo = $l->inspection_memo ?? '';
        $this->resetErrorBag();
    }

    public function closeEdit(): void
    {
        $this->reset(['editingId', 'source', 'expected_price', 'final_price', 'status', 'buyer_verdict', 'buyer_name', 'inspection_memo']);
        unset($this->editing, $this->listings, $this->recentLogs);
    }

    public function save(): void
    {
        $this->validate([
            'source' => 'required|in:encar,auction',
            'expected_price' => 'nullable|numeric|min:0',
            'final_price' => 'nullable|numeric|min:0',
            'status' => 'required|in:'.implode(',', PurchaseListing::STATUSES),
            'buyer_verdict' => 'required|in:none,pending,accepted,rejected',
            'buyer_name' => 'nullable|string|max:100',
        ]);

        $l = PurchaseListing::findOrFail($this->editingId);
        $fields = ['source', 'expected_price', 'final_price', 'status', 'buyer_verdict', 'buyer_name', 'inspection_memo'];
        $original = $l->only($fields);

        $l->source = $this->source;
        $l->expected_price = ($this->expected_price === null || $this->expected_price === '') ? null : (int) $this->expected_price;
        $l->final_price = ($this->final_price === null || $this->final_price === '') ? null : (int) $this->final_price;
        $l->status = $this->status;
        $l->buyer_verdict = $this->buyer_verdict;
        $l->buyer_name = $this->buyer_name ?: null;
        $l->inspection_memo = $this->inspection_memo ?: null;

        // 시간잠금·상태전이 무관 수정 (차량번호·VIN 은 모델이 여전히 차단)
        $l->allowManagerOverride = true;
        $l->save();

        BoardAudit::logChanges($l, $original, $fields, Auth::id());

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

    {{-- KPI --}}
    @php
        $all = $this->listings;
        $todayCount = $all->filter(fn ($l) => $l->created_at?->isToday())->count();
    @endphp
    <div class="mb-4 grid grid-cols-2 gap-3 md:grid-cols-5">
        <div class="kpi"><div class="k">오늘 매입예정</div><div class="v">{{ $todayCount }}</div></div>
        <div class="kpi"><div class="k">엔카</div><div class="v" style="color:var(--color-encar)">{{ $all->where('source', 'encar')->count() }}</div></div>
        <div class="kpi"><div class="k">경매</div><div class="v" style="color:var(--color-auction)">{{ $all->where('source', 'auction')->count() }}</div></div>
        <div class="kpi"><div class="k">바이어 수락</div><div class="v" style="color:#16a34a">{{ $all->where('buyer_verdict', 'accepted')->count() }}</div></div>
        <div class="kpi"><div class="k">ERP 전환대기</div><div class="v" style="color:var(--color-primary)">{{ $all->where('status', 'won')->count() }}</div></div>
    </div>

    {{-- 전체 현황 --}}
    <div class="card">
        <h2 class="mb-3 font-bold text-gray-800">전체 현황 <span class="text-gray-400">· 모든 행 수정 가능</span></h2>
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead>
                    <tr><th>차량</th><th>출처</th><th>영업</th><th>예상가</th><th>최종금액</th><th>바이어</th><th>상태</th><th></th></tr>
                </thead>
                <tbody>
                    @forelse ($all as $l)
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
                        <tr><td colspan="8" class="py-8 text-center text-gray-400">데이터가 없습니다.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- 최근 감사로그 --}}
    <div class="card mt-4">
        <h2 class="mb-3 font-bold text-gray-800">최근 감사로그 <span class="text-gray-400">· board_audit_logs</span></h2>
        @forelse ($this->recentLogs as $log)
            <div class="flex items-center gap-2 border-b border-gray-100 py-1.5 text-[13px] last:border-0">
                <span class="text-gray-400">{{ $log->created_at?->format('m/d H:i') }}</span>
                <span class="font-medium text-gray-700">{{ $log->user?->name }}</span>
                <span class="text-gray-500">#{{ $log->purchase_listing_id }}</span>
                <span class="badge badge-gray">{{ $this->fieldLabel($log->field) }}</span>
                <span class="text-gray-500">{{ $log->old_value ?? '∅' }} → <b class="text-gray-800">{{ $log->new_value ?? '∅' }}</b></span>
            </div>
        @empty
            <p class="text-sm text-gray-400">기록된 변경이 없습니다.</p>
        @endforelse
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
                <div class="card-sm mb-3 bg-gray-50 text-xs text-gray-500">
                    차량번호 <b>{{ $e->vehicle_number }}</b> · VIN <b>{{ $e->vin }}</b> — 식별값은 수정 불가
                </div>

                <label class="label-base">출처</label>
                <select class="input-base" wire:model="source">
                    <option value="encar">엔카</option>
                    <option value="auction">경매</option>
                </select>

                <label class="label-base mt-3">예상가</label>
                <input class="input-base" wire:model="expected_price" inputmode="numeric">
                @error('expected_price') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                <label class="label-base mt-3">현지 최종금액</label>
                <input class="input-base" wire:model="final_price" inputmode="numeric">
                @error('final_price') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                <label class="label-base mt-3">상태</label>
                <select class="input-base" wire:model="status">
                    @foreach (\App\Models\PurchaseListing::STATUSES as $s)
                        <option value="{{ $s }}">{{ $s }}</option>
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

                <label class="label-base mt-3">메모</label>
                <input class="input-base" wire:model="inspection_memo">

                <div class="mt-5 flex gap-2">
                    <button class="btn-primary flex-1 justify-center" wire:click="save">저장 (감사로그 기록)</button>
                    <button class="btn-ghost" wire:click="closeEdit">취소</button>
                </div>
            </div>
        </div>
    @endif
</div>

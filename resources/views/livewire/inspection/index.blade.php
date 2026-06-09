<?php

use App\Models\PurchaseListing;
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

    #[Computed]
    public function groups()
    {
        return PurchaseListing::with(['creator', 'photos'])
            ->whereIn('status', ['draft', 'awaiting_buyer', 'accepted'])
            ->latest()
            ->get()
            ->groupBy(fn ($l) => $l->creator->name);
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
        $this->photos = [];
        $this->resetErrorBag();
    }

    public function closeDrawer(): void
    {
        $this->reset(['editingId', 'final_price', 'inspection_memo', 'buyer_name', 'photos']);
        unset($this->editing, $this->groups);
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

    public function saveDraft(): void
    {
        $this->validate(['final_price' => 'nullable|numeric|min:0']);
        $l = PurchaseListing::findOrFail($this->editingId);
        $l->final_price = ($this->final_price === null || $this->final_price === '') ? null : (int) $this->final_price;
        $l->inspection_memo = $this->inspection_memo ?: null;
        if ($this->buyer_name !== '') {
            $l->buyer_name = $this->buyer_name;
        }
        $l->save();
        $this->persistPhotos($l);
        session()->flash('ok', '저장되었습니다.');
        $this->closeDrawer();
    }

    public function sendToBuyer(): void
    {
        $this->validate([
            'final_price' => 'required|numeric|min:0',
            'buyer_name' => 'required|string|max:100',
        ], attributes: ['final_price' => '현지 최종금액', 'buyer_name' => '바이어명']);

        $l = PurchaseListing::findOrFail($this->editingId);
        $l->final_price = (int) $this->final_price;
        $l->inspection_memo = $this->inspection_memo ?: null;
        $l->buyer_name = $this->buyer_name;
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
        <p class="mt-0.5 text-xs text-gray-500">👁 전체 영업 리스트 · 차 상태 확인 → 최종금액 산정 → 바이어에게 사진+최종금액 전달</p>
    </div>

    <div class="card-sm mb-3 flex items-start gap-2 text-[13px] text-amber-800" style="background:#fffbeb;border-color:#fde68a">
        <span>⏱</span>
        <span><b>시간 흐름 안내</b> — 현지 금액 산정은 실시간, 바이어 회신은 몇 시간 뒤일 수 있습니다. "회신대기"로 두고 다른 차량을 먼저 처리하세요.</span>
    </div>

    @if (session('ok'))
        <div class="card-sm mb-3 border-green-200 bg-green-50 text-[13px] text-green-700">✓ {{ session('ok') }}</div>
    @endif

    @forelse ($this->groups as $salesman => $items)
        <div class="card mb-3">
            <div class="mb-2 flex items-center gap-2">
                <h2 class="font-bold text-gray-800">{{ $salesman }}</h2>
                <span class="pill-count">{{ $items->count() }}건</span>
            </div>
            <div class="flex flex-col gap-2">
                @foreach ($items as $l)
                    <div class="flex items-center gap-3 rounded-lg border border-gray-200 px-3 py-2.5">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <span class="font-semibold text-gray-800">{{ $l->vehicle_number }}</span>
                                <span class="badge {{ $l->isAuction() ? 'badge-auction' : 'badge-encar' }}">{{ $l->isAuction() ? '경매' : '엔카' }}</span>
                            </div>
                            <div class="mt-0.5 text-xs text-gray-500">
                                {{ $l->final_price ? '최종 '.number_format($l->final_price).'원' : ($l->expected_price ? '예상가 '.number_format($l->expected_price).'원' : '금액 미정') }}
                                · VIN {{ \Illuminate\Support\Str::limit($l->vin, 8, '') }}
                            </div>
                        </div>
                        <span class="badge {{ $l->statusBadge() }}">{{ $l->statusLabel() }}</span>
                        <button class="btn-primary btn-sm" wire:click="openDrawer({{ $l->id }})">차상태·금액</button>
                    </div>
                @endforeach
            </div>
        </div>
    @empty
        <div class="card text-center text-gray-400">현지확인 대상 차량이 없습니다.</div>
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

                {{-- 메모 --}}
                <div class="section-title-sm">차 상태 메모</div>
                <input class="input-base" wire:model="inspection_memo" placeholder="예: 운전석 시트 사용감, 앞범퍼 미세 스크래치">

                {{-- 최종금액 --}}
                <div class="section-title-sm">현지 최종금액 (차 상태 반영)</div>
                <input class="input-base" wire:model="final_price" inputmode="numeric" placeholder="예: 13200000">
                @error('final_price') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

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

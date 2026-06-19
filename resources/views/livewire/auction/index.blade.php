<?php

use App\Models\PurchaseListing;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public ?int $detailId = null;

    // 소유자/차주명 (연동 B: car-erp NICE 조회 입력값) — 매입예정에서 미리 입력, 여기서 보정.
    public string $owner_name = '';

    // 매입 정산 입금정보 (§6e) — 판매자/경매장 계좌. won 단계 입력 → 연동 B 전달.
    public string $payee_name = '';
    public string $payee_bank = '';
    public string $payee_account = '';

    private function payeeRules(): array
    {
        return [
            'owner_name' => 'nullable|string|max:60',
            'payee_name' => 'nullable|string|max:60',
            'payee_bank' => 'nullable|string|max:40',
            'payee_account' => 'nullable|string|max:40',
        ];
    }

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
        $l = PurchaseListing::findOrFail($id);
        $this->owner_name = $l->owner_name ?? '';
        $this->payee_name = $l->payee_name ?? '';
        $this->payee_bank = $l->payee_bank ?? '';
        $this->payee_account = $l->payee_account ?? '';
        $this->resetErrorBag();
    }

    public function closeDetail(): void
    {
        $this->reset(['detailId', 'owner_name', 'payee_name', 'payee_bank', 'payee_account']);
        unset($this->detail);
    }

    private function applyPayee(PurchaseListing $l): void
    {
        $l->owner_name = $this->owner_name ?: null;
        $l->payee_name = $this->payee_name ?: null;
        $l->payee_bank = $this->payee_bank ?: null;
        $l->payee_account = $this->payee_account ?: null;
    }

    /** 입금정보만 저장(이미 won 인 차량 보정용). */
    public function savePayee(): void
    {
        $this->validate($this->payeeRules());
        $l = PurchaseListing::findOrFail($this->detailId);
        $this->applyPayee($l);
        $l->save();
        unset($this->detail, $this->listings);
        session()->flash('ok', '입금정보를 저장했습니다.');
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

        if ($result === 'won') {
            $this->validate($this->payeeRules());
            $this->applyPayee($l);   // 낙찰/구매확정 시 입금정보 함께 저장
        }

        $l->status = $result;
        $l->save();
        $this->reset(['detailId', 'owner_name', 'payee_name', 'payee_bank', 'payee_account']);
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

        {{-- 데스크톱: 표 --}}
        <div class="hidden overflow-x-auto sm:block">
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
                                    <span class="badge badge-amber">집행 대기 · 클릭</span>
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

        {{-- 모바일: 카드 --}}
        <div class="space-y-2 sm:hidden">
            @forelse ($this->listings as $l)
                <div class="card-tight cursor-pointer" wire:click="openDetail({{ $l->id }})">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <div class="font-semibold text-gray-800">{{ $l->vehicle_number }}</div>
                            <div class="text-xs text-gray-400">영업 {{ $l->creator->name }}</div>
                        </div>
                        <span class="badge {{ $l->isAuction() ? 'badge-auction' : 'badge-encar' }} shrink-0">{{ $l->isAuction() ? '경매' : '엔카' }}</span>
                    </div>
                    <div class="mt-2 flex items-center justify-between gap-2">
                        @if ($l->status === 'accepted')
                            <span class="badge badge-amber">집행 대기 · 탭</span>
                        @else
                            <span class="badge {{ $l->statusBadge() }}">{{ $l->statusLabel() }} ✓</span>
                        @endif
                        <span class="shrink-0 text-sm font-semibold text-[var(--color-primary-text)]">{{ $l->final_price ? number_format($l->final_price).'원' : '—' }}</span>
                    </div>
                </div>
            @empty
                <div class="py-8 text-center text-gray-400">수락된 차량이 없습니다.</div>
            @endforelse
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
                            @if ($p->isVideo())
                                <video src="{{ $this->photoUrl($p->s3_path) }}" class="aspect-square w-full rounded-md object-cover" controls preload="metadata"></video>
                            @else
                                <img src="{{ $this->photoUrl($p->s3_path) }}" class="aspect-square w-full rounded-md object-cover" alt="">
                            @endif
                        @endforeach
                    </div>
                @endif

                {{-- 소유자(차주) — accepted·won 에서 입력/보정 (car-erp NICE 조회 입력값) --}}
                @if (in_array($d->status, ['accepted', 'won'], true))
                    <div class="section-title-sm">소유자 <span class="text-[11px] font-normal text-gray-400">(차주명 · car-erp VIN 조회용)</span></div>
                    <input wire:model.blur="owner_name" class="input-base" placeholder="등록 소유자명" maxlength="60">
                    @error('owner_name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                @endif

                {{-- 입금정보 (정산 = 판매자/경매장 계좌) — accepted·won 에서 입력/수정 --}}
                @if (in_array($d->status, ['accepted', 'won'], true))
                    <div class="section-title-sm">입금정보 <span class="text-[11px] font-normal text-gray-400">(매입 정산 계좌 · car-erp 전달)</span></div>
                    <div x-data>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <input x-ref="bankAuc" wire:model.blur="payee_bank" list="korean-banks-auction" autocomplete="off"
                                       class="input-base" placeholder="은행" maxlength="100"
                                       x-on:input="$refs.acctAuc.value = $store.koreanBanks.applyMask($el.value, $refs.acctAuc.value)">
                                <datalist id="korean-banks-auction"><template x-for="b in $store.koreanBanks.names()" :key="b"><option :value="b"></option></template></datalist>
                            </div>
                            <div><input wire:model.blur="payee_name" class="input-base" placeholder="예금주" maxlength="60"></div>
                        </div>
                        <input x-ref="acctAuc" wire:model.blur="payee_account" autocomplete="off"
                               class="input-base mt-2 font-mono" placeholder="계좌번호 (암호화 저장)"
                               x-on:input="$el.value = $store.koreanBanks.applyMask($refs.bankAuc.value, $el.value)">
                    </div>
                    @error('payee_name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    @error('payee_bank') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    @error('payee_account') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                @endif

                {{-- 집행 --}}
                @if ($d->status === 'accepted')
                    <div class="section-title-sm">집행</div>
                    <p class="mb-1 text-[11px] text-gray-400">낙찰/구매확정 시 위 입금정보가 함께 저장됩니다.</p>
                    <div class="flex gap-2">
                        <button class="btn-green flex-1 justify-center" wire:click="conclude({{ $d->id }}, 'won')">{{ $d->isAuction() ? '낙찰' : '구매확정' }}</button>
                        <button class="btn-ghost flex-1 justify-center" wire:click="conclude({{ $d->id }}, 'failed')">{{ $d->isAuction() ? '유찰' : '취소' }}</button>
                    </div>
                @elseif ($d->status === 'won')
                    <button class="btn-primary mt-3 w-full justify-center" wire:click="savePayee">입금정보 저장</button>
                @endif
            </div>
        </div>
    @endif
</div>

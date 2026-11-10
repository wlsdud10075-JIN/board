<?php

use App\Models\Claim;
use App\Models\ClaimFile;
use App\Models\User;
use App\Services\ClaimService;
use App\Support\UploadGuard;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

/**
 * 클레임 접수 드로어(2026-11-10 Jin) — /portal 판매내역 행 [클레임 접수] 가 연다(ERP 행 스냅샷을 그대로 받는다, 다시 적는 칸 0).
 * 필수 = 도착일(기본 ETA·영업 수정) · 접수 km · 계기판 사진 ≥1 · 손상 사진/영상 ≥1 · INVOICE(정비소 자료) ≥1 · 내용. 14일 초과·km 는 표시만(§15).
 * 명의 영업 = 포털에서 보고 있던 사용자(super 대리·공유 관리 대신 접수 가능, 업무관리자 조회 전용은 포털이 버튼을 숨긴다 — 서버도 재검사).
 */
new class extends Component
{
    use WithFileUploads;

    public bool $open = false;

    /** ERP 행 스냅샷 — vehicle_id·vehicle_number·vin·brand·model_type·buyer·buyer_id·sale_price·currency·sale_date·eta_date */
    public array $row = [];

    public ?int $salesmanId = null;

    public string $arrived_at = '';

    public string $received_km = '';

    public string $content = '';

    public string $buyer_contact_name = '';

    public array $odometer = [];

    public array $damage = [];

    public array $invoice = [];

    public array $other = [];

    /** 같은 차에 열린 클레임이 있으면 그 상태(중복 접수 안내). */
    public ?string $existingStatus = null;

    #[On('open-claim-intake')]
    public function openFor(array $row, int $salesmanId): void
    {
        if (! self::canActFor(Auth::user(), $salesmanId)) {
            return;
        }
        $this->resetErrorBag();
        $this->reset(['arrived_at', 'received_km', 'content', 'buyer_contact_name', 'odometer', 'damage', 'invoice', 'other', 'existingStatus']);
        $this->row = $row;
        $this->salesmanId = $salesmanId;
        $this->arrived_at = (string) ($row['eta_date'] ?? '');   // 기본값 = ERP ETA(실제 도착일은 ERP 에 없다) — 영업이 고친다
        $existing = Claim::where('car_erp_vehicle_id', (int) ($row['vehicle_id'] ?? 0))->where('status', '!=', Claim::CLOSED)->latest('id')->first();
        $this->existingStatus = $existing?->statusLabel();
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
    }

    /** 이 사용자가 그 영업 명의로 접수할 수 있나 — 본인 · super · 그 영업이 공유한 관리(포털 proxyWriteBlocked 와 같은 규칙). */
    public static function canActFor(User $actor, int $salesmanId): bool
    {
        if ($actor->id === $salesmanId) {
            return true;
        }
        if ($actor->isSuper()) {
            return true;
        }

        return $actor->isManager() && ! $actor->hasFullAccess() && $actor->managedSalesmanUsers()->where('users.id', $salesmanId)->exists();
    }

    public function save(ClaimService $svc): void
    {
        $actor = Auth::user();
        if ($this->salesmanId === null || ! self::canActFor($actor, $this->salesmanId)) {
            abort(403);
        }
        $this->validate([
            'arrived_at' => ['required', 'date'],
            'received_km' => ['required', 'integer', 'min:0', 'max:9999999'],
            'content' => ['required', 'string', 'max:4000'],
            'buyer_contact_name' => ['nullable', 'string', 'max:120'],
            'odometer' => ['required', 'array', 'min:1'],
            'damage' => ['required', 'array', 'min:1'],
            'invoice' => ['required', 'array', 'min:1'],
            'odometer.*' => ['file', 'max:204800'],
            'damage.*' => ['file', 'max:204800'],
            'invoice.*' => ['file', 'max:204800'],
            'other.*' => ['file', 'max:204800'],
        ], [], [
            'arrived_at' => __('claims.arrived_at'), 'received_km' => __('claims.received_km'), 'content' => __('claims.content'),
            'odometer' => __('claims.kind.odometer'), 'damage' => __('claims.kind.damage'), 'invoice' => __('claims.kind.invoice'),
        ]);

        $files = [
            ClaimFile::KIND_ODOMETER => array_values(array_filter($this->odometer)),
            ClaimFile::KIND_DAMAGE => array_values(array_filter($this->damage)),
            ClaimFile::KIND_INVOICE => array_values(array_filter($this->invoice)),
            ClaimFile::KIND_OTHER => array_values(array_filter($this->other)),
        ];
        foreach ($files as $list) {
            foreach ($list as $f) {
                if (UploadGuard::isExecutable($f->getClientOriginalName())) {
                    $this->addError('damage', __('claims.exec_error', ['name' => $f->getClientOriginalName()]));

                    return;
                }
            }
        }
        if (empty($this->row['vehicle_id']) || empty($this->row['vehicle_number'])) {
            $this->addError('content', __('claims.row_missing'));

            return;
        }

        $claim = $svc->intake($actor, User::findOrFail($this->salesmanId), $this->row, [
            'arrived_at' => $this->arrived_at,
            'received_km' => $this->received_km,
            'content' => $this->content,
            'buyer_contact_name' => trim($this->buyer_contact_name) ?: null,
        ], $files);

        $this->open = false;
        $this->dispatch('claim-created', id: $claim->id);
        session()->flash('ok', __('claims.flash_created', ['vehicle' => \App\Support\Plate::show($claim->vehicle_number)]));
    }

    /** 도착일 기준 경과 — 입력 중 미리 보여준다(초과는 안내만). */
    public function daysAfterArrival(): ?int
    {
        if ($this->arrived_at === '') {
            return null;
        }
        try {
            return (int) \Illuminate\Support\Carbon::parse($this->arrived_at)->startOfDay()->diffInDays(today(), false);
        } catch (\Throwable) {
            return null;
        }
    }
}; ?>

<div>
    @if ($open)
        @php $days = $this->daysAfterArrival(); @endphp
        <div class="fixed inset-0 z-40 bg-black/40" wire:click="close"></div>
        <div class="fixed inset-y-0 right-0 z-50 w-full overflow-y-auto bg-white shadow-xl sm:w-[480px]">
            <div class="flex items-center justify-between border-b border-gray-200 px-5 py-4">
                <h3 class="font-bold text-gray-800">📋 {{ __('claims.intake_title') }} · {{ \App\Support\Plate::show($row['vehicle_number'] ?? '') }}</h3>
                <button class="text-gray-400 hover:text-gray-600" wire:click="close">✕</button>
            </div>

            <form wire:submit="save" class="px-5 py-4 text-sm">
                {{-- ERP 스냅샷(읽기) --}}
                <div class="card-sm mb-3 bg-gray-50 text-xs text-gray-600">
                    <div><b class="text-gray-800">{{ \App\Support\Plate::show($row['vehicle_number'] ?? '') }}</b>
                        @if (! empty($row['brand']) || ! empty($row['model_type'])) · {{ trim(($row['brand'] ?? '').' '.($row['model_type'] ?? '')) }}@endif
                        @if (! empty($row['vin'])) · {{ $row['vin'] }}@endif</div>
                    <div class="mt-0.5">🧑 {{ $row['buyer'] ?? '—' }}
                        · {{ is_numeric($row['sale_price'] ?? null) ? trim(($row['currency'] ?? '').' '.number_format((float) $row['sale_price'])) : '—' }}
                        · ETA {{ $row['eta_date'] ?? '—' }}</div>
                    @if ($existingStatus)
                        <div class="mt-1 text-amber-700">⚠️ {{ __('claims.intake_existing', ['status' => $existingStatus]) }}</div>
                    @endif
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="label-base">{{ __('claims.arrived_at') }} <span class="text-red-500">*</span></label>
                        <input type="date" class="input-base" wire:model.live="arrived_at">
                        <p class="mt-0.5 text-[11px] text-gray-400">{{ __('claims.arrived_at_hint') }}</p>
                        @if ($days !== null)
                            <p class="mt-0.5 text-[11px] {{ $days > \App\Models\Claim::ARRIVAL_WINDOW_DAYS ? 'font-semibold text-red-600' : 'text-gray-500' }}">
                                D+{{ $days }}{{ $days > \App\Models\Claim::ARRIVAL_WINDOW_DAYS ? ' · '.__('claims.overdue_chip').' — '.__('claims.overdue_intake_hint') : '' }}
                            </p>
                        @endif
                        @error('arrived_at')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="label-base">{{ __('claims.received_km') }} <span class="text-red-500">*</span></label>
                        <input type="number" inputmode="numeric" class="input-base" wire:model="received_km" placeholder="예: 123456">
                        <p class="mt-0.5 text-[11px] text-gray-400">{{ __('claims.received_km_hint') }}</p>
                        @error('received_km')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                </div>

                <div class="mt-3">
                    <label class="label-base">{{ __('claims.buyer_contact_name') }}</label>
                    <input class="input-base" wire:model="buyer_contact_name" maxlength="120" placeholder="{{ __('claims.buyer_contact_name_ph') }}">
                </div>

                <div class="mt-3">
                    <label class="label-base">{{ __('claims.content') }} <span class="text-red-500">*</span></label>
                    <textarea class="input-base" rows="4" wire:model="content" placeholder="{{ __('claims.content_ph') }}"></textarea>
                    @error('content')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                {{-- 첨부 종류별 칸 — 바이어가 보낸 것을 그대로(§15). 영상은 WhatsApp 인코딩이라 크기 문제 적음. --}}
                @foreach ([['odometer', true], ['damage', true], ['invoice', true], ['other', false]] as [$kind, $req])
                    <div class="mt-3" wire:key="intake-{{ $kind }}">
                        <label class="label-base">{{ __('claims.kind.'.$kind) }} @if ($req)<span class="text-red-500">*</span>@endif
                            <span class="font-normal text-gray-400">— {{ __('claims.kind_hint.'.$kind) }}</span></label>
                        <input type="file" multiple class="block w-full text-xs" wire:model="{{ $kind }}"
                               accept="{{ $kind === 'invoice' ? 'image/*,application/pdf' : ($kind === 'odometer' ? 'image/*' : 'image/*,video/*,application/pdf') }}">
                        <div wire:loading wire:target="{{ $kind }}" class="text-[11px] text-gray-400">{{ __('claims.uploading') }}</div>
                        @if (count(array_filter($$kind)))
                            <p class="mt-0.5 text-[11px] text-gray-500">{{ __('claims.files_selected', ['count' => count(array_filter($$kind))]) }}</p>
                        @endif
                        @error($kind)<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                        @error($kind.'.*')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                @endforeach

                <p class="mt-3 text-[11px] text-gray-400">{{ __('claims.intake_footnote') }}</p>

                <div class="mt-4 flex gap-2">
                    <button type="submit" class="btn-primary flex-1 justify-center" wire:loading.attr="disabled" wire:target="save,odometer,damage,invoice,other">
                        <span wire:loading.remove wire:target="save">{{ __('claims.intake_submit') }}</span>
                        <span wire:loading wire:target="save">{{ __('claims.saving') }}</span>
                    </button>
                    <button type="button" class="btn-outline" wire:click="close">{{ __('common.cancel') }}</button>
                </div>
            </form>
        </div>
    @endif
</div>

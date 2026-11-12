<?php

use App\Models\TransportCompany;
use App\Models\TransportCompanyPhone;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * 탁송 업체 관리(2026-11-12 Jin) — 관리 role 이상. 업체·번호를 넣으면 영업 드로어 「탁송 배차」 탭에 즉시 뜬다(배포·재승인 없음).
 * 번호는 encrypted 저장, 화면엔 그대로. 삭제는 soft(지난 요청 이력엔 company_name 스냅샷이 남는다).
 */
new #[Layout('components.layouts.app')] class extends Component
{
    public string $newName = '';

    public string $newCode = '';

    /** @var array<int, array{phone:string, label:string}> 업체별 새 번호 입력칸 */
    public array $newPhone = [];

    public ?string $notice = null;

    public function mount(): void
    {
        abort_unless(Auth::user()->isManager() || Auth::user()->hasFullAccess(), 403);
    }

    #[Computed]
    public function companies()
    {
        return TransportCompany::with('phones')->orderByDesc('is_active')->orderBy('name')->get();
    }

    public function addCompany(): void
    {
        $this->validate(['newName' => 'required|string|max:60', 'newCode' => 'nullable|string|max:30']);
        TransportCompany::create(['name' => trim($this->newName), 'code' => trim($this->newCode) ?: null, 'is_active' => true]);
        $this->reset('newName', 'newCode');
        $this->notice = __('transport.companies.saved');
    }

    public function toggleCompany(int $id): void
    {
        $c = TransportCompany::findOrFail($id);
        $c->update(['is_active' => ! $c->is_active]);
    }

    public function deleteCompany(int $id): void
    {
        TransportCompany::findOrFail($id)->delete();
        $this->notice = __('transport.companies.saved');
    }

    public function addPhone(int $companyId): void
    {
        $phone = trim((string) ($this->newPhone[$companyId]['phone'] ?? ''));
        $label = trim((string) ($this->newPhone[$companyId]['label'] ?? ''));
        $digits = TransportCompanyPhone::normalize($phone);
        if (! preg_match('/^[0-9\-\s]+$/', $phone) || strlen($digits) < 9 || strlen($digits) > 12) {
            $this->addError('newPhone.'.$companyId.'.phone', __('transport.companies.phone_invalid'));

            return;
        }
        TransportCompany::findOrFail($companyId)->phones()->create(['phone' => $digits, 'label' => mb_substr($label, 0, 30) ?: null, 'is_active' => true]);
        unset($this->newPhone[$companyId]);
        $this->notice = __('transport.companies.saved');
    }

    public function togglePhone(int $id): void
    {
        $p = TransportCompanyPhone::findOrFail($id);
        $p->update(['is_active' => ! $p->is_active]);
    }

    public function deletePhone(int $id): void
    {
        TransportCompanyPhone::findOrFail($id)->delete();
    }

    public function fmt(string $digits): string
    {
        return match (strlen($digits)) {
            11 => substr($digits, 0, 3).'-'.substr($digits, 3, 4).'-'.substr($digits, 7),
            10 => substr($digits, 0, 3).'-'.substr($digits, 3, 3).'-'.substr($digits, 6),
            default => $digits,
        };
    }
}; ?>

<div class="mx-auto max-w-3xl">
    <h1 class="mb-1 text-xl font-bold text-gray-800">🚚 {{ __('transport.companies.title') }}</h1>
    <p class="mb-4 text-[13px] text-gray-500">{{ __('transport.companies.intro') }}</p>

    @if ($notice)<div class="card-sm mb-3 border-green-200 bg-green-50 text-[13px] text-green-700">✓ {{ $notice }}</div>@endif

    <div class="card mb-4">
        <div class="section-title-sm">{{ __('transport.companies.add_company') }}</div>
        <div class="grid grid-cols-1 gap-2 sm:grid-cols-[1fr_1fr_auto]">
            <div><input wire:model="newName" class="input-base" maxlength="60" placeholder="{{ __('transport.companies.name') }}">@error('newName')<p class="text-xs text-red-600">{{ $message }}</p>@enderror</div>
            <div><input wire:model="newCode" class="input-base" maxlength="30" placeholder="{{ __('transport.companies.code') }}"></div>
            <button type="button" class="btn-primary" wire:click="addCompany">{{ __('transport.companies.add_company') }}</button>
        </div>
    </div>

    @forelse ($this->companies as $c)
        <div wire:key="tc-{{ $c->id }}" class="card mb-3 {{ $c->is_active ? '' : 'opacity-60' }}">
            <div class="mb-2 flex flex-wrap items-center gap-2">
                <span class="text-[15px] font-bold text-gray-800">{{ $c->name }}</span>
                @if ($c->code)<span class="text-xs text-gray-400">{{ $c->code }}</span>@endif
                <span class="badge {{ $c->is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-500' }}">{{ $c->is_active ? __('transport.companies.active') : __('transport.companies.inactive') }}</span>
                <span class="ml-auto flex gap-1">
                    <button type="button" class="btn-ghost btn-sm" wire:click="toggleCompany({{ $c->id }})">{{ $c->is_active ? __('transport.companies.inactive') : __('transport.companies.active') }}</button>
                    <button type="button" class="btn-ghost btn-sm text-red-600" wire:click="deleteCompany({{ $c->id }})" wire:confirm="{{ __('transport.companies.delete_confirm') }}">{{ __('transport.companies.delete') }}</button>
                </span>
            </div>
            <div class="section-title-sm">{{ __('transport.companies.phones') }}</div>
            @foreach ($c->phones as $p)
                <div wire:key="tp-{{ $p->id }}" class="flex items-center gap-2 border-b border-gray-50 py-1.5 text-[13px] {{ $p->is_active ? '' : 'text-gray-400 line-through' }}">
                    <span class="tabular-nums">{{ $this->fmt($p->phone) }}</span>
                    <span class="text-gray-500">{{ $p->label }}</span>
                    <span class="ml-auto flex gap-1">
                        <button type="button" class="btn-ghost btn-sm" wire:click="togglePhone({{ $p->id }})">{{ $p->is_active ? __('transport.companies.inactive') : __('transport.companies.active') }}</button>
                        <button type="button" class="btn-ghost btn-sm text-red-600" wire:click="deletePhone({{ $p->id }})">✕</button>
                    </span>
                </div>
            @endforeach
            <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-[1fr_1fr_auto]">
                <div><input wire:model="newPhone.{{ $c->id }}.phone" class="input-base" inputmode="tel" placeholder="{{ __('transport.companies.phone') }}">@error('newPhone.'.$c->id.'.phone')<p class="text-xs text-red-600">{{ $message }}</p>@enderror</div>
                <input wire:model="newPhone.{{ $c->id }}.label" class="input-base" maxlength="30" placeholder="{{ __('transport.companies.label') }}">
                <button type="button" class="btn-secondary btn-sm" wire:click="addPhone({{ $c->id }})">{{ __('transport.companies.add_phone') }}</button>
            </div>
        </div>
    @empty
        <div class="card py-8 text-center text-gray-400">{{ __('transport.companies.empty') }}</div>
    @endforelse
</div>

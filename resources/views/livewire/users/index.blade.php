<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public bool $showForm = false;
    public ?int $editingId = null;
    public string $name = '';
    public string $email = '';
    public string $role = 'sales';
    public bool $is_super = false;
    public bool $is_active = true;
    public ?string $car_erp_salesman_id = null;
    public string $car_erp_salesman_email = '';   // 연동 B 영업 매칭 오버라이드(car-erp 이메일이 로그인과 다를 때)
    public string $respond_agent_email = '';      // 연동 A 승격 라우팅(respond.io 상담원 이메일이 로그인과 다를 때)
    public string $password = '';

    #[Computed]
    public function users()
    {
        return User::orderByDesc('permission')->orderBy('role')->orderBy('name')->get();
    }

    public function roleLabel(string $r): string
    {
        return User::ROLE_LABELS[$r] ?? $r;
    }

    public function openCreate(): void
    {
        $this->reset(['editingId', 'name', 'email', 'password', 'is_super', 'car_erp_salesman_id', 'car_erp_salesman_email', 'respond_agent_email']);
        $this->role = 'sales';
        $this->is_active = true;
        $this->showForm = true;
        $this->resetErrorBag();
    }

    public function openEdit(int $id): void
    {
        $u = User::findOrFail($id);
        $this->editingId = $u->id;
        $this->name = $u->name;
        $this->email = $u->email;
        $this->role = $u->role;
        $this->is_super = $u->isSuper();
        $this->is_active = $u->is_active;
        $this->car_erp_salesman_id = $u->car_erp_salesman_id !== null ? (string) $u->car_erp_salesman_id : null;
        $this->car_erp_salesman_email = $u->car_erp_salesman_email ?? '';
        $this->respond_agent_email = $u->respond_agent_email ?? '';
        $this->password = '';
        $this->showForm = true;
        $this->resetErrorBag();
    }

    public function close(): void
    {
        $this->reset(['showForm', 'editingId', 'name', 'email', 'password', 'is_super', 'car_erp_salesman_id', 'car_erp_salesman_email', 'respond_agent_email']);
        $this->role = 'sales';
        $this->is_active = true;
    }

    public function save(): void
    {
        $rules = [
            'name' => 'required|string|max:50',
            'email' => ['required', 'email', 'max:100', Rule::unique('users', 'email')->ignore($this->editingId)],
            'role' => 'required|in:'.implode(',', User::ROLES),
            'car_erp_salesman_id' => 'nullable|integer|min:1',
            'car_erp_salesman_email' => 'nullable|email|max:100',
            'respond_agent_email' => 'nullable|email|max:100',
        ];
        if (! $this->editingId || filled($this->password)) {
            $rules['password'] = 'required|string|min:6';
        }
        $this->validate($rules);

        // 본인 계정 보호 — 자기 시스템관리자 권한 해제·비활성화로 자기 잠금 방지
        if ($this->editingId === Auth::id()) {
            if (! $this->is_super) {
                $this->addError('is_super', __('users.err_cannot_remove_own_super'));

                return;
            }
            $this->is_active = true;
        }

        $data = [
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'permission' => $this->is_super ? 'super' : 'user',
            'is_active' => $this->is_active,
            'car_erp_salesman_id' => ($this->car_erp_salesman_id === null || $this->car_erp_salesman_id === '') ? null : (int) $this->car_erp_salesman_id,
            'car_erp_salesman_email' => $this->car_erp_salesman_email ?: null,
            'respond_agent_email' => $this->respond_agent_email ?: null,
        ];
        if (filled($this->password)) {
            $data['password'] = $this->password; // 'hashed' cast
        }

        if ($this->editingId) {
            User::findOrFail($this->editingId)->update($data);
        } else {
            $data['email_verified_at'] = now();
            User::create($data);
        }

        unset($this->users);
        session()->flash('ok', __('users.saved'));
        $this->close();
    }

    public function toggleActive(int $id): void
    {
        if ($id === Auth::id()) {
            session()->flash('err', __('users.err_cannot_deactivate_self'));

            return;
        }
        $u = User::findOrFail($id);
        $u->is_active = ! $u->is_active;
        $u->save();
        unset($this->users);
    }
}; ?>

<div class="p-3 md:p-6">
    <div class="mb-4 flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-gray-800">{{ __('users.title') }}</h1>
            <p class="mt-0.5 text-xs text-gray-500">{{ __('users.subtitle') }}</p>
        </div>
        <button class="btn-primary" wire:click="openCreate">+ {{ __('users.add_user') }}</button>
    </div>

    @if (session('ok'))
        <div class="card-sm mb-3 border-green-200 bg-green-50 text-[13px] text-green-700">✓ {{ session('ok') }}</div>
    @endif
    @if (session('err'))
        <div class="card-sm mb-3 border-red-200 bg-red-50 text-[13px] text-red-700">⚠ {{ session('err') }}</div>
    @endif

    <div class="card">
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead>
                    <tr><th>{{ __('users.col_name') }}</th><th>{{ __('users.col_email') }}</th><th>{{ __('users.col_perm_role') }}</th><th>{{ __('users.col_car_erp_match') }}</th><th>{{ __('users.col_status') }}</th><th></th></tr>
                </thead>
                <tbody>
                    @foreach ($this->users as $u)
                        <tr>
                            <td class="font-semibold text-gray-800">
                                {{ $u->name }}
                                @if ($u->id === auth()->id())<span class="badge badge-purple ml-1">{{ __('users.me') }}</span>@endif
                            </td>
                            <td class="text-gray-600">{{ $u->email }}</td>
                            <td>
                                @if ($u->isSuper())<span class="badge badge-red">{{ __('nav.perm.super') }}</span> @endif
                                <span class="badge {{ $u->isManager() ? 'badge-purple' : 'badge-blue' }}">{{ $this->roleLabel($u->role) }}</span>
                            </td>
                            <td class="text-gray-500">{{ $u->car_erp_salesman_email ?? ($u->car_erp_salesman_id ? '#'.$u->car_erp_salesman_id : '—') }}</td>
                            <td>
                                @if ($u->is_active)
                                    <span class="badge badge-green">{{ __('users.status_active') }}</span>
                                @else
                                    <span class="badge badge-gray">{{ __('users.status_inactive') }}</span>
                                @endif
                            </td>
                            <td>
                                <div class="flex gap-2">
                                    <button class="btn-outline btn-sm" wire:click="openEdit({{ $u->id }})">✏️ {{ __('common.edit') }}</button>
                                    @if ($u->id !== auth()->id())
                                        <button class="btn-ghost btn-sm" wire:click="toggleActive({{ $u->id }})">{{ $u->is_active ? __('users.action_deactivate') : __('users.action_activate') }}</button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- 생성/수정 드로어 --}}
    @if ($showForm)
        <div class="fixed inset-0 z-40 bg-black/40" wire:click="close"></div>
        <div class="fixed inset-y-0 right-0 z-50 w-full overflow-y-auto bg-white shadow-xl sm:w-[420px]">
            <div class="flex items-center justify-between border-b border-gray-200 px-5 py-4">
                <h3 class="font-bold text-gray-800">{{ $editingId ? __('users.edit_user') : __('users.add_user') }}</h3>
                <button class="text-gray-400 hover:text-gray-600" wire:click="close">✕</button>
            </div>
            <div class="px-5 py-4">
                <label class="label-base">{{ __('users.label_name') }}</label>
                <input class="input-base" wire:model="name" placeholder="{{ __('users.ph_name') }}">
                @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                <label class="label-base mt-3">{{ __('users.label_email') }}</label>
                <input class="input-base" wire:model="email" type="email" placeholder="{{ __('users.ph_email') }}">
                @error('email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                <label class="label-base mt-3">{{ __('users.label_role') }}</label>
                <select class="input-base" wire:model.live="role">
                    <option value="sales">{{ __('nav.role.sales') }}</option>
                    <option value="inspection">{{ __('nav.role.inspection') }}</option>
                    <option value="auction">{{ __('nav.role.auction') }}</option>
                    <option value="manager">{{ __('nav.role.manager') }}</option>
                </select>
                @error('role') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                {{-- 영업만 car-erp 매핑 --}}
                @if ($role === 'sales')
                    <label class="label-base mt-3">{{ __('users.label_car_erp_email') }} <span class="text-xs font-normal text-gray-400">{{ __('users.optional_only_if_different') }}</span></label>
                    <input class="input-base" wire:model="car_erp_salesman_email" type="email" placeholder="{{ __('users.ph_car_erp_email') }}">
                    <p class="mt-1 text-xs text-gray-400">{!! __('users.hint_car_erp_email') !!}</p>
                    @error('car_erp_salesman_email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                    <label class="label-base mt-3">{{ __('users.label_respond_email') }} <span class="text-xs font-normal text-gray-400">{{ __('users.optional_only_if_different') }}</span></label>
                    <input class="input-base" wire:model="respond_agent_email" type="email" placeholder="{{ __('users.ph_respond_email') }}">
                    <p class="mt-1 text-xs text-gray-400">{!! __('users.hint_respond_email') !!}</p>
                    @error('respond_agent_email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                @endif

                <label class="label-base mt-3">{{ __('users.label_password') }} {{ $editingId ? __('users.label_password_edit_suffix') : '' }}</label>
                <input class="input-base" wire:model="password" type="password" placeholder="{{ $editingId ? __('users.ph_password_keep') : __('users.ph_password_new') }}">
                @error('password') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                <label class="mt-4 flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" wire:model="is_super"> <b>{{ __('users.super_checkbox') }}</b> {{ __('users.super_checkbox_desc') }}
                </label>
                @error('is_super') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                <label class="mt-2 flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" wire:model="is_active"> {{ __('users.active_checkbox') }}
                </label>

                <div class="mt-5 flex gap-2">
                    <button class="btn-primary flex-1 justify-center" wire:click="save">{{ __('common.save') }}</button>
                    <button class="btn-ghost" wire:click="close">{{ __('common.cancel') }}</button>
                </div>
            </div>
        </div>
    @endif
</div>

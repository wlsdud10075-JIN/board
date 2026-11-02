<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public const PER_PAGE_OPTIONS = [10, 30, 50, 100];

    public bool $showForm = false;
    public ?int $editingId = null;
    public string $name = '';
    public string $email = '';
    public string $phone = '';           // 알림톡 수신번호(검차 담당자 지역 검차 안내 등)
    public string $region = '';          // 검차원 담당 지역(고정 로스터) — 지역 검차 알림톡 수신 기준
    public string $role = 'sales';
    public bool $is_super = false;
    public bool $is_work_manager = false;   // 업무관리자 등급(permission=manager) — super 만 지정, 둘 다 체크면 super 우선
    public bool $is_active = true;
    public ?string $car_erp_salesman_id = null;
    public string $car_erp_salesman_email = '';   // 연동 B 영업 매칭 오버라이드(car-erp 이메일이 로그인과 다를 때)
    public string $respond_agent_email = '';      // 연동 A 승격 라우팅(respond.io 상담원 이메일이 로그인과 다를 때)
    public string $password = '';


    /** 검색어(이름·이메일·전화번호) — ERP 사용자관리와 같은 규칙: **버튼·Enter 로만** 돈다(타이핑마다 LIKE 왕복 안 함). */
    public string $search = '';

    /** 실제로 적용된 검색어 — 입력칸(`search`)과 분리해야 버튼을 누르기 전까지 목록이 안 바뀐다. */
    public string $appliedSearch = '';

    /**
     * 등급 → 하위 묶음 칩(ERP 사용자관리 미러, 2026-11-02 Jin). 「일반」 묶음 안에 역할 칩 — 누르면 등급도 같이 잡히고,
     * 같은 칩을 다시 누르면 해제. ⚠️ 메서드명을 프로퍼티와 같게 짓지 말 것(car-erp SKILLS §8 #32) — pickPerm/pickRole.
     */
    public string $permFilter = '';

    public string $roleFilter = '';

    /** ⚠️ `#[Url]` 이라 `?perPage=` 로 아무 값이나 들어온다 — 화이트리스트 밖이면 10 으로. */
    #[Url]
    public int $perPage = 10;

    /** 🚨 `search()` 로 짓지 말 것 — 프로퍼티와 이름이 겹쳐 버튼이 죽는다(car-erp SKILLS §8 #32). */
    public function searchNow(): void
    {
        $this->appliedSearch = trim($this->search);
        $this->resetPage();
        unset($this->users);
    }

    public function pickPerm(string $perm): void
    {
        if ($perm !== '' && ! in_array($perm, ['super', 'manager', 'user'], true)) {
            return;
        }
        $this->permFilter = ($this->permFilter === $perm && $this->roleFilter === '') ? '' : $perm;
        $this->roleFilter = '';
        $this->resetPage();
        unset($this->users);
    }

    public function pickRole(string $role): void
    {
        if (! array_key_exists($role, User::ROLE_LABELS)) {
            return;
        }
        $this->permFilter = 'user';
        $this->roleFilter = ($this->roleFilter === $role) ? '' : $role;
        $this->resetPage();
        unset($this->users);
    }

    public function updatedPerPage(): void
    {
        if (! in_array($this->perPage, self::PER_PAGE_OPTIONS, true)) {
            $this->perPage = 10;
        }
        $this->resetPage();
        unset($this->users);
    }

    #[Computed]
    public function users()
    {
        if (! in_array($this->perPage, self::PER_PAGE_OPTIONS, true)) {   // `?perPage=7` 직접 진입은 updated 훅을 안 탄다
            $this->perPage = 10;
        }
        $term = $this->appliedSearch;
        $digits = preg_replace('/\D/', '', $term);

        return User::query()
            ->when($term !== '', fn ($q) => $q->where(function ($q2) use ($term, $digits) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $q2->where('name', 'like', $like)->orWhere('email', 'like', $like)->orWhere('phone', 'like', $like);
                // 전화번호는 하이픈 유무가 섞여 있다 — 숫자만으로도 걸리게(ERP 와 같은 처리).
                if (strlen($digits) >= 3) {
                    $q2->orWhereRaw("REPLACE(phone, '-', '') like ?", ['%'.$digits.'%']);
                }
            }))
            ->when($this->permFilter !== '', fn ($q) => $q->where('permission', $this->permFilter))
            ->when($this->roleFilter !== '', fn ($q) => $q->where('permission', 'user')->where('role', $this->roleFilter))
            // 등급순(시스템관리자 → 업무관리자 → 일반) → 일반은 역할순 → 이름 → id(동점 tie-break — 페이지 사이 중복·누락 방지, ERP 와 같음).
            //   ⚠️ orderBy('permission') 은 MySQL ENUM 인덱스·sqlite 문자열로 순서가 갈린다 — CASE 로 고정.
            ->orderByRaw("CASE permission WHEN 'super' THEN 0 WHEN 'manager' THEN 1 ELSE 2 END")
            ->orderByRaw("CASE WHEN permission = 'user' THEN CASE role WHEN 'manager' THEN 0 WHEN 'sales' THEN 1 WHEN 'inspection' THEN 2 WHEN 'auction' THEN 3 ELSE 4 END ELSE 0 END")
            ->orderBy('name')->orderBy('id')
            ->paginate($this->perPage);
    }

    public function roleLabel(string $r): string
    {
        return User::ROLE_LABELS[$r] ?? $r;
    }

    /**
     * 이 화면은 관리 role 도 쓰지만(2026-08-04 Jin), 시스템관리자 경계는 그대로 지킨다.
     * 관리자는 super 지정·해제 불가 + super 계정 수정/비활성화 불가 — 아니면 관리가 스스로 super 가 되어
     * 기능설정·감사로그까지 열리고(권한상승), super 비밀번호 변경으로 계정 탈취도 된다.
     */
    public function actorIsSuper(): bool
    {
        return (bool) Auth::user()?->isSuper();
    }

    /**
     * 위 등급 계정은 아래 등급이 못 건드린다 — super 는 super 만, 업무관리자는 super·업무관리자만.
     * 아니면 관리 role 이 업무관리자 비밀번호를 바꿔 그 계정으로 감사로그를 연다(한 단계 아래의 같은 탈취 경로).
     */
    public function targetIsProtected(?User $target): bool
    {
        if ($target === null) {
            return false;
        }
        $actor = Auth::user();

        return ($target->isSuper() && ! $actor->isSuper())
            || ($target->isWorkManager() && ! $actor->hasFullAccess());
    }

    public function openCreate(): void
    {
        $this->reset(['editingId', 'name', 'email', 'phone', 'region', 'password', 'is_super', 'is_work_manager', 'car_erp_salesman_id', 'car_erp_salesman_email', 'respond_agent_email']);
        $this->role = 'sales';
        $this->is_active = true;
        $this->showForm = true;
        $this->resetErrorBag();
    }

    public function openEdit(int $id): void
    {
        $u = User::findOrFail($id);
        if ($this->targetIsProtected($u)) {
            session()->flash('err', __('users.err_super_target_forbidden'));

            return;
        }
        $this->editingId = $u->id;
        $this->name = $u->name;
        $this->email = $u->email;
        $this->phone = $u->phone ?? '';
        $this->region = $u->region ?? '';
        $this->role = $u->role;
        $this->is_super = $u->isSuper();
        $this->is_work_manager = $u->isWorkManager();
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
        $this->reset(['showForm', 'editingId', 'name', 'email', 'phone', 'region', 'password', 'is_super', 'is_work_manager', 'car_erp_salesman_id', 'car_erp_salesman_email', 'respond_agent_email']);
        $this->role = 'sales';
        $this->is_active = true;
    }

    public function save(): void
    {
        $rules = [
            'name' => 'required|string|max:50',
            'email' => ['required', 'email', 'max:100', Rule::unique('users', 'email')->ignore($this->editingId)],
            'phone' => 'nullable|string|max:20',
            'region' => 'nullable|string|max:60',
            'role' => 'required|in:'.implode(',', User::ROLES),
            'car_erp_salesman_id' => 'nullable|integer|min:1',
            'car_erp_salesman_email' => 'nullable|email|max:100',
            'respond_agent_email' => 'nullable|email|max:100',
        ];
        if (! $this->editingId || filled($this->password)) {
            $rules['password'] = 'required|string|min:6';
        }
        $this->validate($rules);

        $target = $this->editingId ? User::findOrFail($this->editingId) : null;

        // 시스템관리자 경계 — 관리자는 super 계정을 수정할 수 없고, super 지정도 못 한다.
        // is_super 는 public 프로퍼티라 화면에서 숨기는 것만으론 못 막는다. 최종 판정은 여기서.
        if (! $this->actorIsSuper()) {
            if ($this->targetIsProtected($target)) {
                session()->flash('err', __('users.err_super_target_forbidden'));

                return;
            }
            // 등급은 super 만 바꾼다. 🚨 재계산하면 관리 role 이 업무관리자 계정을 저장만 해도 user 로 조용히 내려간다
            //    → 기존 등급을 그대로 보존(신규는 user).
            $this->is_super = false;
            $this->is_work_manager = $target?->isWorkManager() ?? false;
        }

        // 본인 계정 보호 — 자기 권한을 내려 스스로 잠기는 것 방지
        if ($this->editingId === Auth::id()) {
            if ($this->actorIsSuper()) {
                if (! $this->is_super) {
                    $this->addError('is_super', __('users.err_cannot_remove_own_super'));

                    return;
                }
            } else {
                $this->role = $target->role;   // 관리자가 자기 역할을 바꿔 사용자관리를 잃는 것 방지
            }
            $this->is_active = true;
        }

        $data = [
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone ?: null,
            'region' => $this->role === 'inspection' ? ($this->region ?: null) : null,   // 지역은 검차원만
            'role' => $this->role,
            'permission' => $this->is_super ? 'super' : ($this->is_work_manager ? 'manager' : 'user'),
            'is_active' => $this->is_active,
            'car_erp_salesman_id' => ($this->car_erp_salesman_id === null || $this->car_erp_salesman_id === '') ? null : (int) $this->car_erp_salesman_id,
            'car_erp_salesman_email' => $this->car_erp_salesman_email ?: null,
            'respond_agent_email' => $this->respond_agent_email ?: null,
        ];
        if (filled($this->password)) {
            $data['password'] = $this->password; // 'hashed' cast
        }

        if ($target) {
            $target->update($data);
        } else {
            $data['email_verified_at'] = now();
            $target = User::create($data);
        }
        $this->detachStaleSharing($target);

        unset($this->users);
        session()->flash('ok', __('users.saved'));
        $this->close();
    }

    /**
     * 관리↔영업 공유 연결(`manager_salesman`, 영업이 /settings/profile 에서 찍는다 — 2026-11-02 Jin)은 role 이 바뀌면 뜻을 잃는다 → 해제.
     * 관리가 아니게 된 계정은 담당 영업 연결을, 영업이 아니게 된 계정은 공유한 관리 연결을 끊는다. 바뀐 게 있으면 감사 행(Jin 「모든 건 로그」).
     */
    private function detachStaleSharing(User $target): void
    {
        $gone = [];
        if (! $target->isManager() && ($n = $target->managedSalesmanUsers()->count()) > 0) {
            $gone['managed_salesmen'] = $n;
            $target->managedSalesmanUsers()->detach();
        }
        if (! $target->isSales() && ($n = $target->managingUsers()->count()) > 0) {
            $gone['shared_managers'] = $n;
            $target->managingUsers()->detach();
        }
        if ($gone !== []) {
            \App\Models\BoardAuditLog::create([
                'user_id' => Auth::id(), 'purchase_listing_id' => null, 'action' => 'user_shared_managers', 'field' => 'user_shared_managers',
                'old_value' => json_encode(['user' => $target->name, 'detached' => $gone], JSON_UNESCAPED_UNICODE),
                'new_value' => json_encode(['user' => $target->name, 'role' => $target->role, 'ids' => []], JSON_UNESCAPED_UNICODE),
            ]);
        }
    }

    public function toggleActive(int $id): void
    {
        if ($id === Auth::id()) {
            session()->flash('err', __('users.err_cannot_deactivate_self'));

            return;
        }
        $u = User::findOrFail($id);
        if ($this->targetIsProtected($u)) {
            session()->flash('err', __('users.err_super_target_forbidden'));

            return;
        }
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

    {{-- 검색·필터 — ERP 사용자관리와 같은 두 줄(2026-11-02 Jin): 1줄 = 검색칸 + [검색](버튼·Enter 로만), 2줄 = 등급 칩(클릭 즉시).
         「일반」 묶음 안에 역할 칩. 모바일은 검색칸 w-full, 칩은 줄바꿈. --}}
    @php
        $chipOn = 'rounded-full px-2.5 py-0.5 text-xs font-semibold bg-gray-800 text-white';
        $chipOff = 'rounded-full px-2.5 py-0.5 text-xs font-medium bg-gray-100 text-gray-600 hover:bg-gray-200';
        $subOn = 'rounded-full px-2 py-0.5 text-[11px] font-semibold bg-violet-600 text-white';
        $subOff = 'rounded-full px-2 py-0.5 text-[11px] font-medium bg-violet-50 text-violet-700 hover:bg-violet-100';
    @endphp
    <div class="card-sm mb-3 flex flex-col gap-2">
        <div class="flex flex-wrap items-center gap-2">
            <input wire:model="search" wire:keydown.enter="searchNow" type="search" class="input-filter w-full sm:w-72"
                   placeholder="{{ __('users.search_ph') }}">
            <button type="button" wire:click="searchNow" class="btn-primary btn-sm">{{ __('common.search') }}</button>
            <span class="ml-auto flex items-center gap-2">
                <span class="text-[12px] text-gray-500">{{ __('users.count', ['count' => $this->users->total()]) }}</span>
                <select wire:model.live="perPage" class="input-filter">
                    @foreach ([10, 30, 50, 100] as $n)
                        <option value="{{ $n }}">{{ __('users.per_page', ['count' => $n]) }}</option>
                    @endforeach
                </select>
            </span>
        </div>
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5">
            <button type="button" wire:click="pickPerm('')" class="{{ $permFilter === '' ? $chipOn : $chipOff }}">{{ __('users.filter_all') }}</button>
            <span class="flex flex-wrap items-center gap-1 rounded-lg bg-gray-50 px-1.5 py-1">
                <button type="button" wire:click="pickPerm('user')" class="{{ $permFilter === 'user' && $roleFilter === '' ? $chipOn : $chipOff }}">{{ __('users.filter_perm_user') }}</button>
                @foreach (\App\Models\User::ROLE_LABELS as $rk => $rl)
                    <button type="button" wire:click="pickRole('{{ $rk }}')" class="{{ $roleFilter === $rk ? $subOn : $subOff }}">{{ $this->roleLabel($rk) }}</button>
                @endforeach
            </span>
            <button type="button" wire:click="pickPerm('manager')" class="{{ $permFilter === 'manager' ? $chipOn : $chipOff }}">{{ __('users.filter_perm_manager') }}</button>
            <button type="button" wire:click="pickPerm('super')" class="{{ $permFilter === 'super' ? $chipOn : $chipOff }}">{{ __('users.filter_perm_super') }}</button>
        </div>
    </div>

    <div class="card">
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead>
                    <tr><th>{{ __('users.col_name') }}</th><th>{{ __('users.col_email') }}</th><th>{{ __('users.col_perm_role') }}</th><th>{{ __('users.col_car_erp_match') }}</th><th>{{ __('users.col_status') }}</th><th></th></tr>
                </thead>
                <tbody>
                    @forelse ($this->users as $u)
                        <tr>
                            <td class="font-semibold text-gray-800">
                                {{ $u->name }}
                                @if ($u->id === auth()->id())<span class="badge badge-purple ml-1">{{ __('users.me') }}</span>@endif
                            </td>
                            <td class="text-gray-600">{{ $u->email }}</td>
                            <td>
                                @if ($u->isSuper())<span class="badge badge-red">{{ __('nav.perm.super') }}</span> @elseif ($u->isWorkManager())<span class="badge badge-amber">{{ __('nav.perm.manager') }}</span> @endif
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
                                    {{-- 시스템관리자 계정은 시스템관리자만 손댄다 (서버 가드는 openEdit/save/toggleActive) --}}
                                    @if ($this->targetIsProtected($u))
                                        <span class="text-xs text-gray-400">{{ __('users.super_row_locked') }}</span>
                                    @else
                                        <button class="btn-outline btn-sm" wire:click="openEdit({{ $u->id }})">✏️ {{ __('common.edit') }}</button>
                                        @if ($u->id !== auth()->id())
                                            <button class="btn-ghost btn-sm" wire:click="toggleActive({{ $u->id }})">{{ $u->is_active ? __('users.action_deactivate') : __('users.action_activate') }}</button>
                                        @endif
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-8 text-center text-gray-400">{{ __('users.search_empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $this->users->links() }}</div>

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

                <label class="label-base mt-3">{{ __('users.label_phone') }}</label>
                <input class="input-base" wire:model="phone" type="tel" placeholder="{{ __('users.ph_phone') }}">
                @error('phone') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

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

                {{-- 검차원만 담당 지역(고정 로스터) — 지역 검차 알림톡 수신 기준 --}}
                @if ($role === 'inspection')
                    <label class="label-base mt-3">{{ __('users.label_region') }} <span class="text-xs font-normal text-gray-400">{{ __('users.region_hint') }}</span></label>
                    <x-region-input model="region" :placeholder="__('users.ph_region')" />
                    @error('region') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                @endif

                <label class="label-base mt-3">{{ __('users.label_password') }} {{ $editingId ? __('users.label_password_edit_suffix') : '' }}</label>
                <input class="input-base" wire:model="password" type="password" placeholder="{{ $editingId ? __('users.ph_password_keep') : __('users.ph_password_new') }}">
                @error('password') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                {{-- 시스템관리자 지정은 시스템관리자만 (관리 role 이 스스로 super 가 되는 권한상승 차단) --}}
                @if ($this->actorIsSuper())
                    <label class="mt-4 flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" wire:model="is_super"> <b>{{ __('users.super_checkbox') }}</b> {{ __('users.super_checkbox_desc') }}
                    </label>
                    @error('is_super') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    {{-- 업무관리자 등급(2026-10-14 Jin) — 지정도 super 만. 둘 다 체크면 super 로 저장된다. --}}
                    <label class="mt-2 flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" wire:model="is_work_manager"> <b>{{ __('users.work_manager_checkbox') }}</b> {{ __('users.work_manager_checkbox_desc') }}
                    </label>
                @endif

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

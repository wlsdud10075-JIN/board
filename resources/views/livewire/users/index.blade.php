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
                $this->addError('is_super', '본인의 시스템관리자 권한은 해제할 수 없습니다.');

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
        session()->flash('ok', '저장되었습니다.');
        $this->close();
    }

    public function toggleActive(int $id): void
    {
        if ($id === Auth::id()) {
            session()->flash('err', '본인 계정은 비활성화할 수 없습니다.');

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
            <h1 class="text-xl font-bold text-gray-800">사용자 관리</h1>
            <p class="mt-0.5 text-xs text-gray-500">시스템관리자(super) 전용. 계정 생성·역할·시스템관리자 지정·활성여부. 비활성 계정은 업무화면 접근이 차단됩니다.</p>
        </div>
        <button class="btn-primary" wire:click="openCreate">+ 사용자 추가</button>
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
                    <tr><th>이름</th><th>이메일</th><th>권한 / 역할</th><th>car-erp 영업 매칭</th><th>상태</th><th></th></tr>
                </thead>
                <tbody>
                    @foreach ($this->users as $u)
                        <tr>
                            <td class="font-semibold text-gray-800">
                                {{ $u->name }}
                                @if ($u->id === auth()->id())<span class="badge badge-purple ml-1">나</span>@endif
                            </td>
                            <td class="text-gray-600">{{ $u->email }}</td>
                            <td>
                                @if ($u->isSuper())<span class="badge badge-red">시스템관리자</span> @endif
                                <span class="badge {{ $u->isManager() ? 'badge-purple' : 'badge-blue' }}">{{ $this->roleLabel($u->role) }}</span>
                            </td>
                            <td class="text-gray-500">{{ $u->car_erp_salesman_email ?? ($u->car_erp_salesman_id ? '#'.$u->car_erp_salesman_id : '—') }}</td>
                            <td>
                                @if ($u->is_active)
                                    <span class="badge badge-green">활성</span>
                                @else
                                    <span class="badge badge-gray">비활성</span>
                                @endif
                            </td>
                            <td>
                                <div class="flex gap-2">
                                    <button class="btn-outline btn-sm" wire:click="openEdit({{ $u->id }})">✏️ 수정</button>
                                    @if ($u->id !== auth()->id())
                                        <button class="btn-ghost btn-sm" wire:click="toggleActive({{ $u->id }})">{{ $u->is_active ? '비활성화' : '활성화' }}</button>
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
                <h3 class="font-bold text-gray-800">{{ $editingId ? '사용자 수정' : '사용자 추가' }}</h3>
                <button class="text-gray-400 hover:text-gray-600" wire:click="close">✕</button>
            </div>
            <div class="px-5 py-4">
                <label class="label-base">이름</label>
                <input class="input-base" wire:model="name" placeholder="홍길동">
                @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                <label class="label-base mt-3">이메일 (로그인 ID)</label>
                <input class="input-base" wire:model="email" type="email" placeholder="user@board.test">
                @error('email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                <label class="label-base mt-3">역할</label>
                <select class="input-base" wire:model.live="role">
                    <option value="sales">영업</option>
                    <option value="inspection">현지확인</option>
                    <option value="auction">경매</option>
                    <option value="manager">관리</option>
                </select>
                @error('role') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                {{-- 영업만 car-erp 매핑 --}}
                @if ($role === 'sales')
                    <label class="label-base mt-3">car-erp 영업 이메일 <span class="text-xs font-normal text-gray-400">(선택 · 로그인과 다를 때만)</span></label>
                    <input class="input-base" wire:model="car_erp_salesman_email" type="email" placeholder="car-erp 영업담당자 이메일">
                    <p class="mt-1 text-xs text-gray-400">연동 B는 <b>이메일로 car-erp 영업담당자를 자동 매칭</b>합니다. <b>위 로그인 이메일 = car-erp 영업 이메일이면 비워두세요</b>(자동 매칭). 로그인 이메일이 다를 때만 여기에 car-erp 영업 이메일을 적으면 그걸로 매칭합니다.</p>
                    @error('car_erp_salesman_email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                    <label class="label-base mt-3">respond.io 상담원 이메일 <span class="text-xs font-normal text-gray-400">(선택 · 로그인과 다를 때만)</span></label>
                    <input class="input-base" wire:model="respond_agent_email" type="email" placeholder="respond.io 상담원 이메일">
                    <p class="mt-1 text-xs text-gray-400">연동 A <b>승격 대기</b>는 respond.io 대화 담당 상담원에게만 보입니다. <b>로그인 이메일 = respond.io 상담원 이메일이면 비워두세요</b>(자동 매칭). 다를 때만 여기에 적으면 그걸로 라우팅합니다.</p>
                    @error('respond_agent_email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                @endif

                <label class="label-base mt-3">비밀번호 {{ $editingId ? '(변경 시에만 입력)' : '' }}</label>
                <input class="input-base" wire:model="password" type="password" placeholder="{{ $editingId ? '비워두면 기존 유지' : '6자 이상' }}">
                @error('password') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                <label class="mt-4 flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" wire:model="is_super"> <b>시스템관리자</b> (전체 접근 + 사용자관리)
                </label>
                @error('is_super') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                <label class="mt-2 flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" wire:model="is_active"> 활성 계정 (로그인 허용)
                </label>

                <div class="mt-5 flex gap-2">
                    <button class="btn-primary flex-1 justify-center" wire:click="save">저장</button>
                    <button class="btn-ghost" wire:click="close">취소</button>
                </div>
            </div>
        </div>
    @endif
</div>

<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public string $name = '';
    public string $email = '';

    /**
     * 내 포털을 공유할 관리(role=manager) id 목록 — 영업 본인이 찍는다(2026-11-02 Jin 「ERP 처럼 영업이 본인 정보를 공유할 사람을 찍게」).
     * 저장 = `manager_salesman` pivot(car-erp 이름 그대로). 찍힌 관리는 /portal 에서 이 영업을 **정산 빼고** 보고 요청(계약금·잔금 등)도 대신 보낸다.
     * 업무관리자·super 는 지정 없이 전체를 보므로 후보에서 뺀다(permission=user 인 관리만).
     *
     * @var list<int>
     */
    public array $share_manager_ids = [];

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;
        $this->share_manager_ids = Auth::user()->isSales()
            ? Auth::user()->managingUsers()->pluck('users.id')->map(fn ($v) => (int) $v)->all()
            : [];
    }

    /** 공유 후보 = 활성 관리(role=manager, permission=user). */
    #[Computed]
    public function managerCandidates()
    {
        return User::where('role', 'manager')->where('permission', 'user')->where('is_active', true)->orderBy('name')->get(['id', 'name', 'email']);
    }

    /** 영업만 — 후보 밖 id 는 버리고 sync. 바뀌면 감사 행(Jin 「모든 건 로그」). */
    public function saveSharing(): void
    {
        $u = Auth::user();
        if (! $u->isSales()) {
            return;
        }
        $allowed = $this->managerCandidates->pluck('id')->map(fn ($v) => (int) $v)->all();
        $after = collect($this->share_manager_ids)->map(fn ($v) => (int) $v)->filter(fn ($id) => in_array($id, $allowed, true))->unique()->sort()->values()->all();
        $before = $u->managingUsers()->pluck('users.id')->map(fn ($v) => (int) $v)->sort()->values()->all();
        if ($after !== $before) {
            $u->managingUsers()->sync($after);
            \App\Models\BoardAuditLog::create([
                'user_id' => $u->id, 'purchase_listing_id' => null, 'action' => 'user_shared_managers', 'field' => 'user_shared_managers',
                'old_value' => json_encode(['user' => $u->name, 'ids' => $before], JSON_UNESCAPED_UNICODE),
                'new_value' => json_encode(['user' => $u->name, 'ids' => $after,
                    'names' => User::whereIn('id', $after)->orderBy('name')->pluck('name')->all()], JSON_UNESCAPED_UNICODE),
            ]);
        }
        $this->share_manager_ids = $after;
        $this->dispatch('sharing-updated');
    }

    /**
     * Update the profile information for the currently authenticated user.
     */
    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],

            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($user->id)
            ],
        ]);

        $user->fill($validated);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        $this->dispatch('profile-updated', name: $user->name);
    }

    /**
     * Send an email verification notification to the current user.
     */
    public function resendVerificationNotification(): void
    {
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false));

            return;
        }

        $user->sendEmailVerificationNotification();

        Session::flash('status', 'verification-link-sent');
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout :heading="__('settings.profile.heading')" :subheading="__('settings.profile.subheading')">
        <form wire:submit="updateProfileInformation" class="my-6 w-full space-y-6">
            <flux:input wire:model="name" :label="__('settings.profile.name')" type="text" name="name" required autofocus autocomplete="name" />

            <div>
                <flux:input wire:model="email" :label="__('settings.profile.email')" type="email" name="email" required autocomplete="email" />

                @if (auth()->user() instanceof \Illuminate\Contracts\Auth\MustVerifyEmail &&! auth()->user()->hasVerifiedEmail())
                    <div>
                        <p class="mt-2 text-sm text-gray-800">
                            {{ __('settings.profile.unverified') }}

                            <button
                                wire:click.prevent="resendVerificationNotification"
                                class="rounded-md text-sm text-gray-600 underline hover:text-gray-900 focus:outline-hidden focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                            >
                                {{ __('settings.profile.resend') }}
                            </button>
                        </p>

                        @if (session('status') === 'verification-link-sent')
                            <p class="mt-2 text-sm font-medium text-green-600">
                                {{ __('settings.profile.sent') }}
                            </p>
                        @endif
                    </div>
                @endif
            </div>

            <div class="flex items-center gap-4">
                <div class="flex items-center justify-end">
                    <flux:button variant="primary" type="submit" class="w-full">{{ __('settings.profile.save') }}</flux:button>
                </div>

                <x-action-message class="me-3" on="profile-updated">
                    {{ __('settings.profile.saved') }}
                </x-action-message>
            </div>
        </form>

        {{-- 내 포털 공유(영업만, 2026-11-02 Jin) — 찍은 관리가 /portal 에서 내 미수·재고·판매·선적을 보고 요청을 대신 보낼 수 있다. 정산은 안 보인다. --}}
        @if (auth()->user()->isSales())
            <div class="mt-8 border-t border-gray-200 pt-6">
                <h3 class="text-base font-semibold text-gray-800">{{ __('settings.sharing.heading') }}</h3>
                <p class="mt-1 text-sm text-gray-500">{{ __('settings.sharing.subheading') }}</p>
                <div class="mt-3 grid gap-1 rounded-md border border-gray-200 bg-gray-50 p-2 sm:grid-cols-2">
                    @forelse ($this->managerCandidates as $mc)
                        <label class="flex items-center gap-2 text-[13px] text-gray-700">
                            <input type="checkbox" wire:model="share_manager_ids" value="{{ $mc->id }}"> {{ $mc->name }} <span class="truncate text-[11px] text-gray-400">{{ $mc->email }}</span>
                        </label>
                    @empty
                        <span class="text-[12px] text-gray-400">{{ __('settings.sharing.none') }}</span>
                    @endforelse
                </div>
                <div class="mt-3 flex items-center gap-4">
                    <flux:button variant="primary" wire:click="saveSharing">{{ __('settings.sharing.save') }}</flux:button>
                    <x-action-message class="me-3" on="sharing-updated">{{ __('settings.sharing.saved') }}</x-action-message>
                </div>
            </div>
        @endif

        {{-- 계정 자가삭제 숨김 — board는 super가 /users 에서 is_active 로 계정 관리(자가 하드삭제는 매물·감사 연결 끊김).
             컴포넌트(delete-user-form)는 남겨둠 → 필요 시 이 줄만 되살리면 복구. --}}
        {{-- <livewire:settings.delete-user-form /> --}}
    </x-settings.layout>
</section>

<?php

use App\Models\Setting;
use App\Services\BizmAlimtalkService;
use Illuminate\Support\Facades\Crypt;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public string $sidebarBrand = '';

    public string $buyerCompanyName = '';

    public bool $localeEnEnabled = false;

    // 카카오 알림톡(BizM) — 발신프로필은 car-erp 와 공유. 코드=board_region_inspection.
    public string $alimtalkUserid = '';

    public string $alimtalkProfile = '';

    public string $alimtalkUserkey = '';       // 쓰기전용(잔액조회 전용) — 로드 시 미노출, 채우면 갱신.

    public bool $alimtalkEnabled = false;      // 마스터 on/off

    public string $alimtalkTmpl = '';          // board_region_inspection BizM 발급코드

    public string $alimtalkTmplForward = '';   // board_forward_ready BizM 발급코드

    public bool $alimtalkToggle = true;        // 지역검차 알림 개별 on/off

    public bool $alimtalkToggleForward = true; // 전달대기 알림 개별 on/off

    public string $alimtalkScheduleTime = '';  // 스케줄 사전알림 시각(HH:MM, Asia/Seoul). 비우면 스케줄 미발송.

    public string $alimtalkTestPhone = '';     // 테스트 발송 대상

    public ?string $alimtalkTestResult = null;

    public function mount(): void
    {
        if (! auth()->user()?->isSuper()) {
            abort(403);
        }
        $this->sidebarBrand = Setting::get('sidebar_brand', 'HeymanBoard') ?: 'HeymanBoard';
        $this->buyerCompanyName = Setting::get('buyer_company_name', 'SSANCAR') ?: 'SSANCAR';
        $this->localeEnEnabled = (bool) Setting::get('locale_en_enabled', false);

        $this->alimtalkUserid = (string) (Setting::get('alimtalk_userid', '') ?: '');
        $this->alimtalkProfile = (string) (Setting::get('alimtalk_profile', '') ?: '');
        $this->alimtalkEnabled = (bool) Setting::get('alimtalk_enabled', false);
        $this->alimtalkTmpl = (string) (Setting::get('alimtalk_tmpl_board_region_inspection', '') ?: '');
        $this->alimtalkToggle = (bool) Setting::get('alimtalk_toggle_board_region_inspection', true);
        $this->alimtalkTmplForward = (string) (Setting::get('alimtalk_tmpl_board_forward_ready', '') ?: '');
        $this->alimtalkToggleForward = (bool) Setting::get('alimtalk_toggle_board_forward_ready', true);
        $this->alimtalkScheduleTime = (string) (Setting::get('alimtalk_region_schedule_time', '') ?: '');
        // userkey 는 보안상 로드하지 않음(설정돼 있으면 placeholder 로만 안내).
    }

    public function saveAlimtalk(): void
    {
        if (! auth()->user()?->isSuper()) {
            abort(403);
        }

        $this->validate([
            'alimtalkUserid' => ['nullable', 'string', 'max:100'],
            'alimtalkProfile' => ['nullable', 'string', 'max:100'],
            'alimtalkUserkey' => ['nullable', 'string', 'max:200'],
            'alimtalkTmpl' => ['nullable', 'string', 'max:100'],
            'alimtalkTmplForward' => ['nullable', 'string', 'max:100'],
            'alimtalkScheduleTime' => ['nullable', 'date_format:H:i'],
        ]);

        $put = fn (string $key, string $value, string $type, string $desc) => Setting::updateOrCreate(
            ['key' => $key], ['value' => $value, 'type' => $type, 'description' => $desc],
        );

        $put('alimtalk_userid', trim($this->alimtalkUserid), 'string', 'BizM 알림톡 계정 아이디');
        $put('alimtalk_profile', trim($this->alimtalkProfile), 'string', 'BizM 발신프로필키(car-erp 공유)');
        $put('alimtalk_enabled', $this->alimtalkEnabled ? '1' : '0', 'boolean', '알림톡 마스터 on/off');
        $put('alimtalk_tmpl_board_region_inspection', trim($this->alimtalkTmpl), 'string', '지역 검차 안내 템플릿 코드');
        $put('alimtalk_toggle_board_region_inspection', $this->alimtalkToggle ? '1' : '0', 'boolean', '지역 검차 알림 개별 on/off');
        $put('alimtalk_tmpl_board_forward_ready', trim($this->alimtalkTmplForward), 'string', '전달 대기 안내 템플릿 코드');
        $put('alimtalk_toggle_board_forward_ready', $this->alimtalkToggleForward ? '1' : '0', 'boolean', '전달 대기 알림 개별 on/off');
        $put('alimtalk_region_schedule_time', trim($this->alimtalkScheduleTime), 'string', '지역 검차 사전알림 스케줄 시각(HH:MM KST)');

        // userkey 는 채웠을 때만 암호화 갱신(빈칸이면 기존 유지).
        if (filled($this->alimtalkUserkey)) {
            $put('alimtalk_userkey', Crypt::encryptString(trim($this->alimtalkUserkey)), 'string', 'BizM userkey(암호화, 잔액조회 전용)');
            $this->alimtalkUserkey = '';
        }

        $this->dispatch('alimtalk-saved');
    }

    public function sendTestAlimtalk(): void
    {
        if (! auth()->user()?->isSuper()) {
            abort(403);
        }
        $this->validate(['alimtalkTestPhone' => ['required', 'string', 'max:20']]);

        $log = BizmAlimtalkService::active()->sendTest($this->alimtalkTestPhone);
        $this->alimtalkTestResult = $log->status.($log->error ? ' — '.$log->error : '');
    }

    public function save(): void
    {
        if (! auth()->user()?->isSuper()) {
            abort(403);
        }

        $this->validate([
            'sidebarBrand' => ['required', 'string', 'max:12'],
            'buyerCompanyName' => ['required', 'string', 'max:30'],
        ], [], [
            'sidebarBrand' => __('settings.feature.brand_label'),
            'buyerCompanyName' => __('settings.feature.company_label'),
        ]);

        $brand = trim($this->sidebarBrand) ?: 'HeymanBoard';
        $company = trim($this->buyerCompanyName) ?: 'SSANCAR';

        Setting::updateOrCreate(
            ['key' => 'sidebar_brand'],
            [
                'value' => $brand,
                'type' => 'string',
                'description' => '사이드바·로그인 브랜드 텍스트 (최대 12자)',
            ],
        );

        Setting::updateOrCreate(
            ['key' => 'buyer_company_name'],
            [
                'value' => $company,
                'type' => 'string',
                'description' => '바이어 견적서·공개페이지 표시 회사명',
            ],
        );

        $this->sidebarBrand = $brand;
        $this->buyerCompanyName = $company;
        $this->dispatch('saved');
    }

    public function updatedLocaleEnEnabled(bool $value): void
    {
        if (! auth()->user()?->isSuper()) {
            abort(403);
        }

        Setting::updateOrCreate(
            ['key' => 'locale_en_enabled'],
            [
                'value' => $value ? '1' : '0',
                'type' => 'boolean',
                'description' => '영어 UI 활성화 (다국어)',
            ],
        );

        // 상단바 언어 스위처는 이 컴포넌트 밖(레이아웃)이라 풀 리로드로 즉시 반영.
        session()->flash('locale_toggle', $value
            ? __('settings.feature.locale_enabled_flash')
            : __('settings.feature.locale_disabled_flash'));

        $this->redirect(route('admin.settings'), navigate: false);
    }
}; ?>

<div class="p-4 sm:p-6">
    <div class="mb-5">
        <h1 class="text-lg font-semibold text-gray-800">{{ __('settings.feature.title') }}</h1>
        <p class="mt-1 text-sm text-gray-500">{{ __('settings.feature.subtitle') }}</p>
    </div>

    @if (session('locale_toggle'))
        <div class="mb-4 max-w-lg rounded-md border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-700">
            {{ session('locale_toggle') }}
        </div>
    @endif

    {{-- 브랜드 --}}
    <div class="card mb-4 max-w-lg">
        <div class="mb-3 border-b border-gray-100 pb-2">
            <span class="text-sm font-semibold text-gray-700">{{ __('settings.feature.brand_section') }}</span>
        </div>
        <form wire:submit="save" class="space-y-5">
            <flux:input
                wire:model="sidebarBrand"
                :label="__('settings.feature.brand_label')"
                :description="__('settings.feature.brand_hint')"
                maxlength="12"
                required
                autofocus
            />

            <flux:input
                wire:model="buyerCompanyName"
                :label="__('settings.feature.company_label')"
                :description="__('settings.feature.company_hint')"
                maxlength="30"
                required
            />

            <div class="flex items-center gap-3">
                <flux:button variant="primary" type="submit">{{ __('settings.feature.save') }}</flux:button>
                <x-action-message class="text-sm text-green-600" on="saved">{{ __('settings.feature.saved') }}</x-action-message>
            </div>
        </form>
    </div>

    {{-- 언어 --}}
    <div class="card mb-4 max-w-lg">
        <div class="mb-3 border-b border-gray-100 pb-2">
            <span class="text-sm font-semibold text-gray-700">{{ __('settings.feature.lang_section') }}</span>
        </div>
        <flux:switch
            wire:model.live="localeEnEnabled"
            :label="__('settings.feature.locale_label')"
            :description="__('settings.feature.locale_hint')"
        />
    </div>

    {{-- 카카오 알림톡(BizM) — 지역 검차 안내 --}}
    <div class="card max-w-lg">
        <div class="mb-3 border-b border-gray-100 pb-2">
            <span class="text-sm font-semibold text-gray-700">{{ __('settings.alimtalk.section') }}</span>
            <p class="mt-1 text-xs text-gray-400">{{ __('settings.alimtalk.hint') }}</p>
        </div>
        <form wire:submit="saveAlimtalk" class="space-y-5">
            <flux:switch wire:model="alimtalkEnabled" :label="__('settings.alimtalk.enabled_label')" :description="__('settings.alimtalk.enabled_hint')" />
            <flux:input wire:model="alimtalkUserid" :label="__('settings.alimtalk.userid_label')" :description="__('settings.alimtalk.shared_hint')" maxlength="100" />
            <flux:input wire:model="alimtalkProfile" :label="__('settings.alimtalk.profile_label')" :description="__('settings.alimtalk.shared_hint')" maxlength="100" />
            <flux:input wire:model="alimtalkTmpl" :label="__('settings.alimtalk.tmpl_label')" :description="__('settings.alimtalk.tmpl_hint')" maxlength="100" />
            <flux:switch wire:model="alimtalkToggle" :label="__('settings.alimtalk.toggle_label')" />
            <flux:input wire:model="alimtalkTmplForward" :label="__('settings.alimtalk.tmpl_forward_label')" :description="__('settings.alimtalk.tmpl_forward_hint')" maxlength="100" />
            <flux:switch wire:model="alimtalkToggleForward" :label="__('settings.alimtalk.toggle_forward_label')" />
            <flux:input wire:model="alimtalkScheduleTime" :label="__('settings.alimtalk.schedule_label')" :description="__('settings.alimtalk.schedule_hint')" type="time" />
            <flux:input wire:model="alimtalkUserkey" :label="__('settings.alimtalk.userkey_label')" :description="__('settings.alimtalk.userkey_hint')" type="password" maxlength="200" />

            <div class="flex items-center gap-3">
                <flux:button variant="primary" type="submit">{{ __('settings.feature.save') }}</flux:button>
                <x-action-message class="text-sm text-green-600" on="alimtalk-saved">{{ __('settings.feature.saved') }}</x-action-message>
            </div>
        </form>

        {{-- 테스트 발송 --}}
        <div class="mt-5 border-t border-gray-100 pt-4">
            <label class="label-base">{{ __('settings.alimtalk.test_label') }}</label>
            <div class="mt-1 flex items-center gap-2">
                <input class="input-base flex-1" wire:model="alimtalkTestPhone" type="tel" placeholder="010-1234-5678">
                <flux:button wire:click="sendTestAlimtalk" type="button">{{ __('settings.alimtalk.test_btn') }}</flux:button>
            </div>
            @error('alimtalkTestPhone') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            @if ($alimtalkTestResult)
                <p class="mt-2 text-xs {{ str_starts_with($alimtalkTestResult, 'sent') ? 'text-green-600' : 'text-amber-600' }}">
                    {{ __('settings.alimtalk.test_result') }}: {{ $alimtalkTestResult }}
                </p>
            @endif
        </div>
    </div>
</div>

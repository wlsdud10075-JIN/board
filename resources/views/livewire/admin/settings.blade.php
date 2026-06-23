<?php

use App\Models\Setting;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public string $sidebarBrand = '';

    public bool $localeEnEnabled = false;

    public function mount(): void
    {
        if (! auth()->user()?->isSuper()) {
            abort(403);
        }
        $this->sidebarBrand = Setting::get('sidebar_brand', 'HeymanBoard') ?: 'HeymanBoard';
        $this->localeEnEnabled = (bool) Setting::get('locale_en_enabled', false);
    }

    public function save(): void
    {
        if (! auth()->user()?->isSuper()) {
            abort(403);
        }

        $this->validate([
            'sidebarBrand' => ['required', 'string', 'max:12'],
        ], [], ['sidebarBrand' => __('settings.feature.brand_label')]);

        $brand = trim($this->sidebarBrand) ?: 'HeymanBoard';

        Setting::updateOrCreate(
            ['key' => 'sidebar_brand'],
            [
                'value' => $brand,
                'type' => 'string',
                'description' => '사이드바·로그인 브랜드 텍스트 (최대 12자)',
            ],
        );

        $this->sidebarBrand = $brand;
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

            <div class="flex items-center gap-3">
                <flux:button variant="primary" type="submit">{{ __('settings.feature.save') }}</flux:button>
                <x-action-message class="text-sm text-green-600" on="saved">{{ __('settings.feature.saved') }}</x-action-message>
            </div>
        </form>
    </div>

    {{-- 언어 --}}
    <div class="card max-w-lg">
        <div class="mb-3 border-b border-gray-100 pb-2">
            <span class="text-sm font-semibold text-gray-700">{{ __('settings.feature.lang_section') }}</span>
        </div>
        <flux:switch
            wire:model.live="localeEnEnabled"
            :label="__('settings.feature.locale_label')"
            :description="__('settings.feature.locale_hint')"
        />
    </div>
</div>

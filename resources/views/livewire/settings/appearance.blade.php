<?php

use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    //
}; ?>

<div class="flex flex-col items-start">
    @include('partials.settings-heading')

    <x-settings.layout :heading="__('settings.appearance.heading')" :subheading="__('settings.appearance.subheading')">
        <flux:radio.group x-data variant="segmented" x-model="$flux.appearance">
            <flux:radio value="light" icon="sun">{{ __('settings.appearance.light') }}</flux:radio>
            <flux:radio value="dark" icon="moon">{{ __('settings.appearance.dark') }}</flux:radio>
            <flux:radio value="system" icon="computer-desktop">{{ __('settings.appearance.system') }}</flux:radio>
        </flux:radio.group>
    </x-settings.layout>
</div>

<?php

use App\Services\Assistant\AssistantService;
use Livewire\Volt\Component;

/**
 * 사내 업무 도우미 위젯 — 플로팅 채팅 (A: 업무가이드 RAG 전용).
 * 레이아웃에 <livewire:assistant.widget /> 로 임베드. canUseAssistant 게이트.
 */
new class extends Component
{
    /** @var array<int,array{role:string,text:string,sources?:array}> */
    public array $messages = [];

    public string $q = '';

    public function send(): void
    {
        $user = auth()->user();
        abort_unless($user?->canUseAssistant(), 403);

        $q = trim($this->q);
        if ($q === '') {
            return;
        }
        $this->messages[] = ['role' => 'me', 'text' => $q];
        $this->q = '';

        $res = app(AssistantService::class)->ask($q, $user);
        $this->messages[] = [
            'role' => 'bot',
            'text' => $res['answer'],
            'sources' => collect($res['sources'] ?? [])->pluck('title')->all(),
        ];
        $this->dispatch('assistant-scroll');   // 새 답변 후 하단으로 스크롤
    }
}; ?>

{{-- 전달대기 토스트(notify.poll, bottom-4 right-4 z-[60])와 겹치지 않게 위로 띄운다. --}}
<div x-data="{ open: false, toBottom() { this.$nextTick(() => { const m = this.$refs.msgs; if (m) m.scrollTop = m.scrollHeight; }); } }"
     x-on:assistant-scroll.window="toBottom()"
     class="fixed bottom-20 right-4 z-50 print:hidden">
    {{-- 플로팅 버튼 --}}
    <button type="button" @click="open = !open; if (open) toBottom()"
            class="flex h-14 w-14 items-center justify-center rounded-full bg-primary text-white shadow-lg transition hover:bg-primary-hover"
            :aria-expanded="open" aria-label="{{ __('assistant.open') }}">
        <span x-show="!open" class="text-2xl">💬</span>
        <span x-show="open" class="text-2xl" x-cloak>×</span>
    </button>

    {{-- 채팅 패널 --}}
    <div x-show="open" x-cloak x-transition
         class="absolute bottom-16 right-0 flex h-[520px] max-h-[calc(100vh-8rem)] w-[360px] max-w-[calc(100vw-2rem)] flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-2xl">
        <div class="border-b border-gray-100 bg-gray-50 px-4 py-3">
            <p class="text-sm font-bold text-gray-800">{{ trim((string) \App\Models\Setting::get('sidebar_brand', 'HeymanBoard')) ?: 'HeymanBoard' }} {{ __('assistant.title') }}</p>
            <p class="text-[11px] text-gray-400">{{ __('assistant.hint') }}</p>
        </div>

        <div class="flex-1 space-y-3 overflow-y-auto p-4" x-ref="msgs">
            @forelse($messages as $m)
                @if($m['role'] === 'me')
                    <div class="ml-auto max-w-[85%] rounded-xl rounded-br-sm bg-primary px-3 py-2 text-sm text-white">{{ $m['text'] }}</div>
                @else
                    <div class="mr-auto max-w-[90%] whitespace-pre-wrap rounded-xl rounded-bl-sm bg-gray-100 px-3 py-2 text-sm text-gray-800">{{ $m['text'] }}</div>
                    @if(! empty($m['sources']))
                        <div class="mr-auto text-[11px] text-gray-400">📎 {{ implode(' · ', $m['sources']) }}</div>
                    @endif
                @endif
            @empty
                <div class="mr-auto max-w-[90%] rounded-xl rounded-bl-sm bg-gray-100 px-3 py-2 text-sm text-gray-600">
                    {{ __('assistant.greeting') }}
                    <span class="mt-1 block text-[11px] text-gray-400">{{ __('assistant.example') }}</span>
                </div>
            @endforelse
            <div wire:loading wire:target="send" class="mr-auto rounded-xl bg-gray-100 px-3 py-2 text-sm text-gray-400">⏳ {{ __('assistant.loading') }}</div>
        </div>

        <form wire:submit="send" class="flex gap-2 border-t border-gray-100 p-3">
            <input wire:model="q" type="text" placeholder="{{ __('assistant.placeholder') }}" autocomplete="off"
                   class="input-base flex-1 text-sm" wire:loading.attr="disabled" wire:target="send" />
            <button type="submit" class="btn-primary px-4 text-sm" wire:loading.attr="disabled" wire:target="send">{{ __('assistant.send') }}</button>
        </form>
    </div>
</div>

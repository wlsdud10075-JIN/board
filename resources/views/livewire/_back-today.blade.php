{{-- 「오늘 할 일」에서 온 화면이면 돌아갈 칩(2026-11-24). $from = 화면의 #[Url] from 값. --}}
@if (($from ?? '') === 'today')
    <a href="{{ route('today') }}" wire:navigate class="btn-outline btn-sm mb-2 inline-flex" data-back-today>{{ __('today.back') }}</a>
@endif

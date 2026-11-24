{{--
    매입 락 승인 요청 상자(2026-11-19 Jin, ERP §17) — 구매탭(/auction)과 매입예정 드로어(재고매입 차 바이어 지정, 2026-11-24)가 같이 쓴다.
    필요한 것 = 컴포넌트가 App\Livewire\Concerns\PurchaseLockRequests 를 쓴다($lockStatus·$lockNotice·$lockError·lockCount·lockReason, 동작 3개).
    🚫 복사해 두 벌로 만들지 말 것 — 한쪽만 고쳐지면 화면마다 다르게 동작한다.
--}}
@php
    $ls = $lockStatus; $lt = $ls['latest'] ?? null; $rem = (int) ($ls['remaining'] ?? 0);
    $fmt = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->timezone(config('app.timezone'))->format('m-d H:i') : '—';
@endphp
<div class="mt-2 rounded-md border border-gray-200 bg-white p-2 text-gray-700">
    <div class="flex flex-wrap items-center gap-1.5">
        <span class="font-semibold text-gray-800">{{ __('auction.lock_req_title') }}</span>
        @php $ltStatus = (string) ($lt['status'] ?? ''); @endphp
        @if ($rem > 0)
            <span class="badge badge-green">{{ __('auction.lock_req_approved', ['remaining' => $rem, 'until' => $fmt($ls['expires_at'] ?? null)]) }}</span>
        @endif
        @if ($ltStatus === 'pending')
            <span class="badge badge-amber">{{ __('auction.lock_req_pending', ['count' => $lt['requested_count'] ?? '—', 'at' => $fmt($lt['requested_at'] ?? null)]) }}</span>
        @elseif ($rem < 1 && $ltStatus === 'rejected')
            <span class="badge badge-red">{{ __('auction.lock_req_rejected') }}</span>
        @elseif ($rem < 1 && $ltStatus === 'exhausted')
            <span class="badge badge-gray">{{ __('auction.lock_req_exhausted') }}</span>
        @elseif ($rem < 1 && $ltStatus === 'expired')
            <span class="badge badge-gray">{{ __('auction.lock_req_expired') }}</span>
        @elseif ($ls !== null && ! $ls['ok'])
            <span class="badge badge-gray">{{ __('auction.lock_req_unknown') }}</span>
        @endif
        <button type="button" class="ml-auto text-[11px] text-[var(--color-primary-text)] hover:underline" wire:click="refreshLockStatus" wire:loading.attr="disabled" wire:target="refreshLockStatus">↻ {{ __('auction.lock_req_refresh') }}</button>
    </div>
    @if ($rem > 0)
        <p class="mt-1 text-gray-600">{{ __('auction.lock_req_approved_hint', ['approved' => $ls['approved'] ?? '—']) }}</p>
    @endif
    {{-- 대기 건이 있으면 새 요청은 못 보낸다(ERP 409). 그 외에는 대수가 남아 있어도 추가 요청 가능 — 승인되면 더해진다(Jin 2026-11-19). --}}
    @if ($ltStatus === 'pending')
        <p class="mt-1 text-gray-600">{{ __('auction.lock_req_pending_hint') }}</p>
        {{-- 대수를 잘못 적었으면 거두고 다시 요청한다(2026-11-19 Jin). --}}
        <button type="button" class="btn-outline btn-sm mt-1.5" wire:click="cancelLockRequest" wire:confirm="{{ __('auction.lock_req_cancel_confirm', ['count' => $lt['requested_count'] ?? '—']) }}"
                wire:loading.attr="disabled" wire:target="cancelLockRequest">{{ __('auction.lock_req_cancel_btn') }}</button>
    @else
        @if ($rem < 1 && $ltStatus === 'rejected' && ! empty($lt['note']))
            <p class="mt-1 text-gray-600">{{ __('auction.lock_req_rejected_reason', ['reason' => $lt['note']]) }}</p>
        @endif
        @if ($rem > 0)<p class="mt-1.5 text-[10px] text-gray-400">{{ __('auction.lock_req_more') }}</p>@endif
        <div class="mt-1.5 flex items-start gap-1.5">
            <div class="w-16 shrink-0">
                <input type="number" min="1" step="1" class="input-base px-2 text-center" wire:model="lockCount" title="{{ __('auction.lock_req_count') }}">
                <div class="mt-0.5 text-center text-[10px] text-gray-400">{{ __('auction.lock_req_count_unit') }}</div>
            </div>
            <input class="input-base min-w-0 flex-1" maxlength="500" wire:model="lockReason" placeholder="{{ __('auction.lock_req_reason_ph') }}">
            <button type="button" class="btn-primary btn-sm shrink-0 whitespace-nowrap" wire:click="requestLockApproval" wire:loading.attr="disabled" wire:target="requestLockApproval">{{ __('auction.lock_req_btn') }}</button>
        </div>
        @error('lockCount')<p class="mt-1 text-gray-800">{{ $message }}</p>@enderror
        @error('lockReason')<p class="mt-1 text-gray-800">{{ $message }}</p>@enderror
        <p class="mt-1 text-[10px] text-gray-400">{{ __('auction.lock_req_hint') }}</p>
    @endif
    @if ($lockNotice)<p class="mt-1 font-semibold text-green-700">✓ {{ $lockNotice }}</p>@endif
    @if ($lockError)<p class="mt-1 font-semibold text-gray-800">⚠️ {{ $lockError }}</p>@endif
</div>

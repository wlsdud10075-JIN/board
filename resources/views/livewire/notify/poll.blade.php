<?php

use App\Models\PurchaseListing;
use Livewire\Volt\Component;

/**
 * 전달 대기 인앱 알림 — 검차완료(inspected) 차가 새로 생기면 영업에게 소리+토스트.
 * SalesmanScope 가 영업은 본인 것만 집계. 폴링 30초(앱 열려있을 때). 모바일도 탭 열려있으면 울림.
 * (앱 닫혀도 울리는 백그라운드 푸시는 후속 슬라이스: PWA + 웹푸시 + VAPID.)
 */
new class extends Component
{
    public int $lastCount = 0;

    public function mount(): void
    {
        $this->lastCount = $this->forwardCount();
    }

    private function forwardCount(): int
    {
        return PurchaseListing::where('status', 'inspected')->count();
    }

    /** 폴링 — 직전보다 늘었으면(새 검차완료 도착) 브라우저 이벤트 발화. */
    public function check(): void
    {
        $c = $this->forwardCount();
        if ($c > $this->lastCount) {
            $this->dispatch('forward-arrived', msg: __('forwarding.notify', ['count' => $c - $this->lastCount]));
        }
        $this->lastCount = $c;
    }
}; ?>

<div wire:poll.30s="check"
     x-data="{ show: false, msg: '', _t: null }"
     @forward-arrived.window="
        msg = $event.detail.msg;
        show = true;
        if (window.__boardBeep) window.__boardBeep();
        clearTimeout(_t); _t = setTimeout(() => show = false, 6000);
     ">
    <div x-show="show" x-transition style="display:none"
         class="fixed bottom-4 right-4 z-[60] flex cursor-pointer items-center gap-2 rounded-lg bg-[var(--color-primary)] px-4 py-3 text-sm font-semibold text-white shadow-lg"
         @click="show = false">
        🔔 <span x-text="msg"></span>
    </div>
</div>

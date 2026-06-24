<?php

use App\Models\PurchaseListing;
use Livewire\Volt\Component;

/**
 * 인앱 알림 폴링 — ① 검차완료(inspected) 새 도착 = 보라 토스트 + 소리 ② car-erp 전송완료(synced) = 초록 토스트(무음).
 * SalesmanScope 가 영업은 본인 것만 집계. 폴링 30초(앱 열려있을 때). 모바일도 탭 열려있으면 울림.
 * (앱 닫혀도 울리는 백그라운드 푸시는 후속 슬라이스: PWA + 웹푸시 + VAPID.)
 */
new class extends Component
{
    public int $lastCount = 0;

    public int $lastSynced = 0;

    public function mount(): void
    {
        $this->lastCount = $this->forwardCount();
        $this->lastSynced = $this->syncedCount();
    }

    private function forwardCount(): int
    {
        return PurchaseListing::where('status', 'inspected')->count();
    }

    private function syncedCount(): int
    {
        return PurchaseListing::where('status', 'synced')->count();
    }

    /** 폴링 — 직전보다 늘었으면(검차완료 도착 / car-erp 전송완료) 브라우저 이벤트 발화. */
    public function check(): void
    {
        $c = $this->forwardCount();
        if ($c > $this->lastCount) {
            $this->dispatch('forward-arrived', msg: __('forwarding.notify', ['count' => $c - $this->lastCount]));
        }
        $this->lastCount = $c;

        $s = $this->syncedCount();
        if ($s > $this->lastSynced) {
            $this->dispatch('forward-arrived', type: 'synced', msg: __('forwarding.notify_synced', ['count' => $s - $this->lastSynced]));
        }
        $this->lastSynced = $s;
    }
}; ?>

<div wire:poll.30s="check"
     x-data="{ show: false, msg: '', type: 'inspect', _t: null }"
     @forward-arrived.window="
        msg = $event.detail.msg;
        type = $event.detail.type || 'inspect';
        show = true;
        if (type !== 'synced' && window.__boardBeep) window.__boardBeep();   // 전송완료(synced)는 무음
        clearTimeout(_t); _t = setTimeout(() => show = false, 6000);
     ">
    <div x-show="show" x-transition style="display:none"
         :class="type === 'synced' ? 'bg-green-600' : 'bg-[var(--color-primary)]'"
         class="fixed bottom-4 right-4 z-[60] flex cursor-pointer items-center gap-2 rounded-lg px-4 py-3 text-sm font-semibold text-white shadow-lg"
         @click="show = false">
        <span x-text="type === 'synced' ? '🚀' : '🔔'"></span> <span x-text="msg"></span>
    </div>
</div>

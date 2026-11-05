<?php

use App\Models\AlimtalkLog;
use App\Models\BoardAuditLog;
use App\Support\AlimtalkTemplates;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/**
 * 알림톡 로그(2026-11-04 Jin — 「알림톡 로그기록도 없네」, car-erp `/admin/alimtalk-logs` 미러). 시스템관리자 전용.
 * board 는 BizM 접수 결과(sent·failed·skipped)까지만 남긴다 — ERP 의 도달 확인(report_status 폴링)은 없다. sent = 「접수」.
 * 전화번호는 마스킹하지 않는다(ERP 와 같음 — super 전용 화면이 보호 수단). 모르는 사유·템플릿은 원문 그대로.
 */
new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public string $search = '';

    public string $appliedSearch = '';

    #[Url]
    public string $status = '';

    #[Url]
    public bool $attention = false;

    #[Url]
    public int $perPage = 30;

    public function mount(): void
    {
        abort_unless(Auth::user()?->isSuper(), 403);
        if (! in_array($this->perPage, [10, 30, 50, 100], true)) {
            $this->perPage = 30;
        }
        if (! in_array($this->status, ['', 'sent', 'failed', 'skipped'], true)) {
            $this->status = '';
        }
    }

    /** 🚨 `search()` 로 짓지 말 것 — 프로퍼티와 이름이 겹쳐 버튼이 죽는다. 검색은 버튼·Enter 로만. */
    public function searchNow(): void
    {
        $this->appliedSearch = trim($this->search);
        $this->resetPage();
    }

    public function updated($name): void
    {
        if (in_array($name, ['status', 'attention', 'perPage'], true)) {
            if (! in_array($this->perPage, [10, 30, 50, 100], true)) {
                $this->perPage = 30;
            }
            $this->resetPage();
        }
    }

    #[Computed]
    public function logs()
    {
        $term = $this->appliedSearch;

        return AlimtalkLog::with(['user', 'acknowledger'])
            ->when($term !== '', fn ($q) => $q->where(function ($q2) use ($term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $q2->where('phone', 'like', $like)->orWhere('template_code', 'like', $like)->orWhere('region', 'like', $like)
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', $like));
            }))
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when($this->attention, fn ($q) => $q->needsAttention())
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($this->perPage);
    }

    #[Computed]
    public function attentionCount(): int
    {
        return AlimtalkLog::needsAttention()->count();
    }

    public function acknowledge(int $id): void
    {
        abort_unless(Auth::user()?->isSuper(), 403);
        $log = AlimtalkLog::findOrFail($id);
        if ($log->acknowledged_at === null) {
            $log->update(['acknowledged_at' => now(), 'acknowledged_by' => Auth::id()]);
            $this->audit(1, [$log->id]);
        }
        unset($this->logs, $this->attentionCount);
    }

    public function acknowledgeAll(): void
    {
        abort_unless(Auth::user()?->isSuper(), 403);
        $ids = AlimtalkLog::needsAttention()->pluck('id')->all();
        if ($ids !== []) {
            AlimtalkLog::whereIn('id', $ids)->update(['acknowledged_at' => now(), 'acknowledged_by' => Auth::id()]);
            $this->audit(count($ids), $ids);
        }
        unset($this->logs, $this->attentionCount);
    }

    private function audit(int $n, array $ids): void
    {
        BoardAuditLog::create([
            'user_id' => Auth::id(), 'purchase_listing_id' => null, 'action' => 'alimtalk_ack', 'field' => 'alimtalk_ack',
            'old_value' => null, 'new_value' => json_encode(['count' => $n, 'ids' => array_slice($ids, 0, 50)]), 'ip_address' => BoardAuditLog::currentIp(),
        ]);
    }

    public function templateLabel(string $code): string
    {
        return AlimtalkTemplates::TEMPLATES[$code]['name'] ?? $code;
    }

    /** 사유 — 건너뜀 코드는 한글, BizM·HTTP 오류는 원문(진단 정보를 삼키지 않는다). */
    public function reasonLabel(?string $error): string
    {
        if ($error === null || $error === '') {
            return '';
        }
        $key = 'alimtalk_logs.reason.'.$error;

        return __($key) === $key ? $error : __($key);
    }
}; ?>

<div class="p-3 md:p-6" wire:poll.30s>
    <div class="mb-4 flex flex-wrap items-start justify-between gap-2">
        <div>
            <h1 class="text-xl font-bold text-gray-800">{{ __('alimtalk_logs.title') }}</h1>
            <p class="mt-0.5 text-xs text-gray-500">{{ __('alimtalk_logs.subtitle') }}</p>
        </div>
        @if ($this->attentionCount > 0)
            <button type="button" class="btn-outline btn-sm" wire:click="acknowledgeAll" wire:confirm="{{ __('alimtalk_logs.ack_all_confirm', ['n' => $this->attentionCount]) }}">{{ __('alimtalk_logs.ack_all', ['n' => $this->attentionCount]) }}</button>
        @endif
    </div>

    <div class="card-sm mb-3 flex flex-col gap-2">
        <div class="flex flex-wrap items-center gap-2">
            <input wire:model="search" wire:keydown.enter="searchNow" type="search" class="input-filter w-full sm:w-72" placeholder="{{ __('alimtalk_logs.search_ph') }}">
            <button type="button" wire:click="searchNow" class="btn-primary btn-sm">{{ __('common.search') }}</button>
            <select wire:model.live="status" class="input-filter">
                <option value="">{{ __('alimtalk_logs.status_all') }}</option>
                @foreach (['sent', 'failed', 'skipped'] as $s)
                    <option value="{{ $s }}">{{ __('alimtalk_logs.status.'.$s) }}</option>
                @endforeach
            </select>
            <label class="flex items-center gap-1.5 text-[13px] text-gray-700"><input type="checkbox" wire:model.live="attention"> {{ __('alimtalk_logs.attention_only') }} <span class="badge {{ $this->attentionCount ? 'badge-red' : 'badge-gray' }}">{{ $this->attentionCount }}</span></label>
            <span class="ml-auto flex items-center gap-2">
                <span class="text-[12px] text-gray-500">{{ __('alimtalk_logs.count', ['count' => $this->logs->total()]) }}</span>
                <select wire:model.live="perPage" class="input-filter">
                    @foreach ([10, 30, 50, 100] as $n)<option value="{{ $n }}">{{ __('alimtalk_logs.per_page', ['count' => $n]) }}</option>@endforeach
                </select>
            </span>
        </div>
    </div>

    @php $badge = ['sent' => 'badge-green', 'failed' => 'badge-red', 'skipped' => 'badge-gray']; @endphp
    <div class="card">
        {{-- PC 표 --}}
        <div class="hidden overflow-x-auto md:block">
            <table class="tbl text-[13px]">
                <thead><tr><th class="w-36">{{ __('alimtalk_logs.col_time') }}</th><th>{{ __('alimtalk_logs.col_template') }}</th><th>{{ __('alimtalk_logs.col_to') }}</th><th class="w-20">{{ __('alimtalk_logs.col_status') }}</th><th>{{ __('alimtalk_logs.col_detail') }}</th><th class="w-24"></th></tr></thead>
                <tbody>
                    @forelse ($this->logs as $log)
                        <tr wire:key="al-{{ $log->id }}">
                            <td class="whitespace-nowrap text-gray-600">{{ $log->created_at->format('m-d H:i:s') }}</td>
                            <td title="{{ $log->template_code }}">{{ $this->templateLabel($log->template_code) }}@if ($log->region)<div class="text-[11px] text-gray-400">{{ $log->region }}</div>@endif</td>
                            <td class="whitespace-nowrap">{{ $log->user?->name ?? '—' }}<div class="text-[11px] text-gray-500">{{ $log->phone }}</div></td>
                            <td><span class="badge {{ $badge[$log->status] ?? 'badge-gray' }}">{{ __('alimtalk_logs.status.'.$log->status) === 'alimtalk_logs.status.'.$log->status ? $log->status : __('alimtalk_logs.status.'.$log->status) }}</span></td>
                            <td class="max-w-md">
                                @if ($log->error)<div class="text-red-600" title="{{ $log->error }}">{{ \Illuminate\Support\Str::limit($this->reasonLabel($log->error), 80) }}</div>@endif
                                @if ($log->message)<div class="truncate text-gray-500" title="{{ $log->message }}">{{ \Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', $log->message), 40) }}</div>@endif
                            </td>
                            <td class="text-right">
                                @if ($log->status === 'failed')
                                    @if ($log->acknowledged_at)
                                        <span class="text-[11px] text-gray-400" title="{{ $log->acknowledger?->name }}">✓ {{ __('alimtalk_logs.acked') }}</span>
                                    @else
                                        <button type="button" class="btn-outline btn-sm" wire:click="acknowledge({{ $log->id }})">{{ __('alimtalk_logs.ack') }}</button>
                                    @endif
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-8 text-center text-gray-400">{{ __('alimtalk_logs.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{-- 모바일 카드 --}}
        <div class="md:hidden">
            @forelse ($this->logs as $log)
                <div class="border-b border-gray-100 py-2.5 text-[13px]" wire:key="alm-{{ $log->id }}">
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <span class="badge {{ $badge[$log->status] ?? 'badge-gray' }}">{{ __('alimtalk_logs.status.'.$log->status) === 'alimtalk_logs.status.'.$log->status ? $log->status : __('alimtalk_logs.status.'.$log->status) }}</span>
                        <b class="text-gray-800">{{ $this->templateLabel($log->template_code) }}</b>
                        <span class="ml-auto text-[12px] text-gray-500">{{ $log->created_at->format('m-d H:i') }}</span>
                    </div>
                    <div class="mt-0.5 text-gray-600">{{ $log->user?->name ?? '—' }} · {{ $log->phone }}@if ($log->region) · {{ $log->region }}@endif</div>
                    @if ($log->error)<div class="mt-0.5 break-words text-red-600">{{ $this->reasonLabel($log->error) }}</div>@endif
                    @if ($log->message)<div class="mt-0.5 break-words text-gray-500">{{ \Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', $log->message), 60) }}</div>@endif
                    @if ($log->status === 'failed')
                        <div class="mt-1 text-right">
                            @if ($log->acknowledged_at)<span class="text-[11px] text-gray-400">✓ {{ __('alimtalk_logs.acked') }}</span>
                            @else<button type="button" class="btn-outline btn-sm" wire:click="acknowledge({{ $log->id }})">{{ __('alimtalk_logs.ack') }}</button>@endif
                        </div>
                    @endif
                </div>
            @empty
                <p class="py-8 text-center text-sm text-gray-400">{{ __('alimtalk_logs.empty') }}</p>
            @endforelse
        </div>
    </div>
    <div class="mt-3">{{ $this->logs->links() }}</div>
</div>

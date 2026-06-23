<?php

use App\Models\BoardAuditLog;
use App\Models\IntegrationEvent;
use App\Models\PurchaseListing;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public function fieldLabel(?string $f): string
    {
        if ($f === null) {
            return '';
        }
        $key = 'audit.field.'.$f;
        $label = __($key);

        return $label === $key ? $f : $label;
    }

    /** 코드값(status/verdict/source)을 한글로 — 비개발자용 표시. */
    public function valueLabel(?string $field, ?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        return match ($field) {
            'status' => PurchaseListing::statusOptions()[$value] ?? $value,
            'buyer_verdict' => $value === 'none' ? __('common.none') : (__('domain.verdict.'.$value) === 'domain.verdict.'.$value ? $value : __('domain.verdict.'.$value)),
            'source' => __('domain.source.'.$value) === 'domain.source.'.$value ? $value : __('domain.source.'.$value),
            default => $value,
        };
    }

    #[Computed]
    public function logs()
    {
        return BoardAuditLog::with(['user', 'listing'])->latest('id')->paginate(25, pageName: 'logs');
    }

    #[Computed]
    public function events()
    {
        return IntegrationEvent::latest('id')->paginate(15, pageName: 'events');
    }
}; ?>

<div class="p-3 md:p-6">
    <div class="mb-4">
        <h1 class="text-xl font-bold text-gray-800">{{ __('audit.title') }} <span class="text-xs font-normal text-gray-400">· {{ __('audit.super_only') }}</span></h1>
        <p class="mt-0.5 text-xs text-gray-500">{{ __('audit.intro') }}</p>
    </div>

    {{-- 변경 감사로그 --}}
    <div class="card">
        <h2 class="mb-3 font-bold text-gray-800">{{ __('audit.change_history') }} <span class="text-gray-400">· board_audit_logs</span></h2>
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead>
                    <tr><th>{{ __('audit.col_time') }}</th><th>{{ __('audit.col_changer') }}</th><th>{{ __('audit.col_vehicle') }}</th><th>{{ __('audit.col_item') }}</th><th>{{ __('audit.col_change') }}</th></tr>
                </thead>
                <tbody>
                    @forelse ($this->logs as $log)
                        <tr>
                            <td class="whitespace-nowrap text-gray-400">{{ $log->created_at?->format('Y-m-d H:i') }}</td>
                            <td class="text-gray-700">{{ $log->user?->name ?? __('audit.system') }}</td>
                            <td class="text-gray-600">{{ $log->listing?->vehicle_number ?? '#'.$log->purchase_listing_id }}</td>
                            <td><span class="badge {{ $log->action === 'status_change' ? 'badge-purple' : 'badge-gray' }}">{{ $this->fieldLabel($log->field) }}</span></td>
                            <td class="text-gray-500">{{ $this->valueLabel($log->field, $log->old_value) ?? '∅' }} → <b class="text-gray-800">{{ $this->valueLabel($log->field, $log->new_value) ?? '∅' }}</b></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-8 text-center text-gray-400">{{ __('audit.no_changes') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $this->logs->links() }}</div>
    </div>

    {{-- car-erp 전송 로그 --}}
    <div class="card mt-4">
        <h2 class="mb-3 font-bold text-gray-800">{{ __('audit.transmission') }} <span class="text-gray-400">· integration_events</span></h2>
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead>
                    <tr><th>{{ __('audit.col_time') }}</th><th>{{ __('audit.col_direction_target') }}</th><th>{{ __('audit.col_event') }}</th><th>{{ __('audit.col_vehicle') }}</th><th>{{ __('audit.col_response') }}</th><th>{{ __('audit.col_content') }}</th></tr>
                </thead>
                <tbody>
                    @forelse ($this->events as $ev)
                        <tr>
                            <td class="whitespace-nowrap text-gray-400">{{ $ev->created_at?->format('Y-m-d H:i') }}</td>
                            <td class="text-gray-600">{{ $ev->direction }} / {{ $ev->target }}</td>
                            <td class="text-gray-700">{{ $ev->event_type }}</td>
                            <td class="text-gray-600">#{{ $ev->purchase_listing_id ?? '—' }}</td>
                            <td>
                                @php $ok = $ev->response_status >= 200 && $ev->response_status < 300; @endphp
                                <span class="badge {{ $ok ? 'badge-green' : 'badge-red' }}">{{ $ev->response_status ?? '—' }}</span>
                                @if ($ev->error)<span class="text-xs text-red-600">{{ \Illuminate\Support\Str::limit($ev->error, 40) }}</span>@endif
                            </td>
                            <td>
                                <details>
                                    <summary class="cursor-pointer text-xs text-gray-400">payload</summary>
                                    <pre class="mt-1 max-w-[420px] overflow-x-auto rounded bg-gray-50 p-2 text-[11px] text-gray-600">{{ json_encode($ev->request_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                </details>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-8 text-center text-gray-400">{{ __('audit.no_transmissions') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $this->events->links() }}</div>
    </div>
</div>

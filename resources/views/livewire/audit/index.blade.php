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
        return [
            'source' => '출처', 'status' => '상태', 'buyer_verdict' => '바이어회신', 'buyer_name' => '바이어',
            'expected_price' => '예상가', 'final_price' => '최종금액', 'car_cost' => '차값', 'discount_rate' => '할인율',
            'shipping_usd' => '배송비', 'owner_name' => '소유자', 'payee_name' => '예금주', 'payee_bank' => '은행',
            'payee_account' => '계좌', 'vehicle_number' => '차량번호', 'vin' => 'VIN', 'car_erp_vehicle_id' => 'car-erp차량',
            'region' => '지역', 'inspection_note' => '추가검사', 'inspection_memo' => '메모', 'c_no' => '매물번호',
            'encar_url' => '엔카URL', 'encar_dealer' => '엔카딜러', 'auction_venue' => '경매장', 'lot_number' => '출품번호',
        ][$f] ?? (string) $f;
    }

    /** 코드값(status/verdict/source)을 한글로 — 비개발자용 표시. */
    public function valueLabel(?string $field, ?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        return match ($field) {
            'status' => PurchaseListing::STATUS_LABELS[$value] ?? $value,
            'buyer_verdict' => ['none' => '없음', 'pending' => '회신대기', 'accepted' => '수락', 'rejected' => '거절'][$value] ?? $value,
            'source' => ['encar' => '엔카', 'auction' => '경매'][$value] ?? $value,
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
        <h1 class="text-xl font-bold text-gray-800">감사 로그 <span class="text-xs font-normal text-gray-400">· 시스템관리자 전용</span></h1>
        <p class="mt-0.5 text-xs text-gray-500">🔒 금액·상태·식별값 변경 이력 + car-erp 전송 기록(append-only). 감사·대조용 보존.</p>
    </div>

    {{-- 변경 감사로그 --}}
    <div class="card">
        <h2 class="mb-3 font-bold text-gray-800">변경 이력 <span class="text-gray-400">· board_audit_logs</span></h2>
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead>
                    <tr><th>시각</th><th>변경자</th><th>차량</th><th>항목</th><th>변경</th></tr>
                </thead>
                <tbody>
                    @forelse ($this->logs as $log)
                        <tr>
                            <td class="whitespace-nowrap text-gray-400">{{ $log->created_at?->format('Y-m-d H:i') }}</td>
                            <td class="text-gray-700">{{ $log->user?->name ?? '시스템' }}</td>
                            <td class="text-gray-600">{{ $log->listing?->vehicle_number ?? '#'.$log->purchase_listing_id }}</td>
                            <td><span class="badge {{ $log->action === 'status_change' ? 'badge-purple' : 'badge-gray' }}">{{ $this->fieldLabel($log->field) }}</span></td>
                            <td class="text-gray-500">{{ $this->valueLabel($log->field, $log->old_value) ?? '∅' }} → <b class="text-gray-800">{{ $this->valueLabel($log->field, $log->new_value) ?? '∅' }}</b></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-8 text-center text-gray-400">기록된 변경이 없습니다.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $this->logs->links() }}</div>
    </div>

    {{-- car-erp 전송 로그 --}}
    <div class="card mt-4">
        <h2 class="mb-3 font-bold text-gray-800">car-erp 전송 <span class="text-gray-400">· integration_events</span></h2>
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead>
                    <tr><th>시각</th><th>방향/대상</th><th>이벤트</th><th>차량</th><th>응답</th><th>내용</th></tr>
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
                        <tr><td colspan="6" class="py-8 text-center text-gray-400">전송 기록이 없습니다.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $this->events->links() }}</div>
    </div>
</div>

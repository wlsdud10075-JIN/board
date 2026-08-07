{{--
    §11 전송 결과 — 성공한 척 하지 않는다(§11-4 항목 5).
    skipped 는 실패가 아니다(대개 already_open = 이미 보낸 것). created 와 나란히 보여준다.
    ⚠️ skipped 항목은 키가 다르다 — forbidden 은 vehicle_id, already_open 은 vehicle_number.
--}}
@if ($reqResult)
    <div class="mb-3 rounded-md border px-3 py-2 text-[13px] {{ isset($reqResult['error']) ? 'border-red-200 bg-red-50 text-red-700' : 'border-gray-200 bg-gray-50 text-gray-700' }}">
        <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
                @if (isset($reqResult['error']))
                    {{ $reqResult['error'] }}
                @else
                    @if ($reqResult['created'])
                        <div><b class="text-green-700">{{ __('portal.req_result_created') }}</b> · {{ implode(', ', $reqResult['created']) }}</div>
                    @endif
                    @forelse ($reqResult['skipped'] as $s)
                        @php
                            $who = data_get($s, 'vehicle_number') ?: ('#'.data_get($s, 'vehicle_id'));
                            $why = data_get($s, 'reason') === 'forbidden' ? __('portal.req_reason_forbidden') : __('portal.req_reason_already_open');
                        @endphp
                        <div class="text-gray-500">{{ __('portal.req_result_skipped') }} · {{ $who }} ({{ $why }})</div>
                    @empty
                        @if (! $reqResult['created'])
                            <div class="text-gray-500">—</div>
                        @endif
                    @endforelse
                @endif
            </div>
            <button type="button" wire:click="dismissReqResult" class="shrink-0 text-[11px] text-gray-400 underline">{{ __('portal.req_dismiss') }}</button>
        </div>
    </div>
@endif

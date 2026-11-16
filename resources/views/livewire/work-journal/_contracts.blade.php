{{-- 금일 계약 차량(자동) — 작성자 화면·결재 카드 공용. $c = {rows:[{buyer, plate, sale_price, currency}], count, by_currency:{EUR: 14000}} (WorkJournalService::contractsFor). 2026-11-16 Jin 엑셀 양식 --}}
@php $rows = (array) ($c['rows'] ?? []); @endphp
@if ($rows === [])
    <p class="text-[13px] text-gray-400">{{ __('journal.contracts_empty') }}</p>
@else
    <div class="space-y-0.5 text-[13px]">
        @foreach ($rows as $r)
            <div><b class="text-gray-800">{{ $r['buyer'] ?: '—' }}</b> : {{ $r['plate'] }} / {{ __('journal.contracts_price') }}
                {{ $r['sale_price'] !== null ? rtrim(rtrim(number_format((float) $r['sale_price'], 2), '0'), '.').' '.($r['currency'] ?? '') : '—' }}</div>
        @endforeach
        <div class="mt-1 border-t border-gray-100 pt-1 font-semibold text-gray-800">
            {{ __('journal.contracts_total', ['n' => (int) ($c['count'] ?? count($rows))]) }}
            @if (! empty($c['by_currency']))
                / {{ __('journal.contracts_price_total') }} {{ collect($c['by_currency'])->map(fn ($v, $cur) => rtrim(rtrim(number_format((float) $v, 2), '0'), '.').' '.$cur)->join(' · ') }}
            @endif
        </div>
    </div>
@endif

<?php

use App\Models\BoardAuditLog;
use App\Models\IntegrationEvent;
use App\Models\PurchaseListing;
use App\Models\User;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/**
 * 감사 로그 — 변경 이력(board_audit_logs) + car-erp 전송(integration_events).
 *
 * 필터는 car-erp 감사로그 화면과 같은 꼴(Jin 2026-10-13 「ERP 감사로그처럼 검색·필터」):
 *   검색 = 버튼·Enter 로만(타이핑마다 LIKE 왕복 금지) / 셀렉트·날짜는 즉시.
 *   두 표가 한 컴포넌트라 URL 키·페이지 이름을 표별로 나눈다(`q…`=변경이력, `e…`=전송).
 * 🚨 `resetPage()` 기본 이름은 `page` — 이 표들은 `logs`/`events` 라 이름을 넘겨야 한다.
 * 🚨 메서드를 `search()` 로 짓지 말 것 — 프로퍼티 `$search` 와 겹쳐 버튼이 죽는다.
 */
new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public const PER_PAGE = [25, 50, 100];

    // ── 변경 이력 필터 ─────────────────────────────────────────────
    #[Url(as: 'q')] public string $search = '';

    #[Url(as: 'u')] public int $userFilter = 0;

    #[Url(as: 'f')] public string $fieldFilter = '';

    #[Url(as: 'from')] public string $dateFrom = '';

    #[Url(as: 'to')] public string $dateTo = '';

    #[Url(as: 'per')] public int $perPage = 25;

    // ── car-erp 전송 필터 ──────────────────────────────────────────
    #[Url(as: 'eq')] public string $eventSearch = '';

    #[Url(as: 'et')] public string $eventType = '';

    #[Url(as: 'er')] public string $eventResult = '';

    #[Url(as: 'efrom')] public string $eventFrom = '';

    #[Url(as: 'eto')] public string $eventTo = '';

    #[Url(as: 'eper')] public int $eventPerPage = 15;

    private const LOG_FILTERS = ['userFilter', 'fieldFilter', 'dateFrom', 'dateTo', 'perPage'];

    private const EVENT_FILTERS = ['eventType', 'eventResult', 'eventFrom', 'eventTo', 'eventPerPage'];

    public function updated(string $prop): void
    {
        if (in_array($prop, self::LOG_FILTERS, true)) {
            $this->resetPage('logs');
        } elseif (in_array($prop, self::EVENT_FILTERS, true)) {
            $this->resetPage('events');
        }
    }

    /** 변경 이력 검색 실행(버튼·Enter). */
    public function searchNow(): void
    {
        $this->resetPage('logs');
    }

    /** 전송 로그 검색 실행(버튼·Enter). */
    public function searchEventsNow(): void
    {
        $this->resetPage('events');
    }

    public function resetLogFilters(): void
    {
        $this->reset('search', 'userFilter', 'fieldFilter', 'dateFrom', 'dateTo');
        $this->resetPage('logs');
    }

    public function resetEventFilters(): void
    {
        $this->reset('eventSearch', 'eventType', 'eventResult', 'eventFrom', 'eventTo');
        $this->resetPage('events');
    }

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

    /** 차량번호 LIKE → 매물 id 서브쿼리. 삭제된 차도 — 삭제 로그가 가리키는 건 지워진 차다. */
    private function listingIdsByVehicle(string $term)
    {
        return PurchaseListing::withTrashed()
            ->where('vehicle_number', 'like', '%'.$term.'%')
            ->select('id');
    }

    /** 날짜 범위 — `whereDate` 는 인덱스를 못 쓴다(무한 증가 표) → created_at 범위 조건. */
    private function applyDateRange($q, string $from, string $to)
    {
        return $q
            ->when($from !== '', fn ($q) => $q->where('created_at', '>=', $from.' 00:00:00'))
            ->when($to !== '', fn ($q) => $q->where('created_at', '<=', $to.' 23:59:59'));
    }

    #[Computed]
    public function logs()
    {
        $term = trim($this->search);

        // 챗봇 질의(assistant_query)는 매물 변경이 아니라 이 표(차량·항목·이전→새값)에 맞지 않고,
        // 건수가 많아 실제 변경이력을 밀어낸다. 기록은 남기되 여기선 제외한다.
        $q = BoardAuditLog::with(['user', 'listing'])
            ->where('action', '!=', 'assistant_query')
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w
                ->whereIn('purchase_listing_id', $this->listingIdsByVehicle($term))
                ->orWhereIn('user_id', User::where('name', 'like', '%'.$term.'%')->select('id'))
                ->orWhere('old_value', 'like', '%'.$term.'%')
                ->orWhere('new_value', 'like', '%'.$term.'%')))
            ->when($this->userFilter > 0, fn ($q) => $q->where('user_id', $this->userFilter))
            ->when($this->fieldFilter !== '', fn ($q) => $q->where('field', $this->fieldFilter));

        // 정렬 = 시각 → id. 과거 시각으로 채워 넣은 행(백필)이 id 순으로 맨 위에 뜨지 않게, 동점은 id 로 고정.
        return $this->applyDateRange($q, $this->dateFrom, $this->dateTo)
            ->latest('created_at')->orderByDesc('id')
            ->paginate(in_array($this->perPage, self::PER_PAGE, true) ? $this->perPage : 25, pageName: 'logs');
    }

    /** 변경자 후보 = 기록을 남긴 적 있는 사용자만(이름순). */
    #[Computed]
    public function changers()
    {
        return User::whereIn('id', BoardAuditLog::query()->whereNotNull('user_id')->select('user_id')->distinct())
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /** 항목 후보 = 실제 기록된 field 만, 라벨 기준 정렬. [field => 라벨] */
    #[Computed]
    public function fields(): array
    {
        $out = [];
        foreach (BoardAuditLog::query()->where('action', '!=', 'assistant_query')->whereNotNull('field')->distinct()->pluck('field') as $f) {
            $out[$f] = $this->fieldLabel($f);
        }
        asort($out, SORT_LOCALE_STRING);

        return $out;
    }

    #[Computed]
    public function events()
    {
        $term = trim($this->eventSearch);

        $q = IntegrationEvent::with('listing')
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w
                ->whereIn('purchase_listing_id', $this->listingIdsByVehicle($term))
                ->orWhere('event_type', 'like', '%'.$term.'%')
                ->orWhere('error', 'like', '%'.$term.'%')))
            ->when($this->eventType !== '', fn ($q) => $q->where('event_type', $this->eventType))
            // 성공 = 2xx. 실패 = 그 외(응답 없음 포함 — 보내다 죽은 것도 실패로 봐야 한다).
            ->when($this->eventResult === 'ok', fn ($q) => $q->whereBetween('response_status', [200, 299]))
            ->when($this->eventResult === 'fail', fn ($q) => $q->where(fn ($w) => $w
                ->whereNull('response_status')->orWhere('response_status', '<', 200)->orWhere('response_status', '>', 299)));

        return $this->applyDateRange($q, $this->eventFrom, $this->eventTo)
            ->latest('created_at')->orderByDesc('id')
            ->paginate(in_array($this->eventPerPage, [15, 50, 100], true) ? $this->eventPerPage : 15, pageName: 'events');
    }

    #[Computed]
    public function eventTypes(): array
    {
        return IntegrationEvent::query()->distinct()->orderBy('event_type')->pluck('event_type')->all();
    }
}; ?>

<div class="p-3 md:p-6">
    <div class="mb-4">
        <h1 class="text-xl font-bold text-gray-800">{{ __('audit.title') }} <span class="text-xs font-normal text-gray-400">· {{ __('audit.super_only') }}</span></h1>
        <p class="mt-0.5 text-xs text-gray-500">{{ __('audit.intro') }}</p>
    </div>

    {{-- 변경 감사로그 --}}
    <div class="card">
        <div class="mb-3 flex flex-wrap items-baseline gap-2">
            <h2 class="font-bold text-gray-800">{{ __('audit.change_history') }} <span class="text-gray-400">· board_audit_logs</span></h2>
            <span class="text-xs text-gray-400">{{ __('audit.total', ['count' => number_format($this->logs->total())]) }}</span>
        </div>

        {{-- 필터 — 검색은 버튼·Enter 로만, 나머지는 즉시 --}}
        <div class="mb-3 flex flex-wrap items-center gap-2">
            <input wire:model="search" wire:keydown.enter="searchNow" type="search" class="input-filter w-full sm:w-64"
                   placeholder="{{ __('audit.search_placeholder') }}">
            <button type="button" wire:click="searchNow" class="btn-primary btn-sm">{{ __('common.search') }}</button>
            <select wire:model.live="userFilter" class="input-filter">
                <option value="0">{{ __('audit.all_changers') }}</option>
                @foreach ($this->changers as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach
            </select>
            <select wire:model.live="fieldFilter" class="input-filter">
                <option value="">{{ __('audit.all_fields') }}</option>
                @foreach ($this->fields as $code => $label)<option value="{{ $code }}" title="{{ $code }}">{{ $label }}</option>@endforeach
            </select>
            <input wire:model.live.debounce.400ms="dateFrom" type="date" class="input-filter">
            <span class="text-sm text-gray-400">~</span>
            <input wire:model.live.debounce.400ms="dateTo" type="date" class="input-filter">
            <button type="button" wire:click="resetLogFilters" class="btn-ghost btn-sm">{{ __('common.reset') }}</button>
            <select wire:model.live="perPage" class="input-filter ml-auto">
                @foreach (self::PER_PAGE as $n)<option value="{{ $n }}">{{ __('audit.per_page', ['count' => $n]) }}</option>@endforeach
            </select>
        </div>

        <div class="overflow-x-auto">
            <table class="tbl">
                <thead>
                    <tr><th>{{ __('audit.col_time') }}</th><th>{{ __('audit.col_changer') }}</th><th>{{ __('audit.col_vehicle') }}</th><th>{{ __('audit.col_item') }}</th><th>{{ __('audit.col_change') }}</th></tr>
                </thead>
                <tbody>
                    @forelse ($this->logs as $log)
                        <tr>
                            <td class="whitespace-nowrap text-gray-400">{{ $log->created_at?->format('Y-m-d H:i') }}</td>
                            <td class="text-gray-700">{{ $log->user?->name ?? __('audit.system') }}@if ($log->ip_address)<div class="text-[10px] text-gray-400">{{ $log->ip_address }}</div>@endif</td>
                            <td class="text-gray-600">
                                {{ \App\Support\Plate::show($log->listing?->vehicle_number) ?? '#'.$log->purchase_listing_id }}
                                @if ($log->listing?->trashed())<span class="badge badge-gray">{{ __('audit.field.deleted') }}</span>@endif
                            </td>
                            <td>
                                @php $badge = match ($log->action) { 'status_change' => 'badge-purple', 'create', 'attachment_add' => 'badge-green', 'delete', 'attachment_delete' => 'badge-red', default => 'badge-gray' }; @endphp
                                <span class="badge {{ $badge }}" title="{{ $log->action }}">{{ $this->fieldLabel($log->field) }}</span>
                            </td>
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
        <div class="mb-3 flex flex-wrap items-baseline gap-2">
            <h2 class="font-bold text-gray-800">{{ __('audit.transmission') }}</h2>
            <span class="text-xs text-gray-400">{{ __('audit.total', ['count' => number_format($this->events->total())]) }}</span>
        </div>

        <div class="mb-3 flex flex-wrap items-center gap-2">
            <input wire:model="eventSearch" wire:keydown.enter="searchEventsNow" type="search" class="input-filter w-full sm:w-64"
                   placeholder="{{ __('audit.event_search_placeholder') }}">
            <button type="button" wire:click="searchEventsNow" class="btn-primary btn-sm">{{ __('common.search') }}</button>
            <select wire:model.live="eventType" class="input-filter">
                <option value="">{{ __('audit.all_events') }}</option>
                @foreach ($this->eventTypes as $t)<option value="{{ $t }}">{{ \App\Support\IntegrationEventView::event($t) }}</option>@endforeach
            </select>
            <select wire:model.live="eventResult" class="input-filter">
                <option value="">{{ __('audit.result_all') }}</option>
                <option value="ok">{{ __('audit.result_ok') }}</option>
                <option value="fail">{{ __('audit.result_fail') }}</option>
            </select>
            <input wire:model.live.debounce.400ms="eventFrom" type="date" class="input-filter">
            <span class="text-sm text-gray-400">~</span>
            <input wire:model.live.debounce.400ms="eventTo" type="date" class="input-filter">
            <button type="button" wire:click="resetEventFilters" class="btn-ghost btn-sm">{{ __('common.reset') }}</button>
            <select wire:model.live="eventPerPage" class="input-filter ml-auto">
                @foreach ([15, 50, 100] as $n)<option value="{{ $n }}">{{ __('audit.per_page', ['count' => $n]) }}</option>@endforeach
            </select>
        </div>

        <div class="overflow-x-auto">
            <table class="tbl">
                <thead>
                    <tr><th>{{ __('audit.col_time') }}</th><th>{{ __('audit.col_direction_target') }}</th><th>{{ __('audit.col_event') }}</th><th>{{ __('audit.col_vehicle') }}</th><th>{{ __('audit.col_response') }}</th><th>{{ __('audit.col_content') }}</th></tr>
                </thead>
                <tbody>
                    @forelse ($this->events as $ev)
                        <tr>
                            <td class="whitespace-nowrap text-gray-400">{{ $ev->created_at?->format('Y-m-d H:i') }}</td>
                            {{-- 사람 말 표시(2026-10-20 Jin) — 원래 값(outbound/car_erp·purchase_sync·201)은 툴팁으로만. --}}
                            <td class="text-gray-600" title="{{ $ev->direction }} / {{ $ev->target }}">{{ \App\Support\IntegrationEventView::direction($ev->direction) }} {{ \App\Support\IntegrationEventView::target($ev->target) }}</td>
                            <td class="text-gray-700" title="{{ $ev->event_type }}">{{ \App\Support\IntegrationEventView::event($ev->event_type) }}</td>
                            <td class="text-gray-600">
                                @if ($ev->listing)
                                    {{ \App\Support\Plate::show($ev->listing->vehicle_number) }} <span class="text-xs text-gray-400">#{{ $ev->purchase_listing_id }}</span>
                                @else
                                    {{ $ev->purchase_listing_id ? '#'.$ev->purchase_listing_id : '—' }}
                                @endif
                            </td>
                            <td>
                                @php [$respLabel, $ok] = \App\Support\IntegrationEventView::response($ev); @endphp
                                <span class="badge whitespace-nowrap {{ $ok === true ? 'badge-green' : ($ok === false ? 'badge-red' : 'badge-gray') }}"
                                      @if ($ev->response_status !== null) title="{{ __('audit.ev.code', ['code' => $ev->response_status]) }}" @endif>{{ $respLabel }}</span>
                            </td>
                            <td>
                                @php $sum = \App\Support\IntegrationEventView::summary($ev); $rawRows = \App\Support\IntegrationEventView::payloadRows($ev); @endphp
                                @if ($sum)<div class="text-[12px] text-gray-700">{{ implode(' · ', $sum) }}</div>@endif
                                {{-- 오류 원문은 응답 문구·요약과 겹치지 않을 때만(「HTTP 422」·회신 결과 코드는 이미 말로 나왔다). --}}
                                @if ($ev->error && ! preg_match('/^HTTP \d+$/', $ev->error) && $ev->error !== $ev->response_body)
                                    <div class="text-[11px] text-red-600">{{ \Illuminate\Support\Str::limit($ev->error, 80) }}</div>
                                @endif
                                @if ($rawRows)
                                    <details>
                                        <summary class="cursor-pointer text-xs text-gray-400">{{ __('audit.ev.raw') }}</summary>
                                        <dl class="mt-1 grid max-w-[460px] grid-cols-[auto_1fr] gap-x-3 gap-y-0.5 rounded bg-gray-50 p-2 text-[11px]">
                                            @foreach ($rawRows as [$k, $v])
                                                <dt class="whitespace-nowrap text-gray-400">{{ $k }}</dt><dd class="break-all text-gray-700">{{ $v }}</dd>
                                            @endforeach
                                        </dl>
                                    </details>
                                @endif
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

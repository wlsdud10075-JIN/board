<?php

use App\Models\User;
use App\Support\TodayList;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * 「오늘 할 일」(2026-11-24 Jin, (나)안) — 로그인 첫 화면. 줄 판정은 전부 `App\Support\TodayList`.
 * 줄 버튼 = 지금 쓰는 화면(전달 대기·바이어 회신·경매/구매·매입예정)을 그 차 상세 창이 열린 채로(`?open=&from=today`).
 * 이 화면엔 쓰기 동작이 없다 — 처리는 늘 원래 화면에서.
 * 관리 role·전체 접근 등급은 위에 팀 표(영업별 칸 건수, 누르면 아래 목록을 그 사람·그 칸으로 거름). 격리는 기본 스코프 그대로.
 */
new #[Layout('components.layouts.app')] class extends Component {
    #[Url(except: 'all')]
    public string $chip = 'all';

    /** 팀 표에서 누른 칸 — 영업 id · 칸 이름. 0/'' = 거르지 않음. */
    #[Url(except: 0)]
    public int $who = 0;

    #[Url(except: '')]
    public string $col = '';

    public function mount(): void
    {
        if (! in_array($this->chip, ['all', 'blocked', 'next'], true)) {
            $this->chip = 'all';
        }
        if (! in_array($this->col, TodayList::TEAM_COLUMNS, true)) {
            $this->col = '';
            $this->who = 0;
        }
    }

    public function showsTeam(): bool
    {
        $u = Auth::user();

        return $u->hasFullAccess() || $u->isManager();
    }

    #[Computed]
    public function all()
    {
        return TodayList::rows();
    }

    /** 영업 화면 = 전부(스코프가 본인만 준다). 관리자 화면 = 팀 표 숫자를 누르기 전엔 본인 등록분. */
    #[Computed]
    public function list()
    {
        $rows = $this->all;
        if ($this->showsTeam()) {
            $rows = $this->col !== ''
                ? $rows->where('owner_id', $this->who)->filter(fn ($r) => TodayList::teamColumn($r['kind']) === $this->col)
                : $rows->where('owner_id', Auth::id());
        }

        return $rows->values();
    }

    #[Computed]
    public function shown()
    {
        return match ($this->chip) {
            'blocked' => $this->list->where('blocked', true)->values(),
            'next' => $this->list->where('blocked', false)->values(),
            default => $this->list,
        };
    }

    /** 팀 표 — 영업별 칸 건수. 본인도 한 줄(관리 role 이 직접 등록한 차). */
    #[Computed]
    public function team(): array
    {
        $out = [];
        foreach ($this->all->groupBy('owner_id') as $owner => $rows) {
            $cells = array_fill_keys(TodayList::TEAM_COLUMNS, 0);
            foreach ($rows as $r) {
                $cells[TodayList::teamColumn($r['kind'])]++;
            }
            $out[] = ['id' => (int) $owner, 'name' => $rows->first()['owner_name'], 'cells' => $cells, 'blocked' => $rows->where('blocked', true)->count()];
        }
        usort($out, fn ($a, $b) => [$b['blocked'], array_sum($b['cells'])] <=> [$a['blocked'], array_sum($a['cells'])]);

        return $out;
    }

    #[Computed]
    public function personal(): array
    {
        return TodayList::personal(Auth::user());
    }

    public function pick(int $who, string $col): void
    {
        if (! in_array($col, TodayList::TEAM_COLUMNS, true) || ($this->who === $who && $this->col === $col)) {
            $this->who = 0;
            $this->col = '';

            return;
        }
        $this->who = $who;
        $this->col = $col;
    }

    public function clearPick(): void
    {
        $this->who = 0;
        $this->col = '';
    }

    public function setChip(string $chip): void
    {
        $this->chip = in_array($chip, ['all', 'blocked', 'next'], true) ? $chip : 'all';
    }

    public function sinceText(?\Illuminate\Support\Carbon $at): string
    {
        if (! $at) {
            return '';
        }
        $local = $at->copy()->timezone(config('app.timezone'));
        $days = (int) $local->copy()->startOfDay()->diffInDays(now()->timezone(config('app.timezone'))->startOfDay());

        return $days < 1
            ? __('today.since_today', ['at' => $local->format('H:i')])
            : __('today.since', ['at' => $local->format('m-d H:i'), 'days' => $days + 1]);
    }
}; ?>

@php
    $tone = [
        'red' => 'border-red-200 bg-red-50',
        'amber' => 'border-amber-200 bg-amber-50',
        'blue' => 'border-blue-200 bg-blue-50',
    ];
    $list = $this->list;
    $chipCount = ['all' => $list->count(), 'blocked' => $list->where('blocked', true)->count(), 'next' => $list->where('blocked', false)->count()];
    $team = $this->showsTeam() ? $this->team : [];
@endphp

<div class="p-3 md:p-6" data-today>
    <div class="mb-4">
        <h1 class="text-xl font-bold text-gray-800">{{ __('today.title') }}</h1>
        <p class="mt-0.5 text-xs text-gray-500">{{ __('today.subtitle', ['date' => now()->timezone(config('app.timezone'))->format('m-d'), 'name' => auth()->user()->name, 'count' => $chipCount['all']]) }}</p>
    </div>

    {{-- 팀 표 — 관리 role·전체 접근 등급(1-b) --}}
    @if ($this->showsTeam() && count($team) > 0)
        <div class="card mb-4 max-w-4xl" data-today-team>
            <div class="mb-2 flex flex-wrap items-baseline gap-2">
                <h2 class="font-bold text-gray-800">{{ __('today.team.title') }}</h2>
                <span class="text-xs text-gray-500">{{ __('today.team.sub', ['people' => count($team), 'blocked' => array_sum(array_column($team, 'blocked'))]) }}</span>
            </div>
            <div class="overflow-x-auto">
                <table class="tbl tbl-center">
                    <thead>
                        <tr>
                            <th style="text-align:left">{{ __('today.team.owner') }}</th>
                            @foreach (\App\Support\TodayList::TEAM_COLUMNS as $c)
                                <th>{{ __('today.team.col.'.$c) }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($team as $t)
                            <tr>
                                <td class="whitespace-nowrap font-semibold text-gray-800" style="text-align:left">{{ $t['name'] }}@if ($t['id'] === auth()->id()) <span class="text-xs font-normal text-gray-400">({{ __('today.team.mine') }})</span>@endif</td>
                                @foreach ($t['cells'] as $c => $n)
                                    <td>
                                        @if ($n > 0)
                                            <button type="button" wire:click="pick({{ $t['id'] }}, '{{ $c }}')"
                                                    class="min-w-[2rem] rounded-md px-2 py-0.5 text-[13px] font-bold {{ $who === $t['id'] && $col === $c ? 'bg-[var(--color-primary)] text-white' : (in_array($c, ['erp_fail', 'no_attach', 'insp_alert'], true) ? 'bg-red-50 text-red-700' : 'bg-amber-50 text-amber-700') }}">{{ $n }}</button>
                                        @else
                                            <span class="text-gray-300">0</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-2 text-[11px] text-gray-400">{{ __('today.team.hint') }}</p>
        </div>
    @endif

    <div class="card max-w-4xl">
        @if ($this->showsTeam())
            <div class="mb-2 flex flex-wrap items-center gap-2 text-[13px] font-semibold text-gray-700">
                @if ($col !== '')
                    @php $pickedName = collect($team)->firstWhere('id', $who)['name'] ?? '—'; @endphp
                    <span>▾ {{ __('today.team.filtered', ['name' => $pickedName, 'col' => __('today.team.col.'.$col)]) }}</span>
                    <button type="button" wire:click="clearPick" class="btn-outline btn-sm">{{ __('today.team.clear') }}</button>
                @else
                    <span>▾ {{ __('today.team.mine') }}</span>
                @endif
            </div>
        @endif

        <div class="mb-3 -mx-1 flex min-w-0 gap-1 overflow-x-auto whitespace-nowrap px-1 pb-1">
            @foreach (['all', 'blocked', 'next'] as $c)
                <button type="button" wire:click="setChip('{{ $c }}')"
                        class="shrink-0 rounded-md border px-2.5 py-1.5 text-[12px] font-semibold {{ $chip === $c ? 'border-[var(--color-primary)] bg-[var(--color-primary)] text-white' : 'border-gray-300 bg-white text-gray-600' }}">
                    {{ __('today.chip.'.$c) }} <span class="{{ $chip === $c ? 'text-white/80' : 'text-gray-400' }}">{{ $chipCount[$c] }}</span>
                </button>
            @endforeach
        </div>

        <div class="space-y-2">
            @forelse ($this->shown as $r)
                <div class="flex flex-col gap-2 rounded-lg border p-3 sm:flex-row sm:items-center {{ $tone[$r['tone']] ?? '' }}" data-today-row="{{ $r['kind'] }}">
                    <div class="flex min-w-0 flex-1 gap-2">
                        <div class="shrink-0 text-lg leading-6">{{ $r['icon'] }}</div>
                        <div class="min-w-0">
                            <div class="text-[13px] font-semibold text-gray-800">{{ $r['plate'] }} · {{ $r['title'] }}</div>
                            <div class="mt-0.5 text-xs text-gray-600">{{ $r['detail'] }}</div>
                            <div class="mt-0.5 text-[11px] text-gray-400">
                                {{ $this->sinceText($r['since']) }}@if ($this->showsTeam() && $r['owner_id'] !== auth()->id()) · {{ __('today.owner', ['name' => $r['owner_name']]) }}@endif
                            </div>
                        </div>
                    </div>
                    <a href="{{ $r['href'] }}" wire:navigate class="btn-primary btn-sm shrink-0 justify-center">{{ $r['btn'] }}</a>
                </div>
            @empty
                <p class="py-6 text-center text-sm text-gray-400">{{ __('today.empty') }}</p>
            @endforelse
        </div>

        @if ($this->personal !== [] && $col === '')
            <div class="mt-4 border-t border-gray-100 pt-3">
                <div class="mb-1.5 text-xs font-semibold text-gray-500">{{ __('today.personal.title') }}</div>
                <div class="space-y-1.5">
                    @foreach ($this->personal as $p)
                        <div class="flex items-center justify-between gap-2 rounded-md border border-gray-200 bg-white px-3 py-2 text-[13px]" data-today-personal="{{ $p['kind'] }}">
                            <span class="font-semibold text-gray-700">{{ __('today.personal.'.$p['kind'], ['count' => $p['count']]) }}</span>
                            <a href="{{ $p['href'] }}" wire:navigate class="btn-outline btn-sm">{{ __('today.personal.open') }}</a>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    <p class="mt-3 max-w-4xl text-[11px] text-gray-400">{{ __('today.note') }}</p>
</div>

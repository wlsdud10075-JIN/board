<?php

use App\Models\BoardAuditLog;
use App\Models\Setting;
use App\Support\AlimtalkConfig;
use App\Support\AlimtalkRecipients;
use App\Support\AlimtalkTemplates;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * 알림톡 안내(2026-11-23 Jin «ERP 알림톡 안내처럼 인원별로 체크해서 보내게», car-erp `/admin/alimtalk-catalog` 의 역할 피커 미러). 시스템관리자 전용.
 * 알림마다 「자동으로 받는 사람」(고정)과 「추가로 받을 사람」(역할째 또는 사람별 체크)을 보여 주고 저장한다.
 * 규칙·저장 형식 = App\Support\AlimtalkRecipients. 체크가 없으면 발송 대상은 종전 그대로다.
 */
new #[Layout('components.layouts.app')] class extends Component {
    /** 알림 => 토큰 배열(역할 키 · user:{id} · approval_line). 저장 전 편집 상태. */
    public array $roles = [];

    public ?string $savedNotice = null;

    private array $memberCache = [];

    public function mount(): void
    {
        abort_unless(Auth::user()?->isSuper(), 403);
        foreach (AlimtalkRecipients::PICKABLE as $notice) {
            $this->roles[$notice] = AlimtalkRecipients::selected($notice);
        }
    }

    #[Computed]
    public function config(): AlimtalkConfig
    {
        return AlimtalkConfig::active();
    }

    public function groupMembers(string $group): array
    {
        return $this->memberCache[$group] ??= AlimtalkRecipients::groupMembers($group);
    }

    public function tokens(string $notice): array
    {
        return array_values(array_filter(array_map('strval', $this->roles[$notice] ?? [])));
    }

    /** 'all'(역할째 — 나중에 그 역할에 들어온 사람도 받는다) / 'some'(고른 사람만) / 'none'. */
    public function groupState(string $notice, string $group): string
    {
        if (in_array($group, $this->tokens($notice), true)) {
            return 'all';
        }

        return $this->pickedIn($notice, $group) === [] ? 'none' : 'some';
    }

    public function pickedIn(string $notice, string $group): array
    {
        $ids = array_column($this->groupMembers($group), 'id');
        $picked = [];
        foreach ($this->tokens($notice) as $t) {
            $id = AlimtalkRecipients::userIdOf($t);
            if ($id !== null && in_array($id, $ids, true)) {
                $picked[] = $id;
            }
        }

        return $picked;
    }

    private function put(string $notice, array $tokens): void
    {
        abort_unless(Auth::user()?->isSuper(), 403);
        if (AlimtalkRecipients::isPickable($notice)) {
            $this->roles[$notice] = array_values(array_unique(array_filter($tokens)));
            $this->savedNotice = null;
        }
    }

    /** 역할째 켜기/끄기 — 켜면 그 역할의 개별 지정은 지운다(겹치면 뜻이 모호하다). */
    public function toggleGroup(string $notice, string $group): void
    {
        if (! isset(AlimtalkRecipients::GROUPS[$group])) {
            return;
        }
        $ids = array_column($this->groupMembers($group), 'id');
        $cur = $this->tokens($notice);
        $tokens = array_filter($cur, function ($t) use ($group, $ids) {
            $id = AlimtalkRecipients::userIdOf($t);

            return $t !== $group && ! ($id !== null && in_array($id, $ids, true));
        });
        if (! in_array($group, $cur, true)) {
            $tokens[] = $group;
        }
        $this->put($notice, $tokens);
    }

    /** 사람별 켜기/끄기 — 개별로 고르면 「역할 전체」는 해제한다. */
    public function toggleMember(string $notice, string $group, int $userId): void
    {
        if (! in_array($userId, array_column($this->groupMembers($group), 'id'), true)) {
            return;   // 그 역할에 없는 id 는 무시(클라이언트 주입 방어)
        }
        $token = 'user:'.$userId;
        $cur = $this->tokens($notice);
        $tokens = array_filter($cur, fn ($t) => $t !== $group && $t !== $token);
        if (! in_array($token, $cur, true)) {
            $tokens[] = $token;
        }
        $this->put($notice, $tokens);
    }

    public function toggleLine(string $notice): void
    {
        if (! AlimtalkRecipients::supportsLine($notice)) {
            return;
        }
        $cur = $this->tokens($notice);
        $line = AlimtalkRecipients::APPROVAL_LINE;
        $this->put($notice, in_array($line, $cur, true) ? array_filter($cur, fn ($t) => $t !== $line) : [...$cur, $line]);
    }

    /** 저장 — 못 쓰는 토큰은 버린다(남기면 「체크돼 있는데 아무에게도 안 가는」 상태가 된다). 바뀌었으면 감사 1행. */
    public function save(string $notice): void
    {
        abort_unless(Auth::user()?->isSuper(), 403);
        if (! AlimtalkRecipients::isPickable($notice)) {
            return;
        }
        $tokens = array_values(array_unique(array_filter($this->tokens($notice), fn ($t) => AlimtalkRecipients::isValidToken($notice, $t))));
        $old = implode(',', AlimtalkRecipients::selected($notice));
        $new = implode(',', $tokens);
        Setting::updateOrCreate(['key' => 'alimtalk_roles_'.$notice], ['value' => $new, 'type' => 'string', 'description' => '알림톡 추가 수신자 '.$notice]);
        if ($old !== $new) {
            BoardAuditLog::create([
                'user_id' => Auth::id(), 'purchase_listing_id' => null, 'action' => 'setting_change', 'field' => 'alimtalk_recipients',
                'old_value' => $notice.': '.($this->describe($old) ?: '—'), 'new_value' => $notice.': '.($this->describe($new) ?: '—'), 'ip_address' => BoardAuditLog::currentIp(),
            ]);
        }
        $this->roles[$notice] = $tokens;
        $this->savedNotice = $notice;
    }

    /** 감사 행용 — 토큰을 사람이 읽는 말로(역할 이름 · 사람 이름). */
    private function describe(string $csv): string
    {
        $names = [];
        foreach (array_filter(explode(',', $csv)) as $t) {
            if (isset(AlimtalkRecipients::GROUPS[$t])) {
                $names[] = __(AlimtalkRecipients::GROUPS[$t]);
            } elseif ($t === AlimtalkRecipients::APPROVAL_LINE) {
                $names[] = __('alimtalk_catalog.group.approval_line');
            } elseif (($id = AlimtalkRecipients::userIdOf($t)) !== null) {
                $names[] = \App\Models\User::query()->whereKey($id)->value('name') ?? $t;
            }
        }

        return implode(', ', $names);
    }

    public function isDirty(string $notice): bool
    {
        $a = $this->tokens($notice);
        $b = AlimtalkRecipients::selected($notice);
        sort($a);
        sort($b);

        return $a !== $b;
    }

    public function lineOn(string $notice): bool
    {
        return in_array(AlimtalkRecipients::APPROVAL_LINE, $this->tokens($notice), true);
    }

    /** 저장된 설정 기준 지금 추가로 받는 인원(번호 있는 사람만, 결재선 전원 제외 — 건마다 다르다). */
    public function extraCount(string $notice): int
    {
        return AlimtalkRecipients::countExtras($notice);
    }
}; ?>

<div class="p-3 md:p-6">
    <div class="mb-4">
        <h1 class="text-xl font-bold text-gray-800">{{ __('alimtalk_catalog.title') }}</h1>
        <p class="mt-1 text-sm text-gray-500">{{ __('alimtalk_catalog.intro') }}</p>
        @unless($this->config->enabled)
            <p class="mt-2 inline-block rounded bg-amber-50 px-2 py-1 text-xs text-amber-800">{{ __('alimtalk_catalog.master_off') }}</p>
        @endunless
    </div>

    <div class="flex flex-col gap-3">
        @foreach(AlimtalkRecipients::notices() as $notice)
            @php
                $tpl = AlimtalkRecipients::templateOf($notice);
                $pickable = AlimtalkRecipients::isPickable($notice);
                $auto = AlimtalkRecipients::AUTO[$notice] ?? null;
                $canSend = $this->config->canSend($tpl);
            @endphp
            <div class="rounded-lg border border-gray-200 bg-white p-3 md:p-4" wire:key="notice-{{ $notice }}" data-notice="{{ $notice }}" x-data="{ open: '', body: false }">
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="text-sm font-bold text-gray-800">{{ __('alimtalk_catalog.notice.'.$notice) }}</h2>
                    @if($canSend)
                        <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-medium text-emerald-700">{{ __('alimtalk_catalog.state_on') }}</span>
                    @else
                        <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-medium text-gray-500" title="{{ __('alimtalk_catalog.state_off_hint') }}">{{ __('alimtalk_catalog.state_off') }}</span>
                    @endif
                    <button type="button" class="ml-auto text-[11px] text-gray-400 underline hover:text-gray-700" x-on:click="body = ! body">{{ __('alimtalk_catalog.show_body') }}</button>
                </div>
                <p class="mt-1 text-xs text-gray-500">{{ __('alimtalk_catalog.when.'.$notice) }}</p>
                @if($tpl !== $notice)
                    <p class="mt-1 text-[11px] text-gray-400">{{ __('alimtalk_catalog.same_template', ['name' => __('alimtalk_catalog.notice.'.$tpl)]) }}</p>
                @endif
                <pre x-show="body" x-cloak class="mt-2 whitespace-pre-wrap rounded bg-gray-50 p-2 text-[11px] leading-relaxed text-gray-600">{{ AlimtalkTemplates::TEMPLATES[$tpl]['body'] ?? '' }}</pre>

                <div class="mt-3 grid gap-3 md:grid-cols-[14rem_1fr]">
                    <div>
                        <div class="mb-1 text-[11px] font-medium text-gray-500">{{ __('alimtalk_catalog.auto_label') }}</div>
                        <div class="rounded bg-gray-50 px-2 py-1.5 text-xs text-gray-700">{{ $auto ? __($auto) : __('alimtalk_catalog.auto.none') }}</div>
                    </div>

                    <div>
                        <div class="mb-1 text-[11px] font-medium text-gray-500">{{ __('alimtalk_catalog.extra_label') }}</div>
                        @if(! $pickable)
                            <div class="rounded bg-gray-50 px-2 py-1.5 text-xs text-gray-500">{{ __('alimtalk_catalog.not_pickable') }}</div>
                        @else
                            <div class="flex flex-col gap-0.5 rounded bg-gray-50 p-1">
                                @if(AlimtalkRecipients::supportsLine($notice))
                                    <div class="rounded bg-white px-2 py-1">
                                        <label class="flex items-center gap-1 pl-6 text-xs text-gray-700">
                                            <input type="checkbox" class="h-3.5 w-3.5 rounded border-gray-300" wire:click="toggleLine('{{ $notice }}')" @checked($this->lineOn($notice)) />
                                            <span class="font-medium">{{ __('alimtalk_catalog.group.approval_line') }}</span>
                                            <span class="text-[11px] text-gray-400">{{ __('alimtalk_catalog.line_hint') }}</span>
                                        </label>
                                    </div>
                                @endif
                                @foreach(AlimtalkRecipients::GROUPS as $g => $gKey)
                                    @php
                                        $st = $this->groupState($notice, $g);
                                        $members = $this->groupMembers($g);
                                        $picked = $this->pickedIn($notice, $g);
                                    @endphp
                                    <div class="rounded bg-white px-2 py-1" wire:key="grp-{{ $notice }}-{{ $g }}">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <button type="button" x-on:click="open = (open === '{{ $g }}' ? '' : '{{ $g }}')" class="w-4 text-xs text-gray-400 hover:text-gray-700" x-text="open === '{{ $g }}' ? '▾' : '▸'">▸</button>
                                            <label class="flex items-center gap-1 text-xs text-gray-700">
                                                <input type="checkbox" class="h-3.5 w-3.5 rounded border-gray-300" wire:click="toggleGroup('{{ $notice }}', '{{ $g }}')" @checked($st === 'all') />
                                                <span class="font-medium">{{ __($gKey) }}</span>
                                            </label>
                                            @if($st === 'all')
                                                <span class="text-[11px] text-emerald-700">{{ __('alimtalk_catalog.group_all', ['n' => count($members)]) }}</span>
                                            @elseif($st === 'some')
                                                <span class="text-[11px] text-amber-700">{{ __('alimtalk_catalog.group_some', ['n' => count($picked)]) }}</span>
                                            @else
                                                <span class="text-[11px] text-gray-300">{{ __('alimtalk_catalog.group_none', ['n' => count($members)]) }}</span>
                                            @endif
                                        </div>
                                        <div x-show="open === '{{ $g }}'" x-cloak class="mt-1 flex flex-wrap gap-x-4 gap-y-1 pl-6">
                                            @forelse($members as $m)
                                                <label wire:key="mem-{{ $notice }}-{{ $g }}-{{ $m['id'] }}" class="flex items-center gap-1 text-xs text-gray-600">
                                                    <input type="checkbox" class="h-3.5 w-3.5 rounded border-gray-300" wire:click="toggleMember('{{ $notice }}', '{{ $g }}', {{ $m['id'] }})" @checked(in_array($m['id'], $picked, true)) />
                                                    {{ $m['name'] }}
                                                    @if($m['phone'] === '')
                                                        <span class="rounded bg-amber-100 px-1 text-[11px] font-bold text-amber-800" title="{{ __('alimtalk_catalog.no_phone_hint') }}">⚠️ {{ __('alimtalk_catalog.no_phone') }}</span>
                                                    @else
                                                        <span class="text-[11px] text-gray-400">{{ $m['phone'] }}</span>
                                                    @endif
                                                </label>
                                            @empty
                                                <span class="text-[11px] text-gray-400">{{ __('alimtalk_catalog.group_empty') }}</span>
                                            @endforelse
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                <button type="button" wire:click="save('{{ $notice }}')" wire:loading.attr="disabled" wire:target="save('{{ $notice }}')"
                                        class="rounded bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-700 disabled:opacity-50">{{ __('alimtalk_catalog.save') }}</button>
                                @if($this->isDirty($notice))
                                    <span class="text-[11px] font-medium text-amber-700">{{ __('alimtalk_catalog.unsaved') }}</span>
                                @elseif($savedNotice === $notice)
                                    <span class="text-[11px] font-medium text-emerald-700">{{ __('alimtalk_catalog.saved') }}</span>
                                @endif
                                <span class="text-[11px] text-gray-500">{{ __('alimtalk_catalog.extra_count', ['n' => $this->extraCount($notice)]) }}</span>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <p class="mt-4 text-[11px] leading-relaxed text-gray-400">{{ __('alimtalk_catalog.footnote') }}</p>
</div>

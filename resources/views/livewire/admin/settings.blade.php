<?php

use App\Models\Setting;
use App\Services\BizmAlimtalkService;
use Illuminate\Support\Facades\Crypt;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public string $sidebarBrand = '';

    public string $buyerCompanyName = '';

    public bool $localeEnEnabled = false;

    public bool $assistantEnabled = false;   // 사내 업무 도우미(챗봇) 운영 on/off

    public string $journalCutoff = '19:00';   // 업무일지 상신 마감 시각(2026-11-04 Jin)

    // 카카오 알림톡(BizM) — 발신프로필은 car-erp 와 공유. 코드=board_region_inspection.
    public string $alimtalkUserid = '';

    public string $alimtalkProfile = '';

    public string $alimtalkUserkey = '';       // 쓰기전용(잔액조회 전용) — 로드 시 미노출, 채우면 갱신.

    public bool $alimtalkEnabled = false;      // 마스터 on/off

    public string $alimtalkTmpl = '';          // board_region_inspection BizM 발급코드

    public string $alimtalkTmplForward = '';   // board_forward_ready BizM 발급코드

    public bool $alimtalkToggle = true;        // 지역검차 알림 개별 on/off

    public bool $alimtalkToggleForward = true; // 전달대기 알림 개별 on/off

    public string $alimtalkScheduleTime = '';  // 스케줄 사전알림 시각(HH:MM, Asia/Seoul). 비우면 스케줄 미발송.

    public string $alimtalkTmplClaimReq = '';     // board_claim_approval_request BizM 발급코드(2026-11-10)

    public string $alimtalkTmplClaimDec = '';     // board_claim_decision

    public bool $alimtalkToggleClaimReq = false;   // BizM 승인 전 — 기본 OFF(2026-11-11 Jin)

    public bool $alimtalkToggleClaimDec = false;

    public string $alimtalkTmplTransportReq = '';     // board_transport_request (2026-11-12 탁송)

    public bool $alimtalkToggleTransportReq = false;   // BizM 승인 전 — 기본 OFF

    public string $alimtalkTmplTransportRes = '';     // board_transport_result

    public bool $alimtalkToggleTransportRes = false;

    /** 클레임 담당자(2026-11-10 Jin) — 지정된 사람만 「클레임」 탭을 본다(super 도 지정 필요). 여러 명 가능. */
    public array $claimHandlerIds = [];

    public string $alimtalkTestPhone = '';     // 테스트 발송 대상

    public ?string $alimtalkTestResult = null;

    public function mount(): void
    {
        if (! auth()->user()?->isSuper()) {
            abort(403);
        }
        $this->sidebarBrand = Setting::get('sidebar_brand', 'HeymanBoard') ?: 'HeymanBoard';
        $this->buyerCompanyName = Setting::get('buyer_company_name', 'SSANCAR') ?: 'SSANCAR';
        $this->localeEnEnabled = (bool) Setting::get('locale_en_enabled', false);
        $this->assistantEnabled = (bool) Setting::get('assistant_enabled', false);
        $this->journalCutoff = app(\App\Services\WorkJournalService::class)->cutoff();

        $this->alimtalkUserid = (string) (Setting::get('alimtalk_userid', '') ?: '');
        $this->alimtalkProfile = (string) (Setting::get('alimtalk_profile', '') ?: '');
        $this->alimtalkEnabled = (bool) Setting::get('alimtalk_enabled', false);
        $this->alimtalkTmpl = (string) (Setting::get('alimtalk_tmpl_board_region_inspection', '') ?: '');
        $this->alimtalkToggle = (bool) Setting::get('alimtalk_toggle_board_region_inspection', true);
        $this->alimtalkTmplForward = (string) (Setting::get('alimtalk_tmpl_board_forward_ready', '') ?: '');
        $this->alimtalkToggleForward = (bool) Setting::get('alimtalk_toggle_board_forward_ready', true);
        $this->alimtalkScheduleTime = (string) (Setting::get('alimtalk_region_schedule_time', '') ?: '');
        $this->alimtalkTmplClaimReq = (string) (Setting::get('alimtalk_tmpl_board_claim_approval_request', '') ?: '');
        $this->alimtalkToggleClaimReq = (bool) Setting::get('alimtalk_toggle_board_claim_approval_request', false);
        $this->alimtalkTmplClaimDec = (string) (Setting::get('alimtalk_tmpl_board_claim_decision', '') ?: '');
        $this->alimtalkToggleClaimDec = (bool) Setting::get('alimtalk_toggle_board_claim_decision', false);
        $this->alimtalkTmplTransportReq = (string) (Setting::get('alimtalk_tmpl_board_transport_request', '') ?: '');
        $this->alimtalkToggleTransportReq = (bool) Setting::get('alimtalk_toggle_board_transport_request', false);
        $this->alimtalkTmplTransportRes = (string) (Setting::get('alimtalk_tmpl_board_transport_result', '') ?: '');
        $this->alimtalkToggleTransportRes = (bool) Setting::get('alimtalk_toggle_board_transport_result', false);
        $this->claimHandlerIds = array_map('strval', \App\Models\User::claimHandlerIds());
        // userkey 는 보안상 로드하지 않음(설정돼 있으면 placeholder 로만 안내).
    }

    /** 클레임 담당자 지정(2026-11-10) — 감사 1행(이전 → 새 id 목록). */
    public function saveClaimHandlers(): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $this->claimHandlerIds))));
        $ids = \App\Models\User::whereIn('id', $ids)->pluck('id')->map(fn ($i) => (int) $i)->all();
        $old = implode(',', \App\Models\User::claimHandlerIds());
        $new = implode(',', $ids);
        Setting::updateOrCreate(['key' => 'claim_handler_user_ids'], ['value' => $new, 'type' => 'string', 'description' => '클레임 탭 담당자 user id 목록(쉼표)']);
        if ($old !== $new) {
            \App\Models\BoardAuditLog::create(['user_id' => auth()->id(), 'purchase_listing_id' => null, 'action' => 'setting_change', 'field' => 'claim_handlers',
                'old_value' => $old ?: null, 'new_value' => $new ?: null, 'ip_address' => \App\Models\BoardAuditLog::currentIp()]);
        }
        $this->claimHandlerIds = array_map('strval', $ids);
        $this->dispatch('claim-handlers-saved');
    }

    public function saveJournalCutoff(): void
    {
        if (! auth()->user()?->isSuper()) {
            abort(403);
        }
        $this->validate(['journalCutoff' => ['required', 'date_format:H:i']]);
        $old = app(\App\Services\WorkJournalService::class)->cutoff();
        Setting::updateOrCreate(['key' => 'work_journal_cutoff'], ['value' => $this->journalCutoff, 'type' => 'string', 'description' => '업무일지 상신 마감 시각(HH:MM KST)']);
        if ($old !== $this->journalCutoff) {
            \App\Models\BoardAuditLog::create(['user_id' => auth()->id(), 'purchase_listing_id' => null, 'action' => 'setting_change', 'field' => 'work_journal_cutoff',
                'old_value' => $old, 'new_value' => $this->journalCutoff, 'ip_address' => \App\Models\BoardAuditLog::currentIp()]);
        }
        $this->dispatch('journal-saved');
    }

    public function saveAlimtalk(): void
    {
        if (! auth()->user()?->isSuper()) {
            abort(403);
        }

        $this->validate([
            'alimtalkUserid' => ['nullable', 'string', 'max:100'],
            'alimtalkProfile' => ['nullable', 'string', 'max:100'],
            'alimtalkUserkey' => ['nullable', 'string', 'max:200'],
            'alimtalkTmpl' => ['nullable', 'string', 'max:100'],
            'alimtalkTmplForward' => ['nullable', 'string', 'max:100'],
            'alimtalkScheduleTime' => ['nullable', 'date_format:H:i'],
            'alimtalkTmplClaimReq' => ['nullable', 'string', 'max:100'],
            'alimtalkTmplClaimDec' => ['nullable', 'string', 'max:100'],
            'alimtalkTmplTransportReq' => ['nullable', 'string', 'max:100'],
            'alimtalkTmplTransportRes' => ['nullable', 'string', 'max:100'],
        ]);

        $put = fn (string $key, string $value, string $type, string $desc) => Setting::updateOrCreate(
            ['key' => $key], ['value' => $value, 'type' => $type, 'description' => $desc],
        );

        $put('alimtalk_userid', trim($this->alimtalkUserid), 'string', 'BizM 알림톡 계정 아이디');
        $put('alimtalk_profile', trim($this->alimtalkProfile), 'string', 'BizM 발신프로필키(car-erp 공유)');
        $put('alimtalk_enabled', $this->alimtalkEnabled ? '1' : '0', 'boolean', '알림톡 마스터 on/off');
        $put('alimtalk_tmpl_board_region_inspection', trim($this->alimtalkTmpl), 'string', '지역 검차 안내 템플릿 코드');
        $put('alimtalk_toggle_board_region_inspection', $this->alimtalkToggle ? '1' : '0', 'boolean', '지역 검차 알림 개별 on/off');
        $put('alimtalk_tmpl_board_forward_ready', trim($this->alimtalkTmplForward), 'string', '전달 대기 안내 템플릿 코드');
        $put('alimtalk_toggle_board_forward_ready', $this->alimtalkToggleForward ? '1' : '0', 'boolean', '전달 대기 알림 개별 on/off');
        $put('alimtalk_region_schedule_time', trim($this->alimtalkScheduleTime), 'string', '지역 검차 사전알림 스케줄 시각(HH:MM KST)');
        $put('alimtalk_tmpl_board_claim_approval_request', trim($this->alimtalkTmplClaimReq), 'string', '클레임 승인요청 템플릿 코드(대표)');
        $put('alimtalk_toggle_board_claim_approval_request', $this->alimtalkToggleClaimReq ? '1' : '0', 'boolean', '클레임 승인요청 알림 개별 on/off');
        $put('alimtalk_tmpl_board_claim_decision', trim($this->alimtalkTmplClaimDec), 'string', '클레임 결재 결과 템플릿 코드(담당자·영업)');
        $put('alimtalk_toggle_board_claim_decision', $this->alimtalkToggleClaimDec ? '1' : '0', 'boolean', '클레임 결재 결과 알림 개별 on/off');
        $put('alimtalk_tmpl_board_transport_request', trim($this->alimtalkTmplTransportReq), 'string', '탁송 배차 요청 템플릿 코드(업체·영업·관리)');
        $put('alimtalk_toggle_board_transport_request', $this->alimtalkToggleTransportReq ? '1' : '0', 'boolean', '탁송 배차 요청 알림 개별 on/off');
        $put('alimtalk_tmpl_board_transport_result', trim($this->alimtalkTmplTransportRes), 'string', '탁송 배차 결과 템플릿 코드(영업·관리)');
        $put('alimtalk_toggle_board_transport_result', $this->alimtalkToggleTransportRes ? '1' : '0', 'boolean', '탁송 배차 결과 알림 개별 on/off');

        // userkey 는 채웠을 때만 암호화 갱신(빈칸이면 기존 유지).
        if (filled($this->alimtalkUserkey)) {
            $put('alimtalk_userkey', Crypt::encryptString(trim($this->alimtalkUserkey)), 'string', 'BizM userkey(암호화, 잔액조회 전용)');
            $this->alimtalkUserkey = '';
        }

        $this->dispatch('alimtalk-saved');
    }

    public function sendTestAlimtalk(): void
    {
        if (! auth()->user()?->isSuper()) {
            abort(403);
        }
        $this->validate(['alimtalkTestPhone' => ['required', 'string', 'max:20']]);

        $log = BizmAlimtalkService::active()->sendTest($this->alimtalkTestPhone);
        $this->alimtalkTestResult = $log->status.($log->error ? ' — '.$log->error : '');
    }

    public function save(): void
    {
        if (! auth()->user()?->isSuper()) {
            abort(403);
        }

        $this->validate([
            'sidebarBrand' => ['required', 'string', 'max:12'],
            'buyerCompanyName' => ['required', 'string', 'max:30'],
        ], [], [
            'sidebarBrand' => __('settings.feature.brand_label'),
            'buyerCompanyName' => __('settings.feature.company_label'),
        ]);

        $brand = trim($this->sidebarBrand) ?: 'HeymanBoard';
        $company = trim($this->buyerCompanyName) ?: 'SSANCAR';

        Setting::updateOrCreate(
            ['key' => 'sidebar_brand'],
            [
                'value' => $brand,
                'type' => 'string',
                'description' => '사이드바·로그인 브랜드 텍스트 (최대 12자)',
            ],
        );

        Setting::updateOrCreate(
            ['key' => 'buyer_company_name'],
            [
                'value' => $company,
                'type' => 'string',
                'description' => '바이어 견적서·공개페이지 표시 회사명',
            ],
        );

        $this->sidebarBrand = $brand;
        $this->buyerCompanyName = $company;
        $this->dispatch('saved');
    }

    public function updatedLocaleEnEnabled(bool $value): void
    {
        if (! auth()->user()?->isSuper()) {
            abort(403);
        }

        Setting::updateOrCreate(
            ['key' => 'locale_en_enabled'],
            [
                'value' => $value ? '1' : '0',
                'type' => 'boolean',
                'description' => '영어 UI 활성화 (다국어)',
            ],
        );

        // 상단바 언어 스위처는 이 컴포넌트 밖(레이아웃)이라 풀 리로드로 즉시 반영.
        session()->flash('locale_toggle', $value
            ? __('settings.feature.locale_enabled_flash')
            : __('settings.feature.locale_disabled_flash'));

        $this->redirect(route('admin.settings'), navigate: false);
    }

    public function updatedAssistantEnabled(bool $value): void
    {
        if (! auth()->user()?->isSuper()) {
            abort(403);
        }

        Setting::updateOrCreate(
            ['key' => 'assistant_enabled'],
            [
                'value' => $value ? '1' : '0',
                'type' => 'boolean',
                'description' => '사내 업무 도우미(챗봇) 노출',
            ],
        );

        // 위젯은 이 컴포넌트 밖(레이아웃)이라 풀 리로드로 즉시 반영.
        $this->redirect(route('admin.settings'), navigate: false);
    }

    /** 색인 파일 상태 — scp 배포 실패를 super 가 화면에서 바로 확인. */
    public function assistantIndexStatus(): ?array
    {
        $path = (string) config('assistant.index_path');
        if ($path === '' || ! is_file($path)) {
            return null;
        }

        // 파싱하지 않는다(2MB+) — 크기·갱신시각만으로 배포 여부는 충분히 판정된다.
        return [
            'size' => round(filesize($path) / 1048576, 1),
            'when' => \Illuminate\Support\Carbon::createFromTimestamp(filemtime($path))->diffForHumans(),
        ];
    }
}; ?>

<div class="p-4 sm:p-6">
    <div class="mb-5">
        <h1 class="text-lg font-semibold text-gray-800">{{ __('settings.feature.title') }}</h1>
        <p class="mt-1 text-sm text-gray-500">{{ __('settings.feature.subtitle') }}</p>
    </div>

    @if (session('locale_toggle'))
        <div class="mb-4 max-w-lg rounded-md border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-700">
            {{ session('locale_toggle') }}
        </div>
    @endif

    {{-- 브랜드 --}}
    <div class="card mb-4 max-w-lg">
        <div class="mb-3 border-b border-gray-100 pb-2">
            <span class="text-sm font-semibold text-gray-700">{{ __('settings.feature.brand_section') }}</span>
        </div>
        <form wire:submit="save" class="space-y-5">
            <flux:input
                wire:model="sidebarBrand"
                :label="__('settings.feature.brand_label')"
                :description="__('settings.feature.brand_hint')"
                maxlength="12"
                required
                autofocus
            />

            <flux:input
                wire:model="buyerCompanyName"
                :label="__('settings.feature.company_label')"
                :description="__('settings.feature.company_hint')"
                maxlength="30"
                required
            />

            <div class="flex items-center gap-3">
                <flux:button variant="primary" type="submit">{{ __('settings.feature.save') }}</flux:button>
                <x-action-message class="text-sm text-green-600" on="saved">{{ __('settings.feature.saved') }}</x-action-message>
            </div>
        </form>
    </div>

    {{-- 언어 --}}
    <div class="card mb-4 max-w-lg">
        <div class="mb-3 border-b border-gray-100 pb-2">
            <span class="text-sm font-semibold text-gray-700">{{ __('settings.feature.lang_section') }}</span>
        </div>
        <flux:switch
            wire:model.live="localeEnEnabled"
            :label="__('settings.feature.locale_label')"
            :description="__('settings.feature.locale_hint')"
        />
    </div>

    {{-- 사내 업무 도우미(챗봇) --}}
    <div class="card mb-4 max-w-lg">
        <div class="mb-3 border-b border-gray-100 pb-2">
            <span class="text-sm font-semibold text-gray-700">{{ __('assistant.settings_title') }}</span>
            <p class="mt-1 text-xs text-gray-400">{{ __('assistant.settings_intro') }}</p>
        </div>
        <flux:switch wire:model.live="assistantEnabled" :label="__('assistant.settings_enabled')" />

        @php $idx = $this->assistantIndexStatus(); @endphp
        <p class="mt-3 text-xs {{ $idx ? 'text-gray-400' : 'text-amber-600' }}">
            {{ $idx ? __('assistant.settings_index_ok', $idx) : __('assistant.settings_index_missing') }}
        </p>
        @if (! config('assistant.enabled'))
            <p class="mt-1 text-xs text-amber-600">{{ __('assistant.settings_env_off') }}</p>
        @endif
    </div>

    {{-- 클레임 담당자(2026-11-10 Jin) — 지정된 사람만 「클레임」 탭(super 포함). 여러 명 가능. --}}
    <div class="card mb-4 max-w-lg">
        <div class="mb-3 border-b border-gray-100 pb-2">
            <span class="text-sm font-semibold text-gray-700">{{ __('claims.settings_title') }}</span>
            <p class="mt-1 text-xs text-gray-400">{{ __('claims.settings_hint') }}</p>
        </div>
        <form wire:submit="saveClaimHandlers" class="space-y-3">
            <div class="grid grid-cols-2 gap-1 text-sm">
                {{-- 이름이 같은 계정이 있어(시스템관리자 2개) 이메일을 같이 보인다 — 2026-11-11 Jin 이 다른 계정을 체크한 일. 본인은 「(나)」. --}}
                @foreach (\App\Models\User::where('is_active', true)->orderBy('name')->get(['id', 'name', 'email', 'role', 'permission']) as $u)
                    <label class="flex items-start gap-2 rounded px-1 py-0.5 hover:bg-gray-50">
                        <input type="checkbox" class="mt-1" value="{{ $u->id }}" wire:model="claimHandlerIds">
                        <span class="min-w-0">{{ $u->name }}@if ($u->id === auth()->id()) <b class="text-[11px] text-[var(--color-primary-text)]">{{ __('claims.settings_me') }}</b>@endif
                            <span class="text-[11px] text-gray-400">{{ $u->roleLabel() }}</span>
                            <span class="block truncate text-[11px] text-gray-400">{{ $u->email }}</span></span>
                    </label>
                @endforeach
            </div>
            <div class="flex items-center gap-3">
                <flux:button variant="primary" type="submit">{{ __('settings.feature.save') }}</flux:button>
                <x-action-message class="text-sm text-green-600" on="claim-handlers-saved">{{ __('settings.feature.saved') }}</x-action-message>
            </div>
            {{-- 결재선 후보 = 최고관리자 결재 직급(부장·상무·대표). 담당자가 요청할 때 골라 지정한다. --}}
            <p class="text-xs text-gray-500">{{ __('claims.settings_line') }}
                @foreach (\App\Models\Claim::lineCandidates() as $title => $u)
                    <span class="ml-1 whitespace-nowrap {{ $u && trim((string) $u->phone) === '' ? 'text-amber-600' : '' }}">{{ $title }} {{ $u ? $u->name.(trim((string) $u->phone) === '' ? ' ('.__('claims.settings_ceo_no_phone').')' : '') : '—' }}</span>
                @endforeach
            </p>
        </form>
    </div>

    {{-- 업무일지 — 상신 마감 시각 --}}
    <div class="card mb-4 max-w-lg">
        <div class="mb-3 border-b border-gray-100 pb-2">
            <span class="text-sm font-semibold text-gray-700">{{ __('journal.settings_title') }}</span>
        </div>
        <form wire:submit="saveJournalCutoff" class="space-y-3">
            <flux:input type="time" wire:model="journalCutoff" :label="__('journal.settings_cutoff')" :description="__('journal.settings_cutoff_hint')" required />
            <div class="flex items-center gap-3">
                <flux:button variant="primary" type="submit">{{ __('settings.feature.save') }}</flux:button>
                <x-action-message class="text-sm text-green-600" on="journal-saved">{{ __('journal.settings_saved') }}</x-action-message>
            </div>
        </form>
    </div>

    {{-- 카카오 알림톡(BizM) — 지역 검차 안내 --}}
    <div class="card max-w-lg">
        <div class="mb-3 border-b border-gray-100 pb-2">
            <span class="text-sm font-semibold text-gray-700">{{ __('settings.alimtalk.section') }}</span>
            <p class="mt-1 text-xs text-gray-400">{{ __('settings.alimtalk.hint') }}</p>
        </div>
        <form wire:submit="saveAlimtalk" class="space-y-5">
            <flux:switch wire:model="alimtalkEnabled" :label="__('settings.alimtalk.enabled_label')" :description="__('settings.alimtalk.enabled_hint')" />
            <flux:input wire:model="alimtalkUserid" :label="__('settings.alimtalk.userid_label')" :description="__('settings.alimtalk.shared_hint')" maxlength="100" />
            <flux:input wire:model="alimtalkProfile" :label="__('settings.alimtalk.profile_label')" :description="__('settings.alimtalk.shared_hint')" maxlength="100" />
            <flux:input wire:model="alimtalkTmpl" :label="__('settings.alimtalk.tmpl_label')" :description="__('settings.alimtalk.tmpl_hint')" maxlength="100" />
            <flux:switch wire:model="alimtalkToggle" :label="__('settings.alimtalk.toggle_label')" />
            <flux:input wire:model="alimtalkTmplForward" :label="__('settings.alimtalk.tmpl_forward_label')" :description="__('settings.alimtalk.tmpl_forward_hint')" maxlength="100" />
            <flux:switch wire:model="alimtalkToggleForward" :label="__('settings.alimtalk.toggle_forward_label')" />
            <flux:input wire:model="alimtalkScheduleTime" :label="__('settings.alimtalk.schedule_label')" :description="__('settings.alimtalk.schedule_hint')" type="time" />
            {{-- 클레임 2종(2026-11-10). ⚠️ 마스터를 켜면 위 지역검차·전달대기도 같이 발사된다 — 쓰지 않을 템플릿은 개별 토글을 먼저 끌 것. --}}
            <flux:input wire:model="alimtalkTmplClaimReq" :label="__('settings.alimtalk.tmpl_claim_req_label')" :description="__('settings.alimtalk.tmpl_claim_req_hint')" maxlength="100" />
            <flux:switch wire:model="alimtalkToggleClaimReq" :label="__('settings.alimtalk.toggle_claim_req_label')" />
            <flux:input wire:model="alimtalkTmplClaimDec" :label="__('settings.alimtalk.tmpl_claim_dec_label')" :description="__('settings.alimtalk.tmpl_claim_dec_hint')" maxlength="100" />
            <flux:switch wire:model="alimtalkToggleClaimDec" :label="__('settings.alimtalk.toggle_claim_dec_label')" />
            <flux:input wire:model="alimtalkTmplTransportReq" :label="__('settings.alimtalk.tmpl_transport_req_label')" :description="__('settings.alimtalk.tmpl_transport_req_hint')" maxlength="100" />
            <flux:switch wire:model="alimtalkToggleTransportReq" :label="__('settings.alimtalk.toggle_transport_req_label')" />
            <flux:input wire:model="alimtalkTmplTransportRes" :label="__('settings.alimtalk.tmpl_transport_res_label')" :description="__('settings.alimtalk.tmpl_transport_res_hint')" maxlength="100" />
            <flux:switch wire:model="alimtalkToggleTransportRes" :label="__('settings.alimtalk.toggle_transport_res_label')" />
            <p class="text-xs text-amber-600">{{ __('settings.alimtalk.master_warning') }}</p>
            <flux:input wire:model="alimtalkUserkey" :label="__('settings.alimtalk.userkey_label')" :description="__('settings.alimtalk.userkey_hint')" type="password" maxlength="200" />

            <div class="flex items-center gap-3">
                <flux:button variant="primary" type="submit">{{ __('settings.feature.save') }}</flux:button>
                <x-action-message class="text-sm text-green-600" on="alimtalk-saved">{{ __('settings.feature.saved') }}</x-action-message>
            </div>
        </form>

        {{-- 테스트 발송 --}}
        <div class="mt-5 border-t border-gray-100 pt-4">
            <label class="label-base">{{ __('settings.alimtalk.test_label') }}</label>
            <div class="mt-1 flex items-center gap-2">
                <input class="input-base flex-1" wire:model="alimtalkTestPhone" type="tel" placeholder="010-1234-5678">
                <flux:button wire:click="sendTestAlimtalk" type="button">{{ __('settings.alimtalk.test_btn') }}</flux:button>
            </div>
            @error('alimtalkTestPhone') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            @if ($alimtalkTestResult)
                <p class="mt-2 text-xs {{ str_starts_with($alimtalkTestResult, 'sent') ? 'text-green-600' : 'text-amber-600' }}">
                    {{ __('settings.alimtalk.test_result') }}: {{ $alimtalkTestResult }}
                </p>
            @endif
        </div>
    </div>
</div>

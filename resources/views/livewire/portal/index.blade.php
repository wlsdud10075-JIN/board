<?php

use App\Models\User;
use App\Services\CarErpReadService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * 영업 포털 — car-erp 읽기 미러(④재무) + 선적요청(③) + 서류(①②). 권위 = car-erp board-portal-api.md.
 * 전부 본인 스코프(salesman_email = Auth car_erp_salesman_email ?: email, 요청 파라미터 금지).
 * degrade: 미설정/401/5xx/403 → "조회 불가"(0원/완납 coerce 금지). 미수금 null = "환율 미입력" 보존.
 * 응답 키 = car-erp InternalPortalController/ShippingRequestController 와 정합(2026-06-18 확인).
 */
new #[Layout('components.layouts.app')] class extends Component {
    public string $tab = 'finance';

    public ?int $viewUserId = null;   // super 전용 — 다른 사용자 포털 열람 대상(본인=null). 비-super 는 무시(본인 격리 유지).

    public array $result = ['ok' => false, 'reason' => 'init', 'data' => null, 'status' => 0];

    // ③ v2 선적·B/L 묶음 (영속 묶음 + 선언형 sync). 권위 = car-erp board-portal-api.md §5.
    public string $shipSubtab = 'bundles';     // bundles(내 선적묶음·모니터) | plan(선적 계획·편집)

    public array $bundles = [];                // GET /bundles — 영속 묶음(표시전용, 재계산 금지)

    public array $shippablePool = [];          // GET /shippable — 새로 묶을 차 후보

    public array $desired = [];                // 선적 계획 편집상태: list of [key,buyer_id,buyer_name,consignee_id,consignees[],shipping_method,bl_type,vehicle_ids[]]

    public ?array $syncResult = null;          // 동기화 응답 {created,updated,cancelled,skipped,locked}

    public ?string $shipNote = null;           // 선적/서류 경고·오류 — 작은 안내

    public array $changeNote = [];             // [vehicle_id => note] 변경요청 메모 입력

    // 미수금 정렬/필터
    public string $recvSort = 'unpaid_krw';   // 기본 = 미수금 많은 순

    public string $recvDir = 'desc';

    public bool $hidePaid = true;             // 0원(완납) 숨김

    public array $monthly = [];               // 요약 탭 월별 집계(판매 건수·정산 실지급·매입)

    public array $salesDetail = [];           // 판매내역 펼침용 per-vehicle (바이어별 차량 리스트)

    private const TABS = ['finance', 'receivables', 'purchases', 'sales', 'settlements', 'shipping'];

    /**
     * 월별 집계 — 날짜 있는 리스트(판매/정산/매입)에서 YYYY-MM 별 집계.
     * 판매는 통화 혼재라 건수만(합산 무의미). 정산 실지급·매입가는 원화 합산.
     * 미수금은 날짜 없어 제외. (car-erp 정산 바이어 추가 시 buyer 차원 확장 가능.)
     */
    private function buildMonthly(string $email): array
    {
        $svc = $this->svc();
        $m = [];
        // $dateKeys = 문자열 1개 또는 폴백 배열(앞에서부터 처음 채워진 값 사용).
        $bump = function (array $env, string|array $dateKeys, ?string $amtKey, string $cnt, ?string $sum) use (&$m) {
            if (! ($env['ok'] ?? false)) {
                return;
            }
            foreach ((array) data_get($env['data'], 'data', []) as $r) {
                $d = null;
                foreach ((array) $dateKeys as $dk) {
                    if ($d = data_get($r, $dk)) {
                        break;
                    }
                }
                if (! $d) {
                    continue;
                }
                $key = substr((string) $d, 0, 7);   // YYYY-MM
                $m[$key][$cnt] = ($m[$key][$cnt] ?? 0) + 1;
                if ($amtKey && $sum) {
                    $m[$key][$sum] = ($m[$key][$sum] ?? 0) + (float) (data_get($r, $amtKey) ?? 0);
                }
            }
        };
        $bump($svc->sales($email), 'sale_date', null, 'sales_cnt', null);
        // 정산 월별 = 실지급일(paid_at) 우선, car-erp 가 아직 안 보내면 confirmed_at 폴백.
        // (handoff-car-erp-settlement-paid-at.md — car-erp 가 paid_at 노출하면 5월/6월 자동 분리)
        $bump($svc->settlements($email), ['paid_at', 'confirmed_at'], 'actual_payout', 'settle_cnt', 'settle_sum');
        $bump($svc->purchases($email), 'purchase_date', 'purchase_price', 'purch_cnt', 'purch_sum');
        krsort($m);   // 최근 월 먼저

        return $m;
    }

    /** 미수금 컬럼 정렬 토글. */
    public function sortRecv(string $key): void
    {
        if ($this->recvSort === $key) {
            $this->recvDir = $this->recvDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->recvSort = $key;
            $this->recvDir = in_array($key, ['unpaid_krw', 'exchange_rate'], true) ? 'desc' : 'asc';
        }
    }

    public function mount(): void
    {
        $this->load();
    }

    public function setTab(string $tab): void
    {
        if (! in_array($tab, self::TABS, true)) {
            return;
        }
        $this->tab = $tab;
        $this->syncResult = null;
        $this->shipNote = null;
        $this->load();
    }

    public function reload(): void
    {
        $this->load();
    }

    private function svc(): CarErpReadService
    {
        return app(CarErpReadService::class);
    }

    /**
     * car-erp 영업 매칭 이메일 — 오버라이드 우선, 없으면 로그인.
     * super 가 다른 사용자를 열람 중이면 그 사용자 기준(서버 isSuper 게이트). 비-super 는 항상 본인.
     */
    private function salesmanEmail(): string
    {
        $u = $this->viewingUser() ?? Auth::user();

        return $u->car_erp_salesman_email ?: $u->email;
    }

    /** super 전용 — 포털 열람 가능한 사용자(영업·관리, 활성). 비-super 는 빈 목록. */
    #[Computed]
    public function portalUsers()
    {
        if (! Auth::user()->isSuper()) {
            return collect();
        }

        return User::whereIn('role', ['sales', 'manager'])
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'role', 'email', 'car_erp_salesman_email']);
    }

    /** 현재 열람 중인 사용자 — super 가 지정했고 목록에 있는 유효 대상일 때만. 그 외(비-super 포함) null=본인. */
    private function viewingUser(): ?User
    {
        if ($this->viewUserId === null || ! Auth::user()->isSuper()) {
            return null;
        }

        return $this->portalUsers->firstWhere('id', $this->viewUserId);
    }

    /** 화면에 표시할 열람 대상 이름(본인 또는 super 가 선택한 사용자). */
    public function viewingName(): string
    {
        return ($this->viewingUser() ?? Auth::user())->name;
    }

    /** super 가 다른 사용자를 열람 중인가 — 그렇다면 쓰기(선적요청·서류)는 조회 전용 차단. */
    public function isViewingOther(): bool
    {
        return $this->viewingUser() !== null;
    }

    /** super — 열람 대상 변경(이름 클릭). null=본인. 비-super 는 무시(본인 격리). 목록 밖 id 도 무시. */
    public function viewUser(?int $id): void
    {
        if (! Auth::user()->isSuper()) {
            return;
        }
        $this->viewUserId = ($id !== null && $this->portalUsers->contains('id', $id)) ? $id : null;
        // 대상이 바뀌면 선적 편집상태(이전 사용자 차량 id)를 초기화.
        $this->reset(['bundles', 'shippablePool', 'desired', 'syncResult', 'shipNote', 'changeNote']);
        $this->load();
    }

    private function load(): void
    {
        $email = $this->salesmanEmail();
        $svc = $this->svc();
        $this->result = match ($this->tab) {
            'receivables' => $svc->receivables($email),
            'purchases' => $svc->purchases($email),
            // 판매·정산 = 바이어별 집계(통화별 판매합 / 정산 payout). 같은 by-buyer 엔드포인트.
            'sales', 'settlements' => $svc->byBuyer($email),
            'shipping' => $this->loadShipping($email, $svc),
            default => $svc->finance($email),
        };
        // 판매내역 = by-buyer(헤더) + per-vehicle 상세(펼침용 차량 리스트).
        $this->salesDetail = [];
        if ($this->tab === 'sales') {
            $s = $svc->sales($email);
            $this->salesDetail = ($s['ok'] ?? false) ? (array) data_get($s['data'], 'data', []) : [];
        }
        // 요약 탭 = 합계 + 월별(판매/정산/매입). 다른 탭은 월별 불필요.
        $this->monthly = $this->tab === 'finance' ? $this->buildMonthly($email) : [];
    }

    /**
     * 선적 로딩 — /bundles(영속 묶음) + /shippable(새 차) → 편집상태(desired) 시드.
     * desired = **requested(미착수) 묶음만** 편집 가능(in_progress/done/issued=잠금). 반환 = /bundles degrade 봉투.
     */
    private function loadShipping(string $email, CarErpReadService $svc): array
    {
        $env = $svc->bundles($email);
        $this->bundles = ($env['ok'] ?? false) ? (array) data_get($env['data'], 'data', []) : [];

        $shipEnv = $svc->shippable($email);
        $this->shippablePool = ($shipEnv['ok'] ?? false) ? (array) data_get($shipEnv['data'], 'data', []) : [];

        $this->desired = collect($this->bundles)
            ->filter(fn ($b) => ($b['status'] ?? '') === 'requested')
            ->map(fn ($b) => [
                'key' => 'b:'.($b['batch_id'] ?? uniqid()),
                'batch_id' => $b['batch_id'] ?? null,
                'buyer_id' => data_get($b, 'buyer.id'),
                'buyer_name' => data_get($b, 'buyer.name'),
                'consignee_id' => data_get($b, 'consignee.id'),
                'consignees' => (array) data_get($b, 'consignees', []),
                'shipping_method' => $b['shipping_method'] ?? 'RORO',
                'bl_type' => $b['bl_type'] ?? null,
                'vehicle_ids' => collect(data_get($b, 'vehicles', []))->pluck('vehicle_id')->map(fn ($i) => (int) $i)->values()->all(),
            ])->values()->all();

        return $env;
    }

    public function setShipSubtab(string $sub): void
    {
        $this->shipSubtab = in_array($sub, ['bundles', 'plan'], true) ? $sub : 'bundles';
        $this->syncResult = null;
        $this->shipNote = null;
    }

    /** 차 한 대를 어느 묶음에도 안 든 상태로(=pool) 만들기 위해 모든 desired 묶음에서 제거. */
    private function detach(int $vehicleId): void
    {
        foreach ($this->desired as $i => $bd) {
            $this->desired[$i]['vehicle_ids'] = array_values(array_filter($bd['vehicle_ids'], fn ($v) => (int) $v !== $vehicleId));
        }
    }

    /** 차를 특정 묶음으로 이동/배정(다른 묶음에서 빼고 그 묶음에 추가). 빈 묶음이면 그 차의 바이어 채택. */
    public function assignVehicle(string $key, int $vehicleId): void
    {
        $this->detach($vehicleId);
        foreach ($this->desired as $i => $bd) {
            if ($bd['key'] === $key) {
                $this->desired[$i]['vehicle_ids'][] = $vehicleId;
                // 바이어 미정 묶음이면 배정 차의 바이어/컨사이니 후보로 채택(묶음=1바이어).
                if (empty($this->desired[$i]['buyer_id'])) {
                    foreach ($this->shippablePool as $car) {
                        if ((int) data_get($car, 'vehicle_id') === $vehicleId) {
                            $this->desired[$i]['buyer_id'] = data_get($car, 'buyer.id');
                            $this->desired[$i]['buyer_name'] = data_get($car, 'buyer.name');
                            $this->desired[$i]['consignees'] = (array) data_get($car, 'consignees', []);
                            break;
                        }
                    }
                }

                return;
            }
        }
    }

    /** 차를 묶음에서 빼기 → pool 로(미배정). */
    public function unassignVehicle(int $vehicleId): void
    {
        $this->detach($vehicleId);
    }

    /** 새 빈 묶음 추가(영업이 새 차를 담아 새 선적 구성). buyer 는 첫 배정 차의 바이어로 자동. */
    public function addBundle(?int $buyerId = null, ?string $buyerName = null, array $consignees = []): void
    {
        $this->desired[] = [
            'key' => 'n:'.uniqid(),
            'batch_id' => null,
            'buyer_id' => $buyerId,
            'buyer_name' => $buyerName,
            'consignee_id' => null,
            'consignees' => $consignees,
            'shipping_method' => 'RORO',
            'bl_type' => null,
            'vehicle_ids' => [],
        ];
    }

    public function removeBundle(string $key): void
    {
        $this->desired = array_values(array_filter($this->desired, fn ($b) => $b['key'] !== $key));
    }

    public function setBundleField(string $key, string $field, $value): void
    {
        if (! in_array($field, ['consignee_id', 'shipping_method', 'bl_type'], true)) {
            return;
        }
        foreach ($this->desired as $i => $bd) {
            if ($bd['key'] === $key) {
                $this->desired[$i][$field] = $value ?: null;

                return;
            }
        }
    }

    /**
     * 동기화 — 영업의 desired 묶음 **전체** 전송(선언형). car-erp 가 현재 open 행과 diff.
     * ⚠️ 전체 전송이라 빠진 requested 차는 car-erp 에서 자동취소(스펙 §5-2). 그래서 desired 를 통째로 보냄.
     */
    public function syncBundles(): void
    {
        if ($this->isViewingOther()) {
            $this->shipNote = __('portal.flash_view_only_ship');

            return;
        }
        // 차 1대 이상 + 바이어 지정된 묶음만 전송(빈 묶음·바이어 미정 제외).
        $payload = collect($this->desired)
            ->filter(fn ($b) => ! empty($b['buyer_id']) && ! empty($b['vehicle_ids']))
            ->map(fn ($b) => [
                'buyer_id' => (int) $b['buyer_id'],
                'consignee_id' => $b['consignee_id'] ? (int) $b['consignee_id'] : null,
                'shipping_method' => in_array($b['shipping_method'] ?? '', ['RORO', 'CONTAINER'], true) ? $b['shipping_method'] : 'RORO',
                'bl_type' => in_array($b['bl_type'] ?? '', ['original', 'surrender'], true) ? $b['bl_type'] : null,
                'vehicle_ids' => array_values(array_map('intval', $b['vehicle_ids'])),
            ])->values()->all();

        $res = $this->svc()->syncShippingRequests($this->salesmanEmail(), $payload);
        if (! ($res['ok'] ?? false)) {
            $this->shipNote = __('portal.flash_ship_failed');

            return;
        }
        $this->shipNote = null;
        $this->syncResult = [
            'created' => count($res['data']['created'] ?? []),
            'updated' => count($res['data']['updated'] ?? []),
            'cancelled' => count($res['data']['cancelled'] ?? []),
            'locked' => count($res['data']['locked'] ?? []),
        ];
        $this->load();
    }

    /** 기존 묶음 B/L요청 — bl_type 확정(original/surrender) → car-erp 관리 알람. */
    public function requestBl(string $batchId, string $blType): void
    {
        if ($this->isViewingOther()) {
            $this->shipNote = __('portal.flash_view_only_ship');

            return;
        }
        if (! in_array($blType, ['original', 'surrender'], true) || $batchId === '') {
            return;
        }
        $res = $this->svc()->blRequest($this->salesmanEmail(), $batchId, $blType);
        $this->shipNote = ($res['ok'] ?? false) ? __('portal.flash_bl_requested') : __('portal.flash_ship_failed');
        $this->load();
    }

    /** in_progress(관리 착수) 차 변경/취소 요청 — 관리가 수락거절(자동적용 X). */
    public function requestChange(int $vehicleId): void
    {
        if ($this->isViewingOther()) {
            $this->shipNote = __('portal.flash_view_only_ship');

            return;
        }
        $note = trim((string) ($this->changeNote[$vehicleId] ?? ''));
        if ($note === '') {
            $this->shipNote = __('portal.flash_change_note_required');

            return;
        }
        $res = $this->svc()->changeRequest($this->salesmanEmail(), $vehicleId, $note);
        $this->shipNote = ($res['ok'] ?? false) ? __('portal.flash_change_requested') : __('portal.flash_ship_failed');
        $this->changeNote[$vehicleId] = '';
        $this->load();
    }

    /** ①② 서류 — 묶음 차량의 선적서류(method별 4종 중 2종). xlsx 스트림 다운로드. */
    public function downloadDocs(array $vehicleIds, string $method, string $kind)
    {
        // 조회 전용 — 타인 포털 열람 중엔 서류(타인 PII) 다운로드 차단. 서버 게이트.
        if ($this->isViewingOther()) {
            $this->shipNote = __('portal.flash_view_only_docs');

            return null;
        }
        $ids = array_values(array_map('intval', $vehicleIds));
        if ($ids === []) {
            $this->shipNote = __('portal.flash_select_vehicle_docs');

            return null;
        }
        $method = strtolower($method) === 'container' ? 'container' : 'roro';   // roro|container
        $type = $method.'_'.$kind;   // roro_contract / container_invoice_packing ...

        $res = $this->svc()->document($type, $ids, $this->salesmanEmail());
        if (! ($res['ok'] ?? false)) {
            $this->shipNote = __('portal.flash_docs_failed');

            return null;
        }

        $body = $res['body'];
        $name = $type.'_'.implode('-', $ids).'.xlsx';

        return response()->streamDownload(fn () => print ($body), $name, [
            'Content-Type' => $res['content_type'] ?: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** 한글 축약 금액 (요약 탭 전용): 704369898 → "7억 436만원". */
    public function abbrevKrw(int|float|null $won): string
    {
        if ($won === null) {
            return '—';
        }
        $won = (int) $won;
        $abs = abs($won);
        if ($abs >= 100000000) {
            $eok = intdiv($won, 100000000);
            $man = intdiv(abs($won) % 100000000, 10000);

            return $man ? $eok.__('portal.abbr_eok').' '.number_format($man).__('portal.abbr_man') : $eok.__('portal.abbr_eok').__('portal.abbr_won');
        }
        if ($abs >= 10000) {
            return number_format(intdiv($won, 10000)).__('portal.abbr_man');
        }

        return number_format($won).__('portal.abbr_won');
    }

    public function degradeMessage(): string
    {
        return match ($this->result['status'] ?? 0) {
            403 => __('portal.degrade_403'),
            default => match ($this->result['reason'] ?? '') {
                'not_configured' => __('portal.degrade_not_configured'),
                default => __('portal.degrade_default'),
            },
        };
    }
}; ?>

<div class="p-3 md:p-6">
    <div class="mb-4">
        <h1 class="text-xl font-bold text-gray-800">{{ __('portal.title') }}</h1>
        @if (auth()->user()->isSuper() && $viewUserId !== null)
            <p class="mt-0.5 text-xs text-gray-500">👁️ {!! __('portal.viewing_other', ['name' => '<b class="text-[var(--color-primary-text)]">'.e($this->viewingName()).'</b>']) !!}</p>
        @else
            <p class="mt-0.5 text-xs text-gray-500">🔒 {{ __('portal.viewing_self', ['name' => auth()->user()->name]) }}</p>
        @endif
    </div>

    {{-- 사용자별 조회 (시스템관리자 전용) — 이름 클릭 시 그 사용자의 정산·미수·선적 표시 --}}
    @if (auth()->user()->isSuper())
        <div class="card-sm mb-3" style="background:#f8f9fb">
            <div class="mb-1.5 flex flex-wrap items-center gap-x-2 gap-y-0.5">
                <span class="text-[12px] font-semibold text-gray-600">👁️ {{ __('portal.view_by_user') }}</span>
                <span class="text-[11px] text-gray-400">{{ __('portal.view_by_user_hint') }}</span>
            </div>
            <div class="flex flex-wrap gap-1">
                <button wire:click="viewUser(null)"
                    class="rounded-md border px-2.5 py-1 text-[12px] font-semibold {{ $viewUserId === null ? 'border-[var(--color-primary)] bg-[var(--color-primary)] text-white' : 'border-gray-300 bg-white text-gray-600' }}">{{ __('portal.view_self_btn') }}</button>
                @foreach ($this->portalUsers as $pu)
                    <button wire:click="viewUser({{ $pu->id }})"
                        class="rounded-md border px-2.5 py-1 text-[12px] font-semibold {{ $viewUserId === $pu->id ? 'border-[var(--color-primary)] bg-[var(--color-primary)] text-white' : 'border-gray-300 bg-white text-gray-600' }}">
                        {{ $pu->name }} <span class="text-[10px] font-normal opacity-70">{{ $pu->roleLabel() }}</span>
                    </button>
                @endforeach
            </div>
        </div>
    @endif

    {{-- 탭 --}}
    <div class="mb-3 flex flex-wrap gap-1">
        @foreach (['finance' => __('portal.tab.finance'), 'receivables' => __('portal.tab.receivables'), 'purchases' => __('portal.tab.purchases'), 'sales' => __('portal.tab.sales'), 'settlements' => __('portal.tab.settlements'), 'shipping' => '🚢 '.__('portal.tab.shipping')] as $key => $label)
            <button wire:click="setTab('{{ $key }}')"
                class="rounded-md border px-3 py-1.5 text-[13px] font-semibold {{ $tab === $key ? 'border-[var(--color-primary)] bg-[var(--color-primary)] text-white' : 'border-gray-300 bg-white text-gray-600' }}">{{ $label }}</button>
        @endforeach
        <button wire:click="reload" class="ml-auto rounded-md border border-gray-300 bg-white px-3 py-1.5 text-[13px] text-blue-600" title="{{ __('portal.reload_title') }}">↻ {{ __('portal.reload') }}</button>
    </div>

    {{-- 동기화 결과 — 생성/갱신/취소/처리중 건수 (크게, 확실하게) --}}
    @if ($syncResult)
        <div wire:key="syncres-{{ $syncResult['created'] }}-{{ $syncResult['cancelled'] }}-{{ $syncResult['locked'] }}"
             x-data="{ show: true }" x-show="show" x-transition.scale.origin.top
             class="mb-4 flex items-center gap-4 rounded-xl border-2 border-green-400 bg-green-50 px-5 py-4 shadow-md">
            <div class="text-4xl">✅</div>
            <div class="flex-1">
                <div class="text-lg font-bold text-green-800">{{ __('portal.sync_done_title') }}</div>
                <div class="mt-0.5 flex flex-wrap gap-x-3 gap-y-0.5 text-[14px] text-green-700">
                    <span>{{ __('portal.sync_created', ['count' => $syncResult['created']]) }}</span>
                    <span>{{ __('portal.sync_updated', ['count' => $syncResult['updated']]) }}</span>
                    @if ($syncResult['cancelled']) <span class="text-amber-600">{{ __('portal.sync_cancelled', ['count' => $syncResult['cancelled']]) }}</span> @endif
                    @if ($syncResult['locked']) <span class="text-blue-600">{{ __('portal.sync_locked', ['count' => $syncResult['locked']]) }}</span> @endif
                </div>
                <div class="mt-1 text-[12px] text-green-600">📨 {{ __('portal.ship_done_alarm') }}</div>
            </div>
            <button @click="show = false" class="self-start text-green-400 hover:text-green-700" title="{{ __('common.close') }}">✕</button>
        </div>
    @endif
    @if ($shipNote)
        <div class="card-sm mb-3 border-amber-200 bg-amber-50 text-[13px] text-amber-700">⚠️ {{ $shipNote }}</div>
    @endif

    <div class="card">
        @if (! ($result['ok'] ?? false))
            <div class="card-sm border-amber-200 bg-amber-50 text-[13px] text-amber-800">
                ⚠️ <b>{{ __('portal.unavailable') }}</b> — {{ $this->degradeMessage() }}
            </div>

        @elseif ($tab === 'finance')
            @php $sum = is_array($result['data']) ? $result['data'] : []; @endphp
            <div class="grid gap-3 sm:grid-cols-4">
                <div class="card-sm"><div class="text-xs text-gray-500">{{ __('portal.kpi_unpaid_total') }}</div><div class="mt-1 text-lg font-bold text-gray-800" title="{{ isset($sum['unpaid_total_krw']) ? number_format((int) $sum['unpaid_total_krw']).__('common.won_currency') : '' }}">{{ isset($sum['unpaid_total_krw']) ? $this->abbrevKrw($sum['unpaid_total_krw']) : '—' }}</div></div>
                <div class="card-sm"><div class="text-xs text-gray-500">{{ __('portal.kpi_purchase_unpaid_total') }}</div><div class="mt-1 text-lg font-bold text-gray-800" title="{{ isset($sum['purchase_unpaid_total']) ? number_format((int) $sum['purchase_unpaid_total']).__('common.won_currency') : '' }}">{{ isset($sum['purchase_unpaid_total']) ? $this->abbrevKrw($sum['purchase_unpaid_total']) : '—' }}</div></div>
                <div class="card-sm"><div class="text-xs text-gray-500">{{ __('portal.kpi_settlement_pending') }}</div><div class="mt-1 text-lg font-bold text-gray-800">{{ isset($sum['settlement_pending_count']) ? __('portal.unit_count', ['count' => $sum['settlement_pending_count']]) : '—' }}</div></div>
                <div class="card-sm"><div class="text-xs text-gray-500">{{ __('portal.kpi_fx_missing') }}</div><div class="mt-1 text-lg font-bold {{ ($sum['fx_missing_count'] ?? 0) ? 'text-amber-600' : 'text-gray-800' }}">{{ isset($sum['fx_missing_count']) ? __('portal.unit_count', ['count' => $sum['fx_missing_count']]) : '—' }}</div></div>
            </div>

            {{-- 월별 (판매 건수·정산 실지급·매입) — 날짜 있는 리스트서 집계. 판매액은 통화혼재라 건수만. --}}
            <div class="mt-4" wire:key="monthly" x-data="{ open: true }">
                <button type="button" class="mb-2 flex items-center gap-2 font-bold text-gray-700" @click="open = !open">
                    <span class="w-3 text-gray-400" x-text="open ? '▼' : '▶'"></span> 📅 {{ __('portal.monthly_perf') }}
                </button>
                <div x-show="open" x-cloak>
                    <div class="hidden overflow-x-auto sm:block">
                        <table class="tbl">
                            <thead><tr><th>{{ __('portal.col_month') }}</th><th>{{ __('portal.col_sales_cnt') }}</th><th>{{ __('portal.col_settle_sum') }}</th><th>{{ __('portal.col_purch_cnt') }}</th><th>{{ __('portal.col_purch_sum') }}</th></tr></thead>
                            <tbody>
                                @forelse ($monthly as $month => $row)
                                    <tr>
                                        <td class="font-semibold text-gray-700">{{ $month }}</td>
                                        <td>{{ $row['sales_cnt'] ?? 0 }}</td>
                                        <td>{{ number_format((float) ($row['settle_sum'] ?? 0)) }}</td>
                                        <td>{{ $row['purch_cnt'] ?? 0 }}</td>
                                        <td>{{ number_format((float) ($row['purch_sum'] ?? 0)) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="py-6 text-center text-gray-400">{{ __('portal.monthly_empty') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="space-y-2 sm:hidden">
                        @forelse ($monthly as $month => $row)
                            <div class="card-tight">
                                <div class="font-semibold text-gray-700">{{ $month }}</div>
                                <div class="mt-1 grid grid-cols-2 gap-x-3 gap-y-1 text-xs text-gray-600">
                                    <div>{{ __('portal.m_sales') }} <b class="text-gray-800">{{ $row['sales_cnt'] ?? 0 }}</b>{{ __('portal.count_suffix') }}</div>
                                    <div>{{ __('portal.m_purchase') }} <b class="text-gray-800">{{ $row['purch_cnt'] ?? 0 }}</b>{{ __('portal.count_suffix') }}</div>
                                    <div>{{ __('portal.m_settle') }} <b class="text-gray-800">{{ number_format((float) ($row['settle_sum'] ?? 0)) }}</b></div>
                                    <div>{{ __('portal.m_purch_price') }} <b class="text-gray-800">{{ number_format((float) ($row['purch_sum'] ?? 0)) }}</b></div>
                                </div>
                            </div>
                        @empty
                            <div class="py-6 text-center text-gray-400">{{ __('portal.monthly_empty') }}</div>
                        @endforelse
                    </div>
                    <p class="mt-1 text-[11px] text-gray-400">💡 {{ __('portal.monthly_note') }}</p>
                </div>
            </div>

        @elseif ($tab === 'shipping')
            {{-- ③ v2 선적·B/L 묶음 — 내 묶음(모니터) + 선적 계획(편집/동기화). 권위 = car-erp board-portal-api.md §5 --}}
            @php
                $stBadge = ['requested' => 'bg-amber-500 text-white', 'in_progress' => 'bg-blue-600 text-white', 'done' => 'bg-gray-500 text-white', 'cancelled' => 'bg-gray-300 text-gray-600'];
                $stLabel = ['requested' => __('portal.ship_status_requested'), 'in_progress' => __('portal.ship_status_in_progress'), 'done' => __('portal.ship_status_done'), 'cancelled' => __('portal.ship_status_cancelled')];
                $blLabel = ['requested' => __('portal.bl_status_requested'), 'issued' => __('portal.bl_status_issued')];
                $blTypeLabel = ['original' => __('portal.bl_original'), 'surrender' => __('portal.bl_surrender')];
            @endphp

            {{-- 서브탭 --}}
            <div class="mb-4 inline-flex overflow-hidden rounded-lg border border-gray-300">
                @foreach (['bundles' => '📦 '.__('portal.ship_sub_bundles'), 'plan' => '🗂️ '.__('portal.ship_sub_plan')] as $sub => $label)
                    <button type="button" wire:click="setShipSubtab('{{ $sub }}')"
                        class="px-4 py-1.5 text-[13px] font-semibold {{ $shipSubtab === $sub ? 'bg-[var(--color-primary)] text-white' : 'bg-white text-gray-600' }}">{{ $label }}</button>
                @endforeach
            </div>

            @if ($shipSubtab === 'bundles')
                {{-- ── 내 선적묶음 (영속 뷰·모니터) — car-erp 값 그대로 표시(재계산·완납 coerce 금지 §5-4) ── --}}
                @forelse ($bundles as $bd)
                    @php
                        $st = $bd['status'] ?? 'requested';
                        $blStatus = $bd['bl_status'] ?? 'none';
                        $blType = $bd['bl_type'] ?? null;
                        $method = $bd['shipping_method'] ?? null;
                        $bvehicles = (array) data_get($bd, 'vehicles', []);
                        $unpaidKrw = $bd['unpaid_total_krw'] ?? null;
                        $fxMissing = (int) ($bd['fx_missing_count'] ?? 0);
                        $fullyPaid = (bool) ($bd['fully_paid'] ?? false);
                        $ratio = $bd['unpaid_ratio'] ?? null;   // 0~1 또는 null
                        $busy = $st === 'in_progress';
                        $batchId = $bd['batch_id'] ?? null;
                        $vIds = collect($bvehicles)->pluck('vehicle_id')->map(fn ($i) => (int) $i)->all();
                    @endphp
                    <div class="card-sm mb-3" wire:key="bundle-{{ $batchId }}">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="flex min-w-0 items-center gap-2">
                                <span class="text-base">{{ $method === 'CONTAINER' ? '📦' : '🚢' }}</span>
                                <span class="truncate text-[14px] font-bold text-gray-800">{{ data_get($bd, 'buyer.name') ?: __('portal.buyer_unassigned') }}</span>
                                <span class="text-[12px] text-gray-400">{{ $method ?: __('portal.ship_method_undefined') }} · {{ __('portal.unit_vehicles', ['count' => count($bvehicles)]) }}</span>
                            </div>
                            <div class="flex shrink-0 items-center gap-1.5">
                                <span class="rounded-full px-2.5 py-1 text-[11px] font-bold {{ $stBadge[$st] ?? 'bg-gray-300 text-gray-600' }}">{{ $stLabel[$st] ?? $st }}</span>
                                @if ($blStatus !== 'none')
                                    <span class="rounded-full bg-indigo-100 px-2.5 py-1 text-[11px] font-bold text-indigo-700">B/L: {{ $blLabel[$blStatus] ?? $blStatus }}{{ $blType ? ' · '.($blTypeLabel[$blType] ?? $blType) : '' }}</span>
                                @endif
                            </div>
                        </div>

                        <div class="mt-2 flex flex-wrap gap-1.5">
                            @foreach ($bvehicles as $v)
                                <span class="rounded-md border border-gray-200 bg-gray-50 px-2 py-0.5 text-[12px] font-semibold text-gray-700">{{ data_get($v, 'vehicle_number') }}</span>
                            @endforeach
                        </div>

                        {{-- 미수 게이지 — car-erp 계산값 그대로(완납판정·0 coerce 금지) --}}
                        <div class="mt-2.5">
                            @if ($fxMissing > 0)
                                <div class="mb-1 text-[12px] font-semibold text-red-600">⚠ {{ __('portal.ship_fx_missing', ['count' => $fxMissing]) }}</div>
                            @endif
                            @if ($fullyPaid)
                                <span class="inline-block rounded-full bg-green-100 px-2.5 py-1 text-[12px] font-bold text-green-700">✅ {{ __('portal.ship_fully_paid') }}</span>
                            @else
                                <div class="flex items-center gap-2">
                                    <div class="h-2.5 flex-1 overflow-hidden rounded-full bg-gray-200">
                                        @php $fill = $ratio === null ? 0 : max(0, min(100, (float) $ratio * 100)); @endphp
                                        <div class="h-full rounded-full bg-amber-500" style="width: {{ $fill }}%"></div>
                                    </div>
                                    <span class="shrink-0 text-[12px] font-semibold text-gray-600">
                                        {{ __('portal.ship_unpaid') }}: {{ $unpaidKrw === null ? __('portal.fx_missing_short') : $this->abbrevKrw($unpaidKrw) }}
                                    </span>
                                </div>
                            @endif
                        </div>

                        @unless ($this->isViewingOther())
                            {{-- B/L요청 (같은 묶음 상태전이) — issued 전까지 --}}
                            @if ($batchId && $blStatus !== 'issued')
                                <div class="mt-2.5 flex flex-wrap items-center gap-2 border-t border-gray-200 pt-2 text-[12px]">
                                    <span class="text-gray-400">{{ __('portal.bl_request_label') }}</span>
                                    <button wire:click="requestBl('{{ $batchId }}','original')" class="btn-ghost btn-sm">{{ __('portal.bl_original') }}</button>
                                    <button wire:click="requestBl('{{ $batchId }}','surrender')" class="btn-ghost btn-sm">{{ __('portal.bl_surrender') }}</button>
                                    @if ($blStatus === 'requested')
                                        <span class="text-[11px] text-blue-600">({{ __('portal.bl_requested_already', ['type' => $blTypeLabel[$blType] ?? '—']) }})</span>
                                    @endif
                                </div>
                            @endif
                            {{-- 서류 (4종 중 method별 2종) --}}
                            <div class="mt-2 flex flex-wrap items-center gap-2 text-[12px]">
                                <span class="text-gray-400">{{ __('portal.docs_label', ['method' => $method ?: 'RORO']) }}</span>
                                <button wire:click="downloadDocs({{ json_encode($vIds) }}, '{{ $method ?: 'RORO' }}', 'contract')" class="btn-ghost btn-sm">📄 {{ __('portal.docs_contract') }}</button>
                                <button wire:click="downloadDocs({{ json_encode($vIds) }}, '{{ $method ?: 'RORO' }}', 'invoice_packing')" class="btn-ghost btn-sm">📄 {{ __('portal.docs_invoice_packing') }}</button>
                            </div>
                            {{-- 변경요청 (in_progress = 관리 착수 → 자동변경 불가, 명시 요청만) --}}
                            @if ($busy)
                                <div class="mt-2 border-t border-gray-200 pt-2">
                                    <p class="mb-1 text-[11px] text-gray-400">🔒 {{ __('portal.change_request_hint') }}</p>
                                    @foreach ($bvehicles as $v)
                                        @php $vid = (int) data_get($v, 'vehicle_id'); @endphp
                                        <div class="mt-1 flex items-center gap-2" wire:key="chg-{{ $vid }}">
                                            <span class="shrink-0 text-[12px] font-semibold text-gray-700">{{ data_get($v, 'vehicle_number') }}</span>
                                            <input wire:model="changeNote.{{ $vid }}" class="input-base flex-1 text-[12px]" placeholder="{{ __('portal.change_request_ph') }}">
                                            <button wire:click="requestChange({{ $vid }})" class="btn-ghost btn-sm shrink-0">{{ __('portal.change_request_btn') }}</button>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        @endunless
                    </div>
                @empty
                    <p class="py-8 text-center text-gray-400">{{ __('portal.bundles_empty') }}</p>
                @endforelse

            @else
                {{-- ── 선적 계획 (재구성·동기화) ── --}}
                @if ($this->isViewingOther())
                    <p class="py-8 text-center text-gray-400">👁️ {{ __('portal.ship_view_only_note', ['name' => $this->viewingName()]) }}</p>
                @else
                    <p class="mb-3 text-[13px] text-gray-500">{!! __('portal.plan_intro') !!}</p>

                    {{-- 편집 가능한 묶음 (requested 단계만) --}}
                    @foreach ($desired as $bd)
                        @php $method = $bd['shipping_method'] ?? 'RORO'; $blType = $bd['bl_type'] ?? null; @endphp
                        <div class="card-sm mb-2" style="background:#f8f9fb" wire:key="desired-{{ $bd['key'] }}">
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-[13px] font-bold text-gray-800">🧑 {{ $bd['buyer_name'] ?: __('portal.buyer_unassigned_paren') }}</span>
                                <button wire:click="removeBundle('{{ $bd['key'] }}')" class="text-[11px] text-gray-400 hover:text-red-600">✕ {{ __('portal.plan_remove_bundle') }}</button>
                            </div>
                            {{-- 차량 칩(각 ✕ = 묶음서 빼기 → pool) --}}
                            <div class="mt-2 flex flex-wrap gap-1.5">
                                @forelse ($bd['vehicle_ids'] as $vid)
                                    @php $vno = data_get(collect($shippablePool)->firstWhere('vehicle_id', $vid), 'vehicle_number') ?? data_get(collect($bundles)->pluck('vehicles')->flatten(1)->firstWhere('vehicle_id', $vid), 'vehicle_number') ?? ('#'.$vid); @endphp
                                    <span class="inline-flex items-center gap-1 rounded-md border border-gray-300 bg-white px-2 py-0.5 text-[12px] font-semibold text-gray-700">
                                        {{ $vno }}
                                        <button wire:click="unassignVehicle({{ $vid }})" class="text-gray-400 hover:text-red-600">✕</button>
                                    </span>
                                @empty
                                    <span class="text-[11px] text-gray-400">{{ __('portal.plan_bundle_empty') }}</span>
                                @endforelse
                            </div>
                            {{-- 컨사이니 · 방식 · B/L유형 --}}
                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                <select wire:change="setBundleField('{{ $bd['key'] }}','consignee_id', $event.target.value)" class="input-base w-auto text-[12px]">
                                    <option value="">{{ __('portal.consignee_select') }}</option>
                                    @foreach ((array) $bd['consignees'] as $c)
                                        <option value="{{ data_get($c, 'id') }}" @selected((int) ($bd['consignee_id'] ?? 0) === (int) data_get($c, 'id'))>{{ data_get($c, 'name') }}</option>
                                    @endforeach
                                </select>
                                <div class="inline-flex overflow-hidden rounded-md border border-gray-300">
                                    @foreach (['RORO', 'CONTAINER'] as $m)
                                        <button type="button" wire:click="setBundleField('{{ $bd['key'] }}','shipping_method','{{ $m }}')"
                                            class="px-3 py-1 text-[12px] font-semibold {{ $method === $m ? 'bg-[var(--color-primary)] text-white' : 'bg-white text-gray-600' }}">{{ $m }}</button>
                                    @endforeach
                                </div>
                                {{-- 오리지널/써랜더(미정 가능) --}}
                                <div class="inline-flex overflow-hidden rounded-md border border-gray-300">
                                    @foreach (['' => __('portal.bl_undecided'), 'original' => __('portal.bl_original'), 'surrender' => __('portal.bl_surrender')] as $bt => $btLabel)
                                        <button type="button" wire:click="setBundleField('{{ $bd['key'] }}','bl_type','{{ $bt }}')"
                                            class="px-2.5 py-1 text-[12px] font-semibold {{ ($blType ?? '') === $bt ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600' }}">{{ $btLabel }}</button>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    @endforeach

                    <button wire:click="addBundle" class="btn-ghost btn-sm mb-3">+ {{ __('portal.plan_add_bundle') }}</button>

                    {{-- 새로 묶을 차 (shippable, 아직 미배정) --}}
                    @php
                        $assigned = collect($desired)->flatMap(fn ($b) => $b['vehicle_ids'])->map(fn ($i) => (int) $i)->all();
                        $pool = collect($shippablePool)->reject(fn ($v) => in_array((int) data_get($v, 'vehicle_id'), $assigned, true));
                    @endphp
                    <div class="rounded-lg border border-dashed border-gray-300 p-3">
                        <h4 class="mb-2 text-[13px] font-bold text-gray-700">🆕 {{ __('portal.plan_pool_title') }} <span class="text-[11px] font-normal text-gray-400">({{ __('portal.unit_vehicles', ['count' => $pool->count()]) }})</span></h4>
                        @forelse ($pool as $v)
                            @php $vid = (int) data_get($v, 'vehicle_id'); @endphp
                            <div class="mb-1 flex flex-wrap items-center gap-2" wire:key="pool-{{ $vid }}">
                                <span class="text-[12px] font-semibold text-gray-700">{{ data_get($v, 'vehicle_number') }}</span>
                                <span class="text-[11px] text-gray-400">🧑 {{ data_get($v, 'buyer.name') ?: __('portal.buyer_unassigned') }}</span>
                                <select @change="if($event.target.value){ $wire.assignVehicle($event.target.value, {{ $vid }}); $event.target.value=''; }" class="input-base w-auto text-[12px]">
                                    <option value="">{{ __('portal.plan_assign_to') }}</option>
                                    @foreach ($desired as $bd)
                                        <option value="{{ $bd['key'] }}">{{ $bd['buyer_name'] ?: __('portal.plan_new_bundle_opt') }} ({{ count($bd['vehicle_ids']) }})</option>
                                    @endforeach
                                </select>
                            </div>
                        @empty
                            <p class="text-[12px] text-gray-400">{{ __('portal.plan_pool_empty') }}</p>
                        @endforelse
                    </div>

                    <div class="mt-4 flex items-center gap-3">
                        <button wire:click="syncBundles" wire:loading.attr="disabled" class="btn-primary">🔄 {{ __('portal.plan_sync_btn') }}</button>
                        <span wire:loading wire:target="syncBundles" class="text-[12px] text-gray-400">…</span>
                    </div>
                    <p class="mt-1.5 text-[11px] text-amber-600">⚠️ {{ __('portal.plan_sync_warn') }}</p>
                @endif
            @endif

        @elseif ($tab === 'receivables')
            {{-- 미수금 — 바이어별 접기 + 완납(0원) 숨김 + 컬럼 정렬 --}}
            @php
                $items = collect(data_get($result['data'], 'data', []));
                if ($hidePaid) {
                    $items = $items->reject(fn ($r) => ($v = data_get($r, 'unpaid_krw')) !== null && is_numeric($v) && (float) $v == 0.0);
                }
                $sortRows = function ($rows) {
                    $key = $this->recvSort;
                    $desc = $this->recvDir === 'desc';

                    return $rows->sort(function ($a, $b) use ($key, $desc) {
                        $x = data_get($a, $key);
                        $y = data_get($b, $key);
                        if ($x === null && $y === null) {
                            return 0;
                        }
                        if ($x === null) {
                            return 1;
                        }   // null 은 항상 뒤로
                        if ($y === null) {
                            return -1;
                        }
                        $cmp = is_numeric($x) && is_numeric($y) ? ((float) $x <=> (float) $y) : strcmp((string) $x, (string) $y);

                        return $desc ? -$cmp : $cmp;
                    })->values();
                };
                $byBuyer = $items->groupBy(fn ($r) => data_get($r, 'buyer') ?: __('portal.buyer_unassigned_paren'));
                $cols = ['vehicle_number' => __('portal.col_vehicle'), 'currency' => __('portal.col_currency'), 'exchange_rate' => __('portal.col_exchange_rate'), 'unpaid_krw' => __('portal.col_unpaid_krw')];
            @endphp
            <label class="mb-2 flex items-center gap-2 text-[12px] text-gray-500">
                <input type="checkbox" wire:model.live="hidePaid"> {{ __('portal.hide_paid') }}
            </label>
            @forelse ($byBuyer as $buyer => $rows)
                <div class="card-sm mb-2" wire:key="recv-{{ $loop->index }}" x-data="{ open: false }">
                    <button type="button" class="flex w-full items-center gap-2 text-left font-bold text-gray-800" @click="open = !open">
                        <span class="w-3 text-gray-400" x-text="open ? '▼' : '▶'"></span>
                        🧑 {{ $buyer }} <span class="text-xs font-normal text-gray-400">· {{ __('portal.unit_vehicles', ['count' => $rows->count()]) }}</span>
                        @php $sum = $rows->sum(fn ($r) => (float) (data_get($r, 'unpaid_krw') ?? 0)); @endphp
                        <span class="ml-auto text-[13px] font-bold text-[var(--color-primary-text)]">{{ number_format($sum) }}{{ __('common.won_currency') }}</span>
                    </button>
                    <div x-show="open" x-cloak class="mt-2">
                        <div class="hidden overflow-x-auto sm:block">
                            <table class="tbl">
                                <thead><tr>
                                    @foreach ($cols as $k => $label)
                                        <th><button type="button" wire:click="sortRecv('{{ $k }}')" class="flex items-center gap-1 font-semibold {{ $recvSort === $k ? 'text-[var(--color-primary-text)]' : '' }}">{{ $label }}<span class="text-[10px]">{{ $recvSort === $k ? ($recvDir === 'asc' ? '▲' : '▼') : '↕' }}</span></button></th>
                                    @endforeach
                                </tr></thead>
                                <tbody>
                                    @foreach ($sortRows($rows) as $row)
                                        <tr>
                                            @foreach ($cols as $k => $label)
                                                @php $val = data_get($row, $k); @endphp
                                                <td class="whitespace-nowrap {{ $val === null ? 'text-amber-600' : 'text-gray-700' }}">
                                                    @if ($val === null)
                                                        {{ $k === 'unpaid_krw' ? __('portal.fx_missing') : '—' }}
                                                    @elseif ($k === 'unpaid_krw' && is_numeric($val))
                                                        {{ number_format((float) $val) }}
                                                    @else
                                                        {{ $val }}
                                                    @endif
                                                </td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <div class="space-y-1.5 sm:hidden">
                            @foreach ($sortRows($rows) as $row)
                                @php $unpaid = data_get($row, 'unpaid_krw'); @endphp
                                <div class="flex items-center justify-between gap-2 rounded-md border border-gray-100 bg-gray-50 px-2.5 py-2">
                                    <div class="min-w-0">
                                        <div class="font-semibold text-gray-700">{{ data_get($row, 'vehicle_number') ?: '—' }}</div>
                                        <div class="text-[11px] text-gray-400">{{ data_get($row, 'currency') ?: '—' }} · {{ __('portal.fx_rate_label') }} {{ data_get($row, 'exchange_rate') ?? '—' }}</div>
                                    </div>
                                    <div class="shrink-0 text-right text-sm font-bold {{ $unpaid === null ? 'text-amber-600' : 'text-gray-800' }}">
                                        {{ $unpaid === null ? __('portal.fx_missing') : number_format((float) $unpaid).__('common.won_currency') }}
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @empty
                <p class="py-8 text-center text-gray-400">{{ __('portal.recv_empty') }}{{ $hidePaid ? __('portal.recv_empty_hidden') : '' }}</p>
            @endforelse

        @elseif ($tab === 'sales')
            {{-- 판매내역 — 바이어별 통화별 합(by-buyer) + 펼치면 그 바이어가 산 차량 리스트 --}}
            @php
                $buyers = data_get($result['data'], 'data', []);
                $detailByBuyer = collect($salesDetail)->groupBy(fn ($r) => data_get($r, 'buyer') ?: __('portal.buyer_unassigned_paren'));
            @endphp
            @forelse ($buyers as $b)
                @php
                    $bName = data_get($b, 'buyer') ?: __('portal.buyer_unassigned_paren');
                    $rows = $detailByBuyer[$bName] ?? collect();
                    $byCur = (array) data_get($b, 'sales_by_currency', []);
                @endphp
                <div class="card-sm mb-2" wire:key="sales-{{ $loop->index }}" x-data="{ open: false }">
                    <button type="button" class="flex w-full items-center gap-2 text-left font-bold text-gray-800" @click="open = !open">
                        <span class="w-3 text-gray-400" x-text="open ? '▼' : '▶'"></span>
                        🧑 {{ $bName }} <span class="text-xs font-normal text-gray-400">· {{ __('portal.unit_vehicles', ['count' => data_get($b, 'vehicle_count', 0)]) }}</span>
                        <span class="ml-auto text-[13px]">@forelse ($byCur as $cur => $amt)<span class="ml-2 whitespace-nowrap"><b class="text-gray-500">{{ $cur }}</b> {{ number_format((float) $amt) }}</span>@empty<span class="text-gray-300">—</span>@endforelse</span>
                    </button>
                    <div x-show="open" x-cloak class="mt-2">
                        <div class="hidden overflow-x-auto sm:block">
                            <table class="tbl">
                                <thead><tr><th>{{ __('portal.col_vehicle') }}</th><th>{{ __('portal.col_currency') }}</th><th>{{ __('portal.col_sale_price') }}</th><th>{{ __('portal.col_sale_date') }}</th></tr></thead>
                                <tbody>
                                    @forelse ($rows as $row)
                                        <tr>
                                            <td class="font-semibold text-gray-700">{{ data_get($row, 'vehicle_number') }}</td>
                                            <td>{{ data_get($row, 'currency') ?: '—' }}</td>
                                            <td>{{ ($p = data_get($row, 'sale_price')) !== null && is_numeric($p) ? number_format((float) $p) : '—' }}</td>
                                            <td>{{ data_get($row, 'sale_date') ?: '—' }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="4" class="py-3 text-center text-gray-400">{{ __('portal.sales_detail_empty') }}</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                        <div class="space-y-1.5 sm:hidden">
                            @forelse ($rows as $row)
                                <div class="flex items-center justify-between gap-2 rounded-md border border-gray-100 bg-gray-50 px-2.5 py-2">
                                    <div class="min-w-0">
                                        <div class="font-semibold text-gray-700">{{ data_get($row, 'vehicle_number') }}</div>
                                        <div class="text-[11px] text-gray-400">{{ data_get($row, 'sale_date') ?: '—' }}</div>
                                    </div>
                                    <div class="shrink-0 text-right text-sm font-semibold text-gray-800">
                                        {{ ($p = data_get($row, 'sale_price')) !== null && is_numeric($p) ? number_format((float) $p) : '—' }}
                                        <span class="text-[11px] font-normal text-gray-400">{{ data_get($row, 'currency') ?: '' }}</span>
                                    </div>
                                </div>
                            @empty
                                <div class="py-3 text-center text-gray-400">{{ __('portal.sales_detail_empty') }}</div>
                            @endforelse
                        </div>
                    </div>
                </div>
            @empty
                <p class="py-8 text-center text-gray-400">{{ __('portal.sales_empty') }}</p>
            @endforelse

        @elseif ($tab === 'settlements')
            {{-- 정산내역 — 바이어별 정산 실지급 (by-buyer, payout 내림차순) --}}
            @php $buyers = data_get($result['data'], 'data', []); @endphp
            <div class="hidden overflow-x-auto sm:block">
                <table class="tbl">
                    <thead><tr><th>{{ __('portal.col_buyer') }}</th><th>{{ __('portal.col_vehicle_count') }}</th><th>{{ __('portal.col_payout_total') }}</th><th>{{ __('portal.col_payout_paid') }}</th></tr></thead>
                    <tbody>
                        @forelse ($buyers as $b)
                            <tr>
                                <td class="font-semibold text-gray-700">{{ data_get($b, 'buyer') ?: __('portal.buyer_unassigned_paren') }}</td>
                                <td class="whitespace-nowrap text-gray-500">{{ __('portal.unit_vehicles', ['count' => data_get($b, 'vehicle_count', 0)]) }}</td>
                                <td class="whitespace-nowrap font-semibold text-gray-800">{{ number_format((float) data_get($b, 'payout_total_krw', 0)) }}</td>
                                <td class="whitespace-nowrap text-gray-500">{{ number_format((float) data_get($b, 'payout_paid_krw', 0)) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-8 text-center text-gray-400">{{ __('portal.settle_empty') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="space-y-2 sm:hidden">
                @forelse ($buyers as $b)
                    <div class="card-tight">
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-semibold text-gray-700">{{ data_get($b, 'buyer') ?: __('portal.buyer_unassigned_paren') }}</span>
                            <span class="shrink-0 text-xs text-gray-400">{{ __('portal.unit_vehicles', ['count' => data_get($b, 'vehicle_count', 0)]) }}</span>
                        </div>
                        <div class="mt-1 grid grid-cols-2 gap-x-3 text-xs text-gray-600">
                            <div>{{ __('portal.lbl_payout_total') }} <b class="text-gray-800">{{ number_format((float) data_get($b, 'payout_total_krw', 0)) }}</b></div>
                            <div>{{ __('portal.lbl_payout_paid') }} <b class="text-gray-800">{{ number_format((float) data_get($b, 'payout_paid_krw', 0)) }}</b></div>
                        </div>
                    </div>
                @empty
                    <div class="py-8 text-center text-gray-400">{{ __('portal.settle_empty') }}</div>
                @endforelse
            </div>

        @else
            {{-- 매입내역 — buyer 무관(경매/판매처) → 평면 목록 --}}
            @php
                $items = data_get($result['data'], 'data', []);
                $cols = ['vehicle_number' => __('portal.col_vehicle'), 'purchase_price' => __('portal.col_purchase_price'), 'cost_total' => __('portal.col_cost_total'), 'purchase_unpaid' => __('portal.col_purchase_unpaid'), 'purchase_date' => __('portal.col_purchase_date')];
            @endphp
            <div class="hidden overflow-x-auto sm:block">
                <table class="tbl">
                    <thead><tr>@foreach ($cols as $label)<th>{{ $label }}</th>@endforeach</tr></thead>
                    <tbody>
                        @forelse ($items as $row)
                            <tr>
                                @foreach ($cols as $key => $label)
                                    @php $val = data_get($row, $key); @endphp
                                    <td class="whitespace-nowrap text-gray-700">
                                        @if ($val === null)
                                            —
                                        @elseif (in_array($key, ['purchase_price', 'cost_total', 'purchase_unpaid'], true) && is_numeric($val))
                                            {{ number_format((float) $val) }}
                                        @else
                                            {{ $val }}
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @empty
                            <tr><td colspan="{{ count($cols) }}" class="py-8 text-center text-gray-400">{{ __('portal.purch_empty') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="space-y-2 sm:hidden">
                @forelse ($items as $row)
                    <div class="card-tight">
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-semibold text-gray-700">{{ data_get($row, 'vehicle_number') ?: '—' }}</span>
                            <span class="shrink-0 text-[11px] text-gray-400">{{ data_get($row, 'purchase_date') ?: '' }}</span>
                        </div>
                        <div class="mt-1 grid grid-cols-3 gap-x-2 text-xs text-gray-600">
                            @foreach (['purchase_price' => __('portal.col_purchase_price'), 'cost_total' => __('portal.col_cost_total'), 'purchase_unpaid' => __('portal.col_purchase_unpaid')] as $key => $label)
                                @php $val = data_get($row, $key); @endphp
                                <div>{{ $label }}<br><b class="text-gray-800">{{ ($val === null || ! is_numeric($val)) ? '—' : number_format((float) $val) }}</b></div>
                            @endforeach
                        </div>
                    </div>
                @empty
                    <div class="py-8 text-center text-gray-400">{{ __('portal.purch_empty') }}</div>
                @endforelse
            </div>
        @endif
    </div>

    <p class="mt-2 text-[11px] text-gray-400">💡 {{ __('portal.footer_note') }}</p>
</div>

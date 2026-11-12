<?php

use App\Models\PurchaseListing;
use App\Models\TransportCompany;
use App\Models\TransportRequest;
use App\Models\TransportRequestRecipient;
use App\Services\TransportService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

/**
 * 매입예정 드로어 「탁송 배차」 탭 — 중첩 컴포넌트(2026-11-12 Jin, 정본 meetings/plan-transport-2026-11.md).
 * 부모 드로어의 update()/reset 과 무관하게 자기 저장을 가진다. 열람 = PurchaseListing 글로벌 SalesmanScope 가 거른다(남의 매물이면 404).
 * 쓰기(요청·취소·매입잔금) = TransportService::canWrite(super·등록 영업·공유받은 관리) — 업무관리자·최고관리자는 조회 전용.
 */
new class extends Component
{
    use WithFileUploads;

    public int $listingId;

    public ?int $company_id = null;

    public string $extra_phone = '';

    public string $address = '';

    public string $stock_location = '';

    public string $message = TransportRequest::DEFAULT_MESSAGE;

    public string $mode = 'now';   // now | later

    public string $send_at = '';

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $photos = [];

    public string $balanceAmount = '';

    public ?string $notice = null;

    public ?string $error = null;

    /** @var array{available:bool, value:?string, note:?string}|null */
    public ?array $erpLocation = null;

    public function mount(int $listingId): void
    {
        $this->listingId = $listingId;
        $this->send_at = now()->addHour()->startOfHour()->format('Y-m-d\TH:i');
        $this->loadErpLocation();
    }

    #[Computed]
    public function listing(): PurchaseListing
    {
        return PurchaseListing::findOrFail($this->listingId);   // SalesmanScope 가 열람 권한
    }

    #[Computed]
    public function canWrite(): bool
    {
        return TransportService::canWrite(Auth::user(), $this->listing);
    }

    #[Computed]
    public function companies()
    {
        return TransportCompany::active()->withCount(['phones as phones_count' => fn ($q) => $q->where('is_active', true)])->get();
    }

    #[Computed]
    public function requests()
    {
        return TransportRequest::where('purchase_listing_id', $this->listingId)->with(['recipients', 'decidedRecipient', 'files'])->orderByDesc('revision')->get();
    }

    #[Computed]
    public function open(): ?TransportRequest
    {
        return $this->requests->first(fn ($r) => $r->isOpen());
    }

    /** 함께 받는 사람 미리보기 — 등록 영업 + 그 영업의 관리(전화 있는 사람만 실제 발송). */
    #[Computed]
    public function cc(): array
    {
        $creator = $this->listing->creator;
        if (! $creator) {
            return [];
        }
        $ids = DB::table('manager_salesman')->where('salesman_user_id', $creator->id)->pluck('manager_user_id');
        $users = collect([$creator])->merge(\App\Models\User::whereIn('id', $ids)->where('is_active', true)->get());

        return $users->map(fn ($u) => ['name' => $u->name, 'has_phone' => (bool) $u->phone])->all();
    }

    /** 버튼 목록 — ERP 가 `options` 를 주면 그것(회사별, karaba 쇼링·항입고·야드), 아니면 board 설정. */
    public function locations(): array
    {
        return ! empty($this->erpLocation['options']) ? $this->erpLocation['options'] : TransportService::stockLocations();
    }

    /** ERP 현재 보관 위치(10분 캐시) — ERP 가 권위. 경로가 아직 없으면 available=false(「연동 대기」). */
    private function loadErpLocation(): void
    {
        $l = PurchaseListing::find($this->listingId);
        if (! $l || ! $l->car_erp_vehicle_id) {
            $this->erpLocation = null;

            return;
        }
        $this->erpLocation = Cache::remember('transport:erploc:'.$l->id, now()->addMinutes(10), function () use ($l) {
            $r = app(TransportService::class)->erpLocation($l);

            return ['available' => $r['available'], 'value' => data_get($r['data'], 'stock_location'), 'note' => data_get($r['data'], 'stock_location_note'),
                'options' => array_values(array_filter((array) data_get($r['data'], 'options', []), 'is_string'))];   // ERP 회사별 목록(§16) — 있으면 상수 대신 이걸로 버튼
        });
        if ($this->stock_location === '' && ! empty($this->erpLocation['value'])) {
            $this->stock_location = (string) $this->erpLocation['value'];
        }
    }

    public function submit(TransportService $svc): void
    {
        $this->notice = $this->error = null;
        if (! $this->canWrite) {
            $this->error = __('transport.errors.forbidden');

            return;
        }
        $this->validate([
            'company_id' => 'nullable|integer',
            'extra_phone' => ['nullable', 'string', 'max:25', 'regex:/^[0-9\-\s]*$/'],
            'address' => 'nullable|string|max:255',
            'stock_location' => 'nullable|string|max:20',
            'message' => 'required|string|max:255',
            'mode' => 'in:now,later',
            'send_at' => 'required_if:mode,later|nullable|date',
            'photos' => 'array|max:'.TransportRequest::MAX_FILES,
            'photos.*' => 'file|max:20480',
        ]);
        $sendAt = $this->mode === 'later' ? Carbon::parse($this->send_at, config('app.timezone')) : now();
        if ($this->mode === 'later' && $sendAt->lte(now())) {
            $this->addError('send_at', __('transport.errors.send_at_past'));

            return;
        }
        try {
            $req = $svc->create($this->listing, Auth::user(), [
                'company_id' => $this->company_id, 'extra_phone' => $this->extra_phone, 'address' => $this->address,
                'stock_location' => $this->stock_location, 'message' => $this->message, 'send_at' => $sendAt,
                'allowed_locations' => $this->locations(),
            ], $this->photos);
        } catch (\DomainException $e) {
            $this->error = __('transport.errors.'.$e->getMessage(), ['max' => TransportRequest::MAX_FILES]);

            return;
        }
        // 도착 위치 → ERP 보관 위치(ERP 경로 ① 있을 때). 실패해도 흐름은 안 끊는다(탭에 표시 + 재시도).
        if ($req->stock_location) {
            $svc->syncLocation($req, Auth::user(), __('transport.balance.note_prefix').($req->company_name ?: '').' · '.$sendAt->timezone(config('app.timezone'))->format('m-d H:i'));
            Cache::forget('transport:erploc:'.$this->listingId);
        }
        $this->notice = $req->isSent()
            ? __('transport.created_now', ['count' => $req->recipients()->count()])
            : __('transport.created_later', ['at' => $sendAt->timezone(config('app.timezone'))->format('m-d H:i')]);
        $this->reset('company_id', 'extra_phone', 'address', 'photos', 'balanceAmount');
        $this->message = TransportRequest::DEFAULT_MESSAGE;
        $this->mode = 'now';
        unset($this->requests, $this->open);
    }

    public function cancel(int $id, TransportService $svc): void
    {
        $this->notice = $this->error = null;
        if (! $this->canWrite) {
            return;
        }
        try {
            $svc->cancel($this->req($id), Auth::user());
            $this->notice = __('transport.cancelled_done');
        } catch (\DomainException $e) {
            $this->error = __('transport.errors.'.$e->getMessage());
        }
        unset($this->requests, $this->open);
    }

    public function reschedule(int $id, string $at, TransportService $svc): void
    {
        $this->notice = $this->error = null;
        if (! $this->canWrite) {
            return;
        }
        $when = Carbon::parse($at, config('app.timezone'));
        try {
            $svc->reschedule($this->req($id), Auth::user(), $when);
            $this->notice = __('transport.reschedule_done');
        } catch (\DomainException $e) {
            $this->error = __('transport.errors.'.$e->getMessage());
        }
        unset($this->requests, $this->open);
    }

    public function requestBalance(int $id, TransportService $svc): void
    {
        $this->notice = $this->error = null;
        if (! $this->canWrite) {
            $this->error = __('transport.errors.forbidden');

            return;
        }
        $amount = preg_replace('/\D/', '', $this->balanceAmount);
        $r = $svc->requestBalance($this->req($id), Auth::user(), $amount === '' ? null : (int) $amount);
        if ($r['ok']) {
            $this->notice = $r['message'];
            $this->balanceAmount = '';
        } else {
            $this->error = $r['message'];
        }
        unset($this->requests);
    }

    public function retryLocation(int $id, TransportService $svc): void
    {
        if (! $this->canWrite) {
            return;
        }
        $req = $this->req($id);
        $svc->syncLocation($req, Auth::user(), __('transport.balance.note_prefix').$req->dispatchLine());
        Cache::forget('transport:erploc:'.$this->listingId);
        unset($this->requests);
    }

    private function req(int $id): TransportRequest
    {
        return TransportRequest::where('purchase_listing_id', $this->listingId)->findOrFail($id);
    }

    public function linkFor(TransportRequest $req, TransportRequestRecipient $r): string
    {
        return $req->linkFor($r);
    }
}; ?>

<div class="text-[13px]">
    @if ($notice)<div class="card-sm mb-3 border-green-200 bg-green-50 text-[13px] text-green-700">✓ {{ $notice }}</div>@endif
    @if ($error)<div class="card-sm mb-3 border-red-200 bg-red-50 text-[13px] text-red-700">⚠️ {{ $error }}</div>@endif
    @unless ($this->canWrite)
        <div class="card-sm mb-3 border-amber-200 bg-amber-50 text-[12px] text-amber-800">{{ __('transport.readonly') }}</div>
    @endunless

    {{-- ── 요청 폼 (열린 요청이 없을 때만) ── --}}
    @if ($this->canWrite && ! $this->open)
        <div class="space-y-2.5">
            <div>
                <label class="label-base">{{ __('transport.form.company') }}</label>
                @if ($this->companies->isEmpty())
                    <p class="text-xs text-gray-400">{{ __('transport.form.company_empty') }}</p>
                @else
                    <select wire:model="company_id" class="input-base">
                        <option value="">{{ __('transport.form.company_none') }}</option>
                        @foreach ($this->companies as $c)
                            <option value="{{ $c->id }}">{{ $c->name }} · {{ __('transport.form.company_phones', ['count' => $c->phones_count]) }}</option>
                        @endforeach
                    </select>
                @endif
            </div>
            <div>
                <label class="label-base">{{ __('transport.form.extra_phone') }}</label>
                <input wire:model="extra_phone" class="input-base" inputmode="tel" placeholder="{{ __('transport.form.extra_phone_ph') }}">
                @error('extra_phone')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="label-base">{{ __('transport.form.address') }}</label>
                <input wire:model="address" class="input-base" maxlength="255" placeholder="{{ __('transport.form.address_ph') }}">
            </div>
            <div x-data="{ cur: @js($erpLocation['value'] ?? null) }">
                <label class="label-base">{{ __('transport.form.location') }}</label>
                <div class="flex flex-wrap gap-1.5">
                    @foreach ($this->locations() as $loc)
                        <button type="button" wire:key="loc-{{ $loc }}"
                            @click="if (cur && cur !== @js($loc) && !confirm(@js(__('transport.form.location_confirm', ['current' => $erpLocation['value'] ?? '', 'new' => $loc])))) return; $wire.set('stock_location', @js($loc))"
                            class="rounded-md border px-3 py-1.5 text-[12px] font-semibold {{ $stock_location === $loc ? 'border-[var(--color-primary)] bg-[var(--color-primary)] text-white' : 'border-gray-300 bg-white text-gray-600' }}">{{ $loc }}</button>
                    @endforeach
                    <button type="button" wire:click="$set('stock_location', '')" class="rounded-md border px-3 py-1.5 text-[12px] {{ $stock_location === '' ? 'border-gray-500 bg-gray-100 text-gray-700' : 'border-gray-300 bg-white text-gray-500' }}">{{ __('transport.form.location_none') }}</button>
                </div>
                @if ($erpLocation !== null)
                    <p class="mt-1 text-[11px] text-gray-500">
                        @if ($erpLocation['available'])
                            {{ __('transport.form.location_erp', ['value' => $erpLocation['value'] ?: '—']) }}@if ($erpLocation['note']) · {{ $erpLocation['note'] }}@endif
                        @else
                            {{ __('transport.form.location_erp_pending') }}
                        @endif
                    </p>
                @endif
            </div>
            <div>
                <label class="label-base">{{ __('transport.form.photos', ['max' => \App\Models\TransportRequest::MAX_FILES]) }} <span class="font-normal text-gray-400">— {{ __('transport.form.photos_hint') }}</span></label>
                <input type="file" wire:model="photos" multiple accept="image/*" class="input-base text-xs">
                @error('photos')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                @error('photos.*')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                @if ($photos)
                    <div class="mt-1.5 grid grid-cols-5 gap-1">
                        @foreach ($photos as $i => $p)
                            <img wire:key="pv-{{ $i }}" src="{{ $p->temporaryUrl() }}" class="aspect-square w-full rounded object-cover" alt="">
                        @endforeach
                    </div>
                @endif
            </div>
            <div>
                <label class="label-base">{{ __('transport.form.message') }}</label>
                <input wire:model="message" class="input-base" maxlength="255">
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <label class="flex items-center gap-1 text-[12px]"><input type="radio" wire:model.live="mode" value="now"> {{ __('transport.form.send_now') }}</label>
                <label class="flex items-center gap-1 text-[12px]"><input type="radio" wire:model.live="mode" value="later"> {{ __('transport.form.send_later') }}</label>
                @if ($mode === 'later')
                    <input type="datetime-local" wire:model="send_at" class="input-base w-auto text-xs">
                @endif
            </div>
            @error('send_at')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
            <p class="text-[11px] text-gray-500">{{ __('transport.form.cc') }}:
                @forelse ($this->cc as $u)<span class="{{ $u['has_phone'] ? '' : 'line-through text-gray-400' }}" title="{{ $u['has_phone'] ? '' : __('transport.form.cc_none') }}">{{ $u['name'] }}</span>@if (! $loop->last) · @endif@empty —@endforelse
            </p>
            <button type="button" class="btn-primary w-full" wire:click="submit" wire:loading.attr="disabled" wire:target="submit,photos">
                <span wire:loading.remove wire:target="submit">{{ __('transport.form.submit') }}</span>
                <span wire:loading wire:target="submit">{{ __('transport.form.sending') }}</span>
            </button>
        </div>
    @endif

    {{-- ── 이력 ── --}}
    <div class="section-title-sm mt-4">{{ __('transport.history') }}</div>
    @forelse ($this->requests as $r)
        <div wire:key="tr-{{ $r->id }}" class="mb-2 rounded-md border border-gray-200 bg-gray-50 px-3 py-2">
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                <span class="font-semibold text-gray-700">{{ __('transport.rev', ['n' => $r->revision]) }}</span>
                <span class="badge {{ $r->statusBadge() }}">{{ $r->statusLabel() }}</span>
                <span class="text-gray-600">{{ $r->company_name ?: __('transport.alimtalk.company_direct') }}</span>
                @if ($r->stock_location)<span class="badge bg-teal-100 text-teal-700">{{ $r->stock_location }}</span>@endif
                <span class="ml-auto text-[11px] text-gray-400">{{ ($r->sent_at ?? $r->send_at)->timezone(config('app.timezone'))->format('m-d H:i') }}</span>
            </div>
            @if ($r->address)<div class="mt-0.5 text-[12px] text-gray-500">{{ $r->address }}</div>@endif
            @if ($r->files->isNotEmpty())
                <div class="mt-1 grid grid-cols-5 gap-1">
                    @foreach ($r->files as $f)<a wire:key="trf-{{ $f->id }}" href="{{ $f->shareUrl() }}" target="_blank"><img src="{{ $f->shareUrl() }}" class="aspect-square w-full rounded object-cover" loading="lazy" alt=""></a>@endforeach
                </div>
            @endif

            @if ($r->isDecided())
                <div class="mt-1 text-[12px] {{ $r->isAccepted() ? 'text-emerald-700' : 'text-red-700' }}">
                    {{ __('transport.decided_by', ['who' => $r->dispatchLine(), 'at' => $r->decided_at?->timezone(config('app.timezone'))->format('m-d H:i')]) }}
                    @if ($r->reject_reason)<br>{{ __('transport.reason', ['reason' => $r->reject_reason]) }}@endif
                </div>
            @endif

            {{-- 수신자 — 번호별 발송 상태. super 는 업체 링크를 대신 열 수 있다(로컬 시험·운영 지원). --}}
            @if ($r->recipients->isNotEmpty())
                <div class="mt-1 flex flex-wrap gap-1 text-[11px]">
                    @foreach ($r->recipients as $rc)
                        <span wire:key="trc-{{ $rc->id }}" class="rounded border border-gray-200 bg-white px-1.5 py-0.5 text-gray-600">
                            {{ __('transport.recipient_kind.'.$rc->kind) }} {{ $rc->displayName() }}
                            @if ($rc->sent_status)<span class="{{ $rc->sent_status === 'sent' ? 'text-emerald-600' : ($rc->sent_status === 'failed' ? 'text-red-600' : 'text-gray-400') }}">· {{ __('transport.sent_status.'.$rc->sent_status) }}</span>@endif
                            @if (auth()->user()->isSuper() && $rc->canDecide() && $r->isSent())
                                <a href="{{ $this->linkFor($r, $rc) }}" target="_blank" class="text-[var(--color-primary-text)] underline" title="{{ __('transport.open_link_hint') }}">{{ __('transport.open_link') }}</a>
                            @endif
                        </span>
                    @endforeach
                </div>
            @endif

            {{-- 발송 전 — 취소·시각 변경 --}}
            @if ($r->isScheduled() && $this->canWrite)
                <div class="mt-1.5 flex flex-wrap items-center gap-1.5" x-data="{ at: @js($r->send_at->timezone(config('app.timezone'))->format('Y-m-d\TH:i')) }">
                    <input type="datetime-local" x-model="at" class="input-base w-auto text-xs">
                    <button type="button" class="btn-secondary btn-sm" @click="$wire.reschedule({{ $r->id }}, at)">{{ __('transport.reschedule') }}</button>
                    <button type="button" class="btn-ghost btn-sm text-red-600" wire:click="cancel({{ $r->id }})" wire:confirm="{{ __('transport.cancel_confirm') }}">{{ __('transport.cancel') }}</button>
                </div>
            @endif

            {{-- 배차완료 — 매입잔금 요청(§11, 사람이 누른다 — 자동은 2차) --}}
            @if ($r->isAccepted())
                <div class="mt-2 rounded-md border border-emerald-200 bg-emerald-50 px-2.5 py-2">
                    <div class="text-[12px] font-semibold text-emerald-800">{{ __('transport.balance.title') }}</div>
                    @if ($r->balance_requested_at)
                        <p class="text-[12px] text-emerald-700">{{ __('transport.balance.already', ['at' => $r->balance_requested_at->timezone(config('app.timezone'))->format('m-d H:i')]) }}</p>
                    @elseif ($this->canWrite)
                        <p class="mb-1.5 text-[11px] text-gray-600">{{ __('transport.balance.hint') }}</p>
                        <div class="flex gap-1.5">
                            <input wire:model="balanceAmount" class="input-base text-xs" inputmode="numeric" placeholder="{{ __('transport.balance.amount_ph') }}">
                            <button type="button" class="btn-primary btn-sm whitespace-nowrap" wire:click="requestBalance({{ $r->id }})"
                                wire:confirm="{{ __('transport.balance.confirm_full', ['plate' => \App\Support\Plate::show($this->listing->vehicle_number)]) }}">{{ __('transport.balance.button') }}</button>
                        </div>
                        @if ($r->balance_request_error)<p class="mt-1 text-[11px] text-red-600">{{ $r->balance_request_error }}</p>@endif
                    @endif
                </div>
            @endif

            {{-- ERP 보관 위치 전송 상태 --}}
            @if ($r->stock_location && ($r->erp_location_synced_at || $r->erp_location_error))
                <p class="mt-1 text-[11px] {{ $r->erp_location_error ? 'text-amber-700' : 'text-gray-500' }}">
                    @if ($r->erp_location_error)
                        {{ __('transport.location.error', ['reason' => $r->erp_location_error]) }}
                        @if ($this->canWrite)<button type="button" class="ml-1 underline" wire:click="retryLocation({{ $r->id }})">{{ __('transport.location.retry') }}</button>@endif
                    @else
                        {{ __('transport.location.synced', ['value' => $r->stock_location, 'at' => $r->erp_location_synced_at->timezone(config('app.timezone'))->format('m-d H:i')]) }}
                    @endif
                </p>
            @endif
        </div>
    @empty
        <p class="text-xs text-gray-400">{{ __('transport.history_empty') }}</p>
    @endforelse
</div>

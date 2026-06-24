<?php

use App\Models\PurchaseListing;
use App\Services\ExchangeRateService;
use App\Services\OfferForwardService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * 전달 대기 — 검차완료(inspected) 차를 영업이 사진 확인 후 바이어에게 전달(→ awaiting_buyer).
 * 검차/영업 분리: 검차는 검차완료까지, 전달은 딜 주인(영업)이 사진 보고 누른다.
 * SalesmanScope: 영업은 본인 글만(크로스영업 노출 없음 = IDOR 차단).
 */
new #[Layout('components.layouts.app')] class extends Component {
    public ?int $detailId = null;

    public string $buyer_name = '';

    public bool $forceManual = false;

    public ?string $conflictVehicle = null;

    // ── 견적 통화 (바이어에게 보낼 때 영업이 선택) ──
    public int $krwPerUsd = 0;

    public int $krwPerEur = 0;

    /** 견적 카드/표시 통화. 드로어 열 때 offer_currency 로 표시만(저장 ❌) — 버튼 누를 때만 저장. */
    public string $quoteCurrency = 'KRW';

    public function mount(ExchangeRateService $rates): void
    {
        $rates->refreshIfStale();   // 오래됐을 때만 갱신(lazy)
        $this->krwPerUsd = $rates->krwPerUsd();
        $this->krwPerEur = $rates->krwPerEur();
    }

    private function usdRate(): int
    {
        return $this->krwPerUsd ?: (int) config('board.default_krw_per_usd');
    }

    private function eurRate(): int
    {
        return $this->krwPerEur ?: (int) config('board.default_krw_per_eur');
    }

    /** 검차완료(전달 대기) 차 — 영업 본인 것만(SalesmanScope 전역). */
    #[Computed]
    public function items()
    {
        return PurchaseListing::with('creator')
            ->withCount(['photos as share_photos_count' => fn ($q) => $q->where('share_to_buyer', true)])
            ->where('status', 'inspected')
            ->orderBy('updated_at')
            ->get();
    }

    /** 드로어 대상 (검차 사진 포함). status=inspected 한정 + SalesmanScope → IDOR 차단. */
    #[Computed]
    public function detail(): ?PurchaseListing
    {
        return $this->detailId
            ? PurchaseListing::with(['creator', 'photos'])->where('status', 'inspected')->find($this->detailId)
            : null;
    }

    public function openDetail(int $id): void
    {
        $l = PurchaseListing::where('status', 'inspected')->findOrFail($id);
        $this->detailId = $l->id;
        $this->buyer_name = $l->buyer_name ?? '';
        $this->forceManual = false;
        $this->conflictVehicle = null;
        // 이미 통화 정한 딜(예: EUR 바이어)은 그 통화로 표시, 안 정했으면 KRW. 표시만 — 저장 ❌.
        $this->quoteCurrency = $l->offer_currency ?: 'KRW';
        $this->resetErrorBag();
    }

    public function closeDetail(): void
    {
        $this->reset(['detailId', 'buyer_name', 'forceManual', 'conflictVehicle', 'quoteCurrency']);
        unset($this->detail);
    }

    /**
     * 견적 통화 확정 — 영업이 버튼을 직접 누를 때만 저장(드로어 열기로는 안 덮음 → EUR 딜 보존).
     * offer_currency = 판매통화(연동 B sale_currency)라 명시 클릭 시에만 굳힌다.
     * offer_rate(라이브 스냅샷) + final_price(=totalKrw 재스냅샷)를 함께 → 카드 최종 = offerAmount() 일치.
     */
    public function setQuoteCurrency(string $cur): void
    {
        if (! in_array($cur, ['KRW', 'USD', 'EUR'], true)) {
            return;
        }

        // findOrFail 이 SalesmanScope 안 → 본인 글만(IDOR). inspected 한정.
        $l = PurchaseListing::where('status', 'inspected')->findOrFail($this->detailId);

        $l->offer_currency = $cur;
        $l->offer_rate = match ($cur) {
            'USD' => $this->usdRate(),
            'EUR' => $this->eurRate(),
            default => 1,
        };
        // 최종금액(KRW) 재스냅샷 — 공식 입력이 있을 때만(없으면 기존 final_price 유지).
        $computed = $l->totalKrw($this->usdRate(), $this->eurRate());
        if ($computed !== null) {
            $l->final_price = $computed;
        }
        $l->save();

        $this->quoteCurrency = $cur;
        unset($this->detail, $this->items);   // 캐시 무효화 → 카드/표시가 새 offer_rate·final_price 로 재렌더
    }

    /**
     * 견적 카드/드로어 3줄 금액 — 선택통화로 환산한 일관된 분해.
     * Total = offerAmount()(final_price 스냅샷 기반, 불변) / Car = carPriceKrw÷rate / Shipping = Total−Car(잔차 흡수).
     * 나눗셈 두 번 금지(센트 어긋남 방지) → 합 == Total 보장. final_price 없으면 null(카드 없이 사진만).
     */
    public function quoteData(): ?array
    {
        $d = $this->detail;
        if (! $d) {
            return null;
        }

        $offer = $d->offerAmount($this->usdRate(), $this->eurRate());
        if ($offer === null) {
            return null;   // final_price 미설정 → 가격 협의중
        }

        $cur = $offer['currency'];
        $rate = max(1, (int) $offer['rate']);
        $total = (int) $offer['amount'];

        $carKrw = $d->carPriceKrw($this->usdRate(), $this->eurRate());
        $car = $carKrw === null ? null : (int) round($carKrw / $rate);
        $shipping = $car === null ? null : $total - $car;   // 잔차 흡수

        return [
            'vehicle' => $this->vehicleRoman($d->vehicle_number),
            'currency' => $cur,
            'car' => $this->fmtCur($car, $cur),
            'shipping' => $this->fmtCur($shipping, $cur),
            'total' => $this->fmtCur($total, $cur),
        ];
    }

    /** 금액 → 통화기호 포맷(₩/$/€ 접두, 바이어용). null=—. */
    private function fmtCur(?int $amount, string $cur): string
    {
        if ($amount === null) {
            return '—';
        }
        $sym = ['KRW' => '₩', 'USD' => '$', 'EUR' => '€'][$cur] ?? '';

        return $sym.number_format($amount);
    }

    /**
     * 차량번호의 한글(번호판 문자)만 로마자로 — 바이어용 카드 표기. (375러1924 → 375 REO 1924)
     * 실제 vehicle_number(식별·연동B 매칭키)는 불변 — 표시용 변환만. 번호판에 안 쓰는 한글은 원문 유지.
     */
    private function vehicleRoman(string $no): string
    {
        static $map = [
            '가' => 'ga', '거' => 'geo', '고' => 'go', '구' => 'gu',
            '나' => 'na', '너' => 'neo', '노' => 'no', '누' => 'nu',
            '다' => 'da', '더' => 'deo', '도' => 'do', '두' => 'du',
            '라' => 'ra', '러' => 'reo', '로' => 'ro', '루' => 'ru',
            '마' => 'ma', '머' => 'meo', '모' => 'mo', '무' => 'mu',
            '바' => 'ba', '버' => 'beo', '보' => 'bo', '부' => 'bu',
            '사' => 'sa', '서' => 'seo', '소' => 'so', '수' => 'su',
            '아' => 'a', '어' => 'eo', '오' => 'o', '우' => 'u',
            '자' => 'ja', '저' => 'jeo', '조' => 'jo', '주' => 'ju',
            '하' => 'ha', '허' => 'heo', '호' => 'ho', '배' => 'bae',
        ];
        $out = '';
        foreach (preg_split('//u', $no, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
            $out .= isset($map[$ch]) ? ' '.strtoupper($map[$ch]).' ' : $ch;
        }

        return trim(preg_replace('/\s+/', ' ', $out));
    }

    public function photoUrl(string $path): string
    {
        $disk = config('board.photo_disk');
        if ($disk !== 's3') {
            return Storage::disk($disk)->url($path);
        }

        return Cache::remember(
            "photo_url:{$path}",
            now()->addMinutes(20),
            fn () => Storage::disk('s3')->temporaryUrl($path, now()->addMinutes(30)),
        );
    }

    /** 바이어 전달 → awaiting_buyer. buyer_name 선택(respond.io 미사용 — 영업이 카톡으로 직접 보내고 전달완료만 체크). 충돌 시 보류 + 수동 옵션. */
    public function forward(): void
    {
        $this->validate(['buyer_name' => 'nullable|string|max:100'], attributes: ['buyer_name' => __('forwarding.attr_buyer_name')]);

        // findOrFail 이 SalesmanScope 안 → 본인 글만(IDOR)
        $l = PurchaseListing::where('status', 'inspected')->findOrFail($this->detailId);
        $newName = $this->buyer_name ?: null;
        if ($l->buyer_name !== $newName) {
            $l->buyer_name = $newName;
            $l->save();
        }

        $r = app(OfferForwardService::class)->forward($l->id, $this->forceManual);

        if ($r['status'] === 'conflict') {
            $this->conflictVehicle = $r['conflict_vehicle'];

            return;
        }

        $this->reset(['detailId', 'buyer_name', 'forceManual', 'conflictVehicle']);
        unset($this->items, $this->detail);
        session()->flash('ok', __('forwarding.flash_forwarded', ['vehicle' => $l->vehicle_number]));
    }

    /** 충돌 시: 이 차를 수동 채널로 전달(자동 1대 제한 우회). */
    public function forwardManual(): void
    {
        $this->forceManual = true;
        $this->forward();
    }
}; ?>

<div class="p-3 md:p-6">
    <div class="mb-4">
        <h1 class="text-xl font-bold text-gray-800">{{ __('forwarding.title') }}</h1>
        <p class="mt-0.5 text-xs text-gray-500">{{ __('forwarding.subtitle') }}</p>
    </div>

    @if (session('ok'))
        <div class="card-sm mb-3 border-green-200 bg-green-50 text-[13px] text-green-700">✓ {{ session('ok') }}</div>
    @endif

    <div class="card">
        <div class="mb-3 flex items-center gap-2">
            <h2 class="font-bold text-gray-800">{{ __('forwarding.panel_title') }}</h2>
            <span class="pill-count">{{ __('forwarding.count', ['count' => $this->items->count()]) }}</span>
        </div>

        {{-- 데스크톱: 표 --}}
        <div class="hidden overflow-x-auto sm:block">
            <table class="tbl">
                <thead>
                    <tr><th>{{ __('forwarding.th_vehicle') }}</th><th>{{ __('forwarding.th_origin') }}</th><th>{{ __('forwarding.th_final_price') }}</th><th>{{ __('forwarding.th_inspection_note') }}</th><th style="text-align:right">{{ __('common.detail') }}</th></tr>
                </thead>
                <tbody>
                    @forelse ($this->items as $l)
                        <tr class="cursor-pointer hover:bg-gray-50" wire:click="openDetail({{ $l->id }})">
                            <td class="whitespace-nowrap align-middle">
                                <div class="font-semibold text-gray-800">{{ $l->vehicle_number }}</div>
                                <div class="text-xs text-gray-400">{{ $l->owner_name ?: '—' }}</div>
                                <div class="mt-1 flex gap-1">
                                    <span class="badge badge-green">{{ __('forwarding.badge_inspected') }}</span>
                                    @if ($l->share_photos_count > 0)
                                        <span class="badge badge-blue">📷 {{ $l->share_photos_count }}</span>
                                    @else
                                        <span class="badge badge-amber">{{ __('forwarding.badge_no_photos') }}</span>
                                    @endif
                                </div>
                            </td>
                            <td class="align-middle"><span class="badge {{ $l->originBadge() }}">{{ $l->originLabel() }}</span></td>
                            <td class="align-middle font-semibold {{ $l->final_price ? 'text-[var(--color-primary-text)]' : 'text-gray-400' }}">{{ $l->final_price ? number_format($l->final_price).__('common.won_currency') : '—' }}</td>
                            <td class="max-w-[200px] truncate align-middle text-xs text-gray-500" title="{{ $l->inspection_note }}">{{ $l->inspection_note ?: '—' }}</td>
                            <td class="whitespace-nowrap align-middle text-right font-medium text-[var(--color-primary-text)]">{{ __('common.detail') }} ›</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-8 text-center text-gray-400">{{ __('forwarding.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- 모바일: 카드 --}}
        <div class="space-y-2 sm:hidden">
            @forelse ($this->items as $l)
                <div class="card-tight cursor-pointer" wire:click="openDetail({{ $l->id }})">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <div class="font-semibold text-gray-800">{{ $l->vehicle_number }}</div>
                            <div class="text-xs text-gray-400">{{ $l->owner_name ?: '—' }}</div>
                            <div class="mt-1 flex gap-1">
                                <span class="badge badge-green">{{ __('forwarding.badge_inspected') }}</span>
                                @if ($l->share_photos_count > 0)
                                    <span class="badge badge-blue">📷 {{ $l->share_photos_count }}</span>
                                @else
                                    <span class="badge badge-amber">{{ __('forwarding.badge_no_photos') }}</span>
                                @endif
                            </div>
                        </div>
                        <div class="shrink-0 text-right">
                            <span class="badge {{ $l->originBadge() }}">{{ $l->originLabel() }}</span>
                            <div class="mt-1 text-sm font-semibold {{ $l->final_price ? 'text-[var(--color-primary-text)]' : 'text-gray-400' }}">{{ $l->final_price ? number_format($l->final_price).__('common.won_currency') : '—' }}</div>
                        </div>
                    </div>
                    @if ($l->inspection_note)
                        <div class="mt-1 truncate text-xs text-gray-500" title="{{ $l->inspection_note }}">📝 {{ $l->inspection_note }}</div>
                    @endif
                    <div class="mt-2 text-right text-xs font-medium text-[var(--color-primary-text)]">{{ __('common.detail') }} ›</div>
                </div>
            @empty
                <div class="py-8 text-center text-gray-400">{{ __('forwarding.empty') }}</div>
            @endforelse
        </div>
    </div>

    {{-- ─────────── 상세 드로어 (검차 사진/금액 읽기전용 → 전달) ─────────── --}}
    @if ($this->detail)
        @php $d = $this->detail; $q = $this->quoteData(); @endphp
        <div class="fixed inset-0 z-40 bg-black/40" wire:click="closeDetail"></div>
        <div class="fixed inset-y-0 right-0 z-50 w-full overflow-y-auto bg-white shadow-xl sm:w-[440px]">
            <div class="flex items-center justify-between border-b border-gray-200 px-5 py-4">
                <h3 class="font-bold text-gray-800">{{ $d->vehicle_number }} · {{ __('common.detail') }}</h3>
                <button class="text-gray-400 hover:text-gray-600" wire:click="closeDetail">✕</button>
            </div>

            <div class="px-5 py-4 text-sm">
                <div class="card-sm mb-3 bg-gray-50 text-xs text-gray-600">
                    <span class="badge {{ $d->originBadge() }}">{{ $d->originLabel() }}</span>
                    · {{ __('auction.salesman_label') }} <b>{{ $d->creator->name }}</b>
                </div>

                {{-- 금액 --}}
                <div class="grid grid-cols-2 gap-2 text-xs text-gray-500">
                    <div>{{ __('auction.car_cost') }}<br><b class="text-sm text-gray-800">{{ $d->carCostDisplay() }}</b></div>
                    <div>{{ __('auction.discount_rate') }}<br><b class="text-sm text-gray-800">{{ $d->discount_rate !== null ? $d->discount_rate.'%' : '—' }}</b></div>
                    <div>{{ __('auction.shipping') }}<br><b class="text-sm text-gray-800">{{ $d->shipping_usd ? '$'.number_format($d->shipping_usd) : '—' }}</b></div>
                    <div>{{ __('forwarding.th_inspection_note') }}<br><b class="text-sm text-gray-800">{{ $d->inspection_note ?: '—' }}</b></div>
                </div>
                <div class="mt-3 flex items-center justify-between rounded-md border border-[var(--color-primary)] bg-[#f5f8ff] px-3 py-2.5">
                    <span class="font-semibold text-gray-700">{{ __('auction.final_price') }}</span>
                    <span class="text-base font-bold text-[var(--color-primary-text)]">{{ $d->final_price ? number_format($d->final_price).__('common.won_currency') : '—' }}</span>
                </div>

                {{-- 견적 통화 + 카드 미리보기 (바이어에게 보낼 값). 버튼 누를 때만 offer_currency 저장 → EUR 딜 보존. --}}
                <div class="section-title-sm">{{ __('forwarding.quote_section') }}</div>
                <div class="inline-flex overflow-hidden rounded-md border border-gray-300 text-xs">
                    @foreach (['KRW' => '₩', 'USD' => '$', 'EUR' => '€'] as $cur => $sym)
                        <button type="button" wire:click="setQuoteCurrency('{{ $cur }}')"
                            class="px-3 py-1 font-semibold {{ $quoteCurrency === $cur ? 'bg-[var(--color-primary)] text-white' : 'bg-white text-gray-600' }}">{{ $sym }} {{ $cur }}</button>
                    @endforeach
                </div>
                @if ($q)
                    <div class="card-sm mt-2 space-y-1 text-sm">
                        <div class="flex justify-between"><span class="text-gray-500">{{ __('forwarding.quote_car') }}</span><span class="font-semibold text-gray-800">{{ $q['car'] }}</span></div>
                        <div class="flex justify-between"><span class="text-gray-500">{{ __('forwarding.quote_shipping') }}</span><span class="font-semibold text-gray-800">{{ $q['shipping'] }}</span></div>
                        <div class="mt-1 flex justify-between border-t border-gray-200 pt-1"><span class="font-semibold text-gray-700">{{ __('forwarding.quote_total') }}</span><span class="font-bold text-[var(--color-primary-text)]">{{ $q['total'] }}</span></div>
                    </div>
                @else
                    <p class="mt-1 text-xs text-gray-400">{{ __('forwarding.quote_unset') }}</p>
                @endif

                @if ($d->inspection_memo)
                    <div class="section-title-sm">{{ __('auction.inspection_memo') }}</div>
                    <p class="text-xs text-gray-600">{{ $d->inspection_memo }}</p>
                @endif

                <div class="section-title-sm">{{ __('auction.vehicle_photos') }}</div>
                @if ($d->photos->count())
                    <div class="grid grid-cols-4 gap-2">
                        @foreach ($d->photos as $p)
                            @php $u = $this->photoUrl($p->s3_path); @endphp
                            @if ($p->isVideo())
                                <video src="{{ $u }}" class="aspect-square w-full rounded-md object-cover" controls preload="metadata"></video>
                            @else
                                <img src="{{ $u }}" @click="$dispatch('open-lightbox', { src: '{{ $u }}' })" class="aspect-square w-full cursor-zoom-in rounded-md object-cover" alt="">
                            @endif
                        @endforeach
                    </div>
                @else
                    <p class="text-xs text-gray-400">—</p>
                @endif

                {{-- 검차사진 전체를 한 번에 공유(OS 공유시트→카톡 등). 같은출처 프록시 fetch 라 운영 S3 CORS 무관. --}}
                @php
                    $sharePhotos = $d->photos
                        ->map(fn ($p) => ['url' => route('photos.show', $p->id), 'name' => $p->original_name ?: 'photo.jpg'])->all();
                @endphp
                @if (count($sharePhotos))
                    <button type="button" class="btn-primary mt-3 w-full justify-center" x-data="{ busy: false }" :disabled="busy"
                        @click="busy = true; window.fwdShare(@js($q), @js($sharePhotos)).finally(() => busy = false)">
                        <span x-show="!busy">📤 {{ __('forwarding.share_button', ['count' => count($sharePhotos)]) }}</span>
                        <span x-show="busy" style="display:none">…</span>
                    </button>
                    <p class="mt-1 text-xs text-gray-400">{{ $q ? __('forwarding.share_hint_quote') : __('forwarding.share_hint') }}</p>
                @endif

                {{-- 바이어 + 전달완료 체크 --}}
                <div class="section-title-sm">{{ __('forwarding.forward_section') }}</div>
                <input class="input-base" wire:model="buyer_name" placeholder="{{ __('forwarding.buyer_placeholder') }}" maxlength="100">
                @error('buyer_name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                @if ($conflictVehicle)
                    <div class="card-sm mt-2 border-amber-300 bg-amber-50 text-amber-800">
                        <p class="text-[13px] font-semibold">⚠️ {{ __('forwarding.conflict_title', ['vehicle' => $conflictVehicle]) }}</p>
                        <p class="mt-0.5 text-xs">{{ __('forwarding.conflict_desc') }}</p>
                        <button type="button" class="btn-primary btn-sm mt-2 w-full justify-center" wire:click="forwardManual">{{ __('forwarding.conflict_manual') }}</button>
                    </div>
                @else
                    <button class="btn-primary mt-2 w-full justify-center" wire:click="forward">✅ {{ __('forwarding.forward_button') }}</button>
                    <p class="mt-1 text-xs text-gray-400">{{ __('forwarding.forward_hint') }}</p>
                @endif
            </div>
        </div>
    @endif

    {{-- 견적 카드(캔버스) + 검차사진을 OS 공유시트(카톡/왓츠앱)로 한 번에.
         사진 URL=같은출처 프록시(photos.show)라 운영 S3 CORS 무관. 카드는 클라에서 그려 맨 앞에 붙임. --}}
    <script>
        // 견적 카드 한 장(PNG) — 헤더 QUOTATION(견적서) + 차량(로마자) + 차값/배송/최종 3줄(영업이 고른 통화, EN 라벨).
        async function buildQuoteCard(q) {
            const W = 1000, H = 620, s = 2;
            const c = document.createElement('canvas');
            c.width = W * s; c.height = H * s;
            const ctx = c.getContext('2d');
            ctx.scale(s, s);
            ctx.textBaseline = 'middle';

            ctx.fillStyle = '#ffffff'; ctx.fillRect(0, 0, W, H);
            ctx.fillStyle = '#7c6fcd'; ctx.fillRect(0, 0, W, 110);
            ctx.fillStyle = '#ffffff'; ctx.font = 'bold 52px sans-serif';
            ctx.fillText('QUOTATION', 48, 58);

            ctx.fillStyle = '#6b7280'; ctx.font = '26px sans-serif';
            ctx.fillText('Vehicle', 48, 180);
            ctx.fillStyle = '#111827'; ctx.font = 'bold 44px sans-serif';
            ctx.fillText(q.vehicle, 48, 230);

            ctx.font = '34px sans-serif';
            let y = 330;
            for (const [label, val] of [['Car Price', q.car], ['Shipping', q.shipping]]) {
                ctx.fillStyle = '#374151'; ctx.textAlign = 'left'; ctx.fillText(label, 48, y);
                ctx.fillStyle = '#111827'; ctx.textAlign = 'right'; ctx.fillText(val, W - 48, y);
                y += 72;
            }
            ctx.strokeStyle = '#e5e7eb'; ctx.lineWidth = 2;
            ctx.beginPath(); ctx.moveTo(48, y - 36); ctx.lineTo(W - 48, y - 36); ctx.stroke();
            ctx.font = 'bold 42px sans-serif';
            ctx.fillStyle = '#111827'; ctx.textAlign = 'left'; ctx.fillText('Total', 48, y + 16);
            ctx.fillStyle = '#7c6fcd'; ctx.textAlign = 'right'; ctx.fillText(q.total, W - 48, y + 16);
            ctx.textAlign = 'left';

            const blob = await new Promise((res) => c.toBlob(res, 'image/png'));
            return new File([blob], 'ssancar-quote.png', { type: 'image/png' });
        }

        window.fwdShare = async function (quote, photos) {
            try {
                const files = [];
                if (quote) {
                    files.push(await buildQuoteCard(quote));   // 견적 카드를 맨 앞에
                }
                for (const p of photos) {
                    const r = await fetch(p.url);
                    if (!r.ok) throw new Error('fetch_failed');
                    const b = await r.blob();
                    files.push(new File([b], p.name, { type: b.type || 'image/jpeg' }));
                }
                if (navigator.canShare && navigator.canShare({ files })) {
                    await navigator.share({ files });
                    return;
                }
                alert('이 브라우저는 다중 사진 공유를 지원하지 않습니다. 사진을 길게 눌러 하나씩 공유해 주세요.');
            } catch (e) {
                if (e && e.name === 'AbortError') return;   // 사용자가 공유 취소
                alert('공유에 실패했습니다. 사진을 길게 눌러 하나씩 공유해 주세요.');
            }
        };
    </script>
</div>

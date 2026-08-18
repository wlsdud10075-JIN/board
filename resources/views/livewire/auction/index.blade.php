<?php

use App\Jobs\SyncWonListingToCarErp;
use App\Models\InspectionPhoto;
use App\Models\PurchaseListing;
use App\Services\ExchangeRateService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('components.layouts.app')] class extends Component {
    use WithFileUploads;

    public ?int $detailId = null;

    // 딜러 차량 첨부 (매입 후 딜러에게 받은 사진·서류 → 낙찰 시 연동 B 로 car-erp 첨부탭). 이미지=사진/그 외=서류.
    public array $salesFiles = [];

    // 소유자/차주명 (연동 B: car-erp NICE 조회 입력값) — 매입예정에서 미리 입력, 여기서 보정.
    public string $owner_name = '';

    // 매입 정산 입금정보 (§6e) — 판매자/경매장 계좌. won 단계 입력 → 연동 B 전달.
    public string $payee_name = '';
    public string $payee_bank = '';
    public string $payee_account = '';
    // 매도비 계좌 (판매자와 다른 대상, 영업 직접입력) — 매입가 계좌와 별개
    public string $selling_fee_payee_name = '';
    public string $selling_fee_payee_bank = '';
    public string $selling_fee_payee_account = '';

    /**
     * 차값 — **셀프검차매입이 금액을 넣을 수 있는 유일한 지점**(2026-08-10 Jin).
     * 셀프검차는 등록 즉시 accepted 라 `/inspection`(최종금액)·`/forwarding`(견적)을 둘 다 건너뛰고,
     * `/listings` 등록폼엔 차값 입력칸이 없다(링크 자동채움 전용) → 링크에서 가격을 못 받으면 영영 null 이었다.
     * 그 상태로 won 이 되면 연동 B payload 의 purchase_price_krw·final_price 가 둘 다 null 이라
     * car-erp 가 `required_without` 로 **422** 를 낸다(2026-08-10 heymanboard 67도4322 실측).
     */
    public ?string $car_cost = null;

    /**
     * 견적 금액 나머지 — `/forwarding` 과 **같은 칸·같은 공식**(`totalKrw`)을 쓴다(2026-08-10 Jin 선택).
     * 바이어 금액(현지 최종금액)을 여기서 직접 타이핑하지 않는 이유 = 그러면 할인·차감액과 숫자가 갈린다.
     */
    public ?string $discount_rate = null;

    public ?string $sale_discount = null;   // 차감액(KRW 절대금액 — Model A sell-side)

    public ?string $shipping_usd = null;

    // ── 셀프검차매입 전용 (2026-08-10 Jin) — 검차·견적 씬이 없어 파생계산의 근거가 없다.
    //    영업이 이미 아는 값을 그대로 적는다: 차값 · 매도비 · 판매가 · 통화 · 환율 · 운임비.
    /** 매도비 — 셀프검차는 **차값에 포함**된 금액이라 따로 적어야 분리된다(모델이 매입가에서 뺀다). */
    public ?string $selling_fee = null;

    /** 판매가 — `offer_currency` 기준 raw(원화 아님). 파생계산 대신 이 값을 그대로 ERP 로 보낸다. */
    public ?string $sale_price = null;

    /** 환율 — 셀프검차는 영업이 직접 적는다(다른 출처는 통화 바꿀 때 자동 스냅). */
    public ?string $offer_rate = null;

    /** 운임비 — **판매통화 기준**(USD 아님). 기존 `shipping_usd` 와 컬럼이 다르다 — SKILLS §14-5. */
    public ?string $transport_fee = null;


    /** 견적 통화 — 드로어 열 때 offer_currency 표시, 저장 시 바뀐 경우만 재스냅(EUR 딜 보존). */
    public string $quoteCurrency = 'KRW';

    public int $krwPerUsd = 0;

    public int $krwPerEur = 0;

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

    /** 통화별 오늘 환율(KRW 는 1) — 셀프검차 환율칸 기본값. */
    private function rateFor(string $cur): int
    {
        return match ($cur) {
            'USD' => $this->usdRate(),
            'EUR' => $this->eurRate(),
            default => 1,
        };
    }

    // v3 — car-erp 바이어/컨사이니 (드롭다운 선택 → 연동B buyer_id/consignee_id). 본인 스코프.
    public ?int $buyerId = null;
    public ?int $consigneeId = null;
    public array $buyerOpts = [];
    public array $consigneeOpts = [];

    private function salesmanEmail(): string
    {
        // 바이어/컨사이니는 '딜 작성자(영업)'의 car-erp 스코프로 조회 — 운영자(관리자 대행)가 아닌 작성자 기준.
        // car-erp /buyers 는 본인격리(IDOR)라, 작성자 기준이어야 그 영업의 바이어가 뜬다.
        // 또 연동 B 송신의 salesman_email(작성자 기준)과 일치 → 교차-FK 오배정 차단.
        $creator = $this->detail?->creator;

        return $creator?->car_erp_salesman_email ?: ($creator?->email ?? '');
    }

    private function loadBuyers(): void
    {
        $r = app(\App\Services\CarErpReadService::class)->buyers($this->salesmanEmail());
        $this->buyerOpts = $r['ok'] ? (array) ($r['data']['data'] ?? []) : [];
    }

    private function loadConsignees(): void
    {
        if (! $this->buyerId) {
            $this->consigneeOpts = [];

            return;
        }
        $r = app(\App\Services\CarErpReadService::class)->consignees($this->salesmanEmail(), $this->buyerId);
        $this->consigneeOpts = $r['ok'] ? (array) ($r['data']['data'] ?? []) : [];
    }

    /**
     * 매입 등록 락 표시정보 — car-erp `GET /buyers` 가 동봉하는 판정(§4-0)을 **그대로** 읽는다.
     * 조건을 board 에 옮겨 적지 않는다: 갈리면 영업이 board 에서 "가능"을 보고 돈을 쓴 뒤 ERP 에서 막힌다.
     *
     * 🚫 `reference`(available_krw 등)는 **절대 같이 그리지 않는다** — ratio 모드에서 락과 분모·분자가 달라
     *    "여력 0원인데 등록 가능"·"락인데 여력 1천만"이 둘 다 정상으로 나온다. 근거는 `basis` 하나뿐이다.
     *
     * @return array{locked:bool,kind:string,current:float,limit:float}|null null = 표시 안 함(토글 OFF·근거 없음)
     */
    public function buyerLock(?int $id = null): ?array
    {
        $id = $id ?? $this->buyerId;
        if (! $id) {
            return null;
        }
        foreach ($this->buyerOpts as $b) {
            if ((int) ($b['id'] ?? 0) !== (int) $id) {
                continue;
            }
            $mode = (string) data_get($b, 'purchase_lock.mode', 'off');
            $kind = data_get($b, 'purchase_lock.basis.kind');
            // 토글 OFF·판정근거 없음(신규 바이어 등)은 아무것도 안 그린다 — 빈 배지가 더 헷갈린다.
            if ($mode === 'off' || $kind === null) {
                return null;
            }

            return [
                'locked' => (bool) data_get($b, 'purchase_locked', false),
                'kind' => (string) $kind,
                // ⚠️ JSON 이 20.0 을 20 으로 주므로 정수·실수가 섞인다 → 숫자로만 다룬다.
                'current' => (float) data_get($b, 'purchase_lock.basis.current', 0),
                'limit' => (float) data_get($b, 'purchase_lock.basis.limit', 0),
            ];
        }

        return null;
    }

    /**
     * 구매확정 시점 재조회 — 드로어를 열어둔 사이 ERP 에서 한도가 풀렸을 수 있고, 반대로 걸렸을 수도 있다.
     * ⚠️ 조회가 degrade(ERP 다운·미설정)면 **막지 않는다** — 지금과 같은 동작을 유지한다.
     *    여기서 막으면 ERP 장애가 board 의 매입 마감을 통째로 세운다.
     */
    private function buyerLockedNow(int $buyerId): bool
    {
        $r = app(\App\Services\CarErpReadService::class)->buyers($this->salesmanEmail());
        if (! ($r['ok'] ?? false)) {
            return false;
        }
        foreach ((array) data_get($r['data'], 'data', []) as $b) {
            if ((int) ($b['id'] ?? 0) === $buyerId) {
                return (bool) data_get($b, 'purchase_locked', false);
            }
        }

        return false;
    }

    /**
     * 구매확정을 막아야 할 이유 — 없으면 null. **버튼 비활성 사유이자 서버 차단 사유**(같은 판정을 두 곳에서 쓴다).
     *
     * 2026-08-10 Jin: 바이어는 **필수**, 락 걸린 바이어는 **버튼 자체가 안 눌리게**.
     * 바이어를 안 고르면 연동 B `buyer_id` 가 null 로 나가 **락 판정 자체가 성립하지 않는다** —
     * 즉 "안 고르면 통과"가 되어 락에 구멍이 남는다. 그래서 필수화가 락의 전제다.
     */
    public function purchaseBlockReason(): ?string
    {
        if (! $this->buyerId) {
            return __('auction.err_buyer_required');
        }
        $lock = $this->buyerLock();

        return ($lock && $lock['locked']) ? __('auction.err_buyer_purchase_locked') : null;
    }

    /** 바이어 변경 시 컨사이니 목록 갱신 + 선택 초기화. */
    public function updatedBuyerId(): void
    {
        $this->buyerId = $this->buyerId ?: null;
        $this->consigneeId = null;
        $this->loadConsignees();
    }

    private function payeeRules(bool $selfInspection = false): array
    {
        $base = [
            'owner_name' => 'nullable|string|max:60',
            'payee_name' => 'nullable|string|max:60',
            'payee_bank' => 'nullable|string|max:40',
            'payee_account' => 'nullable|string|max:40',
            'selling_fee_payee_name' => 'nullable|string|max:60',
            'selling_fee_payee_bank' => 'nullable|string|max:40',
            'selling_fee_payee_account' => 'nullable|string|max:40',
            'car_cost' => 'nullable|numeric|min:0',
            'salesFiles.*' => 'file|max:204800',
        ];

        // 셀프검차매입은 운임비를 직접 적는다 → 고정 선택지(config)로 묶지 않는다.
        if ($selfInspection) {
            return $base + [
                // 매도비는 차값에 포함된 금액이라 차값을 넘을 수 없다 — 넘으면 매입가가 0 으로 깎여
                // **매입가 0원짜리 차**가 조용히 ERP 원장에 생긴다(car-erp 검증도 min:0 이라 통과).
                // ⚠️ 차값이 비었을 때는 걸지 않는다 — 안 그러면 "차값을 넣으세요" 대신 "매도비가 차값보다 큽니다"가 떠서
                //    영업이 엉뚱한 칸을 고치게 된다(진짜 원인은 차값 누락이고 그건 아래 금액 게이트가 잡는다).
                'selling_fee' => $this->car_cost !== null && $this->car_cost !== ''
                    ? 'nullable|numeric|min:0|lte:car_cost'
                    : 'nullable|numeric|min:0',
                'sale_price' => 'nullable|numeric|min:0',
                'offer_rate' => 'nullable|numeric|min:1',
                'transport_fee' => 'nullable|numeric|min:0',
            ];
        }

        return $base + [
            'discount_rate' => 'nullable|numeric|min:0|max:100',
            'sale_discount' => 'nullable|numeric|min:0',
            'shipping_usd' => 'nullable|integer|in:'.implode(',', config('board.shipping_options')),
        ];
    }

    /**
     * 연동 B 가 요구하는 최소 금액이 있는가 — car-erp 수신측 `final_price: required_without:purchase_price_krw`.
     * 둘 다 비면 422 다. 여기서 막지 않으면 영업은 구매확정을 끝냈다고 보는데 ERP 엔 아무것도 안 생긴다.
     */
    private function hasSyncableAmount(PurchaseListing $l): bool
    {
        return $l->car_cost !== null || $l->final_price !== null;
    }

    /** 첨부 사전검증 — 실행파일 차단 + 최대건수(car-erp 첨부탭 cap). 통과=true. */
    private function checkSalesFiles(int $existing): bool
    {
        $files = array_values(array_filter($this->salesFiles));
        if (empty($files)) {
            return true;
        }
        foreach ($files as $f) {
            if (\App\Support\UploadGuard::isExecutable($f->getClientOriginalName())) {
                $this->addError('salesFiles', __('auction.attach.exec_error', ['name' => $f->getClientOriginalName()]));

                return false;
            }
        }
        $max = (int) config('board.attachment_max');
        if ($existing + count($files) > $max) {
            $this->addError('salesFiles', __('auction.attach.max_error', ['max' => $max, 'existing' => $existing]));

            return false;
        }

        return true;
    }

    /** 첨부 저장 — 이미지=사진(sales_photo)/그 외=서류(sales_document). 서류는 바이어 전송 금지(§28). */
    private function storeSalesFiles(PurchaseListing $l): void
    {
        $files = array_values(array_filter($this->salesFiles));
        if (empty($files)) {
            return;
        }
        $disk = config('board.photo_disk');
        $sort = (int) $l->salesAttachments()->max('sort');
        foreach ($files as $f) {
            $isImage = str_starts_with((string) $f->getMimeType(), 'image/');
            $prefix = $isImage ? config('board.sales_photo_prefix') : config('board.sales_document_prefix');
            $path = $f->store($prefix.'/'.$l->id, $disk);
            $l->salesAttachments()->create([
                's3_path' => $path,
                'original_name' => $f->getClientOriginalName(),
                'sort' => ++$sort,
                'kind' => $isImage ? InspectionPhoto::KIND_SALES_PHOTO : InspectionPhoto::KIND_SALES_DOCUMENT,
                'uploaded_by_user_id' => \Illuminate\Support\Facades\Auth::id(),
                'share_to_buyer' => false,
            ]);
        }
        $this->salesFiles = [];
    }

    /** 저장 전 선택파일 빼기. */
    public function removeSalesFile(int $i): void
    {
        unset($this->salesFiles[$i]);
        $this->salesFiles = array_values($this->salesFiles);
    }

    /** 첨부 삭제(파일+행). */
    public function deleteSalesAttachment(int $id): void
    {
        $l = PurchaseListing::findOrFail($this->detailId);
        $p = $l->salesAttachments()->whereKey($id)->firstOrFail();
        Storage::disk(config('board.photo_disk'))->delete($p->s3_path);
        $p->delete();
        unset($this->detail);
    }

    #[Computed]
    public function listings()
    {
        return PurchaseListing::with('creator')
            ->whereIn('status', ['accepted', 'won', 'failed'])
            ->latest()
            ->get();
    }

    #[Computed]
    public function detail(): ?PurchaseListing
    {
        return $this->detailId ? PurchaseListing::with(['creator', 'photos', 'salesAttachments'])->find($this->detailId) : null;
    }

    public function openDetail(int $id): void
    {
        $this->detailId = $id;
        $l = PurchaseListing::findOrFail($id);
        $this->owner_name = $l->owner_name ?? '';
        $this->payee_name = $l->payee_name ?? '';
        $this->payee_bank = $l->payee_bank ?? '';
        $this->payee_account = $l->payee_account ?? '';
        $this->selling_fee_payee_name = $l->selling_fee_payee_name ?? '';
        $this->selling_fee_payee_bank = $l->selling_fee_payee_bank ?? '';
        $this->selling_fee_payee_account = $l->selling_fee_payee_account ?? '';
        $this->car_cost = $l->car_cost !== null ? (string) $l->car_cost : null;
        $this->discount_rate = $l->discount_rate !== null ? (string) $l->discount_rate : null;
        $this->sale_discount = $l->sale_discount_amount !== null ? (string) $l->sale_discount_amount : null;
        $this->shipping_usd = $l->shipping_usd !== null ? (string) $l->shipping_usd : null;
        // 셀프검차는 통화를 **미선택으로 시작**한다(2026-08-10 Jin). KRW 를 미리 골라두면 USD 판매가를 적고
        // 통화를 안 눌러도 그대로 통과해 ERP 에 **1/환율 금액**으로 박힌다(8,590 USD → 8,590원, 실측 확인).
        $this->quoteCurrency = $l->offer_currency ?: ($l->isSelfInspection() ? '' : 'KRW');
        // 매도비 기본값 = 기존 고정값(대부분 그대로라 미리 채워 입력을 줄인다 — 2026-08-10 Jin).
        $this->selling_fee = (string) ($l->selling_fee ?? (int) config('board.sales_fee'));
        $this->sale_price = $l->sale_price !== null ? (string) (0 + $l->sale_price) : null;
        $this->transport_fee = $l->transport_fee !== null ? (string) (0 + $l->transport_fee) : null;
        // 셀프검차는 환율을 **미리 채우지 않는다** — '1' 이 들어가 있으면 USD 를 골라도 그대로 통과해
        // ERP 에 exchange_rate=1 로 박힌다(통화 락을 통과한 뒤 생기는 두 번째 구멍). 빈 칸이어야 락이 잡는다.
        $this->offer_rate = $l->offer_rate !== null
            ? (string) $l->offer_rate
            : ($l->isSelfInspection() ? null : (string) $this->rateFor($this->quoteCurrency));
        $this->buyerId = $l->car_erp_buyer_id;
        $this->consigneeId = $l->car_erp_consignee_id;
        $this->loadBuyers();
        $this->loadConsignees();
        $this->resetErrorBag();
    }

    public function closeDetail(): void
    {
        $this->reset(['detailId', 'owner_name', 'payee_name', 'payee_bank', 'payee_account',
            'selling_fee_payee_name', 'selling_fee_payee_bank', 'selling_fee_payee_account',
            'car_cost', 'discount_rate', 'sale_discount', 'shipping_usd', 'quoteCurrency',
            'selling_fee', 'sale_price', 'offer_rate', 'transport_fee',
            'buyerId', 'consigneeId', 'buyerOpts', 'consigneeOpts', 'salesFiles']);
        unset($this->detail);
    }

    private function applyPayee(PurchaseListing $l): void
    {
        $l->owner_name = $this->owner_name ?: null;
        $l->payee_name = $this->payee_name ?: null;
        $l->payee_bank = $this->payee_bank ?: null;
        $l->payee_account = $this->payee_account ?: null;
        $l->selling_fee_payee_name = $this->selling_fee_payee_name ?: null;
        $l->selling_fee_payee_bank = $this->selling_fee_payee_bank ?: null;
        $l->selling_fee_payee_account = $this->selling_fee_payee_account ?: null;
        $l->car_erp_buyer_id = $this->buyerId ?: null;
        $l->car_erp_consignee_id = $this->consigneeId ?: null;
        // 차값 — 통화는 등록 시 정해진 `expected_price_currency` 그대로(여기선 금액만 보정).
        $l->car_cost = ($this->car_cost === null || $this->car_cost === '') ? null : (int) $this->car_cost;
        $l->shipping_usd = ($this->shipping_usd === null || $this->shipping_usd === '') ? null : (int) $this->shipping_usd;

        if ($l->isSelfInspection()) {
            // 셀프검차매입 — 견적 씬이 없어 파생계산의 근거가 없다. 적은 값을 그대로 쓴다.
            // 차값·매도비 = 항상 KRW. 판매가·환율·운임비 = 선택한 견적통화 기준.
            $l->selling_fee = ($this->selling_fee === null || $this->selling_fee === '') ? null : (int) $this->selling_fee;
            $l->sale_price = ($this->sale_price === null || $this->sale_price === '') ? null : (float) $this->sale_price;
            $l->transport_fee = ($this->transport_fee === null || $this->transport_fee === '') ? null : (float) $this->transport_fee;
            $l->shipping_usd = null;   // 셀프검차는 USD 선택형을 안 쓴다 — 두 값이 같이 있으면 어느 게 진짜인지 갈린다
            $l->offer_currency = $this->quoteCurrency ?: null;   // 미선택은 null 로 둔다('' 저장 금지)
            // ⚠️ **자동계산 없음**(2026-08-10 Jin) — 셀프검차 금액칸은 전부 "적은 값 그대로"다.
            //    통화를 바꿔도 환율을 다시 잡지 않고, 판매가×환율로 final_price 를 만들지도 않는다.
            //    빈 칸일 때만 KRW=1 폴백 — car-erp 는 exchange_rate>0 이어야 판매 pre-fill 을 저장한다.
            $l->offer_rate = ($this->offer_rate === null || $this->offer_rate === '') ? $this->rateFor($this->quoteCurrency) : (int) $this->offer_rate;

            return;
        }

        $l->discount_rate = ($this->discount_rate === null || $this->discount_rate === '') ? null : (float) $this->discount_rate;
        $l->sale_discount_amount = ($this->sale_discount === null || $this->sale_discount === '') ? null : (int) $this->sale_discount;

        // 견적통화는 **바뀐 경우에만** 재스냅 — 안 그러면 EUR 딜의 확정환율이 저장할 때마다 오늘 환율로 덮인다(/forwarding 동일).
        if ($this->quoteCurrency !== ($l->offer_currency ?: 'KRW')) {
            $l->offer_currency = $this->quoteCurrency;
            $l->offer_rate = $this->rateFor($this->quoteCurrency);
        }

        // 바이어 금액(현지 최종금액) = 기존 공식 그대로. 직접 타이핑받지 않는 이유 = 할인·차감액과 숫자가 갈린다.
        // 차값이 비면 null 이 나오므로 기존 final_price 를 유지한다(/forwarding 과 같은 처리).
        $l->final_price = $l->totalKrw($this->usdRate(), $this->eurRate()) ?? $l->final_price;
    }

    /** 입금정보·첨부 저장(이미 won 인 차량 보정용). */
    public function savePayee(): void
    {
        $l = PurchaseListing::findOrFail($this->detailId);
        $this->validate($this->payeeRules($l->isSelfInspection()));
        if (! $this->checkSalesFiles($l->salesAttachments()->count())) {
            return;
        }
        $this->applyPayee($l);
        $l->save();
        $this->storeSalesFiles($l);
        unset($this->detail, $this->listings);

        // 이미 won 인데 ERP 로 못 넘어간 차(금액 누락 422)를 여기서 금액만 채우면 재발사되게 한다.
        // 안 그러면 영업이 금액을 넣어도 아무 일도 안 일어나고, 재전송은 super 전용(/manage)이라 손이 없다.
        // 가드는 Job 안에도 있고(won + car_erp_vehicle_id null) car-erp 는 vehicle_number 로 멱등이라 중복 없음.
        if ($l->status === 'won' && $l->car_erp_vehicle_id === null && $this->hasSyncableAmount($l)) {
            SyncWonListingToCarErp::dispatch($l->id);
            session()->flash('ok', __('auction.flash_resent', ['no' => $l->vehicle_number]));

            return;
        }
        session()->flash('ok', __('auction.flash_payee_saved'));
    }

    public function photoUrl(string $path): string
    {
        $disk = config('board.photo_disk');
        if ($disk !== 's3') {
            return Storage::disk($disk)->url($path);
        }

        // presigned URL — 렌더링마다 재서명되면 영상 재생이 리셋되므로 캐시로 문자열 고정 (TTL < 만료)
        return Cache::remember(
            "photo_url:{$path}",
            now()->addMinutes(20),
            fn () => Storage::disk('s3')->temporaryUrl($path, now()->addMinutes(30)),
        );
    }

    public function conclude(int $id, string $result): void
    {
        if (! in_array($result, ['won', 'failed'], true)) {
            return;
        }

        $l = PurchaseListing::findOrFail($id);
        if ($l->status !== 'accepted') {
            session()->flash('err', __('auction.flash_only_accepted'));

            return;
        }

        if ($result === 'won') {
            $this->validate($this->payeeRules($l->isSelfInspection()));
            if (! $this->checkSalesFiles($l->salesAttachments()->count())) {
                return;
            }
            $this->applyPayee($l);   // 낙찰/구매확정 시 입금정보·차값 함께 저장
            // 금액이 없으면 여기서 세운다 — 통과시키면 won 은 되는데 연동 B 가 car-erp 에서 422 로 죽고,
            // 영업 화면엔 "처리 완료"만 뜬다(2026-08-10 67도4322 실측). 조용한 실패보다 여기서 막는 게 낫다.
            if (! $this->hasSyncableAmount($l)) {
                $this->addError('car_cost', __('auction.err_amount_required'));

                return;
            }
            // 셀프검차 필수 락 (2026-08-10 Jin) — 차값은 위 게이트가 잡고, 판매가는 여기서.
            // 판매가가 비면 car-erp 가 판매 pre-fill 을 통째로 보류해(수신측 `sale_price>0 && rate>0`)
            // ERP 판매탭이 빈 채로 생긴다 — 관리가 나중에 손으로 채워야 하고, 그때 board 값과 갈린다.
            if ($l->isSelfInspection() && $l->sale_price === null) {
                $this->addError('sale_price', __('auction.err_sale_price_required'));

                return;
            }
            // 통화 미선택 락 — 안 막으면 KRW 로 떨어져 **USD 판매가가 원화로 박힌다**(8,590 USD → 8,590원, 실측).
            // 조용히 1/환율 로 기록되므로 나중에 원장을 봐도 틀린 줄을 모른다.
            if ($l->isSelfInspection() && ! $l->offer_currency) {
                $this->addError('quoteCurrency', __('auction.err_currency_required'));

                return;
            }
            // 원화가 아니면 환율도 필수 — 비우면 **오늘 환율**이 조용히 들어가 합의환율과 갈린다.
            if ($l->isSelfInspection() && $l->offer_currency !== 'KRW' && ($this->offer_rate === null || $this->offer_rate === '')) {
                $this->addError('offer_rate', __('auction.err_rate_required'));

                return;
            }

            // 매입 등록 락 (§4-0) — 연동 B 는 car-erp 저장 게이트를 안 타므로 **여기가 유일한 상류 차단점**이다.
            // 바이어 필수 (2026-08-10 Jin): 안 고르면 buyer_id 가 null 로 나가 **락 판정 자체가 성립하지 않는다**
            // = "안 고르면 통과". 그래서 필수화가 락의 전제다. 금액 검사 뒤에 두는 이유 = 금액 오류를 가리지 않기 위해.
            if (! $this->buyerId) {
                $this->addError('buyerId', __('auction.err_buyer_required'));

                return;
            }

            // 락 조회는 **ERP 호출**이라 맨 마지막에 둔다 — 폼이 이미 틀렸으면 부를 이유가 없다.
            // 락은 절대 규칙이 아니다(ERP 에서 사유를 적으면 통과) → "불가"가 아니라 "관리자 승인 필요".
            // 화면 버튼도 같이 비활성이지만 서버에서 한 번 더 본다(드로어를 열어둔 사이 바뀔 수 있고,
            // Livewire 액션은 직접 호출될 수 있다).
            if ($this->buyerLockedNow((int) $this->buyerId)) {
                $this->addError('buyerId', __('auction.err_buyer_purchase_locked'));

                return;
            }

            // 딜러 첨부 필수 (2026-08-18 Jin) — 첨부 없이 확정하면 ERP 첨부탭이 빈 채로 생긴다.
            // 🚫 "확정은 하되 전송만 보류"는 안 쓴다 — 연동 B 는 won 진입 시 **1회 발사**라 자동 재시도가 없고,
            //    사람이 재전송을 안 누르면 **그 차는 영영 ERP 에 없다**(한참 뒤에 발견 = 최악).
            //    여기서 막으면 놓칠 수가 없다. 이번에 올리는 파일도 대상에 넣는다(그 자리 업로드가 정상 흐름).
            $attachCount = $l->salesAttachments()->count() + count(array_filter($this->salesFiles));
            if ($attachCount === 0) {
                $this->addError('salesFiles', __('auction.err_attachment_required'));

                return;
            }
        }

        // ⚠️ 첨부를 **status 저장보다 먼저** 넣는다 — `won` 저장이 모델 훅에서 연동 B 를 발사하는데,
        //    로컬(QUEUE_CONNECTION=sync)은 그 자리에서 즉시 실행돼 **이번에 올린 파일이 payload 에서 빠진다**.
        //    운영(database 큐)은 워커가 늦게 집어 우연히 실리던 것 = 타이밍에 기대는 구조였다(2026-08-18 수정).
        if ($result === 'won') {
            $this->storeSalesFiles($l);   // 딜러 첨부(사진·서류) 저장 → 연동 B 로 car-erp 전달
        }
        $l->status = $result;
        $l->save();
        $this->reset(['detailId', 'owner_name', 'payee_name', 'payee_bank', 'payee_account',
            'selling_fee_payee_name', 'selling_fee_payee_bank', 'selling_fee_payee_account',
            'buyerId', 'consigneeId', 'buyerOpts', 'consigneeOpts', 'salesFiles']);
        unset($this->listings, $this->detail);
        session()->flash('ok', __('auction.flash_processed', ['no' => $l->vehicle_number, 'label' => $l->statusLabel()]));
    }
}; ?>

<div class="p-3 md:p-6">
    <div class="mb-4">
        <h1 class="text-xl font-bold text-gray-800">{{ __('auction.title') }}</h1>
        <p class="mt-0.5 text-xs text-gray-500">{!! __('auction.subtitle') !!}</p>
    </div>

    @if (session('ok'))
        <div class="card-sm mb-3 border-green-200 bg-green-50 text-[13px] text-green-700">✓ {{ session('ok') }}</div>
    @endif
    @if (session('err'))
        <div class="card-sm mb-3 border-red-200 bg-red-50 text-[13px] text-red-700">⚠ {{ session('err') }}</div>
    @endif

    <div class="card">
        <div class="mb-3 flex items-center gap-2">
            <h2 class="font-bold text-gray-800">{{ __('auction.panel_title') }}</h2>
            <span class="pill-count">{{ __('auction.accepted_count', ['count' => $this->listings->where('status', 'accepted')->count()]) }}</span>
        </div>

        {{-- 데스크톱: 표 --}}
        <div class="hidden overflow-x-auto sm:block">
            <table class="tbl">
                <thead>
                    <tr><th>{{ __('auction.col_vehicle') }}</th><th>{{ __('auction.col_source') }}</th><th>{{ __('auction.col_salesman') }}</th><th>{{ __('auction.col_final_price') }}</th><th>{{ __('auction.col_process') }}</th></tr>
                </thead>
                <tbody>
                    @forelse ($this->listings as $l)
                        <tr class="cursor-pointer hover:bg-gray-50" wire:click="openDetail({{ $l->id }})">
                            <td class="font-semibold text-gray-800">{{ $l->vehicle_number }}</td>
                            <td><span class="badge {{ $l->isAuction() ? 'badge-auction' : 'badge-encar' }}">{{ $l->isAuction() ? __('domain.source.auction') : __('domain.source.encar') }}</span></td>
                            <td class="text-gray-600">{{ $l->creator->name }}</td>
                            <td class="font-semibold text-[var(--color-primary-text)]">{{ $l->offerDisplay() ?? '—' }}</td>
                            <td>
                                @if ($l->status === 'accepted')
                                    <span class="badge badge-amber">{{ __('auction.pending_click') }}</span>
                                @else
                                    <span class="badge {{ $l->statusBadge() }}">{{ $l->statusLabel() }} ✓</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-8 text-center text-gray-400">{{ __('auction.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- 모바일: 카드 --}}
        <div class="space-y-2 sm:hidden">
            @forelse ($this->listings as $l)
                <div class="card-tight cursor-pointer" wire:click="openDetail({{ $l->id }})">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <div class="font-semibold text-gray-800">{{ $l->vehicle_number }}</div>
                            <div class="text-xs text-gray-400">{{ __('auction.salesman_label') }} {{ $l->creator->name }}</div>
                        </div>
                        <span class="badge {{ $l->isAuction() ? 'badge-auction' : 'badge-encar' }} shrink-0">{{ $l->isAuction() ? __('domain.source.auction') : __('domain.source.encar') }}</span>
                    </div>
                    <div class="mt-2 flex items-center justify-between gap-2">
                        @if ($l->status === 'accepted')
                            <span class="badge badge-amber">{{ __('auction.pending_tap') }}</span>
                        @else
                            <span class="badge {{ $l->statusBadge() }}">{{ $l->statusLabel() }} ✓</span>
                        @endif
                        <span class="shrink-0 text-sm font-semibold text-[var(--color-primary-text)]">{{ $l->offerDisplay() ?? '—' }}</span>
                    </div>
                </div>
            @empty
                <div class="py-8 text-center text-gray-400">{{ __('auction.empty') }}</div>
            @endforelse
        </div>
        <p class="mt-2 text-xs text-gray-400">{{ __('auction.row_click_hint') }}</p>
    </div>

    {{-- ─────────── 상세 드로어 (읽기전용 + 집행) ─────────── --}}
    @if ($this->detail)
        @php $d = $this->detail; @endphp
        <div class="fixed inset-0 z-40 bg-black/40" wire:click="closeDetail"></div>
        <div class="fixed inset-y-0 right-0 z-50 w-full overflow-y-auto bg-white shadow-xl sm:w-[440px]">
            <div class="flex items-center justify-between border-b border-gray-200 px-5 py-4">
                <h3 class="font-bold text-gray-800">{{ $d->vehicle_number }} · {{ __('common.detail') }}
                    <span class="badge {{ $d->statusBadge() }} ml-1">{{ $d->statusLabel() }}</span>
                </h3>
                <button class="text-gray-400 hover:text-gray-600" wire:click="closeDetail">✕</button>
            </div>

            <div class="px-5 py-4 text-sm">
                <div class="card-sm mb-3 bg-gray-50 text-xs text-gray-600">
                    <span class="badge {{ $d->isAuction() ? 'badge-auction' : 'badge-encar' }}">{{ $d->isAuction() ? __('domain.source.auction') : __('domain.source.encar') }}</span>
                    · {{ __('auction.salesman_label') }} <b>{{ $d->creator->name }}</b> · {{ __('auction.region_label') }} <b>{{ $d->region ?: '—' }}</b><br>
                    VIN <b>{{ $d->vin ?: __('auction.vin_pending') }}</b>
                    @if ($d->isAuction())· {{ $d->auction_venue }} {{ $d->lot_number }}@else· {{ $d->encar_dealer ?: '' }} {{ $d->c_no ? __('auction.listing_no', ['no' => $d->c_no]) : '' }}@endif
                </div>

                {{-- 금액 --}}
                <div class="grid grid-cols-2 gap-2 text-xs text-gray-500">
                    <div>{{ __('auction.car_cost') }}<br><b class="text-sm text-gray-800">{{ $d->carCostDisplay() }}</b></div>
                    <div>{{ __('auction.discount_rate') }}<br><b class="text-sm text-gray-800">{{ $d->discount_rate !== null ? $d->discount_rate.'%' : '—' }}</b></div>
                    <div>{{ __('auction.shipping') }}<br><b class="text-sm text-gray-800">{{ $d->shipping_usd ? '$'.number_format($d->shipping_usd) : '—' }}</b></div>
                    <div>{{ __('auction.buyer') }}<br><b class="text-sm text-gray-800">{{ $d->buyer_name ?: '—' }}</b></div>
                </div>

                {{-- 차값 입력 — **셀프검차매입이 금액을 넣을 수 있는 유일한 지점**이다(검차·견적 씬을 건너뛴다).
                     비어 있으면 연동 B 가 car-erp 에서 422 로 죽으므로 구매확정 버튼이 여기서 막힌다. --}}
                @if (in_array($d->status, ['accepted', 'won'], true))
                    @php $costCur = $d->expected_price_currency ?: 'KRW'; @endphp
                    <div class="mt-2 rounded-md border p-2.5 {{ $d->car_cost === null ? 'border-red-300 bg-red-50' : 'border-gray-200 bg-gray-50' }}">
                        <div class="section-title-sm">{{ __('auction.amount_section') }}</div>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                {{-- 차값·매도비는 **항상 원화** — 견적통화 토글의 영향을 받지 않는다(2026-08-10 Jin). --}}
                                <label class="mb-0.5 block text-xs text-gray-500">{{ __('auction.car_cost') }} <span class="text-gray-400">({{ $d->isSelfInspection() ? __('common.won_currency') : (\App\Support\Money::SYMBOLS[$costCur] ?? '원') }})</span></label>
                                <input type="number" min="0" class="input-base" wire:model="car_cost" placeholder="{{ __('auction.car_cost_ph') }}">
                                @error('car_cost') <p class="mt-0.5 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>

                            {{-- 셀프검차매입 = 매도비·판매가·환율·운임비를 직접 적는다(견적 씬이 없어 파생계산의 근거가 없다).
                                 그 외 출처 = 기존 견적 공식(할인율·차감액·배송 선택). --}}
                            @if ($d->isSelfInspection())
                                <div>
                                    <label class="mb-0.5 block text-xs text-gray-500">{{ __('auction.selling_fee') }} <span class="text-gray-400">({{ __('common.won_currency') }})</span></label>
                                    <input type="number" min="0" class="input-base" wire:model="selling_fee">
                                    @error('selling_fee') <p class="mt-0.5 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                                {{-- 아래 셋은 견적통화 기준 — 라벨에 통화를 안 붙인다(단일 표시 = 견적통화 pill). --}}
                                <div>
                                    <label class="mb-0.5 block text-xs text-gray-500">{{ __('auction.sale_price') }}</label>
                                    <input type="number" min="0" step="0.01" class="input-base" wire:model="sale_price">
                                    @error('sale_price') <p class="mt-0.5 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="mb-0.5 block text-xs text-gray-500">{{ __('auction.offer_rate') }}</label>
                                    <input type="number" min="1" class="input-base" wire:model="offer_rate">
                                    @error('offer_rate') <p class="mt-0.5 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="mb-0.5 block text-xs text-gray-500">{{ __('auction.transport_fee') }}</label>
                                    <input type="number" min="0" step="0.01" class="input-base" wire:model="transport_fee">
                                    @error('transport_fee') <p class="mt-0.5 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                            @else
                                <div>
                                    <label class="mb-0.5 block text-xs text-gray-500">{{ __('auction.discount_rate') }} (%)</label>
                                    <input type="number" min="0" max="100" step="0.1" class="input-base" wire:model="discount_rate" placeholder="0">
                                    @error('discount_rate') <p class="mt-0.5 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="mb-0.5 block text-xs text-gray-500">{{ __('forwarding.sale_discount_label') }} <span class="text-gray-400">({{ __('common.won_currency') }})</span></label>
                                    <input type="number" min="0" class="input-base" wire:model="sale_discount" placeholder="0">
                                    @error('sale_discount') <p class="mt-0.5 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="mb-0.5 block text-xs text-gray-500">{{ __('auction.shipping') }} (USD)</label>
                                    <select class="input-base" wire:model="shipping_usd">
                                        <option value="">—</option>
                                        @foreach (config('board.shipping_options') as $opt)
                                            <option value="{{ $opt }}">${{ number_format($opt) }}</option>
                                        @endforeach
                                    </select>
                                    @error('shipping_usd') <p class="mt-0.5 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                            @endif
                        </div>
                        <div class="mt-2 flex items-center gap-2">
                            <span class="text-xs text-gray-500">{{ __('auction.quote_currency') }}</span>
                            @foreach (['KRW', 'USD', 'EUR'] as $cur)
                                <label class="flex cursor-pointer items-center gap-1 text-xs text-gray-600">
                                    <input type="radio" value="{{ $cur }}" wire:model="quoteCurrency"> {{ $cur }}
                                </label>
                            @endforeach
                        </div>
                        @error('quoteCurrency') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        @if ($d->isSelfInspection())
                            {{-- 라벨에서 통화를 뺐으니(단일 표시 원칙) 어느 통화 기준인지 여기서 한 번 못박는다.
                                 미선택이면 경고 — KRW 로 흘러가면 USD 판매가가 원화로 박힌다. --}}
                            @if ($quoteCurrency === '')
                                <p class="mt-1 text-[11px] font-semibold text-red-600">{{ __('auction.self_currency_unset') }}</p>
                            @else
                                <p class="mt-1 text-[11px] text-gray-600">{{ __('auction.self_currency_hint', ['currency' => $quoteCurrency]) }}</p>
                            @endif
                            <p class="mt-0.5 text-[11px] text-gray-500">{{ __('auction.self_amount_hint', ['purchase' => number_format(max(0, (int) $car_cost - (int) $selling_fee))]) }}</p>
                        @endif
                        <p class="mt-1 text-[11px] {{ $d->car_cost === null ? 'text-red-600' : 'text-gray-400' }}">{{ $d->car_cost === null ? __('auction.car_cost_missing') : __('auction.car_cost_hint') }}</p>
                    </div>
                @endif
                {{-- 셀프검차매입은 파생계산을 안 한다 → 적은 판매가를 그대로 보여준다(계산값 아님). --}}
                <div class="mt-3 flex items-center justify-between rounded-md border border-[var(--color-primary)] bg-[#f5f8ff] px-3 py-2.5">
                    <span class="font-semibold text-gray-700">{{ $d->isSelfInspection() ? __('auction.sale_price') : __('auction.final_price') }}</span>
                    <span class="text-base font-bold text-[var(--color-primary-text)]">
                        @if ($d->isSelfInspection())
                            {{ $d->sale_price !== null ? number_format((float) $d->sale_price, 0).' '.($d->offer_currency ?: 'KRW') : '—' }}
                        @else
                            {{ $d->offerDisplay() ?? '—' }}
                        @endif
                    </span>
                </div>

                @if ($d->inspection_memo || $d->inspection_note)
                    <div class="section-title-sm">{{ __('auction.inspection_memo') }}</div>
                    <p class="text-xs text-gray-600">{{ $d->inspection_memo }}{{ $d->inspection_note ? ' · '.$d->inspection_note : '' }}</p>
                @endif

                @if ($d->photos->count())
                    <div class="section-title-sm">{{ __('auction.vehicle_photos') }}</div>
                    <div class="grid grid-cols-4 gap-2">
                        @foreach ($d->photos as $p)
                            @if ($p->isVideo())
                                <video src="{{ $this->photoUrl($p->s3_path) }}" class="aspect-square w-full rounded-md object-cover" controls preload="metadata"></video>
                            @else
                                <img src="{{ $this->photoUrl($p->s3_path) }}" class="aspect-square w-full rounded-md object-cover" alt="">
                            @endif
                        @endforeach
                    </div>
                @endif

                {{-- 소유자(차주) — accepted·won 에서 입력/보정 (car-erp NICE 조회 입력값) --}}
                @if (in_array($d->status, ['accepted', 'won'], true))
                    <div class="section-title-sm">{{ __('auction.owner') }} <span class="text-[11px] font-normal text-gray-400">{{ __('auction.owner_hint') }}</span></div>
                    <input wire:model.blur="owner_name" class="input-base" placeholder="{{ __('auction.owner_placeholder') }}" maxlength="60">
                    @error('owner_name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                @endif

                {{-- 바이어/컨사이니 (car-erp 목록 드롭다운) — accepted·won, 본인 스코프. 미구성/무목록=수동 --}}
                @if (in_array($d->status, ['accepted', 'won'], true))
                    <div class="section-title-sm">{{ __('auction.buyer') }} <span class="text-[11px] font-normal text-gray-400">{{ __('auction.buyer_hint') }}</span></div>
                    @if (empty($buyerOpts))
                        <p class="text-xs text-gray-400">{{ __('auction.buyer_unavailable') }}</p>
                    @else
                        <select wire:model.live="buyerId" class="input-base">
                            <option value="">{{ __('auction.buyer_select') }}</option>
                            @foreach ($buyerOpts as $b)
                                {{-- 락 표시는 ERP 판정(purchase_locked) 그대로 — board 가 조건을 다시 계산하지 않는다. --}}
                                <option value="{{ $b['id'] }}">{{ !empty($b['purchase_locked']) ? '🔒 ' : '' }}{{ $b['name'] }}{{ !empty($b['country']) ? ' ('.$b['country'].')' : '' }}</option>
                            @endforeach
                        </select>
                        @error('buyerId') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        @php $blk = $this->buyerLock(); @endphp
                        @if ($blk)
                            {{-- 근거는 basis 하나만 — reference(보증금 여력 등)를 같이 그리면 락과 분모·분자가 달라
                                 "여력 0원인데 등록 가능"·"락인데 여력 1천만"이 둘 다 정상이라 영업이 반드시 오판한다. --}}
                            @php
                                $blkBasis = $blk['kind'] === 'ratio'
                                    ? __('auction.buyer_lock_ratio', ['current' => rtrim(rtrim(number_format($blk['current'], 1), '0'), '.'), 'limit' => rtrim(rtrim(number_format($blk['limit'], 1), '0'), '.')])
                                    : __('auction.buyer_lock_unsecured', ['current' => number_format($blk['current']), 'limit' => number_format($blk['limit'])]);
                            @endphp
                            <div class="mt-1.5 rounded-md border px-2.5 py-2 text-[11px] {{ $blk['locked'] ? 'border-red-300 bg-red-50 text-red-700' : 'border-gray-200 bg-gray-50 text-gray-500' }}">
                                @if ($blk['locked'])
                                    <div class="font-semibold">🔒 {{ __('auction.buyer_lock_title') }}</div>
                                    <div class="mt-0.5">{{ $blkBasis }}</div>
                                    {{-- 락은 절대 규칙이 아니다 — ERP 에서 사유를 적으면 1회 통과한다. --}}
                                    <div class="mt-0.5">{{ __('auction.buyer_lock_notice') }}</div>
                                @else
                                    {{ $blkBasis }}
                                @endif
                            </div>
                        @endif
                        @if ($buyerId)
                            <select wire:model="consigneeId" class="input-base mt-2">
                                <option value="">{{ __('auction.consignee_select') }}</option>
                                @foreach ($consigneeOpts as $c)
                                    <option value="{{ $c['id'] }}">{{ $c['name'] }}</option>
                                @endforeach
                            </select>
                        @endif
                    @endif
                @endif

                {{-- 입금정보 (정산 = 판매자/경매장 계좌) — accepted·won 에서 입력/수정 --}}
                @if (in_array($d->status, ['accepted', 'won'], true))
                    <div class="section-title-sm">{{ __('auction.payment_info') }} <span class="text-[11px] font-normal text-gray-400">{{ __('auction.payment_info_hint') }}</span></div>
                    <div x-data>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <input x-ref="bankAuc" wire:model.blur="payee_bank" list="korean-banks-auction" autocomplete="off"
                                       class="input-base" placeholder="{{ __('auction.bank_placeholder') }}" maxlength="100"
                                       x-on:input="$refs.acctAuc.value = $store.koreanBanks.applyMask($el.value, $refs.acctAuc.value)">
                                <datalist id="korean-banks-auction"><template x-for="b in $store.koreanBanks.names()" :key="b"><option :value="b"></option></template></datalist>
                            </div>
                            <div><input wire:model.blur="payee_name" class="input-base" placeholder="{{ __('auction.payee_placeholder') }}" maxlength="60"></div>
                        </div>
                        <input x-ref="acctAuc" wire:model.blur="payee_account" autocomplete="off"
                               class="input-base mt-2 font-mono" placeholder="{{ __('auction.account_placeholder') }}"
                               x-on:input="$el.value = $store.koreanBanks.applyMask($refs.bankAuc.value, $el.value)">
                    </div>
                    @error('payee_name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    @error('payee_bank') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    @error('payee_account') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                    {{-- 매도비 계좌 (판매자와 다른 대상) --}}
                    <div class="section-title-sm mt-3">{{ __('auction.selling_fee_info') }} <span class="text-[11px] font-normal text-gray-400">{{ __('auction.selling_fee_info_hint') }}</span></div>
                    <div x-data>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <input x-ref="feeBankAuc" wire:model.blur="selling_fee_payee_bank" list="korean-banks-fee-auction" autocomplete="off"
                                       class="input-base" placeholder="{{ __('auction.bank_placeholder') }}" maxlength="100"
                                       x-on:input="$refs.feeAcctAuc.value = $store.koreanBanks.applyMask($el.value, $refs.feeAcctAuc.value)">
                                <datalist id="korean-banks-fee-auction"><template x-for="b in $store.koreanBanks.names()" :key="b"><option :value="b"></option></template></datalist>
                            </div>
                            <div><input wire:model.blur="selling_fee_payee_name" class="input-base" placeholder="{{ __('auction.payee_placeholder') }}" maxlength="60"></div>
                        </div>
                        <input x-ref="feeAcctAuc" wire:model.blur="selling_fee_payee_account" autocomplete="off"
                               class="input-base mt-2 font-mono" placeholder="{{ __('auction.account_placeholder') }}"
                               x-on:input="$el.value = $store.koreanBanks.applyMask($refs.feeBankAuc.value, $el.value)">
                    </div>
                    @error('selling_fee_payee_account') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                @endif

                {{-- 딜러 차량 첨부 (매입 후 받은 사진·서류 → 낙찰 시 연동 B 로 car-erp 첨부탭). 서류는 바이어 전송 제외(§28) --}}
                @if (in_array($d->status, ['accepted', 'won'], true))
                    <div class="section-title-sm">{{ __('auction.attach.title') }} <span class="text-[11px] font-normal text-gray-400">{{ __('auction.attach.hint', ['max' => config('board.attachment_max')]) }}</span></div>
                    @if ($d->salesAttachments->count())
                        <div class="mt-1 grid grid-cols-4 gap-2">
                            @foreach ($d->salesAttachments as $p)
                                <div class="relative overflow-hidden rounded-md border border-gray-200" wire:key="auc-att-{{ $p->id }}">
                                    @if ($p->isDocument())
                                        <div class="flex aspect-square w-full flex-col items-center justify-center bg-gray-50 p-1 text-center text-[10px] text-gray-500">
                                            <span class="text-lg">📄</span><span class="line-clamp-2 break-all">{{ $p->original_name }}</span>
                                        </div>
                                    @else
                                        <img src="{{ $this->photoUrl($p->s3_path) }}" class="aspect-square w-full object-cover" alt="">
                                    @endif
                                    <button type="button" wire:click="deleteSalesAttachment({{ $p->id }})" wire:confirm="{{ __('auction.attach.delete_confirm') }}"
                                        class="absolute right-0.5 top-0.5 rounded bg-black/55 px-1 text-[10px] font-semibold text-white hover:bg-red-600">✕</button>
                                </div>
                            @endforeach
                        </div>
                    @endif
                    <label class="mt-2 flex cursor-pointer items-center justify-center rounded-lg border-2 border-dashed border-gray-300 py-2.5 text-[13px] text-gray-500 hover:border-[var(--color-primary)]">
                        {{ __('auction.attach.dropzone') }}
                        <input type="file" multiple wire:model="salesFiles" class="hidden">
                    </label>
                    <div wire:loading wire:target="salesFiles" class="mt-1 text-xs text-gray-400">{{ __('auction.attach.uploading') }}</div>
                    @if (count($salesFiles))
                        <div class="mt-2 grid grid-cols-4 gap-2">
                            @foreach ($salesFiles as $i => $f)
                                <div class="relative overflow-hidden rounded-md border border-gray-200" wire:key="auc-newfile-{{ $i }}">
                                    @if ($f->isPreviewable() && str_starts_with((string) $f->getMimeType(), 'image/'))
                                        <img src="{{ $f->temporaryUrl() }}" class="aspect-square w-full object-cover" alt="">
                                    @else
                                        <div class="flex aspect-square w-full flex-col items-center justify-center bg-gray-50 p-1 text-center text-[10px] text-gray-500">
                                            <span class="text-lg">📄</span><span class="line-clamp-2 break-all">{{ $f->getClientOriginalName() }}</span>
                                        </div>
                                    @endif
                                    <button type="button" wire:click="removeSalesFile({{ $i }})"
                                        class="absolute right-0.5 top-0.5 rounded bg-black/55 px-1 text-[10px] font-semibold text-white hover:bg-red-600">✕</button>
                                </div>
                            @endforeach
                        </div>
                    @endif
                    @error('salesFiles') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    @error('salesFiles.*') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    <p class="mt-1 text-[11px] text-gray-400">{{ __('auction.attach.help') }}</p>
                @endif

                {{-- 집행 --}}
                @if ($d->status === 'accepted')
                    <div class="section-title-sm">{{ __('auction.execute') }}</div>
                    <p class="mb-1 text-[11px] text-gray-400">{{ __('auction.execute_hint') }}</p>
                    {{-- 구매확정은 바이어 필수 + 락 없는 바이어여야 눌린다(2026-08-10 Jin).
                         유찰/취소(failed)는 그대로 — 락은 매입 등록을 막는 것이지 취소를 막는 게 아니다. --}}
                    @php $blockWhy = $this->purchaseBlockReason(); @endphp
                    @if ($blockWhy)
                        <p class="mb-1.5 rounded-md border border-red-300 bg-red-50 px-2.5 py-1.5 text-[11px] font-semibold text-red-700">🔒 {{ $blockWhy }}</p>
                    @endif
                    <div class="flex gap-2">
                        <button class="btn-green flex-1 justify-center {{ $blockWhy ? 'cursor-not-allowed opacity-40' : '' }}" @disabled($blockWhy !== null) wire:click="conclude({{ $d->id }}, 'won')">{{ $d->isAuction() ? __('auction.won_auction') : __('auction.won_encar') }}</button>
                        <button class="btn-ghost flex-1 justify-center" wire:click="conclude({{ $d->id }}, 'failed')">{{ $d->isAuction() ? __('auction.failed_auction') : __('auction.failed_encar') }}</button>
                    </div>
                @elseif ($d->status === 'won')
                    <button class="btn-primary mt-3 w-full justify-center" wire:click="savePayee">{{ __('auction.save_payment_info') }}</button>
                @endif
            </div>
        </div>
    @endif
</div>

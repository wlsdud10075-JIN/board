# 인계 — 선적 계획 후보(`/shippable`)를 미완납 차까지 넓히기

> **받는 쪽 = car-erp 세션** (`C:\xampp\htdocs\car-erp`). 작성 = board 세션, 2026-08-11, Jin 지시.
> board 변경은 board 세션에서 커밋한다(복사 금지 = drift). 권위 = car-erp `docs/integration/board-portal-api.md §5`.

---

## 0. 한 줄 요약

**`GET /shippable` 이 「판매완료」(= 대금 완납) 차만 준다. 「판매중」(미수 있음)도 달라.
영업이 돈 들어오기 전에 미리 묶어두고 서류를 준비하려는 용도다.**

## 1. 왜 (Jin 2026-08-11)

> "board쪽에서 묶어서 넘겨도 어차피 erp에서 진행이 안되고, **묶인것 보고 서류 준비를 미리 할 수 있게**
> 준비해놓을 수 있는 용도로 쓰다가 돈이 들어와서 진행이 되면 그대로 진행한다고 하더라고."
> "본인의 재고로 잡히는 모든 차량을 보여주자. 어차피 미수있으면 선적묶어서 보내도 진행이 안되거든."

지금은 **대금이 다 들어와야** 선적 계획 화면에 차가 나타난다. 그래서 준비를 미리 못 한다.

## 2. 지금 왜 안 보이나 (실측)

`ShippingRequestController::shippable` 의 조건:

```php
->where('sales_channel', 'export')
->where('progress_status_cache', '판매완료')      // ← 이것
->whereNotIn('id', $inOpenBundle)
```

그리고 `Vehicle::progress_status`:

```php
if ($this->sale_price > 0 && $this->sale_unpaid_amount <= 0) return '판매완료';
if ($this->sale_price > 0) return '판매중';
```

⇒ **「판매완료」 = 판매대금 완납**이다. Jin 의 관찰이 정확했다.

## 3. ⚠️ 사실 정정 — sync 에는 완납 게이트가 **없다**

Jin 은 *"미수 있으면 묶어 보내도 어차피 진행이 안 된다"* 고 알고 있는데, **시스템이 막는 게 아니다.**
`POST /shipping-requests/sync` 는 **본인 차(IDOR) 확인만** 하고 완납 여부를 전혀 보지 않는다
(`in_progress` 잠금 외에 거르는 조건 없음). 즉 지금도 미완납 차를 묶어 보내는 것 자체는 **가능**하고,
진행을 멈추는 건 **관리(수출통관) 담당자의 판단**이지 코드가 아니다.

⇒ 이번 변경으로 **미완납 차가 관리 화면에 훨씬 많이 흘러들어간다.** 게이트를 새로 만들자는 게 아니라
(Jin 은 사람이 판단하는 지금 방식을 원한다), **관리가 한눈에 구분할 수 있어야** 한다는 뜻이다.
ERP 선적 화면에서 묶음/차량의 미수 상태가 이미 보이는지 확인해 줄 것 —
`ShippingRequest::bundleFinance` 가 `unpaid_total_krw`·`fully_paid`·`unpaid_ratio` 를 이미 계산하고 있으니
표시만 없으면 붙이면 된다.

## 4. 요청 ① — `/shippable` 조건 완화

`progress_status_cache = '판매완료'` → **`sale_price > 0` 인 본인 export 차**(= 판매중 + 판매완료).

- `whereNotIn('id', $inOpenBundle)` 와 `sales_channel='export'`, 본인 차 조건은 **그대로 유지**.
- 진행상태 문자열 대신 **`sale_price > 0` 로 판정**할 것을 제안한다 — `progress_status_cache` 는
  '수출통관중'·'선적중' 같은 후속 라벨로 넘어가면 조건에서 빠지는데, 그건 이미 묶음에 있으니
  `$inOpenBundle` 이 걸러준다. 반대로 v3 grandfather 라벨 때문에 조용히 빠지는 사고를 피할 수 있다
  (운항 상태에서 "진행상태로 대상을 좁히지 않는다"고 판단한 것과 같은 이유).
- ⚠️ **판단은 그쪽에서** — `inStock()` 기반 `preShippingStock` 스코프를 쓰는 게 더 맞다면 그렇게.
  다만 **출고 후(`shipped_out`) 차가 후보로 돌아오면 안 된다**.

### 범위 한계 — 「재고 전부」는 물리적으로 불가능하다 (Jin 에게 이미 설명 예정)

Jin 은 "재고로 잡히는 모든 차량"이라고 했지만, 재고 4분류 중 **`general`(일반재고) = `sale_price ≤ 0`
= 투기매입·바이어 미정**이다. 묶음은 **바이어별**(`bundles[].buyer_id` 필수, 컨사이니도 바이어 소속)이라
**바이어가 없는 차는 담을 그릇이 없다.** board 가 바이어를 지정하면 그건 판매 등록 행위 = §9 흡수 금지.
그리고 Jin 의 목적(서류 미리 준비)도 바이어·컨사이니가 정해져야 성립한다.
⇒ 실질 최대 범위 = **`sale_price > 0` 전부**. 이게 목적과 정확히 일치한다.

## 5. 요청 ② — 후보 행에 미수 상태를 실어 줄 것 (중요)

지금 `/shippable` 행은 `vehicle_id`·`vehicle_number`·`buyer`·`consignees` + `portalMeta`(vin/brand/model_type)뿐이라
**미수 정보가 전혀 없다.** 완납 차만 오던 시절엔 필요 없었지만, 이제는 **영업이 어느 차가 미완납인지 모른 채 묶게 된다.**

`GET /sales`·`/inventory` 가 이미 쓰는 것과 **같은 이름**으로 부탁한다(board 가 분류를 발명하지 않는다는 원칙):
- `unpaid_ratio` (0~1 또는 null) — board 재고·판매 탭이 이미 이 필드로 게이지를 그린다
- `sale_unpaid_amount_krw` 또는 `fully_paid` 중 그쪽 편한 것

board 는 이 값으로 후보 행과 묶음 안에 미완납 표시를 붙인다.

## 6. board 측 작업 (참고 — board 세션에서 구현·커밋)

1. 후보 풀·묶음 행에 **미완납 표시**(§5 필드 도착 후).
2. ⚠️ **surrender B/L 미수 가드** — 지금 board 는 `bundles` 응답의 **`surrender_unpaid_warning` 을 안 쓴다**(실측).
   완납 차만 묶이던 동안엔 무해했지만, 미완납 차가 들어오면 **돈을 못 받은 채 서렌더 B/L 을 요청**할 수 있다
   (= 화물을 먼저 내주는 것). 최소한 확인 문구, 필요하면 차단.
3. 「선적 계획」 안내 문구에 "미완납 차도 미리 묶어둘 수 있다 / 진행은 입금 후" 를 명시.

## 7. 배포 순서

**ERP 먼저 → board 나중.** ERP 만 나가도 무해하다(후보가 늘어날 뿐, board 는 그대로 표시).
board 가 먼저 나가면 미수 필드가 안 와서 표시가 비는데, 그것도 조용히 죽지는 않는다(없으면 안 그림).

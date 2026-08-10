> ✅ **2026-08-10 회신 완료** — car-erp dev `af823eb`. VIN 노출 **허용**(차량 식별자이지 소유자 식별정보가 아님).
> 필드명·적용지점 요청대로 반영. `settlements` 는 board 가 안 써서 제외. 권위 스펙 = car-erp `board-portal-api.md §4-2`.
> board 구현 완료(dev) — **배포는 car-erp master 이후**.

# 인계 — 포털 차량 행에 **차대번호 · 브랜드/차종** 추가 (car-erp 세션)

작성: board 세션, 2026-08-10 · 수신: **car-erp 세션** (수정은 car-erp repo에서)

## 요청 (Jin, 2026-08-10)

board 포털(`내 정산·미수·선적`)에서 **차량번호가 보이는 곳이면 차대번호와 브랜드/차종도 같이** 보이게 해달라.

board 는 표시만 한다 — **읽기 API 응답에 그 필드가 없으면 아무것도 못 한다.** 지금 어느 엔드포인트도 안 보낸다(실측).

## 실측 — 지금 오는 건 `vehicle_number` 뿐

`InternalPortalController` 의 각 응답 map 을 확인했다. `inventory` 는 `brand`·`model_type`·`nice_reg_vin` 을
**검색(`where … like`)에만** 쓰고 emit 에는 없다. 나머지도 전부 `vehicle_number` 만 나간다.

## 넣어야 할 곳 — **컨트롤러 2개**다 (⚠️ 하나만 고치면 절반만 된다)

### A. `app/Http/Controllers/Api/Internal/InternalPortalController.php`

| 메서드 | board 탭 | 비고 |
|---|---|---|
| `receivables()` | 미수금 | |
| `inventory()` | 재고 (4분류 전부) | 검색엔 이미 있는 컬럼들 |
| `sales()` | 판매내역 | |
| `settlements()` | — | ⚠️ **board 화면엔 차량 행이 없다**(바이어별 집계만 렌더). 넣어도 board 는 안 쓴다 — 판단에 맡김 |

### B. `app/Http/Controllers/Api/Internal/ShippingRequestController.php` ← **다른 파일**

| 응답 | board 탭 | 비고 |
|---|---|---|
| `GET /shippable` 의 차량 | 선적요청(미배정 차 목록) | |
| `GET /bundles` 의 `vehicles[]` | 선적요청(묶음 pill · 변경요청 행) | **묶음 안 차량 배열에도** 필요 |

## 필드명 — board 는 **정확히 이 세 키**를 읽는다

```json
"vin":        "KMHxxxxxxxxxxxxx",   // ERP 컬럼 nice_reg_vin
"brand":      "현대",
"model_type": "그랜저 IG"
```

- 값이 없으면 **`null`** 로 보내면 된다 — board 는 없는 필드/`null` 이면 **아무것도 안 그린다**(degrade).
  대시(`—`)도 안 찍는다. 그래서 **car-erp 배포 전에 board 를 올려도 화면이 틀어지지 않는다**(§12 운항과 같은 패턴).
- 이름을 바꿔 보내면 board 는 못 읽는다. 바꿔야 하면 **바꾼 이름을 알려줄 것**(board 가 맞춘다).

## ❓ car-erp 가 판단해줘야 할 것 — VIN 노출

`nice_reg_vin` 을 **응답에 실어도 되는지**는 §3 PII 화이트리스트의 판단이다. board 가 정할 수 없다.

- 검색 조건으로 쓰는 것과 응답에 emit 하는 건 **노출면이 다르다** — 지금 `inventory` 는 검색에만 쓴다.
- 안 된다면 **브랜드/차종만** 보내줘도 된다. board 는 있는 것만 그린다(각 필드 독립 degrade).
- ⚠️ 전례: board 가 `sales_contract` 를 "허용될 것"이라 가정하고 넣었다가 car-erp 화이트리스트에 없어서
  **몇 주간 403** 이었다(CLAUDE.md 「선적요청 서류 다운로드」). 그래서 이번엔 먼저 묻는다.

## board 쪽 상태

- **board 구현은 dev 에 준비해둔다**(단일 partial `_vehicle-meta.blade.php`, 차량번호 아래 작은 줄).
- ⛔ **board master 배포는 car-erp 가 origin/master 에 올라간 뒤**. 순서 = car-erp → board.
  (degrade 라 먼저 올려도 안 깨지지만, 올릴 이유가 없다 — 보일 게 없다.)
- 적용 탭 = **미수금 · 재고 · 판매내역 · 선적요청 4개**. 정산내역은 위 표대로 차량 행이 없어 제외.

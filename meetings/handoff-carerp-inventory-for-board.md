# 인계 — car-erp 세션: board 포털 「매입내역」을 재고 3분류로 교체

> 보내는 쪽 = board 세션 (2026-08-09). 받는 쪽 = car-erp 세션.
> ⚠️ car-erp 변경은 car-erp 세션에서 커밋(크로스레포 규칙). board 는 이 API 가 들어와야 시작 가능 — 현재 board 가 할 수 있는 작업 0.

## 0. 왜 바꾸나 (jin 2026-08-09)

board 포털 `매입내역` 은 `purchases()` = `ownVehicles($sid)->where('purchase_price','>',0)->get()` 이다.
**필터도 페이징도 없고, 영업이 평생 매입한 모든 차가 매번 통째로 온다.** 단조증가라 절대 줄지 않는다.

jin 판단 = 이걸 **ERP 재고관리(`erp/inventory`)의 3분류를 그대로 미러**하는 쪽으로 바꾼다. 이유:

- 재고는 `inStock()`(= `warehouse_out_date IS NULL`) 이라 **집합이 유한하다**. 영업 1명당 20~50대(jin 확인).
- 계속 불어나는 꼬리(과거 출고분)가 `출고완료` 탭으로 분리돼 기본 조회에서 빠진다.
- 보관위치·입고일·바이어·진행상태처럼 **영업이 실제로 궁금해하는 정보**가 같이 온다.
- 이미 ERP 화면에 있는 개념이라 board 가 새 분류를 발명하지 않는다.

board 는 `매입내역` 탭을 **없애고** 재고 3탭으로 대체한다(둘 다 두면 같은 차가 두 군데 다르게 보인다).
매입가·매입 미지급을 재고 행에 얹으면 매입내역이 하던 일(§11 입금요청)을 그대로 흡수한다.

---

## 요청 ① — `GET /api/internal/board/inventory` 신설

기존 `erp/inventory` 의 `inventoryVehicles()` 쿼리를 §3 화이트리스트로 감싼 읽기 전용 엔드포인트.
인증·스코프는 §1·§2 그대로(`salesman_email`).

### 쿼리 파라미터

| 파라미터 | 값 | 비고 |
|---|---|---|
| `category` | `general` \| `pre_ship` \| `shipped_out` | 필수. ERP 화면의 3분류와 **같은 정의**를 쓸 것 |
| `search` | 문자열 | 선택. 차량번호·차대번호 등 기존 화면 검색과 동일 대상 |
| `limit` / `offset` | 정수 | `shipped_out` 전용(아래 참조). 재고 2분류는 안 써도 됨 |

`category` 정의는 **ERP 화면과 갈리면 안 된다**(갈리는 순간 "ERP엔 재고인데 board엔 없다"가 된다):
- `general` = `inStock()` + `sale_price IS NULL OR sale_price <= 0`
- `pre_ship` = `inStock()` + `sale_price > 0`
- `shipped_out` = `whereNotNull('warehouse_out_date')`, 최근 출고순

### 응답 (행당 화이트리스트)

```jsonc
{
  "count": 23,
  "data": [{
    "vehicle_id": 59,                    // §11 [입금요청] 전송용 — 없으면 board 버튼이 죽는다
    "vehicle_number": "99테0001",
    "progress_status": "판매중",          // progress_status_cache 그대로 (아래 ③)
    "stock_location": "홈플",             // 보관위치
    "stock_location_note": "3번 라인",
    "warehouse_in_date": "2026-08-01",
    "warehouse_out_date": null,          // shipped_out 에서만 값
    "buyer_id": 5,                       // 없으면 null (일반재고 = 바이어 미정)
    "buyer": "YANGON CAR IMPORT",
    "purchase_price": 12000000,
    "purchase_unpaid": 12440000,         // 입금요청 판단 근거
    "purchase_date": "2026-08-09"
  }]
}
```

🚫 **마진·PII 금지** — §3 유지. 기존 `erp/inventory` 화면엔 마진 노출이 0건이라 그대로 옮기면 문제없다.
판매가(`sale_price`)는 `pre_ship` 판정에만 쓰고 **응답에 싣지 않아도 된다**(board 판매내역이 이미 준다).

---

## 요청 ② — `sales()` 에 진행상태 + 상태 필터

board `판매내역` 은 바이어별로 차가 주르륵 뜨는데 **거래완료인지 진행중인지 알 방법이 없다**(jin).

```php
// sales() map() 에 한 줄
'progress_status' => $v->progress_status_cache,
```

+ 쿼리 파라미터 `exclude_status`(또는 `status`) 하나. board 가 「거래완료 숨기기」 토글을 **서버로 보내서** 거른다.
board 에서 받아놓고 감추는 방식은 트래픽이 그대로라 의미가 없다.

---

## ③ 왜 `progress_status_cache` 인가 (jin: "최소 트래픽 최대 정보")

board 가 단계를 재계산하거나 별도 상태 API 를 부르는 안도 검토했는데 전부 이것보다 비싸다.

- `progress_status_cache` = **이미 계산돼 저장된 `varchar(20)` 컬럼 + 인덱스**
  (`2026_05_08_000001_add_progress_status_cache_to_vehicles_table`)
- payload 비용 = 행당 문자열 하나. 추가 쿼리·조인 0.
- **인덱스가 있어서 필터가 진짜로 행을 줄인다** — 화면에서 감추는 게 아니라 DB 가 걸러서 적게 보낸다.

⚠️ **단계를 board 용으로 추리거나 이름을 바꾸지 말 것.** jin 확정(2026-08-09) = **ERP 값 그대로 전부 노출**.
board 가 골라내면 "ERP엔 있는데 board엔 없다"가 생긴다(서류 이름에서 이미 한 번 난 문제).
현재 실제 분포(로컬): 판매중·판매완료·거래완료·매입중·매입완료·통관중·말소완료.

---

## ④ ⚠️ 인덱스 — `shipped_out` 이 유일한 누적 탭인데 가장 비싸다

board 로컬에서 `SHOW INDEX FROM vehicles` 확인 결과:

| 컬럼 | 인덱스 | 쓰임 |
|---|---|---|
| `progress_status_cache` | **있음** ✓ | 뱃지·상태필터가 싼 이유 |
| `salesman_id` | 있음 ✓ (FK) | 스코프 |
| `warehouse_out_date` | **없음** ✗ | `shipped_out` 의 **필터 + 정렬 둘 다** 여기 걸림 |
| `vehicle_number` | **없음** ✗ | 검색(`like '%…%'`)이 풀스캔 |

`shipped_out` 은 영원히 단조증가하는 유일한 카테고리인데, 필터도 정렬도 인덱스가 없다.
지금은 56대라 티가 안 나지만 누적되면 여기부터 아프다.

**요청: `warehouse_out_date` 인덱스 추가.** `vehicle_number` 는 `%…%` 검색이라 인덱스로 해결이 안 되니
(끝 4자리로 찾는 실사용 패턴이 있어 접두 검색으로 바꾸는 것도 곤란) **board 가 호출량으로 막는다** — 아래 참조.

---

## ⑤ board 가 지킬 것 (이쪽 약속)

- **재고 2분류(`general`·`pre_ship`)** = 20~50대라 한 번에 받는다. board 페이징 UI 안 만든다.
- **`shipped_out`** = 기본 **최근 30건**만. `[더 보기]` 로 30건씩 `offset` 이어붙임.
  탭을 열었다고 전량을 부르지 않는다.
- **검색은 ERP 로 넘긴다.** 최근 30건만 받아놓고 board 에서 거르면 옛날 차를 영영 못 찾는다.
  대신 **사용자가 실제로 검색어를 칠 때만** 그 조회가 돈다.
- 진행상태는 **ERP 값 그대로** 표시. 재계산·재명명·완료 coerce 없음(§11-4 항목 4와 같은 원칙).
- 응답에 `vehicle_id` 가 없는 행은 §11 버튼을 **없애지 않고 "전송 불가"로 비활성** 표시(기존과 동일).

---

## ⑥ 순서

1. car-erp: 요청 ①②④ → dev
2. board: 재고 3탭 구현 + 매입내역 제거 → dev, 로컬 e2e
3. jin 승인 → **car-erp master 먼저 → board 나중** (board 만 먼저 올리면 엔드포인트가 없어 탭이 통째로 "조회 불가")
4. 배포 후 두 박스(heymanboard·ssancarboard) job + `db:backup` 확인

⚠️ board 는 `.md` 를 master 에 안 올린다(dev 전용). 이 문서도 board dev 에만 남는다.

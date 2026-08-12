# 인계 — 포털 재고 검색에 **바이어** 추가

> **받는 쪽 = car-erp 세션** (`C:\xampp\htdocs\car-erp`). 작성 = board 세션, 2026-08-12, Jin 요청.
> 권위 = car-erp `docs/integration/board-portal-api.md §4`(재고 4분류 `GET /inventory`).

---

## 0. 한 줄 요약

**`GET /inventory?search=` 가 바이어명을 안 훑는다. 훑게 해달라.**

## 1. 왜

> Jin 2026-08-12: "내정산/미수(포털)에서 재고에 차량번호/차대번호/선박명으로 검색할 수 있는데,
> 여기에서 **바이어로 검색**할 수 있게 할 수 있어?"

영업이 "이 바이어한테 나갈 차 지금 뭐뭐 있지"를 재고 탭에서 바로 보려는 것.
`/sales`(판매내역)엔 바이어별 블록이 있지만 **재고엔 없다.**

## 2. 지금 훑는 것 (실측 — `InternalPortalController` 재고 분기)

```php
->where('vehicle_number', 'like', ...)
->orWhere('brand', ...)
->orWhere('model_type', ...)
->orWhere('nice_reg_vin', ...)
->orWhere('export_declaration_number', ...)
->orWhere('vessel_name', ...)
->orWhere('container_number', ...)
```

⇒ **바이어만 없다.** 바이어는 컬럼이 아니라 `export_buyer_id` → `buyers` 릴레이션이라
`orWhereHas('buyer', fn ($q) => $q->where('name', 'like', "%{$search}%"))` 같은 게 하나 더 필요하다.

## 3. 요청

- 위 `search` 절에 **바이어명**을 `orWhereHas` 로 추가.
- **파라미터·응답 형태는 그대로**(`search=` 하나에 다 태우는 지금 방식 유지). board 는 보내는 게 안 바뀐다
  ⇒ **board 코드 변경 없이 ERP 배포만으로 동작**한다(board 는 문구만 고친다).
- ⚠️ 재고 4분류 중 **`general`(일반재고)은 바이어가 없다**(`sale_price ≤ 0` = 투기매입). 그 탭에서
  바이어로 치면 0건이 정상이다 — 억지로 매칭시키지 말 것.
- 성능: 영업당 재고는 수십~수백 대 수준이라 서브쿼리 하나는 무해할 것으로 본다.
  다만 실측은 그쪽이 하는 게 맞다(board 는 행 수를 모른다).

## 4. board 측 작업 (참고 — board 세션에서 커밋)

검색창 placeholder 갱신뿐. **지금 문구가 실제보다 좁다** — `차량번호·차대번호·선박명 검색` 인데
실제로는 브랜드·차종·수출신고번호·컨테이너번호도 이미 검색된다. ERP 배포 후 바이어까지 넣어 한 번에 고친다.

## 5. 배포 순서

**ERP 먼저 → board 문구.** board 가 먼저 나가면 "바이어 검색됨"이라고 써놓고 안 되는 상태가 된다.

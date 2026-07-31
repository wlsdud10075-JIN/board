# 인계 — board 선적요청 서류 다운로드 (판매계약서 · proforma invoice)

> 보내는 쪽 = **board 세션** / 받는 쪽 = **car-erp 세션**
> 작성 2026-07-31. 근거 = board `resources/views/livewire/portal/index.blade.php` · `app/Services/CarErpReadService.php`,
> car-erp `app/Http/Controllers/Api/Internal/InternalDocumentController.php`(origin/master 확인).

## 한 줄 요약

board 선적요청 화면의 **판매계약서 버튼은 지금 100% 실패**한다 — car-erp 가 `sales_contract` 를 board 에
열어주지 않았기 때문(403). **바이어가 달라서가 아니다.** proforma invoice 는 car-erp 에 아직 존재하지 않는다.

---

## 1. 판매계약서(sales_contract) — 403, 구조적으로 불가

### 확인된 사실

| 위치 | 상태 |
|---|---|
| board `CarErpReadService::ALLOWED_DOC_TYPES` | `sales_contract` **포함** (버튼도 노출 중) |
| car-erp `InternalDocumentController::BOARD_ALLOWED_TYPES` (**origin/master**) | 선적 4종만 — `sales_contract` **없음** |
| car-erp `VehicleDocumentController` | `sales_contract` **있음** (ERP 자체 화면용, `HOMOGENEOUS_TYPES` = 동일 바이어 조건) |
| car-erp `DocumentFiller` | `sales_contract => SalesContractMapping` **있음** (문서 생성 자체는 가능) |

→ board 요청은 `InternalDocumentController::show()` 의
`abort_unless(in_array($type, self::BOARD_ALLOWED_TYPES, true), 403)` 에서 즉시 차단된다.

### 왜 이렇게 됐나 (확인 요청 ①)

board 쪽 주석(`CarErpReadService.php:25`)엔 **"sales_contract … 2026-07-01 car-erp 추가"** 라고 적혀 있는데
car-erp 코드엔 없다. **되돌려진 건지, 애초에 안 올라간 건지** car-erp 세션에서 확인 바란다.

### 열어주려면 = 코드 한 줄이 아니라 **보안 판단** (확인 요청 ②)

car-erp `docs/meetings/2026-07-10-sales-contract-e-signature.md` Engineer 항목에 명시:

> `BOARD_ALLOWED_TYPES`에 `sales_contract`(passport_id 포함) 추가는 **§29 PII 정책 충돌 → 보안 승인 필요**

board 의 PII 스탠스도 "RRN·전화 미보유 원칙"이라 board 가 임의로 요구할 사안이 아니다.
**판단 재료로 함께 검토 바라는 것**:

- board 는 이미 **§10 전자서명 경로가 운영 배포**돼 있다(board master `f807ed7`). 바이어가 서명 URL 을 열면
  **같은 계약서 내용을 본다** — 즉 계약서 내용 자체는 이미 board 를 거쳐 바이어에게 도달한다.
- 차이는 **누가 passport 데이터를 보느냐**다: 바이어 본인(서명 화면) vs **board 안의 영업**(다운로드).
- 이 차이가 §29 판단을 바꾸는지 여부 = **car-erp 보안 담당의 결정**. board 는 결론을 내지 않는다.

### 결정에 따른 board 측 대응

| car-erp 결정 | board 가 할 일 |
|---|---|
| **허용** (`BOARD_ALLOWED_TYPES` 에 `sales_contract` 추가) | 없음 — 버튼·화이트리스트 이미 있음. 배포되는 즉시 동작 |
| **불허** | board `ALLOWED_DOC_TYPES` 에서 `sales_contract` 제거 + 버튼 내림(전자서명 요청만 남김) |

→ **결정을 board 세션에 회신 바람.** 불허면 board 가 버튼을 정리한다.

---

## 2. proforma invoice — car-erp 에 아직 없음

`grep -rn "proforma" car-erp/{app,resources,docs}` = **0건**. Jin 확인대로 ERP 신규 작업이 선행이다.

### car-erp 에 요청하는 것

proforma invoice 를 만들 때 **board 프록시로도 내려받을 수 있게** 다음을 함께 정해 회신 바란다:

1. **타입 문자열** — `proforma_invoice` 인가? (board 가 그 문자열로 화이트리스트·버튼을 맞춘다)
2. **method 접두 여부** — ⚠️ 이게 board 구현 분기를 가른다:
   - 선적 4종처럼 `roro_` / `container_` 접두가 붙는가 → `roro_proforma_invoice`
   - 아니면 `sales_contract` 처럼 **리터럴 단일 타입**인가
3. **묶음 조건** — `HOMOGENEOUS_TYPES`(동일 바이어) 대상인가? 통화·export 제약은?
4. **`BOARD_ALLOWED_TYPES` 포함 여부** — PII 검토 결과 포함(passport 등 민감정보가 들어가는지)

### board 측 작업량

위 4개만 정해지면 **2줄**이다 — `ALLOWED_DOC_TYPES` 에 타입 추가 + 기존 3버튼 옆에 버튼 하나.
(`downloadDocs()` 가 이미 리터럴/method접두 두 갈래를 다룬다.)

---

## 3. board 가 이번에 한 것 (참고 — car-erp 작업 아님)

실패 안내가 원인을 **잘못 짚고 있었다**. `ok=false` 면 전부 "동일 바이어·단일 통화" 로 안내해서,
실제로는 403(타입 미허용)인데 Jin 이 묶음 구성을 의심하게 만들었다. car-erp 응답 코드로 분기하도록 수정:

- `403` → "car-erp 쪽에서 허용해야 하는 항목입니다 (묶음 구성 문제 아님)"
- `422` → 기존 동일 바이어·단일 통화 안내
- 그 외 → 기존 연동 확인 안내

---

## 회신 요청 정리

1. `sales_contract` 가 board 화이트리스트에 **없는 이유** (되돌림? 미반영?)
2. `sales_contract` **허용/불허 결정** (§29 PII — 위 전자서명 대비 판단재료 참고)
3. proforma invoice 신설 시 **타입 문자열 · method 접두 여부 · 묶음 조건 · board 허용 여부**

→ board 세션에 회신되면 board 쪽 대응(버튼 정리 또는 타입 추가)을 즉시 반영한다.

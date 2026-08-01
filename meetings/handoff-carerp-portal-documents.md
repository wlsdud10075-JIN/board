# 인계 — board 선적요청 서류 다운로드 (판매계약서 · proforma invoice)

> 보내는 쪽 = **board 세션** / 받는 쪽 = **car-erp 세션**
> 작성 2026-07-31. 근거 = board `resources/views/livewire/portal/index.blade.php` · `app/Services/CarErpReadService.php`,
> car-erp `app/Http/Controllers/Api/Internal/InternalDocumentController.php`(origin/master 확인).

---

## ✅ 종결 (2026-08-01) — car-erp 회신 + board 반영 완료

car-erp 가 **`sales_contract`·`invoice` 둘 다 개방**(master `4d3959e`, 3사 배포). board 도 반영 완료.
아래 원문은 이력으로 남긴다. 회신 요약과 실제 코드 대조 결과:

| 회신 항목 | 결론 | board 대조 |
|---|---|---|
| ① 화이트리스트 누락 경위 | **되돌림 아님 — 애초에 안 올라감.** board 주석이 2026-07-01 `VehicleDocumentController`(ERP 화면) 변경을 프록시 추가로 오독 | 주석 정정 완료 |
| ② `sales_contract` | **허용**(jin 결정). §29 근거 = 판매계약서엔 **RRN 없음**(여권·연락처·주소뿐), 4종 제한의 이유였던 말소서류 주민번호와 다름 | `BOARD_ALLOWED_TYPES` 에 존재 확인 |
| ③ proforma invoice | **이미 존재**. 타입명 = **`invoice`**(`proforma_invoice` 아님) · **method 접두 없는 리터럴** · 1바이어·단일통화·export·최대 30대 | board 화이트리스트·버튼 추가 |

**추가 확인한 것** — car-erp 가 board 프록시에도 동질성 가드를 신설:
`HOMOGENEOUS_TYPES = ['sales_contract', 'invoice']` → 바이어 혼합/통화 혼합이면 **422**.
(그전엔 매핑이 primary 로만 채워 **조용히 틀린 서류**가 나갈 수 있었다.) board 의 403/422 분기가 이제 정확히 대응한다.

### board 반영 내역

- `ALLOWED_DOC_TYPES` 에 `invoice` 추가 + 오독했던 주석 정정
- `downloadDocs()` 리터럴 타입 = `['sales_contract', 'invoice']` (method 접두 금지 — 붙이면 403)
- 프로포마 인보이스 버튼 추가 (`portal.docs_proforma_invoice`, ko·en)
- 422 안내를 판매계약서 전용 문구에서 **서류 공통**(`flash_docs_homogeneous_required`)으로 일반화
- 회귀 테스트: 리터럴 타입이 접두 없이 나가는지 + 선적서류는 접두가 붙는지

### ⏳ 남은 확인 1건 (car-erp 제기 — board 단독으로 못 끝냄)

> `SalesmanResolver` 가 명부로 매칭하므로, **ERP 계정 없이 salesmen 명부에만 있는 영업**도 board 를 통해
> 여권 정보가 든 서류를 받게 된다. 그런 사람이 실제로 있는지 확인 필요.

board 쪽 사실관계(코드 확인):

- 서류를 받을 수 있는 계정 = board `role ∈ {sales, manager}` 또는 `super` (포털 접근 권한)
- 조회 스코프 = `car_erp_salesman_email ?: email` → 이 값이 car-erp salesmen 명부와 매칭되면 그 영업의 차량 서류
- **super 가 타인 포털을 열람 중일 땐 서류 다운로드가 서버에서 차단**된다(`isViewingOther()` 게이트) — 임퍼소네이션 경로로는 안 샌다

→ 실제 대조는 **운영 DB 2개를 맞춰봐야** 한다(board `users` ↔ car-erp `salesmen`/`users`).
board 운영에서 후보 목록 뽑는 쿼리:

```sql
SELECT email, COALESCE(car_erp_salesman_email, email) AS erp_key, role, permission, is_active
FROM users
WHERE is_active = 1 AND (role IN ('sales','manager') OR permission = 'super');
```

이 `erp_key` 목록을 car-erp 세션에 넘겨 **ERP 로그인 계정이 없는 사람**이 있는지 대조하면 끝난다.

---

## (이력) 최초 인계 원문 — 2026-07-31

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

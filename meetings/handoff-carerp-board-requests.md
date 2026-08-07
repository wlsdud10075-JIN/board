# 인계 — car-erp 세션: §11 요청·확인 신호, board 측 완료 + 요청 3건

> 보내는 쪽 = board 세션 (2026-08-07). 받는 쪽 = car-erp 세션.
> 권위 스펙 = car-erp `docs/integration/board-portal-api.md` §11.
> ⚠️ 이 문서는 board repo 에 있다. **car-erp 변경은 car-erp 세션에서 커밋**할 것(크로스레포 규칙).

## 0. 현재 상태

| 쪽 | 상태 |
|---|---|
| car-erp | §11 구현 완료 (`0ca13a7` 테이블·API·자동해소, `fffb7b0` 재무 처리 화면) — **dev only, master 미머지** |
| board | §11-4 여섯 항목 구현 완료 (`bf4b3db`) — **dev only, master 미머지** |

로컬 e2e 실측 완료 (board `:8003` → car-erp `:8001`, 둘 다 dev):

```
POST /requests purchase_payment [6]  → 201 {batch_id:null, created:["20가2001"], skipped:[]}
POST 재전송 (멱등)                    → 201 {created:[], skipped:[{vehicle_number:"20가2001", reason:"already_open"}]}
POST 남의 차 [9999]                   → 201 {created:[], skipped:[{vehicle_id:9999, reason:"forbidden"}]}
POST sale_payment_confirm buyer 누락  → 422
GET  /requests?status=all            → {count:1, requests:[{batch_id, type, status:"open", vehicles:[...]}]}
```

⚠️ **car-erp 로컬 DB 에 테스트 신호 1건**(`board_requests`, vehicle 6 = `20가2001`, status=open)이 남아 있다. 위 실측에서 만든 것 — 지워도 되고, 화면 확인용으로 둬도 된다.

---

## 1. 요청 ① (필수) — 읽기 API 에 `vehicle_id` 를 넣어달라

**이게 없으면 board 버튼이 동작하지 않는다.** `POST /requests` 는 `vehicle_ids`(정수)를 요구하는데, 영업이 그 버튼을 누르는 화면(매입 탭·판매 탭)의 응답에 차량 id 가 없다.

`app/Http/Controllers/Api/Internal/InternalPortalController.php` — **3줄**:

```php
// purchases()  — map() 안에 추가
'vehicle_id' => $v->id,

// sales()      — map() 안에 추가
'vehicle_id' => $v->id,
'buyer_id'   => $v->buyer_id,
```

근거 / 안전성:
- **PII 아님.** 둘 다 내부 정수 id 이고, `shippable()` 이 이미 `vehicle_id` 와 `buyer.id` 를 같은 영업 스코프로 내주고 있다. §3 화이트리스트 취지(마진·PII 금지)에 걸리지 않는다.
- **스코프 동일.** `ownVehicles($sid)` 를 그대로 통과한 행이라 노출 범위가 넓어지지 않는다.
- `sales()` 의 `buyer_id` 는 판매대금확인의 `buyer_id` 확정에 필요하다. 지금은 바이어 **이름 문자열**뿐이라 동명이인·표기흔들림에 취약하다.

**우회는 검토했고 버렸다.** board 의 `purchase_listings.car_erp_vehicle_id` 로 조인하는 방법 — board 를 거치지 않고 매입한 차는 영영 커버가 안 되고(영구 결함), 로컬에서는 board 값(erp#176~180)과 car-erp 로컬(id 1~54)이 아예 어긋나 테스트도 불가능하다.

**board 쪽은 이미 대비되어 있다.** `vehicle_id` 가 없는 행은 버튼을 없애지 않고 *"전송 불가 — ERP가 이 행의 차량 id 를 주지 않습니다"* 로 비활성 표시한다. 위 3줄이 들어오는 순간 **board 코드 변경 0으로 켜진다.**

---

## 2. 요청 ② — §11-3 문서를 구현에 맞게 정정

구현이 스펙과 두 군데 다르다. **구현이 맞고 문서가 틀렸다**(board 는 구현 기준으로 만들었다). car-erp 세션에서 문서를 고쳐주면 다음 사람이 안 헤맨다.

| 항목 | §11-3 문서 | 실제 구현 |
|---|---|---|
| `batch_id` | "purchase_payment 도 1행짜리 묶음으로 발급" | **`null`** — `store()` 가 `$isSaleConfirm ? $batchId : null`. (행 자체엔 `BoardRequest::open()` 이 uuid 를 넣지만 **응답엔 안 실린다**) |
| `skipped[]` | `[{vehicle_number, reason}]` 고정 | 키가 갈린다 — `forbidden` 은 **`vehicle_id`**, `already_open` 은 **`vehicle_number`** |

`GET /requests` 가 문서에 없는 `count` 를 같이 주는 것은 무해 — 문서에 한 줄 추가만 하면 된다.

---

## 3. 요청 ③ — master 머지는 **양쪽 동시에**

board 만 먼저 master 에 올리면 운영 ERP 에 `/requests` 가 없어 전 영업에게 "전송 불가"만 뜬다. 반대로 ERP 만 올리면 아무도 안 쓴다.

순서 제안:
1. car-erp: 요청 ①(3줄) 반영 → dev
2. **board 로컬에서 e2e 재확인** (버튼이 실제로 켜지는지 — board 세션이 함)
3. Jin 승인 → **car-erp master 머지 → board master 머지** (ERP 가 먼저 서 있어야 board 가 안 캄캄하다)
4. 배포 후 검증: 두 박스(heymanboard / ssancarboard) job 결과 + `db:backup` ✓

⚠️ board 는 `.md` 를 master 에 안 올린다(dev 전용). 이 문서도 board dev 에만 남는다.

---

## 4. 참고 — board 가 구현한 것 (§11-4 여섯 항목)

1. `CarErpReadService::sendBoardRequest()` / `boardRequests()` — 기존 HMAC 전송(`send()`) 그대로 사용, 전송계층 무변경
2. 매입 탭 행마다 **[입금요청]** (차량 1대)
3. 판매 탭 바이어 블록에서 차량 체크 → **[판매대금확인]** (바이어 1 + N대). 바이어 블록 안에서만 고르게 되어 있어 **바이어 혼합이 구조적으로 불가능** — ERP 422 는 이중방어로 남는다
4. 상태 칩 — `GET /requests` 값을 **그대로** 표시(open/partial/done/cancelled, 2대 이상이면 `3/5`). board 재계산·완료 coerce 없음
5. degrade — 미설정·타인 포털 열람·전송실패를 전부 "전송 불가". 성공한 척 안 함
6. 🚫 금액칸 없음. 테스트(`test_board_request_payload_carries_no_amount`)가 금액 키 재유입을 막는다

**안 만든 것**: `POST /requests/{batch}/cancel` UI. §11-3 에서 *선택 구현*이고 §11-4 여섯 항목 밖이다. car-erp 라우트는 살아 있으니 Jin 이 원하면 board 에 버튼만 붙이면 된다.

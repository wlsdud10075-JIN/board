# 인계 — 대표 계약금(`purchase_deposit_ceo`) : 시각 규칙을 타지 않고 대표에게만 가는 입금요청

> **받는 쪽 = car-erp 세션** (`C:\xampp\htdocs\car-erp`). 작성 = board 세션, 2026-09-17, Jin 요청.
> 이 문서는 **요청서**다. car-erp 변경은 car-erp 세션에서 판단·구현·커밋한다(복사 금지 = drift).
> 권위 스펙 = car-erp `docs/integration/board-portal-api.md §11`. 선행 인계 = board `meetings/handoff-carerp-payment-request-split.md`
> (계약금/잔금 분리 + 알림톡 수신자 시각 규칙 §5-1). **이 문서는 그 §5-1 에 "규칙을 타지 않는 신호" 하나를 더한다.**

---

## 0. 한 줄 요약

**포털 재고(지급대기) 행에 [대표계약금] 버튼을 하나 더 둔다. 보내는 내용은 계약금과 같고(차량 1대 + 금액 KRW),
다른 점은 **수신자가 대표 고정 · 시각 규칙 무시**라는 것 하나다. ERP 화면에서는 「대표계약금」 뱃지로 구분한다.**

board 측 구현은 **dev 에 완료**(아래 §5). ERP 가 새 `type` 을 받기 전에는 422 만 나므로 **배포 순서는 ERP 가 먼저다**(§7).

---

## 1. 왜 필요한가 (Jin 2026-09-17)

지금 입금요청 알림톡은 **시각 규칙**(§5-1, 근무시간엔 담당자 1~2명 / 시간 밖엔 대표)을 따른다.
그래서 **담당자가 휴가·외근으로 자리를 비우면 요청이 담당자에게만 가 있고**, 그걸 누가 봤는지를
Jin 이 사람 손으로 계속 확인해 줘야 했다.

> Jin: *"이게 시간에 따라 하니까, 누군가가 휴가가고 자리를 비울때 내가 계속 체크를 해줘야하더라고.
> 그래서 시간에 구애받지 않고, 계약금버튼 하나를 추가해서 그건 대표에게 시간에 상관없이 보낼 수 있는게 있으면해."*

**기존 씬(계약금·매입잔금)은 그대로 둔다.** 이건 대체가 아니라 **탈출구 한 개 추가**다.

---

## 2. 🚨 왜 subtype·플래그가 아니라 별개 `type` 이어야 하는가

계약금/잔금을 쪼갤 때와 **정확히 같은 이유**다(선행 인계 §2).

- 멱등키가 `(vehicle_id, type)` 이다. `purchase_deposit` 에 `ceo:true` 같은 **플래그**로 얹으면,
  이미 일반 계약금이 `open` 인 차에서 대표계약금이 **`already_open` 으로 조용히 버려진다.**
  화면에는 "이미 요청됨" 만 뜨고 대표에게는 **아무것도 도착하지 않는다** — 없는 것보다 나쁘다.
- ⇒ `type = 'purchase_deposit_ceo'` (board 가 이미 이 문자열로 보낸다. 오타 하나면 422라 board 는 상수 한 곳에만 둔다:
  `App\Services\CarErpReadService::REQ_PURCHASE_DEPOSIT_CEO`).

**두 신호는 서로 독립이다** — 같은 차에 `purchase_deposit` open 과 `purchase_deposit_ceo` open 이 **동시에 성립한다.**
board 는 이걸 막지 않는다(상태 재계산·coerce 금지 §11-4 항목 4). 의도된 동작이다:
평시 요청이 안 먹혀서 대표에게 다시 보내는 게 이 기능의 용도이기 때문이다.
⚠️ 다만 **"같은 계약금을 두 번 송금"** 이 될 수 있는 경로이므로, ERP 확인 화면에서 두 신호가 **같은 차·같은 금액**임이
보이도록 뱃지를 나란히 그려주면 좋겠다(판단은 ERP 세션).

---

## 3. ERP 요청사항 ① — type 추가 + 소멸 조건

| 신호 | `type` | 단위 | 뜻 | 닫히는 방법 |
|---|---|---|---|---|
| **대표 계약금** | `purchase_deposit_ceo` | 차량 1대 | "이 차 계약금 N원 보내주세요 — 대표님께" | **수동 확인만**(= `purchase_deposit` 과 동일 규칙) |

- 🚫 **"매입 미지급 0" 자동소멸 금지** — `purchase_deposit` 과 같은 이유다(선행 인계 §3).
  미지급 0 은 잔금까지 끝난 상태라, 그때까지 계약금 신호가 살아 있으면 거짓 신호가 인수 시점까지 남는다.
- 확인 주체도 `purchase_deposit` 과 동일(`canConfirmFinance()`).
- 금액 = `amount_krw`(정수 KRW). **표시 전용**이다 — 🚫 회계 컬럼(`final_payments`·`purchase_balance_payments`) 반영 금지(§11-5 그대로 유효).

### 3-1. 손대야 할 것으로 보이는 지점 (ERP 세션이 확인)

1. `BoardRequestController::store` 의 **type 검증 목록**(`in:` / enum). 여기 없으면 board 버튼이 422 만 뱉는다.
2. 요청 **라벨·뱃지 맵** — 화면 표기 **「대표계약금」**(Jin 확정 문구).
3. 알림톡 **수신자 라우팅 분기**(§4).
4. `GET /requests` 응답에 이 type 이 **그대로 실려야 한다** — board 칩은 응답의 `type` 을 키로 그린다.
   응답에서 `purchase_deposit` 으로 뭉뚱그려 내려주면 board 화면에서 대표계약금 칩이 사라져 **영업이 다시 누른다.**
5. 멱등키·소멸 규칙 테이블(§11-1)에 행 추가 + `docs/integration/board-portal-api.md §11` 개정.

---

## 4. ERP 요청사항 ② — 알림톡 수신자 (이 기능의 핵심)

**`purchase_deposit_ceo` 는 §5-1 시각 규칙 테이블을 건너뛴다.**
요일·시각·공휴일과 무관하게 **대표에게 즉시 발송**한다(= 지금 "매칭 0명 fallback" 으로 지정해 둔 그 수신자를 재사용하면 된다).

- 🚫 **board 는 시각을 판정하지 않는다.** "지금은 근무시간 밖" 같은 힌트를 payload 에 싣지 않는다
  (서버시각 단일 판정 원칙 — board TimeGate 와 같다). board 가 판정하면 두 판정이 갈려 수신자가 조용히 어긋난다.
  board 테스트가 이걸 고정한다(`test_ceo_deposit_request_is_a_separate_type_with_amount_and_no_time_hint`).
- 발송 규칙은 기존 그대로: **`created` 라인에만 발송**, `skipped`(already_open/forbidden)에는 발송 금지(§5-2).
- 담당자에게도 같이 보낼지는 **ERP·Jin 판단**. board 의견 = **대표 1명만**(담당자 부재가 전제인 신호라 겸사겸사 보내면 평시 요청과 구분이 안 된다).

### 4-1. ❓ 확인 요청 1순위 — BizM 템플릿

`erp_board_request` 본문에서 **"계약금/잔금" 이 치환변수인지, 본문에 박혀 있는지**에 따라 일이 갈린다.

- **변수라면** → 값만 「대표계약금」으로 바꿔 보내면 끝, **재검수 불필요**.
- **본문에 박혀 있다면** → 새 문구 = **재검수 대상**이고, ⚠️ **발신프로필 수만큼**(heymanerp·ssancarerp …) 각각 승인받아야 한다.
  하나만 승인받고 배포하면 나머지 인스턴스는 **발송 실패**한다(board 알림톡 2종에서 실제로 겪은 함정).

### 4-2. ❓ 확인 요청 2순위 — 지금 알림톡이 실제로 나가고 있나

board `CLAUDE.md` 기준 마지막으로 아는 상태는 **"BizM 템플릿 승인 + 수신자 번호 설정 전까지 실발송 0"** 이다(2026-08-11 배포분).
그 사이 승인·설정이 끝났는지 board 는 알 방법이 없다. **아직 실발송 0 이라면 이 기능도 캄캄한 채널 위에 얹히는 것**이므로,
대표 번호 설정 + 승인이 이 작업의 **선행조건**이 된다. ERP 세션이 현황을 한 줄로 알려주면 Jin 이 판단한다.

---

## 5. board 측 구현 (이미 dev 완료 — ERP 는 읽기만)

| 파일 | 변경 |
|---|---|
| `app/Services/CarErpReadService.php` | `REQ_PURCHASE_DEPOSIT_CEO = 'purchase_deposit_ceo'` 상수 + `PURCHASE_REQUEST_TYPES`(= 금액을 싣는 type)에 포함 |
| `resources/views/livewire/portal/_request-purchase-action.blade.php` | 재고(지급대기) 행 버튼 `$reqBtns` 에 1개 추가 → 버튼·칩이 같이 생성 |
| `lang/ko·en/portal.php` | `req_deposit_ceo_btn` = 「대표계약금」 / "Deposit (CEO)" |
| `tests/Feature/BoardTest.php` | 별개 type·금액·시각힌트 금지 / 일반 계약금과 독립 / lang ko·en 대칭 |

전송 payload (기존 매입요청과 동일 형태, `type` 만 다름):

```json
POST /api/internal/board/requests
{ "salesman_email": "sales@…", "type": "purchase_deposit_ceo", "vehicle_ids": [123], "amount_krw": 3000000 }
```

기대 응답 = 기존과 동일 `201 {batch_id: null, created: ["11가1111"], skipped: []}`
(매입 type 은 차량마다 별개 묶음이라 `batch_id` 는 null).

---

## 6. 하지 말 것

- 🚫 board payload 에 시각·긴급도·수신자 힌트를 요구하지 말 것 → 라우팅은 **type 하나로** 분기한다.
- 🚫 `purchase_deposit` 과 **합쳐서 하나로** 내려주지 말 것(§3-1 항목 4).
- 🚫 금액을 회계 컬럼에 반영하지 말 것(§11-5).
- 🚫 대표계약금이 open 이라고 해서 일반 계약금을 막지 말 것(그 반대도) — **독립 신호**다.

---

## 7. 배포 순서 (중요)

1. **ERP 먼저** — type 검증 목록 + 라벨/뱃지 + 라우팅 + `GET /requests` 반영 배포.
2. ERP 배포 확인 후 **board master 머지**(두 박스 자동배포). 순서가 뒤집히면 영업이 누른 버튼이 **422** 만 돌려준다.
3. e2e 검증: 재고(지급대기) 한 대에 금액 입력 → [대표계약금] → ① ERP 요청 목록에 「대표계약금」 뱃지 ②
   board 재고 행에 「대표계약금 요청중」 칩 ③ 대표에게 알림톡(승인·번호 설정이 끝난 뒤라면) ④ **근무시간 중에 눌러도** 대표에게 간다는 점 확인.

---

## 8. ERP 세션에 바라는 회신

1. §4-1 — `erp_board_request` 본문에서 계약금/잔금이 **변수인가 고정문구인가** (재검수 필요 여부).
2. §4-2 — 알림톡 **실발송 현황**(승인·대표 번호 설정 완료 여부).
3. 대표 수신자를 **어디서 읽을 것인가**(§5-1 fallback 수신자 재사용 여부).
4. 일반 계약금과 대표계약금이 **같은 차에 동시에 열리는 것**을 ERP 화면이 어떻게 보여줄지(중복 송금 방지 관점).
5. ERP 배포 시점 — board master 머지는 그 뒤에 Jin 허락을 받아 진행한다.

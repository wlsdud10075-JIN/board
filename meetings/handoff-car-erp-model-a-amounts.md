# 인계: board 연동 B 금액 = Model A 정렬 (car-erp 수신측 확인)

> 발신: board 세션 (2026-07-06). 수신: car-erp 세션.
> **결론: car-erp 코드 변경 불필요.** board 발신값만 바뀜 — 다만 의미가 바뀌므로 확인/인지 요청.
> 근거·설계 = board `meetings/board-flow-resequencing-2026-07-06.md`(부록 A) + 엑셀 `바탕화면/0. 헤이맨 수출차량현황표.xlsx`.

## 무엇이 바뀌었나 (board → car-erp 연동 B payload)

board 가 `won` 차량을 push 할 때 담는 **값의 계산**을 엑셀·ERP 설계(Model A)에 맞춰 정정:

| payload 필드 | 이전(board) | 지금(board, 2026-07-06) |
|---|---|---|
| `purchase_price_krw` | 차값 − 할인 | **원가 그대로(할인 미반영)** |
| `sale_price` | (차값 − 할인 + 매도비) → 판매통화 | **(차값 − 관례할인% − 차감액) → 판매통화. 매도비 제외** |
| `selling_fee_krw` | 매도비 | 매도비 (그대로) |
| `transport_fee` | 운임(판매통화) | 그대로 |
| (신규 개념) 차감액 | — | board 내부 `sale_discount_amount`(sell-side, 판매가에만) — payload엔 sale_price에 이미 반영돼 전달, 별도 필드 없음 |

## car-erp 가 확인할 것 (코드 변경은 없음)

1. **부가세마진 정합** — car-erp `Settlement::getVatMarginAttribute` = `purchase_price × 0.09`. 이제 board 가 `purchase_price` 를 **원가**로 보내므로 부가세마진이 원가 기준으로 정확해짐(설계 의도대로). ✅ car-erp 공식 그대로 OK.
2. **판매마진** — `getSalesMargin` = `정산판매금 − (purchase_price + selling_fee)`. board 가 `sale_price` 에서 **매도비를 뺐고**(매도비는 `selling_fee_krw` 로만), car-erp 는 이미 매도비를 매입쪽 비용으로 차감하는 설계라 정합. 즉 매도비가 **판매마진에서 정확히 1회만** 비용처리됨(이전 board 는 sale_price 에도 매도비를 넣어 상쇄시켰음 → 이제 제거). ✅
3. **⚠️ `sale_total_amount` 축소 (진행 중 FX 재설계와 맞물림)** — board 가 `sale_price` 에서 매도비를 빼면 car-erp `sale_total_amount`(= sale_price + transport + …)가 **매도비만큼 작아짐**. 이는:
   - 미수율 분모가 정확해짐(바이어가 실제 내는 값 = 판매가).
   - **2026-07-06 진행 중인 2차 정산 FX baseline 재설계**(`car-erp/docs/meetings/2026-07-06-settlement-fx-repivot.md`)가 `sale_total_amount` 를 baseline 으로 쓰므로, 이 축소를 **인지**하고 baseline 숫자 검증 시 반영할 것. (블로커 아님 — 오히려 정합에 도움.)
4. **수신 컨트롤러 주석 stale** — `PurchaseSyncController` line 83·135 주석 `"purchase_price_krw = 구입금액(차값−할인)만"` 이 낡음 → **"원가 그대로(Model A)"** 로 정정 권장(코드 동작은 그대로, 주석만).

## 영향 없는 것
- 페이로드 **필드 구조·contract_version(4) 그대로**. 수신 검증/멱등/첨부/바이어·컨사이니 로직 무변경.
- 기존 synced 차량(과거 push분)은 재계산 안 함 — car-erp 값은 관리가 편집 가능한 pre-fill.

## 대표 승인
- car-erp 무수정이라 "무수정 원칙 예외" 트리거는 해당 없음. 단 **할인 딜의 정산 pre-fill 숫자(판매마진·부가세마진·지급액)가 정확해지며 값이 바뀜** → 재무/대표 인지 권장(board 측 Jin 판단).

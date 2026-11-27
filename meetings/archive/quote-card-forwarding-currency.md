# 견적 카드 + 전달대기 통화 + n차 견적 (다음 세션 진입점)

> 2026-06-24 Jin 결정. 컨텍스트 한계로 **설계만 확정, 구현은 새 세션.** 이 문서가 빌드 스펙.
> 선행: 검차사진 다중공유(프록시) + 바이어명 선택 + respond.io UI 숨김은 **이미 배포 완료**(master). 그 위에 얹는다.

## 배경
영업이 전달대기(`/forwarding`)에서 검차사진을 카톡으로 바이어에게 보낼 때, **사진만으론 "이 차 얼마"가 안 보인다.** → 사진과 함께 **견적 카드**(바이어 통화로 차값/배송/최종)를 보낸다. 통화는 **보내는 영업이 그 순간 정하는 게 찐**이라 전달대기에 통화 토글을 둔다.

## 결정 (확정)
1. **전달대기 통화 토글** = KRW / USD / EUR. 영업이 보낼 때 선택.
2. **기본값 = KRW, 단 "아직 통화 안 정한 딜만".** 현지확인에서 이미 통화 잡은 딜(예: EUR 바이어)은 **그 통화로 표시**, 안 덮어씀.
3. **견적 카드 = 3줄: 차값 / 배송 / 최종** — 선택 통화로.
   - **차값 줄 = 차량금액(`carPriceKrw` = 차값−할인+매도비 44만 포함, A안).** → 차값+배송=최종 딱 맞음. 매도비 별도줄 ❌.
4. **n차 견적**: 한 번에 확정 안 됨. 1차/2차… 견적 발송. **바이어 거절 → 다시 전달대기로 복귀**해서 재견적.
5. 카드 헤더 = "SSANCAR". (카드 라벨 언어 EN vs KO = **미정, 새 세션서 결정** — 바이어 외국인이라 EN 유력.)

## ⚠️ advisor 제약 (money 사고 방지 — 반드시 지킬 것)
`offer_currency`/`offer_rate`는 **표시용이 아니라 낙찰 시 car-erp로 가는 판매통화·환율**(연동B `sale_currency`/`sale_exchange_rate`/운임비 환산, `SyncWonListingToCarErp`). 그래서:
- **드로어 열 때 통화 저장 금지.** 표시 기본값만 `offer_currency ?: 'KRW'`. **영업이 통화 버튼 직접 누를 때만** 저장 → 안 그러면 EUR 딜이 KRW로 덮여 **방금 배포·검증한 EUR 운임 매핑(`prod-test-amount-mapping.md`)이 깨짐.**
- **명시적 통화 확정 시**: `offer_rate`(라이브 스냅샷, ExchangeRateService) **+ `final_price = totalKrw()` 재스냅샷을 함께.** 그래야 카드 최종 = `offerAmount().amount` = 차값+배송이 일치.
- **카드 최종 줄 = `offerAmount()`(final_price 기반), 별도 라이브 계산 금지** (안 그러면 car_cost 외화+환율변동 시 바이어가 본 값 ≠ car-erp 값으로 드리프트).
- 검증: `차값 + 배송 == 최종 == offerAmount().amount` (KRW/USD/EUR 각각).

## 구현 계획
**A. 통화 토글 (forwarding 컴포넌트)**
- `quoteCurrency` 프로퍼티. `openDetail`에서 = `$l->offer_currency ?: 'KRW'` (저장 ❌, 표시만).
- `setQuoteCurrency($cur)` (KRW/USD/EUR): ExchangeRateService로 라이브환율 → `offer_rate` 스냅샷(KRW=1) + `final_price = totalKrw($usd,$eur)` 재스냅샷 + `offer_currency=$cur` 저장(`saveQuietly` 또는 일반 save — won-트리거 무관, status=inspected).
- 드로어에 토글 버튼 + 3줄 금액(차값/배송/최종) 선택통화 표시(영업이 보기 전 확인).

**B. 견적 카드 (클라이언트 캔버스 → 공유 파일에 prepend)**
- `window.fwdShare`를 `(quoteData, photos)`로 확장. `buildQuoteCard(quoteData)` = canvas로 카드 그려 `toBlob`→File.
- 공유 = `[견적카드, ...사진]` → `navigator.share({files})`. (지금 프록시 공유 구조에 카드 한 장 추가만.)
- quoteData = {vehicle, currency, car, shipping, total} — 컴포넌트가 PHP에서 계산해 `@js`로 전달. car/shipping/total은 위 제약대로 offer_rate 기반 일관 환산.
- 전제: 통화·금액 확정 안 됐으면(final_price null) 카드 없이 사진만(또는 "가격 협의중").

**C. n차 견적 + 거절→전달대기 복귀 (상태흐름)**
- 현재: `awaiting_buyer` → `rejected`(거절, 사실상 종료). → **거절 시 다시 전달대기(`inspected`)로 되돌리는 "재견적" 경로** 필요.
- verdicts(`/verdicts`) 거절 처리에 "재견적(전달대기로)" 옵션 또는 거절=자동 inspected 복귀. **TRANSITIONS에 rejected→inspected(또는 awaiting_buyer→inspected) 추가** 검토(모델 가드).
- **견적 차수(1차/2차) 추적**: 컬럼 `quote_round`(int, 기본0/1) 증가 or 카드에 차수 표기. 단순화 가능하면 차수 생략하고 "재견적 가능"만. → **새 세션서 범위 결정.**
- ⚠️ 거절→복귀 시 SalesmanScope·알림(notify/poll inspected count)·연동 영향 점검.

## 테스트
- 통화 토글 저장은 명시적일 때만(드로어 열기로 offer_currency 안 변함 — EUR 딜 보존 테스트).
- 카드 금액 일관성: 차값+배송==최종==offerAmount (KRW/EUR).
- 거절→전달대기 복귀 전이 + 재견적.

## 관련
권위 메모리 [[board-forwarding-photos-manage]], 금액매핑 [[board-amount-mapping]](`prod-test-amount-mapping.md` 깨지지 않게), 영업 e2e [[board-sales-end-to-end]]. 사용자=Jin [[user-jin]]. 프록시 공유=`PhotoController`+`forwarding/index.blade.php`.

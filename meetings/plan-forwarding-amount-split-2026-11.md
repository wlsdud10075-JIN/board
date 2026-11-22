# 전달 대기 금액 칸 — 구매탭 방식(총판매가·운임비 USD)으로 바꾸기 · 준비 메모

> 2026-11-22 Jin: 「전달 대기도 구매탭처럼 금액 계산되게 바꿀 수 있게 **준비만** 해주고, 이거 관련해서는 좀 더 알아봐야겠다.」
> 상태 = **미착수 · 결정 대기**. 이 문서는 착수할 때 바로 시작할 수 있게 현황·영향 범위·정할 것을 모아 둔 것이다. 🚫 Jin 지시 전 코드 착수 금지.

## 1. 지금 두 화면이 다르다

| | 전달 대기 `/forwarding` | 구매탭 `/auction` (2026-10-22~) |
|---|---|---|
| 입력 | 차값 · 할인율(%) · 차감액(원) · 배송비 선택(1640/1740/1840 USD) · 견적 통화 | 차값 · 매도비 · **총판매가 · 운임비 USD · 환율 USD/EUR** · 견적 통화 |
| 계산 | 차량 가격 = 차값 − 차값×할인율 − 차감액 / 총액 = 차량 가격 + 배송비×오늘 USD 환율 (`forwarding/index.blade.php` `workingKrw()`) | 운임비(견적통화) = 운임비 USD × USD 환율 ÷ 견적통화 환율(정수) / 판매가 = 총판매가 − 운임비 (`auction/index.blade.php` `selfSplit()`) |
| 저장 칸 | `discount_rate` · `sale_discount_amount` · `shipping_usd` · `offer_currency` · `offer_rate` · `final_price`(= `totalKrw()`) | `sale_price` · `transport_fee` · `transport_fee_usd` · `offer_rate_usd` · `offer_rate_eur` · `offer_currency` · `offer_rate` |
| 환율 | 오늘 환율 자동(사람이 안 적음) | 사람이 적음(미리 채우지 않음) |

구매탭에서 10-22 에 옛 칸(할인율·차감액·배송 선택)을 뺐지만 전달 대기는 그대로 뒀다 — 견적 단계 값은 컬럼에 남기기로 했기 때문(SKILLS §14-5).

## 2. 실제 사용 기록 (2026-11-22 두 박스 운영 DB 읽기 전용 집계)

| | heymanboard | ssancarboard |
|---|---|---|
| 전달 대기를 거친 차(살아 있는 행) | 14대 (8월 4 · 9월 8 · 10월 2 · 11월 0) | 2대 |
| 셀프검차매입(전달 대기 건너뜀) | 109대 | 149대 |
| 할인율 적음 | 1대(4%) | 0 |
| 차감액 적음 | 0 | 0 |
| 배송비 선택 | 0 | 0 |
| 견적 통화 고름 | 8대(EUR 5 · USD 2 · KRW 1) | 1대 |

- 「전달 완료」를 누른 기록(삭제된 차 포함): heymanboard 영업 3명 30건 + 관리 계정 3건, 마지막 2026-10-08 / ssancarboard 2건(10-28 테스트 · 10-31 영업 1명).
- 구매확정까지 간 heymanboard 13대 중 판매가가 있는 5대는 **할인율·배송비 없이 판매가만 따로** 적혀 있다(예: 차값 870만 원 → 5,700 EUR). 전달 대기 계산식으로 판매가를 만든 흔적이 없다.
- ⇒ 전달 대기는 금액을 정하는 곳으로 쓰이지 않았고, 옛 금액 칸을 바꾸거나 없애도 기존 습관과 충돌할 일이 거의 없다.
- 확인 못 한 것: 바이어 링크(견적 페이지)를 실제로 몇 번 열었는지 — 기록이 없다.

## 3. 바꾼다면 손댈 곳 (grep 2026-11-22)

- `resources/views/livewire/forwarding/index.blade.php` — 입력 칸 · `workingKrw()` · 미리보기 · 「적용」 저장 · 통화 필수 락.
- `app/Models/PurchaseListing.php` — `carPriceKrw()` · `totalKrw()` · `offerAmount()` · `offerDisplay()`(목록 「현지 최종금액」·바이어 페이지가 쓰는 표시값).
- `app/Jobs/SendOfferToBuyer.php` — 바이어에게 보내는 견적 금액.
- 바이어 공개 견적 페이지(링크) — 금액 표시가 `offerAmount()` 기준.
- `resources/views/livewire/verdicts/index.blade.php` · `listings/index.blade.php` · `manage/index.blade.php` — 견적 금액 표시·편집 칸.
- `resources/views/livewire/auction/index.blade.php` — 전달 대기에서 적은 총판매가·운임비가 구매탭에 **그대로 미리 들어오는지**(같은 컬럼을 쓰면 자동으로 이어진다 — 지금은 `sale_price`+`transport_fee` 로 총판매가를 되살린다).
- `app/Jobs/SyncWonListingToCarErp.php` — 판매가가 없을 때 `shipping_usd`·`offerAmount()` 로 파생하던 경로(2026-10-16 「지어내기 폐지」 뒤 통화 없으면 비움). 옛 칸을 없애면 이 폴백도 정리 대상.
- `config/board.php` `shipping_options` — 배송비 선택지.
- 테스트(`BoardTest` 의 forwarding·offer·totalKrw 관련), 업무 가이드 「바이어에게 전달」(`resources/guides/buyer-forward/`) + 그림, 챗봇 카드 「전달 대기 금액·견적 계산」·「견적 변경 후 공유·전달이 안 됨」·「전달 완료와 바이어명」, 씬 점검 기대값.

## 4. 정할 것 (Jin)

1. **방향**: (가) 전달 대기를 구매탭과 같은 칸(총판매가·운임비 USD·환율)으로 바꾼다 / (나) 전달 대기에서 금액 칸을 아예 빼고 「넘기기」만 남긴다(금액은 구매탭에서만) / (다) 그대로 둔다.
2. (가)면 — 환율을 구매탭처럼 **사람이 적게** 할지, 전달 단계는 오늘 환율 자동으로 둘지. 구매탭은 「합의환율」이라 사람이 적는다.
3. 바이어 견적 페이지에 무엇을 보일지 — 총판매가 하나 / 판매가 + 운임비 나눠서.
4. 옛 칸 값(`discount_rate` 1대 · `shipping_usd` 0대)의 처리 — 컬럼은 남기고 화면에서만 빼는 쪽이 안전(연동 B payload 무변).
5. 매물 표시가·차값과 총판매가의 관계 — 전달 단계에서 「이상 총판매가」(참고값)를 같이 보일지.
6. ERP 영향 — 판매 금액은 구매확정 때 가므로 전달 단계만 바꾸면 ERP 무변으로 보인다(착수 때 car-erp 세션에 확인).

## 5. 착수 순서(안)

1. 4번 결정 → 2. forwarding 화면·모델 계산 한 곳으로(`selfSplit` 을 모델/지원 클래스로 올려 두 화면이 같이 쓴다) → 3. 바이어 페이지·Job → 4. 테스트 → 5. Jin 로컬 확인 → 6. 가이드·카드·씬 점검 → 7. 배포(Jin 지시).

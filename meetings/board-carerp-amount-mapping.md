# board → car-erp 금액 매핑 (메신저 대체) — 설계 + 엑셀 역산 + 미팅 과제

> 2026-06-23. #3「금액 매칭」설계. 목표 = car-erp 직원이 메신저 받아 수기입력하던 금액·바이어·컨사이니를 board(낙찰 시점)가 연동B로 자동 전송. 권위 엑셀 = `1. 헤이맨 수출차량현황표.xlsx`(현 경로, master 제외 대상).

## 엑셀(헤이맨 마스터시트) 판매측 산정 구조 — 실측 역산
```
판매총합계(외화) = 판매금액(차량) + 운임비 + 커미션(+기타)
판매금원화(KRW)  = 판매총합계 × 환율
```
검산: R3(EUR,1684) 8,796+1,504+60=10,360 ×1684=17,446,240 ✓ / R4 4,384+1,516=5,900 ×1676=9,888,400 ✓ / R23(USD,1458) 5,300+975=6,275 ×1458=9,148,950 ✓

### 마진 구조 (핵심 발견)
- **판매마진 = 정산판매금원화 − 매입금액 ≈ 0, 대부분 마이너스**: R3 −768,146 / R4 −74,226 / R5 −596,235 / R23 +7,362. → **차량은 원가(또는 약간 손해)에 판다. 판매가에 마크업 없음.**
- **부가세마진 = 구입금액 × 9% (회사 진짜 수익원)**: R3 15,160,000×9%=1,364,400 / R4 7,300,000×9%=657,000 / R5 1,134,000 / R23 675,000 (전 행 일치). = 수출 부가세 환급.
- 따라서 **board 원가기반 최종금액 ≈ 엑셀 판매금원화** (우연 아님, 원가판매라). 운임비 = `운임USD × (USD환율/판매통화환율)` 규칙적(board shipping_usd로 재현 가능).
- 판매금액(차량)×환율 / 매입합계 비율: R3 0.95 / R4 1.006 / R5 0.96 / R23 1.008 → **원가 근처 ±5% 손조정**(공식 아님, 협상/라운딩 추정).

## 탭별 매핑 결론 (Jin 확정 방향)
- **매입탭 = board 확정값 전송**: 매입가(구입금액=차값→KRW−할인) / 매도비(440k) / 운임비(shipping_usd, USD) / 정산계좌 / 바이어 / (컨사이니).
- **판매탭 = board pre-fill, car-erp 관리 확정**: board 최종금액 ≈ 판매금원화. 단 ①판매통화(EUR/USD) 결정 ②KRW총액→통화 환산 후 판매금액(차량)/운임비 분해 ③딜별 ±5% = 관리 몫.
- **정산탭 = car-erp 고유**(부가세9%·판매마진·총마진·정산비율·지급액). board 안 건드림.

## car-erp 수신 그릇 (확인됨)
vehicles: `purchase_price`(매입가KRW) · `selling_fee`(매도비KRW,별도) · `transport_fee`(운임비,USD decimal) · `sale_price`(판매가,currency기준) · `currency`(enum USD/EUR/…) · `exchange_rate` · `buyer_id`→`buyers`(id,name) · `consignee_id`→`consignees`(id,name,buyer_id 하위). 현재 purchase-sync는 `purchase_price`(=board final_price, ⚠️부풀음)+정산계좌+첨부만 채움. 나머지 미수신.

## ⚠️ "±5%"는 규칙 아님 (정정 2026-06-23)
판매금액×환율/매입합계 = 0.95~1.01 흩어짐을 "딜별 손조정"으로 *해석*했으나 규칙 아님. 실제론 **판매가(통화)를 딜 시점 확정 + 환율은 인보이스 시점 → 시점차**일 가능성이 큼. (Jin의 "현지확인에서 통화+금액 확정" 흐름과 정합.) 원인 확정 = 미팅.

## 통화 결정 = Jin 확정 (2026-06-23): 현지확인에서 정함
**흐름**: 영업 매입예정서 1차 산정 → **현지확인에서 USD/EUR/KRW로 최종금액 확정** → 그 통화/금액/환율이 ①바이어 offer ②car-erp 판매탭. 
⚠️ **현재 동작과 다름**: `displayCurrency` 토글은 **표시 전용·저장 안 됨**(현지확인 가면 KRW 초기화). 이 흐름 구현하려면 **board에 `offer_currency` + 확정시점 환율 스냅샷 컬럼 추가** + `SendOfferToBuyer`를 하드코딩 USD → 택한 통화로 변경. (차값/배송 분해는 이미 모델에 있음.)

## ✅ 미팅 답 확정 (2026-06-23 Jin)
1. **판매가 0.95~1.01 편차 = 환율 시점차 맞음.** car-erp [관리]가 ERP에서 판매가 지정 시 그 시점 환율이 달라서. → board는 **pre-fill만, 관리가 확정**(정확 매칭 불필요·불가).
2. **부가세 9% = 고정, car-erp 정산씬에 박혀 있음.** board 무관. 정산탭 car-erp 고유.
3. **계약서/인보이스 = 현상 유지** — car-erp(DocumentFiller) 생성, board는 /portal `downloadDocs`로 다운로드(화이트리스트 `roro_contract`·`container_contract`·`*_invoice_packing` 이미 존재). board 자체 생성기 안 만듦. (참고: 새 엑셀 `★판매보고 통합 INVOICE`의 PROFORMA INVOICE = USD/EUR/독일 3종, 바이어 Name/ID/주소/국가/전화·은행·Invoice No·Model 필요 → board엔 차량명/모델 컬럼도 없고 바이어 상세도 없어 절반 못 채움 → car-erp 문서가 맞음.)

## 빌드 갈래 (설계 확정, 착수 대기)
**A. board-only (승인 무관, 즉시 가능)**: 통화 저장(`offer_currency`+확정시점 환율 스냅샷, displayCurrency 표시전용→저장) + 현지확인 확정 흐름 + `SendOfferToBuyer` 하드코딩USD→확정통화.
**B. car-erp 수정 (대표 승인+인계문서)**: payload v3(매입가=구입금액 분리+selling_fee/transport_fee/sale_price/currency/exchange_rate/buyer_id/consignee_id) + 수신 매핑 + buyers/consignees 목록 엔드포인트 + 도착 뱃지/알람.

## 추가 결정 (2026-06-23 Jin)
- **계약서 생성기**(TODO-B): board에서 내용 채워 바로 다운로드. 템플릿 = 엑셀 시트 `2.계약서`·`양도증명서`·`Invoice-EUR/USD`. **어느 문서부터 만들지 Jin 지정 대기**(매입계약서=판매자 / 판매계약서·인보이스=바이어).
- **계약금**: 은행연동 전까진 board 미보유. 흐름 = 바이어확정→구매탭 구매확정(won)→연동B→car-erp [관리] 뱃지/알람 보고 board가 보낸 계좌(payee)로 진행. board는 이미 payee 전송+won→sync. **car-erp측 신규 = 동기화 차량 도착 뱃지/알람(TaskAlarm 활용, 인계문서).**

## 빌드 스코프 (미팅 후 착수) — 대표 승인 + car-erp 인계 필요
1. board payload contract_version 3: 매입가=구입금액으로 의미교체 + `selling_fee`·`transport_fee`·`sale_price`·`currency`·`exchange_rate`·`buyer_id`·`consignee_id` 추가. SKILLS §12 갱신.
2. board 경매/구매 드로어: 판매통화 선택 + 바이어·컨사이니 **드롭다운**(car-erp 목록 조회). 신규 바이어=공란(car-erp 처리).
3. car-erp: 수신 매핑 추가 + **buyers/consignees 목록 엔드포인트**(board 드롭다운용) = 무수정 예외 → 대표 승인 + `meetings/handoff-car-erp-amount-mapping.md`(작성 예정).
4. 결정됨: 매입가=구입금액만 / 바이어·컨사이니=드롭다운 전송 / 판매가류=board pre-fill + 관리 확정(미팅 후 정밀화).

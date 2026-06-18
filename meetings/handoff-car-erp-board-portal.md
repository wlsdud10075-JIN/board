# 인계: car-erp → board 영업 포털 (읽기 API + 선적요청 + 서류 다운로드)

> **방향**: board 가 car-erp 에 요청하는 인계서. board 세션에서 작성(2026-06-18 딥인터뷰 확정). **Jin 이 car-erp 세션에 전달** → car-erp 가 자기 권위 스펙 문서를 만들고 구현. (크로스레포 규칙: 메모리·세션 직접통신 불가 → 커밋된 인계문서로 전파.)
> **목표**: 영업은 **board 만 씀**(car-erp 계정 없음). 낙찰 후 car-erp 로 넘어간 차의 **선적·재무·서류**를 영업이 board 한 곳에서 보고, 선적을 요청. car-erp 가 권위·계산, board 는 읽어서 표시 + 가벼운 선적요청만.

## 0. 통합 패턴 (전제)
- **board → car-erp `GET /api/internal/...` (HMAC)** = 읽기. purchase-sync(POST)의 **역방향**. 인증 = 기존 `VerifyPurchaseSyncHmac`(`X-Board-Signature: sha256=<raw body hmac>`) 패턴 재사용. board 는 빈 바디/쿼리 서명 방식만 car-erp 와 합의.
- **영업 식별 = 이메일**. board 가 `salesman_email`(= board `users.car_erp_salesman_email ?: 로그인 email`, 연동 B 와 동일 매핑) 전달 → car-erp 가 `salesmen` 매칭 → 그 영업의 차/정산만 반환. **본인 격리는 car-erp 가 강제**(board 가 남의 salesman_email 못 보냄).
- board 는 car-erp **DB 직접접근 0 유지**. car-erp 가 계산한 값만 받음(미수금 캐시·정산 확정액 등). board 에 재무로직/정산 재현 안 함(drift 방지).
- 전부 **읽기전용**. 유일한 쓰기 = §2 선적요청(가벼운 지시).

---

## 1. 빌드 순서 (이 순서로 API 제공)
**④ 재무 읽기 API → ③ 선적요청(읽기+요청) → ①② 서류 다운로드 API.** 그 뒤 지급게이트웨이(별도 트랙, `payment-disbursement-gateway.md`).

---

## 2. ④ 재무 읽기 API (먼저)
board 가 영업 본인 기준으로 표시할 **읽기전용 미러**. car-erp 가 계산해주는 숫자/목록을 그대로 반환.

신설 권장 (salesman_email 로 스코프):
- **미수금**: 영업 담당 차의 미수금 — `Vehicle.sale_unpaid_amount_krw_cache` + `receivable_risk`, 바이어 단위 집계 + 차량별. (`ReceivableHistory` 요약 선택)
- **매입금(미지급)**: `PurchaseBalancePayment`(type down/selling_fee/balance) 기준 차량별 매입 미지급.
- **정산내역**: `Settlement`(salesman_id) — status·금액(`amount_krw`/확정스냅샷)·확정일. **확정/계산은 car-erp, board 는 표시만.** 1차/2차·환차·이월은 **숫자만** 보여줘도 됨(board 재현 안 함).
- **판매내역**: 영업 담당 매도 차 목록 — `sale_price`·`currency`·바이어.
- **매입내역**: 영업 담당 매입 차 목록 — `purchase_price`·비용(cost_*)·매입일.

> 출처(Explore 확인): `Vehicle`(sale_unpaid_amount_krw_cache·receivable_risk), `FinalPayment`, `PurchaseBalancePayment`, `Settlement`, `ReceivableHistory`, `Buyer`/`Salesman`/`Consignee`. 기존 화면 = `/erp/salesmen/{id}/cashflow`·`/erp/receivables`·`/erp/settlements`(세션 role). → 같은 데이터의 **internal GET(HMAC) 버전** 신설.
> ⚠️ 통화: 판매=다중통화(`vehicles.currency`), 미수금 캐시는 KRW 환산본. board 표시 정책(§ 매입가 통화: 바이어전송 외화·계약금 한화)과 어긋나지 않게 **통화·환산값 둘 다 내려주면** board 가 토글.

## 3. ③ 선적요청 (읽기 + 가벼운 쓰기)
영업이 메신저로 하던 "이 차들 이 바이어/컨사이니로 RORO/컨테이너 보내라"를 시스템화.

**(a) 읽기**: 영업 본인의 **선적 가능 차** 목록 GET — car-erp 차 중 매도완료~선적전 상태(❓상태경계 확정 필요) + 각 차의 바이어 + 선택 가능한 **컨사이니 목록**(Buyer HasMany Consignee).

**(b) 쓰기(요청)**: board → car-erp POST(HMAC) — payload = **묶음 지시까지만**:
```
{ vehicle_ids:[...], buyer_id, consignee_id(❓기존선택 vs 신규), shipping_method: 'RORO'|'CONTAINER', salesman_email, requested_at }
```
- car-erp = 이 요청 수신 → **기존 car-erp 알람 발동**(❓Jin 이 만든 알람의 실체·매핑 = car-erp 가 결정) → 관리가 선적 실무(컨테이너번호·B/L·선적항·선적일·서류) 진행.
- **컨테이너번호·B/L·선적일 등 상세·서류는 car-erp 관리가 채움** — board/영업은 입력 안 함(모름).
- board 표시 = 요청 상태(접수/진행/완료) 정도. 권위는 car-erp `progress_status_cache`.

> car-erp 엔 별도 선적 모델/요청흐름 **없음**(전부 `vehicles` 컬럼 + progress). 선적요청을 **어디에 적재하고 어느 알람에 거나**는 car-erp 설계 영역.

## 4. ①② 서류 다운로드 API
car-erp 가 **이미 생성**(`DocumentFiller`, 4종: roro_contract·roro_invoice_packing·container_contract·container_invoice_packing). 기존 라우트 `GET /erp/vehicles/{id}/documents/{type}` = **세션 로그인 필요** → board 유저(car-erp 계정 없음)는 못 씀.
- **신설**: board 유저가 받을 수 있는 **internal 다운로드 통로**(HMAC/단기토큰). 옵션 — (a) car-erp internal GET 이 파일 바이트 반환→board 가 프록시 전달, (b) 단기 서명 URL 발급. car-erp 선택.
- board UI = 차량별 다운로드 버튼(`shipping_method` 로 RORO/컨테이너 분기), `document_access_logs` 감사 유지.

---

## 5. car-erp 가 결정/확정할 열린 항목
1. **선적요청 컨사이니** — 영업이 기존 컨사이니 선택 vs 신규 입력 허용?
2. **선적 가능 차 상태경계** — 어느 progress 부터 선적요청 대상?
3. **알람 매핑** — Jin 이 만든 car-erp 알람 = 무엇이며 선적요청을 어떻게 연결?
4. **운임비 매핑 충돌(지급게이트웨이 #2)** — 회의록은 "운임비=판매측 → 숫자 엉킴, 탁송비 추천" 경고, **Jin 은 운임비(transport_fee) 결정**. car-erp 가 매입측 배송금액을 transport_fee 에 넣어도 미수율/판매 숫자 안 엉키는지 **정합성 재확인** 후 확정.
5. 서류 다운로드 인증 방식(프록시 vs 서명URL).
6. HMAC 시크릿 — 읽기 API 도 `CAR_ERP_HMAC_SECRET` 공유 재사용 vs 별도.

## 6. board 측 작업(이 레포, 참고)
- `config/services.car_erp` 에 base_url 재사용 + 읽기 클라이언트(`CarErpReadService`, HMAC GET, 미설정 시 no-op 안전밸브).
- 영업 화면 신설: 재무 미러(④) → 선적요청(③) → 서류(①②). 전부 본인(`car_erp_salesman_email ?: email`) 스코프.
- car-erp 응답 캐싱(짧게) 여부는 board 측 결정(실시간 우선).
- **확정(기존 결정)**: 배송금액=운임비, 매입가 통화=바이어전송 외화·계약금 한화, car-erp 단일게이트·건당승인·한콜, respond.io Advanced 추후.

# [인계 → car-erp 세션] 연동B 금액/바이어/컨사이니 확장 (purchase-sync v3) + 목록 엔드포인트 + 도착 알람

> board → car-erp 단방향. board가 낙찰차를 넘길 때 **car-erp 직원이 메신저 받아 수기입력하던** 매입/판매탭 금액·바이어·컨사이니를 자동 전송. **이 문서 = 요청(ask). car-erp가 자기 권위 스펙(`docs/integration/purchase-sync-receiver.md`)에 반영·구현.** 근거·역산 = board `meetings/board-carerp-amount-mapping.md`. ⚠️ car-erp 무수정 원칙의 명시적 확장 → **대표 승인 필요**.

## 배경 (Jin 확인)
- 차량은 **원가에 판다(판매마진≈0)**, 회사 수익 = **부가세 환급 = 구입금액×9%**(car-erp 정산씬 고정, 변경 없음).
- 판매가/환율은 **관리가 ERP에서 지정 시점 환율로 확정** → board는 **pre-fill(추정치)만** 보내고 관리가 덮어씀(편집 가능 필드 유지).
- 계약서/인보이스 = car-erp DocumentFiller 생성 유지(board는 portal 다운로드). 변경 없음.

## 1. purchase-sync payload v3 (전방호환 — v1/v2 그대로 수용)
기존 v2 필드(`vehicle_number·owner_name·source·final_price·salesman_email·car_erp_salesman_id·c_no·payee_*·attachments[]`) **전부 유지**. 아래 **신규 필드 추가**(모두 nullable/optional):

| 신규 payload 필드 | 단위 | car-erp 매핑 컬럼 | 비고 |
|---|---|---|---|
| `purchase_price_krw` | KRW | `purchase_price` | **구입금액(차값−할인)만.** v3면 `final_price` 대신 **이 값**을 purchase_price 로. (v2는 기존대로 final_price) |
| `selling_fee_krw` | KRW | `selling_fee` | 매도비(440,000 등). 별도 컬럼 |
| `transport_fee_usd` | USD | `transport_fee` | 운임비(board shipping_usd). car-erp transport_fee=외화/USD라 그대로 |
| `sale_price` | sale_currency | `sale_price` | **pre-fill, 관리 편집.** board 차량금액→sale_currency 환산 |
| `sale_currency` | enum USD/EUR/KRW | `currency` | board 현지확인 확정 통화 |
| `sale_exchange_rate` | KRW/단위 | `exchange_rate` | **pre-fill, 관리가 지정시점 환율로 덮어씀** |
| `buyer_id` | int | `buyer_id` (FK buyers) | board 드롭다운 선택값. 없으면 null(관리 수동) |
| `consignee_id` | int | `consignee_id` (FK consignees) | 선택 바이어 하위. 없으면 null |

**수신 규칙**:
- `contract_version: 3` 수용(현재 1·2). 미지원 버전 422 유지.
- `purchase_price`: v3 & `purchase_price_krw` 있으면 그것, 아니면 기존 `final_price`(v2 호환). ⚠️ **현재 final_price→purchase_price 는 매도비·배송 포함이라 부풀어 있음** → v3로 구입금액만 받게 교정.
- `selling_fee`/`transport_fee`/`sale_price`/`currency`/`exchange_rate`: 값 있으면 채움(관리가 이후 편집). 멱등(기존차)이면 덮어쓸지/스킵할지는 car-erp 정책(권장: 기존차는 스킵).
- `buyer_id`/`consignee_id`: 존재·활성 검증 후 FK 세팅. consignee 는 buyer 하위인지 검증. 무효면 무시(null).
- 정산(부가세 9%·마진)은 **건드리지 않음** — 기존 정산씬 그대로.

## 2. 신규 읽기 엔드포인트 (board 드롭다운용 — board-portal-api HMAC GET 패턴 재사용)
board 경매/구매 화면에서 바이어·컨사이니를 **car-erp 목록에서 선택**하기 위함. 인증 = 기존 `VerifyBoardReadHmac`(CAR_ERP_READ_HMAC_SECRET, canonical http_build_query) 동일.

- `GET /api/internal/board/buyers?salesman_email=` → `{ "count": N, "data": [ {"id":int, "name":str, "country":str|null} ] }` (활성 바이어. 영업 스코프 여부는 car-erp 정책 — 전체 허용 권장: 신차 바이어 지정 가능해야).
- `GET /api/internal/board/consignees?buyer_id=&salesman_email=` → `{ "count": N, "data": [ {"id":int, "name":str} ] }` (해당 buyer 하위 활성 컨사이니).
- 권위 응답키는 car-erp가 확정 → board `meetings/board-carerp-amount-mapping.md` 에 역링크.

## 3. 도착 알람/뱃지 (계약금 처리 트리거)
board `won`→purchase-sync 로 차량 생성 시, [관리]에게 **신규 매입차 도착 알람/뱃지**(기존 TaskAlarm 활용). 관리가 보고 board가 보낸 정산계좌(`purchase_seller_*`)로 계약금 진행. (계약금 자체는 은행연동 전까지 board 미보유.)

## board 측 대응 (board 세션이 별도 구현 — 참고)
- payload v3 송신(SyncWonListingToCarErp + SKILLS §12 갱신), 현지확인 통화확정(offer_currency), 경매/구매 드로어 바이어·컨사이니 드롭다운(위 2 엔드포인트 호출, 미구현 시 graceful degrade).

## 체크리스트 (car-erp)
- [ ] 대표 승인 (무수정 예외 확장)
- [ ] PurchaseSyncController v3 신규 필드 수신·매핑 (purchase_price 교정 포함)
- [ ] buyers / consignees 목록 엔드포인트 2개 (HMAC GET)
- [ ] 도착 알람/뱃지
- [ ] `docs/integration/purchase-sync-receiver.md` + `board-portal-api.md` 갱신(권위), board 문서에 역링크

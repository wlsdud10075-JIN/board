# 주말 개발 계획 — ① 컨테이너 운임비 → 판매통화 환산·총판매가·면장 미러 ② 여권 Name·ID + board 전용 Travel Services Invoice ③ 포털 바이어 적립금

> 2026-10-28 Jin 요구(「기록만 해놔줘」, 주말 개발 예정). **양쪽 코드 0 — 결정값 확정 단계.**
> 경위: board 초안 → car-erp 세션 사실 확인(파일:줄 대조) → Jin 2차(문서는 board 전용) → **Jin 결정값(car-erp 세션에서 직접 답, 2026-10-28)** 반영. 받는 쪽 스펙은 car-erp 문서(`purchase-sync-receiver.md` ① · `board-portal-api.md` ②)가 권위 — car-erp 세션이 쓴다. board 는 SKILLS §12·§14 에 보내는 쪽만.

## ① 컨테이너 운임비 USD → ERP 판매통화 운임비 자동 환산 (+ 총판매가·면장은 기존 cascade)

> 📎 **받는 쪽 권위 = car-erp `docs/integration/board-portal-api.md` §5** `transport_fee_usd_total` 아래 「💱 판매통화 환산 → `vehicles.transport_fee`」(car-erp dev `e1c86a28`). 환율 없으면 선적요청 응답 `skipped[]` 사유 `no_rate`. 여기엔 복사하지 않는다 — board 선적 계획이 그 사유를 사람 말로 보여줄 때 lang 키 1개 추가(착수 때).

### Jin 원문 요지
선적묶음에서 CONTAINER 를 고르면 나오는 운임비 USD 칸이 ERP 판매탭의 환율로 그 차의 판매통화로 환산돼 운임비에 기록되고, 판매가 + 운임비 = 총판매가, 그 총판매가가 면장에 미러되게.

### 사실(확인)
- board: 선적 계획 컨테이너 운임비 = 묶음 총액 USD(`portal/index.blade.php:693-768`, `transport_fee_usd_total`, CONTAINER 일 때만 전송). won 시 payload = `sale_price` · `sale_currency` · `sale_exchange_rate`(→ ERP `exchange_rate`) · `transport_fee`(견적통화, 셀프검차/운임 USD 적은 차만) · `transport_fee_usd` · `usd_exchange_rate`. **board 변경 없음.**
- ERP: 1/N = `ShippingRequest::splitFreightUsd()`(`app/Models/ShippingRequest.php:80-104`) → `ShippingRequestController.php:231-236·:492·:503-504` 가 `vehicles.transport_fee_usd`(USD 메모) **빈 칸만** 기입. 환율 환산·`transport_fee` 기입은 없다.
- ERP: 실컬럼 = `vehicles.exchange_rate`(판매통화 KRW/단위) · `vehicles.usd_exchange_rate`(nullable, 지금은 어떤 계산에도 안 쓰임) · `vehicles.transport_fee_usd`. `sale_exchange_rate` 는 컬럼이 아니라 웹훅/응답 키.
- ERP: **총판매가 = accessor** `Vehicle::sale_total_amount`(`Vehicle.php:3405`) = 판매가 + 운임비 + 기타판매비용 + 수수료 + 자동상차 − tax_dc. **면장 기준액** `declaration_base_amount`(`:3427`) = 총판매가 − 기타판매비용 → **이미 운임 포함(CFR 성격)**. 저장 훅(`:996-1019`)이 면장을 채움/추종/수기 보존. 통관 SET `구매리스트!D15` = 운임, `차량인보이스!J22` = 판매금+운임(`ClearanceSetMapping.php:92-105`). ⇒ **`transport_fee` 만 채우면 총판매가·면장·통관 SET 은 자동.**
- ERP: 「운임 미확정」 정산 블로커 `Vehicle::isFreightConfirmedForSettlement()`(`:1885`, KRW·FOB 통과 / 그 외 CFR && transport_fee>0)는 `transport_fee` 만 본다 → 환산값이 들어가면 CFR 컨테이너 차의 블로커가 풀리고 `saved` 훅(`:1261-1269`)이 정산 자동 생성을 다시 건다. **= ①의 실제 효과.**
- 정정: 「후속 후보(1/N 적재 시 환산)」 문구는 board `SKILLS.md §12`(:193)에만 있었다. ERP 문서엔 없음 → ERP 입장에선 **신규**.

### ✅ 결정값(Jin 2026-10-28)
| # | 쟁점 | 결정 |
|---|---|---|
| Q1-1 | 판매가 고정 vs 총판매가 고정 | **판매가 고정.** 총판매가는 ERP 계산값(`sale_total_amount`)으로 따라간다. 별도 칸 없음 |
| Q1-2 | 이미 운임비(판매통화) 있는 차 | **빈 칸만**(USD 칸 `:503-504` 와 같은 규칙). 덮어쓰기 ❌ |
| Q1-3 | USD 환율 메모 없는 차 | **기록하지 않는다**(보류). 자동 환율 대입 ❌. Jin 「거의 없을 것」 |
| Q1-4 | 묶음 변경 시 재계산 | ❌. 빈 칸만. 묶음 변경은 사람이 ERP 에서 두 칸을 비우고 재sync |
| Q1-5 | 면장 FOB/CFR | 분기 불필요 — ERP 면장 기준액이 이미 운임 포함, FOB 차는 운임 0 |
| Q1-6 | 반올림 | 소수 2자리. 판매통화 USD 면 환산 없이 그대로, KRW 면 × `usd_exchange_rate` |
| Q1-7 | 소급 | ❌ |
| Q1-8 | 정산 자동 재트리거 | 결정 기록 없음 — ERP 기존 동작 그대로 둠(블로커 해제 = 의도된 효과로 간주). ⚠️ 운영 후 첫 사례에서 확인 |

### 분담
- **ERP**: 선적요청 1/N 적용 지점(`applyBundleToVehicle`)에서 `transport_fee_usd` 몫 × (`usd_exchange_rate` ÷ `exchange_rate`) → `transport_fee`, **빈 칸·환율 둘 다 있을 때만**. 테스트. 문서 = `purchase-sync-receiver.md`.
- **board**: 코드 0. SKILLS §12 「후속 후보」 문구를 결정값으로 교체 + 선적 계획 운임비 칸 안내 한 줄(선택).

## ② 여권 Name·ID → ERP 송금인 칸 + 「송금증이름」 검색 / Travel Services Invoice 는 **board 전용 문서**

> 📎 **받는 쪽 권위 = car-erp `board-portal-api.md` §14**(전용 엔드포인트 `POST /api/internal/board/vehicles/{id}/remitter`, 덮어쓰기+감사, 같은 값 no-op, 응답 `{vehicle_id, updated[], had_previous}`) + `purchase-sync-receiver.md` 「송금인 Name·ID 동봉」(`contract_version` 상향 없음, 빈 칸만, `fields_skipped.remitter_*=already_set`). payload 키 = **`remitter_name`**(필수 ≤100 trim) · **`remitter_id`**(선택 ≤64, 빈 문자열 = 무시, 지우기는 ERP 화면). 읽기 API·포털 응답엔 안 실린다. car-erp dev `e1c86a28`. board 는 이 키를 그대로 쓴다(복사 금지, 포인터만).

### Jin 원문 요지(1차 + 2차)
- 1차: Travel Services Invoice 에 여권 Name·ID 가 들어가야 한다. OCR, 안 되면 수기. board 매입예정 드로어에서 Name·ID 를 ERP 로 별도 전송 → ERP 기입 → 차량관리 「송금증이름」 검색.
- **2차: 「서류는 내가 별도로 줄게. Travel 시트의 Name 은 ERP 에서 오는 Name 이 아니다. board 에서만 사용하는 파일로 별도로 만들자.」** ⇒ 문서는 ERP 통관 SET 와 무관한 **board 전용**. ERP 양식(F5 수식 제거·F5/F6 매핑·다운로드 type)은 **전부 취소**(ERP 세션 통보 완료).

### 사실(확인)
- ERP: `vehicles` 에 송금인 칸 없음. 여권 기존 칸 = `buyers.passport_id`(평문) · `consignees.id_value`(cast 암호화). vehicles 암호화 관행 = `$casts 'encrypted'` + `AuditLog::MASKED_COLUMNS` + `column_labels`. 검색 단일 출처 = `Vehicle::scopeSearchAny`(`Vehicle.php:3182-3198`). 🚨 **암호화 칸은 LIKE 검색 불가** → 검색은 이름만.
- board: OCR 인프라 = 사내 GPU PC `qwen2.5vl:7b`(`App\Jobs\ExtractPayeeFromPhotos` 틀, 1100px/`num_ctx` 4096, §14-15). 여권은 **MRZ 2줄**(고정 포맷 + 체크디지트) → 모델에 MRZ 두 줄을 그대로 옮기게 하고 board 가 파싱·검증, 후보 제시만.
- board: xlsx 라이브러리 **없음**(composer.json 에 phpspreadsheet/openspout 없음). ERP 는 `maatwebsite/excel ^3.1`(= phpspreadsheet 래퍼). board 는 템플릿 셀 채우기만 필요하므로 `phpoffice/phpspreadsheet` 직접 추가가 가볍다(착수 때 결정).
- board 서류 보관 = `inspection_photos.kind=sales_document`, S3 `sales/documents`, `share_to_buyer=false`. PII 스탠스 = RRN·전화 미보유. 여권번호는 RRN 급.

### ✅ 결정값(Jin 2026-10-28)
| # | 쟁점 | 결정 |
|---|---|---|
| Q2-1 | 저장 단위 | **차량 단위 2칸** — ERP `vehicles` 송금인 이름(평문, 검색용) · 송금인 ID(encrypted cast + 감사 가림). board 도 `purchase_listings` 2칸(ID encrypted) |
| Q2-1b | ERP 화면 위치 | **차량 편집 「기본정보」 탭, 영업담당자 옆**(판매 탭 아님 — 매입예정 단계 값이라 바이어 없어도 들어온다). 라벨에 「board 기록」 |
| Q2-6 | 검색 | 차량관리 「송금증이름」 = **이름만**(ID 암호화). board 포털 재고 검색은 ERP 가 훑는 칸이라 자동(placeholder 갱신) |
| Q2-3 | 전송 경로 | **둘 다** — ① 새 엔드포인트(`Api/Internal`, HMAC, 차량 재인가) ② won 전에 적었으면 purchase-sync 동봉(v6) |
| Q2-4 | 덮어쓰기 | ⚠️ **두 경로 규칙이 다르다**: 전용 엔드포인트 = **덮어쓰기 + ERP 감사로그** / purchase-sync 동봉분 = 그 컨트롤러 재전송 규칙(**빈 칸만**/`already_set`). 스펙에 명기 |
| Q2-2 | 여권 이미지 | board = **OCR 뒤 폐기, Name·ID 만 보관**(ID encrypted). OCR·보관·폐기는 board 몫 |
| Q2-8 | OCR 결과 | **후보 제시만, 자동 기입 ❌**(양쪽 동의). 사람이 [적용] → 저장 → 전송 |
| Q2-5 | 저장 형식 | Name = 여권 표기 그대로(라틴 대문자 `SURNAME GIVEN NAMES`, MRZ `<` → 공백), **ID = 여권번호(Passport No.)**(영숫자, 공백 제거 — Q2-13). 개인번호·국적·생년월일은 **보관·전송 안 함** |
| Q2-7 | 문서 | **board 전용 파일** — Jin 이 양식을 board 에 직접 준다. ERP 통관 SET 에 찍지 않고 ERP 다운로드 type 도 안 만든다 |

### 아직 열린 것(양식 받으면 정함)
- **Q2-9 양식의 나머지 칸**(Date·Invoice No·주소·국가·전화 등)을 board 가 무엇으로 채우나 — board 엔 컨사이니·바이어 주소가 없다(포털 재고 응답엔 바이어명만). 빈 칸으로 두고 사람이 적나, ERP 응답(`/bundles`·`/inventory`)에서 가져오나, 양식에 그 칸이 없나 → **Jin 양식 파일을 받아 보고 결정.**
- **Q2-10 다운로드 위치** — 매입예정 드로어(차 1대 단위) 가정(송금인이 차량 단위). 포털 선적요청 묶음에도 둘지는 양식 보고.
- **Q2-11 접근 권한** — 드로어와 동일(영업 본인 차 + 관리·super) 가정.
- ✅ **Q2-13 「ID」= 여권번호(Passport No.)** — 2026-10-28 Jin 최종 확인(예시 파일명의 값이 개인번호였던 건 Jin 착오, 필요한 건 **여권번호**). 읽기 = MRZ 2행 앞 9자리(체크디지트 있음 — 실험 5장 중 4장 통과, 1장은 O/0 혼동으로 탈락)와 인쇄 칸 Passport No. 교차검증. 개인번호·국적·생년월일은 보관하지 않는다.
- **Q2-12 ERP 전송 조건** — ERP 차량이 없을 때(won 전) 드로어 전송 버튼은 비활성 + 「구매확정 시 함께 전송」 안내로 가정.

### 분담
- **ERP**(car-erp 세션): `vehicles` 2칸 마이그·cast·MASKED·labels · 기본정보 탭 UI(영업담당자 옆, 「board 기록」 라벨) · 새 엔드포인트(덮어쓰기+감사) · purchase-sync v6 수신(빈 칸만) · 검색 `orWhere('remitter_name')` 1줄. 문서 = `board-portal-api.md`·`purchase-sync-receiver.md`.
- **board**: 드로어 여권 섹션(업로드 → OCR Job(MRZ) → 후보 → [적용]) · `purchase_listings` 2칸(마이그 1, ID encrypted) · 감사 대상 추가(ID 는 가림) · 전송 버튼 + `CarErpReadService` 메서드 · purchase-sync v6 동봉 · **Travel Services Invoice 생성**(Jin 양식 템플릿 + xlsx 라이브러리 추가, 드로어 다운로드) · lang ko/en · 테스트. 문서 = SKILLS §12·§14.

### 🧪 OCR 사전 실험(2026-10-28, 사내 GPU `qwen2.5vl:7b`, 1100px·num_ctx 4096·temperature 0, 샘플 5장 = Jin 제공, 알바니아 1·조지아 2·코소보 2 — 값은 여기 적지 않는다(PII))
- 속도: 장당 **3.2~3.7초**(계좌추출의 사진 5~7장 15~20초와 같은 급).
- **인쇄 칸 읽기(SURNAME·GIVEN·PASSPORT_NO·PERSONAL_NO) = 5장 모두 값은 맞게 읽었다.** 단 조지아 1장은 성·이름을 서로 바꿔 적었고(MRZ 는 맞음), 코소보 1장은 여권번호 앞 글자 하나를 빠뜨렸다.
- **MRZ 전사는 5장 중 1장만 완전**(체크디지트 전부 통과). 나머지는 글자 누락(`<` 하나 빠짐 → 뒤 칸 전부 밀림, 성에서 글자 하나 탈락), `O`/`0` 혼동, 줄 끝 숫자 섞임. **체크디지트 검증이 전부 잡아냈다**(doc/dob/exp/composite).
- ⇒ 설계: **MRZ(체크디지트 통과분)와 인쇄 칸을 둘 다 읽어 교차검증** — 일치하면 후보 1개, 불일치하면 둘 다 후보(사람이 고름). MRZ 만 믿으면 4/5 가 탈락하고, 인쇄 칸만 믿으면 성·이름 뒤바뀜을 못 잡는다. 자동 기입 없음 원칙 그대로.
- 🚨 **「ID」가 무엇인지 확인 필요(Q2-13).** Jin 이 예시로 준 파일명의 ID 값은 그 알바니아 여권의 **여권번호가 아니라 개인번호(Numri personal / NID) 칸의 값**이다 — 여권번호는 별개 값(MRZ 2행 앞 9자리, 체크디지트 통과). 5장 모두 개인번호 칸이 따로 있다. **개인번호는 MRZ 선택칸에 있는 나라(알바니아·신형 조지아)와 없는 나라(코소보·구형 조지아)가 갈린다** → 인쇄 칸 읽기가 필수. Travel Services Invoice 의 ID = 개인번호인지 여권번호인지, 또는 둘 다 보관할지 Jin 결정.
- 이름 형식: 예시 파일명 = 성 + 이름 순(MRZ `성<<이름` 과 일치). 저장은 대문자 `성 이름` 제안 — 송금증 표기와 맞춰야 하면 Jin 확인.
- 샘플 해상도: 395×678 짜리 스크린샷도 읽혔다. 폰 원본(3024×4032)은 1100px 로 줄여도 충분.
- 실험 스크립트(일회성, 레포 밖) = scratchpad `passport_mrz_test.py`. 운영 코드엔 안 들어감. 샘플 이미지는 레포·S3 에 올리지 않는다.

### 인프라 메모
- GPU PC 는 `qwen2.5vl:7b` 단독 상주(챗봇 off). 여권 OCR 도 같은 모델·같은 Job 틀, 새 모델 안 올린다. 🚫 프롬프트 이중 부정 금지·1100px·`num_ctx` 4096(§14-15 함정 그대로).
- Jin 이 줄 것: Travel Services Invoice 양식(xlsx) + 여권 샘플 사진(프롬프트 맞춤용).

## ③ 포털 — 내 바이어의 적립금 잔액 보기 (2026-10-28 Jin 추가, 🅿️ 기획 · 코드 0)

### Jin 원문 요지
「포털에서 내 바이어의 적립금이 얼마나 있는지를 볼 수 있는 탭이 없는 것 같아.」

### 사실(확인)
- board 포털 탭 = 요약·미수금·재고·판매내역·정산내역·선적요청(`portal/index.blade.php:94` TABS). **적립금은 어느 탭·lang 키에도 없다**(grep 0건).
- ERP 에는 있다: `savings_statuses`(마이그 `2026_05_07_000007`) = buyer_id · vehicle_id(nullable) · currency(USD/JPY/EUR/GBP/CNY/KRW) · transaction_type(EARNED/REFUND/USED/ADJUSTMENT/CANCELLED) · savings(거래액 ±) · **balance(잔액 스냅샷)** · note. `Buyer::savingsStatuses()`(`Buyer.php:146`), 바이어 화면 「통화별 적립금 잔액」(`lang/ko/buyer.php:136`).
- ⚠️ ERP 는 **적립금(회사가 준 크레딧)과 바이어 현금 입금 원장(실제 들어온 외화, `BuyerCashReceipt`·`BuyerCashFee`)을 다른 것**으로 다룬다(`Buyer.php:157-158`, 기획 `docs/design/buyer-cash-ledger.md`). 대시보드 라벨 「디파짓(적립금 사용분)」(`admin_dash.php:147`).
- board 로 나오는 바이어 API = `GET /buyers`(board-portal-api.md §4) — `{id, name, country, purchase_locked, purchase_lock}` 만, **영업 본인 바이어 스코프**(Jin 결정). 적립금 필드 없음. 포털 미수금 탭은 바이어별 접기 헤더가 이미 있다(바이어 통화별 미납 합).

### 제안(Jin 결정 대기)
- **Q3-1 무엇을 보여주나.** (a) 적립금 잔액만 (b) 적립금 + 현금 디파짓 잔액 둘 다(ERP 가 구분해 두었으니 섞어 한 숫자로 내지 않는다). 제안 = **(a) 먼저**, 현금 디파짓은 Jin 이 원하면 같은 자리에 별도 줄.
- **Q3-2 잔액만 vs 이력.** 제안 = **통화별 잔액 + 최근 이력 N건(적립/사용/환불/조정, 차량번호·메모·일자)** — 잔액만 주면 「왜 줄었지」를 또 카톡으로 묻는다(§11 금액 분리 때와 같은 이유).
- **Q3-3 위치.** Jin 표현은 「탭」. 제안 = **새 탭 「바이어」**(본인 바이어 목록 → 바이어별 적립금 통화별 잔액·이력 펼침). 대안 = 미수금 탭 바이어 헤더에 「적립금 USD 1,200」 칩 — 탭 하나 덜 늘지만 미수금과 적립금이 한 화면에 섞인다.
- **Q3-4 스코프.** `/buyers` 와 동일 = 영업 본인 바이어만(관리·super 는 대리조회 규칙 그대로). 금액이지 PII 는 아니라 §3 화이트리스트 충돌 없음(ERP 확인 요청).
- **분담(예상)**: ERP = `GET /buyers/{id}/savings`(또는 `/buyers` 에 `savings_balances[]` 동봉 + 이력은 별도) · board = 탭 1개 + 서비스 메서드 + lang + 테스트. throttle 분당 120 공유 버킷에 호출 1~2개 추가(요약 탭 5개 수준엔 영향 없음).

### ERP 세션에 물어둔 것(2026-10-28 발신)
- `/buyers` 컨트롤러 위치(InternalPortalController 추정) · 통화별 잔액 계산이 「통화별 최신 balance 스냅샷」인지 합산인지 · 현금 디파짓 잔액 메서드 · §3 PII 판단 · 이력 노출 시 note 에 내부 메모가 섞이는지(영업에게 보여도 되는 문구인지).


# 주말 개발 계획 — ① 컨테이너 운임비 → 판매통화 환산·총판매가·면장 미러 ② 여권 Name·ID + board 전용 Travel Services Invoice ③ 포털 바이어 적립금 ④ 딜러 서류 OCR(사업자등록증·명함) ⑤ super 전체 바이어 선택

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
- ✅ **Q2-10 다운로드 위치 = 매입예정 탭(드로어, 차 1대 단위)** — Jin 2026-10-28 「문서 받는 건 매입예정탭에서 진행」. 포털 선적요청엔 두지 않는다.
- ✅ **메뉴·페이지 제목 변경: 「매입예정」 → 「매입예정(Invoice)」**(Jin 2026-10-28). 사이드바·페이지 제목·브라우저 title 모두. EN 은 「Purchase plan (Invoice)」 식으로 같은 괄호 구조(실제 키 확인 완료: `lang/ko/nav.php:11·:25` `listings` 2곳 + `lang/ko/listings.php:5` `heading`, en 동일 키). ② 기능과 같은 커밋으로.
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

### 📋 매입예정 드로어 「여권·Invoice」 섹션 — 체크리스트(2026-10-28 Jin 「빼먹은 게 있을까」 답)
확정된 것: 여권 이미지 업로드 → OCR(MRZ+인쇄칸 교차검증) → 후보 → [적용] → **Name 칸·여권번호 칸**(수기 입력도 가능) → 저장 → [ERP 로 전송] → [Travel Services Invoice 다운로드].
빠지기 쉬운 것(board 가 추가로 넣을 것):
- **폰 카메라 바로 촬영** — 파일 input 에 `capture="environment"`(영업이 현장에서 여권을 찍어 올린다, 드로어는 모바일 사용 비중이 높다).
- **OCR 불가 안내** — GPU PC 가 꺼져 있거나 응답 없으면 「OCR 불가 · 직접 입력」 한 줄(계좌추출 `payee_extraction_status` 와 같은 상태값 `passport_ocr_status`: idle/running/done/failed). 추출 중에는 전송 버튼만 잠근다(저장은 허용).
- **ERP 전송 상태 표시** — 「ERP 전송됨 · 시각」/「미전송」/「ERP 차량 없음(구매확정 시 함께 전송)」 — board 칸 `remitter_synced_at` 1개 추가. 전송 응답 `updated[]`·`had_previous` 를 사람 말로(§14-16 재전송 결과와 같은 방식).
- **감사 가림** — `remitter_id` 는 `BoardAudit::MASKED`(`app/Services/BoardAudit.php:15`, 현재 `payee_account`·`selling_fee_payee_account`)에 추가 → 로그엔 `***`. `remitter_name` 은 평문 기록.
- ✅ **상태와 무관하게 편집 가능 — Jin 확정(2026-10-28 「ERP 로 보낸 것도, 구매대기 상태로 있는 것도 다 가능해야 해」).** 드로어 `editable()`(`listings/index.blade.php:354-370`)은 won/synced·ERP 연동 차를 막는데, 여권 섹션은 그 게이트 밖(첨부 추가와 같은 예외, `:607`) — draft·accepted(구매대기)·won·synced 전부. 시간잠금 경매도 예외. **단 본인 글만**(SalesmanScope) + 관리·super.
- **`/manage` 드로어에도 같은 두 칸**(관리자 정정 경로 — 매입예정은 본인 글만 열리므로 남의 차 정정은 여기뿐). ERP 전송 버튼도 같이.
- **지우기는 전파되지 않는다** — ERP 스펙상 빈 문자열은 무시(지우기는 ERP 화면). board 에서 비우면 「ERP 값은 남습니다」 안내.
- **다운로드 권한·감사** — Travel Services Invoice(여권번호 포함) 다운로드 = 드로어를 여는 사람(본인·관리·super). 다운로드도 감사 행 1개(`action=download`, 파일명) 남긴다(선택).
- ✅ **목록 「Invoice」 칸 — 넣는다(Jin 2026-10-28, Q2-14).** `/listings` 표·폰 카드에 칸 1개: 「미입력 / 여권 입력됨 / ERP 전송됨」(검차상태 칸과 같은 역할, 가운데 정렬 규칙 동일). 값 = `remitter_name` 유무 + `remitter_synced_at` 유무로 판정, 추가 쿼리 0.
- 🅿️ **같은 바이어 다른 차에 복사 체크 — 보류(Jin 2026-10-28, Q2-15).** 이번 범위 밖. 필요해지면 재논의.
- ✅ **이미지 폐기 — Jin 직접 확인(2026-10-28 「이미지는 버려도 되고」, Q2-2).** OCR 뒤 이미지는 버리고 Name·여권번호만 남긴다. S3·ERP 첨부에 올리지 않는다.
- ✅ **드로어를 탭으로 나눈다 — Jin 제안(2026-10-28 「내용 자체에 각 탭을 나눠서 보기 좋게」).** 지금 드로어는 한 스크롤에 편집 폼·검차상태·첨부·판매가 재전송·삭제가 다 쌓여 있고 여권 섹션이 더 붙으면 폰에서 너무 길다. 제안 탭 4개(상단 pill, 폰은 가로 스크롤, 기본 = 「기본」):
  1. **기본** — 차량·출처·링크·예상가/차값·입금정보·검차 요청사항 + 판매가 후보완·「금액 다시 보내기」(금액 계열이라 여기)
  2. **검차** — 검차상태 한 줄·폴러 판정·「지금 있는 사진으로 전달대기로 넘기기」·검차 보류 사유
  3. **첨부** — 딜러 사진·서류 보기/추가(ERP 전송 포함)
  4. **Invoice** — 여권 업로드(카메라)·OCR 후보·Name·여권번호·[ERP 로 전송]·전송 상태·[Travel Services Invoice 다운로드]
  삭제 버튼은 탭 밖 드로어 맨 아래 그대로. 탭마다 「미입력」 점 표시(예: Invoice 미입력이면 탭 이름 옆 빨간 점) — 선택. `/manage` 드로어는 이번엔 탭 안 나누고 두 칸만 추가(범위 제한). 탭 구성은 Jin 이 조정 가능.
- 입력 정규화 — Name 대문자·연속 공백 1개, 여권번호 대문자·영숫자만(공백·하이픈 제거). 길이 제한은 ERP 스펙(이름 ≤100·ID ≤64)과 동일.
- EN 화면 — 라벨·안내 전부 lang 양쪽, 차량번호 `Plate::show`.

### 인프라 메모
- GPU PC 는 `qwen2.5vl:7b` 단독 상주(챗봇 off). 여권 OCR 도 같은 모델·같은 Job 틀, 새 모델 안 올린다. 🚫 프롬프트 이중 부정 금지·1100px·`num_ctx` 4096(§14-15 함정 그대로).
- Jin 이 줄 것: Travel Services Invoice 양식(xlsx) + 여권 샘플 사진(프롬프트 맞춤용).

## ③ 포털 「적립금」 탭 — 본인 바이어별 적립금 (2026-10-28 Jin, ✅ 결정 완료 · 코드 0)

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

### ERP 회신(2026-10-28, 코드 대조·변경 0) — 반영
- `GET /buyers` = `InternalPortalController::buyers`(`:442`, 라우트 `routes/api.php:53`), 스코프 = `Buyer::where('salesman_id',$sid)->where('is_active',true)` → 적립금도 같은 스코프. 바이어별 묶음 화면 = `GET /by-buyer`(`byBuyer` `:389`).
- 통화별 잔액 = **통화별 최신 `balance` 스냅샷**(합산 아님, `erp/buyers/index.blade.php:764-769`). 원화 환산 = `SavingsLedger::balanceKrw()`(선입선출, 적립 시점 환율). 단일 출처가 Volt 안 `loadSavings()` 라 API 로 내려면 **`SavingsLedger` 에 잔액 메서드를 두고 화면도 그걸 부르게**(ERP 몫, ERP SKILLS §8 #44).
- 현금 디파짓 잔액 = `BuyerCashReceipt::balanceFor()`(`:96`, 받은 합 − 배분 합). **적립금과 다른 돈** — 합치지 않고 따로(`savings_balances[]` / `cash_balances[]`). ⚠️ **현금 원장은 heymanerp·karabaerp 만 쓰고 ssancarerp 는 미사용**(Jin 2026-09-22) → ssancarboard 에 내리면 늘 0 이라 「없음」으로 오해. 내려면 회사 토글이 필요 → **「적립금만」으로 자르는 게 깔끔**(ERP 의견, board 동의).
- §3: 금액·통화·일자·차량번호·바이어명·`transaction_type` 허용. 🚨 **`note` 는 자유 입력(사람이 친 정정 사유·내부 사정)이 섞인다 → 내리지 않는다.** 이력 = type·금액·통화·일자·차량번호. `ADJUSTMENT`·`CANCELLED` 는 사유 없이 라벨만(또는 「조정」으로 합침).
- 엔드포인트 형태(ERP 제안, board 동의): **잔액 = `/by-buyer` 행에 `savings_balances[]`({currency, balance, balance_krw}) 동봉**(`/buyers` 는 드로어 드롭다운이라 매번 불려 분당 120 버킷 소모) · **이력 = `GET /buyers/{id}/savings?limit=N` 신설**(IDOR = `buyers.salesman_id` 본인 아니면 404/빈 응답, `/consignees` 방식). 새 탭이든 칩이든 ERP 엔드포인트는 같다.

### ✅ 결정값(Jin 2026-10-28)
| # | 쟁점 | 결정 |
|---|---|---|
| Q3-1 | 무엇을 | **적립금만**(현금 디파짓은 ssancarerp 미사용·회사 토글 필요라 범위 밖) |
| Q3-2 | 잔액/이력 | **통화별 잔액(+원화 환산) + 최근 이력**(종류·금액·통화·일자·차량번호, **note 없음**, 조정·취소는 라벨만) |
| Q3-3 | 위치 | **새 탭 「적립금」 — 본인 바이어별로 보여준다**(Jin 「적립금탭을 만들어서 본인 바이어별로 보여주자」). 탭 이름은 「바이어」가 아니라 **「적립금」** |
| Q3-4 | 스코프 | `/buyers` 와 동일 = 영업 본인 바이어(`buyers.salesman_id`)만. 관리·super 대리조회 규칙 그대로 |

> 📎 **받는 쪽 권위 = car-erp `board-portal-api.md` §4 표**(dev `e30223c6`): `GET /by-buyer` 행 `savings_balances[]=[{currency, balance, balance_krw}]` · `GET /buyers/{id}/savings?limit=N` → `{buyer_id, balances[], history[{id, type, amount, currency, date, vehicle_number}]}`, `note` 없음, `type` 원문(EARNED/USED/REFUND/ADJUSTMENT/CANCELLED — board 가 lang 라벨, 모르는 값은 원문), `limit` 기본 20·최대 100 최신순, 본인 바이어 아니면 **404**. board 는 이 키를 그대로 쓴다.

### 분담
- **ERP**: `SavingsLedger` 에 통화별 잔액 메서드(화면도 그걸 부르게) · `GET /by-buyer` 행 `savings_balances[]`({currency, balance, balance_krw}) 동봉 · `GET /buyers/{id}/savings?limit=N`(이력, note 제외, IDOR = 본인 바이어 아니면 404/빈 응답). 문서 = `board-portal-api.md` §4 표(ERP 세션).
- **board**: 포털 TABS 에 `savings` 추가(7번째, 선적요청 뒤) · 탭 = 본인 바이어 목록(바이어명·국가) → 바이어별 통화별 잔액 카드(원화 환산 보조 표기) → 펼치면 최근 이력 표(가운데 정렬·차량번호 `Plate::show`) · 잔액 0·이력 0 인 바이어도 행은 그린다(없는 척 금지) · 조회 실패 시 「조회 불가」 한 줄(§14-2 칩 원칙) · `CarErpReadService::buyerSavings()` · lang ko/en · 테스트(잔액 렌더·이력 note 미표시·스코프·실패 표시). 「↻ 갱신」 버튼 동작 동일. 호출 = 탭 진입 시 `/by-buyer` 1 + 펼친 바이어당 이력 1(지연 로드).

### ERP 세션에 물어둔 것(2026-10-28 발신, 회신 완료 — 위 반영)
- `/buyers` 컨트롤러 위치(InternalPortalController 추정) · 통화별 잔액 계산이 「통화별 최신 balance 스냅샷」인지 합산인지 · 현금 디파짓 잔액 메서드 · §3 PII 판단 · 이력 노출 시 note 에 내부 메모가 섞이는지(영업에게 보여도 되는 문구인지).

## ④ 딜러 서류 OCR — 사업자등록증 법인명·대표자 + 명함 휴대폰 → ERP 구입처·국내딜러 알림톡 (2026-10-28 Jin, ✅ 결정 완료 · 코드 0)

### Jin 원문 요지
사진 5장(구매확정 딜러 첨부)에서 **사업자등록증 → 법인명·대표자**, **명함 → 휴대폰번호**를 찾아 ERP **매입탭 구입처에 「법인명/대표자」**, **국내딜러 알림톡 탭에 휴대폰번호**를 넣는다. OCR 을 ERP 에서 따로 돌릴지, board 가 찾아 ERP 로 넘길지 판단 필요.

### 사실(확인)
- board 는 **같은 5장을 이미 읽고 있다** — `/auction` 구매확정 드로어의 계좌추출 Job(`ExtractPayeeFromPhotos`, `PayeeExtractor.php:31-46`)이 장마다 `doc_type`(자동차등록증·통장·**사업자등록증·명함**·차량사진·기타)을 판정하고 계좌·등록증 소유자를 후보로 낸다. 명함 연락처는 「계좌가 아니다」로 **의도적으로 버리는 중**(`:43`). ⇒ 법인명·대표자·휴대폰은 **같은 Job 의 프롬프트·파싱 확장**이다(호출 횟수 증가 0, 사진당 3~4초 그대로).
- ERP 칸: 구입처 = `vehicles.purchase_from`(자유서식 string, 검색 대상 `Vehicle.php:3190`) · 국내딜러 알림톡 번호 = `vehicles.deregistration_notice_phone`(「국내 딜러 알림톡 (말소등록증·입금완료)」, `lang/ko/vehicle.php:1238`, 없으면 `no_phone` 오류 `:1422`). 연동 B 는 지금 둘 다 안 보낸다(`purchase-sync-receiver.md:37` 매핑에 없음).
- ERP 엔 **비전 파이프라인이 없다** — `Services/Assistant/OllamaClient.php` 는 챗봇용 텍스트 호출뿐. ERP 에서 하려면 비전 클라이언트·Job·후보 UI 를 새로 짜야 한다(board 것과 중복).
- 사람 확인 주체: 딜러를 만난 사람은 **board 의 영업**(구매확정 시점). ERP 쪽 사용자(재무·관리)는 그 딜러를 모른다.
- board PII 스탠스 = **전화 미보유 원칙**(CLAUDE.md). 명함 휴대폰은 딜러(사업자) 연락처라 바이어·소유자 개인정보와 성격이 다르지만, 원칙 예외는 Jin 결정.

### 판단(board 제안) — **board 가 찾아서 ERP 로 넘긴다**
이유 ① 같은 사진·같은 시점·같은 Job(확장만) ② 확인하는 사람이 영업(계좌 후보 [적용] 과 같은 UX, 자동 기입 ❌) ③ ERP fill-if-empty 경로가 이미 있다 ④ ERP 는 비전 인프라 0. ERP 단독 OCR 은 같은 GPU 에 파이프라인을 하나 더 만들고, 확인자가 딜러를 모르는 사람이 되며, sync 뒤에야 돈다.
- 흐름: `/auction` 드로어 [이미지에서 계좌·소유자 찾기] → **[계좌·소유자·구입처·연락처 찾기]** 로 확장 → 후보 4종(계좌 / 등록증 소유자 / 법인명·대표자 / 휴대폰) → 각 [적용] → 구매확정 시 purchase-sync 에 동봉 → ERP fill-if-empty(`purchase_from`·`deregistration_notice_phone`). 재전송·후보완은 `/listings` 드로어 「금액 다시 보내기」 와 같은 길.
- payload: **분리해서 보낸다** — `seller_company`·`seller_ceo`·`seller_phone` 3개. 「법인명/대표자」 한 줄로 합치는 건 **ERP 가** `purchase_from` 에 넣을 때(형식을 ERP 가 소유, 나중에 칸이 쪼개져도 board 무변경). 🚫 board 가 합친 문자열을 보내지 않는다.
- board 보관: `purchase_listings` 에 `seller_company`·`seller_ceo`(평문) · `seller_phone`(**encrypted**, `BoardAudit::MASKED`, integration_events payload 로그에서도 마스킹 — 지금 payload 로그에 계좌가 어떻게 남는지 착수 때 확인). 감사 대상.
- ✅ **버튼 이름·후보 UX(Jin 2026-10-28)**: 현재 「📷 이미지에서 계좌·소유자 찾기」(`lang/ko/auction.php:145` `payee_extract.find`, en :144)는 매입처·연락처까지 찾게 되므로 **포괄적 이름으로** — **✅ 확정(Jin 2026-10-28 「그 이름으로 가자」) = 「📷 이미지에서 정보 찾기」**(EN 「Find info in images」), 버튼 아래 작은 글씨로 「계좌 · 소유자 · 매입처 · 연락처」. 같은 묶음의 문구(`searching`·`title`·`not_found`·`failed`·`wait`)도 「계좌·소유자」→「정보」로. **OCR 뒤 항목별 후보에서 고르는 방식은 지금 그대로 유지**(Jin) — 후보 섹션 4개: 계좌 후보([차값에 적용]/[매도비에 적용]) · 소유자 후보([소유자에 적용]) · **매입처 후보**(법인명·대표자 — [매입처에 적용]) · **연락처 후보**(휴대폰 — [연락처에 적용]). 못 찾은 항목만 「찾지 못했습니다 — 직접 입력」. 「계좌 없이 확정하기」 탈출구와 추출 중 확정 잠금은 그대로.
- 프롬프트 함정(§14-15 그대로): 이중 부정 금지 · 명함의 1566-/1588- 고객센터·유선번호와 **휴대폰(010-)** 구분 · 사업자등록증의 「상호(법인명)」 과 「대표자」 칸 각각 · 등록증 소유자와 섞지 않기(2026-10-23 사고 재발 금지).

### ✅ 결정값(Jin 2026-10-28)
- **Q4-1 OCR = board 에서 찾아 ERP 로 보낸다**(Jin 「board 에서 해서 보내줘」, ERP 동의).
- **Q4-2 휴대폰 = board 에 보관**(Jin 「그렇게 해줘」). 방식 = 계좌번호(`payee_account`)와 **동일**: DB 컬럼은 **암호화(encrypted cast)** — 마스킹이 아니라 복호 가능한 암호화라 **화면(드로어)에는 번호가 그대로 보인다**(볼 권한 = 그 차를 여는 사람). **마스킹은 로그에만**: 감사로그 `board_audit_logs` 는 `***`(`BoardAudit::MASKED`), ERP 전송 로그 `integration_events` payload 도 마스킹(착수 때 계좌 처리 방식 확인 후 동일하게). CLAUDE.md 「PII 스탠스」에 딜러 휴대폰을 **범위한정 예외**로 추가(계좌·서류와 같은 줄).
- **Q4-3 구입처 형식** — ERP `purchase_from` = 「법인명/대표자」(슬래시 구분)로 ERP 가 합성. 법인명만 있으면 「법인명」, 대표자만이면 「/대표자」? → ERP 가 정한다.
- **Q4-4 덮어쓰기** — purchase-sync 동봉 = 빈 칸만(기존 규칙). `/listings` 후보완 재전송도 빈 칸만. ERP 에서 수기로 넣은 구입처를 board 가 덮지 않는다.
- **Q4-5 명함이 여러 장/번호가 여러 개** — 후보 전부 제시(계좌 「전부 후보」 와 동일), 사람이 고른다.

### ERP 회신(2026-10-28, 코드 대조·변경 0) — 반영
- **OCR 위치 = board 에 동의**(ERP 비전 클라이언트 없음 확인, 확인 주체 = 첨부 올린 영업). ERP 는 받는 쪽 + 형식 소유(합성·하이픈)만. → **Q4-1 양쪽 합의**(Jin 확정 대기).
- `purchase_from` = string nullable 자유서식. 연동 B 는 지금 `purchase_source` 만 넣고 `purchase_from` 은 안 건드린다. 운영 관행 = 「{출처}-{상호}/{대표자}[/전화]」 를 사람마다 다르게 씀. **ERP 합성 = 접두사 없이 `{법인명}/{대표자}`**, 하나만 있으면 그것만(「/」 없이). 📏 말소 재촉 알림톡 본문은 구입처를 **12자에서 자른다** — 법인명이 길면 대표자가 잘림(현행과 같음). `purchase_from` 은 감사 대상.
- `deregistration_notice_phone` = string(20), 화면 저장은 `PhoneFormat::format()` 하이픈 형식(국가코드 없음), 발송 직전 `normalizePhone()` 이 숫자만 남겨 **저장 형식 무관하게 발송됨**. 말소등록증·입금완료·이체확인증 **3종 알림톡 공용 수신 번호**. ⚠️ 감사 대상 아님 → board 가 쓰기 시작하면 ERP 가 추가(ERP 몫).
- purchase-sync: **버전 상향 불필요**(모르는 필드 무시). 신규 생성·재전송 경로에 3블록(값 있고 빈 칸이면 채움, 아니면 `already_set`). `already_set` 키는 ERP 컬럼명 `purchase_from`·`deregistration_notice_phone` 으로 돌려준다. 합성 `purchase_from` 은 비어 있을 때만(사람이 친 값 보호).
- 휴대폰: ERP 감사 MASKED 아님(RRN·계좌만). §3 「연락처 PII 금지」는 board 로 **내려주는** 응답 화이트리스트라 board → ERP **들어오는** 값엔 해당 없음. ERP 안에선 딜러 전화가 이미 평문으로 다닌다.
- 📎 **받는 쪽 권위 = car-erp `purchase-sync-receiver.md` 「딜러 서류 OCR 동봉」 절**(dev `c0fe2889`): `seller_company`(≤100)·`seller_ceo`(≤50)·`seller_phone`(≤20, 숫자만), 버전 상향 없음, 신규·재전송 빈 칸만, `fields_skipped` 키 = `purchase_from`·`deregistration_notice_phone`. 합성·하이픈·감사 추가 = ERP. board 는 이 키·길이를 그대로 쓴다(복사 금지).
- ERP 문서 포인터 총괄: ① `board-portal-api.md` §5 · ② `board-portal-api.md` §14 + receiver 「송금인 Name·ID 동봉」 · ③ `board-portal-api.md` §4 · ④ receiver 「딜러 서류 OCR 동봉」.
- board 보내는 쪽 메모: `seller_phone` 은 **숫자만**(하이픈 제거) 보낸다 — ERP 가 저장 시 하이픈 형식으로 바꾸거나 발송 시 정규화하므로 어느 쪽이든 된다. 길이 ≤20.

### ERP 세션에 물어둔 것(2026-10-28 발신, 회신 완료 — 위 반영)
- `purchase_from` 현재 운영 값 형식(「상호/대표자」 관행이 이미 있는지) · `deregistration_notice_phone` 저장 형식(하이픈 유무·국가코드) 과 알림톡 발송 시 정규화 여부 · purchase-sync 수신에 3필드 fill-if-empty 추가 의견 · ERP 에서 OCR 을 따로 돌리는 쪽을 선호하는지(비전 인프라 없음 확인 요청).

## ⑤ 시스템관리자는 매핑 없이 전체 바이어 선택 (2026-10-28 Jin, e2e 중 발견 · ✅ 결정 「전체 바이어로 가자, 저녁 배포 같이」 · ERP dev 구현 완료 · board dev 구현 완료)

### Jin 원문 요지
「시스템관리자는 바이어 지정(영업 매핑) 안 돼 있어도 전체 바이어 선택이 가능하게 해야겠다. 그래야 테스트할 때 정상적으로 테스트할 수 있겠어.」

### 사실(확인)
- board `/auction` 드로어 바이어·컨사이니 목록 = **매물 등록자**의 매핑 이메일(`car_erp_salesman_email` 없으면 로그인 이메일)로 `GET /buyers` · `GET /consignees`(`auction/index.blade.php:130-153`).
- ERP: 그 이메일이 활성 영업으로 안 풀리면 **403**(`SalesmanResolver::resolveActiveOrFail`, §2 「서명 + 매핑 이중」) → 매핑 없는 super 가 등록한 차는 목록이 비고 **바이어 필수 락**(`conclude` :778)에 걸려 구매확정 불가. 연동 B 자체는 영업 못 찾으면 null(수동 배정)로 생성돼 안 막힌다.
- ERP `/buyers` 스코프 = `Buyer::where('salesman_id',$sid)->where('is_active')`(`InternalPortalController:444`), `consignees()` 는 `$ownsBuyer` 판정(`:529-541`). purchase-sync 는 `car_erp_salesman_id`(명시) 를 `salesman_email` 보다 우선(`PurchaseSyncController.php:645-650`).

### ✅ 확정·구현(2026-10-28)
- Q5-1 **ERP 가 super 판정** — 확정·양쪽 구현. board: `/auction` `buyerScope()` — 로그인 사용자가 super 면 `scope=all` + 본인 매핑 이메일(없으면 로그인 이메일), 영업·관리·**업무관리자**는 종전 등록자 스코프·플래그 없음(ERP 가 super 만 열므로 hasFullAccess 로 넓히지 않는다). 드롭다운에 ERP 가 주는 `salesman_name` 을 「· 이름」 으로 붙인다. 가드 = `test_super_asks_erp_for_all_buyers_with_own_email`·`test_non_super_keeps_creator_scope_without_all_flag`. 서비스 = `CarErpReadService::buyers($email, $all)`·`consignees(..., $all)`.
- ⚠️ **운영 설정**: board `/users` 의 super 계정 매핑(`car_erp_salesman_email`)에 **ERP super 로그인 이메일**을 넣어야 전체가 열린다(영업 이메일이면 그 영업 바이어만 = 종전). 두 박스 각각.
- Q5-2 **담당 영업 자동 배정 — Jin 결정 없음 → 종전대로 null(수동 배정)**. super 가 남의 영업 바이어로 확정한 차는 ERP 에서 담당을 손으로 붙인다. 열어 두려면 board 가 `/buyers` 행 `salesman_id` 를 `car_erp_salesman_id` 로 실어 보내면 된다(ERP 이미 지원 `:645`) — Jin 결정 시 반나절 미만.
- 배포 순서 = ERP 먼저(그 전엔 ERP 가 플래그를 몰라 종전 403 = 오늘과 같음, 깨지진 않음) → board master(Jin 허락).

### 합의안(board 제안 → ERP 수정 반영) — 기록
- **Q5-1 super 판정은 ERP 가 한다**(ERP 수정안 채택 권장): board 는 로그인 사용자가 super 면 `scope=all` 을 붙이고 `salesman_email` 에 **그 사람의 ERP 로그인 이메일**을 싣는다. ERP 는 `User(email, permission=super, 활성)` 일 때만 전체 활성 바이어/컨사이니(+`salesman_id`·`salesman_name` 동봉), 아니면 종전 403. 이유 = HMAC 호출 하나로 전 바이어가 열리지 않게(매핑 검증 유지, §2 IDOR 근거). ⇒ 운영에서 super 계정의 board 매핑 = **ERP super 이메일**(영업 이메일 아님).
- **Q5-2 담당 영업 자동 배정 = ERP 수신 폴백**(권장 ②): 영업을 못 풀었고 `buyer_id` 가 있으면 `vehicles.salesman_id = buyer.salesman_id`. board 변경 0, 규칙 한 곳. super 가 바이어 없이 재고매입하면 종전대로 null. (대안 ① = board 가 `/buyers` 행 `salesman_id` 를 `car_erp_salesman_id` 로 실음 — 규칙이 두 곳이라 비권장.)
- board 할 일(결정 뒤): `/auction` `buyers()`·`consignees()` 호출에 super 면 `scope=all` + 본인 이메일 · 드롭다운에 「(영업이름)」 표시 · 테스트 2건(super 면 scope=all·영업이면 없음). `/portal` 대리실행과는 별개(그쪽은 종전 `isViewingOther` 규칙).
- **오늘 e2e 임시 우회(코드 0)**: scope=all 이 아직 없으므로 board `/users` 에서 super 계정 매핑에 **ERP 영업 이메일**(테스트 바이어의 담당)을 넣는다 — 구현 뒤엔 ERP super 이메일로 바꾼다.
- ERP 규모 1~2시간(두 메서드 분기 + 테스트), board 반나절 미만. 문서 = ERP `board-portal-api.md` §4 `/buyers`·`/consignees` + receiver 「영업 매칭」 폴백(ERP 세션).


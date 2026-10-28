# 주말 개발 계획 — ① 컨테이너 운임비 → 판매통화 환산·총판매가·면장 미러 ② 여권 Name·ID + board 전용 Travel Services Invoice

> 2026-10-28 Jin 요구(「기록만 해놔줘」, 주말 개발 예정). **양쪽 코드 0 — 결정값 확정 단계.**
> 경위: board 초안 → car-erp 세션 사실 확인(파일:줄 대조) → Jin 2차(문서는 board 전용) → **Jin 결정값(car-erp 세션에서 직접 답, 2026-10-28)** 반영. 받는 쪽 스펙은 car-erp 문서(`purchase-sync-receiver.md` ① · `board-portal-api.md` ②)가 권위 — car-erp 세션이 쓴다. board 는 SKILLS §12·§14 에 보내는 쪽만.

## ① 컨테이너 운임비 USD → ERP 판매통화 운임비 자동 환산 (+ 총판매가·면장은 기존 cascade)

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
| Q2-5 | 저장 형식 | Name = 여권 표기 그대로(라틴 대문자 `SURNAME GIVEN NAMES`, MRZ `<` → 공백), ID = 여권번호(영숫자, 공백 제거). 국적·생년월일은 **보관·전송 안 함** |
| Q2-7 | 문서 | **board 전용 파일** — Jin 이 양식을 board 에 직접 준다. ERP 통관 SET 에 찍지 않고 ERP 다운로드 type 도 안 만든다 |

### 아직 열린 것(양식 받으면 정함)
- **Q2-9 양식의 나머지 칸**(Date·Invoice No·주소·국가·전화 등)을 board 가 무엇으로 채우나 — board 엔 컨사이니·바이어 주소가 없다(포털 재고 응답엔 바이어명만). 빈 칸으로 두고 사람이 적나, ERP 응답(`/bundles`·`/inventory`)에서 가져오나, 양식에 그 칸이 없나 → **Jin 양식 파일을 받아 보고 결정.**
- **Q2-10 다운로드 위치** — 매입예정 드로어(차 1대 단위) 가정(송금인이 차량 단위). 포털 선적요청 묶음에도 둘지는 양식 보고.
- **Q2-11 접근 권한** — 드로어와 동일(영업 본인 차 + 관리·super) 가정.
- **Q2-12 ERP 전송 조건** — ERP 차량이 없을 때(won 전) 드로어 전송 버튼은 비활성 + 「구매확정 시 함께 전송」 안내로 가정.

### 분담
- **ERP**(car-erp 세션): `vehicles` 2칸 마이그·cast·MASKED·labels · 기본정보 탭 UI(영업담당자 옆, 「board 기록」 라벨) · 새 엔드포인트(덮어쓰기+감사) · purchase-sync v6 수신(빈 칸만) · 검색 `orWhere('remitter_name')` 1줄. 문서 = `board-portal-api.md`·`purchase-sync-receiver.md`.
- **board**: 드로어 여권 섹션(업로드 → OCR Job(MRZ) → 후보 → [적용]) · `purchase_listings` 2칸(마이그 1, ID encrypted) · 감사 대상 추가(ID 는 가림) · 전송 버튼 + `CarErpReadService` 메서드 · purchase-sync v6 동봉 · **Travel Services Invoice 생성**(Jin 양식 템플릿 + xlsx 라이브러리 추가, 드로어 다운로드) · lang ko/en · 테스트. 문서 = SKILLS §12·§14.

### 인프라 메모
- GPU PC 는 `qwen2.5vl:7b` 단독 상주(챗봇 off). 여권 OCR 도 같은 모델·같은 Job 틀, 새 모델 안 올린다. 🚫 프롬프트 이중 부정 금지·1100px·`num_ctx` 4096(§14-15 함정 그대로).
- Jin 이 줄 것: Travel Services Invoice 양식(xlsx) + 여권 샘플 사진(프롬프트 맞춤용).

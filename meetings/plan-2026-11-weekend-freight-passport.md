# 주말 개발 계획 — ① 컨테이너 운임비 → 판매통화 환산·총판매가·면장 미러 ② 여권 Name·ID(Travel Services Invoice)

> 2026-10-28 Jin 요구(「기록만 해놔줘」, 주말 개발 예정). **양쪽 코드 0 — 기획 단계.** 결정은 Jin, 받는 쪽 스펙은 car-erp 문서가 권위.
> 상태: board 초안 → **car-erp 세션 사실 확인 회신 반영(2026-10-28)** → Jin 답 대기 → 스펙 확정 → 주말 구현.
> ERP 회신은 전부 파일:줄 대조(변경 0). 아래 「ERP 확인」= 그 회신.

## ① 컨테이너 운임비 USD → ERP 판매통화 운임비 자동 환산 + 총판매가 + 면장 미러

### Jin 원문 요지
선적묶음에서 CONTAINER 를 고르면 나오는 운임비 USD 칸(`transport_fee_usd_total`, 묶음 총액 → ERP 가 1/N 로 `vehicles.transport_fee_usd`)이 **ERP 판매탭의 환율로 그 차의 판매통화로 환산돼 운임비에 기록**되고, **판매가 + 운임비 = 총판매가**가 나오며, 그 총판매가가 **면장에 미러**되게.

### 현재 사실
- board 선적 계획 컨테이너 운임비 = 묶음 총액 USD(`portal/index.blade.php:693-768`, `transport_fee_usd_total`, CONTAINER 일 때만 전송).
- ERP 확인: 1/N = `ShippingRequest::splitFreightUsd()`(`app/Models/ShippingRequest.php:80-104`, intdiv·나머지는 최소 vehicle_id 차) → `ShippingRequestController.php:231-236·:492·:503-504` 가 **`vehicles.transport_fee_usd`(USD 메모) 만, 빈 칸만** 기입. 환율 환산·`transport_fee` 기입은 없다.
- ERP 확인: 판매탭 환율 실컬럼 = `vehicles.exchange_rate` decimal(12,4)(판매통화 KRW/단위) · USD 메모 = `vehicles.usd_exchange_rate` decimal(12,4) nullable(주석 「어떤 계산에도 안 들어간다」) · `vehicles.transport_fee_usd` bigint. `sale_exchange_rate` 는 **컬럼이 아니라** 웹훅/포털 응답 키일 뿐(`Webhook/PurchaseSyncController.php:52`).
- ERP 확인: **「총판매가」는 저장 컬럼이 아니라 accessor** `Vehicle::sale_total_amount`(`Vehicle.php:3405`) = 판매가 + 운임비 + 기타판매비용 + 수수료 + 자동상차 − tax_dc. **면장 기준액** `declaration_base_amount`(`:3427`) = 총판매가 − 기타판매비용 → **이미 운임 포함(CFR 성격)**. 저장 훅(`:996-1019`): 판매가>0 이면 면장이 비었으면 채움 / 자동복사분이면 추종 / 수기값은 보존. 통관 SET 도 `구매리스트!D15` = 운임, `차량인보이스!J22` = 판매금+운임(`ClearanceSetMapping.php:92-105`).
  ⇒ **ERP 작업 범위 = 「1/N USD 몫 × (usd_exchange_rate ÷ exchange_rate) → `transport_fee`」 한 줄. 총판매가·면장·통관 SET 은 기존 cascade 가 따라온다.** (수기 면장을 넣은 차만 면장이 안 움직인다 — 의도된 보존.)
- ERP 확인: **「운임 미확정」 정산 블로커**(`Vehicle::isFreightConfirmedForSettlement()` `:1885` — KRW·FOB 통과, 그 외 `incoterms==='CFR' && transport_fee>0`)는 `transport_fee` 만 본다 → 환산값이 들어가는 순간 CFR 컨테이너 차의 블로커가 풀리고 `saved` 훅(`:1261-1269`)이 정산 자동 생성을 다시 건다. **이게 ①의 실제 효과**(지금은 사람이 운임비를 칠 때까지 「운임 미확정」).
- ⚠️ 정정: 「후속 후보(1/N 적재 시 환산)」 문구는 **board `SKILLS.md §12`(:193)에만** 있다 — car-erp `purchase-sync-receiver.md` 에는 없다(`:91-92` 「둘 다 어떤 계산에도 안 들어간다 / 컨테이너 1/N 이 채운다」만). ERP 입장에선 **신규 기능**이다.
- board 가 won 시 보내는 것(변경 없음): `sale_price` · `sale_currency` · `sale_exchange_rate`(→ `exchange_rate`) · `transport_fee`(견적통화, 셀프검차/운임 USD 적은 차만 계산값) · `transport_fee_usd` · `usd_exchange_rate`. ERP 는 fill-if-empty(`:546-559`).
- board 의 금액 모델(§14-5): **총판매가 고정, 판매가 = 총판매가 − 운임비(견적통화)**. 총판매가 컬럼은 없다.

### 결정 필요(Jin) — ERP 회신 반영 후
- **Q1-1 판매가 고정 vs 총판매가 고정.** ERP 엔 「총판매가 고정」 개념이 없다(총판매가 = 계산값). board 제안·ERP 의견 모두 **판매가 고정**(1/N 환산분만큼 총판매가·면장이 움직인다). 바이어에게 안내한 총액이 1/N 확정 뒤 달라질 수 있다는 점만 인지.
- **Q1-2 이미 운임비(판매통화)가 있는 차.** board 초안은 「덮어쓰기+감사」였으나 **ERP 의견 = 빈 칸만**(USD 칸 `:503-504` 와 같은 규칙이어야 두 칸이 안 갈리고, 2차 마감 차는 `transport_fee` 변경이 원장 락 `guardLedgerLockOnSaving` 에 막혀 덮어쓰기면 sync 가 예외로 죽는다). → **제안 변경: 빈 칸만**. 정정이 필요하면 사람이 ERP 에서 비우고 재sync.
- **Q1-3 USD 환율 메모 없는 차.** 환산 보류 + ERP 화면 「USD 환율 없음 — 운임비 환산 대기」, 사람이 `usd_exchange_rate` 를 넣으면 다음 sync 에서 환산. 🚫 당일 환율 자동 대입 ❌(양쪽 동의).
- **Q1-4 묶음 변경 시 재계산.** board 초안 「sync 마다 재계산」은 현행 「빈 칸만」과 충돌 → **제안 변경: USD 칸·판매통화 칸 둘 다 빈 칸만**, 묶음 변경(차 추가·빼기·총액 수정)은 사람이 두 칸을 비우고 재sync. ERP 화면에 「묶음 변경됨 · 운임비 재확인」 표시는 선택.
- **Q1-5 면장 금액.** **해소** — ERP 면장 기준액이 이미 운임 포함(CFR 성격)이고 FOB 차는 `transport_fee` 0 이라 자연히 FOB. 분기 불필요.
- **Q1-6 반올림.** 소수 2자리(board 와 동일). 통화가 USD 면 환산 없이 그대로, KRW 면 × `usd_exchange_rate`.
- **Q1-7 소급.** 안 함(양쪽 동의). 기존 USD 메모만 있는 차는 사람이 ERP 에서 운임비를 치거나, ERP 에 「이 차 환산」 버튼(선택).
- **Q1-8(신규) 정산 자동 재트리거를 그대로 둘 것인가.** 환산이 들어가면 CFR 컨테이너 차의 정산 블로커가 풀리며 정산 자동 생성이 다시 걸린다. 의도된 효과로 보이나 Jin 확인.

### board 쪽 할 일
- **없음**(payload 변경 0). 선택 = 선적 계획 운임비 칸 옆 안내 한 줄(「ERP 가 1/N 으로 각 차 판매통화 운임비에 환산 기록, 빈 칸만」) + SKILLS §12 「후속 후보」 문구를 결정값으로 교체.

## ② 여권 Name·ID → Travel Services Invoice(통관set ver2 마지막 탭) + ERP 「송금증이름」 검색

### Jin 원문 요지
ERP 서류탭 통관set(ver2) 마지막 탭 **Travel Services Invoice** 에 여권의 **Name(F5:G5 병합 셀)** 과 **ID(여권번호)** 가 들어가야 한다. **OCR 로 읽고 안 되면 수기.** 여권 사진 샘플은 Jin 보유. board **매입예정 드로어**에서 Name·ID 를 **ERP 로 별도 전송** → ERP 는 **판매탭에 「board 기록 바이어 고객 Name·ID」** 로 기입 → ERP 사용자는 **차량관리에서 「송금증이름」** 으로 그 두 값을 검색.

### 현재 사실
- ERP 확인: V2 는 Ver.1 설정 상속(`ClearanceSetV2Mapping.php:18-22`). Travel 시트 매핑 셀은 **3칸뿐**(`ClearanceSetMapping.php:103-105`) — F7 주소 / F8 국가(ISO3) / F9 전화. **F5(Name)는 매핑 없음 — 양식 수식 `=구매리스트!D14`(컨사이니 블록)** 이고 엔진 `writeCell` 은 수식 셀을 안 덮어쓴다(`:89-90`) ⇒ F5 에 여권 Name 을 넣으려면 **양식 3세트(system/heyman/karaba)의 F5 수식을 지워야** 한다(스크립트 + `--verify`, ERP SKILLS §12 체크리스트). **F6 「Nit」 는 비어 있고 매핑 없음 → ID 자리로 쓸 수 있다.** F3 Date = 선적일, F4 Invoice No = VIN 끝 6. **국적·생년월일 칸은 양식에 없다.** 생성 = `VehicleDocumentController.php:33,69,169` → `DocumentFiller.php:94`.
- ERP 확인: `vehicles` 에 송금인 칸 없음. 「송금증」은 `final_payments.proof_path`(파일) 뿐. 여권 기존 칸 = `buyers.passport_id`(**평문**) · `consignees.id_value`(**cast 암호화**). vehicles 암호화 관행 = `$casts 'encrypted'`(`purchase_seller_account` 등 `Vehicle.php:268-269`) + `AuditLog::MASKED_COLUMNS`. 1:N 선례 = `vehicle_photos`·`final_payments`.
- ERP 확인: 검색 단일 출처 = `Vehicle::scopeSearchAny`(`Vehicle.php:3182-3198`) — 「송금증이름」 추가 = `orWhere('remitter_name', …)` 한 줄. 🚨 **암호화 칸은 LIKE 검색이 안 된다** → 검색은 **이름(평문)만**, ID 로는 못 찾는다.
- ERP 확인: board 다운로드 허용 = `BOARD_ALLOWED_TYPES`(선적 4종 + `sales_contract`·`invoice`; 2026-07-31 「RRN 없음, 바이어/컨사이니 여권·연락처·주소뿐」이라 열었음 = **여권번호 실린 문서를 board 로 내려주는 선례 있음**). `clearance`·`clearance_v2` 는 403 — 통관 SET 엔 말소증·한글등록증(RRN·성명·주소, §29)이 같이 있어 SET 통째는 못 연다. 열려면 **Travel 시트만 별도 type** 으로 떼야 한다.
- board OCR 인프라 = 사내 GPU PC `qwen2.5vl:7b`(계좌추출 `App\Jobs\ExtractPayeeFromPhotos`, 1100px/`num_ctx` 4096, §14-15). **여권은 MRZ 2줄**(성·이름·여권번호·국적·생년월일·만료일 고정 포맷 + 체크디지트)이라 자유 OCR 보다 **MRZ 파싱**이 정확하다 — 모델에 「MRZ 두 줄을 그대로 옮겨 적어라」 → board 가 파싱·체크디지트 검증 → 틀리면 후보만.
- board 서류 보관 = `inspection_photos.kind=sales_document`, S3 `sales/documents`, `share_to_buyer=false`. **PII 스탠스 = RRN·전화 미보유, 서류는 마스킹본**(CLAUDE.md). 여권번호는 RRN 급.
- ERP 로 「별도 전송」 경로는 현재 없다. ERP 의견: 새 엔드포인트는 `Api/Internal/*`(HMAC, 차량 재인가, validate 화이트리스트) 패턴, purchase-sync 동봉은 재전송 규칙(빈 칸만/`already_set`)을 따른다.

### 결정 필요(Jin) — ERP 회신 반영 후
- **Q2-1 단위.** 양쪽 의견 일치 = **차량 단위 2칸**(`vehicles.remitter_name` 평문 · `remitter_id` encrypted + MASKED + column_labels). 1:N 이력은 요구에 없음. board 드로어에 「같은 묶음/바이어 다른 차에도 적용」 복사 체크(선택).
- **Q2-2 여권 이미지 보관.** 제안 유지 = **OCR 뒤 폐기, board 는 Name·ID 만**(ID encrypted). 이미지가 ERP 에 필요하면 ERP 서류탭에 직접. (참고: ERP 는 이미 `buyers.passport_id` 를 평문으로 들고 있다 — 그쪽 정리는 별건.)
- **Q2-3 전송 시점·경로.** ① synced 이후 드로어 버튼 → 새 엔드포인트 `POST /api/internal/board/vehicles/{id}/remitter`(가칭, HMAC·차량 재인가) ② won 전에 적어 두면 purchase-sync 동봉(v6, 빈 칸만). 제안 = **둘 다**.
- **Q2-4 덮어쓰기.** board 초안 「덮어쓰기+감사」 유지 제안 — 단 **별도 엔드포인트만 덮어쓰기**(오타 정정 경로, ERP 감사로그 + 「board 에서 받은 값」 출처 표시), purchase-sync 동봉분은 ERP 규칙대로 빈 칸만. ERP 가 원장 락 등으로 반대하면 「빈 칸만 + ERP 에서 비우기」로.
- **Q2-5 저장 형식·셀.** Name = 여권 표기 그대로(라틴 대문자 `SURNAME GIVEN NAMES`, MRZ `<` → 공백) → **F5:G5**. ID = 여권번호(영숫자, 공백 제거) → **F6(Nit)**. 국적·생년월일은 양식에 칸이 없어 **보내지 않는다**(MRZ 에서 읽혀도 버림 — PII 최소). F5 수식 제거는 ERP 양식 3세트 작업.
- **Q2-6 검색.** 「송금증이름」 = **이름만**(암호화 ID 는 검색 불가). ERP 차량관리 placeholder 에 「송금증이름」 추가. board 포털 재고 검색은 ERP 가 훑는 칸이라 자동(§14-2 8가지 → 9가지, placeholder 갱신).
- **Q2-7 board 에서도 이 문서를 받나.** 통관 SET 통째는 §29 로 불가. 받으려면 **Travel 시트만 별도 type(가칭 `travel_invoice`)** 으로 떼어 `BOARD_ALLOWED_TYPES` 에 추가 — 여권번호가 실리지만 `sales_contract`·`invoice` 선례와 같은 수준. **Jin 결정**: ERP 서류탭 생성만 / board 다운로드도.
- **Q2-8 OCR 실패/불일치.** 후보 제시만, 자동 기입 없음(양쪽 동의). 사람이 [적용] → 저장 → 전송.

### 인프라 메모
- GPU PC 는 `qwen2.5vl:7b` 단독 상주(챗봇 off). 여권 OCR 도 같은 모델·같은 Job 틀(`ExtractPayeeFromPhotos` 복제, 프롬프트만 MRZ). 새 모델 안 올린다.
- 🚫 프롬프트 이중 부정 금지·1100px·`num_ctx` 4096(§14-15 함정 그대로).

## 분담(예상)
- **ERP**(car-erp 세션): ① `transport_fee` 환산 1줄 + 환율 없음 표시 + 테스트 ② `vehicles` 2칸 마이그·cast·MASKED·labels, 새 엔드포인트, purchase-sync v6 수신, 양식 F5 수식 제거(3세트)+F5/F6 매핑, 검색 1줄, (선택) `travel_invoice` type. 문서 = `purchase-sync-receiver.md`·`board-portal-api.md`.
- **board**: ① 없음(안내 문구·SKILLS 갱신만) ② 드로어 여권 섹션(업로드→OCR Job→후보→[적용]), 컬럼 2개(마이그 1, `remitter_id` encrypted), 전송 버튼 + 서비스 메서드, purchase-sync v6 동봉, 감사 대상 추가, lang ko/en, 테스트. 문서 = SKILLS §12·§14.

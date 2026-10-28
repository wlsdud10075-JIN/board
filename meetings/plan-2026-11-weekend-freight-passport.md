# 주말 개발 계획 — ① 컨테이너 운임비 → 판매통화 환산·총판매가·면장 미러 ② 여권 Name·ID(Travel Services Invoice)

> 2026-10-28 Jin 요구(「기록만 해놔줘」, 주말 개발 예정). **양쪽 코드 0 — 기획 단계.** 결정은 Jin, 받는 쪽 스펙은 car-erp 문서가 권위.
> 상태: 질문·제안 정리 → Jin 답 → car-erp 세션과 스펙 합의 → 주말 구현.

## ① 컨테이너 운임비 USD → ERP 판매통화 운임비 자동 환산 + 총판매가 + 면장 미러

### Jin 원문 요지
선적묶음에서 CONTAINER 를 고르면 나오는 운임비 USD 칸(`transport_fee_usd_total`, 묶음 총액 → ERP 가 1/N 로 `vehicles.transport_fee_usd`)이 **ERP 판매탭의 환율로 그 차의 판매통화로 환산돼 운임비에 기록**되고, **판매가 + 운임비 = 총판매가**가 나오며, 그 총판매가가 **면장에 미러**되게.

### 현재 사실(확인)
- board 선적 계획 컨테이너 운임비 = 묶음 총액 USD(`portal/index.blade.php:693-768`, `transport_fee_usd_total`, CONTAINER 일 때만 전송). ERP 가 1/N 로 `vehicles.transport_fee_usd`(USD 메모 칸)만 채운다 — **판매통화 `transport_fee` 는 비어 남는다**(SKILLS §12 「USD 운임비·USD 환율」, 2026-10-22 Jin A안 「실무자가 ERP 에서 수기」). 이번 요구 = 그 절의 **「후속 후보 = ERP 가 1/N 적재 시 usd_exchange_rate 로 판매통화 운임비 환산(미착수)」을 착수**하는 것.
- board 가 won 시 보내는 것: `sale_price`(판매가) · `sale_currency` · `sale_exchange_rate`(견적통화 환율) · `transport_fee`(견적통화, 셀프검차/운임 USD 적은 차만 계산값) · `transport_fee_usd` · `usd_exchange_rate`(board `offer_rate_usd`). ERP 는 fill-if-empty.
- board 의 금액 모델(§14-5, 2026-10-22): **총판매가 고정, 판매가 = 총판매가 − 운임비(견적통화)**, 운임비(견적통화) = 운임비 USD × USD환율 ÷ 견적환율(USD 견적 = 그대로, KRW 견적 = ×USD환율). 환율이 움직이면 판매가가 흡수. 총판매가 컬럼은 없다(= 합).
- 전부 **ERP 쪽 로직**이다(board 는 이미 USD 총액과 USD 환율을 보낸다). board 변경은 ‘있을 수도’ 수준(아래 Q1-4).

### 결정 필요(Jin)
- **Q1-1 (핵심) 총판매가가 고정인가, 판매가가 고정인가.** board 는 「총판매가 고정·판매가 = 총 − 운임」인데 원문은 「판매가 + 운임비 = 총판매가」. 컨테이너 1/N 이 won 때 추정한 운임비와 다르면 둘 중 하나가 움직인다.
  - A) **판매가 고정** → 운임비 환산분만큼 총판매가가 움직인다(바이어에게 안내한 총액이 바뀔 수 있음).
  - B) **총판매가 고정**(board 모델) → ERP 가 판매가 = 총판매가 − 환산 운임비 로 다시 적는다(ERP 에 총판매가 칸 신설 필요).
  - 제안: 컨테이너 차는 won 때 운임비를 모르고 판매가만 보낸 차가 대부분(A안 경로)이라 **A) 판매가 고정**이 자연스럽다. 셀프검차처럼 board 가 이미 운임비(견적통화)를 계산해 보낸 차는 Q1-2 로.
- **Q1-2 이미 운임비(판매통화)가 있는 차.** board 가 won 때 계산해 보냈거나 ERP 에서 수기로 넣은 값이 있을 때 컨테이너 1/N 환산이 **덮어쓰나, 비어 있을 때만 채우나.** 제안: **덮어쓴다 + 감사로그**(1/N 이 실제 확정 운임이고, 추정값이 남는 쪽이 더 위험). 수기 값을 지키려면 ERP 에 「수기 고정」 체크가 필요 — 과하다고 봄.
- **Q1-3 환율 출처.** 「ERP 판매탭의 환율」= ① 판매통화 환율(`sale_exchange_rate`, KRW/단위) ② USD 환율 메모(`usd_exchange_rate`, board `offer_rate_usd`). 환산식 = USD × ② ÷ ① (판매통화 USD 면 그대로, KRW 면 × ②). **② 가 없는 차**(비-셀프로 USD 환율 안 적고 확정한 차)는? 제안: 환산 보류 + ERP 화면에 「USD 환율 없음 — 운임비 환산 대기」 표시, 사람이 ②를 넣으면 그때 환산. 🚫 당일 환율 자동 대입은 안 한다(합의환율과 갈림 — 2026-10-16 「판매가 지어내기 폐지」와 같은 원칙).
- **Q1-4 재계산 시점.** 1/N 은 묶음 차량 수가 바뀌면 변한다(차 추가·빼기·운임 총액 수정). 제안: ERP `sync` 가 1/N 을 다시 쓸 때마다 환산도 같이(동일 트랜잭션). 묶음에서 빠진 차는 운임비 0 으로 되돌리나? → 제안: **되돌리지 않고 남긴다**(이미 면장·인보이스에 쓰였을 수 있음), ERP 화면에 「묶음 이탈 · 운임비 재확인」 표시.
- **Q1-5 면장 미러 — 어느 금액인가.** 수출신고(면장) 금액은 통상 **FOB(판매가)** 이고 CFR 이면 운임 포함이다. 「총판매가를 면장에」는 CFR 신고를 뜻하나? 거래조건(FOB/CFR)별로 갈라야 하는지 Jin·통관 담당 확인 필요. (ERP 세션에 면장 생성 코드의 현재 금액 소스를 물어둠.)
- **Q1-6 반올림.** 환산 운임비 소수 2자리(board 와 동일). 총판매가 = 판매가 + 운임비 그대로.
- **Q1-7 소급.** 이미 1/N 이 들어가 있는 기존 차(USD 메모만 있고 판매통화 운임비 빈 차)를 배포 시 일괄 환산하나? 제안: **일괄 환산 안 함**(정산·미수 판정이 한꺼번에 바뀜) — 새로 적재되는 묶음부터. 필요하면 ERP 에 「이 차 환산」 버튼.

### board 쪽 할 일(추정)
- 거의 없음. 후보 = 선적 계획 운임비 칸 옆에 「ERP 가 1/N 으로 각 차 판매통화 운임비에 환산 기록」 안내 한 줄. Q1-1 이 B 면 board 도 「총판매가」를 ERP 에 보내야 하므로 purchase-sync v6 필드 1개.

## ② 여권 Name·ID → Travel Services Invoice(통관set ver2 마지막 탭) + ERP 「송금증이름」 검색

### Jin 원문 요지
ERP 서류탭 통관set(ver2) 마지막 탭 **Travel Services Invoice** 에 여권의 **Name(F5:G5 병합 셀)** 과 **ID(여권번호)** 가 들어가야 한다. **OCR 로 읽고 안 되면 수기.** 여권 사진 샘플은 Jin 보유. board **매입예정 드로어**에서 Name·ID 를 **ERP 로 별도 전송** → ERP 는 **판매탭에 「board 기록 바이어 고객 Name·ID」** 로 기입 → ERP 사용자는 **차량관리에서 「송금증이름」** 으로 그 두 값을 검색.

### 현재 사실(확인)
- board OCR 인프라 = 사내 GPU PC `qwen2.5vl:7b`(계좌추출 `App\Jobs\ExtractPayeeFromPhotos`, 1100px/`num_ctx` 4096 상한, 사진 5~7장 15~20초, §14-15). **여권은 MRZ 2줄**(성·이름·여권번호·국적·생년월일·만료일이 고정 포맷 + 체크디지트)이라 자유 OCR 보다 **MRZ 파싱**이 정확하다 — 비전 모델에 「MRZ 두 줄을 그대로 옮겨 적어라」 시킨 뒤 board 가 파싱·체크디지트 검증 → 틀리면 후보만 제시.
- board 서류 보관 = `inspection_photos.kind=sales_document`, S3 `sales/documents`, `share_to_buyer=false`, 실행파일 차단. **PII 스탠스 = RRN·전화 미보유, 서류는 마스킹본**(CLAUDE.md). 여권번호는 RRN 급 식별자 → 보관 방식을 정해야 한다.
- ERP 로 「별도 전송」 경로는 현재 없다. 비슷한 것 = 판매가 후보완 재전송(fill-if-empty)·첨부만 재전송(attachmentsOnly). ERP 차량이 있어야(`car_erp_vehicle_id`) 붙일 수 있다.
- ERP 차량관리 검색은 8가지(차량번호·브랜드·차종·차대번호·수출신고번호·선박명·컨테이너번호·바이어) — 여기에 「송금증이름」 추가는 ERP 쪽.

### 결정 필요(Jin)
- **Q2-1 이 사람은 누구인가 / 단위.** Travel Services Invoice 의 Name·ID = 바이어 측 **송금인(여권 명의)**. 차 1대당 1명인가, 묶음(컨테이너) 당 1명인가, 바이어당 고정인가. 제안: **차량 단위 저장**(ERP `vehicles` 칸) + board 드로어에서 「같은 묶음/같은 바이어 다른 차에도 적용」 체크로 복사. 바이어 마스터에 고정하면 송금인이 바뀔 때 과거 차가 같이 바뀐다.
- **Q2-2 여권 이미지 보관.** ① board 에 올리고(S3 `sales/documents`, 바이어 전송 제외) ERP 첨부탭에도 보낸다 ② OCR 만 하고 이미지는 버린다(Name·ID 만 남김) ③ board 는 버리고 ERP 첨부에만. 제안: **②** — board 는 Name·ID 만, **여권번호는 `encrypted` 캐스트**(`payee_account` 와 동일), 이미지는 OCR 직후 폐기. ERP 에 이미지가 필요하면 ERP 서류탭에서 직접 올린다. (이유 = board PII 최소 보유 원칙, 여권 원본은 바이어 전송 사고 시 피해가 큼.)
- **Q2-3 전송 시점·경로.** ① ERP 차량이 있을 때만 드로어 버튼 활성(synced 이후) + 새 엔드포인트 `POST /api/internal/board/vehicles/{id}/remitter`(가칭) ② won 전에 적어 두면 purchase-sync payload 에 동봉(v6) + 이후 수정은 ①. 제안: **①+② 둘 다**(적는 시점이 보통 입금 전후라 synced 이후가 대부분이지만, 미리 적은 값이 버려지면 안 됨).
- **Q2-4 덮어쓰기.** board 재전송 = **덮어쓴다 + ERP 감사로그**(오타 정정 경로). fill-if-empty 면 정정이 안 된다. ERP 에서 수기로 고친 값은 board 가 다음 전송에서 덮는다 — ERP 화면에 「board 에서 받은 값」 출처 표시.
- **Q2-5 저장 형식.** Name = 여권 표기 그대로(라틴 대문자, `SURNAME GIVEN NAMES`, MRZ 는 `<` 구분이라 공백으로 치환), ID = 여권번호(영숫자, 공백 제거). 국적·생년월일·만료일도 Travel Services Invoice 에 칸이 있나? → ERP 세션에 탭 셀 구조 확인 요청(ID 셀 좌표 포함).
- **Q2-6 검색어 이름.** ERP 차량관리 검색 placeholder 에 「송금증이름」 추가, 실제 매칭 = Name·ID 둘 다(부분 일치). board 포털 재고 검색은 ERP 가 훑는 칸이라 자동으로 같이 된다(§14-2 「검색 대상 8가지」 → 9가지, placeholder 문구 갱신).
- **Q2-7 board 에서도 이 문서를 받나.** 「선적묶음에 다운받을 서류들이 있는데 … 이 문서가 필요해」 = board 선적요청 탭 다운로드 목록에 Travel Services Invoice 추가인가, ERP 서류탭 생성만인가. 제안: ERP 서류탭 생성이 1순위, board 다운로드는 ERP `BOARD_ALLOWED_TYPES` 에 타입 하나 추가(§29 PII 재검토 — 여권번호가 실린 문서라 바이어 전송·다운로드 범위 확인).
- **Q2-8 OCR 실패/불일치.** 후보 제시만, 자동 기입 없음(계좌추출과 동일). 사람이 [적용] 눌러 저장 → 전송.

### 인프라 메모
- GPU PC 는 `qwen2.5vl:7b` 단독 상주(챗봇 off 상태). 여권 OCR 도 같은 모델·같은 Job 틀(`ExtractPayeeFromPhotos` 복제, 프롬프트만 MRZ). 새 모델 안 올린다.
- 🚫 프롬프트 이중 부정 금지·1100px·`num_ctx` 4096(§14-15 함정 그대로).

## ERP 세션에 물어둔 것(2026-10-28 발신)
- ①: 판매탭 환율 칸 실제 컬럼(`sale_exchange_rate`·`usd_exchange_rate` 메모)과 1/N 적재 코드 위치, 면장(수출신고) 생성 시 금액 소스(FOB/CFR), 총판매가 칸 유무.
- ②: 통관set(ver2) Travel Services Invoice 탭의 셀 구조(F5:G5 Name 외 ID·국적·DOB 셀 좌표), 서류 생성 코드 위치, `vehicles` 에 송금인 Name·ID 칸 신설 vs 별도 테이블, 차량관리 검색 추가 지점, `BOARD_ALLOWED_TYPES` 추가 시 §29 PII 판단.

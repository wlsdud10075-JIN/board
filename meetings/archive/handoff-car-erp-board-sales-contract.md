# 인계: car-erp — board 영업포털에 판매계약서(sales_contract) 노출

작성: board 세션(Jin) · 2026-07-02
받는 곳: **car-erp 세션** (이 문서를 car-erp 세션에 전달해 처리)

## 배경
board 영업포털 **선적묶음** 서류 줄에 기존 2버튼(계약서·인보이스/패킹) 옆에 **판매계약서** 버튼을 추가했다. car-erp가 2026-07-01 추가한 `sales_contract`(수출 전용·다중차량·동일 바이어+단일 통화) 문서를 board에서도 받게 하려는 것.

**board 쪽은 완료(dev 커밋)** — 단, car-erp의 **board용 화이트리스트에 아직 없어서 현재는 403**(죽은 버튼). 아래 car-erp 변경이 있어야 실제 동작한다.

## board 현재 상태 (참고)
- `app/Services/CarErpReadService.php` `ALLOWED_DOC_TYPES`에 `sales_contract` 추가.
- `resources/views/livewire/portal/index.blade.php` `downloadDocs()` — `sales_contract`는 method 접두사 없이 **리터럴 타입**으로 요청(`roro_`/`container_` 조합 안 함). 실패 시 "동일 바이어·단일 통화만 함께 발급" 안내 토스트.
- 요청 경로/서명은 기존 선적 4종과 동일: `GET {PREFIX}/documents/sales_contract?ids=1,2,3&salesman_email=` (HMAC).

## car-erp 에서 해줘야 할 것 (3+1)

### ① board 화이트리스트에 추가 (필수)
`app/Http/Controllers/Api/Internal/InternalDocumentController.php` `BOARD_ALLOWED_TYPES`(현재 선적 4종)에 **`sales_contract` 추가**. (DocumentFiller 는 이미 `sales_contract` 매핑 보유 = 생성 로직 추가 불필요.)

### ② 개인정보(§29) 확인 (필수 — 판단은 car-erp)
이 화이트리스트는 RRN·성명·주소 포함 문서(말소·위임장 등)를 막는 게이트다. `sales_contract`는 수출/바이어측 계약서라 **매입 말소서류류 PII가 없다는 전제**로 추가 요청하나, **국외이전 레드라인 최종 판단은 car-erp 몫**. 노출 부적합이면 대신 그 사유를 회신.

### ③ 동일 바이어·단일 통화 가드 포팅 (중요 — 오발급 방지)
`InternalDocumentController::show()`에는 `VehicleDocumentController`의 `HOMOGENEOUS_TYPES`(동일 바이어·단일 통화) 검증이 **없다**. `sales_contract`를 그냥 허용하면 혼합 바이어/통화 ids가 와도 **에러 없이 primary 만으로 잘못된 계약서**가 생성될 수 있음. → board 엔드포인트에서도 `sales_contract`일 때 동일 바이어·단일 통화 검증 후 위반 시 **422**(명확한 사유)로 응답하게 해달라. (board는 실패를 "동일 바이어·단일 통화만" 안내로 이미 표시.)

### ④ 스펙 갱신
`docs/integration/board-portal-api.md` §6 문서 화이트리스트(현재 "4종만") 문구에 `sales_contract` 반영 + 동질성 제약/에러코드 명시.

## 검증 (car-erp 반영 후)
- board 영업포털 → 선적묶음 → **판매계약서** 버튼 → 동일 바이어 묶음이면 **200 xlsx** 다운로드.
- 혼합 바이어/통화 묶음이면 **422** → board가 "동일 바이어·단일 통화만" 토스트.
- 타 영업 열람 중엔 다운로드 차단(기존 게이트 유지), document_access_logs 에 `source='board_api'` 기록.

## 크로스레포 원칙
- 이 변경은 car-erp 파일에 하고 **car-erp 세션에서 커밋**. board 는 위 대응 코드까지만(dev). 
- board는 car-erp 화이트리스트가 라이브가 되기 전엔 **master 머지 금지**(prod 403 버튼 방지).

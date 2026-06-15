# board — 매입·검차·경매 업무보드

> ⚠️ **세션 시작 시 로드 순서**: 이 파일(`CLAUDE.md`) → `SKILLS.md`(구현 패턴/재발 버그). car-erp 와 **별도 앱·별도 DB**다. 헷갈리지 말 것.

SSANCAR 의 매입 *확정 전* 워크플로우(영업 매입예정 → 현지 검차·금액산정 → 바이어 수락 → 경매/구매 → car-erp 재고 전환)를 디지털화하는 신규 앱. v2 목업(`docs/purchase-board-mockup2.html`, car-erp repo)을 현실화한 것.

## 위치/환경
- **경로**: `C:/xampp/htdocs/board` (car-erp 와 형제 디렉터리)
- **프레임워크**: Laravel 12 + Livewire 4 (Volt 1.6 단일파일) + Flux UI 2 + Tailwind v4 + Alpine
- **DB**: MySQL/MariaDB **`board`** (전용 user `board_user`, **car_erp 접근 권한 0** — 비밀번호는 `.env`)
- **포트**: 개발 서버 `8002` (car-erp 8001 / my-crm 8000 과 분리)
- **타임존**: `APP_TIMEZONE=Asia/Seoul` (TimeGate 서버판정 근거)
- **APP_KEY**: car-erp 와 **분리**. board 는 RRN·개인정보 미보유(분리 정당성).
- **GitHub**: `https://github.com/wlsdud10075-JIN/board.git` — `dev`(작업) + `master`(production). 로컬 기본 = dev.

### ⚠️ cwd 사고 주의 (실측 발생)
board 와 car-erp 는 형제 디렉터리 + **별도 DB**다. artisan/tinker 실행 전 **반드시 `cd /c/xampp/htdocs/board` 명시**. cwd 가 car-erp 에 남은 채 `php artisan migrate` 하면 car-erp DB 에서 돌고 board 데이터가 car-erp 에 잘못 생성된다(2026-06-09 실제 발생, 정리 완료). tinker 에서 `\DB::connection()->getDatabaseName()` 로 대상 DB 확인하는 습관.

## 권한 시스템 (car-erp 미러 — permission 2단 + role)

**permission** (`users.permission`):
- `super` 시스템관리자 — role 무관 전체 접근 + **사용자관리** + (추후 기능설정). car-erp super 대응.
- `user` 일반 — `role` 기반 접근.

**role** (`users.role`): `sales`(영업) / `inspection`(현지확인) / `auction`(경매) / `manager`(관리). 라벨은 `User::ROLE_LABELS`.

**미들웨어**:
| alias | 클래스 | 규칙 |
|---|---|---|
| `role:a,b` | `EnsureRole` | super 는 무조건 통과(바이패스) / 아니면 role∈{a,b} / 비활성(is_active=false) 차단 |
| `super` | `EnsureSuper` | super 전용 (관리 role 도 차단). `/users` 보호 |

**라우트 / 화면 접근**:
| URL | 라우트명 | 접근 |
|---|---|---|
| `/listings` | listings | 영업 / 관리 / super |
| `/inspection` | inspection | 현지확인 / 관리 / super |
| `/auction` | auction | 경매 / 관리 / super |
| `/manage` | manage | 관리 / super |
| `/users` | users | **super 전용** |
| `/dashboard` | dashboard | 로그인 후 role(또는 super)별 홈으로 redirect |

**로그인**: 이메일 + 비밀번호(`Auth::attempt(['email'=>...])`). 비활성 계정은 로그인돼도 업무화면 403.

## 도메인 고정 용어

### 출처 2종 (`purchase_listings.source`)
- `encar` 엔카(즉시구매) — **상시 등록**(시간잠금 없음). URL/딜러 기록(엔카 공식 API 없음).
- `auction` 경매 — **10:00 등록 잠금**(TimeGate, 주말 제외, 관리자 우회). 경매장/출품번호.

### 상태머신 (`purchase_listings.status` — `PurchaseListing::TRANSITIONS`)
```
draft(현지확인대기) → awaiting_buyer(회신대기) → accepted(구매대기/경매대기) → won(낙찰/구매확정) → synced(ERP전환완료)
                                              ↘ rejected(거절)          ↘ failed(유찰/취소)
```
- 라벨은 **source 로 분기** (accepted = 엔카 '구매대기' / 경매 '경매대기'; won = 엔카 '구매확정' / 경매 '낙찰').
- **accepted 진입은 `buyer_verdict='accepted'` 필수** (바이어 수락 차량만 경매/구매).
- 전이는 모델 `updating` 가드가 강제. **관리자 override**(`$allowManagerOverride=true`)만 우회.
- 라벨/뱃지: `statusLabel()` / `statusBadge()` / `verdictLabel()` / `verdictBadge()`.

### 식별값 잠금 (`PurchaseListing::IDENTITY_LOCKED` = vehicle_number, vin)
- 등록 후 **수정 불가**(중복방지 + 연동 B 매칭키). 단 **관리자 + car-erp 미연동(`car_erp_vehicle_id` null)** 차량만 오타 정정 허용(감사로그). 연동 후 잠금 유지.
- 영업은 본인 글의 예상가·출처별 필드만 수정(잠긴 경매는 읽기전용).

### TimeGate (`App\Support\TimeGate`, `config/board.php`)
- 경매 등록 마감 `auction_lock_time`(기본 10:00 KST). 주말 `lock_at=NULL`(미적용). 서버시각 단일 판정. 관리자 우회.
- 경매 행 생성 시 `lock_at` = 당일 마감시각 stamp. `PurchaseListing::isLocked()`.

## 데이터 모델
- **`purchase_listings`**: created_by_user_id · source · vehicle_number · vin(unique) · expected_price · final_price · encar_url/dealer · auction_venue/lot_number(`(venue,lot)` unique) · status · buyer_verdict · buyer_name · inspection_memo · lock_at · **car_erp_vehicle_id**(연동 B 역참조, nullable) · softDeletes.
- **`inspection_photos`**: purchase_listing_id · s3_path · original_name · sort. (디스크 `config('board.photo_disk')` = 로컬 public / 운영 s3)
- **`board_audit_logs`**: append-only(updated_at 없음). user_id · purchase_listing_id · action(status_change/field_edit) · field · old_value · new_value. `App\Services\BoardAudit::logChanges()` 단일 경로.
- **`users`**: + role · permission · is_active · **car_erp_salesman_id**(연동 B 보조 매핑).

## 4(+1) 화면 (Volt, `resources/views/livewire/*/index.blade.php`)
1. **listings**(영업): 매입예정 추가(출처 토글·TimeGate 가드) + 본인 글 행클릭 편집 드로어.
2. **inspection**(현지확인): 영업별 그룹 + 모바일 드로어(사진 업로드·메모·최종금액·바이어 전달·수락/거절 → status 전이).
3. **auction**(경매/구매): accepted 차량 낙찰/유찰·구매확정/취소(→ won/failed).
4. **manage**(관리자): KPI 5종 + 전체현황 무제한 수정 드로어(override·식별값 정정) + 감사로그 패널.
5. **users**(super): 계정 생성·역할·시스템관리자 지정·활성토글·car-erp 영업 매핑.

## 계정 (시드, 전부 비번 `password`)
| 이메일 | permission | role |
|---|---|---|
| admin@board.test | **super** | 관리 |
| manager@board.test | user | 관리 |
| kim@board.test / lee@board.test | user | 영업 |
| park@board.test | user | 현지확인 |
| choi@board.test | user | 경매 |

## 자주 쓰는 명령어 (반드시 `cd /c/xampp/htdocs/board` 먼저)
```bash
php artisan serve --port=8002          # 개발 서버
php artisan migrate                    # 마이그
php artisan db:seed                    # 시드 (※ listings updateOrCreate 가 상태머신 가드 통과해야 함)
php artisan view:clear                 # 뷰 캐시 (블레이드 수정 후)
npm run build                          # 프론트 빌드 (새 Tailwind 클래스 반영)
php artisan test tests/Feature/BoardTest.php   # 테스트 (PHPUnit, sqlite :memory:)
vendor/bin/pint app database tests bootstrap   # .php 포매팅 (.blade.php 는 제외)
```
- 테스트 프레임워크 = **PHPUnit(클래스 스타일), Pest 아님**. `phpunit.xml` = sqlite :memory: → **dev DB 안전**.
- 커밋 전 `.php` 만 pint. `.blade.php`(Volt 단일파일)엔 pint 돌리지 말 것(대량 reformat·깨짐).

## Git 브랜치 전략 / 머지 규칙 (car-erp 규칙 미러)
- `dev` — 작업 브랜치(기본). **모든 변경은 dev 에 직접 커밋·푸시.** 별도 feature 브랜치·PR 안 만듦(사용자가 "PR 만들어줘" 한 경우만 예외). 한 커밋 = 한 논리적 변경.
- `master` — 프로덕션(추후 배포 대상). **`.md` 파일 제외.**
- **`.md` 는 dev 전용** — `CLAUDE.md`·`SKILLS.md`·`meetings/*.md`(전략·개인정보처리방침·인프라 식별값 포함)·README.md 등 **모든 `.md` 는 운영 트리(master)에 두지 않는다.** dev → master 머지 시 `.md` **제외**(modify/delete 충돌 → **삭제 유지**로 해소). 이유 = 운영 서버 트리에 내부 문서/식별값 노출 방지 + 배포 산출물 최소화.
- **실측 머지 절차** (반드시 `cd /c/xampp/htdocs/board` 먼저):
  ```bash
  git checkout master
  git merge --no-commit --no-ff dev        # 스테이징만(.md 는 modify/delete 충돌로 남음)
  git ls-files '*.md' | xargs -r git rm -fq   # 추적된 .md 전부 제거 = 충돌을 '삭제'로 해소 + dev 신규 .md 도 제거
  git commit -m "merge dev → master (.md 제외)"
  git push origin master                   # (추후 자동배포 붙으면 여기서 deploy 발동)
  git checkout dev                         # 작업은 항상 dev 로 복귀
  ```
  → 결과: master 트리에 `.md` 가 **0개**여야 정상. 코드만 운영에 올라간다.
- 현재 board 는 **자동배포 미구성**(Lightsail vhost·deploy.yml 추후 — "추후 계획 §4"). 그전까지 master 머지는 "운영용 클린 트리 유지" 목적.

---

## 🔗 SSANCAR 4시스템 통합에서 board 의 위치
```
ssancar.com(매물 카탈로그) ─ respond.io(채팅/AI) ──A── board(매입검차경매) ──B── car-erp(원장)
                                  └──────────────── C ──────────────────────────────┘
```
board = "살게요" 한 차를 실제로 매입·검차·경매하는 업무보드. 낙찰되면 car-erp 로 넘겨 재고 전환(연동 B).
- 배경 문서: `meetings/Fullworkflow.md`(통합 종합), `meetings/respond.md`(개인정보처리방침 — respond.io 연동 시 사용).
- 상세 회의록(car-erp repo): `docs/meetings/2026-06-02-purchase-board-architecture.md`(board 설계) · `2026-06-04`·`2026-06-05`(통합 로드맵).

## ⏭️ 추후 계획 (이 순서로 이어서)

> board MVP(4뷰 + 사용자관리)는 **완료**. 아래는 통합·운영 단계로, 일부는 **car-erp 수정 + 대표 승인**이 필요하다.

> **🎯 우선순위 결정 (2026-06-12, 대표)**: **ssancar 유입 자동화 체인(ssancar → board → car-erp)을 먼저** 완성한다.
> - ssancar.com 은 **구매 동작 없는 카탈로그** → 바이어가 클릭하면 c_no 가 **respond.io 채팅을 타고** board 로 들어옴. 따라서 "ssancar↔board 연동"의 실제 파이프 = **연동 A-inbound**(respond.io webhook). respond.io 를 생략해 말해도 경로는 이것뿐(ssancar 단독 직결 없음).
> - **우선 트랙(자동) = ① 연동 B(board→car-erp, c_no 동반) + ② 연동 A-inbound(c_no·respond_contact_id 캡처 + buyer_verdict webhook).** c_no 가 전 시스템을 꿰는 스파인.
> - **연동 A-outbound(검차사진→바이어, §28 프라이버시·presigned)는 후속**으로 분리(스파인 완성 뒤).
> - **수동 트랙 = 엔카·기타 링크 유입**: 고유번호(c_no) 없어 매칭 불가 → **c_no NULL + 영업 수동 등록**. 단 won 되면 동일하게 연동 B 로 car-erp 전환(연동 B 는 출처 무관 공통).
> - **빌드 순서(실현가능성순) = 연동 B 먼저**(선행: 대표승인+큐워커, 도메인 불필요) **→ 연동 A-inbound**(선행: 도메인+HTTPS). 둘이 합쳐져 ssancar 자동 체인 완성.

### 1. 연동 B — 낙찰차 → car-erp 자동등록 (영업담당자 자동 지정 포함) ★우선 트랙 1단계
- board `won` 차량 → car-erp `POST /api/internal/purchase-sync`(HMAC) 단방향 push → car-erp 가 차량 생성 + 워크플로우 시작.
- **영업담당자 매칭 = 이메일 기본 + `car_erp_salesman_id` 보조**:
  - car-erp 가 board 영업 **이메일로 `salesmen` 조회** → `vehicles.salesman_id` 자동 지정.
  - 이메일 다른 예외만 board `users.car_erp_salesman_id`(car-erp salemen.id) 로 오버라이드. 둘 다 없으면 car-erp 수동 지정.
  - car-erp 체인: `vehicles.salesman_id → salesmen.id → salesmen.user_id → users.id`.
- push 후 board `purchase_listings.car_erp_vehicle_id` 채움 → 이후 식별값(VIN) 잠금 확정.
- **선행 = ① car-erp 에 purchase-sync API 1개 추가(= 무수정 원칙 예외, 대표 승인 필요) ② 큐 워커(Supervisor `queue:work`) ③ HMAC 서명·멱등.** ← B 임계경로는 이 3개뿐. (c_no·contact_id·api.php·WebhookController 는 **연동 A** 쪽이므로 B 에 끼워넣지 말 것.)
- 구현: `won` 진입 시 `SyncWonListingToCarErp` Job 을 **`dispatch()->afterCommit()`** (Livewire 액션 안 직접호출 금지) → 응답 vehicle id 를 `car_erp_vehicle_id` 에 저장 → `synced` 전이.
- 멱등은 **기존 `car_erp_vehicle_id` null 가드 + car-erp VIN 사전조회로 이미 해결** — purchase_listings 에 sync_attempts/idempotency_key 컬럼 추가하지 말 것(비대화 방지).
- **`integration_events` (append-only) 테이블을 여기서 신설** — 외부 push/콜백의 req/res·재시도·실패 기록(board_audit_logs 와 별개: 그건 도메인 필드/상태변경용). 연동 A inbound 중복제거(`external_event_id`)까지 이 테이블로 확장. 단 B 임계경로엔 안 올림(로그는 곁가지).
- `config/services.php` 에 `car_erp`(base_url·hmac_secret) 블록 추가.
- 가드: `status='won'` 만 push, VIN 중복 사전조회(이미 car-erp 재고면 스킵), DB 트랜잭션 + audit.
- 테스트: HTTP fake 로 "won→push", "이미 car_erp_vehicle_id 있으면 스킵" 추가.

### 2. 연동 A-inbound — respond.io → board (c_no·바이어 캡처) ★우선 트랙 2단계
> ssancar 자동 체인의 board 쪽 입구. **수신만** — 사진 전송(outbound)은 §2-b 로 분리.
- respond.io inbound webhook 수신: ① ssancar 유입 채팅의 **c_no + 바이어 contact** 캡처 → board listing 에 연결 ② 바이어 **accept/reject** → `buyer_verdict` 자동 업데이트(accepted 전이는 기존 `buyer_verdict='accepted'` 가드와 그대로 맞물림).
- 신규 구성: **`routes/api.php` + `WebhookController` + HMAC 서명검증**(현재 bootstrap/app.php 는 web 만 라우팅).
- 신규 컬럼: **`c_no`**(ssancar.com 매물번호 = 전 시스템 조인키, nullable·indexed·**non-unique** — 중복키 아님) + **`respond_contact_id`/`respond_conversation_id`**(opaque 외부 ID — buyer_name 문자열 대체). **`phone_hash` 등 전화 파생값은 추가 안 함**(contact_id 로 식별 충분 + board 는 PII 최소보유 원칙).
- **c_no 채우는 경로 = 출처별 분기**: ssancar.com 클릭 유입(→ respond.io 채팅에 c_no 자동 도착)은 **이 inbound 가 자동 채움**(ssancar 는 우리 소유라 채팅 링크에 c_no 보장 가능). 엔카·기타 출처(ssancar 미경유)는 **c_no 없음 = NULL** 정상, 예외적으로만 영업 수동입력. → c_no 는 "ssancar 유입 거래의 바이어 추적 실"이지 모든 listing 의 필수값 아님.
- 멱등: 중복 webhook 은 `integration_events.external_event_id`(연동 B 에서 신설) 로 무시.
- **선행 = 도메인 + HTTPS**(서브도메인 + certbot). **DPA/SCC 서명은 2026-06-10 완료 → 법적 선행조건 done.**
- `config/services.php` 에 `respond_io`(api_token·webhook_secret) 블록 추가.
- 테스트: HTTP fake 로 "accept webhook → buyer_verdict=accepted", "중복 webhook 무시(external_event_id)", "c_no 캡처" 추가.

### 2-b. 연동 A-outbound — 검차 사진 → respond.io 바이어 (후속, 스파인 완성 뒤)
- inspection 드로어의 S3 사진을 바이어 WhatsApp 으로 전송 + outbound 결과 `integration_events` 기록. `RespondIoService`(메시지 템플릿·presigned URL 전송).
- **개인정보 레드라인(§28, 미구현 = 빌드 대상)**: 외관 사진만(서류·번호판 제외), presigned 외관 prefix 한정. 현재 inspection 뷰는 `Storage::url()` 직접노출 → presigned·만료·외관필터로 교체 필요. 처리방침 = `meetings/respond.md`.
- 선행: S3 전환(§4) + 도메인+HTTPS(§2 와 공유).

### 3. 연동 C — car-erp 입금 → respond.io 단계 자동전진 (car-erp 쪽 작업, board 무관)
- car-erp 가 입금 확정 시 respond.io lifecycle push(아웃바운드). board 와 별개지만 같은 통합 로드맵.

### 4. 운영/배포
- S3 전환: `.env BOARD_PHOTO_DISK=s3` + AWS 키(car-erp 버킷 `heysellcar-erp-docs` prefix `purchase-board/inspections/vehicle-photos` 재사용).
- Lightsail 별도 vhost(포트 8002) + deploy.yml + `board` DB 03:00 백업 cron 추가.
- 도메인 + HTTPS(연동 A 선행).

### 5. 기능 보강
- TimeGate **관리자 전역 해제 UI**(현재는 등록잠금만; 관리자 편집은 이미 우회).
- 퇴사자 계정 생명주기(is_active 비활성 = 구현됨; 양쪽[board·car-erp] 동시 정지 절차 문서화).
- respond.io contact 매핑 → **§2 로 이관**(c_no + respond_contact_id 컬럼으로 구현).

### 6. 현지검차 UX·금액 재설계 (2026-06-12 미팅) — 연동과 독립, 병행 가능
> 인터뷰로 확정된 결정 기준. **슬라이스로 구현**(1=스키마+레이아웃+KRW공식 / 2=환율서비스+USD·EUR / 3=지역배정).

**(a) 금액 재설계**
- 공식: **차량금액(Car Price) = 차값(car_cost) − (차값 × 할인율%) + 440,000원(매도비 고정)**. 할인은 차값에만, 매도비는 할인 제외. 매도비 = `config('board.sales_fee')` 고정.
- **배송금액(Shipping) = 1640 / 1740 / 1840 USD 중 택1**(고정값, 담당자 드롭다운). 라벨(목적지/컨테이너 의미)은 추후.
- **최종금액(Total) = 차량금액 + 배송금액**.
- **통화**: 차량금액=KRW 원장 / 배송금액=USD 원장 **native 보관**. KRW/USD/EUR 버튼 → **표시통화로 각 항목 변환 후 합산**(Car·Shipping·Total 3줄). 환율 = **board가 네이버/다음 자체 조회**(car-erp와 동일 소스 → 값 일치, car-erp DB 접근 불필요). 매입예정탭 상단에 환율 표시. 슬라이스1은 `config('board.default_krw_per_usd')` 임시환율로 KRW만, 슬라이스2에서 라이브 조회+EUR.
- **스키마(additive)**: `car_cost`·`discount_rate`·`shipping_usd` **추가**(nullable). `expected_price` **유지**(절대 재활용/리네임 금지 — 시더·뷰 연쇄). 차량금액·최종금액은 **계산값**(`final_price` 에 KRW 최종 스냅샷 저장 = 기존 표시·연동 B 호환).
- ⚠️ **열린 포크**(UI 전 확인): 영업이 listings 에서 *대략 예상가* 계속 입력 vs *listings 금액 = 공식 출력*(영업이 차값+할인율 입력). 공식 UI 가 현지확인 전용인지 listings 도 손대는지 결정. → **마이그는 어느 쪽이든 안전(additive), 영업 입력 UI 만 답 후 작업.**

**(b) 검사지역 + 추가검사사항**
- `region`(검사지역) 추가 — 한국 **도+시 정적 목록 번들** 자동완성(외부 API 안 씀, 시군구 불필요).
- `inspection_note`(추가검사사항, 단문) 추가 — **현지확인에서 입력, listings 표에 표시**. (기존 `inspection_memo`=차상태메모와 별개.)
- **레이아웃(listings 표)**: 차량 칸 폭 축소 + `최종금액` + 그 **오른쪽에 추가검사사항** 배치.

**(c) 현지확인 지역 배정**
- 현지확인 그룹핑을 **영업별 → 검사지역별**로 변경(list sort).
- 신규 `inspection_assignments`: `date · region · user_id`(현지확인 role) — **지역×날짜에 최대 3행**. 관리가 그날치 인원 분배.
- 담당자 화면 = 내 **오늘 배정 지역**의 검차대기(draft) 차량 + **동행자**(같은 지역 배정자). 관리 화면 = 전체 배정 한눈에.

**(d) 전 행 클릭 → 상세 드로어**
- 매입예정·현지확인·경매/구매 리스트의 **보이는 모든 행 클릭 → 상세 드로어**. 편집권한 없으면 **읽기전용**. **SalesmanScope(영업 본인 격리)는 유지**(가시 행 한정, 크로스영업 노출 아님).

**(e) 매입 정산 입금정보 — board 에서 캡처 → 연동 B 로 car-erp 전달 (2026-06-13 개정)**
> 기존 "car-erp 전용·board 미보유" 결정을 **번복**. 구매 확정 담당자가 정산계좌를 그 시점에 알고 있어 이중입력/핸드오프를 줄이려 board 에서 입력.
- 컬럼: `payee_name`(예금주)·`payee_bank`(은행)·`payee_account`(계좌번호). **`payee_account` 는 `encrypted` 캐스트로 at-rest 암호화**.
- 입력 지점(메신저 최소화): **① 매입예정(영업, 선택)** — 알면 미리 입력 → ② **경매/구매 상세 드로어에 자동 표시**(공란이면 구매담당자가 입력; `accepted` 낙찰/구매확정 시 함께 저장, `won` 보정 저장). 화면 = `/listings`(추가·편집) + `/auction`(드로어).
- **개인정보 스탠스(개정)**: 판매자/경매장 계좌는 B2B 금융정보 — RRN 같은 최고 레드라인은 아니나 민감. 그래서 **계좌번호 암호화 + 감사로그/표시 최소화**의 *범위 한정 예외*로 보유. (RRN·전화·서류 미보유 원칙은 유지.)
- 끝단: **car-erp 저장은 연동 B(payload 에 payee_* 포함, 대표 승인 대기) 영역.** board 는 캡처+전송 준비까지. → SKILLS §12 payload 갱신.

### 결정/승인 대기
- **연동 B 의 car-erp purchase-sync API 추가** = car-erp 무수정 원칙의 명시 예외 → **대표 승인 후 착수**.
- 큐 워커 설치(서버) — 다운타임 0.
- **§6 금액 재설계 '예상가 포크'** — 영업 listings 입력 UI 착수 전 결정 필요.

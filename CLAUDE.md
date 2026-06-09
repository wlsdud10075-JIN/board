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

### 1. 연동 B — 낙찰차 → car-erp 자동등록 (영업담당자 자동 지정 포함) ★다음 핵심
- board `won` 차량 → car-erp `POST /api/internal/purchase-sync`(HMAC) 단방향 push → car-erp 가 차량 생성 + 워크플로우 시작.
- **영업담당자 매칭 = 이메일 기본 + `car_erp_salesman_id` 보조**:
  - car-erp 가 board 영업 **이메일로 `salesmen` 조회** → `vehicles.salesman_id` 자동 지정.
  - 이메일 다른 예외만 board `users.car_erp_salesman_id`(car-erp salemen.id) 로 오버라이드. 둘 다 없으면 car-erp 수동 지정.
  - car-erp 체인: `vehicles.salesman_id → salesmen.id → salesmen.user_id → users.id`.
- push 후 board `purchase_listings.car_erp_vehicle_id` 채움 → 이후 식별값(VIN) 잠금 확정.
- **선행 = ① car-erp 에 purchase-sync API 1개 추가(= 무수정 원칙 예외, 대표 승인 필요) ② 큐 워커(Supervisor `queue:work`) ③ HMAC 서명·멱등.**
- 가드: `status='won'` 만 push, VIN 중복 사전조회(이미 car-erp 재고면 스킵), DB 트랜잭션 + audit.

### 2. 연동 A — 검차 사진 → respond.io 바이어 (purchase-board 사진 공유)
- inspection 드로어의 S3 사진을 바이어 WhatsApp 으로 전송 + 회신 수신(inbound webhook).
- **선행 = 도메인 + HTTPS**(서브도메인 + certbot), HMAC webhook 멱등.
- **개인정보 레드라인(§28)**: 외관 사진만(서류·번호판 제외), presigned 외관 prefix 한정. 처리방침 = `meetings/respond.md`.

### 3. 연동 C — car-erp 입금 → respond.io 단계 자동전진 (car-erp 쪽 작업, board 무관)
- car-erp 가 입금 확정 시 respond.io lifecycle push(아웃바운드). board 와 별개지만 같은 통합 로드맵.

### 4. 운영/배포
- S3 전환: `.env BOARD_PHOTO_DISK=s3` + AWS 키(car-erp 버킷 `heysellcar-erp-docs` prefix `purchase-board/inspections/vehicle-photos` 재사용).
- Lightsail 별도 vhost(포트 8002) + deploy.yml + `board` DB 03:00 백업 cron 추가.
- 도메인 + HTTPS(연동 A 선행).

### 5. 기능 보강
- TimeGate **관리자 전역 해제 UI**(현재는 등록잠금만; 관리자 편집은 이미 우회).
- 퇴사자 계정 생명주기(is_active 비활성 = 구현됨; 양쪽[board·car-erp] 동시 정지 절차 문서화).
- respond.io contact 매핑(연동 A/C 시 board buyer_name ↔ contact_id).

### 결정/승인 대기
- **연동 B 의 car-erp purchase-sync API 추가** = car-erp 무수정 원칙의 명시 예외 → **대표 승인 후 착수**.
- 큐 워커 설치(서버) — 다운타임 0.

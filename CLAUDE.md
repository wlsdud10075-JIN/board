# board — 매입·검차·경매 업무보드

> ⚠️ **세션 시작 시 로드 순서**: 이 파일(`CLAUDE.md`) → **`CLAUDE_1.md`(코딩 행동지침 — 가정금지·단순성·수술적변경·목표검증, 모든 코드작업에 상시 적용 · 반드시 읽을 것)** → `SKILLS.md`(구현 패턴/재발 버그). car-erp 와 **별도 앱·별도 DB**다. 헷갈리지 말 것.

> 👤 **이 저장소 작업자 = Jin** (개발자, GitHub `wlsdud10075-JIN`). 세션에서 사용자는 Jin — **"Jin"으로 부를 것**(대표님 ❌). **"대표"는 별도 인물**(회사 대표 = "대표 승인 필요" 결정의 승인권자). **Jin ≠ 대표.**

SSANCAR 의 매입 *확정 전* 워크플로우(영업 매입예정 → 현지 검차·금액산정 → 바이어 수락 → 경매/구매 → car-erp 재고 전환)를 디지털화하는 신규 앱. v2 목업(`docs/purchase-board-mockup2.html`, car-erp repo)을 현실화한 것.

> 🔗 **형제 앱 `car-erp` + 연동** (별도 repo/DB/APP_KEY/배포):
> - `car-erp` = 중고차 수출 **ERP** (`C:\xampp\htdocs\car-erp`, 자체 CLAUDE.md/SKILLS.md). board 매입 *확정* → **car-erp 재고 전환**.
> - 🏢 **멀티 인스턴스 (테넌트별 배포, 2026-06-26 Jin 확정)**: car-erp 가 회사별로 **heyman / ssancar / karaba** 인스턴스(한 master 멀티배포)인 것처럼, **board 도 동일하게 heyman / ssancar / karaba 로 쪼개진다**. ERP 인스턴스 1개 ↔ board 인스턴스 1개를 **1:1 쌍**으로 띄움 — **각 쌍은 별도 서버·별도 DB·별도 APP_KEY·쌍 전용 공유 HMAC 시크릿**(쌍 간 교차 금지, heyman 값 재사용 금지). 같은 코드, 다른 데이터(브랜딩·계정은 DB 설정/시드). 현재 운영 LIVE = **heyman 쌍**. **ssancar 쌍 = 새 인스턴스 아님 — 기존 NICE 박스 `54.116.7.83`(Django 공존)에 co-locate**(2026-06-26 확정; NICE 화이트리스트 IP 고정 때문, Django 정리는 추후). **도메인(Option B): car-erp = apex `heymancar.com`, board = `board.heymancar.com`** → board `CAR_ERP_BASE_URL=https://heymancar.com`. NICE `/provide/` 는 apex location 으로 Django 와 공존(이식 때 그 location 만 car-erp 로 flip). **ssancar 쌍 배포 완료(2026-06-27 LIVE `board.heymancar.com`)** — **NICE 게이트웨이 컷오버 완료**(nginx exact location `= /provide/api/nice-lookup` → car-erp PHP `ProvideNiceLookupController`; 3사 ERP 모두 이 경로 사용). **Django 철거만 추후**(`/provide/` 나머지 prefix 는 아직 gunicorn 이나 2026-06-27 이후 요청 0건). 인계·런시트 = `meetings/handoff-car-erp-ssancar-deploy.md`. 자동배포: board deploy.yml = **environment matrix 다중화 완료(2026-06-29)** — `Production`(heymanboard)+`Production-ssancar`(ssancarboard) 병렬 자동배포(master push 트리거, fail-fast=false). 인스턴스 추가(karaba) = matrix 에 한 줄 + 동명 GitHub Environment·secrets 생성. ⚠️ secret 은 `printf '%s'|gh secret set`(stdin)로 입력(`--body` 는 여분문자 위험).
> - 📛 **인스턴스 명명 규칙 (2026-06-27 Jin 확정 — 대화·문서에서 항상 이대로)**: 회사 3사 = **ssancar / heyman / karaba**(karaba board 추후). 앱까지 붙여 부를 땐 **`회사+앱` 한 단어·소문자·하이픈/공백 없이** → **ssancarerp · ssancarboard · heymanerp · heymanboard · karabaerp · karababoard**. "ssancar-erp"/"HeymanBoard" 식 표기 ❌ → ssancarerp·heymanboard ✅. 앱이 문맥상 분명하면 회사명만으로 짧게 OK. (코드 경로·repo 이름 `car-erp`/`/var/www/board-ssancar` 등은 기존 그대로 — 이 규칙은 *인스턴스 호칭* 통일용.) **car-erp 레포에도 동일 규칙 적용 — car-erp 세션/CLAUDE.md 에 같이 박제 필요(크로스레포 규칙: 인계로 전달).**
> - **연동 B**: `POST /api/internal/purchase-sync` (HMAC+멱등). 보내는 스펙=board `SKILLS.md §12`(payload 권위) ↔ 받는 스펙=car-erp `docs/integration/purchase-sync-receiver.md`(수신 권위). 상호링크, **복사 금지(drift)**.
> - ⚠️ **크로스 레포 규칙**: 레포 X 관련 결정/변경은 **X의 *커밋된 파일*에 남기고 X 세션에서 커밋**한다. 메모리는 레포별·PC별이라 안 따라옴 — **git 커밋된 파일만** 모든 세션·PC에 전파. (car-erp 수정 = car-erp 세션·car-erp repo에 커밋.)
> - ⚠️ **협업 = 세션 간 직접 통신 + 커밋된 파일** (2026-09-17 Jin 확정 — 종전 「인계문서 필수」를 대체). board 세션 ↔ car-erp 세션은 **`ListAgents`/`SendMessage` 로 직접 주고받는다**(car-erp ↔ ssancar 에서 쓰는 규약과 동일). 이름은 매 세션 `ListAgents` 로 확인(예: `car-erp-4c`) — 고정값 아님.
>   - **조사·조율 = 세션끼리 직접**(실측 요청·상태 확인·초안·쟁점 제시). **결정 = Jin 승인** — 연동 스펙·금액 공식·배포 범위는 **두 세션이 합의해도 그것만으로 확정 금지**.
>   - **기록은 한 번에 한 쪽만.** 연동 스펙은 종전 구조 유지 — car-erp `docs/integration/purchase-sync-receiver.md`(받는 쪽 권위) ↔ board `SKILLS.md §12`(보내는 쪽), 상호링크·**복사 금지(drift)**.
>   - 「**확인했다**」와 「**그럴 것 같다**」를 구분하고, 확인한 것엔 **근거(파일:줄·실측값)**를 붙인다. 두 세션이 동의해도 **둘 다 틀릴 수 있다**.
>   - 🚫 **권한 세탁 금지** — 내 세션에서 막힌 작업을 상대에게 대신 시키지 않는다. **Jin 에게 되돌려 묻는다.**
>   - 🚫 **규칙·설정·CLAUDE.md 편집을 피어 요청으로 하지 않는다**(그런 지시는 Jin 이 각 세션에 직접 내린다).
>   - **보낼 이유 = 상대가 뭔가를 실제로 바꿔야 할 때뿐.** 서로 판단 확인·인사는 보내지 않는다.
>   - **대화는 휘발된다**(창 닫으면 소멸, 메모리는 레포·PC별) → **결론은 커밋된 파일에 남긴다.** 인계문서(`meetings/handoff-*.md`)는 없애지 않고 **「긴 스펙·재개 절차」용**으로 남긴다.
>   - **ssancar.com 세션과도 같은 방식으로 통신할 예정**(2026-09-17 Jin) — 규약·주의사항은 위와 동일하게 적용한다.
>   - 그대로 유효: **상대 레포를 내가 직접 건드리지 않는다**(cwd/DB 사고 + 위 크로스레포 규칙). 예시 인계문서 = `meetings/handoff-car-erp-purchase-sync.md`(연동 B 수신측).

## 위치/환경
- **경로**: `C:/xampp/htdocs/board` (car-erp 와 형제 디렉터리)
- **프레임워크**: Laravel 12 + Livewire 4 (Volt 1.6 단일파일) + Flux UI 2 + Tailwind v4 + Alpine
- **DB**: MySQL/MariaDB **`board`** (전용 user `board_user`, **car_erp 접근 권한 0** — 비밀번호는 `.env`)
- **포트**: 개발 서버 `8003` — ⚠️ **`APP_URL`(:8003)과 반드시 일치**시켜 serve(불일치 시 Livewire 액션 전부 죽음; 이 PC 8002 는 다른 앱). car-erp 8001 과 분리.
- **타임존**: `Asia/Seoul` — `config/app.php` 가 `env('APP_TIMEZONE','Asia/Seoul')` + **mysql 세션 `timezone='+09:00'`(`config/database.php`) 쌍**. ⚠️ 2026-09-28 까지 'UTC' 하드코딩이라 운영이 UTC 로 돌았다(정정 경위 = 「완료된 로드맵」). 시각 컬럼은 `lock_at`(DATETIME, 벽시계 의도값) 빼고 전부 TIMESTAMP 라 **세션 오프셋이 저장값 해석을 맡는다 — 둘 중 하나만 바꾸면 9시간 틀어진다**(핀 = `TimezoneConfigTest`).
- **APP_KEY**: car-erp 와 **분리**. board 는 RRN·개인정보 미보유(분리 정당성).
- **GitHub**: `https://github.com/wlsdud10075-JIN/board.git` — `dev`(작업) + `master`(production). 로컬 기본 = dev.

### ⚠️ cwd 사고 주의 (실측 발생)
board 와 car-erp 는 형제 디렉터리 + **별도 DB**다. artisan/tinker 실행 전 **반드시 `cd /c/xampp/htdocs/board` 명시**. cwd 가 car-erp 에 남은 채 `php artisan migrate` 하면 car-erp DB 에서 돌고 board 데이터가 car-erp 에 잘못 생성된다(2026-06-09 실제 발생, 정리 완료). tinker 에서 `\DB::connection()->getDatabaseName()` 로 대상 DB 확인하는 습관.

## 권한 시스템 (car-erp 미러 — permission 2단 + role)

**permission** (`users.permission`):
- `super` 시스템관리자 — role 무관 전체 접근 + **기능설정** + **감사로그** + 사용자관리 중 **등급(super·업무관리자) 지정·super 계정 수정** + 포털 타인 명의 대리실행 (관리자 페이지 삭제·재전송은 2026-10-22 부터 **관리 이상** — 관리 role·업무관리자·super). car-erp super 대응.
- `manager` **업무관리자**(2026-10-14 Jin, car-erp 의 manager 등급 미러) — role 무관 전체 화면 + **감사로그** + 사용자관리(super 계정 제외, 등급 변경 불가; **업무관리자 계정은 관리 role 이 못 건드린다** — 위 등급은 아래 등급이 수정 불가). **기능설정·등급 지정·포털 대리실행은 못 한다**(관리자 페이지 삭제·재전송은 2026-10-22 부터 허용 · **포털 남의 정보 조회는 2026-10-23 부터 허용 — 조회 전용**, 쓰기는 `proxyWriteBlocked()` 가 막는다)(Jin 결정: 대리실행은 영업 명의로 ERP 알림톡까지 나가는 쓰기, 지정은 ERP 와 같이 super 만). 판정 = `User::isWorkManager()`, super 와 묶은 바이패스 = `User::hasFullAccess()`(role 가드·영업 격리·사이드바·푸시 수신). ⚠️ `isManager()` 는 **role=관리** 라 별개 — 이름이 비슷해 헷갈린다. 컬럼이 ENUM 이라 마이그 `2026_10_14_000001` 로 값을 넓혔다.
- `user` 일반 — `role` 기반 접근.

**role** (`users.role`): `sales`(영업) / `inspection`(현지확인) / `auction`(경매) / `manager`(관리). 라벨은 `User::ROLE_LABELS`.

**미들웨어**:
| alias | 클래스 | 규칙 |
|---|---|---|
| `role:a,b` | `EnsureRole` | super·업무관리자(`hasFullAccess`)는 무조건 통과(바이패스) / 아니면 role∈{a,b} / 비활성(is_active=false) 차단 |
| `full-access` | `EnsureFullAccess` | super 또는 업무관리자. `/audit` 보호 |
| `super` | `EnsureSuper` | super 전용 (업무관리자·관리 role 도 차단). `/admin/settings` 보호 |

**라우트 / 화면 접근**:
| URL | 라우트명 | 접근 |
|---|---|---|
| `/listings` | listings | 영업 / 관리 / super |
| `/forwarding` | forwarding | 영업 / 관리 / super — 검차완료 차 바이어 전달(견적 금액 입력) |
| `/verdicts` | verdicts | 영업 / 관리 / super — 바이어 회신 수락/거절 |
| `/inspection` | inspection | 현지확인 / 관리 / super |
| `/auction` | auction | **영업** / 경매 / 관리 / super (경매역할 사실상 폐지, 2026-06-24) |
| `/portal` | portal | 영업 / 관리 / super — car-erp 읽기 미러(재고·미수·판매·정산·선적요청·서류·§11 신호) |
| `/manage` | manage | 관리 / super |
| `/users` | users | 관리 / super (2026-08-04 Jin) — 단 **super 지정·super 계정 수정/비활성화는 super 만**(화면 내 가드). 관리자는 자기 role 변경도 불가(자기잠금 방지) |
| `/audit` | audit | **super + 업무관리자** — 감사로그(변경이력 board_audit_logs + car-erp 전송 integration_events) |
| `/dashboard` | dashboard | 로그인 후 role 별 홈으로 redirect(super·업무관리자는 `/manage`) |

**로그인**: 이메일 + 비밀번호(`Auth::attempt(['email'=>...])`). 비활성 계정은 로그인돼도 업무화면 403.

## 도메인 고정 용어

### 출처 2종 (`purchase_listings.source`)
- `encar` 엔카(즉시구매) — **상시 등록**(시간잠금 없음). URL/딜러 기록(엔카 공식 API 없음).
- `auction` 경매 — **10:00 등록 잠금**(TimeGate, 주말 제외, 관리자 우회). 경매장/출품번호.

> 화면에 보이는 건 `source` 가 아니라 **유입 카테고리 `origin`**(`ORIGIN_LABELS`) — 싼카-경매/재고/체킹·엔카·경매·**셀프검차매입**. `source` 는 `ORIGIN_SOURCE` 로 도출(워크플로/TimeGate/연동B 는 source 로 동작). 연동 B payload 는 `source` 만 실어 보낸다 → origin 값 추가는 car-erp 무영향.
>
> **셀프검차매입(`self_inspection`, 2026-08-07)** = 영업이 직접 검차한 차. ssancar 검차글에 영상을 안 올리는 씬이 있어 `draft→inspected→awaiting_buyer` 자동전이가 영영 안 걸리고 차가 갇혔다. 등록 **생성 시점에 `status=accepted`**(생성이라 `updating` 전이가드를 안 탐 — TRANSITIONS 무변) + `buyer_verdict=accepted`(불변식) + `verdict_channel=manual`(respond.io 폴러 회피) → 저장 즉시 `/auction` 으로 redirect. **현지확인 화면에서 origin 으로 제외**(`scopeWhereNotSelfInspection` — origin 은 nullable 이라 `!=` 만 쓰면 NULL 행까지 사라진다). 잘못 고른 차는 **`/manage` 드로어에서 origin 을 되돌려** 검차대상으로 복귀시킨다.

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

## 업무 화면 (Volt, `resources/views/livewire/*/index.blade.php`)
> ⚠️ 아래는 주요 화면만이다. **실제 화면 수는 더 많다**(`resources/views/livewire/` 를 볼 것 — forwarding·verdicts·portal·assistant·notify 등).
> 화면 이름·탭 이름을 사용자에게 안내하기 전에 **lang 파일에서 실제 렌더 문자열을 확인**할 것(추측 금지 — 실제로 틀린 안내를 했다).
1. **listings**(영업): **상태 탭**(전체(기본)·진행중·현지확인대기·검차완료·회신대기·수락·낙찰/확정·ERP전환완료·종료) + **페이지당 건수(10/20/30/50/100·건수만, 기본 30) + 페이지네이션** · 매입예정 추가(출처 토글·TimeGate 가드, 차량번호/소유자/차값/할인 가로 grid) + 본인 글 행클릭 편집 드로어. 드로어 하단에 **본인 등록건 삭제**(soft delete — ERP 미연동 + accepted 이전 상태만, 아래 「완료된 로드맵」 참조).
2. **inspection**(현지확인): 지역별 그룹 + 모바일 드로어(사진/영상 업로드·메모·최종금액). **전달/회신 = "선택 후 저장" 수동씬**(클릭=색강조만, 하단 저장이 상태전이 커밋).
3. **auction**(경매/구매): accepted 차량 낙찰/유찰·구매확정/취소(→ won/failed) + 소유자·입금정보. won → 연동 B 자동 push.
4. **manage**(관리자): KPI 5종(**클릭=그 차원 필터 토글**) + **필터(검색·상태·출처·회신, 가로 grid) + 페이지네이션(20)** 전체현황 + **무제한 수정 드로어(어지간한 필드 전부 — 식별값은 미연동만)**. 모든 변경은 옵저버가 감사기록.
5. **users**(관리·super): 계정 생성·역할·활성토글·**car-erp 영업 이메일 매핑**. **시스템관리자 지정 체크박스와 super 계정 행은 super 에게만** 노출·허용(서버 가드 = `save`/`openEdit`/`toggleActive`).
6. **audit**(super): 감사로그 — 변경이력(board_audit_logs, 상태/회신/출처 한글표시) + car-erp 전송로그(integration_events payload). 페이지네이션. /manage 에서 분리.
7. **forwarding**(영업): 검차완료(inspected) 차 견적 금액 입력 → 바이어 전달(awaiting_buyer).
8. **verdicts**(영업): 회신대기 차 수락/거절 처리.
9. **portal**(영업·관리): car-erp 읽기 미러 — 탭 = `요약`·`미수금`·**`재고`**(지급대기/일반재고/선적전/출고완료/거래완료)·`판매내역`·`정산내역`·`선적요청`. §11 신호 버튼([입금요청]·[판매대금확인])이 여기 있다. 운항 칩(🚢운항중/⚓도착예정)은 **진행상태와 직교하는 별개 축**(`SKILLS.md §14-4`). 상세 = `SKILLS.md §14`.

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
php artisan serve --port=8003          # 개발 서버 (APP_URL=:8003 과 일치)
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
- master 머지 = **두 박스 자동배포 발동**(deploy.yml environment matrix: heymanboard + ssancarboard 병렬, master push 트리거). 배포 후 검증루틴은 메모리 참조.

---

## 🔗 SSANCAR 4시스템 통합에서 board 의 위치
```
ssancar.com(매물 카탈로그) ─ respond.io(채팅/AI) ──A── board(매입검차경매) ──B── car-erp(원장)
                                  └──────────────── C ──────────────────────────────┘
```
board = "살게요" 한 차를 실제로 매입·검차·경매하는 업무보드. 낙찰되면 car-erp 로 넘겨 재고 전환(연동 B).
- 배경 문서: `meetings/Fullworkflow.md`(통합 종합), `meetings/board-flow.md`(**전체 흐름·연동 결정·실대화 점검** — 연동 착수 전 필독), `meetings/respond.md`(개인정보처리방침 — respond.io 연동 시 사용).
- 상세 회의록(car-erp repo): `docs/meetings/2026-06-02-purchase-board-architecture.md`(board 설계) · `2026-06-04`·`2026-06-05`(통합 로드맵).

## ✅ 완료된 로드맵 (배포됨 — 한 줄 요약, 상세는 메모리/회의록)

> board MVP + 아래 통합·재설계 모두 운영 배포 완료. 상세는 각 메모리(`_ARCHIVE.md` 인덱스)·회의록 참조.

- **연동 B** (won → car-erp `POST /api/internal/purchase-sync`, HMAC+멱등, 영업담당 이메일매칭, `car_erp_vehicle_id` 역참조, `integration_events` 로그): 배포·실거래 e2e 완료. payload 권위 = `SKILLS.md §12`. [메모리 board-amount-mapping]
- **연동 A-inbound** (respond.io webhook → 승격·c_no·`respond_conversation_id`·buyer_verdict): 운영 완료. 설계 = `meetings/integration-A-design.md`.
- **연동 A-outbound** (검차사진·영상 → 바이어): **ssancar.com CDN 링크 방식**으로 대체·배포(§28 외관필터는 그 경로에 반영). [메모리 board-ssancar-cdn-video-link, board-ssancar-auto-forward]
- **연동 C** (car-erp 입금 → respond.io): car-erp 측 작업, board 무관.
- **운영/배포**: S3 전환(`BOARD_PHOTO_DISK=s3`, car-erp 버킷 prefix 재사용)·deploy.yml matrix(heymanboard+ssancarboard 자동배포)·도메인(`board.heymancar.com`)·DB 백업 완료.
- **§6 현지검차 UX·금액 재설계**: Model A 로 배포(2026-07-07, 씬재배치 포함). 권위 = `meetings/board-flow-resequencing-2026-07-06.md`. [메모리 board-flow-model-a-deployed]
- **현지확인 = 검차할 차만 + 매입예정 「검차상태」·사진만으로 전달대기**(2026-10-13 배포, master `e2cee78` — heymanboard·ssancarboard success, db:backup ✓, 마이그 `ssancar_poll_result` Ran): 현지확인 화면이 검차 끝난 차(inspected·회신대기·수락)까지 계속 보여줘 heymanboard 14대 중 10대가 끝난 차였다 → **draft 만**(토글·탭 없음 — 사진·영상은 ssancar.com 연동으로 들어오니 완료 차를 거기서 다시 볼 이유가 없다, Jin). 배정 후보 지역도 draft 만. 대신 **영업이 보는 매입예정에** ① 폴러가 남긴 마지막 판정(`ssancar_checked_at`·검차글 사진/영상 수·재고글 사진 수 — 마이그 1)으로 「왜 아직 안 넘어갔나」 한 줄(`PurchaseListing::ssancarHoldHint`, 문구 6종 `inspection.hold_*`)을 **목록 「검차상태」 칸 + 드로어 카드**에 ② 드로어에 **「지금 있는 사진으로 전달대기로 넘기기」**(본인 draft·시간잠금 아님·미디어 1건 이상 → draft→inspected, 옵저버 감사, 알림톡 B 동일 발사; 미디어 0 이면 안내만). 전달 화면이 ssancar 미디어를 실시간으로 다시 가져오므로 나중 올라온 영상은 바이어 링크 재열람에 들어간다. 목록 표는 8칸 가운데 정렬(`tbl-center`): 차량·출처·최종금액·추가검사사항·**바이어(이름)·바이어 회신(칩)**·상태·검차상태. 🚫 현지확인 화면에는 안내 줄을 넣지 않는다(영업이 못 보는 화면). 🚫 화면이 ssancar 를 직접 묻지 않는다(폴러 저장값만). 로컬 `.env` 에 `BOARD_SSANCAR_AUTO_FORWARD=true` 넣어 둠(백업 `.env.bak-autoforward-20261013`) + 로컬 draft 4대에 샘플 판정값 심어 둠(폴러가 돌면 덮임).
- **ERP 후속 2건 배포 완료 — 정산 마진율 동봉 + sync 변경 없는 행 no-op**(2026-10-14 저녁, Jin 통보 · heymanerp HEAD `60a5a3e` 실측, car-erp `fe21493`·`60a5a3e`): ① ERP 가 `/settlements` 행·`/payout-batches` 의 `settlements[]`·`unbatched_paid[]` 세 행 맵에 **`margin_rate`(마진율) 만** 동봉(총마진 등 다른 마진 값은 안 옴). heymanboard 실측 무사백 254행 전부 키 있음 → 요약 차량별 표 마진율 열이 「—」에서 값으로 채워짐(board 재배포 없음). ℹ️ **마진율 노출은 Jin 결정(2026-10-14)** — 종전 「마진 노출 = 대표 승인 사안」 기록을 알린 뒤 Jin 이 재확인. 마진율 × 판매가 = 총마진이라 영업이 차 단위 회사 이익을 역산할 수 있다는 점은 인지된 상태. ② ERP `sync` 가 **변경 없는 requested 행을 건너뜀**(갱신·`requested_at`·관리 알람·`updated` 집계 없음) → board 선적 계획에서 새 차 1대 선적요청 시 기존 묶음 알람 재발사·「갱신 N」 소음 해소. 남음(선택) = 무사백 계정으로 새 차 1대 선적요청해 배너가 「생성 1」만 뜨는지 1회 확인.
- **주말 4건(딜러 서류 OCR·적립금 탭·여권/ID Invoice·운임비 문구) + 묶음 미수 판매통화 + 금액 다시 보내기 쪼개기 — board 배포**(2026-10-29 Jin 「배포해줘」, master `be8de72` — heymanboard·ssancarboard success, db:backup ✓ 1480.4 KB / 344.1 KB, 마이그 `2026_10_29_000001`·`000002` DONE, phpspreadsheet 5.10 설치(박스에 unzip 없어 PHP zip 확장으로 — 경고만)): **board 먼저 올라갔고 같은 날 ERP master `8347b1e5` 3사 배포 완료**(car-erp 세션 통보: 런 40816008297 잡 5 success, 마이그 1) → ②③④①·묶음 `unpaid_by_currency` 전부 운영에서 양방향 활성(board 재배포 없음). ⚠️ ERP 같은 배포에 **재고 분류 의미 변경** 동봉 — `category=shipped_out` 이 「출고일 있음 OR 선적일 ≤ 오늘」로 넓어져(일반재고·선적전은 그만큼 좁아짐) 3사 902대가 출고완료로 이동 → board 포털 재고 숫자가 달라 보여도 정상(ERP 정의를 그대로 미러). 운영 첫 컨테이너 선적요청에서 `freight_skipped.no_rate` 가 많으면 그 차들은 USD 환율/판매 환율이 비어 있는 것(ERP 에서 사람이 넣음). 로컬 e2e 완료(board 91샘0001 → 로컬 ERP 273: 송금인·매입처 5키 수신 ✓, remitter POST·`/buyers/{id}`·`/sales` ✓, 인보이스 생성 ✓). 같이 나간 것 = 매입예정 드로어 「금액 다시 보내기」 칸이 구매 탭과 같은 쪼개기(총판매가·통화·운임비 USD·환율 USD/EUR → 판매가·운임비 계산, `88e4125`) · 묶음 카드 미수 판매통화(`f674b14`, ERP 키 전엔 원화). 규칙 = `SKILLS.md §12·§14-8·§14-15c·§14-27·§14-28`. 남음 = ERP master 배포 뒤 운영 e2e(여권 1장 → 후보 → 저장 → ERP 전송 → 인보이스) · ④ 사업자등록증·명함 실샘플 프롬프트 검증 · ① `freight_skipped` 사유 board 표시.
- **계좌추출 상태 표시 3건 + ssancar 미디어 캐시 「영상 대기」만 2분**(2026-10-28 배포, master `214d4da` — heymanboard·ssancarboard success, db:backup ✓ 1447.1 KB / 323.4 KB, Nothing to migrate): ① `/auction` 「이미지에서 계좌·소유자 찾기」 결과 상자가 버튼에서 ~450px 위라 눌러도 반응이 없어 보였다(Jin 실사용·헤드리스 실측) → 버튼 아래 상태 줄 + 결과 도착 시 자동 스크롤 + pending 은 5분 넘어도 「실패」 대신 「오래 걸림」(15초 폴링, 실패는 failed 만; 확정 잠금 5분 창은 그대로). 규칙 = `SKILLS.md §14-15b`. ② 운영 e2e 에서 영상 업로드 1초 전 조회가 「영상 없음」으로 10분 캐시돼 전달대기가 ~12분 늦었다 → `SsancarMediaService::ttlMinutesFor()`: 검차글 사진>0·영상 0·재고 사진 0 만 2분(`BOARD_SSANCAR_MEDIA_CACHE_WAITING_MINUTES`), 나머지 10분(`BOARD_SSANCAR_MEDIA_CACHE_MINUTES`) — ssancar 추가 호출은 영상 대기 차에만(Jin A안, ssancar 변경 0). B안(영상 done 이벤트)은 ssancar 가 그 이벤트를 안 보내 보류. 규칙 = `SKILLS.md §14-24b`.
- **시스템관리자 전체 바이어 선택**(2026-10-28 배포, master `f525c3b` ↔ car-erp master `2349598f` — heymanboard·ssancarboard success, db:backup ✓ 1439 KB / 302 KB, Nothing to migrate): 매핑 없는 super 가 등록한 차는 ERP `/buyers` 가 403 이라 바이어 필수 락에 걸려 구매확정이 안 됐다(운영 e2e 중 발견). `/auction` `buyerScope()` — 로그인 사용자가 super 면 등록자 무관 `scope=all` + **본인 매핑 이메일**(= ERP super 로그인 이메일), **판정은 ERP**(활성 super 일 때만 전체). 영업·관리·업무관리자는 종전 등록자 스코프. 드롭다운 「· 영업이름」. 🚫 `hasFullAccess` 로 넓히지 말 것. 담당 자동 배정 안 함(super 확정 = 테스트 용도, 즉시 삭제). ⚠️ 운영 설정 = `/users` super 매핑에 ERP super 이메일(두 박스 각각). 규칙 = `SKILLS.md §14-7b`.
- **§11 입금요청 재전송 — 계약금·대표계약금·매입잔금을 같은 내용으로 다시 알린다**(2026-10-27 배포, master `9f3f085` ↔ car-erp master `123ffd55` — heymanboard·ssancarboard success, db:backup ✓ 1429.8 KB / ssancarboard(로그 마스킹, 이전 165 KB), Nothing to migrate · ERP 는 마이그 1): 열린 요청은 ERP 멱등 가드에 걸려 재촉할 길이 없었다(ERP 에서 확인·거절로 닫는 것만). 그 type 칩이 open 이면 버튼이 「계약금 재전송」(종류 유지), confirm(차량·종류·금액·회차) 뒤 `resend: true`. 회차는 칩 「재전송 N/5」(분모 = ERP `resend_limit`, board 는 5 미보유), 상한이면 버튼 비활성+사유. 금액칸 비면 열린 요청 금액 그대로. 🚫 서버가 칩을 보고 플래그를 켜지 않는다(재전송 버튼에서만). Jin 결정 = confirm 만·5회·거절 행 제외. 받는 쪽 권위 = car-erp `board-portal-api.md §11-3`, 보내는 쪽 = `SKILLS.md §14-2`. 남음 = Jin 운영 실호출 1건 대조(금액 같음+재전송 → 칩 회차·ERP 배지).
- **매입예정 담당자 칸 + 업무관리자 포털 조회 + 등록증 소유자 2차 질의**(2026-10-23 배포, master `ab15e67` — heymanboard·ssancarboard success, db:backup ✓ 1427.7 KB / 113.9 KB, Nothing to migrate): ① `/listings` 표·카드에 담당자(등록자) — 전체가 보이는 사람(`canSeeAll`)만 ② 업무관리자도 `/portal` 사용자별 조회 — **쓰기는 super 만**(`proxyWriteBlocked()`, 버튼은 보이고 누르면 「조회 전용」) ③ 계좌추출 — `doc_type` 이 자동차등록증인 사진만 위쪽 45% 잘라 소유자 재질의, 1·2차가 다르면 둘 다 후보, 숫자·은행·발급기관 등 쓰레기 값 제외. 계기 = ssancarboard 334소8009(소유자는 영업이 예금주를 손으로 옮긴 것 — OCR 아님, 등록증 정답 `배한규(번영모터스)상품용`). 운영 드라이런 heymanboard 81대·ssancarboard 3대(저장 없음) 결과 = `SKILLS.md §14-15`.
- **삭제·재전송 권한 확대**(2026-10-22 **배포 완료** master `cb12d92` — heymanboard·ssancarboard success, db:backup ✓ 1408.6 KB / 105.2 KB, 마이그 없음): `/manage` 삭제·car-erp 재전송 = super 전용 → **관리 이상**(관리 role·업무관리자·super, `canManageActions`). 재전송을 묶을 이유가 없어졌다(금액·사진을 따로 보내는 경로, Jin). ⚠️ 삭제는 ERP 로 넘어간 차도 지운다(board 행만, ERP 차량은 남음). 영업 본인 삭제(`/listings`)는 **accepted 허용**(셀프검차매입 등록 즉시 accepted 라 버튼이 없었다) — won·synced·ERP 연동·시간잠금 경매는 여전히 막힘(시간잠금 해제는 Jin 대기).
- **계좌추출 — 계좌 전부 후보 + 등록증 소유자 후보 + [이미지에서 계좌·소유자 찾기]**(2026-10-22 **배포 완료** master `cb12d92` — heymanboard·ssancarboard success, db:backup ✓ 1408.6 KB / 105.2 KB, 마이그 없음): 모델이 계좌 1개를 고르다 평생계좌 사진에서 후보 0 이 됐다 → 번호를 전부 이름표와 함께 내고 사람이 고른다. 소유자는 자동차등록증 소유자만 읽어 [소유자에 적용]. 종전 버튼은 새 사진이 있을 때만 돌았다(기존 사진으로는 무반응). 상세 = `SKILLS.md §14-15`.
- **셀프검차 구매 금액 — 운임비 USD 고정 + USD·EUR 환율 + 판매가 자동 조정**(2026-10-22 **배포 완료** master `cb12d92` — heymanboard·ssancarboard success, db:backup ✓ 1408.6 KB / 105.2 KB — 마이그 `2026_10_22_000001`): 운임비는 USD(예: RORO 알바니아 세단 2,040)인데 판매는 EUR 이라 환율에 따라 운임비가 흔들렸다 → `/auction` 셀프검차 칸 = 총판매가·운임비 USD·환율 USD·환율 EUR 입력, 운임비(견적통화)·판매가는 계산(판매가 = 총판매가 − 운임비, 소수 2자리, 총판매가 고정). 2026-08-10 「자동계산 없음」을 이 두 칸에 한해 뒤집음(Jin). **같은 날 이어서 전 출처로 확대**(옛 할인율·차감액·배송 칸 제거, 비-셀프는 총판매가 적을 때만 판매 락) + **이상 총판매가 표시**(매입가+매도비−매입가×9%×45%, 보여주기 전용) + 연동 B 에 `transport_fee_usd`(정수)·`usd_exchange_rate` 동봉(ERP dev `4e48bac3`, 버전 그대로 — 운임비 USD 비우면 컨테이너 1/N 이 나중에 **USD 메모만** 채움. 판매통화 운임비는 ERP 에서 수기 = Jin A안, 그 전까지 CFR 정산 대기). 상세 = `SKILLS.md §14-5·§12`.
- **매입예정 — 매물 링크 1칸 통합 + 검차 요청사항 칸**(2026-10-21 배포, master `5e1f504` — heymanboard·ssancarboard success, db:backup ✓ 1397.4 KB / 81.4 KB, 마이그 `2026_10_21_000001` 두 박스 DONE): ① 엔카/ssancar 링크 칸 → **1칸**(사이트는 주소로 판별 `ListingLink::parse` — 원래도 칸이 아니라 주소로 정하고 있었다). 출처 **기본 선택 폐지**(미선택 저장 = 「링크를 추출하거나 출처를 고르세요」), 외부 경매·셀프검차는 사람이 고름, 추출 후 칸을 비워 다음 링크 합침(출처 = 마지막 추출), 모르는 사이트 = 미지원 안내(`isKnownSite` — 사이트 추가 시 둘 다 고칠 것) ② `inspection_request`(영업 → 검차원, varchar 255) — 등록 폼·드로어 입력(셀프검차 숨김·저장 안 함), `/inspection` 읽기 전용, `/manage` 편집, 감사 대상. ⚠️ `inspection_note`(추가검사사항)는 검차원 **결과** 메모 — 섞지 말 것. ssancar.com 검차 큐 upsert 에 같은 이름으로 실린다(미구현).
- **포털 재고 「거래완료」 탭 + KO/EN 정리 + 감사로그 전송 표 사람 말**(2026-10-20 배포, master `78264d0` ↔ car-erp `a142073e` — heymanboard·ssancarboard success, db:backup ✓ 1401.4 KB / 67.7 KB(ssancarboard 감소 = 만료 세션 정리), Nothing to migrate): ① 출고완료가 거래완료로 덮여 움직이는 차가 묻혔다 → ERP `category=completed` 신설, **배 위면 거래완료여도 출고완료에 남는다**(Jin B안). 배포 직후 heymanboard 실조회 출고완료 13(운항중 거래완료 8)·거래완료 244 ② EN 에서 차량번호 30NA3333(**표시 전용**)·ERP 진행상태/운항 라벨 영문·원화 기호·JS 알림창 ③ `/audit` 전송 표 방향·대상·이벤트·응답·내용을 사람 말로(코드는 툴팁). 규칙·함정 = `SKILLS.md §14-2·§14-24`.
- **포털 요청 칩 — 재요청이 옛 거절에 가려지던 것 + 거절 칩 접기**(2026-10-20 배포, master `da7622e` — heymanboard·ssancarboard success, db:backup ✓ 1392.9 KB / 80.4 KB, Nothing to migrate): heymanboard 34루7841 재요청 「확인됨」 대신 옛 「거절」이 떴다(ERP 최신 id 먼저 응답 + chipMap 덮어쓰기) → 같은 차·type 은 `requested_at` 최신 묶음. 거절 칩은 「거절 ⓘ」 한 줄, 눌러서 사유·금액(Jin A안). 상세 = `SKILLS.md §14-2`.
- **포털 재고 표 PC 폭 맞춤**(2026-10-20 배포, master `3cc86a3` — heymanboard·ssancarboard success, db:backup ✓ 1394.4 KB / 81.1 KB, Nothing to migrate): 8칸 전부 nowrap 이라 표가 화면을 넘쳤다 → nowrap 은 원자값(차량번호·칩·날짜·금액)만, 나머지는 접힘(요청칸 `min-w-[15rem]`). 남는 예외 = 출고완료 탭 운항 상세 줄.
- **포털 재고 탭 — 입고일/출고일 헤더 lang 키 누락 + 한글 표 헤더 세로 끊김**(2026-10-20 배포, master `0181285` — heymanboard·ssancarboard success, db:backup ✓ 1394.4 KB / 81.1 KB, Nothing to migrate): `PORTAL.COL_WAREHOUSE_IN` 원문이 2026-08-09 부터 떠 있었다 → 키 추가 + `.tbl th` `word-break:keep-all`(전 화면 표 공통). 재발 방지 = 리터럴 lang 키 전수 대조 테스트 `test_every_literal_lang_key_used_in_code_exists_in_both_locales`.
- **판매가 지어내기 폐지 — 기본 통화 없음 · 드로어 운임비 · 「저장」이 바뀐 금액·새 사진 전송 · 중복 파일 건너뛰기**(2026-10-16 배포, master `3517a97` — heymanboard·ssancarboard success, db:backup ✓ 1359.1 KB / 61.3 KB, Nothing to migrate): heymanboard 17두0923 — 통화 안 고른 엔카 차가 `/manage` 로 won 되자 Job 이 `offerAmount()` USD 폴백으로 **판매가 6,571 USD·1,324 를 지어내** ERP 에 저장(8,700,000 ÷ 당일 환율), 영업이 넣은 5,700 EUR 은 ERP fill-if-empty 가 `already_set` 으로 무시. **전수 12/74대**(엔카·싼카체킹 전부)가 같은 경로로 ERP 에서 수기 정정돼 있었다 — `/forwarding`·`/auction` 이 통화 기본 표시값 KRW 를 저장하지 않아 정상 흐름도 `offer_currency` null 로 남았기 때문. 수정 = ① `offerAmount()` 통화 없으면 null(파생 없음) → Job 판매 3종·운임비 **비워 전송**(ERP 판매탭 빈 채 생성 → 드로어에서 채우면 fill-if-empty 가 받음) ② `/forwarding`·`/auction` 통화 **미선택 시작**(기본 없음), 전달 시 통화 필수(KRW 도 선택), 첫 선택 저장 ③ 드로어 「금액 다시 보내기」 운임비 칸 ④ 「저장」 = 금액 하나라도 바뀌면 금액+첨부, 사진만 새로면 attachmentsOnly, 둘 다 없으면 안 보냄(세트 검증 동일) ⑤ 같은 이름 파일 건너뛰기(이름 기준). ⚠️ 비-셀프 차를 통화 없이 확정하면 ERP 에 **판매가 없이** 생겨 「일반재고」에 앉는다 — 버그 아님, 드로어/ERP 에서 채운다. 상세·함정 = `SKILLS.md §14-23`. 보류 = `/manage` won 경고(원화 거래 애매, Jin)·ERP 덮어쓰기 옵션. 미해결 = ERP 244보9276 EUR·환율 1334 불일치 확인, 17두0923 첫 5장 삭제 경위.
- **포털 「갱신」 버튼 상태 표시**(2026-10-14 배포, master `8261781` — heymanboard·ssancarboard success, db:backup ✓ 1329.5 KB / 53.1 KB, Nothing to migrate): 눌러도 아무 표시가 없어 눌린 건지 반영된 건지 몰랐다(요약 탭은 ERP 호출 5개 = 2~5초). 글자만 「↻ 갱신」→「⟳ 갱신 중…」(비활성·스피너)→2초 「갱신됨 ✓」→「↻ 갱신」. 시각 표시는 공간을 먹어 안 쓴다(Jin). 비활성화가 분당 120 공유 한도도 지킨다. 구현 = `reload()` 가 `portal-reloaded` 디스패치 → 버튼 Alpine 이 2초 전환, 로딩은 `wire:loading` + `wire:target="reload"`.
- **포털 — 선적 계획 = 아직 안 묶인 차만 · 요약 정산 차량별 표 · 미완납 칩 방어**(2026-10-14 배포, master `c893cc3` — heymanboard·ssancarboard success, db:backup ✓ 1327.1 KB / 55.8 KB, Nothing to migrate): ① **선적 계획에는 아직 선적요청 전인 차만**(Jin — 「ERP 에서 묶었으면 선적묶음에서 보여야지, 계획에서 선택돼 있으면 누가 쓰나」). heymanboard 무사백 담당의 requested 35대가 계획에 체크된 채 떠 「지금 보낼 차」로 읽혔다. requested 묶음은 「내 선적묶음」 전용(확인·취소·변경요청), 계획은 새 묶음만 그리고 편집. 🚨 `/sync` 가 선언형이라 requested 묶음은 **desired 에 남겨 payload 로 그대로 보낸다**(빼면 ERP 자동 취소) + 서버 메서드도 batch_id 있는 묶음을 건너뛴다. pool 차 있는 바이어는 requested 묶음이 있어도 새 묶음 시드(기존 묶음에 추가하는 경로 폐지). ② **요약 정산 차량별 표 = 차량번호·마진율·건당금액 3열 고정, 가운데 정렬**(ERP 승인 카드 「차량별 보기」 미러, 진행 중 정산·월 펼침 배치·배치 밖 세 곳, 조정 행은 사유 colspan). 🚫 일자 등 임의 열 추가 금지(붙였다가 Jin 지적으로 되돌림). 마진율은 ERP `margin_rate` 가 오기 전엔 「—」(ERP 요청 = 아래 남은 작업). ③ 미완납 칩 — 미수 필드가 **없는** 행엔 칩을 안 그린다(필드 없음 ≠ null; 거짓 「환율 미입력」 35대 사고). ERP 는 같은 날 `/bundles` 차량 행에 미수 4필드를 3사 배포(`38bc1a22`, 스펙 §5-1) — ①로 묶인 차가 계획에서 사라져 이 칩은 pool 차에만 남는다. 상세 = `SKILLS.md §14-8`.
- **포털 문구 2건 — 미수금 헤더 합계 = 바이어 통화 + 선적 계획 「동기화」→「선적요청」**(2026-10-14 배포, master `dd6cea7` — heymanboard·ssancarboard success, db:backup ✓ 1324.8 KB / 54.5 KB, Nothing to migrate): ① 미수금 탭 바이어 접기 헤더가 원화 합(`unpaid_krw`)이었는데 거래 통화와 달라 실무자에게 어색했다 → 행과 같은 식(`unpaid_ratio × sale_total`)의 **바이어 통화별 네이티브 합**(통화 혼재면 `USD 1,000 · EUR 500`, 미납률 없는 차는 「N대 합계 제외」). 원화는 바이어 간 비중 게이지 분모에만 남는다. ⚠「N대 환율미입력」 배지는 이제 합계 결손이 아니라 ERP 완납 판정 불가 경고다. ② 선적 계획의 「동기화」(버튼·경고·완료 배너·소개문·차단 메시지)는 실무자에게 생소해 **「선적요청」**으로(동작·`syncBundles` 동일, 영문 Request shipping). 탭 이름도 「선적요청」이라 같은 말이 두 번 보인다 — Jin 인지.
- **업무관리자 등급(permission=manager)**(2026-10-14 배포, master `4744fdb` — heymanboard·ssancarboard success, db:backup ✓ 1323.9 KB / 53.7 KB, 마이그 `2026_10_14_000001` 두 박스 DONE): 감사로그를 볼 사람이 super(=Jin)뿐이라 「누가 넣었나」를 매번 Jin 이 조회해 줘야 했다 → car-erp 미러로 **super > 업무관리자 > user** 3단. 업무관리자 = role 무관 전체 화면 + `/audit` + `/users`(super 계정 제외) + 종·푸시 수신, **super 전용 유지** = 기능설정·등급 지정·포털 대리실행·`/manage` 삭제/재전송(의도적 보류). 위 등급 계정은 아래 등급이 수정·비활성화 불가(관리 role 의 업무관리자 비밀번호 교체 = 감사로그 탈취 경로). 기존 「관리」 role 권한 무변. 배포 직후 업무관리자 0명 — 지정은 Jin. 상세·함정 = `SKILLS.md §14-22`, 규칙 = 위 「권한 시스템」.
- **감사 로그 — 등록 기록 + 판매측 금액 감사 + `/audit` 검색·필터**(2026-10-13 배포, master `6a6df51` — heymanboard·ssancarboard success, db:backup ✓ 1318.8 KB / 59.5 KB, Nothing to migrate): 「59오5691 판매가를 누가 넣었나」를 board 로그가 답하지 못했다 — 감사가 수정에만 걸려 **등록 시점 값**이 안 남고, `sale_price`·`offer_currency`·`offer_rate`·`selling_fee`·`transport_fee`·`car_erp_buyer_id` 는 감사 필드가 아니었다(답은 purchase_sync payload + heymanerp audit_logs 대조 + 코드 경로로 — 조하 님이 `/auction` 구매확정 저장 09-04 15:36:31 에 입력(등록 15:24 엔 판매가 칸이 없다), ERP 105행은 전부 표기 차이). 모델 `created` 훅에 `BoardAudit::logCreation`(action `create`, 「등록」 1행 + 채워진 필드 각 1행, 0·null 제외) + AUDITED 6개 추가 + `/audit` 에 ERP 감사로그와 같은 필터(검색 버튼·변경자·항목·날짜·건수 / 전송 표는 이벤트·성공실패·날짜) + 삭제된 차 차량번호 표시(`withTrashed`). heymanboard #72 는 등록(15:24:18)·구매확정 저장(15:36:31) 두 시각으로 create 행을 손으로 백필(Jin 지시). 상세·함정 = `SKILLS.md §14-21`.
- **매입예정 등록자 필터 + 메뉴 이름**(2026-10-13 배포, master `c016991` — heymanboard·ssancarboard success, db:backup ✓ 1319.6 KB / 52.3 KB, Nothing to migrate): 관리·super 에게 상단 「등록자 전체 ▾」 셀렉트(`#[Url] owner`, 매입예정을 등록한 적 있는 사용자 이름순). 목록·탭 건수가 같은 조건(`tabQuery`). 영업은 셀렉트 없음·URL 값 무시(SalesmanScope). 사이드바 메뉴·페이지 제목 「매입예정 (영업)」→「매입예정」. 같은 날 heymanboard 옛 draft 4건(45·70·80·90, 엔카·ssancar 미디어 없음·28~59일 경과) **소프트 삭제**(Jin 지시, 감사기록 `delete`) → 남은 draft = 셀프검차 #115 1건.
- **매입예정 삭제 — 영업 본인 손으로**(2026-09-09 배포, master `9985d86`): 검차 사진·영상이 늦어 `draft` 에 갇힌 차는 영업이 손댈 방법이 없었다 — **중복차단이 상태를 안 보고 활성 행만 보므로**(`listings/index.blade.php`) 같은 차를 다시 등록할 수도 없어, 지우려면 super 가 `/manage` 로 들어가야 했다(새벽엔 연락 불가). `/listings` 드로어에 삭제 버튼(soft delete + 감사기록, `/manage` 와 같은 경로).
  - 게이트 = **본인 등록건** + `car_erp_vehicle_id` null + status ∉ {won, synced}(**2026-10-22 accepted 허용** — 셀프검차매입이 등록 즉시 accepted 라 버튼이 안 보였다) + `editable()`(시간잠금 경매는 읽기전용 = 삭제도 막음). 남의 글 전체 대상 삭제는 **`/manage` 경로**(2026-10-22 부터 관리 이상) — 관리 role 이 `/listings` 에서 전체를 보므로 소유자 조건을 안 걸면 그 정책이 조용히 뚫린다.
  - 🚨 **거절(`rejected`)은 딜만 닫는다 — 재등록은 안 열린다.** 중복차단은 status 를 안 본다. 다른 바이어가 같은 차를 사겠다고 하면 **삭제 후 재등록**(또는 관리자가 `/manage` 에서 상태를 되돌려 같은 행 재활용 — 이쪽은 검차 사진·이력이 남는다). 가드 = `test_rejected_still_blocks_reregistration_until_deleted`.
  - 재등록 차는 `created_at` 이 새것이라 **ssancar 폴러 후보에 다시 들어간다**(에이지아웃 3일) → 검차글 영상이 그대로면 자동으로 전달대기까지 복귀. 단 **board 에 직접 올린 검차 사진·메모는 옛 행에 남아 안 따라온다**.
- **대표계약금**(2026-09-17 배포, master `fd631e2` ↔ car-erp `ae14f24`): 입금요청 알림톡이 **시각 규칙**(근무시간=담당자 / 시간 밖=대표)을 타는 탓에, 담당자가 휴가·외근이면 요청이 담당자에게만 가 있고 **Jin 이 사람 손으로 확인해 줘야 했다**. `/portal` 재고(지급대기) 행에 **[대표계약금]** 버튼 1개 — 보내는 건 계약금과 같고 **수신자가 대표 고정 · 시각 규칙 무시**만 다르다. 기존 계약금·매입잔금 씬은 그대로(실사용 중). 상세·함정 = `SKILLS.md §14-2`, 긴 스펙 = `meetings/handoff-carerp-ceo-deposit-request.md`.
  - ⚠️ 세 번째 **별개 type**(`purchase_deposit_ceo`) — 플래그로 얹으면 멱등키 `(vehicle_id, type)` 에 걸려 **조용히 버려진다**. 같은 차에 둘 다 열리는 게 정상이고 board 는 안 막는다.
  - ℹ️ ERP 라우팅은 시각 규칙 표가 아니라 **코드 분기**(표에 얹으면 기존 3종까지 평일에 대표로 간다). 대표 수신자 = 시각 규칙 fallback 재사용, 중복 방지 = 뱃지 병렬 표시만(둘 다 Jin 결정).
  - 남음 = **실거래 e2e**(대표계약금 1건을 실제로 눌러 대표 알림톡 도착 확인).
- **셀프검차매입**(2026-08-09 배포, master `03f45fb`): 영업이 직접 검차한 차 — ssancar 검차글에 영상이 없어 자동전이가 안 걸리고 갇히던 경로. 등록 즉시 `accepted` 로 만들어 `/auction` 에서 마무리. 상세 = 위 「출처」 절.
- **§11 요청·확인 신호 + 재고 4분류**(2026-08-09 배포, master `b0f875a`): 카톡으로 하던 "입금해주세요/대금 확인해주세요" 두 마디를 포털로. 같이 **`매입내역` 탭을 `재고` 4분류로 교체**(전량조회 → 유한 집합). 상세·함정 = `SKILLS.md §14-2·§14-3`, 인계 = `meetings/handoff-carerp-board-requests.md`·`handoff-carerp-inventory-for-board.md`.

- **운항 상태(🚢운항중/⚓도착예정)**(2026-08-10 배포): 진행상태와 **직교하는 축** — 포털 판매내역·재고에 칩, 판매내역엔 서버측 운항 필터. 「도착예정」은 ETA 경과이지 **입항 확인이 아니다**(문구 고정). 상세 = `SKILLS.md §14-4`.
- **셀프검차매입 금액 입력·락**(2026-08-10 배포, 마이그 `selling_fee`·`sale_price`·`transport_fee`): 그 경로는 검차·견적 씬을 건너뛰어 금액을 넣을 곳이 없었고 **won 이 조용히 422** 로 죽었다. `/auction` 드로어에 **출처별로 다른 금액칸**(셀프검차 = 차값·매도비[KRW] + 판매가·환율·운임비[견적통화], **자동계산 없음** — 2026-10-22 판매가·운임비는 쪼개기 계산으로 바뀜) + **필수 락 4개**(차값·판매가·통화·환율). 매도비는 셀프검차에서만 차값에서 뺀다. 상세 = `SKILLS.md §14-5`.
- **포털 차량 메타**(2026-08-10 배포): 차량번호 옆에 차대번호·브랜드/차종(미수금·재고·판매내역·선적요청 4탭. 정산내역은 차량 행 없음). 값은 전부 car-erp 가 준다 — 없으면 안 그린다. 상세 = `SKILLS.md §14-6`.
- **구매확정 첨부 필수 + 저장순서 · 모바일 사이드바 재발 수정**(2026-08-18 배포, master `75cb242`): 첨부 0건이면 **확정 자체를 막는다**(연동 B 는 won 시 1회 발사라 "전송만 보류"하면 재전송 안 누른 차가 영영 ERP 에 없다). 🚨 같이 발견 — 첨부가 status 저장 **뒤에** 저장돼 **그 자리에서 올린 파일이 payload 에서 빠지고 있었다**(로컬 sync 큐는 항상, 운영은 타이밍 의존). 사이드바는 가드가 인스턴스 로컬이라 `wire:navigate` 누적 시 무효 → **window 전역**으로. 상세 = `SKILLS.md §14-13·§14-14`.
- **판매가 나중 채우기 + 선적계획 서류·서명 + 422 사유 분기**(2026-08-18 배포, master `a8403a1` ↔ car-erp fill-if-empty): 급해서 매입가만 보낸 차의 **판매가·통화·환율을 `/listings` 드로어에서 채워 재전송**(ERP 가 빈 칸만 채움, `fields_filled` 로 확인). 판매계약서·프로포마·서명을 **선적 계획에서도**. 서류 422 를 **원문으로 갈라** 안내(전부 "동일 바이어"로 뭉뚱그리던 것). 상세 = `SKILLS.md §14-11·§14-12`.
- **시스템관리자 타인 포털 대리실행**(2026-08-18 배포, master `3a3a1c9`): super 가 남의 포털에서 쓰기가 전부 막혀 있었고 **선적 계획은 화면 자체가 안 그려졌다**. 차단 8곳 제거 + 배너에 **"요청은 OO님 명의로 전달됩니다"**(그게 유일한 안전장치). ⚠️ `isViewingOther()` 는 super 일 때만 true — 그 차단은 처음부터 super 만 겨냥한 것이었다. 상세 = `SKILLS.md §14-10`.
- **매입예정 드로어에서 딜러 첨부 보기**(2026-08-12 배포, master `88784c6`): 올리는 곳은 `/auction` 하나뿐인데 그 화면은 accepted·won 만 다뤄 **synced 가 되면 board 어디서도 못 봤다**. 매입예정이 본인 차를 전 상태로 여는 유일한 화면이라 거기서 읽기 전용으로. 상세 = `SKILLS.md §14-9`.
- **재고 검색에 바이어**(2026-08-12 배포, master `20a2b84` ↔ car-erp `40b5c4b`): ERP 가 훑는 칸에 바이어명 추가(board 는 문구만). ⚠️ 검색 대상은 **8가지**(차량번호·브랜드·차종·차대번호·수출신고번호·선박명·컨테이너번호·바이어) — placeholder 를 실제보다 좁게 쓰지 말 것. 상세 = `SKILLS.md §14-2`.
- **선적 계획 — 미완납 후보 + 포워딩사 + 컨테이너 운임비**(2026-08-12 배포, master `b6c0105` ↔ car-erp master `94a59c3`): 돈 들어오기 전에 **미리 묶어 서류를 준비**하는 화면이 됐다(후보 조건 `판매완료` → `sale_price>0`). 묶음에 **포워딩사**(ERP 명부에서 선택, `vehicles.forwarding_company_id` 반영) + **컨테이너 운임비 USD**(ERP가 1/N로 `transport_fee_usd` 기록). ⚠️ `unpaid_krw=null`은 완납이 아니라 환율 미입력·판정 불가 / 출고일 찍힌 차도 후보에 온다 / 1/N 합계는 총액과 안 맞을 수 있다 = `SKILLS.md §14-8`. 인계 = `meetings/handoff-carerp-shippable-scope.md`.
- **입금요청 분리 — 계약금/매입잔금 + 금액**(2026-08-11 배포, master `c2b1e28` ↔ car-erp master `bb5af1a`): 받는 사람이 **얼마를 보낼지 몰라** 결국 카톡으로 되물었다 = 신호가 일을 못 끝냈다. `/portal` 재고 행이 [입금요청] 1버튼 → **금액칸 + [계약금] [매입잔금]**. ⚠️ 별개 `type` 이어야 하는 이유(ERP 멱등키)·금액 차량 키잉·자동계산 금지 = `SKILLS.md §14-2`. 알림톡은 **ERP 가 보낸다**(board 코드 0). 인계 = `meetings/handoff-carerp-payment-request-split.md`.
- **매입 등록 락**(2026-08-10 배포): 연동 B 는 car-erp 저장 게이트를 안 타므로 **바이어 고르는 상류**에서 막는다. **바이어 필수** + 락 걸린 바이어면 **구매확정 버튼 비활성**. 판정은 ERP `purchase_locked` 그대로. 상세 = `SKILLS.md §14-7`.

- **재고매입(바이어 미정)**(2026-09-08 배포, master `28e839b` ↔ car-erp v5): 차값이 쌀 때 **바이어 없이 먼저 사두는 매입**. 그런 차는 board 에 자리가 없어 바이어를 억지로 고르거나 `/manage` override 로 밀어넣는 수밖에 없었다(override 는 바이어·락·첨부·통화 락을 전부 건너뛴다). `/auction` 드로어 토글 → 연동 B **v5**(`buyer_undecided`) → 이후는 **ERP 화면에서 진행**(거기서 바이어를 붙이면 「선적전」으로 이동). 권한·출처 제한 없음, 차값·첨부는 그대로 필수.
  - 🚨 **판매가를 안 적는 것만으론 안 된다** — Job 이 `차량금액 ÷ 환율` 로 파생한다. ERP 재고 분류는 `sale_price` 하나로 갈리므로(>0 = 「선적전」) 판매측을 Job 에서 강제로 비운다. `buyer_id` 도 같이 — ERP **재전송 경로엔 매입 등록 락 게이트가 없어서**(회신 Q3) 컬럼에 남은 바이어가 나중에 실려 나가면 락 우회로가 된다. `/listings` 판매가 후보완 재전송도 이 차엔 안 준다(조용한 no-op).
  - ⚠️ 신규 재고매입 차는 **「지급대기」에 먼저 앉는다**(매입 미지급). 지급 후 「일반재고」로 이동, 그 전엔 ERP 「전체」 탭에도 안 보인다. 상세·함정 = `SKILLS.md §12` v5 절, 인계·회신 = `meetings/handoff-carerp-stock-purchase-no-buyer.md`.

- **ERP 로 넘어간 차에 사진·서류 추가**(2026-09-11 배포, master `7d4d83f`): 딜러가 사진을 늦게 주면 board 에 올릴 데가 없었다 — 업로드 화면은 `/auction` 하나뿐인데 그 화면은 accepted·won 만 다뤄 **synced 가 되면 추가할 방법이 사라졌다**(ERP 직접 입력). **수신측은 손댈 게 없었다** — car-erp 는 멱등 분기에서도 첨부를 보강하고 target 키가 source 로 결정적이라 목록을 통째로 다시 보내도 새 것만 붙는다(2026-08-18 fill-if-empty 때 이미 열린 문). `/listings` 드로어에 [📷 사진·서류 추가].
  - 🚨 **`attachmentsOnly` 재전송** — 평범한 resync 는 판매 금액도 실어 ERP 빈 판매가가 채워지고 `sale_date=now()` 가 찍힌다(→ 「판매중」 + 채권 독촉 기산점이 오늘). 판매측·바이어를 비워 보낸다(재고매입이 쓰는 검증된 길). 🚨 **cap 초과는 실패로 안 잡힌다**(10건에서 조용히 break) → 보낸 장수 vs 붙은 장수를 비교해 말한다. 삭제는 전파 안 됨(추가만 양방 일치). 상세 = `SKILLS.md §14-16`.
- **매입예정 목록 — 상태 탭 + 페이지네이션**(2026-09-11 배포, master `7d4d83f`): `latest()->get()` 으로 **전량**을 읽고 있었다(synced 는 영원히 쌓이는 통 → 랜딩이 매년 느려진다). 탭 9개(`TAB_STATUSES`) + 페이지당 10/20/30/50/100·**건수만**, **기본 = 전체 + 30건**(2026-09-11 Jin — 화면 성격은 그대로 두고 전량 로드만 끊는다). 탭 배지 = group by 쿼리 1번. 인덱스는 이미 있어 마이그 없음. 상세·함정(`min-w-0`·`#[Url]` 정규화·`input-filter`) = `SKILLS.md §14-17`.
- **매도비 — 자동 기입 폐지 + 차값과 완전 분리**(2026-09-11 배포, master `7d4d83f`): 사람이 적은 적 없는 440,000 이 **세 곳**에서 붙고 있었다(ERP 전송 폴백 · 드로어 프리필 · `/listings` 미리보기 +매도비 ⇒ **화면 금액이 저장 금액과 매도비만큼 어긋나 있었다**). 전부 제거하고 **수기 입력만**, 입력칸은 셀프검차 전용 → **전 출처 공통**. 이어서 **매입가에서 매도비 차감도 폐지**(셀프검차 예외 포함) — 차값은 차값·매도비는 매도비로 각각 그대로 ERP 전달. ⚠️ **운영 관행 변경**: 셀프검차 차값 칸에 매도비를 합쳐 적으면 안 된다(**담당자 공지 완료 2026-09-11**). 🚨 매도비는 **구매확정 전에** — ERP 는 멱등 재전송에서 `selling_fee` 를 갱신하지 않는다. 상세 = `SKILLS.md §14-18`.
- **문구 점검 — 화면에 뜨던 ERP 변수명 제거**(2026-09-11 배포, master `7d4d83f`): 재전송 결과가 **`sale_price — already_set`** 을 그대로 그리고 있었다 → 사람 말로(모르는 값은 원문 유지 — 사유를 버리면 추적 불가). 페이지네이션이 한국어 화면에서 **「« Previous」** 로 떴다(`lang/{ko,en}/pagination.php` 부재) → 이전/다음, **`/manage`·`/audit`·`/users` 등 기존 화면 전부에 적용**. 전 lang 파일 ko/en 키·치환변수 대칭 확인 완료.
- **첨부사진 계좌 추출**(2026-09-10 배포, master `3d1d05e`): 딜러에게 받은 사진에 계좌가 있는데 손으로 안 옮겨 적어 `payee_account` 기재율이 **19%(6/31)** 였다 — 연동 B 가 계좌 없이 나가고 **입금요청 알림톡이 「계좌 미등록」으로** 나갔다. 사내 GPU PC 비전 모델(`qwen2.5vl:7b`)로 읽어 **후보로 제안**하고 사람이 `/auction` 드로어에서 확정한다(자동 기입 없음). 사진 5~7장에 15~20초.
  - 🚨 **계좌는 구매확정 전에** — car-erp 는 멱등 경로에서 `payee_*` 를 갱신하지 않는다(push-once). 그래서 추출 중에는 확정을 막고, **[계좌 없이 확정하기]** 탈출구를 둔다. `conclude()` 가 `applyPayee()` 를 부르므로 **후보 적용 후 곧바로 확정하면 계좌가 함께 저장**된다.
  - 🚨 **평생계좌를 전화번호로 오인해 버리지 말 것**(031-296-5454 형태가 실제 송금 계좌다) · **프롬프트에 이중 부정 금지**(매도비를 통째로 빠뜨렸다) · **`.env` 변경 뒤 큐 워커 재시작**(안 하면 Job 이 로그 없이 무동작 종료).
  - 인프라 = gpu-office `qwen2.5vl:7b` **단독 상주**(8GB 라 챗봇과 공존 불가 → 3사 ERP 챗봇 off + 03:00 색인 정지). 1100px/`num_ctx` 4096 이 상한. 되살릴 때 = 색인 재개 → 1회 실행 → 챗봇 on, 둘 다 쓰려면 GPU 증설(16GB).
  - 상세·함정 = `SKILLS.md §14-15`, 설계 = `meetings/design-payee-extraction-2026-09-10.md`.

- **브라우저 탭 아이콘 + 홈 화면 앱(PWA)**(2026-08-21~22 배포, master `2dd9d30`·`5f9f9d0`): board 는 아이콘이 **없었다** — `public/favicon.ico` 가 첫 커밋부터 0바이트고 선언도 없었는데, 크롬이 캐시한 옛 아이콘을 그려서 있는 것처럼 보였을 뿐이다. 파비콘은 **car-erp 와 같은 것**(heymanboard=파란 H / ssancarboard=빨간 SS)을 쓰고, 같은 축으로 **manifest·홈화면 아이콘**까지 붙여 폰에서 "홈 화면에 추가" 하면 주소창 없는 앱으로 열린다(스토어·서명·심사 없음).
  - ⚠️ **인스턴스 판별 = `APP_NAME` 재사용**(실측 `board-heyman`/`board-ssancar`). board 엔 car-erp 의 `company.template_set` 같은 회사 식별값이 없지만 APP_NAME 이 이미 박스마다 다르다 — 새 값을 만들면 두 LIVE 박스 `.env` 를 건드려야 한다. 지도 = `config/board.php` 의 `instances`, 조회 = `App\Support\Instance::assets()`. **목록에 없는 이름이면 선언 자체를 생략**한다(아무거나 폴백하면 다른 회사 로고가 뜬다).
  - ⚠️ 아이폰은 manifest 의 icons 를 **안 본다** → `apple-touch-icon` + standalone 메타 별도. manifest 를 `.json` 으로 둔 건 nginx 기본 mime.types 에 `.webmanifest` 가 없어서다(박스 nginx 무변경).
  - ⚠️ **바이어 공개 페이지는 파비콘만**, PWA 선언은 안 붙인다(설치되면 로그인 화면으로 데려간다). 테스트가 지킨다.
  - ssancar 아이콘 원본은 **32px 뿐**(ssancar.com 공사중·apple-touch-icon 404) → 확대 후 알파 임계로 뽑음. 큰 로고가 생기면 아이콘만 재생성.
  - 실기 확인 = 아이폰·안드로이드 모두 정상, **`/portal` 서류 다운로드도 standalone 에서 동작**(2026-08-22 Jin) → 다운로드 방식 교체는 **불필요로 종결**.

## 현재 도메인 규칙 (§6 재설계 반영 — 코딩 시 준수)

- **금액 (Model A)**: 매입 = **원가(차값 그대로)**, 판매 = **매도비 제외**, 차감액 별도 컬럼. **매도비 = 수기 입력만**(`/auction` 드로어, 출처 무관 공통 칸 — 2026-09-11 Jin). 안 적으면 **안 보낸다**(ERP 매도비 0). 🚫 고정값 자동 기입(440,000) 폐지 · 🚫 **매입가에서 매도비 차감 폐지**(셀프검차 예외 포함) — 차값·매도비를 **별도로 적어 각각 그대로** ERP 전달. ⚠️ 셀프검차 차값 칸에 매도비를 합쳐 적지 말 것. 상세 = [board-flow-model-a-deployed / board-amount-mapping].
- **차값 통화**: 엔카 = KRW, 싼카 = 원/미/유로 토글 택1을 `car_cost` 에 **외화 그대로** 보관(`expected_price_currency`). KRW 환산은 **계산 시에만** — 단일 경로 `App\Support\Money::toKrw()` ↔ 모델 `carCostKrw/carPriceKrw/totalKrw`. **매물표시가 토글 = 차값 선택 / `displayCurrency` = 표시만**(차값 불변). `expected_price` 컬럼은 **재활용·리네임 금지**. final_price = KRW 스냅샷(연동 B 무변).
- **환율**: board 가 car-erp `/rates`(네이버 전신환매입률) 받아씀 — 값 일치. [board-exchange-rate-source]
- **입금정보**: `payee_name·payee_bank·payee_account`(**`payee_account` = `encrypted` 캐스트**). 입력 = `/listings`(영업 선택) → `/auction` 드로어 자동표시 → 연동 B 로 car-erp 전달. 은행 datalist + 계좌 동적 마스킹(`Alpine.store('koreanBanks')`).
- **사진·서류 보유 (범위한정 예외)**: 영업이 board 에 차량 사진 + 서류(**주소·RRN 마스킹본**) 업로드 → 낙찰 시 연동 B `attachments[]` 로 car-erp 첨부탭. 격리 = 전용 S3 prefix(`sales/photos`·`sales/documents`), **실행파일 차단(`App\Support\UploadGuard`)**, **서류는 바이어 전송 제외(`share_to_buyer=false`)**. `inspection_photos.kind`(inspection/sales_photo/sales_document). [board-vehicle-attachments]
- **PII 스탠스**: RRN·전화 미보유 원칙 유지. 계좌·서류는 위 *범위한정 예외*(암호화·마스킹본·표시 최소화). **딜러 휴대폰(`seller_phone`, 2026-10-28 Jin)도 같은 줄의 예외** — 사업자 연락처라 바이어·소유자 개인정보와 성격이 다르지만 계좌와 동일하게 `encrypted` + 감사·전송 로그 `***`(화면엔 그대로). 용도 = ERP 국내딜러 알림톡 번호(§14-15c).

## ⏭️ 남은 작업 (미완)

- **연동 B 감사 명령 `board:purchase-sync-audit`**(2026-09-28 Jin 승인, **dev 구현 완료·운영 미배포** — 「야간 배치 실행기」 1단계, 정본 = car-erp `docs/design/agent-virtual-office-analysis.md` §11·§12): won 인데 60분 넘게 ERP 로 안 넘어간 차(`stalled`) · 정합성 2식(synced인데 erp id 없음 / erp id 있는데 synced 아님) · synced 매물의 erp id 를 ERP `GET /vehicles/exists`(car-erp §4-3, dev `b8f2d423`)로 대조한 `missing_in_erp` 를 **JSON 으로만** 기록(`storage/app/integration/purchase-sync-audit.json`, exit 항상 0, DB 쓰기·재전송 0). 스케줄 07:40 — ERP 08:00 아침 보고가 `BOARD_AUDIT_JSON` 으로 그 파일을 읽는다. 테스트 = `tests/Feature/PurchaseSyncAuditTest.php`.
  - ⚠️ **두 박스 모두 돈다**(heymanboard·ssancarboard) — ERP 는 같은 박스의 board 파일을 읽어야 하고 경로가 다르다(`/var/www/board/...` vs `/var/www/board-ssancar/...`). ERP 측은 heymanerp 만 설정(2026-09-28 car-erp 세션).
  - ⚠️ stalled 시계 = `updated_at` — won 행을 다른 이유로 저장하면(드로어 편집) 60분이 다시 시작된다. 더 엄밀히 하려면 `board_audit_logs` 의 won status_change 시각으로 바꿀 것(미착수).
  - ERP 호출 실패 시 `missing_in_erp=null` + `errors[]`(명령은 성공) — **null 은 "없음" 이 아니라 "못 봤다"** 다.
  - **`deleted_in_erp` 분리**(2026-10-06 Jin 결정 1번안, **두 박스 배포 완료** master `abd85a5`): heymanerp 아침점검에 매일 뜨던 「ERP 미도착 5건」은 전부 **ERP 에 생긴 뒤 ERP 에서 소프트 삭제한 차**였다(전송 누락 아님). ERP `/vehicles/exists` 가 `{exists, missing, deleted}` 로 바뀌어(car-erp dev `33b91d7e`, 스펙 §4-3) board 는 `deleted` 를 `missing_in_erp` 에 **넣지 않고** `counts.deleted_in_erp` + 목록 `deleted_in_erp[]` 로 따로 낸다. missing = **ERP 에 아예 없는 id** 만. ERP 실패 시 deleted 도 **null**(0 아님). 옛 ERP 응답(키 없음)은 0 — 배포 순서 무관.
    - 2026-10-06 배포 직후 heymanboard 실측 = stalled 0 · 정합성 0·0 · missing 0 · **deleted 5**(ERP 295·305·333·356·357, 참고) · errors 0. 종전 「정합성 1건」= 매물 86 `12가0000`(2026-09-10 계좌추출 시험용, super 등록·셀프검차·override 로 상태 왕복 → won 에 erp id 328 만 남고 ERP 는 이미 삭제) → **board 에서 소프트 삭제 처리**(Jin 지시, 감사기록 `delete` 남김). ⚠️ won 행은 ERP 대조 대상(synced 만)이 아니라 deleted 에도 안 잡힌다 — 이런 행은 `erp_id_without_synced` 로만 보인다.
  - **✅ 2026-09-28 두 박스 배포 완료**(master `afd0cef`, ERP 는 `a13e138c`). 첫 실행 결과 heymanboard = ERP 부재 3 + 정합성 1 → **전부 ERP 쪽에서 사람이 소프트 삭제한 차**(동기화는 성공). Jin 결정 = 「보고만」 한 주 관찰, board 무변경(실무자 안내는 Jin).
  - **운영 스케줄러 실측(2026-09-28)**: heymanboard = `/etc/cron.d/board`(www-data, config 캐시로 .env 우회) / ssancarboard = **2026-06-27 배포 이후 schedule:run 이 등록돼 있지 않았다** → `/etc/cron.d/board-ssancar`(ubuntu — .env 600) 신설. heymanboard 매일 03:00 `db:backup` 이 `storage/backups/db` 그룹 쓰기 없음으로 **매일 실패 중이었다** → `chmod g+w` 로 해소(로컬 백업은 배포 시점 것만 남아 있었음).

- **✅ 운영 시간대 UTC → 서울 정정**(2026-09-28 Jin 「서울시간과 맞게」, **두 박스 배포 완료** master `b0ccf22` — 검증: app tz Seoul·세션 +09:00·기존 행 06:19→15:19·schedule 07:40 KST·감사 JSON `+09:00`): `config/app.php` 가 첫 커밋부터 `'UTC'` 하드코딩이라 `.env` 의 `APP_TIMEZONE=Asia/Seoul` 을 읽은 적이 없었다(두 박스 실측, car-erp 는 Seoul). 영향이던 것 = TimeGate 10:00 잠금이 **19:00 KST**(운영 경매 등록 1건뿐이라 실피해 미미) · 스케줄 `dailyAt` 전부 UTC(백업 03:00→정오, 감사 07:40→16:40 KST) · 감사로그·manage 화면 시각 UTC 표시.
  - **방식 = 데이터 무보정.** 시각 컬럼 26개가 전부 MySQL TIMESTAMP(내부 UTC 순간 저장, 세션 시간대로 변환)라 `database.connections.mysql.timezone='+09:00'` 하나로 기존 저장값이 KST 로 읽히고 새 값도 정확히 저장된다(실측: id 40 `06:19`→`15:19`). `useCurrent()`(failed_at) 도 같은 오프셋으로 맞는다. **`lock_at` 은 건드리지 않는다** — DATETIME 이고 `10:00` 은 벽시계 의도값이라 새 체제에서 그대로 10:00 KST 로 읽히는 게 맞다. HMAC `X-Timestamp` 는 unix 초라 무영향(확인). 배포가 `config:cache`+`queue:restart` 를 하므로 캐시·워커 모두 새 시간대를 탄다.
  - 검증(배포 후 두 박스): `config('app.timezone')`=Asia/Seoul · `@@session.time_zone`=+09:00 · id 40 created_at 15:19 · `schedule:list` 07:40 Next Due 가 KST 거리 · 감사 JSON `generated_at` 이 `+09:00`.
  - ℹ️ 로컬(xampp MariaDB) 은 SYSTEM tz 가 이미 KST 라 옛 로컬 행은 다른 방향으로 어긋나 있다 — dev 데이터라 무시.

- **ssancar.com 미디어 — 워터마크 없는 원본을 받는다**(2026-09-17 Jin, 🅿️ **기록만 · 미착수**): 지금 board 가 ssancar.com 에서 받아오는 **사진·영상 둘 다** 워터마크가 박힌 것이다. 추후 **워터마크 없는 원본**을 받도록 바꾼다. 현재 수신 경로 = `app/Services/SsancarMediaService.php`(검차글 영상·사진) + 바이어 전달은 ssancar.com CDN 링크 방식. 착수 시 조율은 **ssancar.com 세션과 직접 통신**(위 협업 규약). 범위·방식(원본 URL 추가 제공인지, 별도 파라미터인지, 바이어 전달분도 원본인지)은 **아직 미정 — Jin 결정 사항**.

- **포털 요약 「내 정산 월별 상세」 = 승인된 ERP 월배치 미러**: board **dev 구현 완료·운영 미배포**(2026-08-31). car-erp 는 **배포 완료**(master `488e597`, `GET /api/internal/board/payout-batches`, 3사 정상 응답). 요약 탭 월 행을 펼치면 그 달 정산 상세 — 배치 묶음(차량행 + 조정 + `net_payout`) + 배치 밖 지급 행 + 이 달 수령액.
  - 🚨 **배치 밖 지급이 예외가 아니라 본류다.** 배치는 2026-07 에 생긴 개념이라 그 전 정산은 속할 배치가 없고 **영원히 배치 밖**이다(과거 데이터 적재분). car-erp 실측 = **ssancarerp paid 3,815건 전량(100%)이 배치 밖·승인 배치 0건**, heymanerp 65%. ⇒ **그 달 수령액 = Σ`net_payout` + Σ`unbatched_paid`**, 화면에서도 배치 밖을 **기본 형태**로 그린다(각주로 다루면 ssancarboard 는 전부가 각주가 된다). 가드 = `BoardTest::test_monthly_payout_renders_when_there_are_no_batches`.
  - `net_payout` 은 **그대로 표시**(ERP `recomputeTotal()` 과 일치 검증됨) — 재계산 금지. 월 합계만 ERP 값을 합산한다.
  - 조정 `reason` 은 **ERP 가 준다**(예: "62두1461 5월 배치 환율오류 과지급 환수"). 본인 차·본인 돈이라 노출이 맞고, 안 보여주면 「−729,250」만 떠서 설명 없이 깎인 걸로 읽힌다. ERP 조정 입력칸에 "영업담당자에게 그대로 보임" 경고가 붙어 있다.
  - ⚠️ **throttle = 분당 120, 이 prefix 의 모든 엔드포인트가 같은 버킷을 공유**(문서의 `300,1` 은 오기 — car-erp 가 정정). 요약 탭은 현재 5개를 부른다(finance·sales·purchases·payout-batches·settlements).
  - ⚠️ 배포하면 **요약의 월 금액이 달라진다** — confirmed(확정·미지급)가 빠지고 조정이 반영된다. 버그가 아니라 수정이지만 미리 알리지 않으면 버그로 읽힌다.
  - **2026-08-31 두 박스 운영 배포 완료**(master `0338ab7` — heymanboard·ssancarboard job success, db:backup ✓, Nothing to migrate). 남음 = **Jin 실화면 확인**(운영 데이터로만 보이는 것 = heymanboard 조정 1건 표시·ssancarboard 배치 0건 렌더). 권위 스펙 = car-erp `docs/integration/board-portal-api.md` §13, 회신 = `C:\Users\User\Desktop\연구소\전달패킷_carerp→board_2026-08-31_월배치미러_배포완료.md`.

- **포털 요약 「진행 중 정산」**(아직 안 받은 것 — ERP 정산처리 탭 본인 몫 미러): **2026-08-31 두 박스 운영 배포 완료**(master `ecdf0c8` — heymanboard·ssancarboard job success, db:backup ✓). 요약 탭 월별 표 **위**에 상태별 섹션(확정·지급 대기 / 산정 중 / 확정 대기)으로 차량·금액. ERP 작업 0 — `GET /settlements` 가 이미 pending·calculating·confirmed·paid 를 전부 준다.
  - 소스 역할 분담: **월별 표 = `/payout-batches`(받은 것)**, **진행 중 = `/settlements`(안 받은 것, `paid` 제외)**. `paid` 를 안 버리면 같은 돈이 두 곳에 뜬다(가드 = `test_in_progress_splits_confirmed_and_pending_and_drops_paid`).
  - ⚠️ **상태는 ERP 가 준 것을 그대로 순회한다(화이트리스트 아님).** ERP KPI 「정산 대기」는 **pending·calculating·confirmed** 를 센다 — board 가 아는 상태만 담으면 KPI 와 어긋나고 진행 중 정산이 조용히 사라진다. 라벨 없는 상태는 원문 표시(가드 = `test_in_progress_keeps_calculating_and_unknown_states`).
  - 🚨 **pending 금액은 확정이 아니다**(비용 9개·환율이 아직 움직인다). 상태를 합치지 않고 총합도 안 낸다 + 「예상」 라벨 + "금액이 바뀔 수 있습니다" 힌트. 이걸 지우면 확정에서 줄었을 때 그대로 분쟁이 된다.
  - ℹ️ ERP 정산 탭은 원래 **영업이 못 보는 화면**(`canAccessSettlement` = 재무·관리·admin). board 로 본인 몫만 여는 것 = 새 노출면이라는 점은 Jin 인지 상태(2026-08-31).
  - **층 2(미구현·2026-08-31 안 하기로)** = ERP 화면의 나머지 컬럼(매입가·입금률·진행상태·정산율·환차, 그리고 **총마진·정산액**).
    ⛔ **board 단독으로는 불가능**(확인 완료) — ① 마진 값이 **어떤 응답에도 안 온다**(§3 차단, board 코드 수신 0건) ② 공식으로 만들어낼 수도 없다: `판매마진 = (정산판매금원화 − 비용9합) − (매입가 + 매도비)` 인데 **정산판매금원화는 입금 시점 실효환율**(`settlement_exchange_rate`)이라 board 의 `/rates`(네이버 전신환)와 다른 값이고, 부가세율·차감율·정산율은 전부 **ERP 기능설정 파라미터**(런타임 변경). 복제하면 급여 숫자가 두 개가 된다.
    ⛔ `board_show_margin` 토글은 **문서에만 있고 car-erp 코드에 없다**(미구현) — 하려면 토글부터 만들어야 한다.
    ℹ️ **2026-10-14 부분 변경 — 마진율(`margin_rate`) 하나만 ERP 가 정산 행에 실어 주기로**(Jin 결정, car-erp `fe21493`). 총마진·정산액 등 나머지 층 2 컬럼은 여전히 안 온다. 노출 = 요약 탭 차량별 표(「완료된 로드맵」 2026-10-14 항목).
    ⚠️ 노출 판단 = **총마진 − 실지급액 = 회사이익**(ERP `SettlementPayoutBatch::profitStats` 공식 그대로). 즉 마진 공개 = 차 단위 회사 이익 공개 → 대표 승인 사안. 토글은 인스턴스별이라 회사별로 따로 열 수 있다.
- ~~**입금요청 알림톡 실발송**~~ **✅ 가동 중**(2026-09-17 car-erp 세션 실측 회신으로 확인 — 종전의 「실발송 0」은 2026-08-11 시점 상태였다). heymanerp 운영 실측 = `alimtalk_logs(erp_board_request)` **sent 95**·skipped 12·failed 4, 마지막 발송 2026-09-16 13:51 / 템플릿 설정·토글 ON·시각 규칙 3행. board_requests 실사용 = `purchase_balance` 37 · `purchase_deposit` 18(구 `purchase_payment` 는 2건, 마지막 08-10). **board 는 알림톡 코드 0**(전부 car-erp 쪽) — 이 줄은 board 판단의 전제라서만 남긴다.

- **알림톡 2종**(지역검차·전달대기, Bizm): 코드 **운영 배포 완료**(master `77738a3`, 2026-07-13, 두 박스). 현재 enabled off 라 실발송 0 — 켜는 순간 가동.
  - **2026-07-27 BizM 1차 반려**("수신 대상 불명확") → **개정 문구 dev 반영 완료**(`AlimtalkTemplates.php` + `docs/operations/alimtalk-templates-draft.md`). 개정점 = `[사내 업무용]` 접두 · "회원님"→"담당자님" · `ssancar.com`/`board`→"사내 업무 시스템(board)". 템플릿코드·명·프로필·카테고리·변수는 불변(재검수라 기존과 매칭돼야 함).
  - 남음 = ① **Jin**: BizM 재검수 요청(+검수팀 회신문) → ② **승인 나면 master 머지·배포**(Jin 지시 2026-07-27 — 승인이 배포 트리거) → ③ tmplId 2개 입력 + enabled on + 스케줄시각.
  - ✅ **2026-10-21 Jin 확인: 재검수 승인 → 등록 완료, 아직 미사용.** 두 박스 Setting 실측(읽기) = tmplId 리터럴(`board_region_inspection`·`board_forward_ready`) 입력됨 · `alimtalk_enabled=0` · 스케줄시각 빈 값 · userid·발신프로필 키 **두 박스 동일 값**(위 「2프로필」 기록과 다름 — 미확인). ⚠️ 검차 큐가 ssancar.com 으로 가면 **A(지역 검차 안내)는 ssancar 배정 알림톡과 겹친다** → A 사용 여부는 Jin 결정 대기. B(전달대기 → 영업)는 board 소유 전이라 무관.
  - ⚠️ **재검수 대상 = 2프로필 × 2템플릿 = 4건**. 코드 `body` 는 하나인데 발신프로필은 heymanboard/ssancarboard 각각 별도 등록이다(`upload_board_헤이맨.xlsx` A열 교체본). **heyman 것만 재승인하면** master 머지가 두 박스에 동시배포되므로 ssancarboard 는 옛 승인문구 ↔ 새 코드 불일치 → enabled on 하는 순간 발송 실패. 설정(tmplId·enabled)도 **인스턴스별로 각각**.
  - ⚠️ 승인 문구가 위 개정본과 **한 글자라도 다르게** 확정되면 코드 `body` 를 그 확정본으로 먼저 맞춘 뒤 배포(불일치 = 발송 실패). [board-alimtalk-region-inspection]
- **§11 요청 거절 씬 + 상단 종 + 웹푸시 + 매입잔금 전액**(2026-10-12 Jin, **board 두 박스 배포 완료 2026-10-13** master `42c92be` — heymanboard·ssancarboard job success · db:backup ✓ 1312.7 KB / 49 KB · 마이그 2개 Ran · `/sw.js` 200 / car-erp dev `508b6b2a`·`2463fd40` 구현 완료·**ERP 배포는 Jin 이 car-erp 세션에서** — board 배포 완료는 2026-10-13 통보함): "ERP 가 확인 말고 **거절(사유)** 도 할 수 있어야 하고, 운전 중인 영업이 그 결과를 알아야 한다." ① ERP 드로어 「입금 확인 | 거절(사유 필수, 차량별)」 → 라인 `rejected` + `reject_reason`·`resolved_at`·`resolved_by`, `GET /requests` 에 `since=` ② board 칩 `rejected` 빨간 「거절」+사유 원문, **모르는 상태는 원문** ③ **상단 종**(상단바 우측, 모바일은 상단바 아래 전체 너비 시트) = 결과(확인·거절)·진행 중, 미읽음 배지 = `users.portal_seen_at` ④ **웹푸시** = `board:push-request-results` 매분 + `public/sw.js` + 종 패널 「휴대폰 알림 켜기」, 커서 = `users.push_notified_at` ⑤ [매입잔금] 금액칸 비우면 ERP `purchase_unpaid`(매도비 포함) 전액, confirm 으로 금액 표시(별도 버튼 없음 — Jin). 상세·함정 = `SKILLS.md §14-2·§14-19`.
  - 🚦 **배포 순서 = board 먼저, ERP 다음**(칩이 `rejected` 를 모르면 거절이 「요청중」으로 보인다). board 배포 뒤 car-erp 세션에 알려야 한다(그쪽이 Jin 「배포해줘」 대기). 마이그 2개(`portal_seen_at`·`push_subscriptions`+`push_notified_at`) — 자동배포 `migrate --force` 가 돈다.
  - 🔑 **웹푸시 VAPID = 두 박스 `.env` 투입 완료(2026-10-13 Jin 지시)** — 각 박스에서 `board:push-vapid-keys` 로 생성(박스마다 다른 키), `BOARD_VAPID_SUBJECT` 는 그 박스 URL(heymanboard `https://board.heysellcar.com` / ssancarboard `https://board.heymancar.com`). `config:cache`(ubuntu)·`queue:restart` 완료, 박스 `.env.bak-vapid-20261013` + Desktop\AWS 백업 동기화(`*.env.txt.bak-20261013`). 첫 수동 실행 = 「발송 0건 (사용자 0명)」(구독자 아직 없음 — 정상). 키를 바꾸면 기존 구독 전부 무효. 아이폰은 홈 화면 앱에서만. 로컬 `.env` 엔 테스트 키(`.env.bak-push-20261012` 백업).
  - ✅ **ERP 거절 씬도 배포 완료**(car-erp master `46627df1`, 2026-10-13, 3사 success). heymanboard 에서 운영 실호출 확인(2026-10-13): `GET /requests?status=all&since=` 200, 라인 키에 `resolved_at·resolved_by·reject_reason` 실림, `since_invalid` 없음. 영업 1명은 90일 라인 77건 — 종 폴링은 `since=열람시각` 이라 훨씬 좁다. 매핑 없는 계정(관리 등)은 종전처럼 403(포털과 동일).
  - 남음 = ① 영업 각자 종 패널에서 「휴대폰 알림 켜기」 ② 실거래 첫 거절 1건으로 칩·종·푸시 도착 확인(안 뜨면 batch_id 와 함께 car-erp 세션에).
- **판매계약서 전자서명 요청**: board **운영 배포 완료**(master `f807ed7`, 2026-07-11) + car-erp §10/§10-2 엔드포인트도 배포됨. 남음 = **실거래 e2e 검증**(발급→전달→바이어서명→증거메일). [board-esignature-request]
  - **2026-10-12 점검(Jin 「문서 받기·서명 문제 없나」)** → 4건 수정, **두 박스 배포 완료**(master `046b4af`, heymanboard·ssancarboard job success · db:backup ✓ · Nothing to migrate): ① 서명 422 가 전부 「동일 바이어」로 오안내(ERP 서명 422 는 한글 lang 문구라 영문 매칭 불발) → **ERP 사유 원문 그대로** ② 서명 상태 폴링을 requested·in_progress 묶음만(heymanerp 영업 1명 묶음 44개 = 탭 1회 46호출, throttle 120/분 공유) + done 묶음 서명 버튼 제거 ③ 계획 그룹(batch_id 없음)도 차 담는 순간 상태 조회(미서명 링크 무효화 방지) ④ 발급 성공 즉시 「서명 대기」 심기(URL 패널 옆 재클릭 revoke 방지). 상세·함정 = `SKILLS.md §14-11`. ℹ️ 「서류 403 `Export only` 오매핑」은 **철회** — car-erp `sales_channel` 이 `ENUM('export')` 단일값(마이그 `2026_05_14_000002`)이라 발생 불가. 운영 실측 = heymanerp 서명 세션 **revoked 1·signed 0**, ssancarerp 서명 0·board 서류 다운로드 **0건**(미사용인지 실패인지 미확인 — ssancarboard 로그 확인 남음).
- **차감액 외화 입력**: `sale_discount_amount` 는 **KRW 절대금액 고정**(통화 컬럼 없음 — 마이그 `2026_07_06_133348` 단독). 바이어 통화로 차감액을 넣는 건 미착수. Model A 후속 ③. [board-flow-model-a-deployed]
- ~~**board 앱 챗봇 통합**~~ **✅ 2026-08-03 운영 가동**(master `be425b5`, 두 박스). 로컬 LLM 업무 Q&A **A단계(업무가이드 RAG)만** 이식 — B(미수·채권·자금)는 그 데이터가 car-erp 원장에 있어 board 엔 해당 없음.
  - 열람 = **영업(`sales`)·관리(`manager`)·super**(`User::canUseAssistant`). 노출 2단 = `.env ASSISTANT_ENABLED`(인프라) + 기능설정 `Setting assistant_enabled`(운영 토글).
  - **색인 청크 등급(`audience`) 필터는 도입 안 함** — 볼 수 있는 사람이 곧 전체 가이드를 본다(car-erp 에서 가장 조용한 사고 지점이라 board 는 아예 안 들고 옴).
  - 질의는 `board_audit_logs`(`action=assistant_query`, `purchase_listing_id` null)에 남기되 **`/audit` 변경이력 표에서는 제외** — 매물 변경이 아니라 표 형식이 안 맞고 실제 이력을 밀어낸다.
  - **색인 배포 = scp**(git ❌ — 매일 갱신이 매일 운영 2대 자동배포가 됨). 회사 GPU PC 03:00 `sync.php` → `sync-and-push.ps1` 이 car-erp 3사 + **board 2사**로 push. 경로 = `/var/www/board/storage/app/index-board.json`(heymanboard) · `/var/www/board-ssancar/...`(ssancarboard, **경로 다름**). 인계 = `meetings/handoff-assistant-index-push.md`.
  - scp 실패는 저쪽에서 조용히 넘어가므로 **`board:assistant-health`(매시)** 가 색인 mtime + Ollama 응답을 감시.
  - Notion 카드 = `scripts/notion-cards/publish.php`(38장, 원본 = `cards.json`). **car-erp 와 같은 흐름**: `--verify`(대조, 읽기전용) → `--card "제목" --apply`(제자리 교체 또는 그룹 끝 추가) → 다시 `--verify`. `--replace`(그룹째 재발행)는 **그룹 구성이 바뀔 때만**. car-erp 와 유일한 차이 = **`audience` 없음**. 발행은 **Codex 위임** — 절차 정본 = `C:\xampp\htdocs\CODEX_NOTION_HANDOFF.md` **§9-A2**. 카드 고칠 일 = `cards.json` 수정 → Codex 발행 → **익일 03:00** 색인 반영.
  - e2e 실측(2026-08-03): heymanboard 4.8초 · ssancarboard 5.1초, 출처 표기 정상. 대상 인원 = heymanboard 8명(sales 5·manager 3) / ssancarboard 1명. [local-llm-chatbot-poc]
- **원부조회(압류/저당/구조) board 확장**: 🅿️ **보류**(2026-07-26 Jin). ⛔ **board 사용자 개인 carmodoo 계정을 ERP 경유 우리 고정 IP 로 프록시하는 구현 금지** — "여러 계정이 한 IP" = 조합 IP제한 시스템의 계정도용/봇 패턴 → 그 IP 차단 시 **3사 ERP 원부조회 동반 사망**. 재개 시 방향은 (A) 우리 단일계정·우리 IP 로만, 또는 (B) 사용자가 자기 앱·자기 IP 로 조회하고 board 엔 결과만 담기 — **설계·인계는 car-erp 세션**. 상세 = `meetings/handoff-from-carerp-2026-07-26.md`.
  - 🛑 **2026-09-11 재검토 → 「안 한다」로 확정**(Jin). 매입예정 추가 화면 차량번호 옆 [원부조회] 버튼 안이었고, heymanboard 5명 + ssancarboard 30명 = 35명 기준으로 검토했다. **부하가 아니라 구조가 문제**라 상한·캐시로 풀리지 않는다:
    - 🚨 **board 의 조회 실패 한 번이 3사 ERP 조회를 끊는다** — carmodoo 로그인 세션은 3사 공유 캐시인데, 조회가 null 이면(타임아웃 포함) **그 공유 세션을 지우고 즉시 재로그인**한다. carmodoo 는 짧은 간격 재로그인을 거부한다 ⇒ 한 사람의 타임아웃이 그 순간 ERP 사용자까지 실패시킨다. **사용자가 적어도 일어난다**(부하 무관).
    - carmodoo 는 **동시 상한 없음 + 결과 캐시 없음**(클릭 = 실호출). NICE 의 세마포어(동시 4·429)에 해당하는 보호가 전무하다. ERP 문서에도 "board 확장 시 필요"로 **미완 표기**.
    - **일/월 한도 미확인** — 한도에 부딪히면 ERP 가 먼저 멈춘다. 재개하려면 **이 숫자 확인이 1순위**.
    - 압류·저당·구조를 주는 건 **carmodoo 하나뿐**(NICE 는 제원·소유자·VIN). 붙일 대상은 보호장치가 없는 쪽이다.
  - ℹ️ board 확장과 **무관하게 남는 car-erp 결함 2건**(이번 조사 부수 발견, car-erp 세션 소관): ① 위 세션 무효화 로직(지금도 3사끼리 서로 세션을 날린다) ② `POST /provide/api/nice-lookup` 에 **인증·CSRF·throttle 전무**(호출측이 보내는 `X-SSANCAR-API-KEY` 를 수신측이 안 읽는다) — 건당 과금 조회와 동시 슬롯 4개가 공개 주소 뒤에 있다. + NICE 크레덴셜 실값이 car-erp `docs/operations/nice-gateway-migration.md` 에 평문 커밋(dev).
- ~~**ssancar 자동전달대기 전이 불발**~~ **✅ 2026-07-28 해소**(경위·검증 = `meetings/handoff-ssancar-media-merge.md`): ①ssancar 검차글이 차당 2개(영상글+사진전용글)인데 교차매칭이 `matched:1` 로 사진전용글을 물어 `videos:[]` → 전이 안 됐음. **ssancar 가 `ss_media_inspected()` 합산 배포**(4글=최신검차만/동일장수쌍=합산) → 쌍 케이스도 영상 8·사진 70 정상 수신 확인, **board 코드 변경 0**. ⭐**실거래 e2e 완료**(2026-07-28 heymanboard, 테스터 2명 모바일): 엔카링크 등록 → **59초 후 자동 전달대기** → 연동 B `purchase_sync` 201 → `synced`(erp_id 246·249). 쌍 케이스(02다3749=1038/1039)도 완주. ⚠️`?url=` 은 ssancar 페이지 URL 전용(엔카 URL 은 ok=0) — board 링크모드 우회는 불가. ②별개 경로 — `SsancarMediaService` `Http::timeout(4)` 인데 콜드 응답 4.8초 실측 → 빈 배열 → 조용히 전이 안 함. **✅ 해결: 폴러 전용 타임아웃 분리**(dev `4e0c598`, `BOARD_SSANCAR_POLL_TIMEOUT` 기본 15초 / 바이어페이지는 4초 유지) — **미배포, master 머지 대기**. [board-ssancar-inspected-duplicate-entries]
- ~~**지역명 정합성 + 지역 자동배정**~~ **✅ 2026-08-01 운영 배포 완료**(master `01d2d8d`, 두 박스).
  - 문제 = 크롤링이 만든 축약형("안산")과 사람이 고른 정식형("경기 안산시")이 영영 안 만나 **배정·알림톡이 조용히 빗나감**(세 테이블 region 은 문자열 그대로 조인).
  - 해결 = **canonical = 풀네임**(축약으로 통일하면 `광주광역시`↔`경기 광주시` 가 뭉개짐 — 도 정보 손실). `App\Support\Region` 단일 경로 + 세 모델 mutator(`NormalizesRegion`)로 **저장 시점** 정규화(입력 화면 5곳·크롤링 1곳 누락 방지). `config('board.regions')` = 전국 시·군.
  - 검차화면 = 그날 배정 없으면 **`users.region` 로스터 폴백**(있으면 덮어쓰기 — `RegionInspectionNotifier::recipientsFor` 와 동일 규칙 ⇒ "알림톡 받은 사람 = 화면에 보이는 사람"). 배정 요약표는 상시 담당을 별도 배지로 계상.
  - 모바일 자동완성 = `<datalist>`(모바일 미지원) → Alpine `x-region-input` 컴포넌트로 5곳 교체.
  - **기존 데이터 백필은 안 하기로 결정**(2026-08-01 Jin) — 실사용은 신규 등록 위주라 새로 들어오는 값은 이미 정식형이고, **기존 차량도 드로어에서 한 번 저장하면 mutator 가 자동 정규화**한다(`부산`→`부산광역시`). 명령어 `php artisan board:region-normalize` 는 남겨둠 — 나중에 필요하면 **박스별로 각각**(DB 분리) dry-run 후 `--apply`. dry-run 이 애매값(광주·고성)과 **알림톡 기발송 건수**(정규화해도 재발송 안 됨)를 보고.
  - ⚠️ 알려진 엣지(기존 동작, 이번에 안 건드림): 배정 select 후보(`pendingRegions`)는 **차량이 있는 지역만** → 로스터로만 커버되는 지역은 요약표엔 뜨지만 그 지역으로 per-date 배정을 걸 수 없다.
- **선적요청 서류 다운로드**: ✅ **2026-08-01 배포 완료**(master `331a524`·`0c0d1a4`) — 판매계약서·**프로포마 인보이스**(car-erp 타입명 = **`invoice`**, `proforma_invoice` 아님) 추가.
  - 경위 = board 는 `sales_contract` 를 허용목록에 넣어뒀는데 car-erp `BOARD_ALLOWED_TYPES` 엔 **애초에 없어서** 계속 403 이었다(board 주석이 ERP 자체화면 변경을 프록시 추가로 오독). 게다가 board 가 실패를 전부 "동일 바이어" 로 안내해 원인을 잘못 짚게 했다. → car-erp 가 §29 PII 재검토 후 `sales_contract`·`invoice` 개방(master `4d3959e`), board 는 403/422 분기 + 타입 추가. 인계 = `meetings/handoff-carerp-portal-documents.md`.
  - ⚠️ **리터럴 타입** = `sales_contract`·`invoice`(method 접두 금지 — 붙이면 화이트리스트 밖 이름이라 403). 선적 4종만 `roro_`/`container_` 접두.
  - ⚠️ 서류 **버튼 이름은 car-erp `vehicle.shipdoc.*` 그대로** 쓴다(Jin 2026-08-01). board 에서 새로 지으면 "ERP엔 그런 서류 없다" 가 된다 — 이름 핀 테스트가 지킨다.
  - 남은 확인(car-erp 제기) = **ERP 로그인 계정 없이 salesmen 명부에만 있는 영업**도 board 로 여권 든 서류를 받게 된다. 실재 여부는 운영 DB 2개 대조 필요(쿼리는 인계문서에).
- **검차 시스템 재설계 — 검차 큐는 ssancar.com 에**(2026-10-21 Jin 결정, 🅿️ **기획 단계 · 양쪽 코드 0**, ssancar.com 세션 주도 — 초안 = ssancar 레포 `_ops/dev/INSPECTION_PLAN.md`): Jin 요구 = 검차원이 리스트 보고 검차 → 완료 실시간 보고 → 지역 편차 시 일찍 끝난 팀이 지원 → 전부 끝나면 팀장 알림. 배경 실측(2026-10-21) = **board 검차원 씬은 운영 미사용**(두 박스 role=inspection 0명·배정 1건(6월 시험)·알림톡 A off) — 실제 검차는 ssancar.com 업로드 → 폴러 경로뿐, 두 board 가 같은 www.ssancar.com 을 본다.
  - 결정(Jin): ① **검차 작업 큐 = ssancar.com · 매입 딜 = board · 키로 연결**(board 가 draft 차를 큐에 push, 진행·완료는 ssancar → board webhook, 2분 폴러는 백업으로 존치, inspected 전이 판정은 board 소유) ② **지역 단위** 배정 ③ **검차 대상 = 신규 매물만**(재고차는 이미 검차됨) ④ 팀장 = board 에서 하면 **관리 role**, ssancar.com 쪽은 별도 검토(미결) ⑤ 지원 = 알림톡 + 팀장 수동 지시로 충분(추후 발전 가능).
  - board 가 낸 의견(결정 아님): 테넌트 식별 = `heymanboard`/`ssancarboard`(config `instances` 에 매핑, .env 무변경) + **인스턴스별 시크릿으로 인증**(이름만 신뢰 금지) · 멱등키 = (tenant, listing id) · 「검차중」은 상태머신에 넣지 말고 칸(예: `inspection_started_at`)으로 · 난이도 = 인바운드 webhook 반나절~1일(respond 웹훅 틀), 큐 push Job 1~2일 — 큰 비용은 스펙 합의·인스턴스별 시크릿.
  - **스펙 v1 = 세션 간 합의(제안 상태, 2026-10-21)** — 정본 = ssancar 레포 `_ops/dev/INSPECTION_PLAN.md` §8(Jin 승인 후 ssancar 가 커밋). board 쪽 요점: 큐 등록/철회는 **판정식 하나**(`status=draft AND origin≠self_inspection AND 미삭제`)를 `PurchaseListing::booted()` created·updated·deleted 에서 비교해 **Job 비동기** 발송(연동 B 틀) · withdraw reason 4종(deleted·not_inspection_target·closed_in_board·status_override) · board 칸 3개 신설(`inspection_started_at`·`ssancar_inspector_name`·`ssancar_inspected_wr_id` — 🚫 `ssancar_ref` 겸용 금지: 미디어 조회 모드를 정하는 칸) · 인바운드 `POST /api/webhooks/ssancar-inspection`(X-Webhook-Secret, event_id 멱등) · 시크릿 = 인스턴스별·방향별 별개, **Jin 이 직접 투입** · 지역 문자열 = board 정규화 값 그대로 · 일일 대사 `queue.reconcile`(빈 배열 무시·대량 철회 보류 안전장치, 응답 `missing_listing_ids[]` 로 board 가 재발송) · ⚠️ `saveQuietly()` 는 판정식을 우회한다(현재 5곳은 계좌추출 칸뿐이라 무해).
  - **검차 패스(`skipped`) 추가**(2026-10-21 Jin 목업 피드백, 제안 반영됨): 검차원이 검차 없이 넘기는 경우(차 상태 불량·차 없음/연락 불가·영업 요청·기타) → ssancar 이벤트 `inspection.skipped`{event_id, listing_id, plate, at, reason, memo?, photo_url?, inspector}. board 는 **draft 유지 + 「검차상태」 칸에 「검차 보류 · 사유」만**(`ssancarHoldHint()` 에서 폴러 문구보다 먼저) · 칸 4개 추가(`inspection_skipped_at`·`inspection_skip_reason`·`inspection_skip_memo`·`inspection_skip_photo_url`, 사유는 board lang·모르는 코드는 원문) · reconcile 은 skipped 를 「있음」으로 셈(재발송 방지). 재검차 경로 = **둘 다**(2026-10-21 Jin 결정, ssancar 세션 경유) — board 「재검차 요청」 버튼 → `queue.upsert(reason=reinspect)` 재진입 + ssancar 「되돌리기」 → 이벤트 `inspection.reopened` 수신 시 board 패스 칸 비움. ⚠️ 「추가검사사항」(`inspection_note`)은 **검차원·관리자가 적는 검차 결과 메모**다(입력 = `/inspection` 드로어·`/manage` 드로어만, 매입예정은 목록 읽기 전용) — **영업이 검차원에게 추가 검사를 요청하는 칸은 없다**(2026-10-21 Jin 지적으로 정정, 신설 여부 Jin 결정 대기).
  - ✅ **2026-10-28 운영 e2e 1회 완주**(Jin, ssancarboard 매물 37 `162수2581`, ssancar.com 세션 실측): upsert 14:47:26 → ssancar 배정·알림톡 14:54 → 검차앱 started 웹훅 → 검차글 등록 14:56(사진 5·영상 1, completed 웹훅 2xx) → board 폴러 15:10:03 영상 감지 → inspected → `queue.withdraw`(closed_in_board) 15:10:49 200 → /forwarding → /verdicts → /auction won → ERP synced. 양쪽 차량은 테스트라 삭제. heymanboard 매물 158 은 패스(skipped 웹훅 2xx). **board↔ssancar 검차 큐 연동 운영 확인**(ssancar 로그 §40). 📌 발견: 폴러는 2분이지만 **미디어 응답 캐시 10분**(`SsancarMediaService::request()`, 「영상 없음」도 캐시)이라 영상 업로드 직전에 조회가 떨어지면 전이가 최대 ~12분 지연(이번 14:58:03 조회가 영상 완료 1초 전). → **A안 배포(2026-10-28 `214d4da`)**: 영상 대기 응답만 2분 캐시, 나머지 10분.
  - ✅ **2026-10-26 두 박스 배포 완료**(master `5921ec5` — heymanboard·ssancarboard success, db:backup ✓ 1424.9 KB / 165 KB, 마이그 `2026_10_23_000001`·`2026_10_24_000001`·`2026_10_24_000002` DONE). 운영 시크릿 4키 두 박스 `.env` 투입(Jin 지시, 백업 `.env.bak-inspect-20261026` + Desktop\AWS 동기화). 운영 확인 = configured·웹훅 401·대사 07:20. 배포 시점 검차 대상 draft 0대 → 첫 실제 등록 때 첫 upsert. 같이 나간 것 = 사용자 검색·매입예정 바이어(ERP 이름 스냅샷)·감사(첨부·IP·숫자 소음·매도비 계좌)·보류/지역 미지정 강조·보류 종/웹푸시·목록 칸 분리. 상세 = `SKILLS.md §14-25·§14-26`.
- **검차 스케줄 자동화 P1 — board 몫(2026-10-29 Jin 승인, 🅿️ board 코드 0 · 주말 건과 묶어 착수)**: ssancar.com 이 접수 마감 07:00·확정 뒤 끼워넣기 금지·동 단위 동선을 만든다(ssancar dev `ae0f5beb`, 정본 = ssancar `_ops/dev/INSPECTION_PLAN.md` §10-10). Jin 결정 = ① board 착수 OK ② **`address` 는 board 가 보관·발신하지 않는다**(엔카 차는 upsert 의 `source_url` carId 로 ssancar 가 수집, 링크 없는 차는 `region` 만 — board 에 주소 컬럼 없음 실측: `ListingEnrichment.php:143` 이 `contact.address` 를 시 단위로 접어 region 에만 저장) ③ **긴급 승인·기각 = ssancar.com 업무관리자만**, board 는 보내기만(승인 UI 없음). board 구현 범위 = upsert 선택 필드 `urgent`(bool)·`urgent_note`(≤255) 송신(드로어 「긴급 요청」 버튼; 재요청은 ssancar 가 pending/approved 면 무시·rejected 면 다시 pending) · 응답 `work_date`·`urgent_decision` 과 started/completed/skipped/reopened 웹훅의 `work_date`(`at` 다음) 저장 → 목록 「검차 예정일」 · 신규 웹훅 `inspection.urgent_decided` {event, event_id, listing_id, plate, at, work_date, approved, by:{mb_id,name}, note} 수신 → 칸 갱신 + 종·웹푸시(서명·멱등은 기존 웹훅과 동일). work_date 규칙(ssancar 소유) = 07:00 전 접수·미잠금 = 오늘, 그 뒤·「전체 확정」 뒤 = 다음 **영업일**(주말·공휴일 검차 없음 — 금 07:00 뒤 접수 = 월, Jin 2026-10-29 낮 추가 결정), 미완 차는 07:00 이월. 긴급 승인 시 검차원 알림톡은 ssancar 가 보내고 board 는 `urgent_decided` 수신으로 영업에게만. 🚦 **board 수신 준비 뒤에 ssancar 가 긴급 승인을 켠다**(아니면 아웃박스 5회 실패). ERP 무관. ssancar 배포 = P1~P5 다 만들고 한 번에(Jin) → **board 몫도 그때 같이** — 아직 board 코드 0(주말 4건을 먼저 했다), ssancar P2 진행 중인 지금 착수하면 된다.
- **§11 요청(계약금·매입잔금) 취소 X 버튼**: 🅿️ **보류**(2026-10-20 Jin). 기술 장벽 없음 — ERP `POST /requests/{batch_id}/cancel` 이 이미 살아 있고(car-erp `routes/api.php:77`, open 라인만 cancelled + 종 알람 resolve) board 는 `chipMap` 에 `batch_id` 한 칸 + X·확인 모달이면 된다(반나절). 보류 이유 = **이미 나간 알림톡은 못 거둔다**(ERP cancel 은 취소 알림톡을 안 보냄) → 취소는 받은 사람에게 **직접 말하는 것**으로 운영. 재개하려면 ERP 취소 알림톡 여부부터 결정. 당초 안 만든 경위 = `meetings/handoff-carerp-board-requests.md:90`(§11-3 선택 구현·§11-4 여섯 항목 밖).
- (저우선) TimeGate **관리자 전역 해제 UI**(현재 등록잠금만; 편집은 이미 우회) · 퇴사자 계정 양쪽 동시정지 절차 **문서화**.

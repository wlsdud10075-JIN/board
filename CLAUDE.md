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
> - ⚠️ **협업 = 인계 문서 필수**: Claude 세션끼리는 실시간 통신 채널이 없다(컨텍스트·메모리 격리). board↔car-erp 협업이 필요하면 **반드시 정리(인계) 문서를 `meetings/handoff-*.md` 로 만들어** 사용자가 상대 세션에 전달하게 한다 — "내가 직접 상대 레포를 건드리겠다"는 금지(규칙 위반 + cwd/DB 사고 위험). 예시: `meetings/handoff-car-erp-purchase-sync.md`(연동 B 수신측 인계).

## 위치/환경
- **경로**: `C:/xampp/htdocs/board` (car-erp 와 형제 디렉터리)
- **프레임워크**: Laravel 12 + Livewire 4 (Volt 1.6 단일파일) + Flux UI 2 + Tailwind v4 + Alpine
- **DB**: MySQL/MariaDB **`board`** (전용 user `board_user`, **car_erp 접근 권한 0** — 비밀번호는 `.env`)
- **포트**: 개발 서버 `8003` — ⚠️ **`APP_URL`(:8003)과 반드시 일치**시켜 serve(불일치 시 Livewire 액션 전부 죽음; 이 PC 8002 는 다른 앱). car-erp 8001 과 분리.
- **타임존**: `APP_TIMEZONE=Asia/Seoul` (TimeGate 서버판정 근거)
- **APP_KEY**: car-erp 와 **분리**. board 는 RRN·개인정보 미보유(분리 정당성).
- **GitHub**: `https://github.com/wlsdud10075-JIN/board.git` — `dev`(작업) + `master`(production). 로컬 기본 = dev.

### ⚠️ cwd 사고 주의 (실측 발생)
board 와 car-erp 는 형제 디렉터리 + **별도 DB**다. artisan/tinker 실행 전 **반드시 `cd /c/xampp/htdocs/board` 명시**. cwd 가 car-erp 에 남은 채 `php artisan migrate` 하면 car-erp DB 에서 돌고 board 데이터가 car-erp 에 잘못 생성된다(2026-06-09 실제 발생, 정리 완료). tinker 에서 `\DB::connection()->getDatabaseName()` 로 대상 DB 확인하는 습관.

## 권한 시스템 (car-erp 미러 — permission 2단 + role)

**permission** (`users.permission`):
- `super` 시스템관리자 — role 무관 전체 접근 + **기능설정** + **감사로그** + 사용자관리 중 **super 지정·super 계정 수정**. car-erp super 대응.
- `user` 일반 — `role` 기반 접근.

**role** (`users.role`): `sales`(영업) / `inspection`(현지확인) / `auction`(경매) / `manager`(관리). 라벨은 `User::ROLE_LABELS`.

**미들웨어**:
| alias | 클래스 | 규칙 |
|---|---|---|
| `role:a,b` | `EnsureRole` | super 는 무조건 통과(바이패스) / 아니면 role∈{a,b} / 비활성(is_active=false) 차단 |
| `super` | `EnsureSuper` | super 전용 (관리 role 도 차단). `/audit`·`/admin/settings` 보호 |

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
| `/audit` | audit | **super 전용** — 감사로그(변경이력 board_audit_logs + car-erp 전송 integration_events) |
| `/dashboard` | dashboard | 로그인 후 role(또는 super)별 홈으로 redirect |

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
1. **listings**(영업): 매입예정 추가(출처 토글·TimeGate 가드, 차량번호/소유자/차값/할인 가로 grid) + 본인 글 행클릭 편집 드로어.
2. **inspection**(현지확인): 지역별 그룹 + 모바일 드로어(사진/영상 업로드·메모·최종금액). **전달/회신 = "선택 후 저장" 수동씬**(클릭=색강조만, 하단 저장이 상태전이 커밋).
3. **auction**(경매/구매): accepted 차량 낙찰/유찰·구매확정/취소(→ won/failed) + 소유자·입금정보. won → 연동 B 자동 push.
4. **manage**(관리자): KPI 5종(**클릭=그 차원 필터 토글**) + **필터(검색·상태·출처·회신, 가로 grid) + 페이지네이션(20)** 전체현황 + **무제한 수정 드로어(어지간한 필드 전부 — 식별값은 미연동만)**. 모든 변경은 옵저버가 감사기록.
5. **users**(관리·super): 계정 생성·역할·활성토글·**car-erp 영업 이메일 매핑**. **시스템관리자 지정 체크박스와 super 계정 행은 super 에게만** 노출·허용(서버 가드 = `save`/`openEdit`/`toggleActive`).
6. **audit**(super): 감사로그 — 변경이력(board_audit_logs, 상태/회신/출처 한글표시) + car-erp 전송로그(integration_events payload). 페이지네이션. /manage 에서 분리.
7. **forwarding**(영업): 검차완료(inspected) 차 견적 금액 입력 → 바이어 전달(awaiting_buyer).
8. **verdicts**(영업): 회신대기 차 수락/거절 처리.
9. **portal**(영업·관리): car-erp 읽기 미러 — 탭 = `요약`·`미수금`·**`재고`**(지급대기/일반재고/선적전/출고완료)·`판매내역`·`정산내역`·`선적요청`. §11 신호 버튼([입금요청]·[판매대금확인])이 여기 있다. 운항 칩(🚢운항중/⚓도착예정)은 **진행상태와 직교하는 별개 축**(`SKILLS.md §14-4`). 상세 = `SKILLS.md §14`.

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
- **셀프검차매입**(2026-08-09 배포, master `03f45fb`): 영업이 직접 검차한 차 — ssancar 검차글에 영상이 없어 자동전이가 안 걸리고 갇히던 경로. 등록 즉시 `accepted` 로 만들어 `/auction` 에서 마무리. 상세 = 위 「출처」 절.
- **§11 요청·확인 신호 + 재고 4분류**(2026-08-09 배포, master `b0f875a`): 카톡으로 하던 "입금해주세요/대금 확인해주세요" 두 마디를 포털로. 같이 **`매입내역` 탭을 `재고` 4분류로 교체**(전량조회 → 유한 집합). 상세·함정 = `SKILLS.md §14-2·§14-3`, 인계 = `meetings/handoff-carerp-board-requests.md`·`handoff-carerp-inventory-for-board.md`.

- **운항 상태(🚢운항중/⚓도착예정)**(2026-08-10 배포): 진행상태와 **직교하는 축** — 포털 판매내역·재고에 칩, 판매내역엔 서버측 운항 필터. 「도착예정」은 ETA 경과이지 **입항 확인이 아니다**(문구 고정). 상세 = `SKILLS.md §14-4`.
- **셀프검차매입 금액 입력·락**(2026-08-10 배포, 마이그 `selling_fee`·`sale_price`·`transport_fee`): 그 경로는 검차·견적 씬을 건너뛰어 금액을 넣을 곳이 없었고 **won 이 조용히 422** 로 죽었다. `/auction` 드로어에 **출처별로 다른 금액칸**(셀프검차 = 차값·매도비[KRW] + 판매가·환율·운임비[견적통화], **자동계산 없음**) + **필수 락 4개**(차값·판매가·통화·환율). 매도비는 셀프검차에서만 차값에서 뺀다. 상세 = `SKILLS.md §14-5`.
- **포털 차량 메타**(2026-08-10 배포): 차량번호 옆에 차대번호·브랜드/차종(미수금·재고·판매내역·선적요청 4탭. 정산내역은 차량 행 없음). 값은 전부 car-erp 가 준다 — 없으면 안 그린다. 상세 = `SKILLS.md §14-6`.
- **구매확정 첨부 필수 + 저장순서 · 모바일 사이드바 재발 수정**(2026-08-18 배포, master `75cb242`): 첨부 0건이면 **확정 자체를 막는다**(연동 B 는 won 시 1회 발사라 "전송만 보류"하면 재전송 안 누른 차가 영영 ERP 에 없다). 🚨 같이 발견 — 첨부가 status 저장 **뒤에** 저장돼 **그 자리에서 올린 파일이 payload 에서 빠지고 있었다**(로컬 sync 큐는 항상, 운영은 타이밍 의존). 사이드바는 가드가 인스턴스 로컬이라 `wire:navigate` 누적 시 무효 → **window 전역**으로. 상세 = `SKILLS.md §14-13·§14-14`.
- **판매가 나중 채우기 + 선적계획 서류·서명 + 422 사유 분기**(2026-08-18 배포, master `a8403a1` ↔ car-erp fill-if-empty): 급해서 매입가만 보낸 차의 **판매가·통화·환율을 `/listings` 드로어에서 채워 재전송**(ERP 가 빈 칸만 채움, `fields_filled` 로 확인). 판매계약서·프로포마·서명을 **선적 계획에서도**. 서류 422 를 **원문으로 갈라** 안내(전부 "동일 바이어"로 뭉뚱그리던 것). 상세 = `SKILLS.md §14-11·§14-12`.
- **시스템관리자 타인 포털 대리실행**(2026-08-18 배포, master `3a3a1c9`): super 가 남의 포털에서 쓰기가 전부 막혀 있었고 **선적 계획은 화면 자체가 안 그려졌다**. 차단 8곳 제거 + 배너에 **"요청은 OO님 명의로 전달됩니다"**(그게 유일한 안전장치). ⚠️ `isViewingOther()` 는 super 일 때만 true — 그 차단은 처음부터 super 만 겨냥한 것이었다. 상세 = `SKILLS.md §14-10`.
- **매입예정 드로어에서 딜러 첨부 보기**(2026-08-12 배포, master `88784c6`): 올리는 곳은 `/auction` 하나뿐인데 그 화면은 accepted·won 만 다뤄 **synced 가 되면 board 어디서도 못 봤다**. 매입예정이 본인 차를 전 상태로 여는 유일한 화면이라 거기서 읽기 전용으로. 상세 = `SKILLS.md §14-9`.
- **재고 검색에 바이어**(2026-08-12 배포, master `20a2b84` ↔ car-erp `40b5c4b`): ERP 가 훑는 칸에 바이어명 추가(board 는 문구만). ⚠️ 검색 대상은 **8가지**(차량번호·브랜드·차종·차대번호·수출신고번호·선박명·컨테이너번호·바이어) — placeholder 를 실제보다 좁게 쓰지 말 것. 상세 = `SKILLS.md §14-2`.
- **선적 계획 — 미완납 후보 + 포워딩사 + 컨테이너 운임비**(2026-08-12 배포, master `b6c0105` ↔ car-erp master `94a59c3`): 돈 들어오기 전에 **미리 묶어 서류를 준비**하는 화면이 됐다(후보 조건 `판매완료` → `sale_price>0`). 묶음에 **포워딩사**(ERP 명부에서 선택, `vehicles.forwarding_company_id` 반영) + **컨테이너 운임비 USD**(ERP가 1/N로 `transport_fee_usd` 기록). ⚠️ `unpaid_krw=null`은 완납이 아니라 환율 미입력·판정 불가 / 출고일 찍힌 차도 후보에 온다 / 1/N 합계는 총액과 안 맞을 수 있다 = `SKILLS.md §14-8`. 인계 = `meetings/handoff-carerp-shippable-scope.md`.
- **입금요청 분리 — 계약금/매입잔금 + 금액**(2026-08-11 배포, master `c2b1e28` ↔ car-erp master `bb5af1a`): 받는 사람이 **얼마를 보낼지 몰라** 결국 카톡으로 되물었다 = 신호가 일을 못 끝냈다. `/portal` 재고 행이 [입금요청] 1버튼 → **금액칸 + [계약금] [매입잔금]**. ⚠️ 별개 `type` 이어야 하는 이유(ERP 멱등키)·금액 차량 키잉·자동계산 금지 = `SKILLS.md §14-2`. 알림톡은 **ERP 가 보낸다**(board 코드 0). 인계 = `meetings/handoff-carerp-payment-request-split.md`.
- **매입 등록 락**(2026-08-10 배포): 연동 B 는 car-erp 저장 게이트를 안 타므로 **바이어 고르는 상류**에서 막는다. **바이어 필수** + 락 걸린 바이어면 **구매확정 버튼 비활성**. 판정은 ERP `purchase_locked` 그대로. 상세 = `SKILLS.md §14-7`.

## 현재 도메인 규칙 (§6 재설계 반영 — 코딩 시 준수)

- **금액 (Model A)**: 매입 = **원가**, 판매 = **매도비 제외**, 차감액 별도 컬럼. 매도비 = `config('board.sales_fee')`. 상세 = [board-flow-model-a-deployed / board-amount-mapping].
- **차값 통화**: 엔카 = KRW, 싼카 = 원/미/유로 토글 택1을 `car_cost` 에 **외화 그대로** 보관(`expected_price_currency`). KRW 환산은 **계산 시에만** — 단일 경로 `App\Support\Money::toKrw()` ↔ 모델 `carCostKrw/carPriceKrw/totalKrw`. **매물표시가 토글 = 차값 선택 / `displayCurrency` = 표시만**(차값 불변). `expected_price` 컬럼은 **재활용·리네임 금지**. final_price = KRW 스냅샷(연동 B 무변).
- **환율**: board 가 car-erp `/rates`(네이버 전신환매입률) 받아씀 — 값 일치. [board-exchange-rate-source]
- **입금정보**: `payee_name·payee_bank·payee_account`(**`payee_account` = `encrypted` 캐스트**). 입력 = `/listings`(영업 선택) → `/auction` 드로어 자동표시 → 연동 B 로 car-erp 전달. 은행 datalist + 계좌 동적 마스킹(`Alpine.store('koreanBanks')`).
- **사진·서류 보유 (범위한정 예외)**: 영업이 board 에 차량 사진 + 서류(**주소·RRN 마스킹본**) 업로드 → 낙찰 시 연동 B `attachments[]` 로 car-erp 첨부탭. 격리 = 전용 S3 prefix(`sales/photos`·`sales/documents`), **실행파일 차단(`App\Support\UploadGuard`)**, **서류는 바이어 전송 제외(`share_to_buyer=false`)**. `inspection_photos.kind`(inspection/sales_photo/sales_document). [board-vehicle-attachments]
- **PII 스탠스**: RRN·전화 미보유 원칙 유지. 계좌·서류는 위 *범위한정 예외*(암호화·마스킹본·표시 최소화).

## ⏭️ 남은 작업 (미완)

- **입금요청 알림톡 실발송**: ERP 가 `erp_board_request` 템플릿·시각 규칙까지 배포했지만(2026-08-11), **BizM 템플릿 승인 + 수신자 번호 설정 전까지 실발송 0**. 남음 = ① BizM 승인(인스턴스별 발신프로필 각각) ② 시각 규칙에 담당자 1~2명·대표 번호 입력. **전부 car-erp 쪽 일** — board 는 알림톡 코드 0.

- **알림톡 2종**(지역검차·전달대기, Bizm): 코드 **운영 배포 완료**(master `77738a3`, 2026-07-13, 두 박스). 현재 enabled off 라 실발송 0 — 켜는 순간 가동.
  - **2026-07-27 BizM 1차 반려**("수신 대상 불명확") → **개정 문구 dev 반영 완료**(`AlimtalkTemplates.php` + `docs/operations/alimtalk-templates-draft.md`). 개정점 = `[사내 업무용]` 접두 · "회원님"→"담당자님" · `ssancar.com`/`board`→"사내 업무 시스템(board)". 템플릿코드·명·프로필·카테고리·변수는 불변(재검수라 기존과 매칭돼야 함).
  - 남음 = ① **Jin**: BizM 재검수 요청(+검수팀 회신문) → ② **승인 나면 master 머지·배포**(Jin 지시 2026-07-27 — 승인이 배포 트리거) → ③ tmplId 2개 입력 + enabled on + 스케줄시각.
  - ⚠️ **재검수 대상 = 2프로필 × 2템플릿 = 4건**. 코드 `body` 는 하나인데 발신프로필은 heymanboard/ssancarboard 각각 별도 등록이다(`upload_board_헤이맨.xlsx` A열 교체본). **heyman 것만 재승인하면** master 머지가 두 박스에 동시배포되므로 ssancarboard 는 옛 승인문구 ↔ 새 코드 불일치 → enabled on 하는 순간 발송 실패. 설정(tmplId·enabled)도 **인스턴스별로 각각**.
  - ⚠️ 승인 문구가 위 개정본과 **한 글자라도 다르게** 확정되면 코드 `body` 를 그 확정본으로 먼저 맞춘 뒤 배포(불일치 = 발송 실패). [board-alimtalk-region-inspection]
- **판매계약서 전자서명 요청**: board **운영 배포 완료**(master `f807ed7`, 2026-07-11) + car-erp §10/§10-2 엔드포인트도 배포됨. 남음 = **실거래 e2e 검증**(발급→전달→바이어서명→증거메일). [board-esignature-request]
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
- (저우선) TimeGate **관리자 전역 해제 UI**(현재 등록잠금만; 편집은 이미 우회) · 퇴사자 계정 양쪽 동시정지 절차 **문서화**.

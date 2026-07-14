# board — 매입·검차·경매 업무보드

> ⚠️ **세션 시작 시 로드 순서**: 이 파일(`CLAUDE.md`) → **`CLAUDE_1.md`(코딩 행동지침 — 가정금지·단순성·수술적변경·목표검증, 모든 코드작업에 상시 적용 · 반드시 읽을 것)** → `SKILLS.md`(구현 패턴/재발 버그). car-erp 와 **별도 앱·별도 DB**다. 헷갈리지 말 것.

> 👤 **이 저장소 작업자 = Jin** (개발자, GitHub `wlsdud10075-JIN`). 세션에서 사용자는 Jin — **"Jin"으로 부를 것**(대표님 ❌). **"대표"는 별도 인물**(회사 대표 = "대표 승인 필요" 결정의 승인권자). **Jin ≠ 대표.**

SSANCAR 의 매입 *확정 전* 워크플로우(영업 매입예정 → 현지 검차·금액산정 → 바이어 수락 → 경매/구매 → car-erp 재고 전환)를 디지털화하는 신규 앱. v2 목업(`docs/purchase-board-mockup2.html`, car-erp repo)을 현실화한 것.

> 🔗 **형제 앱 `car-erp` + 연동** (별도 repo/DB/APP_KEY/배포):
> - `car-erp` = 중고차 수출 **ERP** (`C:\xampp\htdocs\car-erp`, 자체 CLAUDE.md/SKILLS.md). board 매입 *확정* → **car-erp 재고 전환**.
> - 🏢 **멀티 인스턴스 (테넌트별 배포, 2026-06-26 Jin 확정)**: car-erp 가 회사별로 **heyman / ssancar / karaba** 인스턴스(한 master 멀티배포)인 것처럼, **board 도 동일하게 heyman / ssancar / karaba 로 쪼개진다**. ERP 인스턴스 1개 ↔ board 인스턴스 1개를 **1:1 쌍**으로 띄움 — **각 쌍은 별도 서버·별도 DB·별도 APP_KEY·쌍 전용 공유 HMAC 시크릿**(쌍 간 교차 금지, heyman 값 재사용 금지). 같은 코드, 다른 데이터(브랜딩·계정은 DB 설정/시드). 현재 운영 LIVE = **heyman 쌍**. **ssancar 쌍 = 새 인스턴스 아님 — 기존 NICE 박스 `54.116.7.83`(Django 공존)에 co-locate**(2026-06-26 확정; NICE 화이트리스트 IP 고정 때문, Django 정리는 추후). **도메인(Option B): car-erp = apex `heymancar.com`, board = `board.heymancar.com`** → board `CAR_ERP_BASE_URL=https://heymancar.com`. NICE `/provide/` 는 apex location 으로 Django 와 공존(이식 때 그 location 만 car-erp 로 flip). **ssancar 쌍 배포 완료(2026-06-27 LIVE `board.heymancar.com`)** — NICE 게이트웨이 이식만 추후. 인계·런시트 = `meetings/handoff-car-erp-ssancar-deploy.md`. 자동배포: board deploy.yml = **environment matrix 다중화 완료(2026-06-29)** — `Production`(heymanboard)+`Production-ssancar`(ssancarboard) 병렬 자동배포(master push 트리거, fail-fast=false). 인스턴스 추가(karaba) = matrix 에 한 줄 + 동명 GitHub Environment·secrets 생성. ⚠️ secret 은 `printf '%s'|gh secret set`(stdin)로 입력(`--body` 는 여분문자 위험).
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
- `super` 시스템관리자 — role 무관 전체 접근 + **사용자관리** + **기능설정**. car-erp super 대응.
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
| `/audit` | audit | **super 전용** — 감사로그(변경이력 board_audit_logs + car-erp 전송 integration_events) |
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

## 4(+2) 화면 (Volt, `resources/views/livewire/*/index.blade.php`)
1. **listings**(영업): 매입예정 추가(출처 토글·TimeGate 가드, 차량번호/소유자/차값/할인 가로 grid) + 본인 글 행클릭 편집 드로어.
2. **inspection**(현지확인): 지역별 그룹 + 모바일 드로어(사진/영상 업로드·메모·최종금액). **전달/회신 = "선택 후 저장" 수동씬**(클릭=색강조만, 하단 저장이 상태전이 커밋).
3. **auction**(경매/구매): accepted 차량 낙찰/유찰·구매확정/취소(→ won/failed) + 소유자·입금정보. won → 연동 B 자동 push.
4. **manage**(관리자): KPI 5종(**클릭=그 차원 필터 토글**) + **필터(검색·상태·출처·회신, 가로 grid) + 페이지네이션(20)** 전체현황 + **무제한 수정 드로어(어지간한 필드 전부 — 식별값은 미연동만)**. 모든 변경은 옵저버가 감사기록.
5. **users**(super): 계정 생성·역할·시스템관리자 지정·활성토글·**car-erp 영업 이메일 매핑**.
6. **audit**(super): 감사로그 — 변경이력(board_audit_logs, 상태/회신/출처 한글표시) + car-erp 전송로그(integration_events payload). 페이지네이션. /manage 에서 분리.

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

## 현재 도메인 규칙 (§6 재설계 반영 — 코딩 시 준수)

- **금액 (Model A)**: 매입 = **원가**, 판매 = **매도비 제외**, 차감액 별도 컬럼. 매도비 = `config('board.sales_fee')`. 상세 = [board-flow-model-a-deployed / board-amount-mapping].
- **차값 통화**: 엔카 = KRW, 싼카 = 원/미/유로 토글 택1을 `car_cost` 에 **외화 그대로** 보관(`expected_price_currency`). KRW 환산은 **계산 시에만** — 단일 경로 `App\Support\Money::toKrw()` ↔ 모델 `carCostKrw/carPriceKrw/totalKrw`. **매물표시가 토글 = 차값 선택 / `displayCurrency` = 표시만**(차값 불변). `expected_price` 컬럼은 **재활용·리네임 금지**. final_price = KRW 스냅샷(연동 B 무변).
- **환율**: board 가 car-erp `/rates`(네이버 전신환매입률) 받아씀 — 값 일치. [board-exchange-rate-source]
- **입금정보**: `payee_name·payee_bank·payee_account`(**`payee_account` = `encrypted` 캐스트**). 입력 = `/listings`(영업 선택) → `/auction` 드로어 자동표시 → 연동 B 로 car-erp 전달. 은행 datalist + 계좌 동적 마스킹(`Alpine.store('koreanBanks')`).
- **사진·서류 보유 (범위한정 예외)**: 영업이 board 에 차량 사진 + 서류(**주소·RRN 마스킹본**) 업로드 → 낙찰 시 연동 B `attachments[]` 로 car-erp 첨부탭. 격리 = 전용 S3 prefix(`sales/photos`·`sales/documents`), **실행파일 차단(`App\Support\UploadGuard`)**, **서류는 바이어 전송 제외(`share_to_buyer=false`)**. `inspection_photos.kind`(inspection/sales_photo/sales_document). [board-vehicle-attachments]
- **PII 스탠스**: RRN·전화 미보유 원칙 유지. 계좌·서류는 위 *범위한정 예외*(암호화·마스킹본·표시 최소화).

## ⏭️ 남은 작업 (미완)

- **알림톡 2종**(지역검차·전달대기, Bizm): dev 완료·미배포. 남음 = 슬라이스3(Jin Bizm 2종 승인 + tmplId + enable + 스케줄시각). enabled off 라 실발송 0. [board-alimtalk-region-inspection]
- **판매계약서 전자서명 요청**: board 측 dev 커밋. master 미머지 + e2e 미검증(ERP §10 master 배포 대기). [board-esignature-request]
- (저우선) TimeGate **관리자 전역 해제 UI**(현재 등록잠금만; 편집은 이미 우회) · 퇴사자 계정 양쪽 동시정지 절차 **문서화**.

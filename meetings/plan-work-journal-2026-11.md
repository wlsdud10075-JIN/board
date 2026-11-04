# 업무일지 + 업무일지결재 — 기획 (2026-11-04 Jin, 주말 개발 예정)

> 상태: **1~4단계 구현 완료(dev, 미배포 · 2026-11-04)** — 남은 것 = Jin 로컬 확인 → 배포 허락, 5단계(알림톡). 기획 확정(2026-11-04 미리보기 확인 — 「나머지는 괜찮다」, 입력·자동저장·알림톡 3건 반영). 미리보기 = 바탕화면 `업무일지_기획_미리보기.html`. 결재 구조 참고 = car-erp 월정산 결재(조사 회신 car-erp-d7, 2026-11-04).

## 확정된 결정 (Jin 2026-11-04)

| 항목 | 결정 |
|---|---|
| 작성자 | **전 직원**(영업·현지확인·경매·관리 — 등급 「일반」). 결재 직급이 붙은 사람은 작성 대상 아님 — 결재만 |
| 결재선 | 기안자 → **팀장(업무관리자 중 지정 1명) → 부장 → 상무 → 대표(최고관리자 직급)**. **비어 있는 단계는 건너뜀**(부장 없으면 팀장 → 상무, 대표는 필수) — Jin 2026-11-04 |
| 팀 | **팀장 1명이 전체**(인스턴스당). heymanboard·ssancarboard 각각 |
| 등급 | **최고관리자(`permission=admin`) 신설** — super > admin > manager(업무관리자) > user. ERP 미러(car-erp-d7 조사): 전체 화면·데이터, 감사 로그, 사용자관리(super 계정 제외 · 줄 수 있는 등급 admin·manager·user), `/manage` 삭제·재전송. **못 함** = 기능설정(super)·남의 포털 대신 실행(**조회만**, 업무관리자와 같게 — Jin). admin 은 role 을 「관리」로 고정 |
| 직급 | `users.approval_title`: 최고관리자에게만 **부장·상무·대표**, 업무관리자에게만 **팀장**(1명 지정, 「팀장으로 지정」). 등급이 바뀌면 직급 자동 해제(ERP 와 같은 3중 검증: 저장 시 비움·저장값 가드·결재선 생성 시 재검증) |
| 묶음 | **날짜별 자동 묶음** — 그날 상신분이 한 기안. 팀장이 결재하기 전까지 늦게 올린 사람도 합류 |
| 마감 | **19:00 기본, 기능설정(super)에서 변경** — 마감 시각에 팀장 알림 + 「미상신」 표시 시작 |
| 주말 | 쓰기·상신은 가능, **마감 알림·미상신 표시 없음**. 공휴일은 일단 평일 취급 |
| 반려 | **없음 — 의견만**(업무일지는 보고 성격) |
| 의견 | 결재자마다 **인원별 의견 + 전체 의견**. 다음 결재자는 앞 단계 의견을 전부 본다(ERP 는 묶음 전체 의견만 — 여기는 다름) |
| 알림 | **종·웹푸시 먼저**(기존 기능), BizM 알림톡(결재요청·최종승인)은 템플릿 검수 승인 후 추가 |
| 감사 | 작성·상신·회수·의견·승인 전부 `board_audit_logs`(Jin 원칙) |
| 입력 | **[+ 줄 추가] = 지금 시각 자동 기입, 시간 칸은 수정 가능**(Jin 2026-11-04 미리보기 피드백) |
| 자동 저장 | **적을 때마다 서버에 저장**(draft) — 그때그때 들어와 한 줄씩 쓰고 저녁에 상신하는 사람이 있다. 화면에 「자동 저장됨 HH:MM」 |
| 알림톡 | 붙일 때는 **ERP 월정산과 똑같이 진행**(Jin) — 차례 결재자 1명에게 결재요청(전용 서명 링크 5일·로그인 없음·GET 보기/POST 승인·결정 때 재검증), 최종 승인 시 기안자+결재선에 완료 |

## 화면

1. **업무일지 탭**(작성자): 날짜 선택 → 표 「시간 | 한 일 | 비고」, [+ 줄 추가]·시간 「지금」 버튼. 맨 아래 **「오늘 board 활동 (자동)」** = 그날 본인 감사 로그(차량 등록·ERP 전송·요청·사진 등)를 시간·차량번호·한 일·**현재 진행상태**로 — **옮겨 적는 버튼 없음, [상신]하면 표와 함께 그대로 올라간다**(Jin 2026-11-04 — 「기록된 게 그대로 같이 상신되면 된다」). [상신] → 잠김·「결재 대기」, 팀장이 승인하기 전엔 [회수] 가능.
2. **업무일지결재 탭**(팀장·상무·대표): 날짜별 기안 목록 → 기안 상세 = 결재선 띠(기안→팀장✓→상무⏳→대표) + 「상신 N / 미상신 M(이름)」 + **인원별 접기·펼치기 카드**(일지 표 + 자동 활동 + 앞 단계 인원별 의견 + 내 인원별 의견 칸) + 전체 의견 + [승인]. 완료 기안은 보관함(날짜·사람 검색).
3. **기능설정**(super): 마감 시각. **사용자관리**: 결재 직급 칸.

## ERP 에서 가져올 교훈 (car-erp-d7 회신)

- 🚨 **최고관리자 도입 함정**(ERP 경험): board `hasFullAccess()`(super+업무관리자)·라우트 가드·사이드바·SalesmanScope·푸시 수신 등 「전체 접근」 판정 전부에 admin 을 같이 넣을 것 — 하나라도 빠지면 admin 만 조용히 막힌다. 화면별 접근 테스트로 고정. ERP `isAdmin()` 은 super 포함 이름이라 헷갈림 — board 는 메서드 이름을 명확히(예: `isTopManager()`).
- 직급 라벨은 저장값 그대로 — 이름을 바꾸려면 데이터 마이그를 같이(ERP 11-02 전무→상무).

- **상신 순간 내용 박제** — 이후 일지 수정·활동 변화가 결재 내용을 바꾸지 않게(ERP: 반려·최종 승인 때 박제 안 해 빈 카드 사고). 자동 활동 줄도 상신 시점 스냅샷.
- 알림톡 링크 = `temporarySignedRoute`(5일, 로그인 없음) · **GET 은 보기만, POST 로 승인**(카톡 미리보기가 링크를 먼저 열어도 자동 승인 안 되게) · 결정 때마다 현재 단계·그 사람 재검증 · 서명은 호스트 포함 → `forceRootUrl(APP_URL)`.
- 알림톡 템플릿은 **처음에 필요한 것 다 같이 검수**(결재요청·최종승인 + 필요하면 중간 통보) — 본문 고정이라 나중에 못 늘린다.
- 승인요청 재알림 10분 연타 방지.

## 데이터 모델 초안

- `work_journals`(user_id, date, status draft/submitted/approved, submitted_at, snapshot json) unique(user_id,date)
- `work_journal_entries`(journal_id, time, task, note, sort)
- `work_journal_batches`(date, status pending/approved, current_step)
- `work_journal_steps`(batch_id, seq, title, approver_user_id, status, acted_at, note)
- `work_journal_comments`(batch_id, step_id, journal_id null=전체, user_id, body)
- `users.approval_title`(팀장·상무·대표, nullable) · Setting `work_journal_cutoff`(19:00)

## 미결

- 결재 완료 후 기안자에게 의견이 보이나(보인다 가정 — 미리보기에서 확인)
- 대표 최종 승인 후 수정 불가(가정)

---

## 🏠 개발 시작 안내 (다른 PC 에서 이어 받기 — 2026-11-04 작성)

> 메모리는 PC 별이라 안 따라간다. **이 문서 + 미리보기 `meetings/mockups/work-journal-preview.html` 이 전부**다(브라우저로 열어 사람 바꿔 가며 확인).
> 시작 전: `git pull origin dev` → `composer install`·`npm install`(필요 시) → `php artisan migrate` → `php artisan test`(기준선: 실패 2건 = Dashboard 302·Example, 원래부터).
> master 머지/배포는 **Jin 명시 허락 후에만**. `.md` 는 dev 전용.

### 단계 (각 단계 = 커밋 1개 이상, 테스트 동반)

**1단계 — 최고관리자 등급 + 결재 직급** (먼저 단독으로 끝내고 배포 가능한 단위)
- 마이그: `users.permission` ENUM 에 `admin` 추가(선례 `2026_10_14_000001` — change() 로 값 넓힘, down 은 admin→manager 강등 후 축소) · `users.approval_title` string(10) nullable.
- `app/Models/User.php`: `isTopManager()`(permission=admin, 🚫 이름을 `isAdmin` 으로 짓지 말 것 — ERP 는 super 포함이라 혼동) · `hasFullAccess()` 에 admin 포함(:109) · 상수 `APPROVAL_TITLES_TOP = ['부장','상무','대표']`, 팀장 = `'팀장'`.
- 「전체 접근」 판정 전수 확인(`hasFullAccess()` 호출 26곳 + `isSuper()`/`isWorkManager()` 직접 비교 + `EnsureFullAccess`·`EnsureSuper` 미들웨어 + 사이드바 + `SalesmanScope` + 푸시 수신 + `/portal` `proxyWriteBlocked()`(admin = **조회만**) + `/manage` `canManageActions()`). 화면별 접근 테스트로 고정 — 하나라도 빠지면 admin 만 조용히 막힌다.
- `/users`(`resources/views/livewire/users/index.blade.php`): 등급 선택에 최고관리자(지정 = super 와 admin, admin 은 super 를 못 줌) · `targetIsProtected()` 에 admin 계층(admin 계정은 super·admin 만 수정) · 결재 직급 칸(admin → 부장/상무/대표 select, manager → 「팀장으로 지정」 체크 — 팀장은 1명, 지정하면 기존 팀장 해제) · 등급이 바뀌면 직급 자동 해제 · admin 은 role=manager 고정 · 감사 행 · 등급 칩 필터에 최고관리자. `nav.perm.*`·`users.*` lang ko/en.
- ⚠️ CLAUDE.md 「권한 시스템」 표 갱신(admin 행).

**2단계 — 업무일지 탭(작성자)**
- 테이블: `work_journals`(user_id, date, status draft/submitted/approved, submitted_at, snapshot json — 상신 순간 표+자동 활동 고정, unique(user_id,date)) · `work_journal_entries`(journal_id, time 'HH:MM', task text, note, sort).
- 화면 `resources/views/livewire/work-journal/index.blade.php`(Volt) + 라우트·사이드바(작성자 = 직급 없는 일반 사용자 전원). 날짜 선택, 표 3칸, [+ 줄 추가] = **지금 시각 자동(수정 가능)**, [지금], 줄 삭제, [전날 일지 불러오기].
- **자동 저장**: 칸 변경마다 서버 저장(`wire:model.live.debounce` + updated 훅, 행 단위 upsert) · 「✓ 자동 저장됨 HH:MM」.
- **board 활동(자동)**: 그날 본인 `board_audit_logs`(user_id, created_at 날짜) — 등록(create)·구매확정/상태·ERP 전송(`erp_*`)·사진(`attachment_add`)·요청(`erp_board_request` 등)을 시간·차량번호·한 일·**현재 진행상태**(listing statusLabel)로. **옮겨 적는 버튼 없음 — 상신 때 snapshot 에 그대로 포함.** 같은 순간 칸별 field_edit 은 한 줄로 묶을 것(구매확정 1회 = 수십 행).
- [상신] → 잠김 · [회수](그날 묶음의 팀장 단계가 아직 대기일 때만).

**3단계 — 업무일지결재 탭(팀장·부장·상무·대표)**
- 테이블: `work_journal_batches`(date unique, status pending/approved, current_step) · `work_journal_steps`(batch_id, seq, title, approver_user_id, status pending/approved/skipped, acted_at) · `work_journal_comments`(batch_id, step_id, journal_id null=전체, user_id, body).
- 묶음 생성 = 그날 첫 상신 때(date 단위 1개). 결재선 = 팀장(지정 manager 1명) → 부장 → 상무 → 대표(admin 직급) — **그 직급 사람이 없으면 skipped**, 대표 없으면 상신 막음(안내). 팀장 승인 전 상신분은 합류, **승인 후 상신분 = 「추가 기안」**(같은 날짜 두 번째 묶음 — date unique 대신 (date, seq)).
- 화면: 날짜별 기안 목록 · 결재선 띠 · 상신 N/미상신 M(이름, 주말 제외) · 인원별 `<details>` 카드(표 + board 활동 snapshot + 앞 단계 인원별 의견 + 내 의견 칸) · 전체 의견 · [승인 → 다음]. **반려 없음.** 차례 아닌 결재자는 읽기 전용. 완료 후 기안자는 본인 일지에서 **나에게 달린 의견 + 전체 의견**만 본다. 대표 승인 후 수정 불가.
- ERP 참고(읽기 전용, car-erp 레포): 카드 `resources/views/components/payout/person-card.blade.php`, 결재 로직 `app/Models/SettlementPayoutBatch.php`(approveBy·normalizeLine).

**4단계 — 마감·알림**
- 기능설정(super) `work_journal_cutoff` 기본 19:00(`Setting::get`) · 스케줄 명령(매분 또는 cutoff 시각) 평일만: 팀장에게 「상신 N · 미상신 M」 종·웹푸시(`app/Services/WebPushService.php`, 종 = `resources/views/livewire/notify/bell.blade.php` 패턴). 결재 차례가 오면 그 결재자에게 종·푸시, 최종 승인 시 기안자 전원.
- 감사 행 전부: 작성(첫 저장)·상신·회수·의견·승인·skip(시스템).

**5단계(나중) — 알림톡 = ERP 월정산과 똑같이**: BizM 템플릿(결재요청·최종승인) 검수 후. 차례 결재자 1명에게 전용 `temporarySignedRoute` 링크(5일, 로그인 없음, GET 보기 / POST 승인·의견, 결정 때 재검증, `forceRootUrl(APP_URL)`), 10분 연타 방지.

### 미리보기와 실제의 차이 (구현 시 주의)
- 미리보기는 브라우저 저장 — 실제는 서버 저장.
- 미리보기의 board 활동은 가짜 샘플 — 실제는 감사 로그 매핑(action → 문구 표, 모르는 action 은 원문).

---

## ✅ 구현 결과 (2026-11-04, dev)

- 1단계 최고관리자 = `0701ced` · 2~4단계 = 이 커밋. 테스트 `tests/Feature/WorkJournalTest.php`(10) + BoardTest 최고관리자 4건, 전체 567 통과(기준선 2건 실패 그대로).
- 파일: `app/Services/WorkJournalService.php`(상신·회수·결재·board 활동·마감 — 로직 단일 출처) · `app/Models/WorkJournal*.php` · `resources/views/livewire/work-journal/{index,approvals,_activities}.blade.php` · 마이그 `2026_11_04_000001`(admin·approval_title)·`000002`(업무일지 5테이블) · 명령 `board:work-journal-cutoff`(5분마다, 평일·마감 이후·하루 1번) · 기능설정 「업무일지 마감 시각」.
- 결정 보충: **직급당 1명**(부장·상무·대표도 — 결재선 자동 선택이라 둘이면 규칙이 없다, ERP 는 제출 때 고름) · 최고관리자는 `hasFullAccess` 라 **포털 정산도 보인다**(ERP admin 과 같음, 관리 role 정산 숨김과는 별개 축) · 날짜 칸 조회는 `whereDate`(sqlite date 캐스트 함정).
- 종(🔔) 패널 연동은 안 함 — 사이드바 「업무일지결재」 배지(내 차례 수) + 웹푸시(차례·최종 승인·마감)로 대신. 필요하면 다음에.
- 로컬 확인 순서: ① `php artisan migrate` + 결재선 계정(`db:seed` 또는 이미 있으면 사용자관리에서 지정) ② 일반 계정(예: kim@board.test)으로 「업무일지」 → [+ 줄 추가]·적기·다른 화면 다녀오기·[상신] ③ leader@board.test → 「업무일지결재」 → 인원 펼쳐 의견 → [승인 → 부장에게] ④ bujang → sangmu → ceo 순서로 승인 ⑤ 다시 작성자 → 결과·의견 확인 ⑥ admin@board.test(super) → 사용자관리 등급·결재 직급, 기능설정 마감 시각.

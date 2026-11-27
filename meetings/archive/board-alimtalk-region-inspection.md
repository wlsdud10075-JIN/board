# board 알림톡 — 지역 검차 안내 (설계, 2026-07-12)

> 상태: **설계 확정 대기 → 슬라이스 빌드.** 코드 미착수(발송 코드는 Bizm 템플릿 승인 전 금지, 스캐폴딩은 placeholder tmplId 로 가능).
> car-erp 알림톡(Bizm/스윗트래커)을 **board 가 직접 발송**하는 방식으로 도입. 권위 소스(이식 대상) = car-erp `app/Services/BizmAlimtalkService.php` · `app/Support/{AlimtalkConfig,AlimtalkTemplates,AlimtalkRecipients}.php` · `app/Models/AlimtalkLog.php` · `docs/operations/alimtalk-templates-draft.md`.

## 알림톡 종류 = 2종 (Jin 2026-07-13 확장)
검차 워크플로우 양끝에 알림톡. 발송기·설정·로그·users.phone 인프라는 공유, **템플릿·수신자·트리거만 다름**(Bizm 템플릿도 각각 등록 = 2번 연계).

### A. 지역 검차 안내 (→ 검차원) — 워크플로우 진입
지역별 **「지역명 + 검차 대상 차량번호 목록」** 을 담당 검차원에게 → 검차원이 보고 검차 → ssancar.com 등록.
- 트리거 A-1 **스케줄 사전알림**: 내일 배정분을 전날 저녁(Setting 시각) 지역별 digest. board `schedule:run` cron 이미 가동.
- 트리거 A-2 **관리 수동발송 버튼**: 스케줄 외 즉시. 진입점만 다르고 템플릿·수신자·발송기 동일.
- 템플릿 `board_region_inspection`, 수신자 = region 고정 로스터(users.region) 또는 per-date 배정 override.

### B. 사진/영상 완료·전달대기 (→ 영업담당자) — 워크플로우 출구
ssancar.com inspected 에 사진/영상 올라가 **자동 전달대기 전이**되면, 그 차 **영업담당자**에게 "OO차량 사진/영상 업로드 완료, 바이어 전달 대기".
- 트리거 = **`PollSsancarMedia` 의 `draft → inspected(전달대기)` 전이 직후**(`app/Console/Commands/PollSsancarMedia.php:63-80`). fire-and-forget.
- 수신자 = 그 매물 **작성 영업**(`purchase_listings.created_by_user_id` → user.phone).
- 템플릿 `board_forward_ready`(신규), 변수 `#{차량번호}`.
- **dedup 불필요** — draft→inspected 는 1회성 단방향(폴러 쿼리도 draft 만). 전이 시 1건만 발송.
- ✅ **자동전이만(Jin 2026-07-13)**: 검차하면 inspected 에 사진/영상이 **무조건** 올라감 → 전달대기 전이는 항상 자동 경로(PollSsancarMedia). **수동 전환 케이스 없음** → B 는 이 훅 하나만. 수동전이 발송 불필요.
- ⚠️ **새로 만드는 것 = 알림톡 발송뿐.** 자동전이(전달대기)·미러링 꼬리는 **이미 운영 중**([[board-ssancar-auto-forward]]). 재구축 금지.

## 확정 결정 (Jin, 2026-07-12)
1. **발송 방식 = board 직접.** car-erp 11종 커맨드 이식 아님 — 얇은 발신기 1개 + 템플릿 1종만. car-erp 가동여부와 무관(heyman 쌍 박스 분리 → 디커플링 이점). **발신프로필은 car-erp 와 공유 전제**(⚠️ Jin 확인 필요 — Bizm 콘솔).
2. **수신자 = board.users 에 `phone` 추가.** 수신자 = 그 지역×날짜 배정 검차원(`InspectionAssignment.user_id`). 직원 전화 = 바이어 PII 아님 → 범위한정 예외로 보유(CLAUDE.md 선례).
3. **중복 방지 = 차량당 1회.** 이미 알린 draft 는 재발송 안 함(`purchase_listings.region_notified_at` stamp 또는 AlimtalkLog 조회). 스케줄 사전알림이 검차 전까지 매일 스팸되는 것 차단.

## 데이터 모델 변경 (additive)
- `users.phone` (nullable string) — 검차 직원 수신번호. `/users`(super) 관리 UI 에 입력칸.
- `purchase_listings.region_notified_at` (nullable timestamp) — 차량당 1회 dedup stamp.
- `alimtalk_logs` 테이블 신설 (car-erp AlimtalkLog 미러: template_code·phone·status(sent/failed/skipped)·msgid·error·맥락). append-only.
- Setting 키: `alimtalk_userid`·`alimtalk_profile`·`alimtalk_enabled`·`alimtalk_tmpl_board_region_inspection`·`alimtalk_toggle_*`·(선택)`alimtalk_recipients_*`. car-erp AlimtalkConfig 패턴.

## Bizm 대량등록 파일 (2026-07-13, Jin ERP 아이템리스트 전환에 맞춤)
- ERP 가 `upload_erp_헤이맨_아이템리스트.xlsx`(Desktop\알림톡) 대량등록 양식으로 전환 중. board 도 같은 양식으로 2종 등록 파일 생성.
- **board 산출물 = `Desktop\알림톡\upload_board_헤이맨.xlsx`** (ERP 파일을 서식·검증째로 복제 후 데이터행만 board 2종으로 교체, car-erp PhpSpreadsheet 사용). 헤더행(1-5) 보존, 데이터행 6~.
- 컬럼: A=발신프로필(`@헤이맨___`, ERP 와 **공유** — 원본에서 그대로 복사) · B=코드 · C=명 · D=메시지유형 **BA(기본형)** · E=본문(코드 AlimtalkTemplates 와 char-for-char 일치) · H=FALSE · I=카테고리 **008002(내부 업무 알림)** · J=강조유형 **선택안함**.
- ⚠️ **board 2종 = "선택안함"(아이템리스트형 아님)** 결정: ① `#{차량목록}` 가변길이 → 아이템리스트 고정슬롯(2~10) 부적합 ② 현 발송기(BizmAlimtalkService)는 본문 msg 만 전송 → 아이템리스트형은 아이템 변수 전송 별도 필요(ERP 도 전환 작업 중, 미검증) ③ ERP 도 목록형(판매미입금 등)은 선택안함 사용. board 내용(가변목록/단일필드)엔 선택안함이 정합.
- ⚠️ **ssancarboard**: 같은 2종을 **ssancar 발신프로필**로 별도 등록 필요(A열만 교체). ssancar 프로필 값 확보 후 동일 스크립트로 생성.
- 생성 스크립트 = 세션 scratchpad `gen_board.php`(car-erp autoload). 문구 수정 시 파일·코드·초안(docs) 3곳 동시.

## 템플릿 (Bizm 사전등록·승인 필요 = 외부 의존성·리드타임)
- 코드 `board_region_inspection`, 변수 `#{지역}` · `#{건수}` · `#{차량목록}`(가변, 개행 목록 — 길이/포맷 검수 주의).
- 초안(등록 시 글자까지 동일):
  ```
  [검차 안내] #{지역}

  검차 대상 차량 목록입니다.

  ■ 지역: #{지역}
  ■ 대상 차량 #{건수}대
  #{차량목록}

  ssancar.com 에 검차 결과를 등록해 주세요.
  ```
- 초안 원본 board 보관 = `docs/operations/alimtalk-templates-draft.md`(신설, car-erp 관례 미러). 문구 수정 시 Bizm·코드 동시 반영.

## ✅ 슬라이스 2 구현 완료 (2026-07-13, dev)
- **A(지역검차)**: `users.region` 마이그 + /users 검차원 지역 드롭다운(검차 role 만 저장) + `RegionInspectionNotifier`(수신자 resolver: per-date 배정 override→지역 로스터 / region 별 digest / **실발송 성공 시에만 region_notified_at stamp**=off·no_phone 상태 stamp 방지) + `AlimtalkRegionInspection` 커맨드(`--date`, 기본=내일) + routes/console.php **조건부 스케줄**(Setting `alimtalk_region_schedule_time` 유효 HH:MM 일 때만 dailyAt 등록) + inspection 배정패널 **「지역 검차 알림톡 발송」 수동버튼**(canAssign, assignDate 기준).
- **B(전달대기)**: `PollSsancarMedia` draft→inspected 전이 직후 `creator`(작성 영업) phone 에 `board_forward_ready` fire-and-forget.
- ⚠️ **컨테이너 바인딩**: `BizmAlimtalkService` 는 `AlimtalkConfig`(스칼라 생성자)라 오토와이어 불가 → `AppServiceProvider::register` 에서 `bind(BizmAlimtalkService::class, fn()=>::active())`. 커맨드/서비스 주입은 **매 resolve 시 Setting 기반 fresh**(테스트도 설정 변경 후 fresh resolve 필요).
- 테스트 4종(roster·dedup·off-미stamp / override / B훅 / users.region). 196 통과.

## 빌드 슬라이스 (Bizm 승인과 병렬 진행)
- **슬라이스 1 (스키마+플럼빙, 승인 불필요)**: 마이그(users.phone·region_notified_at·alimtalk_logs) + BizmAlimtalkService/AlimtalkConfig/AlimtalkTemplates(1종)/AlimtalkLog 이식 + Setting UI(Bizm 계정·토글·테스트발송) + /users phone 입력칸. placeholder tmplId 로 스캐폴딩(canSend 게이트로 실발송 차단).
- **슬라이스 2 (트리거)**: 스케줄 커맨드(지역별 digest, 차량당 1회 dedup) + 관리 배정화면 「저장/반영→발송」 버튼. 수신자 해석(InspectionAssignment→user.phone).
- **슬라이스 3 (활성화)**: Bizm 템플릿 승인 후 Setting 에 tmplId 입력 + enabled on + e2e(테스트발송→실지역 digest). 로컬 안전장치 = `ALIMTALK_TEST_PHONE`(car-erp 패턴, local 환경만 수신자 강제).

## 열린 항목
- ✅ **발신프로필 = car-erp 와 공유(Jin 2026-07-12).** board Setting 에 car-erp 와 같은 profile/userid 입력.
- ✅ **스케줄 digest = 내일 배정분을 전날 저녁 발송(Jin 2026-07-12).** 시각은 Setting 값(Jin 나중 입력).
- ✅ **슬라이스 3(Bizm 템플릿 등록) 보류(Jin 2026-07-12).**
- 수신자: 배정 검차원 본인만 vs 동행자 포함 — 기본 = 배정된 전원.
- fire-and-forget: 발송 실패가 업무 저장/배정을 깨지 않음(car-erp 원칙 승계).

## ✅ 지역 배정 모델 = 하이브리드 (Jin 2026-07-13)
- **기본 = 지역 고정 로스터**: 검차원마다 담당 지역을 **사용자관리(/users)에서 배정**. 지역 차량 → 그 로스터에게 자동 매칭(관리 일일배정 불필요, 전날 저녁 자동발송 안정).
- **덮어쓰기 = per-date 배정**: 필요한 날만 기존 `InspectionAssignment`(date×region×user_id, ≤3)으로 override. 있으면 그 날은 배정자에게, 없으면 고정 로스터로.
- **수신자 resolver(내일 날짜 기준)**: `InspectionAssignment(date=내일, region=R)` 있으면 그 user 들, 없으면 region=R 고정 로스터 user 들 → 각자 user.phone.

### ✅ 스키마 확정 (Jin 2026-07-13)
- **검차원 1명 = 1지역**(N명당 1지역 = 한 지역에 여러 검차원, 다대일) → **`users.region`(string, nullable)** 단일 컬럼. `user_regions` 피벗 불필요.
- **override = 완전 대체**: per-date `InspectionAssignment` 있으면 그 날 그 지역은 배정자로 대체, 없으면 고정 로스터.

## 슬라이스 2 스펙 (착수 준비 완료 — 결정 전부 확정)
1. **마이그**: `users.region`(string, nullable) — 검차원 담당 지역.
2. **/users UI**: 지역 드롭다운(`config('board.regions')` datalist, manage 화면과 동일 패턴). inspection role 위주지만 전 role 노출 무방.
3. **수신자 resolver(내일 날짜, region R)**: `InspectionAssignment(date=내일, region=R)` 있으면 그 user 들(override), 없으면 `User::where('region',R)->where('role','inspection')->where('is_active',true)`(고정 로스터) → 각 user.phone(빈 값 제외).
4. **스케줄 커맨드**(예 `alimtalk:region-inspection`): 내일 날짜 기준, region 있는 draft 차량 중 `region_notified_at` NULL 인 것 → region 별 그룹 → resolver 로 수신자 → digest(지역·건수·차량목록) 각 수신자 발송 → 성공 시 그 차량들 `region_notified_at` stamp(차량당 1회). schedule 등록 = Setting `alimtalk_region_schedule_time`(비면 미실행).
5. **관리 수동 버튼**(하이브리드): inspection/배정 화면에서 "지금 발송"(오늘/내일 선택) — 스케줄 외 즉시 발송용. fire-and-forget.
6. 테스트: resolver(override/roster 분기)·커맨드(dedup·그룹핑)·수동버튼.
- ⚠️ 슬라이스 3(Bizm 템플릿 등록·승인) 보류 유지 — enabled off 라 슬라이스 2 배포해도 무발송(안전).

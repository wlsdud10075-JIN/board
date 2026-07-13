# board 알림톡 — 지역 검차 안내 (설계, 2026-07-12)

> 상태: **설계 확정 대기 → 슬라이스 빌드.** 코드 미착수(발송 코드는 Bizm 템플릿 승인 전 금지, 스캐폴딩은 placeholder tmplId 로 가능).
> car-erp 알림톡(Bizm/스윗트래커)을 **board 가 직접 발송**하는 방식으로 도입. 권위 소스(이식 대상) = car-erp `app/Services/BizmAlimtalkService.php` · `app/Support/{AlimtalkConfig,AlimtalkTemplates,AlimtalkRecipients}.php` · `app/Models/AlimtalkLog.php` · `docs/operations/alimtalk-templates-draft.md`.

## 목적 / 흐름
지역별 검차 담당자에게 **「지역명 + 검차 대상 차량번호 목록」** 을 카카오 알림톡으로 보내, 검차원이 그걸 보고 검차 → ssancar.com 등록 → (기존) 자동 미러링 → 전달대기.
- ⚠️ **새로 만드는 것 = 알림톡 트리거뿐.** 검차→ssancar.com→자동미러링→전달대기 꼬리는 **이미 운영 중**(ssancar.com 검차영상 자동전달 기능). 재구축 금지.

## 트리거 (2종, 같은 발송 경로로 수렴)
1. **지역 기정(사전 알림)** — `region` 이 이미 정해진 draft 차량들 → **스케줄 시각**(Setting, Asia/Seoul, 기본 08:00 제안)에 지역별 digest 발송. board `schedule:run` cron 이미 가동.
2. **지역 미정(배정 직후)** — [관리]가 지역×날짜 배정(InspectionAssignment) 후 **저장/반영 버튼** 클릭 → 그 시점 즉시 발송.
- 둘 다 **동일 템플릿·동일 수신자 해석·동일 발송기**. 진입점만 다름.

## 확정 결정 (Jin, 2026-07-12)
1. **발송 방식 = board 직접.** car-erp 11종 커맨드 이식 아님 — 얇은 발신기 1개 + 템플릿 1종만. car-erp 가동여부와 무관(heyman 쌍 박스 분리 → 디커플링 이점). **발신프로필은 car-erp 와 공유 전제**(⚠️ Jin 확인 필요 — Bizm 콘솔).
2. **수신자 = board.users 에 `phone` 추가.** 수신자 = 그 지역×날짜 배정 검차원(`InspectionAssignment.user_id`). 직원 전화 = 바이어 PII 아님 → 범위한정 예외로 보유(CLAUDE.md 선례).
3. **중복 방지 = 차량당 1회.** 이미 알린 draft 는 재발송 안 함(`purchase_listings.region_notified_at` stamp 또는 AlimtalkLog 조회). 스케줄 사전알림이 검차 전까지 매일 스팸되는 것 차단.

## 데이터 모델 변경 (additive)
- `users.phone` (nullable string) — 검차 직원 수신번호. `/users`(super) 관리 UI 에 입력칸.
- `purchase_listings.region_notified_at` (nullable timestamp) — 차량당 1회 dedup stamp.
- `alimtalk_logs` 테이블 신설 (car-erp AlimtalkLog 미러: template_code·phone·status(sent/failed/skipped)·msgid·error·맥락). append-only.
- Setting 키: `alimtalk_userid`·`alimtalk_profile`·`alimtalk_enabled`·`alimtalk_tmpl_board_region_inspection`·`alimtalk_toggle_*`·(선택)`alimtalk_recipients_*`. car-erp AlimtalkConfig 패턴.

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

### ⚠️ 슬라이스 2 착수 전 확정할 작은 스키마 결정
1. **검차원 담당 지역 = 1개 vs 여러 개?** 1개면 `users.region`(string). 여러 개면 `user_regions` 피벗(또는 JSON). 검차원이 인접 여러 지역 커버 가능성 → **다중**이 현실적이나 UI 복잡. Jin 확정 필요.
2. **override 의미**: per-date 배정이 그 날 그 지역 로스터를 **완전 대체**(기본) vs 추가. 기본 = 대체.

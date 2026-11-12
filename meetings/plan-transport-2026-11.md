# 탁송 배차 요청 — 기획 정본 (2026-11-12 Jin 착수)

> 상태 = **결정 반영(2026-11-12, 4차 회신까지 §6·§11) · BizM 승인 요청 완료 2026-11-12(2종 × 프로필 2 = 4건, §7·§11-3) · ERP 요청 4건 Jin 보류 중(§11 끝) · 구현 착수 전**. ⚠️ 엑셀은 **승인본 xlsx 를 바이트 복사해 행6 셀만 교체**해야 한다 — openpyxl 로 다시 저장하면 구조(sharedStrings·workbook 속성)가 바뀌어 BizM 이 거부한다(2026-11-12 두 번 거부 실측). 규칙은 구현 때 `SKILLS.md §14-32` 로 옮긴다. 코드 0.
> 원문 요구 = §1. 기존 코드 재사용 지도 = §4. **스펙을 그대로 못 만드는 지점 1건 = §2(먼저 읽을 것).**

## 1. Jin 요구 (2026-11-12 원문 요지)
- 탁송 업체를 만들고 그 안에 휴대폰번호가 n개. 이 번호들로 보낸다.
- 영업이 매입예정 드로어 → **INVOICE 옆 「탁송」 탭**. 이미지 2장 · 전화번호 · 차량이 있는 주소 한 줄(없어도 보낼 수 있음). 기본 문구 「배차신청 요청드립니다」.
- **알림톡 발송 날짜·시간을 정한다**(미래 시각 가능).
- 받은 사람에게 첨부 2개 + 주소 + 배차신청 문구가 간다. 받은 사람은 **[배차완료] / [배차거절]**(거절은 사유 칸, 안 써도 됨).
- 상대가 [배차완료] 를 누르면 **우리 쪽에 완료 알림** + 그 차량의 **매입잔금 알림 버튼이 자동 활성화**(배차정보와 함께 가면 더 좋음).

## 2. 그대로 못 만드는 것 — 알림톡에는 사진이 못 실린다
BizM 알림톡 = **승인된 고정 본문 + 변수 + 웹링크 버튼(WL, 최대 5개)** 뿐이다. 사진·주소·문구가 「문자 안에」 보이는 게 아니라 **버튼 하나 → 서명 링크 페이지**에서 보는 구조로만 가능하다. 이미 같은 구조가 운영 중이다 — 클레임 대표 승인 링크(`a/claim/{claim}`, 2026-11-10: 로그인 없음 · 서명 URL · GET 표시 / POST 결정 · `lockForUpdate` · CSRF 제외) + WL 버튼 템플릿 승인 완료.
⇒ **탁송 = 「클레임 승인 링크」를 외부 업체 대상으로 복제한 것.** 알림톡 본문은 「[업체명] 배차 요청 · 차량 00가0000 · 요청 회사명」 수준, 버튼 [배차 확인] → 페이지에서 사진 2장·주소·문구를 보고 [배차완료] / [배차거절(사유 선택)].

## 3. 흐름 (v1)
```
영업: 드로어 「탁송」 탭 → 업체 선택(번호 n개) · 사진 ≤2 · 주소 1줄 · 발송시각(지금 or 예약) → [요청]
  └ transport_requests 행(scheduled) + 감사
매분 명령: send_at ≤ now 인 scheduled → 번호 n개 각각 알림톡(버튼 = 서명 링크 5일) → sent
업체: 링크 → 공개 페이지(사진·주소·문구·차량번호) → [배차완료] / [배차거절]
  └ 먼저 누른 사람이 확정(lockForUpdate), 나머지는 「이미 처리됨」 · 감사(전화 ***)
완료: 영업에게 종 + 웹푸시 「00가0000 배차완료 · 업체 · 기사 전화」
  └ 드로어 탁송 탭에 [매입잔금 요청] 버튼 활성(§11 purchase_balance, 금액은 사람이·비우면 ERP 미지급 전액)
거절: 영업에게 종 + 웹푸시(사유) → 같은 탭에서 재요청(회차 +1, 다른 업체 가능)
```
- 상태: `scheduled → sent → accepted | rejected | expired(5일 무응답) | cancelled(발송 전 취소)`.
- 한 차에 열린 요청은 1건(멱등키 = listing + 회차). 거절·만료 뒤 재요청은 새 회차.

## 4. 재사용 지도 (새로 짓지 않는 것)
| 조각 | 재사용 | 비고 |
|---|---|---|
| 공개 결정 페이지 | `ClaimApprovalController` 패턴 → `a/transport/{req}` | GET 표시(카톡 프리페치가 결정 못 함) / POST 는 진입 때 재발급한 60분 서명 URL. CSRF 제외 = `bootstrap/app.php` |
| 알림톡 발송 | `BizmAlimtalkService::send(code, phone, vars, context, buttons)` + `AlimtalkConfig`(인스턴스별 tmplId·토글) | 로그 = `alimtalk_logs` → `/admin/alimtalk-logs` 실패 [확인]·관제 요약 「알림톡 실패」에 자동 포함 |
| 예약 발송 | 새 표 `transport_requests.send_at` + 매분 명령 `board:transport-send` | cron 두 박스 가동 확인됨(2026-09-28), KST 정정 완료 |
| 영업 알림 | 종(`portal_seen_at` 패턴) + 웹푸시(`board:push-request-results` 틀) | v1 은 알림톡 없음(템플릿 승인 비용) |
| 매입잔금 | `/portal` §11 `purchase_balance` 와 **같은 호출**(`CarErpReadService` → `POST /requests`) | 금액 자동계산 금지 · 비우면 ERP 미지급 전액(2026-10-12 규칙 그대로) |
| 전화번호 | `seller_phone` 선례 — `encrypted` + 감사·전송 로그 `***`, 화면엔 그대로 | 업체 번호·기사 번호 모두 |
| 사진 | `inspection_photos.kind` 에 `transport` 추가 또는 별도 표, 디스크 `board.photo_disk`(S3) | 공개 페이지엔 **만료 임시 URL** 로만 |
| 감사 | 요청 생성·예약 변경·취소·발송(번호별)·완료/거절(누가 = 전화 ***)·매입잔금 전송 전부 `board_audit_logs` | Jin 규칙 2026-10-29(묻지 않고 기본) |
| 업체 관리 | 새 작은 화면 `/transport-companies`(관리 이상) — 업체명·코드·번호 n개·활성 | 기능설정은 super 전용이라 거기 두지 않는다 |

## 5. 확인한 사실 (추측 아님)
- **ERP §11 `note`(200자)는 저장만 되고 알림톡 본문에는 안 실린다** — car-erp `BoardRequestController::281` `#{요청내역}` = 구분·차량·금액·계좌 줄만(`note` 미포함). ⇒ 「매입잔금 알림에 배차정보 동봉」은 **ERP 변경이 필요**(알림톡 `#{요청내역}` 에 note 한 줄 추가 — 템플릿 변수 추가 없이 가능해 보이나 car-erp 세션 확인). board 는 `note` 에 「배차: 업체 · 기사전화 · 배차일시」를 실어 보내면 ERP 드로어에서는 바로 보인다.
- car-erp 는 **탁송비**(월명세서 파서 `wika` / `gucheonyuk` / `hyundai_a1`, `Vehicle::972~`)를 이미 다룬다 — 비용은 ERP 소관. board 업체 코드를 이 이름과 맞춰 두면 나중에 매칭이 쉽다(선택).
- board 알림톡 발송기는 WL 버튼을 `button1~5` 개별 필드로 보내며 **템플릿의 버튼 개수·이름과 글자 단위로 같아야** 한다(K108).
- BizM 승인 전례 = 2026-07-27 반려 → 2026-10-21 승인(약 3개월). **프로필 2개(heymanboard·ssancarboard) 각각 등록** = 2건.

## 6. Jin 결정 (2026-11-12 회신 — 번호 = 질문 번호)
1. **대상 상태 = 전부** — 탁송 탭은 상태와 무관하게 열린다(draft 포함).
2. **수신자 = 업체 번호 전부 + 임의 번호 1개 추가 허용** + **영업담당자(등록자) + 그 영업을 관리하는 관리(`manager_salesman`)** 셋 다 같은 알림톡을 받는다(전화 = `users.phone`). 결정(배차완료/거절)은 **업체 측 링크에서만** — 영업·관리 링크는 상태 보기 전용(board 제안, Jin 확인 전). 먼저 누른 사람이 확정, 나머지는 「이미 처리됨」.
3. **사진 = 명함·차량 외관** — 서류 아님. 공개 페이지엔 이 둘만(소유자명·계좌 노출 없음). 링크·임시 URL 만료 5일.
4. **재요청(회차+1, 다른 업체 가능)·발송 전 취소·시각 변경 = 둘 다 허용.**
5. **매입잔금 = v1 은 사람이 누른다**(Jin 2026-11-12 2차 「먼저 heymanerp 와 board 에서 써보고 판단」). 배차완료가 오면 ① 종·웹푸시 알림에 [매입잔금 요청] 버튼 ② 드로어 탁송 탭에 같은 버튼 — 누르면 `/portal` 과 같은 §11 `purchase_balance`(금액 비우면 ERP 미지급 전액, `note` = 「배차: 업체 · 기사전화 · 배차일시」). **자동 전송(배차완료 즉시)은 2차** — heymanerp·heymanboard 에서 써본 뒤 Jin 판단, 하게 되면 기능설정 토글 뒤에. 이미 지급된 차(미지급 0)는 ERP 가 자동 소멸시키므로 board 가 따로 막지 않는다.
6. **ERP 알림톡 동봉 = v1 안 함** — 매입잔금 요청의 ERP 알림톡 수신자는 **ERP 기능설정의 시각 규칙 표가 정한다**(car-erp `AlimtalkRecipients::forTimeRules` — 현재 시각에 매칭되는 행의 수신자 합집합, 매칭 0명이면 대표 강제; 대표 직행은 `purchase_deposit_ceo` 만). **운영 표 실측(2026-11-12 ERP 세션, heymanerp·ssancarerp 동일)** = 평일 09:00~17:30 → 관리+업무관리자 / 평일 그 밖·주말 → 업무관리자 / 대표 행 없음 ⇒ Jin 「대표에게는 대표계약금 말고 안 간다」가 맞다. 매입잔금 요청은 관리·업무관리자에게 간다. 2번으로 영업·관리가 배차 알림톡을 이미 받으므로 배차정보는 ERP 드로어 `note` 표시로 충분. 알림톡 본문 동봉은 2차.
7. **업체 관리 = 관리 role 이상**, 별도 작은 화면. 업체 코드 ↔ ERP 탁송비 파서명 맞추기는 선택.
8. **요청 권한 = 별도 없음** — 그 매물 드로어를 열 수 있는 모든 사용자(기존 `SalesmanScope`·드로어 게이트 그대로).
9. **BizM 제출 = 엑셀을 board 가 만든다**(클레임 결재요청 등록본 복제 · 카테고리 008002 내부 업무 알림 · 프로필 2개) → `Desktop\알림톡\{헤이맨확정알림톡,싼카확정알림톡}\upload_board_{헤이맨,싼카}_board_transport_request_신규.xlsx`. Jin 이 올린다. 코드 `AlimtalkTemplates` 등록은 구현 때 같은 본문으로(글자 단위 일치).

## 7. 알림톡 템플릿 확정안 (BizM 제출용 · 변수·버튼은 코드와 글자 단위 일치)
- 코드 `board_transport_request` · 템플릿명 「탁송 배차 요청」 · 메시지유형 BA · 카테고리 **008002 내부 업무 알림**(클레임 2종과 같은 승인 전례 — 「관련 업무자」에 탁송 업체 포함) · 변수 = `#{요청회사}` `#{차량번호}` `#{주소}` `#{요청일시}` · 버튼 1개 WL 「**배차 확인**」 = `{board 도메인}/a/transport/#{url}`(heymanboard `board.heysellcar.com` / ssancarboard `board.heymancar.com`).
- **엑셀 생성 완료 2026-11-12**(클레임 결재요청 등록본 복제, 프로필 `@heyman_con` / `@site_condition`) = `Desktop\알림톡\헤이맨확정알림톡\upload_board_헤이맨_board_transport_request_신규.xlsx` · `Desktop\알림톡\싼카확정알림톡\upload_board_싼카_board_transport_request_신규.xlsx`. **본문(209자, 엑셀 E6 과 글자 단위 동일 — 코드 `AlimtalkTemplates` 에도 이대로):**
```
[배차 요청] #{요청회사}

#{요청회사}에서 #{차량번호} 차량의 탁송 배차를 요청드립니다.

■ 차량: #{차량번호}
■ 차량 위치: #{주소}
■ 요청 일시: #{요청일시}

아래 버튼에서 차량 사진과 위치를 확인하신 뒤 배차 가능 여부(배차완료/배차거절)를 알려 주세요.
이 메시지는 #{요청회사}가 배차를 요청한 탁송 업체 담당자와 요청 영업담당자에게 발송됩니다.
```
- 마지막 줄 = 수신 대상 명시(2026-07-27 반려 사유 「수신 대상 불명확」 대비). `#{요청회사}` = 인스턴스 회사명(헤이맨/싼카). 주소 없으면 `#{주소}` = 「페이지에서 확인」(빈 변수 금지). `#{요청일시}` = 발송(예약) 시각 KST. 사진 2장·「배차신청 요청드립니다」 문구는 페이지에.
- 영업 대상 완료/거절 알림톡은 **v1 범위 밖**(종·웹푸시로 대체).

## 8. 데이터 (초안 · 결정 뒤 확정)
- `transport_companies`: id · name · code(nullable, ERP 파서명) · is_active · timestamps · softDeletes.
- `transport_company_phones`: company_id · phone(`encrypted`) · label(기사/사무실) · is_active.
- `transport_requests`: purchase_listing_id · revision · company_id(nullable, 임의 번호면 null) · extra_phone(`encrypted`, nullable) · address(nullable) · message(기본 문구) · send_at · status(`scheduled|sent|accepted|rejected|expired|cancelled`) · sent_at · decided_at · decided_phone(`encrypted`) · decided_name(nullable) · reject_reason(nullable, 200) · requested_by_user_id · balance_requested_at(§11 전송 시각) · timestamps. 유니크 (listing, revision).
- 사진 = `inspection_photos` `kind='transport'`(기존 업로드·S3·UploadGuard 그대로) 또는 `transport_request_files` — 2장 제한은 앱.
- 알림톡 발송 행 = `alimtalk_logs`(template_code `board_transport_request`, 수신자 = 업체 번호).

## 9. 범위 밖 (v1 안 함 — 번지지 않게)
- 탁송비·월명세서 매칭(ERP 소관, 이미 있음) · 기사 배차 최적화·경로 · 영업 대상 알림톡 · 업체 포털/로그인 · 여러 차 묶음 요청(1차는 차 1대 = 요청 1건).

## 10. 순서
결정(§6) → 템플릿 본문 확정 → **Jin BizM 제출(프로필 2개)과 병행해 dev 구현**(업체 관리 → 요청·예약 발송 → 공개 페이지 → 종·웹푸시 → 매입잔금 연결) → 목업 `mockups/transport-preview.html`(공개 페이지는 그림으로 판단) → 테스트 → 배포(Jin 허락) → tmplId 입력·토글 on → 운영 첫 1건 왕복.

## 11. 3차 회신 반영 (2026-11-12 Jin — 화면 그림 본 뒤)
1. **사진 최대 5장**(2장 → 5장, 「혹시 모르니까」). 종류는 명함·차량 외관 그대로.
2. **도착 위치 = ERP 재고 「보관 위치」와 같은 값을 찍는다** — ERP `vehicles.stock_location`(heyman/ssancar = 홈플·화물·야드 / karaba = 쇼링·항입고·야드, `Vehicle::stockLocations()` 단일 출처, 2026-07-28 jin) + `stock_location_note`. 탁송 탭에서 [홈플] [야드] [화물] 중 하나를 찍어 보내면 **ERP 그 차량의 보관 위치도 같은 값으로** 찍힌다(양방향 — Jin 「재고관리를 별도로 신경 안 써도 될 것 같다」). 공개 페이지·알림톡에도 「도착지: 홈플」 한 줄.
   - **확인한 사실**: ERP 는 이 값을 board 포털 재고 탭에 **읽기로만** 준다(`InternalPortalController::280`, board `portal/index.blade.php:2506` 표시 중). **board → ERP 쓰기 경로는 없다** — 선례 = `POST /vehicles/{id}/remitter`(§14-2, won 뒤 ERP 차량이 있을 때 전용 전송). ⇒ **ERP 작업 필요(car-erp 세션, Jin 승인 뒤 요청) — 2건**: ① `POST /vehicles/{id}/stock-location` {stock_location, stock_location_note} — 값은 `stockLocations()` 검증, 본인 차(§2 스코프), 감사 ② ERP 차량이 아직 없는 차(won 전)는 연동 B payload 에 `stock_location`·`stock_location_note` 동봉 → 수신측 fill(§12 권위 갱신).
   - **찍는 시점(board 제안, Jin 결정)**: 요청 보낼 때 위치 + note 「탁송 요청 · 업체 · 예정일」 → 배차완료 때 note 를 「배차완료 · 기사 · 일시」로 갱신(위치는 그대로). 거절·취소는 ERP 를 되돌리지 않는다(사람이 ERP 에서 고침). ERP 전송 실패는 탭에 빨간 줄 + 재시도 버튼, 감사 행.
   - 값 목록 = **board 설정**(`config/board.php` `stock_locations` = 홈플·화물·야드, Jin 2026-11-12 「karaba 는 board 가 없으니 상관없다」). karababoard 를 만들 때 그 인스턴스 설정(`instances.*`)에 쇼링·항입고·야드를 넣는다 — ERP 조회 신설 안 함. ERP 쪽 저장 검증은 그대로 `stockLocations()`.
3. **배차완료·거절도 알림톡으로**(영업담당자 + 그 영업의 관리 — 2번 수신자와 같은 사람들, 업체 제외). 종·웹푸시에 **더해** 보낸다. 템플릿 1개가 완료·거절을 `#{결과}`·`#{사유}` 로 덮는다(클레임 결재 결과와 같은 방식, 승인이면 사유 「—」).
   - 코드 `board_transport_result` · 템플릿명 「탁송 배차 결과」 · BA · 008002 · 변수 `#{차량번호}` `#{결과}` `#{업체}` `#{처리자}` `#{처리일시}` `#{사유}` · 버튼 없음. **엑셀 생성 완료 2026-11-12** = `Desktop\알림톡\{헤이맨확정알림톡,싼카확정알림톡}\upload_board_{헤이맨,싼카}_board_transport_result_신규.xlsx`.
```
[배차 결과] #{차량번호}

담당자님, 요청하신 #{차량번호} 차량의 탁송 배차 결과를 안내드립니다.

■ 결과: #{결과}
■ 업체: #{업체}
■ 처리: #{처리자} · #{처리일시}
■ 사유: #{사유}

사내 업무 시스템(board) 매입예정 탁송 탭에서 상세를 확인하고 다음 단계(매입잔금 요청 등)를 진행해 주세요.
요청한 영업담당자와 담당 관리자에게 발송됩니다.
```
   - `#{처리자}` = 기사 이름(페이지에서 적으면) 또는 전화 가운데 마스킹. `#{사유}` 거절 사유 없으면 「—」(빈 변수 금지).
4. §8 데이터 반영: `transport_requests` 에 `stock_location`(string 20)·`erp_location_synced_at`·`erp_location_error` 추가 · 사진 상한 5.
5. **ERP 에 이미 찍힌 보관 위치는 board 가 그대로 받는다**(Jin 2026-11-12 4차 「기본」). 확인한 사실: ERP 는 `/inventory` 4분류 행 전부에 `stock_location`·`stock_location_note` 를 싣고(car-erp `InternalPortalController::280`), board 포털 재고 탭은 이미 그걸 표시한다(`portal/index.blade.php:2506`). 그러나 **차량 1대를 id 로 읽는 경로는 없다**(board `vehicleMeta()` 는 `/sales` 전량을 받아 고르는 우회 — 판매 전 차엔 안 맞음). ⇒ 탁송 탭 설계: **ERP 가 권위, board 는 복사본을 진실로 안 쓴다** — 탭을 열 때 ERP 현재값을 읽어 미리 찍어 두고 「ERP 현재: 야드 · 11-10 김관리」로 보여 준다. 사용자가 다른 값을 고르면 **덮어쓰기 확인창**(「ERP 에 야드로 적혀 있습니다. 홈플로 바꿀까요?」) 뒤에만 ERP 에 쓴다. ERP 차량이 없는 차(won 전)는 board 값만 들고 있다가 연동 B 로 실어 보낸다. ⇒ ERP 요청에 **③ `GET /vehicles/{id}/stock-location`**(현재값·메모·마지막 변경 시각/사람) 추가 — 또는 ① 쓰기 응답에 `previous` 동봉 + 같은 경로 GET. (board 가 `/inventory` 를 4번 긁어 찾는 우회는 안 한다.)
6. **매입잔금 알림톡에 배차정보 동봉 — 템플릿 재승인 불필요, ERP 코드 한 줄**(Jin 4차 「확인 필요」 → 확인함). ERP `erp_board_request` 템플릿 본문은 고정문 + `#{요청내역}` 한 변수이고(`AlimtalkTemplates.php:412~`), `#{요청내역}` 은 ERP 코드가 줄을 모아 만든다(`BoardRequestController::255~281` — 구분·차량 / 금액 / 계좌 줄). 여기에 **note 가 있으면 「  배차: 위카 · 김기사 010-****-1234 · 11-13 08:41」 줄 하나를 더 넣는 것은 변수 내용 변경이라 BizM 승인 대상이 아니다**(고정문 불변). 총 길이 1000자 안. 지금은 note 가 저장만 되고 본문에 안 실린다 → **ERP 요청 ④**: note 를 `#{요청내역}` 에 한 줄로 동봉(모든 §11 매입 신호 공통 — 자동 전송이든 수동이든 같은 경로). §6-6 「v1 안 함」은 이 결정으로 **뒤집음**(Jin 2026-11-12 4차).

### ERP 요청 묶음 (Jin 승인 뒤 car-erp 세션에 보낼 것 — 4건)
① `POST /vehicles/{id}/stock-location` {stock_location, stock_location_note} — `stockLocations()` 검증·본인 차·감사·응답에 `previous` ② 연동 B payload `stock_location`·`stock_location_note`(수신측 fill, §12 권위 갱신) ③ `GET /vehicles/{id}/stock-location` {stock_location, note, updated_at, updated_by_name} ④ §11 `note` → `#{요청내역}` 한 줄 동봉(템플릿 불변).

## 12. 구현 (2026-11-12 dev) · 로컬 시험 절차
- 코드: 마이그 `2026_11_12_000001_create_transport_tables`(5표) · 모델 `TransportCompany/Phone/Request/Recipient/File` · `TransportService`(생성·예약·발송·결정·알림·매입잔금·ERP 위치) · 명령 `board:transport-send`(매분)·`board:transport-demo`(local) · 공개 페이지 `TransportDecisionController` + `resources/views/transport/{show,result}` · 드로어 탭 `livewire/transport/tab`(중첩, `?edit=<id>` 로 열림) · 업체 관리 `/transport-companies`(`livewire/transport/companies`, 관리 이상) · 종 패널 탁송 결과 · 기능설정 tmplId/토글 2쌍 · 템플릿 2종(`AlimtalkTemplates`, DEFAULT_OFF) · lang `transport.php` ko/en · 테스트 `tests/Feature/TransportTest.php`.
- ERP 요청 4건(§11 끝)은 car-erp 세션에 전달(11-12). board 는 ①③ 없이도 돌아간다(탭 「ERP 연동 대기」, 전송 실패 행 + 재시도).
- **로컬 시험**(알림톡은 tmplId 없이 skipped 로 남고 흐름은 끝까지):
  1. `php artisan board:transport-demo --phone=010-xxxx-xxxx`(업체 2개·번호 시드, 로컬 계정 전화)
  2. `/listings` 드로어 → 「탁송 배차」 탭 → 업체·주소·도착 위치·사진 ≤5 → [배차 요청 보내기]. 예약이면 `php artisan board:transport-send` 1회.
  3. 이력의 업체 수신자 옆 **[링크 열기]**(시스템관리자만) → 업체 페이지 → [배차완료]/[배차거절]. 두 번째 번호로 또 열면 「이미 처리됨」.
  4. 상단 종 → 「탁송 배차」 항목(새 배지) → 드로어 탁송 탭 → [매입잔금 요청](로컬 ERP 가 떠 있으면 §11 로 감, note 에 배차 정보).
  5. 감사 = `/audit` 변경이력에서 `transport_*` 행, 알림톡 = `/admin/alimtalk-logs`(skipped).

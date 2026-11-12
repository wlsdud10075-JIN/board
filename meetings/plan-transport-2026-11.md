# 탁송 배차 요청 — 기획 정본 (2026-11-12 Jin 착수)

> 상태 = **초안 · Jin 결정 대기(§6)**. 결정이 닫히면 이 문서가 정본이고 규칙은 `SKILLS.md §14-32` 로 옮긴다. 코드 0.
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

## 6. Jin 결정 필요 (번호로 답해 주세요)
1. **대상 상태** — 탁송 탭이 열리는 상태. 제안 = `won`·`synced`(차를 산 뒤)만. `accepted`(셀프검차는 등록 즉시 accepted)도 열까?
2. **수신자** — 업체 선택 시 그 업체 번호 **전부**에 보내는가, 그중 고르는가. 임의 번호 1개 추가 허용? 제안 = 전부 + 임의 1개 추가 허용. n명이 받으면 **먼저 [배차완료] 누른 사람이 확정**, 나머지는 「이미 처리됨」.
3. **사진 2장의 정체** — 차량 외관인가, 서류(등록증 등)인가. 서류면 마스킹본 규칙 + 공개 페이지에 소유자명·계좌 **절대 노출 금지**. 링크 만료 제안 = 5일(클레임과 같음), 사진 임시 URL 만료 = 링크와 동일.
4. **거절·만료 뒤** — 같은 차에 다른 업체로 재요청(회차 +1) 허용? 예약 발송 전 취소·시각 변경 허용? 제안 = 둘 다 허용.
5. **「매입잔금 버튼 자동 활성화」의 뜻** — (a) 완료 종 알림에 [매입잔금 요청] 버튼 / (b) 탁송 탭에 버튼이 생김 / (c) 자동 전송. 제안 = **(a)+(b), 전송은 사람이 누름**((c) 는 금액 필수·자동계산 금지 규칙과 충돌). 지급대기가 아닌 차(이미 지급)면 버튼 숨김.
6. **배차정보를 ERP 알림톡에도** — §5 대로 ERP 변경이 필요. v1 에서 car-erp 세션에 요청할까(「`#{요청내역}` 에 note 한 줄」), 아니면 v1 은 ERP 드로어 표시만으로 가고 알림톡 동봉은 2차?
7. **업체 관리 권한** — 제안 = 관리 role 이상(관리·업무관리자·최고관리자·super). 업체 코드를 ERP 탁송비 파서 이름과 맞출지(선택).
8. **요청 권한** — 제안 = 기존 드로어 게이트 그대로(본인 매물 영업 · 공유받은 관리 · 업무관리자 이상).
9. **템플릿 본문** — §7 확정안으로 BizM 제출(Jin, 프로필 2개). 개발과 병행.

## 7. 알림톡 템플릿 확정안 (BizM 제출용 · 변수·버튼은 코드와 글자 단위 일치)
- 코드 `board_transport_request` · 카테고리 = 사내 업무/배차 요청 · 변수 = `#{차량번호}` `#{주소}` `#{요청회사}` `#{요청일시}` · 버튼 1개 WL 「배차 확인」(`#{url}`).
```
[배차 요청] #{요청회사}

#{차량번호} 차량의 탁송 배차를 요청드립니다.
차량 위치: #{주소}
요청: #{요청일시}

아래 버튼에서 차량 사진과 위치를 확인하시고 배차 가능 여부를 알려 주세요.
```
- 주소 없으면 `#{주소}` = 「페이지에서 확인」. 사진 2장·「배차신청 요청드립니다」 문구는 페이지에.
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

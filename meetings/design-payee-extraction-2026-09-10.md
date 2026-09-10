# 첨부사진 계좌 자동추출 — 설계 (2026-09-10)

> 상태: **✅ 2026-09-10 두 박스 운영 배포·가동 완료** (master `3d1d05e`, `BOARD_PAYEE_EXTRACT_ENABLED=true`).
> ⚠️ 배포 후 실사용에서 나온 수정은 **`SKILLS.md §14-15` 가 정본**이다 — 이 문서는 설계 시점 기록으로 둔다.
> 주요 변경: 평생계좌를 버리지 않음(`phone_like` 경고) · 프롬프트 이중 부정 제거 · [📷 계좌정보 찾기] 버튼 ·
> `payee_extraction_at` 5분 타임아웃 · [닫기] 버튼 분리.
> 관련: `SKILLS.md §14-13`(첨부 저장 순서·구매확정 첨부 필수) · `§12`(연동 B payload) · `CLAUDE.md` 입금정보 절

## 1. 왜 하는가 — 실측 근거

heymanboard 운영 데이터(`synced` 31대, 첨부 140장) 실사:

- `payee_account` 기재 **6/31(19%)**, `selling_fee_payee_account` **0/31(0%)**
- 표본 12대 중 **9대(75%)의 사진에 계좌가 있었다**
- 계좌가 **비어 있던** 6대 중 **5대**는 사진에 계좌가 있었다 = 정보는 board 에 들어와 있는데 사람이 안 옮겨 적었다
- 기재된 4대는 사진 속 계좌와 **자릿수·끝3자리가 일치** = 영업이 그 사진을 보고 손으로 타이핑한 것

즉 이 기능은 새 정보를 만드는 게 아니라 **이미 board 에 있는 정보를 옮기는 일**을 대신한다.

### 계좌가 비면 무슨 일이 생기나 — §11 입금요청 신호가 반쯤 죽는다 (2026-09-10 Jin 지적)

계약금·매입잔금 **입금요청 알림톡 본문에 계좌가 전체로 실린다.** car-erp `BoardRequestController::payeeLine()` 이
`은행 + 계좌번호 + (예금주)` 를 `#{요청내역}` 에 조립한다 — 마스킹 없음. **계좌가 비어 있으면 `계좌 미등록` 이 찍혀 나간다.**

> car-erp 주석: *빈 줄이면 받는 사람이 계좌를 못 찾아 결국 카톡으로 되묻는다(기능의 목적이 무너진다)*

board 기재율이 19% 이므로 **지금 그 알림톡의 대부분이 「계좌 미등록」으로 나가고 있을 것**이다.
카톡 되묻기를 없애려고 만든 §11 신호가 계좌가 없어서 무력화된 상태다.
⇒ 이 기능은 연동 B 뿐 아니라 **§11 입금요청을 원래 의도대로 작동시킨다. 알림톡 쪽 추가 작업은 0**
(ERP 가 이미 계좌를 읽어 싣는다 — board 가 `payee_*` 를 채우기만 하면 따라온다).

ℹ️ **판매대금확인 신호엔 계좌를 안 싣는다**(방향이 반대 — 돈이 들어온 걸 확인하라는 신호에 매입처 계좌가
찍히면 받는 사람이 거기로 보낼 수 있다). 딜러(외부) 수신 `erp_purchase_paid` 는 **뒤 4자리만** — 둘 다 의도된 설계이므로 건드리지 않는다.

### 전달 경로 정리 — 계좌는 사내에서 **전체가** 보여야 한다 (Jin 확인)

| 경로 | 계좌 표시 | 상태 |
|---|---|---|
| 연동 B payload → ERP 매입탭 「정산계좌」 | **전체** (`purchase_seller_*`, 수신측 암호화 저장) | ✅ 이미 동작, 추가 작업 0 |
| 입금요청 알림톡(계약금·매입잔금) | **전체** (`payeeLine()`) | ✅ 이미 동작, 추가 작업 0 |
| `integration_events` 감사로그 | `***` 마스킹 | 기존 정책 유지 — **전송 본문은 실값** |

### 파일럿 결과 (실제 운영 경로: heymanboard → 태일넷 → gpu-office:11434)

| 항목 | 값 |
|---|---|
| 모델 | `qwen2.5vl:7b` 단독 상주, 1100px, `num_ctx` 4096 |
| 속도 | 9장 총 24초 (장당 1.5~5.8초, 평균 2.7초) |
| 계좌번호 판독 | **8/9 정확** |
| 두 계좌(차값/매도비) 분리 | ✅ 성공 |
| 90도 회전 통장 · 문자 속 사진(중첩) | ✅ 통과 |
| 실패 1건 | 전화번호를 계좌로 (→ §5 후처리로 차단) |

⚠️ **1100px/4096 은 이 카드(RTX 2070 8GB)의 상한이다.** 1500px 는 비전 토큰 3300~4100 으로 4096 을 넘겨
HTTP 400(`exceed_context_size_error`)이 난다. GPU 증설 시에만 열린다(§9).

⚠️ **VRAM 여유가 성능을 지배한다.** 같은 이미지가 여유 26MiB 에서 45.4초, 448MiB 에서 2.0초였다.
`ollama ps` 의 `100% GPU` 는 **가중치 위치만** 말하고 연산 버퍼는 안 보여준다 — 느려도 폴백으로 안 잡힌다.

---

## 2. 흐름 — 2단계 드로어 (현재 구조를 그대로 쓴다)

`/auction` 드로어에는 이미 **첨부만 저장하는 경로**(`savePayee()`, 상태 변경 없음)와
**구매확정 경로**(`confirmWon` 계열, 첨부 저장 후 `status=won` → 연동 B 발사)가 따로 있다. 이걸 그대로 쓴다.

```
① 영업이 드로어에서 사진 5~7장 선택 → [입금정보 저장]
      → storeSalesFiles() 로 S3 저장
      → ExtractPayeeFromPhotos Job dispatch   (payee_extraction_status = pending)
      ↓ 장당 2~3초, 5~7장이면 15~20초
② 드로어에 "계좌 후보" 블록 표시 (근거 사진 썸네일 + 은행·계좌·예금주)
      → 영업이 [차값에 적용] / [매도비에 적용] / [무시]
      → 적용하면 payee_* 입력칸이 채워짐 (아직 저장 아님, 사람이 눈으로 확인)
      ↓
③ [입금정보 저장] → payee_* 확정 (감사로그 기록)
      ↓
④ [구매확정] → status=won → 연동 B 로 계좌까지 실려 나감
```

### 🚨 지켜야 할 불변식

**연동 B 는 추출이 진행 중일 때 발사되면 안 된다.** 발사 후에 제안이 도착하면 그 차의 계좌는 영영 ERP 에 없다
— car-erp 의 fill-if-empty 는 **판매 필드 전용**이고 `payee_*` 를 안 채운다(§12 확인 완료). 재전송 경로도
`hasSyncableAmount()` 기준이라 계좌만으로는 안 돈다.

그래서 **구매확정 경로**에 가드를 하나 넣는다:

- 이번 저장에서 **새 사진이 올라왔고** `payee_account` 가 비어 있으면 → 확정을 막고
  `"계좌 후보를 찾는 중입니다. 잠시 후 확인해 주세요"` 표시 (`payee_extraction_status = pending`)
- 추출이 끝나면(`done`/`failed`) 확정 가능
- **[계좌 없이 확정] 버튼을 같이 둔다** — 계좌는 원래 필수가 아니다. 급한 차를 막아 세우지 않는다.

> 이 가드는 §14-13 과 같은 계열의 문제다(첨부가 status 저장보다 늦어 payload 에서 빠졌던 것).
> 거기선 순서를 바꿔 해결했지만, 추출은 15~20초짜리라 순서로는 못 푼다 → 상태 플래그로 막는다.

---

## 3. 데이터 모델

`purchase_listings` 에 컬럼 2개 추가 (마이그레이션 1개):

| 컬럼 | 타입 | 용도 |
|---|---|---|
| `payee_suggestions` | `text` nullable, cast **`encrypted:json`** | 추출 후보. 확정하면 비운다 |
| `payee_extraction_status` | `string(12)` nullable | `pending` / `done` / `failed` / `none` |

🚨 **제안값도 암호화한다.** 확정 전이라도 실제 계좌번호다 — `payee_account` 가 `encrypted` 인 것과 같은 이유.
평문 JSON 으로 두면 DB 덤프·백업에 계좌가 그대로 남는다.

### `payee_suggestions` 구조

```json
{
  "extracted_at": "2026-09-10T13:20:00+09:00",
  "candidates": [
    {
      "role": "car",                  // car | fee  (§5 라우팅 규칙으로 결정)
      "bank": "신한은행",              // koreanBanks 로 정규화된 값
      "number": "140-014-011460",
      "holder": "(주)수호모터스",
      "source_photo_ids": [173],      // 근거 사진 (여러 장에 같은 계좌면 합침)
      "warnings": ["owner_mismatch"]  // 비었으면 경고 없음
    }
  ],
  "rejected": [                        // 후처리로 버린 것 — 디버깅·감사용
    {"number": "031-296-5454", "reason": "phone_pattern", "source_photo_id": 212}
  ]
}
```

`payee_suggestions` 는 **`payee_*` 를 절대 직접 안 건드린다.** 사람이 [적용]을 눌러야 입력칸에 들어간다.

---

## 4. Job 스펙 — `App\Jobs\ExtractPayeeFromPhotos`

- 트리거: `storeSalesFiles()` 가 **새 사진을 1장 이상 저장했을 때** (savePayee·구매확정 두 경로 모두)
- 대상: 그 listing 의 `kind=sales_photo` 전체 (서류는 제외 — 등록증은 사진 kind 로 올라온다)
- 큐: 운영 `database`(supervisor `board-worker.conf` 로 워커 상주 중, 실측 확인), 로컬 `sync`
- 재시도: `tries=2`, 실패 시 `payee_extraction_status='failed'`
- 이미지 준비: **최대변 1100px 리사이즈 + JPEG q80** (GD, 파일럿과 동일 파이프라인)
- 호출: `OllamaClient` 에 **`vision()` 메서드 추가**해서 재사용 (새 클라이언트 만들지 않는다)
  - `POST /api/generate`, `model` = config, `images:[base64]`, `options: {temperature:0, num_ctx:4096}`
  - URL 은 **`ASSISTANT_OLLAMA_URL` 재사용** (두 번째 URL 변수를 만들지 않는다)
- 장당 1회 호출 · 순차 (동시 호출 금지 — 8GB 카드에 여유가 448MiB 뿐이다)

### 프롬프트 출력 스키마

```json
{"found":true,"car_account":{"bank":"","number":"","holder":""},
 "fee_account":null,"registration_owner":"","note":""}
```

`registration_owner` = 자동차등록증·사업자등록증이 찍힌 장에서 읽은 **소유자/상호**.
§5 의 예금주 대조에 쓴다. 자동차등록증은 거의 모든 묶음에 들어 있다(실사 확인).

---

## 5. 후처리 — 안전장치는 코드가 한다 (모델이 아니라)

프롬프트에도 같은 규칙을 넣지만, **판정은 PHP 가 한다.** 파일럿에서 프롬프트 규칙만으로는 전화번호를 못 막았다.

| # | 규칙 | 처리 |
|---|---|---|
| 1 | `^0\d{1,2}-` (02-/031-/010) · `^1[56]\d{2}-` (1566/1588/1544) | **버린다** (`rejected` 에 기록) |
| 2 | 하이픈 제거 후 숫자 **10~17자리**가 아니면 | 버린다 |
| 3 | 숫자 외 문자가 섞이면 | 버린다 |
| 4 | 은행명 정규화 — `WOORIBANK`→`우리은행`, `IBK 기업은행`→`IBK기업은행` 등 `Alpine.store('koreanBanks')` 목록에 매핑 | 매핑 실패 시 은행만 비우고 계좌는 살린다 |
| 5 | **라우팅**: 모델이 `fee_account` 를 냈고 **그 장의 텍스트에 매도비/이전비/수수료/알선 라벨이 있을 때만** `fee` | 라벨 없으면 **무조건 `car`** |
| 6 | 예금주 ↔ `registration_owner` 불일치 | 버리지 않고 `warnings: ["owner_mismatch"]` — **UI 에 경고 표시** |
| 7 | 여러 장에서 같은 계좌(하이픈 무시 비교) | 한 후보로 합치고 `source_photo_ids` 에 모두 기록 |

> 규칙 5 의 근거: 파일럿에서 라벨 없는 통장사본 2건을 모델이 매도비로 넣었다.
> 차값 계좌를 매도비로 잘못 넣으면 **차값이 안 나간다** — 라벨이 있을 때만 fee 로 보낸다.

---

## 6. UI — `/auction` 드로어 계좌 칸 위

```
┌─ 계좌 후보 (사진에서 읽음) ─────────────────────┐
│  [썸네일]  신한은행  140-014-011460            │
│            (주)수호모터스                       │
│            [차값에 적용]  [매도비에 적용]  [무시] │
├────────────────────────────────────────────────┤
│  [썸네일]  신한은행  140-013-925020   매도비    │
│            (주)수호모터스                       │
│            [차값에 적용]  [매도비에 적용]  [무시] │
├────────────────────────────────────────────────┤
│  ⚠ 예금주가 등록증 소유자와 다릅니다 — 확인 필요  │
└────────────────────────────────────────────────┘
        ※ 확인 후 [입금정보 저장]을 눌러야 반영됩니다
```

- **썸네일 = 근거 사진.** 영업이 3초 만에 대조할 수 있어야 한다(7장을 다시 뒤지게 하면 안 쓴다)
- 추출 중: `"계좌 후보를 찾는 중… (약 20초)"` + `wire:poll.5s`
- 후보 없음: 블록 자체를 안 그린다 (지금과 동일하게 손으로 입력)

---

## 7. 실패 모드

| 상황 | 동작 |
|---|---|
| GPU PC 꺼짐 · 태일넷 끊김 · 모델 언로드 | Job 실패 → `failed` → 블록 안 뜸 → **손으로 입력(현행과 동일)**. 구매확정은 풀린다 |
| 계좌가 사진에 없음 | `found:false` → `none` → 블록 안 뜸 |
| 후처리가 전부 버림 | `done` + `candidates:[]` → 블록 안 뜸, `rejected` 는 남김 |
| 모델이 헛것을 읽음 | 사람이 [무시]. **자동 기입이 없으므로 DB 에 안 들어간다** |

- 로그: `integration_events` 에 `outbound/ollama/payee_extract` 로 append (성공·실패 모두)
- 🚨 **계좌번호는 로그에 남기지 않는다** — `BoardAudit::MASKED` 와 같은 방식으로 `***`
- 실패가 조용히 쌓이지 않게 `board:assistant-health` 에 항목 추가 검토(별건)

---

## 8. 테스트 (PHPUnit, sqlite :memory:)

- `test_phone_number_candidate_is_rejected` — 전화번호는 후보에 안 들어간다
- `test_no_fee_label_routes_to_car_account` — 라벨 없으면 매도비로 안 간다
- `test_won_is_blocked_while_extraction_pending` — 추출 중 구매확정 차단
- `test_won_is_allowed_when_extraction_failed` — 실패는 막지 않는다
- `test_won_is_allowed_with_skip_flag` — [계좌 없이 확정] 경로
- `test_applying_suggestion_writes_payee_and_clears_suggestion` — 적용 시 감사로그(마스킹) 기록
- `test_job_failure_leaves_payee_null` — 실패해도 기존 값 안 건드림
- `test_duplicate_account_across_photos_is_merged` — 중복 합치기
- OllamaClient 는 컨테이너 fake 바인딩 (assistant 테스트와 같은 방식, HTTP 미발생)

---

## 9. 배포 체크리스트

- [ ] 마이그레이션 1개 (컬럼 2개)
- [ ] `.env` **두 박스** 추가: `BOARD_PAYEE_EXTRACT_ENABLED`, `BOARD_PAYEE_EXTRACT_MODEL=qwen2.5vl:7b`,
      `BOARD_PAYEE_EXTRACT_MAX_PX=1100`, `BOARD_PAYEE_EXTRACT_NUM_CTX=4096`
      ⚠️ `.env` 변경 시 **Desktop\AWS 백업 동기화**(메모리 규칙) · `config:cache` 는 **ubuntu 로만**
- [ ] lang 키 **ko·en 양쪽** (`auction.payee_extract.*`)
- [ ] `npm run build` (새 Tailwind 클래스)
- [ ] **ssancarboard 도 같은 코드로 배포된다** — GPU PC 는 **한 대를 두 박스가 공유**한다.
      지금 물량(하루 1건 미만)에선 문제없지만 동시 호출이 겹치면 순번이 밀린다. 용량 메모.
- [ ] gpu-office 는 **추가 작업 없음** — `qwen2.5vl:7b` 단독 상주가 이미 운영 구성이다

### gpu-office 현재 상태 (2026-09-10, 파일럿 후 그대로 유지)

- 상주 = `qwen2.5vl:7b` 단독 (재부팅해도 복귀 — `warmup-ollama.ps1` 의 `$genModel`/`$embModel`)
- `qwen3:8b`·`bge-m3` 언로드 / 3사 ERP 챗봇 앱 레벨 off (Jin)
- 03:00 색인 정지 (`llm-poc\SYNC-PAUSED` 파일 — 지우면 재개). 서버 5대 색인은 09-10 03:01 판에서 고정
- **되살릴 때 순서 = 색인 재개 → 1회 실행 → 챗봇 on**
- 🚨 8GB 에서 **비전 모델과 챗봇은 공존 불가**. 챗봇을 되살리면서 이 기능을 유지하려면 **GPU 증설(16GB 최소선)**

---

## 10. 이번 범위 밖 (명시)

- ⛔ **자동 기입 없음.** 신뢰도가 아무리 높아도 사람이 확인한다
- ⛔ 이미 ERP 로 넘어간 25대 **소급 안 함** (Jin 지시)
- ⛔ 1500px — GPU 증설 전까지 안 쓴다. `max_px` 를 config 로 빼둬서 증설 시 한 줄 변경
- ⛔ 예금주 자동 확정 — 파일럿에서 오독 1건(`성진`→`성원`). **계좌번호만 신뢰**하고 예금주는 참고값
- ⛔ 서류(`sales_document`) 판독 — 사진 kind 만 본다

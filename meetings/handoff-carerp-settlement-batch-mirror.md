> ## ✅ 2026-08-31 회신 완료 — car-erp master `488e597` 배포됨 (3사 정상 응답)
> **권위 = car-erp `docs/integration/board-portal-api.md` §13.** 아래 본문은 *요청 당시의 경위*로 남긴다.
> ⚠️ 회신으로 **뒤집힌 곳**: ①§3 "`net_payout` 을 그대로 표시" → 합계식은 **Σ`net_payout` + Σ`unbatched_paid`**
> (배치 밖 지급이 예외가 아니라 **본류** — ssancarerp 는 paid 전량이 배치 밖·승인 배치 0건) ②§4 질문 = **답 나옴**
> ③`throttle:300,1` → 실제는 **분당 120, prefix 전체 공유 버킷** ④§6 "조정 미반영 각주" = 지금은 없음(조정이 들어온다).
> 현재 상태는 `CLAUDE.md` 「남은 작업」 항목을 볼 것.

# 인계 — car-erp 세션: 정산 **월배치(승인분) 미러** 읽기 API

> **from**: board 세션 (2026-08-31) / **to**: car-erp 세션
> **요청자**: Jin — *"내 정산/미수(포털) 요약에서 본인이 정산받은 내용을 월별로 펼치면 상세가 나왔으면.
> ERP 월 배치를 보면 알 수 있으니, **승인된 월배치를 그대로 미러**로. 본인은 본인 것만."*
>
> board 측 화면은 이미 dev 에 있다(요약 탭 월 행 클릭 → 그 달 정산 상세 펼침, 모바일 카드 포함).
> 지금은 기존 `GET /settlements` 로 **차량별 정산액을 합해서** 그리는 임시 형태다 — 아래 이유로
> **그 숫자는 통장 입금액과 다를 수 있다**. 이 API 가 오면 board 는 데이터 소스만 갈아끼운다.
> ⛔ **board 는 이 문서를 근거로 car-erp 를 직접 고치지 않는다**(크로스레포 규칙). 구현·커밋은 car-erp 세션에서.

---

## 1. 왜 배치여야 하는가 (임시 구현으로는 안 되는 이유)

`settlement_payout_adjustments` 마이그 주석 그대로 —
**"개별 차량 정산은 무손상, 배치 총액에만 반영"**.

즉 **환수(−)·특별 인센티브(+)가 있었던 달은, 차량별 `actual_payout` 을 아무리 정확히 합해도
영업이 실제로 받은 금액과 다르다.** board 는 조정의 존재조차 모른다.

부수 효과로 하나 더 해결된다: 현재 `GET /settlements` 는 **상태 필터가 없어** `confirmed`(확정했지만
아직 지급 전)까지 섞여 온다. board 요약의 「정산 실지급」에 **아직 받지도 않은 건이 이미 들어가 있다**
(숫자 하나일 땐 안 보였고, 펼치는 순간 드러난다). 배치를 미러하면 *"승인돼서 실제로 나간 묶음"* 만
보게 되므로 이 혼선이 원천적으로 사라진다.

---

## 2. 요청 — `GET /api/internal/board/payout-batches`

기존 재무 읽기 API 와 **같은 prefix·같은 미들웨어**(`VerifyBoardReadHmac`, `throttle:300,1 by(salesman_email)`).
쿼리 = `salesman_email` 하나(기존 해소 로직 그대로). 새 개념 없음.

```json
{
  "count": 2,
  "data": [
    {
      "batch_id": 12,
      "month": "2026-07",                 // 귀속월 (batch.month) — ERP 화면과 같은 라벨
      "status": "approved",
      "decided_at": "2026-08-10",
      "settlements": [
        {"vehicle_number": "11가1111", "actual_payout": 1000000, "paid_at": "2026-08-10"}
      ],
      "adjustments": [
        {"amount": -300000, "reason": "과지급 환수"}
      ],
      "settlement_total": 1000000,
      "adjustment_total": -300000,
      "net_payout": 700000                // ⭐ 이 사람이 이 배치로 실제 받은 금액
    }
  ],
  "unbatched_paid": [                      // §4 참고 — 배치를 안 거친 지급분
    {"vehicle_number": "44라4444", "actual_payout": 800000, "paid_at": "2026-06-15"}
  ]
}
```

### 스코프 — 여기가 제일 중요합니다

- `settlements[]` = 그 배치 소속 정산 중 **`salesman_id` = 해소된 영업 본인 것만.**
- `adjustments[]` = 같은 배치의 조정 중 **`salesman_id` 일치분만.**
- 🚫 **`batches.total_payout`·`settlement_count`(배치 전체 스냅샷)를 그대로 실어 보내지 말 것.**
  그건 **전 영업 합계**다 — 내보내는 순간 board 영업이 남의 정산 총액을 본다.
  `settlement_total`·`adjustment_total`·`net_payout` 은 **본인 행만 재집계**한 값이어야 합니다.
- 본인 행이 하나도 없는 배치는 응답에서 **제외**(빈 배치가 목록에 뜨면 "왜 0원이지"가 된다).

### 상태 필터

- **`status = approved`(최종 승인)만.** `pending`·`rejected`·`cancelled` 는 제외.
  승인 전 금액이 영업에게 보이면 확정으로 오해하고, 반려되면 "받기로 한 돈이 사라졌다"가 된다.
- 승인 사다리 중간 단계(누가 몇 번째 서명했는지)는 **board 에 불필요** — 넣지 말 것.

### PII·노출 판단 (car-erp 몫)

- 마진 raw 는 기존 §3 그대로 **금지**. `actual_payout` 만.
- **`adjustments[].reason` 텍스트 노출은 car-erp 가 판단해 주세요.** 환수 사유에 사건·사람이 적힐 수
  있습니다. 안 주기로 하면 board 는 금액만 표시합니다(`reason` 없으면 「조정」으로만 렌더 — degrade 됨).
  본인 조정이라 본인이 보는 건 문제없다고 보지만, 문구를 누가 썼는지가 걸리면 빼는 쪽이 안전합니다.

---

## 3. board 가 이 값으로 하는 일 (참고 — board 세션이 구현)

요약 탭 월별 표에서 월 행을 펼치면 **그 배치 내용 그대로**:
차량별 실지급액 목록 → 조정 줄(+/−) → **최종 수령액(`net_payout`)**.
board 는 **재계산하지 않는다** — `net_payout` 을 그대로 표시합니다(합이 안 맞으면 ERP 값이 정답).

⚠️ **월 축이 바뀝니다.** 현재 board 월별 표는 **지급일(`paid_at`) 월**로 묶습니다
(`board-portal-api.md §4` — "4월 일한 분 = 5/10 지급 → 5월"). 배치는 **귀속월(`month`)** 입니다.
board 는 펼침 상세에 **「2026-07월분 배치 (2026-08-10 지급)」** 식으로 두 날짜를 같이 찍어
혼동을 막을 예정입니다. car-erp 는 `month` 와 `decided_at`(또는 대표 `paid_at`) 을 **둘 다** 주세요.

---

## 4. ⚠️ 확인 요청 — 배치를 안 거친 지급이 실재하나요

`Settlement` 가드 주석에 **"직접 paid 는 대표(admin/super)만, manager·[관리] 는 배치로만"** 이라고
돼 있습니다. 즉 **대표가 직접 paid 로 넘긴 정산은 `payout_batch_id` 가 null** 일 수 있습니다.
배치만 미러하면 **그 건은 board 에서 통째로 사라집니다**("나 이거 받았는데 왜 없지").

- 운영 DB 에 `settlements where settlement_status='paid' and payout_batch_id is null` 이
  **실제로 존재하는지** 두 박스(heymanerp·ssancarerp) 각각 확인 부탁드립니다.
- 있으면 위 응답의 **`unbatched_paid[]`** 로 같이 내려주세요(같은 본인 스코프). board 는 그 건을
  `paid_at` 월에 「배치 외 지급」으로 따로 찍습니다.
- 없다면 그 키는 생략해도 됩니다 — board 는 없는 키를 안 그립니다(degrade).

---

## 5. 배포 순서 · 가드

1. **car-erp 먼저** — 엔드포인트 없으면 board 는 기존 임시 화면(차량별 합계 + "조정 미반영" 각주)으로
   그대로 돌아갑니다. 안전합니다.
2. car-erp 배포 후 board 가 소스 교체 → 각주 제거 → 두 박스 배포.
3. 권위 스펙은 **`docs/integration/board-portal-api.md` 에 새 절**로 적어 주세요(이 인계문서를 복사하지 말 것 — drift).
4. 테스트 제안(car-erp): ① 다른 영업의 정산·조정이 응답에 절대 안 섞이는지 ② `total_payout`(전체 스냅샷)이
   응답 어디에도 없는지(정적 검사) ③ pending·rejected 배치가 안 나오는지 ④ `net_payout` = 본인 정산합 + 본인 조정합.

---

## 6. board 측 현재 상태 (참고)

- dev 커밋 — 요약 탭 월 펼침 UI(데스크톱 표 + 모바일 카드), 상태 뱃지(지급 완료/확정(지급 전)),
  월 소계 전체·지급확정 분리, "ERP 월배치의 조정은 반영되지 않습니다" 각주.
- **운영 배포 안 함** — 배치 미러가 최종 형태라 그때 한 번에 올립니다(Jin 확인).
- 코드 = `resources/views/livewire/portal/index.blade.php` (`collectSettlements()` / `$monthlySettle`),
  테스트 = `tests/Feature/BoardTest.php::test_monthly_settlement_detail_separates_paid_from_confirmed`.

---

## 7. 2026-11-09 추기 — ⏸ 지급보류 · ➕ 지급추가 미러 (Jin 결정, car-erp 세션 경유)

> 권위 = car-erp `docs/integration/board-portal-api.md §13`(ERP 가 갱신). 여기는 board 쪽 결론만.

- **Jin 결정(2026-11-09)**: ① 사유(`note`) **보낸다**(ERP 입력칸 안내 불필요) ② 실송금 계산 **없음**((가) — 그 달 보류·추가 금액이 보이면 끝, 수령액 라벨 변경·급여 전송 논의 안 함) ③ 화면 = **요약 월 행 「정산」 옆 뱃지만**(ERP 사내직원관리 이름 옆 ⏸/➕ 뱃지처럼, 눌러서 줄 목록). 배치 블록 추가·확정 전 힌트·조정 kind 라벨은 **안 한다**.
- **ERP 가 넓히는 §13 응답**(배치마다, cancelled 제외): `holds[]`/`extras[]` = `{month, amount_krw, status, note, released_at}` · `held_total`·`extra_total` · `adjustments[].kind` + `source_months[]` · `finalized_at` · 「정산·조정 없는 배치 제외」 조건 완화(보류·추가만 있는 사람도 배치가 남음). **ERP 먼저 배포.**
- **ERP 사실(car-erp 회신)**: hold `amount_krw` = 지급합계와 같은 부호의 크기(보통 +) · extra 는 +/− 서명값 · 상태 집합 둘 다 `held→queued→released_batch` / `released_paid` / `cancelled`(최종 확정 뒤에도 hold 는 `held` 로 남을 수 있음) · 귀속월 = 항상 소속 배치 `month` · `kind` 는 string(16)(닫힌 ENUM 아님). **ssancarerp 실측** = 승인 month 배치 3건(08~10) · 급여(`payroll_entries`) 09월 22명·10월 40명 → **ssancarboard `net_payout` 은 이미 그 두 달에서 통장과 다르다**(급여만큼 작음) · extra +3,000,000 1줄 실존. heymanerp = 급여 0·보류 0.
- **board 구현(dev)**: `collectPayouts()` 가 배치의 `held_total`/`extra_total` 을 `$monthly[월]['hold_sum'|'extra_sum']`, 줄을 `hold_lines[]|extra_lines[]` 로 모은다(**settle_sum 불변**, 재합산 없음). 뱃지 = `_settle-hold-badges.blade.php`(데스크톱 셀·모바일 카드 공용, 부호 = ERP 뱃지와 같이 저장값 그대로, `.stop` 으로 행 펼침과 분리, 모르는 status 는 원문). 정산 0건 배치는 `_settle-detail` 이 빈 차량 표를 안 그린다. 가드 = `test_monthly_row_shows_hold_and_extra_badges_without_touching_total` · `test_monthly_row_has_no_hold_badge_when_erp_sends_none`.
- **Jin 확정(2026-11-09, 로컬 더미로 확인)**: 뱃지 합 = ERP `held_total`/`extra_total` 그대로(처리 끝난 줄 포함) · 부호 = 저장값 그대로(ERP 뱃지 미러). 로컬 미리 보기 = `.env` `BOARD_PORTAL_FAKE_PAYOUT_BATCHES` + `database/dev/payout-batches-hold-demo.json`(local 전용 훅, SKILLS §14-29).

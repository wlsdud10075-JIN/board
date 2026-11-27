# 인계 (board → car-erp): 정산 읽기 API에 `paid_at`(실지급일) 노출

> 작성 2026-06-24 (board 세션). **car-erp 세션에서 처리**할 것 — board 는 직접 못 고침(크로스레포 규칙).
> 수신 권위 = car-erp `docs/integration/board-portal-api.md`(읽기 API 계약). 이 인계 = 그 계약에 필드 1개 추가 요청.

## 증상 (Jin 보고)
board 영업 포털 **요약(finance) 탭**의 "월별 — 정산 실지급"이 **6월만** 뜨고, car-erp 에서 **5월/6월로 나눈 실지급**이 안 갈림. car-erp 화면엔 5월/6월이 보이는데 board 엔 6월만.
- 배경: 이 정산지급은 car-erp 에서 **엑셀 업로드**로 입력했고, 그 엑셀 기준으로 5월/6월이 나뉨.

## board 측 진단 (확정 — board 버그 아님)
board 의 월별 집계(`buildMonthly`, `resources/views/livewire/portal/index.blade.php`)는 car-erp `/settlements` 응답의 **`confirmed_at`** 으로 월(YYYY-MM)을 가르고 `actual_payout` 을 합산한다.

실측 (moo@car-erp.test, board→car-erp 실호출):
```
GET /api/internal/board/settlements  →  101건
모든 레코드 confirmed_at = "2026-06-23"   (+ 1건 confirmed_at=null)
→ board 월별 버킷: 2026-06 만 (cnt=101, sum=16,962,769). 2026-05 없음.
```
즉 **board 는 받은 날짜(confirmed_at)대로 정확히 가른 것**이고, car-erp 가 전부 6/23 을 보내서 6월만 나온 것.

## 근본 원인
car-erp `Settlement` 모델엔 날짜가 **둘** 있음 (`app/Models/Settlement.php`):
- `confirmed_at` — 정산 **확정일**(시스템 승인 시각, 여기선 전부 6/23)
- `paid_at` — **실제 지급일** ← Jin 의 5월/6월(엑셀 기준)은 여기 있을 것

그런데 `/settlements` 엔드포인트(`app/Http/Controllers/Api/Internal/InternalPortalController.php::settlements()`)는 **`confirmed_at` 만** 내보내고 `paid_at` 을 안 보냄:
```php
->map(fn (Settlement $s) => [
    'vehicle_number' => $s->vehicle?->vehicle_number,
    'status'         => $s->settlement_status,
    'actual_payout'  => $s->actual_payout,
    'confirmed_at'   => $s->confirmed_at?->toDateString(),
    // ← paid_at 누락
]);
```
"실지급 월"의 의미상 맞는 필드는 **`paid_at`**(실제 지급일)인데 board 가 `confirmed_at`(확정일)을 받고 있어 월이 안 갈린다.

## 요청 (car-erp 세션에서)
1. **`/settlements` 응답에 `paid_at` 추가**:
   ```php
   'paid_at' => $s->paid_at?->toDateString(),
   ```
   (`confirmed_at` 은 그대로 두고 **추가**만. 형식 = `YYYY-MM-DD` 문자열, confirmed_at 과 동일.)
2. **검증**: 엑셀 업로드로 들어간 정산들의 `paid_at` 이 실제로 5월/6월로 채워져 있는지 DB 확인.
   - 만약 엑셀 업로드가 `paid_at` 을 **안 채우고 `confirmed_at` 만** 채웠다면 → 업로드 매핑을 고쳐 엑셀의 지급일이 `paid_at` 에 들어가게 해야 함(그래야 월 분리가 데이터에 존재).
3. **계약 문서 갱신**: `docs/integration/board-portal-api.md` 의 `/settlements` 응답 스키마에 `paid_at` 추가.

## board 측 대응 (이미 반영, 후방호환)
board `buildMonthly` 의 정산 집계를 **`paid_at` 우선, 없으면 `confirmed_at` 폴백**으로 변경:
```php
$bump($svc->settlements($email), ['paid_at', 'confirmed_at'], 'actual_payout', 'settle_cnt', 'settle_sum');
```
→ car-erp 가 `paid_at` 을 **보내기 전**엔 지금처럼 confirmed_at 으로 동작(무변), **보내기 시작하면** 자동으로 실지급일 기준 월 분리. **car-erp 배포 타이밍과 무관하게 안전.**

## 열린 질문 (car-erp 가 판단)
- 정산 1건을 **한 달이 아니라 여러 달에 걸쳐 분할 지급(할부)** 한 경우가 있나? car-erp `Settlement.paid_at` 은 **단일 값**이라, 같은 정산을 5월+6월 나눠 지급했다면 단일 paid_at 으론 표현 불가.
  - 그런 분할이 `finalPayments`/`purchaseBalancePayments`(각자 `confirmed_at` 보유, Settlement 의 자식 결제 레코드) 로 들어간다면, "월별 실지급"을 정확히 하려면 그 **결제 레코드별 날짜·금액**을 노출해야 함(별도 엔드포인트/필드). 이건 범위가 커지니 **현재 케이스(엑셀=차별 단일 지급일)면 위 `paid_at` 노출로 충분**한지 먼저 확인.

## 끝단
- car-erp: `paid_at` 노출 + (필요시) 엑셀 매핑 점검 + 계약문서 갱신 → 배포.
- board: 후방호환 이미 반영(아래 커밋). car-erp 배포 후 요약 월별이 5월/6월로 자동 분리됨.

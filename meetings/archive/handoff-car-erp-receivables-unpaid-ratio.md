# 인계 (board → car-erp): 미수 읽기 API에 `unpaid_ratio`(미납률) 노출

> 작성 2026-07-22 (board 세션). **car-erp 세션에서 처리**할 것 — board 는 직접 못 고침(크로스레포 규칙).
> 수신 권위 = car-erp `docs/integration/board-portal-api.md`(읽기 API 계약). 이 인계 = 그 계약의 `/receivables` 응답에 **필드 1개 추가** 요청.

## 배경 (Jin 요청)
board 영업 포털 **미수금(receivables) 탭**은 바이어별로 접히고, 펼치면 차량별 미수금이 **금액만** 표시된다. ERP `채권관리(receivables)` 화면처럼 **"진행중 총판매가 대비 미납" = 미납률 게이지**를 board 에도 넣고 싶다.

## 왜 board 가 지금 payload 로는 못 그리나 (핵심)
ERP 채권관리 게이지의 미납률 = `Vehicle::unpaid_ratio` accessor(`app/Models/Vehicle.php`):
```php
public function getUnpaidRatioAttribute(): ?float
{
    $total  = (float) $this->sale_total_amount;   // 판매 통화 그대로 (환율 안 곱함 — 통화 비의존)
    if ($total <= 0) return null;                  // 판매가 미입력 → 미납률 평가 불가
    $unpaid = (float) $this->sale_unpaid_amount;   // 같은 판매 통화
    if ($unpaid <= 0) return 0.0;                  // 완납
    return max(0.0, min(1.0, $unpaid / $total));   // 0~1
}
```
즉 미납률 = `sale_unpaid_amount ÷ sale_total_amount`, **둘 다 판매 외화**(dimensionless).

그런데 `/receivables` 엔드포인트(`app/Http/Controllers/Api/Internal/InternalPortalController.php::receivables()`)는:
```php
'sale_total' => (float) $v->sale_total_amount,        // ← 외화
'unpaid_krw' => $v->sale_unpaid_amount_krw_cache,     // ← 원화(KRW 환산 캐시)
```
**단위가 어긋난다** — `sale_total`(외화) vs `unpaid_krw`(원화). board 가 `unpaid_krw ÷ sale_total` 하면 원화÷외화라 **틀린 비율**. board 에서 `exchange_rate` 로 역환산해 맞출 수도 있으나 JPY(100엔 기준)·ERP 캐시 반올림까지 재현해야 해 **ERP 숫자와 drift** + board "환산 단일경로(Money::toKrw)" 원칙 위반.

## 요청 (car-erp 세션에서)
1. **`/receivables` 응답에 `unpaid_ratio` 추가** (`InternalPortalController::receivables()` 의 map):
   ```php
   'unpaid_ratio' => $v->unpaid_ratio,   // 0~1 또는 null. 이미 있는 accessor 그대로.
   ```
   - **기존 필드는 그대로 두고 추가만** (`sale_total`·`unpaid_krw` 유지 — 다른 로직 무변).
   - 새 쿼리·조인 불필요: `unpaid_ratio` 는 이미 로드된 `sale_total_amount`/`sale_unpaid_amount` 로만 계산되는 accessor. (N+1 없음.)
   - 의미: `null` = 판매가 미입력(`sale_total_amount ≤ 0`), `0.0` = 완납, `0<r≤1` = 미납률. **환율 미입력과 무관**(통화 비의존).
2. **계약 문서 갱신**: `docs/integration/board-portal-api.md` 의 `/receivables` 응답 스키마에 `unpaid_ratio`(0~1|null) 추가.

## 검증 (car-erp 배포 전)
- `GET /api/internal/board/receivables?salesman_email=<영업>` 호출 → 각 row 에 `unpaid_ratio` 가 있고, 그 값이 ERP 채권관리 화면의 미납률(%)과 **일치**하는지 대조.
- 완납 차(미수 0) = `0.0`, 판매가 미입력 = `null` 로 나오는지 확인.

## board 측 대응 (이미 반영, 후방호환)
board `resources/views/livewire/portal/index.blade.php` 미수금 탭 차량 행이 **`unpaid_ratio` 가 오면** ERP 패리티 미납률 게이지(초록→노랑→빨강 hue + `NN% 미납`)를, **안 오면(null/미배포)** 게이지 없이 금액만 표시하도록 null-safe 처리됨.
→ car-erp 가 필드를 **보내기 전엔** 지금처럼 금액만(무변), **보내기 시작하면** 자동으로 미납률 게이지 표출. **배포 타이밍 무관하게 안전.**

## 끝단
- car-erp: `unpaid_ratio` 1줄 노출 + 계약문서 갱신 → 배포.
- board: 후방호환 이미 반영(아래 커밋). car-erp 배포 후 미수금 탭 차량 게이지가 자동으로 미납률로 표출됨.

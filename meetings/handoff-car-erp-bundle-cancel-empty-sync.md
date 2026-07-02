# 인계: car-erp — 선적묶음 "취소"가 서버(prod)에서 안 됨

작성: board 세션(Jin) · 2026-07-02
받는 곳: **car-erp 세션**
증상(Jin 보고): board 영업포털 선적묶음에서 **취소가 프로덕션에서 여전히 안 됨**. (이전에 취소 경로 수정했으나 "여전히".)

## board 취소 메커니즘 (배경 — 선언형 재동기화)
board엔 per-bundle 취소 엔드포인트가 없다. `cancelBundle($batchId)`는:
1. 그 묶음을 `desired`(원하는 묶음 전체)에서 제거
2. `syncBundles()` → `POST /shipping-requests/sync` 로 **남은 desired 전체** 전송
3. car-erp가 desired에 빠진 requested 행을 **자동취소**(스펙 §5-2)

→ **바이어당 묶음이 1개면**, 그 묶음을 취소할 때 남는 desired가 없어 board는 **`bundles: []`(빈 배열)** 을 보내야 하고, car-erp가 "빈 desired = 전체 자동취소"로 처리해야 정상.

## 유력 원인 (주) — sync 검증이 빈 배열을 거부
`app/Http/Controllers/Api/Internal/ShippingRequestController.php` `sync()`:
```php
$data = $request->validate([
    'bundles' => ['required', 'array', 'min:1'],   // ← 빈 배열 거부(422)
    ...
]);
```
- 자동취소 로직(같은 파일 line 196-203)은 **빈 desired면 전체 취소**로 정상 동작하는데, `min:1`(그리고 `required`)이 **그 로직 도달 전에 422**로 튕긴다.
- board는 그 422를 `flash_ship_failed`("선적요청 전송 실패 — 잠시 후 다시 시도하세요") 토스트로 표시 → 취소 실패.

### 고칠 것 (⚠️ 반쪽 수정 주의)
`'bundles'` 규칙을 **`['present', 'array']`** 로 교체. 
- **`required`와 `min:1` 둘 다 제거해야 함.** Laravel에서 `required`는 빈 배열도 단독으로 거부하므로, `min:1`만 빼고 `required`를 남기면 **여전히 422**. (이전 수정이 `min:1`만 건드렸다면 이게 "여전히 안 됨"의 원인일 수 있음 — 확인 요망.)
- 교체 후: 빈 `bundles` → 생성/갱신 루프 skip → line 196-203이 본인 open `requested` 전부 취소.

### 안전성 (게이트 완화가 안전한 이유)
- 자동취소는 **`requested` 행만** 건드림(`in_progress`는 line 198에서 제외 = 관리 착수분 보호).
- board가 빈 payload를 보내는 경우는 `cancelBundle`이 **유일한 차-보유 requested 묶음**을 취소할 때뿐 → "전체취소 == 그 한 묶음 취소"로 동치.
- board엔 **오발 빈-전송 방지 가드**가 이미 있음: /bundles 로딩 degrade 시 차단(`flash_sync_blocked_degraded`), 기존 묶음 buyer_id 누락 시 차단(`flash_sync_incomplete_buyer`). 즉 *의도치 않은* 빈 전송은 board가 막고, *의도된* 취소 빈-전송만 통과한다.

## 대안 원인 (부) — prod /bundles 가 buyer 를 객체로 안 줄 때
board는 묶음 buyer_id를 `data_get(bundle,'buyer.id')`로 읽고, 기존 묶음에 batch_id는 있는데 buyer_id가 없으면 **동기화를 막는다**(전체취소 footgun 방지) → `flash_sync_incomplete_buyer` 토스트.
- 현재 car-erp 코드 `ShippingRequestController::bundles`(line 84)는 `'buyer' => ['id'=>.., 'name'=>..]` **객체로 반환**(정상). 그러나 `InternalPortalController`(line 42/58, finance/receivables 계열)는 `'buyer' => name` **문자열**로 반환 — 혼동/회귀 주의.
- **프로덕션 car-erp가 최신 코드(buyer 객체)로 배포됐는지 확인.** 스테일 배포면 buyer가 문자열→board buyer_id null→취소 차단. 이 경우 fix = **prod 재배포**.

## 판별자 (어느 원인인지 = board 토스트 문구)
Jin에게 취소 시 뜨는 문구 확인:
- **"선적요청 전송 실패 …"** → 주 원인(min:1). 위 검증 fix.
- **"기존 묶음의 바이어 정보(buyer_id)가 응답에 없어 …"** → 부 원인. prod /bundles buyer 객체 여부/재배포 확인.
- **"선적묶음을 불러오지 못해 …"** → /bundles 조회 자체 실패(degrade). car-erp 엔드포인트/HMAC 점검.

## 검증 (fix 후)
- 요청됨(requested) 묶음 1개만 있는 상태에서 board 취소 → **200** `{cancelled:[...]}` → 그 묶음이 cancelled. board 목록에서 사라짐.
- 요청됨 묶음 2개 중 1개 취소 → 남은 1개 유지 + 취소분만 cancelled(회귀 확인).
- `in_progress`(관리 착수) 묶음은 자동취소 안 됨(변경요청 경로 유지) 재확인.

## 크로스레포 원칙
- 검증 fix는 car-erp 파일에, **car-erp 세션에서 커밋**. board 쪽 코드 변경 없음(취소 로직은 board는 이미 올바름 — 빈 전송이 설계).

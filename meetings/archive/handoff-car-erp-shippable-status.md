# 인계: 선적요청 후에도 차량 유지 + 상태표시 + 재요청 (car-erp)

> **방향**: board → car-erp 요청. Jin 이 car-erp 세션에 전달 → car-erp 가 권위 스펙(`board-portal-api.md §5`) 갱신·구현. (board 는 받은 것만 그림 — 이 동작은 car-erp 가 정함.)
> **배경**: 영업이 board 선적요청을 올리면 차가 목록서 **즉시 사라짐**(요청 직후). 영업은 "요청됨" 상태로 계속 보고 싶고, 잘못되면 **재요청**도 하고 싶음. 실제 사라짐은 **관리가 선적/통관을 진행해 progress 가 '판매완료'를 벗어날 때**여야 함.

## 현재 동작 (확인)
- `ShippingRequestController::shippable()` — `progress_status_cache='판매완료' AND sales_channel='export' AND whereNotIn(open requested vehicle_ids)`. → **요청 직후 open 'requested' 생기면 목록서 제외 = 사라짐.**
- `store()` — open 'requested' 있으면 **skip**(재요청 불가, 내용 변경 불가).
- `ShippingRequest::STATUS_*` = requested / in_progress / done.

## 요청 변경
1. **사라짐 조건 변경 — `whereNotIn(openVehicleIds)` 제거.**
   - 선적 가능 = `progress_status_cache='판매완료' AND export` 만. open 요청 있어도 **포함**.
   - → 차는 **관리가 선적/통관 진행해 progress 가 '판매완료'를 벗어날 때 자연 소멸**(또는 요청 status='done'). Jin 의도와 일치.
2. **item 에 `shipping_status` 추가** — 그 차의 최신 ShippingRequest status: `none`(요청전) | `requested`(요청됨) | `in_progress`(진행중). board 가 뱃지로 표시("요청됨"/"진행중")해 이미 요청한 차를 구분.
   - 응답 예: `{vehicle_id, vehicle_number, buyer, consignees, shipping_status, requested_method?}`(요청됨이면 직전 method 동봉하면 board UX↑, 선택).
3. **재요청 허용 — `store()` skip → 갱신(update).**
   - open 'requested' 있으면 skip 대신 **기존 row 의 consignee_id/shipping_method 갱신** + 알람 재발동(또는 due 갱신). 영업이 컨사이니/방식 잘못 골랐을 때 정정 가능.
   - 멱등 취지(이중 생성 방지)는 유지 — **새 row 안 만들고 기존 갱신**. 응답에 created/updated/skipped 구분 주면 board 가 안내.
   - (대안 최소안: skip 유지하되 응답에 'already_requested' 명시 → board 가 "이미 요청됨" 안내. 단 내용 정정 불가 → 갱신안 권장.)

## board 측 (구현됨 — car-erp 응답만 기다림, dev 2026-06-19)
- `/portal 선적요청` 탭: **shippable item 의 `shipping_status`(none/requested/in_progress) + `shipping_method`** 로 분기 렌더 **이미 구현**.
  - `requested`/`in_progress` → **맨 위 "🚚 진행 중인 선적요청" 카드**(요청됨=amber / 진행중=blue 뱃지, 차량번호·바이어·method).
  - `none`(또는 필드 없음) → 아래 바이어별 선택 UI(요청전).
- **car-erp 가 ① whereNotIn 제거(요청 차 목록 유지) ② item 에 `shipping_status`·`shipping_method` 동봉** 하면 board 가 **자동으로 진행카드 표시**. (현재는 요청 차가 응답에서 빠져 카드 0 — car-erp 반영 시 즉시 동작.)
- 재요청/갱신(요청됨 카드에서) 은 car-erp 갱신 정책 확정 후 추가.

## 열린 항목 (car-erp 결정)
- 재요청 = **갱신** vs **skip+안내** (권장: 갱신).
- `shipping_status='done'` 차도 목록에 잠깐 남길지(완료 표시) vs 즉시 제외. (권장: progress 로만 제어, done 은 곧 progress 이동되므로 자연 소멸.)

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

## board 측 (car-erp 확정 후 구현)
- shippable item `shipping_status` 로 뱃지(요청전/요청됨/진행중). **요청됨·진행중도 목록 유지**(체크·재요청 가능).
- 요청됨 차량에 "재요청/갱신" 동작(현 submitShipping 재사용, car-erp 가 갱신 처리).
- 사라짐은 car-erp 가 progress 로 제어 → board 무변경(목록 새로고침 시 빠짐).

## 열린 항목 (car-erp 결정)
- 재요청 = **갱신** vs **skip+안내** (권장: 갱신).
- `shipping_status='done'` 차도 목록에 잠깐 남길지(완료 표시) vs 즉시 제외. (권장: progress 로만 제어, done 은 곧 progress 이동되므로 자연 소멸.)

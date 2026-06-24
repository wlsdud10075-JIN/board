# [인계문서] car-erp ↔ board 토스트 알림 (양방향)

> 작성: **car-erp 세션 2026-06-24** (jin 요청). 구현·커밋은 **board 세션·board repo에서**.
> car-erp측 대칭 구현(= board 도착 시 car-erp 토스트)은 car-erp 세션에서 따로 한다.

## 목적
car-erp는 비동기 이벤트(NICE 에러·board 도착 등)를 **잠깐 떴다 사라지는 토스트**로 알린다.
board도 **car-erp와 데이터를 주고받을 때 같은 토스트**를 띄워 영업이 즉시 인지하게 한다.

⚠️ **토스트는 보조**다. 영구 배지/알림 목록이 본체(놓쳐도 받쳐줌). 토스트 단독으로 중요한 걸 알리지 말 것.

## ✅ board엔 이미 패턴이 다 있다 — 신호만 추가하면 끝
`resources/views/livewire/notify/poll.blade.php` = 완성된 폴링+토스트:
- `wire:poll.30s="check"` → `check()`가 `PurchaseListing(status=inspected)` count 증가 감지
- → `dispatch('forward-arrived', msg)` → Alpine `@forward-arrived.window` 가 **우하단 토스트(6초) + `window.__boardBeep`** 표시
- `SalesmanScope` 로 영업은 본인 것만 울림

→ **car-erp 신호만 이 `check()`(또는 같은 패턴의 형제 컴포넌트)에 추가**하면 토스트가 그대로 재사용된다.

## 구현 (board 세션)

### A. board → car-erp **보낼 때** (전송 완료 토스트)
- 트리거: `app/Jobs/SyncWonListingToCarErp.php`(won push) 등 car-erp 전송 작업 **성공 시**.
- 큐 잡이라 비동기 → `PurchaseListing` 에 `synced_to_carerp_at`(또는 sync 성공 플래그) 기록 →
  notify/poll `check()` 에서 "새로 sync 성공한 건수" 증가 감지 → `dispatch('forward-arrived', msg:'car-erp 전송 완료 N건')`.
- (won push를 **사용자 클릭**으로 트리거하는 화면이면 그 액션 직후 동기 `dispatch` 가 더 간단.)

### B. car-erp → board **받을 때** (수신 토스트)
- board가 `app/Services/CarErpReadService.php` 로 car-erp 데이터를 조회/폴링하는 화면에서,
  board가 관심 있는 car-erp측 변화(예: 선적요청 처리됨·정산 상태 변경 등)를 감지하면 토스트.
- notify/poll `check()` 에 **car-erp 신호 count/timestamp 비교 한 줄 추가** → 증가 시 `dispatch('forward-arrived', msg:'car-erp에서 …')`.
- car-erp 가 board 로 능동 push 하는 수신 테이블이 있으면 그 count 로 감지.

### 권장
- 기존 `notify/poll.blade.php` `check()` 에 car-erp 신호 1~2개 추가하거나, 같은 패턴 형제 컴포넌트 1개 추가.
- 이벤트명 재사용(`forward-arrived`) 또는 신규(`carerp-event`). 종류별 색/아이콘 원하면 `$event.detail.type` 추가.

## 원칙 (car-erp와 동일)
1. 토스트는 **보조** — 본체는 배지/목록/영구 알림. 토스트만으로 알리지 말 것.
2. 폴링 30초 = 최대 30초 지연(즉시 아님). 즉시성 필요해지면 추후 WebSocket(Reverb), 지금은 폴링 충분.
3. **본인 스코프(SalesmanScope)** 지켜 영업은 본인 것만 울리게.

## car-erp측 참고 (대칭)
- 전역 토스트: `resources/views/components/layouts/app/sidebar.blade.php` `@notify.window` + `dispatch('notify', message, type)`.
- 알람 폴링: `resources/views/livewire/erp/alarm-center.blade.php` `wire:poll.60s` (배지/벨, board 도착=purchase_arrival).
- car-erp 받는 쪽 토스트는 car-erp 세션에서 alarm-center 폴링에 "새 알람 → notify 토스트" 추가 예정.
- 통합 스펙(권위): car-erp `docs/integration/board-portal-api.md`, `docs/integration/purchase-sync-receiver.md`.

## 크로스레포 규칙
이 토스트는 **board UI** → board 코드로 구현하고 **board 세션·board repo에 커밋**. (car-erp 메모리엔 안 따라옴 — git 커밋 파일만 전파.)

# 인수인계 — car-erp 수신측 (연동 B: purchase-sync)

> **왜 이 문서가 있나**: Claude 세션끼리는 실시간 통신 채널이 없다(컨텍스트·메모리 격리). board↔car-erp 협업의 유일한 다리 = **git 커밋된 파일 + 사용자가 옮기는 인계 문서**. board 세션이 만든 이 노트를 사용자가 car-erp 세션에 전달한다.
>
> - **보내는 절반(권위)** = board `SKILLS.md §12` (구현 완료, dev `e633555`).
> - **받는 절반(권위)** = car-erp `docs/integration/purchase-sync-receiver.md` (← car-erp 세션이 작성·커밋).
> - 상호링크, **복사 금지(drift)**.

## 상태 (2026-06-15)
- board 보내는 절반 = **구현+테스트 완료** (`SyncWonListingToCarErp` Job · `integration_events` · HMAC · dispatch 훅 · 테스트 4종, 총 31통과). dev 커밋, **master 미배포**(안전밸브로 no-op).
- 남은 것 = **car-erp 수신 `PurchaseSyncController`** (아래) + 양쪽 env + 배포.
- 대표 승인됨: "car-erp 무수정 원칙의 명시적 예외" (purchase-sync API 1개 추가).

---

## car-erp 세션 첫 프롬프트 (그대로 붙여넣기)

```
연동 B 수신측을 구현해줘. board(매입보드)가 낙찰차를 car-erp로 단방향 push하는 걸
받는 API다. 보내는 쪽(권위 스펙)은 board repo의 SKILLS.md §12에 있어
(C:\xampp\htdocs\board\SKILLS.md — 같은 PC라 읽을 수 있음). 대표 승인 완료된
"car-erp 무수정 원칙의 명시적 예외" 작업이야.

[엔드포인트]
POST /api/internal/purchase-sync   (라우트 + PurchaseSyncController)

[인증 = HMAC. board와 반드시 동일 방식]
- 헤더: X-Board-Signature: sha256=<hex>
- 검증: hash_hmac('sha256', $request->getContent(), env('CAR_ERP_HMAC_SECRET'))
  → "sha256=" 뗀 hex와 hash_equals 비교.
- ⚠️ 반드시 "수신 raw body 그대로" 서명 재계산. $request->all() 재직렬화 금지
  (바이트 달라지면 불일치). 세션/CSRF 없는 순수 API 라우트.
- 불일치 → 401.

[payload (JSON body)]
contract_version(1), vin, vehicle_number, source(encar|auction),
final_price(int, KRW), salesman_email, car_erp_salesman_id(nullable),
c_no(nullable), payee_name, payee_bank, payee_account(nullable)
→ 모르는 필드는 무시(전방호환).

[처리]
1. VIN 멱등: 동일 VIN 차량이 이미 있으면 새로 만들지 말고 그 vehicle_id 반환(스킵).
2. 없으면 vehicle 생성 + 매입 워크플로우 시작.
3. 영업 매칭: salesman_email로 salesmen 조회 → vehicles.salesman_id 지정.
   이메일 다른 예외만 car_erp_salesman_id로 오버라이드. 둘 다 없으면 수동.
   체인: vehicles.salesman_id → salesmen.id → salesmen.user_id → manager_user_id 솔팅.
4. payee_name/bank/account → 매입 정산계좌 필드로 저장(account는 민감, car-erp
   기존 암호화/마스킹 정책 따름).

[응답]
성공: 2xx + {"vehicle_id": <int>}  (멱등 스킵도 기존 id 반환)
실패: 비-2xx (board가 큐 재시도 5회로 흡수)

[문서/배포]
- 받는 스펙을 car-erp repo의 docs/integration/purchase-sync-receiver.md에 작성
  (board SKILLS §12와 상호링크, 복사 금지/drift 방지).
- env에 CAR_ERP_HMAC_SECRET 추가(board와 동일 값 공유).
- 배포 순서: car-erp(수신측) 먼저 배포 → 그다음 board가 보내기 시작.
- 테스트: 유효 서명 → vehicle 생성 + vehicle_id 응답 / 잘못된 서명 → 401 /
  동일 VIN 재전송 → 중복 생성 안 하고 기존 id 반환.
```

---

## 배포 순서 (양쪽 끝난 뒤)
1. car-erp 수신측 구현·배포 (위)
2. **공유 비밀키 생성** → car-erp `.env` `CAR_ERP_HMAC_SECRET` + board `.env` 동일값 + board `CAR_ERP_BASE_URL=https://<car-erp도메인>`
3. board `dev→master` 머지 배포 → 그때부터 won 차량이 실제 push
4. end-to-end: board에서 차 하나 won → car-erp 자동 생성 확인

board 쪽 env(`CAR_ERP_BASE_URL`/`CAR_ERP_HMAC_SECRET`) 세팅은 board 세션에서 처리.

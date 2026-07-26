# 인계 (car-erp → board) — NICE 동시 상한·swap·carmodoo 원부조회 결정 (2026-07-26)

> **수신 = board 세션.** car-erp 세션에서 오늘 배포한 변경 중 **board 가 알아야 할 것** + carmodoo 원부조회 board 확장 논의 결과(킵).
> jin 지시 = **"알고만 있게"** — 지금 board 가 할 일은 없다. **인지만** 하면 됨.
> (board 가 남긴 `board/meetings/handoff-car-erp-infra-fpm-2026-07-26.md` 에 대한 답례 격. 이 파일은 board 세션에서 읽고 커밋해도 됨.)

## 1. NICE 게이트웨이 동시 조회 상한 — ⚠️ board 영향 있음
- car-erp 가 **모든 NICE 조회가 지나는** `ProvideNiceLookupController`(54.116.7.83)에 **전역 동시 상한(기본 4건)** 을 걸었다(master `ce48448`).
- board 가 NICE 차량정보를 **ERP 경유로 조회**하면, **동시 4건 초과 시 HTTP 429**(`{"success":false,"message":"동시 조회가 많아 잠시 후 다시 시도해 주세요."}`)를 받는다.
- **board 처리 원칙**: 429 = 에러 아님 = **잠시 후 재시도** 신호. (조회 1건이 워커를 55~90초 점유해서, 몰리면 ERP+board 공유 워커 풀이 고갈되는 것 방지용.)

## 2. 인프라 안정화 — FYI (별도 조치 불필요)
- car-erp 세션이 `54.116.7.83`·`52.79.200.151` 에 **swap 2GB** 추가(OOM 쿠션). board 도 같은 박스라 안정성 ↑.
- deploy(car-erp) SSH 타임아웃/재시도 개선 = car-erp 배포 안정화용, board 무관.

## 3. carmodoo 원부조회 board 확장 — 🅿️ 킵 (결정 보류)
board 사용자가 원부조회(압류/저당/구조)를 하는데, **board→ERP 경유로 통합할지** 논의하다 **보류(jin)**. board 가 알아야 할 결론:

- ⛔ **절대 금지 = board 사용자 개인 carmodoo 계정들을 ERP 경유로 우리 고정 IP(사무실 WireGuard)로 조회.**
  = **"여러 계정이 한 IP"** = 조합(carmodoo) IP제한 시스템의 계정도용/봇 패턴 → 그 IP 차단 시 **3사 ERP 원부조회까지 동반 사망**.
- 재개 시 방향(둘 중 하나, **car-erp 세션이 설계·인계**):
  - **A.** board 가 눌러도 조회는 **우리 단일계정 하나 / 우리 IP** 로만(자연스러운 패턴). 우리 계정 조회 한도가 board 물량 감당 시.
  - **B.** board 사용자는 지금처럼 **자기 앱·자기 IP** 로 조회하고, board 엔 **결과(요약)만 담기**(네트워크 호출 우리가 안 함 → IP·계정 위험 0).
- **재개 트리거 시 = car-erp 세션이 이 주제 상세 인계문서를 새로 만들어 전달**한다(carmodoo 아키텍처·계정·WG 상세는 car-erp 메모리 `project_wonbu_lookup` 에만 있고 board 엔 없음). board 는 지금 **인지만**.

---
*작성 = car-erp 세션(2026-07-26). board 관련 코드/결정은 board 세션·board repo 에서 커밋(크로스레포 규칙). 이 문서는 인지용.*

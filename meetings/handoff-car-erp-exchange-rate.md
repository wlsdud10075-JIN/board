# [인계 → car-erp 세션] board 환율을 car-erp 값으로 통일 (전신환 매입률)

> 작성: board 세션(Jin) · 2026-07-03
> 받는 곳: **car-erp 세션**
> 방향: board 가 car-erp 에서 환율을 **HMAC GET 으로 받아** 그대로 씀(Option B). 기존 영업포털 읽기 계약(`board-portal-api.md`, `VerifyBoardReadHmac`) 재사용.

## 0. 배경 / 왜
- **문제**: board 환율 ≠ car-erp 환율(같은 시각인데 값 다름). 원인 = **소스가 다름** — board 는 Frankfurter(ECB 기준환율), car-erp 는 **네이버 금융 상세페이지의 "송금 받으실 때 (전신환 매입률)"** 스크래핑.
- **Jin 결정(2026-07-03)**: board 를 car-erp 와 **똑같은 값**으로 맞춘다. "같은 소스로 각자 긁기"는 **긁는 시점차로 완전일치 안 됨** → **car-erp 가 쓰는 값을 board 가 그대로 받는다**(단일 소스 = car-erp, 100% 일치 보장).

## 1. 요청 (car-erp) — 환율 read 엔드포인트 1개
기존 영업포털 read 와 동일 인증(`VerifyBoardReadHmac`, `CAR_ERP_READ_HMAC_SECRET`, canonical `METHOD\nPATH?SORTED_QUERY\nX-Timestamp\nBODY`). **스코프(salesman_email) 불필요 — 환율은 전역값.**

- **`GET /api/internal/board/rates`** (쿼리 없음)
- **응답(제안 — 권위키는 car-erp 확정, board 에 역링크)**:
```json
{
  "rates": { "USD": 1385, "EUR": 1502 },
  "fetched_at": "2026-07-03 14:05",
  "source": "naver_전신환매입률"
}
```
  - `rates.USD` / `rates.EUR` = **1 통화당 원화(정수)**, **car-erp 가 실제 계산·저장에 쓰는 그 값**(네이버 전신환 매입률) 그대로. board 는 이 숫자를 표시·환산에 그대로 사용 → 두 앱 값 일치.
  - `fetched_at` = car-erp 가 마지막으로 네이버에서 긁은 시각(표시·신선도용, 선택).
  - 통화 없거나 조회 실패 시 그 키 생략 가능 — board 는 없는 통화는 기존 폴백(마지막 캐시→config) 유지.
- car-erp 가 이미 내부적으로 이 환율을 저장/조회하고 있을 것 → **그 값을 그대로 노출만** 하면 됨(새 스크래핑 로직 불필요).

## 2. board 측 대응 (board 세션이 별도 구현 — 참고)
- `App\Services\CarErpReadService` 에 `rates()` 추가 → `GET /api/internal/board/rates`(기존 `get()` 재사용, HMAC).
- `App\Services\ExchangeRateService::fetch()` 를 **Frankfurter 직접호출 → car-erp `/rates` 경유**로 교체. 실패/미설정 시 **기존 폴백 체인 유지**(마지막 캐시값 → `config('board.default_krw_per_*')`) → car-erp 불통이어도 board 안 깨짐.
- 캐시(ExchangeRate 테이블 · TTL `rate_ttl_hours`) · lazy `refreshIfStale()` 구조는 그대로 → car-erp 부하 최소(1시간에 1회 조회).
- `config('board.rate_api_base')`(Frankfurter) 는 폴백 소스로 남기거나 제거 — 구현 시 결정.

## 3. 배포 순서 / 크로스레포
- **car-erp 먼저**(`/rates` 엔드포인트 배포) → 그 다음 board 가 소스 전환. (엔드포인트 없으면 board 는 폴백으로 도니 안전.)
- 이 변경은 car-erp 파일에 하고 **car-erp 세션에서 커밋**. board 는 §2 대응까지(dev).
- 권위 스펙 = car-erp `docs/integration/board-portal-api.md` 에 `/rates` 추가 + board 에 역링크.

## 4. 체크리스트 (car-erp)
- [ ] `GET /api/internal/board/rates`(HMAC GET, 스코프 없음) — car-erp 가 쓰는 전신환 매입률 USD/EUR(정수 원) 반환
- [ ] `docs/integration/board-portal-api.md` 에 엔드포인트 명세 추가(응답키 확정), board 역링크
- [ ] (선택) fetched_at·source 필드

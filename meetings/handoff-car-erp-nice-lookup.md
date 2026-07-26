# 인계 — board 원부조회(NICE) 엔드포인트 신설 요청

> **수신 = car-erp Claude 세션.** board `/listings` 차량 등록 화면에 「원부조회」를 넣으려면 **ERP 가 엔드포인트를 하나 열어줘야 한다**. board 는 NICE 를 직접 못 부른다(IP 화이트리스트 + PII 계약).
> **같이 전달되는 문서** = `meetings/handoff-car-erp-infra-fpm-2026-07-26.md` (이 기능의 전제가 된 서버 실측·변경. 먼저 읽을 것).
> ⚠️ board 세션은 car-erp 코드를 건드리지 않는다. 이 문서는 **요청서**다. 구현·커밋은 car-erp 세션에서.

## 1. 무엇을 왜

Jin 요청: board 매입예정 등록 폼에서 **차량번호 아래 「원부조회」 버튼** — 등록 시점에 차량 실체와 소유자를 확인하고 싶다.

원부조회 본체(`NiceApiService::lookupVehicle`)는 이미 ERP 에 있고 잘 돈다. 문제는 board 가 그걸 **직접 쓸 수 없다**는 것:

1. **IP 화이트리스트** — NICE 실호출은 `54.116.7.83` 경유만 가능. board 박스는 화이트리스트 밖.
2. **PII 계약** — `lookupVehicle()` 응답에는 **소유자명·주소·RRN** 이 들어있다. board 는 이것들을 보유하지 않는 앱이고, `docs/integration/board-portal-api.md:35` 에 이미 **"`nice_reg_owner_rrn`·`nice_reg_owner_name/addr` — 어떤 응답에도 미포함"** 이 계약으로 박혀 있다.
3. **drift 금지** — board 가 `/provide` 를 직접 때리면 `transform()` 을 board 에 복제해야 한다. 금지된 패턴.

⇒ **ERP 가 PII 를 걷어내고 돌려주는 얇은 엔드포인트 하나**가 답이다.

## 2. 요청 스펙 (제안 — 최종 형태는 car-erp 판단)

`board-portal-api.md` 에 새 절(§11 등)로 추가. 기존 board↔ERP HMAC 채널 재사용.

```
POST /api/internal/board/nice-lookup
인증: 기존 CAR_ERP_READ_HMAC_SECRET (POST = raw JSON 바이트 서명, §10 signing-requests 와 동일 방식)
```

**요청**
```json
{ "email": "sales@...", "vehicle_number": "12가3456", "owner_name": "홍길동" }
```
- `owner_name` 은 board 가 이미 보유한 값(영업이 수기 입력, `purchase_listings.owner_name`)이라 **새 PII 유입이 아니다**. NICE 가 조회 조건으로 요구하므로 보낸다.

**응답 (성공)**
```json
{
  "success": true,
  "owner_match": true,
  "registration": {
    "nice_reg_vin": "...", "nice_reg_engine_no": "...", "nice_reg_use_type": "...",
    "nice_reg_vehicle_form": "...", "nice_reg_first_date": "2019-03-11",
    "nice_reg_fuel_type": "...", "nice_reg_passengers": "5", "nice_reg_max_load": null,
    "mileage": "84000", "year": "2019", "model_type": "...", "brand": "..."
  },
  "spec": {
    "nice_spec_length": "4840", "nice_spec_width": "...", "nice_spec_height": "...",
    "nice_spec_maker": "...", "nice_spec_year": "2019", "nice_spec_curb_weight": "2115",
    "nice_spec_displacement": "1995", "nice_spec_fuel_efficiency": "9.5",
    "weight_kg": "2115", "cc": "1995"
  }
}
```

### ⛔ 응답에서 반드시 빠져야 할 것
| 필드 | 이유 |
|---|---|
| `nice_reg_owner_name` | 계약 `board-portal-api.md:35` |
| `nice_reg_owner_addr` | 〃 |
| `nice_reg_owner_rrn` | 〃 (RRN — board 는 절대 보유 안 함) |
| `raw` | NICE 원본. **위 3개가 그대로 들어있다.** 통째로 빼야 한다 |

### `owner_match` 가 이 설계의 핵심
NICE 는 **소유자명이 다르면 `E901` 로 거절**한다. 그래서 board 는 소유자명을 *돌려받지 않고도* "이 사람이 실제 차주인가"를 알 수 있다:

- 조회 성공 → `owner_match: true` (NICE 가 통과시켰다 = 일치)
- `E901` → `{"success": false, "owner_match": false, "message": "소유자명이 원부와 일치하지 않습니다"}`
- 그 외 실패(네트워크·타임아웃·미설정) → `owner_match` 는 `null`, `message` 에 `humanizeError()` 결과

**PII 를 필터링해서 지키는 게 아니라, 애초에 넘기지 않는 구조**라 계약 위반이 구조적으로 불가능하다.

## 3. ⚠️ 전역 동시 상한 — ERP 만 걸 수 있다 (이 문서의 제일 중요한 요청)

원부조회 1건은 NICE 응답을 **55~90초** 기다리며 워커를 잡는다. board 에서 조회하면 `54.116.7.83` PHP 워커를 **3칸** 쓴다:

```
board-ssancar 워커(1) → ssancar-erp 내부 API 워커(2) → /provide/api/nice-lookup 워커(3)
```

워커 증설 전(5칸)에는 **동시 2건이면 교착**이었다. 지금은 14칸이라 여유가 생겼지만 상한은 여전히 필요하다.

**board 는 전역 상한을 걸 수 없다.** heymanboard·ssancarboard 는 DB·캐시가 완전히 분리돼 서로의 진행 건수를 모르고, ERP UI 자체 조회와 karabaerp 는 세지도 못한다:

```
heymanboard  "2건까지" ─┐
ssancarboard "2건까지" ─┼→ 54.116.7.83 에는 합계가 몰린다
ssancarerp UI (무제한)  ─┤
karabaerp UI  (무제한)  ─┘
```

**모든 경로가 반드시 지나는 유일한 지점 = `ProvideNiceLookupController`** (3사 `NICE_PROVIDE_URL` 이 전부 `https://heymancar.com/provide/api/nice-lookup/`). NICE 게이트웨이가 Django → car-erp PHP 로 컷오버된 덕에 **이제 car-erp 코드로 걸 수 있다.**

요청:
- `ProvideNiceLookupController` 진입부에 **동시 실행 N건 제한**(`Cache::lock` / atomic counter). N 은 **3~4** 제안(워커 14칸 기준).
- 초과 시 **대기시키지 말고 즉시 429 반환** — 기다리게 하면 그 요청도 워커를 잡아 상한의 의미가 없다.
- 429 body 에 사유 메시지 → 호출자(board·ERP UI)가 "잠시 후 다시 시도" 안내.
- 락은 **반드시 finally 해제 + TTL**(90초 이상). 타임아웃으로 죽은 요청이 락을 물고 있으면 그때부터 전면 차단된다.

## 4. 타임아웃 체인 (틀리면 과금만 나가고 결과를 못 받는다)

| 구간 | 값 | 상태 |
|---|---|---|
| nginx `fastcgi_read_timeout` (3박스) | **90s** | ✅ 2026-07-26 적용 |
| ERP → NICE (`NiceApiService`) | 55s | 기존 |
| **board → ERP (신규 메서드)** | **90s 필요** | ⚠️ `CarErpReadService` 의 기존 POST 는 `Http::timeout(20)` — **그대로 쓰면 20초에 끊기고 NICE 과금은 발생**. 이 메서드만 별도 타임아웃 |

## 5. 비용 — board 는 ERP 보다 낭비가 크다

NICE 는 **건당 과금**이다. ERP 는 매입 확정 후 조회라 버려지는 게 없지만, **board 등록은 후보 단계**라 draft·rejected 로 죽는 차까지 조회하게 된다.

board 측이 지킬 것(이 문서의 약속):
- 링크 붙여넣기 시 **자동 발사 금지** — `ListingEnrichment`(자동 채움)와 달리 **버튼 명시 클릭만**.
- 버튼 중복클릭 차단(진행 중 비활성) + board 인스턴스 내 동시 1건.
- 조회 로그 남김.
- ERP 의 기존 5분 캐시(`vehicleNumber+ownerName` 키)는 그대로 이득.

## 6. board 측 구현 계획 (ERP 배포 전까지 404 degrade)

전자서명(§10) 때 성공한 순서를 그대로 따른다 — **board 가 먼저 만들고, ERP 가 준비되면 자동으로 살아난다**:

1. `CarErpReadService::niceLookup($email, $vehicleNumber, $ownerName)` — 미설정/404/5xx → degrade(패널에 "조회 불가" 안내), 예외 안 던짐.
2. `/listings` 차량번호 필드 아래 「원부조회」 버튼 → **read-only 접힘 패널**(`_encar-history.blade.php` 와 같은 형태).
3. **무저장** — `purchase_listings` 에는 연식·차종·주행거리·중량을 담을 컬럼이 **아예 없다**(실측 확인). 저장하지 않고 화면 표시만. 예외로 **`vin` 이 비어 있을 때만 prefill** 제안(영업이 확인 후 저장, `IDENTITY_LOCKED` 자동확정 금지 원칙 유지).
4. i18n ko/en 양쪽 키 + 테스트.

## 7. 검증

- ERP: 실차량 1건으로 응답에 `owner_name`·`owner_addr`·`owner_rrn`·`raw` 가 **없는지** 직접 확인(계약 위반은 눈으로 확인해야 한다).
- 소유자명을 일부러 틀리게 → `owner_match: false` + E901 메시지.
- 동시 상한: N+1 번째 요청이 **즉시** 429 인지(대기 아님).
- board: ERP 미배포 상태에서 버튼 눌러도 화면이 안 깨지는지(degrade).

## 8. 참고

- 원본 구현: `car-erp/app/Services/NiceApiService.php` (`lookupVehicle` / `transform` / `humanizeError`)
- 게이트웨이: `car-erp/routes/web.php:19` → `ProvideNiceLookupController` → `NiceDirectClient`
- 계약 권위: `car-erp/docs/integration/board-portal-api.md` (§35행 PII 제외 조항)
- 인프라 전제: `board/meetings/handoff-car-erp-infra-fpm-2026-07-26.md`

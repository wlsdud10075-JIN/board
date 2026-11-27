# 인계 — board 「원부조회」(Carmodoo) 엔드포인트 신설 요청

> **수신 = car-erp Claude 세션.** board `/listings` 차량 등록 화면에 ERP 의 **「원부조회」 버튼을 그대로** 넣고 싶다. 조회 본체(`CarmodooService`)는 ERP 에 있고, board 는 **직접 못 부른다**(조합 IP 화이트리스트 + 계정 정지 위험 + 세션 단일화).
> **같이 전달되는 문서** = `meetings/handoff-car-erp-infra-fpm-2026-07-26.md`(서버 실측·변경 통보).
> ⚠️ board 세션은 car-erp 코드를 건드리지 않는다. 이 문서는 **요청서**다.

## 1. 무엇을 왜

Jin 요청: board 매입예정 등록 폼에서 **차량번호 아래 「원부조회」** — ERP 차량관리에 이미 있는 그 기능(`vehicle.wonbu.button`)이다.

**등록 시점에 압류·저당·구조를 보는 게 목적**이다. 매입 후보를 board 에 올리는 단계에서 압류/저당이 걸린 차를 걸러내는 건 매입 판단에 직접 쓰인다. 지금은 ERP 로 넘어간 뒤에야 볼 수 있다.

⚠️ **NICE 조회와 혼동 금지** — 별개 기능이다.

| | **원부조회(Carmodoo)** ← 이 문서 | NICE(`NiceApiService`) |
|---|---|---|
| 출처 | 경기도자동차매매사업조합 `sh.carmodoo.com` | NICE평가정보 |
| 입력 | **차량번호만**(또는 차대번호) | 차량번호 **+ 소유자명** |
| 결과 | **압류·저당·구조** + 등록원부 제원 | 차량 제원·연식·주행거리 자동채움 |
| 접근 제약 | 조합 등록 IP + **계정 정지 위험** | IP 화이트리스트, 건당 과금 |
| 경유 | 사무실 회선 **포워드 프록시**(`CARMODOO_PROXY`) | `heymancar.com/provide` |

## 2. 왜 board 가 직접 못 부르나 (PII 가 이유가 아니다)

1. **계정 정지 위험** — `config/services.php` 주석: *"조합에 등록된 사무실 회선에서만 조회 가능. 운영서버(AWS) 직접 호출은 '등록 외 장소 조회'로 잡혀 계정 정지 위험"*. board 박스에서 부르면 조합 계정이 날아갈 수 있다.
2. **프록시가 ERP 에만 있다** — `CARMODOO_PROXY`(사무실 회선). board 에 같은 프록시를 또 물리는 건 설정 중복이고 위험만 늘린다.
3. **세션 단일화** — 아래 §4. 이게 제일 중요하다.

### 📌 데이터 전달 범위 — 제한 없음 (Jin 결정 2026-07-26)
> **"ERP랑 board는 한 세트여서 데이터 주고받아도 괜찮다. 노출될 염려가 없다."**

⇒ `CarmodooService::lookup()` 결과를 **필터 없이 그대로** 넘기면 된다. `detail` 화이트리스트 불필요(ERP 모달도 지금 `detail` 을 전부 렌더한다).
단 **board 는 저장하지 않는다** — `purchase_listings` 에 압류·저당·원부제원을 담을 컬럼이 **아예 없고, 만들지 않는다**. 표시 전용(조회 즉시 화면, 새로고침하면 사라짐). ERP 와 동일한 on-demand 스탠스다.

## 3. 요청 스펙 (제안 — 최종 형태는 car-erp 판단)

`board-portal-api.md` 에 새 절로 추가. 기존 board↔ERP HMAC 채널 재사용.

```
POST /api/internal/board/wonbu-lookup
인증: 기존 CAR_ERP_READ_HMAC_SECRET (POST = raw JSON 바이트 서명, §10 signing-requests 와 동일)
```

**요청**
```json
{ "email": "sales@...", "vehicle_number": "12가3456", "vin": "" }
```
- `vin` 은 선택(차량번호 없을 때 대체). `CarmodooService::lookup($carNum, $viNumber)` 시그니처 그대로.

**응답** — `lookup()` 반환을 그대로 통과시키면 된다:
```json
{
  "success": true,
  "summary": { "압류": 0, "저당": 1, "구조": 0 },
  "liens": [ { "type": "저당", "date": "2024-05-02", "info": "관리번호 / 채권자 / 채권가액 …" } ],
  "detail": { "차명": "…", "최초등록일": "…", "…": "…" },
  "note": null
}
```
실패: `{ "success": false, "message": "…" }` — `lookup()` 이 이미 사용자용 한글 메시지를 준다(계정 미설정·로그인 실패·세션 갱신 실패·조회 실패). **그대로 전달**하면 board 가 그대로 띄운다.

⚠️ **ERP 인스턴스 전부에 필요** — board 는 자기 짝 ERP 를 부른다(`CAR_ERP_BASE_URL`). **ssancarboard → ssancarerp**, **heymanboard → heymanerp** 둘 다 이 엔드포인트가 있어야 한다. 한쪽만 넣으면 다른 쪽은 404 degrade 로 남는다. (조합 계정·프록시 설정도 그 ERP 인스턴스에 있어야 조회가 실제로 된다.)

## 4. ⚠️ 조합 계정 · 세션 — 이 요청의 제일 위험한 지점

`CarmodooService` 주석(실측 2026-07-15):
> *"⚠️ 짧은 간격 재로그인도 '다시 시도하세요'로 거부됨 → 세션(JSESSIONID) 캐시 재사용으로 회피"*

- 조합 계정은 **1개**, 세션은 `Cache::get('carmodoo_jsessionid')` 로 **앱 전체가 하나를 공유**하고 TTL 25분이다.
- **board 를 ERP 경유로 붙이는 게 곧 세션 공유**다. board 가 자체 경로로 붙으면 세션이 둘이 되고, 두 쪽이 만료 시점에 동시에 재로그인 → **"짧은 간격 재로그인" 거부 → 양쪽 다 조회 불가**, 반복되면 계정 위험.
- ⇒ **board 요청도 반드시 ERP 의 같은 `CarmodooService`(같은 캐시 키)를 타야 한다.** 새 클라이언트를 만들지 말 것.
- 세션 만료 시 재로그인 경합이 걱정되면 `login()` 을 짧은 락으로 감싸는 것 정도가 적절하다(조회 자체엔 락 불필요).

## 5. 타임아웃 · 동시성

- Carmodoo 는 Tomcat 웹 조회라 **수 초** 수준이다. NICE(55초)와 달리 워커를 오래 잡지 않는다.
- `CarErpReadService` 기존 POST 타임아웃 **20초면 충분**(NICE 때 필요했던 90초는 여기선 불필요).
- 전역 동시 상한도 불필요하다. 다만 board 쪽에서 **버튼 중복클릭 차단**(진행 중 비활성)은 넣는다 — 조합 서버에 대한 예의이자 세션 경합 예방.
- 참고: 2026-07-26 nginx `fastcgi_read_timeout 90s` 적용은 **NICE 때문**이며 이 기능과 무관하다(무해).

## 6. board 측 구현 계획 (ERP 배포 전까지 404 degrade)

전자서명(§10) 때 성공한 순서 그대로 — board 가 먼저 만들고 ERP 가 준비되면 자동으로 살아난다:

1. `CarErpReadService::wonbuLookup($email, $vehicleNumber, $vin = '')` — 미설정/404/5xx → degrade(패널에 안내), 예외 안 던짐.
2. `/listings` **차량번호 필드 아래 「원부조회」 버튼** → read-only 접힘 패널(`_encar-history.blade.php` 와 같은 형태).
3. 표시: **압류/저당/구조 뱃지**(0=회색, 1건 이상=빨강) + 상세 표 + 원부 제원 + 조합 고지문구(`vehicle.wonbu.disclaimer` 취지 — "참고자료, 저장 안 함").
4. **무저장**(§2) · 버튼 명시 클릭만(자동 발사 금지) · i18n ko/en 양쪽 · 테스트.

## 7. 검증

- 압류/저당이 실제로 있는 차량 1건으로 board 패널에 건수·상세가 뜨는지.
- 조합 계정 미설정 ERP 에서 호출 → board 가 "계정이 설정되지 않았습니다" 를 그대로 표시하는지.
- ERP 미배포 상태에서 버튼 눌러도 화면이 안 깨지는지(degrade).
- ERP 차량관리에서 같은 차량을 조회했을 때와 **세션 충돌 없이** 둘 다 되는지(§4).

## 8. 참고

- 원본 구현: `car-erp/app/Services/CarmodooService.php` (`lookup` / `session` / `parseHtml`)
- UI 선례: `car-erp/resources/views/livewire/erp/vehicles/index.blade.php` (`openWonbuLookup`, wonbu 모달) · 라벨 `lang/ko/vehicle.php` `wonbu.*`
- 설정: `config/services.php` `carmodoo.*`(base_url·proxy) + 기능설정 DB(id/passwd/dNo, 암호화)
- 인프라 전제: `board/meetings/handoff-car-erp-infra-fpm-2026-07-26.md`

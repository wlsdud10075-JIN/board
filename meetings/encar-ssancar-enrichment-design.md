# 매물 자동채움(enrichment) 설계 — encar/ssancar 링크 → 추가폼 prefill (2026-06-18 Jin 확정)

> 영업이 매물 링크를 붙이면 **차량번호·차값·지역**을 자동으로 채워 승격 추가폼에 prefill 한다.
> `integration-A-design.md`(승격/링크파싱)의 후속. 코딩 시 이 문서가 스펙. 바꾸려면 이 문서부터 갱신.

## 한 줄 결론
**둘 다 링크 기반. 외부 호출(Python/Selenium) 불필요. 전부 board PHP 안에서.**
- **encar = 공개 JSON API** (`api.encar.com`) → Laravel `Http::get` 직접 호출.
- **ssancar = 페이지 HTML 파싱** — 그누보드 **서버렌더링**이라 `Http::get(페이지URL)` + HTML 파싱(브라우저 불필요). ⚠️ **DB 조회 아님**(Jin 결정 2026-06-19). 단 ssancar는 encar보다 데이터가 적다(아래 §ssancar 참고).

(2026-06-18 실측: encar 상세페이지를 Selenium 으로 긁는 PoC도 성공했으나, JSON API 발견으로 **불필요해짐** — API가 더 빠르고·안 깨지고·서버에 Chrome 불필요.)

## 확정 결정 (Jin, 2026-06-18)
1. **차값 = `expected_price`(매물 표시가)**. `car_cost`(협상 차값, `carPriceKrw()` 계산식 입력) 아님 — 표시가를 car_cost 에 넣으면 가격계산 오염.
2. **소유자(`owner_name`) = 수기 입력.** encar·ssancar **둘 다 소유자 없음**(2026-06-18 확인). 소유자는 car-erp 가 매입 후 NICE 조회로 채우는 값.
3. **vehicle_number 는 `IDENTITY_LOCKED`+감사** → 자동 prefill 하되 **영업이 확인 후 저장**(자동확정 금지).

## 통합 지점
```
/listings linkInput (index.blade.php:295)
  → ListingLink::parse()         [이미 있음] origin·source·encar_id·c_no 추출
  → [신규] enrich (전부 PHP)
       encar 링크   → Http::get("https://api.encar.com/v1/readside/vehicle/{encar_id}") → JSON
       ssancar 링크 → Http::get(ssancar 페이지 URL) → HTML 파싱 (페이지타입 3종 분기)
  → 추가폼 속성 prefill → 영업 확인 → 저장
```

## 필드 매핑
| 화면 | board 컬럼 | encar API 경로 | ssancar 링크(페이지 파싱) |
|---|---|---|---|
| 차량번호 | `vehicle_number` | `vehicleNo` | ✅ 페이지에 노출 (예 `06구4150`) |
| 차값(표시가) | `expected_price` | `advertisement.price` **×10000** (만원→원) | ⚠️ **USD만 노출**(수출가, `api_exchange_price`). KRW 안 찍힘 |
| 지역 | `region` | `contact.address` 파싱(시 단위) | ❌ **없음** (ssancar에 지역 데이터 자체가 없음) |
| VIN(보너스) | `vin` | `vin` | ✅ `<em id="copy_txt">` |
| 소유자 | `owner_name` | 없음 → 수기 | 없음 → 수기 |
> 보너스: encar API `category`/`spec` 에 차종·연식·주행거리·연료·색상도 있음(필요 시 확장).
> ⚠️ **encar↔ssancar 비대칭**: ssancar 링크는 **지역 못 줌, 차값은 USD**. → ssancar 매물은 지역=수기, 차값은 USD를 KRW로 환산하거나 별도 처리 필요(아래 §ssancar·열린항목).

## encar JSON API (실측 2026-06-18)
- **엔드포인트**: `GET https://api.encar.com/v1/readside/vehicle/{encar_id}`
- **인증/헤더 불필요** — 헤더 0으로도 HTTP 200 JSON (로컬 PC 실측). 매물 42116243·42176484 둘 다 정상.
- 최상위 키: `manage category advertisement contact spec photos options condition partnership contents view vehicleId vehicleType vin vehicleNo`
- **단위**: `advertisement.price` = 만원(650) → ×10000.
- **지역 파싱**: `contact.address`("대구 서구 문화로 37") → 시 단위("대구"). 도+시("경기 안산시…") → "안산".

## ssancar 링크 파싱 (실측 2026-06-19, 라이브 + 로컬소스)
ssancar는 **그누보드 서버렌더링** → `Http::get(URL)` 가 데이터 박힌 HTML 반환(브라우저 불필요). **페이지타입 3종**(`ListingLink`가 이미 구분):

| 페이지 | 파라미터 | 데이터 출처 | 비고 |
|---|---|---|---|
| `stock_car_view.php` | `c_no` | `car_db_api`(카모두) | 아래 실측 |
| `inspected_view.php` | `wr_id` | `g5_write_inspected` | **`wr_link1`에 원본 encar 링크 보유** → 거기서 encar_id 뽑아 **encar API 재활용 가능**(KRW·지역까지!) |
| `car_view.php` | `car_no` | (경매) | 파싱대상 별도확인 필요 |

**stock_car_view 실측(c_no=17463, HTTP 200):**
- 차량번호 ✅ `06구4150` / VIN ✅ `KLALA69KD6B008933`(`<em id="copy_txt">`)
- 차값 = `stock_car_view.php:28` `number_format(api_exchange_price(c_price)) . ' USD'` → **페이지엔 USD만**(예 `1,838 USD`). 원본 KRW(`c_price`)는 페이지 미노출.
- **지역 없음**(car_db_api에 지역컬럼 없음 — 2026-06-18 DB확인 + 페이지에도 없음).
- 소유자 없음.

→ **권장**: ssancar **검차(inspected) 링크는 wr_link1의 encar 링크로 encar API 태우면** encar와 동일품질(KRW·지역) 확보. stock/auction은 페이지 파싱(USD·지역없음 감수).

## 열린 항목 / 주의
1. **비공식 API** — encar 내부 엔드포인트. 변경 가능성 있음(단, 해시 CSS 스크래핑보다 안정적).
2. **운영 서버 호출 확인** — board 실서버(board.heysellcar.com) IP에서도 200 나는지 검증 필요. 막히면 UA/Referer 헤더 추가.
3. **ssancar 지역 = 없음(확정)** — ssancar 매물은 지역을 못 가져옴 → **지역=수기** 또는 미입력. (encar만 지역 자동).
4. **ssancar 차값 = USD(확정)** — stock/auction 페이지는 USD만 노출. board `expected_price`(KRW 가정)에 어떻게? 후보: (a) USD 그대로 별 필드, (b) 환율 역산 KRW, (c) inspected는 encar API로 우회해 KRW. **← 결정 필요(Jin).**
5. **car_view(경매) 파싱** — 아직 미실측. stock과 템플릿 다를 수 있음.
6. **레이트리밋** — 대량 호출 시 차단 가능. 승격(수동·건당)이라 위험 낮음.

## 구현 상태 (2026-06-19, dev)
- **`app/Services/ListingEnrichment.php`**: `byEncarId`(encar JSON API, 라이브 200 검증) + `fromSsancar`(페이지 HTML: inspected=encar 링크 정규식 우회 / stock=VIN `id="copy_txt"`+차량번호 번호판 정규식). `enrich($parsed,$url)` 라우팅, 실패=[] 안전밸브. **셀렉터 = 위 실측과 일치**(copy_txt·06구4150 패턴).
- **UI**(listings 폼): **엔카 링크칸 / ssancar 링크칸 분리**(Jin 요청, `parseLink('encar'|'ssancar')`, 둘 다 넣으면 빈칸 합쳐 채움). 빈 칸만 prefill·영업 확인 후 저장. `expected_price`(매물 표시가) 필드 신설.
- **결정 반영**: ssancar **USD 차값은 prefill 안 함**(열린항목4 미정 → 차량번호·VIN만 채움, 차값은 영업/대표 결정 후). 지역=수기(encar만 자동). car_view=best-effort.
- 테스트 6종, 총 94통과. ⚠️ **운영 호출(api.encar.com·ssancar.com) board 실서버 IP 200 검증은 배포 후**(막히면 UA/Referer).
- **3종 실링크 end-to-end 검증(2026-06-19)**: stock(c_no=6974228)→`{vin:WP1BA29Y0NDA65763, 차량번호:144가5845}` · inspected(wr_id=854)→`{차량번호:31노0558, 차값:10,500,000, 지역:광명, vin:WVWZZZ…}`(encar 우회 풀세트) · **car_view(경매, car_no=2120379900)→`[]`**: 차량번호·VIN·차값 **페이지 노출 없음**(title 에 차종/연식/주행만, Bid 가격이라 고정 차값 아님) = 경매는 enrichment 불가, 수기. → car_view 는 "미실측"이 아니라 "**측정 완료: 가져올 것 없음**".

## 참조
- `app/Support/ListingLink.php` (도메인 라우팅·식별자 추출)
- `app/Models/PurchaseListing.php` (fillable·IDENTITY_LOCKED·AUDITED)
- `meetings/integration-A-design.md` (승격 트리거·매칭키)
- PoC 스크립트(참고용, 비사용): `C:/xampp/htdocs/ssancar/encar_scraper/encar_scrape.py`

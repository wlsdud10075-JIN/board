# 인계 — ssancar.com `api_car_media.php`: board 검차영상 자동감지 폴링 대응

> **from**: board 세션 (heymanboard/ssancarboard) · **to**: ssancar.com 세션
> **작성**: 2026-07-01 · **상태**: board 쪽 빌드 착수, ssancar 쪽 **계약 확인/보강 요청**
> **성격**: 대부분 **기존 엔드포인트 재사용(신규개발 최소)** — 아래 3가지 보장만 확인·필요시 보강.

---

## 0. 한 줄 요약

board 가 이제 `api_car_media.php` 를 **주기적으로 폴링**해서, 검차팀이 ssancar 에 **영상을 올리는 순간** board 매물을 `현지확인대기 → 전달대기` 로 **자동 전진**시킨다. 지금까지는 바이어 페이지 렌더 때 **1회성**으로만 호출했지만, 이제 **감지 목적의 반복 호출**이 붙는다. 엔드포인트 스펙 자체는 그대로 쓰되, 아래 계약이 견고한지 확인해달라.

---

## 1. 배경 (왜 이걸 하나)

- 현재 board 흐름: 영업이 매입예정 등록 → **board 현지확인 화면에서 검차팀이 사진/영상/금액 입력** → `전달대기(inspected)` → 영업이 바이어에게 전달.
- Jin 결정(2026-07-01): 검차팀이 찍은 영상은 어차피 **ssancar.com 에 올라간다**. 그러면 board 에서 검차입력을 **또** 할 게 아니라, **ssancar 에 영상이 뜬 걸 감지해서 자동으로 전달대기로 넘기자.**
- board 현지확인 화면은 **수동 폴백으로 유지**(ssancar 에 영상 안 올라오는 차 = 엔카 유입 등 대비).

---

## 2. board 쪽이 하는 것 (참고 — ssancar 는 안 만져도 됨)

- 신규 스케줄 커맨드 `board:poll-ssancar-media` (2분 주기, 기존 respond.io 폴러와 동일 패턴).
- 대상 = **`status='draft'` + (vin 또는 차량번호(번호판) 보유)** 매물.
- 각 매물에 대해 `api_car_media.php` 호출 → 응답 **`videos[]` 가 비어있지 않으면** = "검차영상 올라옴" 으로 판정 → `draft → inspected(전달대기)` 전이 + 감사/이벤트 기록.
- **매칭 방식(=호출 파라미터)**: board 가 ssancar 식별자(wr_id/car_no/c_no)를 알면 **(A) type+id 직접모드**, 모르면(엔카 유입 등) **(B) vin·car_no 교차매칭**.
- 🔒 **board 안전 게이트**: 자동전이 폴러는 board 쪽 **독립 플래그(`BOARD_SSANCAR_AUTO_FORWARD`, 기본 off)** 뒤에 있다. 즉 미디어 표시용 env(`SSANCAR_MEDIA_*`)를 켜도 **아래 계약이 확인되기 전엔 board 가 상태를 자동으로 바꾸지 않는다.** ssancar 는 급할 것 없이 §5 만 확인해주면 되고, board 는 확인 후 Jin 이 플래그를 켠다.

---

## 3. board 가 `api_car_media.php` 에 의존하는 **계약** (여기가 핵심)

### 3-1. 요청 (board → ssancar) — *기존과 동일*
```
GET  {SSANCAR_MEDIA_BASE_URL}
Header: X-Api-Key: {공유키}

(A) 직접모드:   ?type=inspected|stock|auction & id={wr_id|car_no|c_no}
(B) 교차매칭:   ?vin={VIN} & car_no={번호판}     (둘 중 있는 것만, 둘 다 보내기도 함)
```

### 3-2. 응답 (ssancar → board) — *기존과 동일*
```json
{
  "ok": 1,
  "mode": "...",
  "sources": [...],
  "videos": [
    { "embed_url": "https://iframe.mediadelivery.net/embed/...",
      "hls_url": "...", "thumbnail": "...", "url": null }
  ],
  "photos": ["https://.../1.jpg", "..."]
}
```
- board 는 `videos[]` **개수>0** 만 신호로 쓴다(사진만으론 자동전진 안 함 — 영상=검차완료 신호).
- 영상은 **검차(inspected) 매물에만** 존재(기존 그대로).

### 3-3. ✅ ssancar 가 **보장해줘야 하는 3가지** (확인 요청)

1. **업로드 전 = 빈 `videos[]`, 업로드 후 = 채워진 `videos[]`.**
   → board 는 "empty → non-empty" 전이로 "영상 떴다"를 감지한다. 업로드 전에 스텁/플레이스홀더 video 객체가 나오면 **오탐(가짜 전달대기 전진)**. 업로드 전엔 반드시 빈 배열이어야 함.

2. **(B) vin·car_no 교차매칭이 검차(inspected) 엔트리를 정확히 찾는다.**
   → board 는 Jin 결정으로 **vin 또는 번호판 중 하나만 맞아도** 자동 매칭한다. 그래서 **번호판 매칭 정밀도**가 중요:
   - 같은 번호판이 여러 차(폐차 후 재사용 등)에 붙어 **엉뚱한 차 영상**이 매칭되면 안 됨. **vin 우선, 번호판은 보조**로 매칭하고, 애매하면 매칭 안 하는(빈 배열) 쪽이 안전.
   - board 쪽 안전망: 자동전진은 `전달대기`까지만이고, **실제 바이어 전송은 영업이 드로어에서 영상 눈으로 확인 후** 누른다. 그래도 오매칭은 노이즈이므로 정밀도 유지 요망.

3. **엔카 유입차 커버리지 확인(운영 질문).**
   → ssancar.com 카탈로그에 **없던** 차(엔카에서 매입, ssancar wr_id/c_no 없음)를 검차팀이 검차하면, 그 영상이 ssancar 에 **vin/번호판으로 조회 가능한 inspected 엔트리**로 올라가는가?
   - 올라간다면 board 가 (B)로 자동감지 가능.
   - **안 올라간다면** 그 차들은 board 현지확인 화면(수동 폴백)으로 처리 → 정상. 다만 "어떤 차가 ssancar 에 실리고 어떤 차가 안 실리는지" 운영 기준을 알려주면 board UX 를 거기 맞춘다.

---

## 4. 호출량 / 부하 (heads-up)

- board 폴러는 **2분마다** `draft` 매물 수만큼 호출(각 매물 1콜). draft 가 N개면 최대 2분당 N콜.
- 완화: board 는 응답을 **키별 10분 캐시**(빈 결과 포함)한다 → 같은 draft 는 10분에 1콜로 수렴. 실호출은 (draft 수)/10분 수준.
- 그래도 draft 가 많아질 수 있으니 **레이트리밋/차단이 있으면 알려달라**. 필요하면 board 가 배치 간격을 늘리거나, ssancar 가 **VIN 리스트 벌크조회 엔드포인트**(선택)를 열어주면 콜 수를 크게 줄일 수 있음(둘 중 편한 쪽, 필수 아님).

---

## 5. ssancar 세션 체크리스트 (이것만 답 주면 됨)

- [ ] (3-3-1) 영상 업로드 **전엔 `videos[]` 빈 배열** 반환 확정? (스텁 없음)
- [ ] (3-3-2) `?vin=&car_no=` 교차매칭이 inspected 엔트리를 정확히 찾음? **vin 우선, 번호판 보조** 매칭 정책 OK?
- [ ] (3-3-3) 엔카 유입(ssancar id 없는) 검차차도 vin/번호판으로 조회되는 inspected 엔트리로 올라가나? (예/아니오 + 운영기준)
- [ ] (4) 폴링 레이트리밋 이슈 있나? 벌크조회 엔드포인트 필요/가능 여부?
- [ ] 응답 스키마(`ok/videos[]/photos[]`, `videos[].embed_url`) **현행 유지** 확정? (board 가 여기에 의존)

→ **대부분 "현행 그대로 OK" 면 ssancar 쪽 코드변경 0.** 3-3-1(스텁 없음)·3-3-2(번호판 오매칭 방지)만 확인/보강해주면 충분.

---

## 6. 권위/상호링크

- board 소비 클라이언트: `app/Services/SsancarMediaService.php` (요청/응답 스펙 권위 — 위 §3 은 이걸 그대로 옮긴 것).
- board 미디어 해석: `PurchaseListing::ssancarMediaParams()` (A/B 모드 선택) + `BuyerViewController`(바이어페이지 현행 사용처).
- ssancar 미디어 배경: board memory `board-ssancar-cdn-video-link` (검차영상 CDN 링크전송, 2026-06-30 배포).
- 설정: board `config/services.php` → `ssancar_media.base_url / api_key` (`.env` SSANCAR_MEDIA_BASE_URL / SSANCAR_MEDIA_API_KEY, 두 박스 세팅 필요).

> ⚠️ 크로스세션 규칙: 이 문서는 board repo 의 `.md`(dev 전용, master 미배포). ssancar 쪽 결정/변경은 **ssancar 쪽 커밋된 파일**에 남겨서 회신해달라(세션 메모리는 안 넘어옴).

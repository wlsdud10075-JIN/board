# 인계: car-erp — 선적묶음 "취소"가 안 됨 (근본원인 확정)

작성: board 세션(Jin) · 2026-07-02
받는 곳: **car-erp 세션**
증상(Jin): board 영업포털에서 선적묶음 취소 → **에러도 안 뜨고 "생성0/갱신0"만, 실제로는 취소 안 됨.** (car-erp에서 직접 취소하면 board에도 반영됨 = 읽기 경로는 정상.)

## 근본원인 (로컬 재현으로 확정 — 추정 아님)
board 취소 = "그 묶음 빼고 남은 desired 전체 재전송 → car-erp가 빠진 requested 자동취소"(선언형, 스펙 §5-2). **바이어당 묶음이 1개면 취소 시 `bundles: []`(빈 배열)** 을 보내야 함.

로컬 board→car-erp(8001) 실측 체인:
1. board가 `POST /api/internal/board/shipping-requests/sync` 에 `{"bundles":[]}` 전송.
2. car-erp `ShippingRequestController::sync` 검증 `'bundles' => ['required','array','min:1']` → 빈 배열 **ValidationException**.
3. **board 요청에 `Accept: application/json` 이 없어서**(과거 코드) Laravel이 422 JSON 대신 **웹식 302 리다이렉트(back → `/`)** 를 반환.
4. board의 HTTP 클라가 302를 **GET으로 따라가** car-erp 웹 HTML(200)에 착지.
5. board `send()`: 200 → ok=true, `json()`=null → `count($res['data']['created'] ?? [])` = 전부 0.
6. → **에러 없이 "생성0/갱신0", 실제 취소 0.** (Jin 증상과 정확히 일치.)

실측 근거:
- 같은 서명: `GET /bundles` → 200 JSON / `POST /sync {bundles:[]}` → **302 → http://…/**(리다이렉트 끄고 캡처).
- 같은 POST에 **`Accept: application/json`만 붙이면 → 422 JSON** `{"message":"The bundles field is required."}`. ← 302의 정체 = 검증실패의 웹 렌더.
- 다른 POST `change-request`는 검증 통과(유효 body)라 컨트롤러 도달(403) — sync만 302였던 이유.

## board 쪽 — **이미 수정(dev 커밋)**
`app/Services/CarErpReadService.php` 의 `send()`·`document()` HTTP 호출에 **`->acceptJson()` 추가.** 이제 car-erp 오류가 웹 302가 아니라 JSON 상태코드로 옴 → 빈 sync가 **ok=false/422** 로 잡혀 조용히 안 묻힘(검증: 빈 sync now ok=false status=422). ⚠️ 단 이건 "실패가 보이게" 만든 것 — **취소가 실제로 되게 하려면 아래 car-erp 수정이 필요.**

## car-erp 에서 해줘야 할 것 (취소가 실제로 되게)
`app/Http/Controllers/Api/Internal/ShippingRequestController.php` `sync()` 검증:
```php
'bundles' => ['required', 'array', 'min:1'],   // ← 빈 배열 거부
```
→ **`['present', 'array']` 로 교체** (빈 배열 허용 = "내 requested 전체 취소", 스펙 §5-2).
- ⚠️ **`required`·`min:1` 둘 다 제거.** Laravel에서 `required`는 빈 배열도 단독 거부하므로 `min:1`만 빼면 여전히 422(반쪽 수정 함정).
- 교체 후: 빈 `bundles` → 생성/갱신 루프 skip → 기존 자동취소 로직(이 파일 line 196-203)이 본인 open `requested` 전부 취소. (`in_progress`는 line 198에서 제외 = 관리 착수분 보호.)

### 안전성 (게이트 완화가 안전한 이유)
- 자동취소는 `requested` 행만. board가 빈 payload를 보내는 경우는 `cancelBundle`이 유일한 차-보유 requested 묶음을 취소할 때뿐(= cancel-all == 그 한 묶음). board엔 오발 빈-전송 방지 가드(로딩 degrade 차단 / buyer_id 누락 차단)가 이미 있음.

### 스펙 갱신
`docs/integration/board-portal-api.md` §5 sync 항목에 "빈 bundles = 전체 취소" 명시(현재 min:1 뉘앙스면 정정).

## 검증 (car-erp 반영 후)
- requested 묶음 1개만 있을 때 board 취소 → **200** `{cancelled:[...]}` → 목록에서 사라짐.
- requested 2개 중 1개 취소 → 남은 1개 유지, 취소분만 cancelled(회귀).
- `in_progress` 묶음은 자동취소 안 됨(변경요청 경로 유지) 재확인.

## 크로스레포 원칙
- 검증 완화는 car-erp 파일에, **car-erp 세션에서 커밋.** board 쪽(acceptJson)은 dev 커밋 완료.

# 인계 — ① 판매가 나중에 채우기(fill-if-empty) ② 선적 계획에서 서류·서명

> **받는 쪽 = car-erp 세션**. 작성 = board 세션, 2026-08-18, Jin 요청.
> 권위 = car-erp `docs/integration/purchase-sync-receiver.md`(연동 B 수신) + `board-portal-api.md §6·§10`.

---

## 안건 ① — 급해서 매입가만 보낸 차의 **판매가를 나중에 채우면 ERP 에 반영**

### 무엇을 원하나 (Jin 2026-08-18)

> "급해서 차량정보만 넣고 매입가는 들어가고 **판매가·통화·운임비를 기재 안 한 상태로** ERP 로 보내게 되면,
> **최초 판매가를 적을 시 ERP 에 자동 적용**이 되게 할 수 있나? 최초 판매가 기입을 잘 이용하면 될 것도 같은데."

### board 쪽 현황 (확인함)

- **판매가 없이 보내는 건 지금도 된다.** 판매가·통화·환율 필수 락은 **셀프검차매입(`isSelfInspection`)에만** 걸려 있고,
  일반 매입 경로는 `hasSyncableAmount`(차값 또는 final_price)만 통과하면 `won` 이 된다.
- 그때 ERP 는 판매 pre-fill 을 보류한다(수신측 `sale_price>0 && rate>0` 일 때만 저장) → **판매탭이 빈 채로 차량 생성**. 정상 동작.

### 🚨 막는 지점은 딱 하나 — 멱등 스킵

`PurchaseSyncController` (실측):

```php
$existing = Vehicle::where('vehicle_number', $data['vehicle_number'])->first();
if ($existing) {
    $synced = $this->syncAttachments($existing, $data['attachments'] ?? []);   // 첨부만 보강
    return response()->json([...], 200);                                        // 금액은 손대지 않음
}
```

⇒ board 가 나중에 판매가를 채워 **재전송해도 ERP 는 첨부만 받고 금액은 무시**한다.

### 요청 — 멱등 경로에서 **비어 있는 판매 필드만 채우기**

- 대상(제안) = `sale_price` · `sale_currency` · `sale_exchange_rate` · `transport_fee` · `buyer_id` · `consignee_id`.
- **이미 값이 있으면 건드리지 않는다**(관리가 ERP 에서 고친 값 보호). 운임비 1/N 에서 정한 원칙과 동일.
- ✅ **근거가 ERP 안에 이미 있다** — `Vehicle` 원장 잠금 가드가
  *"빈 값(0/null) → 첫 입력은 retroactive 변경이 아니라 최초 set 이므로 통과"* 이고,
  주석이 **"매입 잔금 confirm 후 영업이 판매가·바이어 처음 입력하는 정상 흐름 보호"** 라고 적고 있다.
  Jin 이 말한 "최초 판매가 기입"이 정확히 이것이다.
- 응답에 **무엇을 채웠는지** 돌려주면 board 가 "반영됨/이미 값 있어 스킵"을 구분해 보여줄 수 있다(선택).

### 🚨 반드시 정해줘야 할 것 — `sale_date`

수신측에 **`chk_sale_required` CHECK**(`sale_price>0` → `sale_date`·`exchange_rate>0` 필수)가 있다.
**board 는 `sale_date` 를 모른다**(판매일 개념이 board 에 없다).

⇒ fill-if-empty 를 켜는 순간 **CHECK 위반으로 실패**할 수 있고, **board 는 200 만 보고 성공으로 기록**한다
(연동 B 로그에 사유가 안 남는 건 이미 알려진 함정 = board `SKILLS.md` #21).

**셋 중 하나를 정해서 알려줄 것**:
1. ERP 가 수신일(`now()`)로 채운다 — board 무변경. **권장**
2. board 가 `sale_date` 를 실어 보낸다 — board 에 입력칸이 새로 필요(무엇을 판매일로 볼지 정의 필요)
3. `sale_date` 없으면 판매 필드를 통째로 **안 채우고**, 그 사실을 응답으로 알린다(조용한 실패 금지)

### board 쪽 작업 (board 세션에서 구현)

- **영업도 재전송할 수 있어야 한다**(Jin 2026-08-18 확정). 지금 재전송은 `/manage`(관리·super) 전용이고
  구현이 `car_erp_vehicle_id = null` + `status='won'` 되돌림 + 재발사다.
- ⚠️ 그리고 **`synced` 된 차의 판매가를 영업이 입력할 화면이 board 에 없다**(`/auction` 목록이 accepted·won·failed 라
  synced 는 빠진다). 이 경로 설계는 board 몫이며 **ERP 답(§`sale_date`)을 받은 뒤 만든다** — 지금 만들면
  CHECK 위반 여부를 모른 채 "된 것처럼" 보이는 화면이 된다.

---

## 안건 ② — 판매계약서·프로포마·전자서명을 **선적 계획**에서

### 무엇을 원하나

> "판매계약서, proforma invoice, 전자서명요청 이 세 가지가 **선적묶음에서 되는 것보다
> 선적 계획 쪽에서 바이어를 펼치고 차량을 n대 선택해서 활성화**가 되어야 한대."

- Jin 확정(2026-08-18): 선택 = **묶음에 담은 차량 그대로**(별도 서류용 체크박스 안 만듦).
- **board 단독으로 가능**하다 — `downloadDocs(vehicleIds, method, kind)` · `requestSignature(vehicleIds, batchId?)`
  둘 다 **차량 id 기반**이고 `batchId` 는 옵셔널이다. board 가 UI 위치만 옮긴다. **ERP API 변경 요청 없음.**
- 선적 4종(`roro_*`/`container_*`)은 **묶음 화면에 그대로 둔다**(실제 선적 단계의 서류라서).

### ❓ ERP 에 확인만 부탁 — sync 전 차량으로 발급해도 되나

선적 계획의 묶음은 **아직 ERP 에 없는 board 편집상태**다(sync 전이면 `shipping_requests` 행이 없다).
그 상태의 `vehicle_ids` 로 **`sales_contract` · `invoice`(프로포마) · 서명 세션**을 발급해도 정상인가?

- 차량 + 바이어 기준이라 묶음과 무관할 것으로 **추측**하지만 확인이 필요하다.
- 🚨 **최악은 403 이 아니라 "내용이 비거나 틀린 서류"** 다 — 에러가 안 나서 아무도 못 잡는다.
- 문제가 있다면 **어떤 조건이 갖춰져야 하는지**(예: 바이어 필수, 판매가 필요) 알려주면 board 가 그때 버튼을 비활성화한다.

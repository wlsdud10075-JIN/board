# [인계 → car-erp 세션] 매입 정산 계좌 **2개 분리** (매입가 계좌 + 매도비 계좌) — purchase-sync 확장

> 작성: board 세션(Jin) · 2026-07-03
> 받는 곳: **car-erp 세션** (이 문서를 car-erp 세션에 전달해 처리)
> board → car-erp 단방향(연동 B, `purchase-sync`). **이 문서 = 요청(ask) + 현행 확인.** car-erp 가 자기 권위 스펙(`docs/integration/purchase-sync-receiver.md`)에 반영·구현.
> 근거 계약 = board `SKILLS.md §12`, 기존 금액분해 인계 = `meetings/handoff-car-erp-amount-mapping.md`(v3).

---

## 0. 목적 (한 줄)
지금 board 가 매입 정산계좌를 **1개**(`payee_*` → car-erp `purchase_seller_*`)만 보내는데, **2개**로 나눠 보내야 한다:
- **① 매입가 계좌** = 차값−할인(**매도비 제외**)을 받는 판매자 계좌 = **기존 `payee_*` 그대로**
- **② 매도비 계좌** = 매도비(440,000 등)를 받는 **별도 계좌 = 신규 필드**

금액(매입가/매도비)은 **이미 v3 에서 분리 전송 중** — 계좌만 아직 하나다. 이번 변경 = **계좌를 금액과 같은 축으로 2개로 쪼개는 것.**

---

## 1. 현행 매핑 확인 요청 (car-erp 답변 필요 — 이게 선행)
board 는 아래대로 **보내고** 있고, 아래 ERP 컬럼은 board 가 인계로 **요청했던** 매핑이다. **실제 car-erp 구현이 이대로인지 먼저 확인 회신 바람** (특히 ★).

### 1-1. 금액 (참고 — 이미 분리됨, 변경 없음)
| board payload | 계산 | 요청했던 ERP 컬럼 | 확인 |
|---|---|---|---|
| `purchase_price_krw` | 차값 − 할인 (**매도비·배송 제외**) | `purchase_price` | ★ 실제 이 컬럼에 구입금액만 들어가는지 |
| `selling_fee_krw` | 매도비(440,000 고정) | `selling_fee` (별도 컬럼) | ★ **매도비가 별도 컬럼에 저장되는지 / 컬럼명 확인** |
| `final_price` | 총액 | (v2 fallback: `purchase_price_krw ?? final_price`) | — |

### 1-2. 계좌 (현재 1개 — 여기를 2개로)
| board payload | 요청했던 ERP 컬럼 | 확인 |
|---|---|---|
| `payee_name` | `purchase_seller_holder` | ★ |
| `payee_bank` | `purchase_seller_bank` | ★ |
| `payee_account` (암호화) | `purchase_seller_account` (AES) | ★ |

**★ 핵심 확인 질문 3개:**
1. `purchase_price` / `selling_fee` 가 실제로 **각각 별도 컬럼**으로 저장되나? (컬럼명 알려줄 것)
2. 매입탭 정산계좌 필드는 지금 **`purchase_seller_holder/bank/account` 하나뿐**인가?
3. **매도비용 두 번째 계좌 세트**를 받을 자리(컬럼 3개)를 추가할 수 있나? (UI/정산씬에서 매도비 계좌가 별도 표시되어야 하는지 포함)

---

## 2. 요청 (build) — 매도비 계좌 수신 추가
### 2-1. payload 신규 필드 (contract_version **4** — 전방호환, v1/v2/v3 그대로 수용)
기존 v3 필드 **전부 유지.** 아래 **3개만 신규 추가**(모두 nullable/optional):

| 신규 payload 필드 | 단위/타입 | 제안 ERP 매핑 컬럼 | 비고 |
|---|---|---|---|
| `selling_fee_payee_name` | string | `purchase_fee_holder` (제안) | 매도비 예금주 |
| `selling_fee_payee_bank` | string | `purchase_fee_bank` (제안) | 매도비 은행 |
| `selling_fee_payee_account` | string | `purchase_fee_account` (제안, **AES 암호화**) | 매도비 계좌번호 |

- 기존 `payee_*` (= 매입가/판매자 계좌 `purchase_seller_*`) = **의미·매핑 그대로 유지**. 이번에 이름 안 바꿈(마이그레이션 회피).
- 제안 컬럼명(`purchase_fee_*`)은 **car-erp 가 확정** — 기존 `purchase_seller_*` 와 짝이 맞게. board 는 어떤 이름이든 payload 필드만 매핑되면 됨.
- **암호화**: 매도비 계좌번호도 판매자 계좌와 동일하게 at-rest 암호화(`purchase_seller_account` 와 동일 캐스트).

### 2-2. 수신 규칙
- `contract_version: 4` 수용. 신규 3필드 값 있으면 매도비 계좌 컬럼에 채움. 없으면 null(하위호환 — v3 이하는 이 필드 자체가 없음 → 매도비 계좌 미보유로 정상).
- 멱등(기존차 재수신) 시 덮어쓸지/스킵할지 = car-erp 정책(기존 `payee_*` 정책과 동일하게).
- 정산(부가세 9%·마진)·금액 컬럼은 **건드리지 않음**. 이번 변경은 **계좌 3필드 추가뿐.**

---

## 3. board 측 대응 (board 세션이 별도 구현 — 참고, 이 문서 아님)
- 마이그: `purchase_listings` 에 `selling_fee_payee_name/bank/account` 추가(additive, `selling_fee_payee_account` = `encrypted` 캐스트).
- 입력 UI: 매입예정(`/listings`) + 경매/구매 드로어(`/auction`) 에 **매도비 계좌 입력란** 추가(기존 판매자 계좌 입력 UX 미러 — 은행 datalist + 계좌 하이픈 마스킹).
- 송신: `SyncWonListingToCarErp` payload 에 3필드 추가 + `contract_version: 4`.
- 로그: `selling_fee_payee_account` 도 `integration_events` 에 `***` 마스킹(기존 `payee_account` 와 동일).
- SKILLS §12 payload 표 갱신.
- **car-erp 먼저 배포된 뒤** board 가 v4 전송 시작(안 그럼 신규 필드는 무시되지만, 매도비 계좌가 ERP 에 안 꽂힘).

---

## 4. ⚠️ 미확정 (Jin 확정 필요 — 이 답이 있어야 board UI/문구 확정)
1. **매도비 계좌 = 누구 계좌인가?** 판매자와 **다른 대상**(매도 대행/딜러 등)인가, 아니면 같은 판매자의 다른 계좌인가? → board 입력란 라벨·기본값에 영향.
2. **매도비 계좌가 항상 있나, 선택인가?** (엔카/경매 출처별로 다른지) → 필수/선택 검증.
3. car-erp 정산씬/매입탭에서 매도비 계좌를 **판매자 계좌와 나란히 표시**해야 하나? (단순 보관 vs 화면 노출) → car-erp UI 범위.

---

## 5. 배포 순서 / 크로스레포 원칙
- **car-erp 먼저**(수신 컬럼·매핑 배포) → 그 다음 board v4 전송. (계약 필드 추가 표준 순서, SKILLS §12.)
- 이 변경은 car-erp 파일에 하고 **car-erp 세션에서 커밋**. board 는 §3 대응까지만(dev).
- ⚠️ car-erp 무수정 원칙의 확장(계좌 컬럼 추가) → **대표 승인 필요 여부는 car-erp/Jin 판단**(연동 B 금액분해 때와 동일 성격).

## 6. 체크리스트 (car-erp)
- [ ] §1 현행 확인 3개 회신 (purchase_price/selling_fee 컬럼, 계좌 컬럼 개수, 매도비 계좌 추가 가능성)
- [ ] `purchase_fee_holder/bank/account`(또는 확정 컬럼명) 신설 + AES 암호화
- [ ] `PurchaseSyncController` `contract_version:4` + 신규 3필드 수신·매핑
- [ ] `docs/integration/purchase-sync-receiver.md` 갱신(권위), board `SKILLS.md §12` 에 역링크
- [ ] (필요 시) 정산씬/매입탭 매도비 계좌 표시

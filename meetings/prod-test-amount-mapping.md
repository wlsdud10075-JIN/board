# 운영 e2e 테스트 시나리오 — 금액 매핑 v3 (다음 세션 진입점)

> 2026-06-23 작성. 이 세션에서 금액 매핑(#3) 설계·구현·배포·로컬검증까지 완결. **남은 건 운영 실거래 e2e 검증.** 새 세션은 이 문서로 이어가면 됨. 설계 근거 = `board-carerp-amount-mapping.md`, 인계 = `handoff-car-erp-amount-mapping.md`, 메모리 = [[board-amount-mapping]].

## 현재 운영 상태 (양쪽 배포 완료)
- **board** master `daba583` 배포 — 현지확인 통화확정(offer_currency) + 연동B **v3**(매입/판매 금액분해·운임비 판매통화 환산·바이어/컨사이니 드롭다운). login 200 확인.
- **car-erp** v3 수신기 + 운임비 통화버그 수정(`67a4ca5`) 배포 완료. SUPPORTED_VERSIONS=[1,2,3], transport_fee 판매통화 그대로 저장.
- buyers/consignees 목록 엔드포인트(영업 **본인 스코프**, IDOR 격리), 도착 알람 = car-erp 구현됨.
- **로컬 검증 통과**: EUR 거래 시 운임비 환산 정확(미수금 운임 KRW = 실제 USD운임, 구버그 7.1% 부풀음 해소).

## ⚠️ 테스트 전 확인
1. **대표 승인** — 연동B v3 확장(car-erp 무수정 예외). Jin 권한으로 정리(car-erp가 "Jin 권한" 명시). 됐는지 확인.
2. board 영업 계정의 `car_erp_salesman_email` 매핑 — car-erp 활성 salesman 과 맞아야 buyers 드롭다운·영업매칭 동작(예: moo@board.test→moo@car-erp.test). 안 맞으면 드롭다운 빈 목록(=수동).
3. car-erp에 해당 영업의 **바이어/컨사이니가 미리 등록**돼 있어야 드롭다운에 뜸(신규 바이어=공란→car-erp 수동).

## 운영 e2e 시나리오 (EUR 바이어 차량 권장 — 운임비 통화 검증 핵심)
1. **영업** `/listings` 매입예정 추가: 엔카/싼카 링크 붙여 자동채움 → 차값·할인율·매도비·배송(USD) 입력 + 차량사진+서류 첨부. (싼카면 매물표시가 통화 택1.)
2. **현지확인** `/inspection` 드로어: 금액 확인 → **금액산정 통화를 EUR 선택 후 저장**(= offer_currency 확정, 환율 스냅샷) → 바이어 전달(견적 EUR 금액+외관사진 발송).
3. **바이어 회신** `/verdicts`: 해당 바이어 수락.
4. **경매/구매** `/auction` 드로어: **바이어·컨사이니 드롭다운 선택**(car-erp 목록) + 계좌정보 입력 → **구매확정(won)**.
5. **자동**: won → 연동B v3 push → car-erp 차량 생성 + 도착 알람.

## 검증 체크리스트
**board 측** (`/audit` 또는 integration_events 마지막 purchase_sync 행):
- [ ] `contract_version` = 3, response 200/201
- [ ] payload: `purchase_price_krw`(구입금액=차값−할인, 매도비·배송 제외) / `selling_fee_krw`(매도비) / `transport_fee`(**판매통화** 환산) / `sale_price`(차량금액→판매통화) / `sale_currency`(EUR) / `sale_exchange_rate` / `buyer_id` / `consignee_id`
- [ ] `purchase_listings.car_erp_vehicle_id` 채워짐 + 상태 synced

**car-erp 측** (생성된 차량):
- [ ] 매입가(purchase_price) = 구입금액 KRW (매도비·배송 안 섞임 = 부풀지 않음)
- [ ] 매도비(selling_fee) KRW
- [ ] 판매가(sale_price)/통화(currency=EUR)/환율(exchange_rate) pre-fill
- [ ] **운임비(transport_fee) = 판매통화(EUR)** — ⚠️ 미수금 운임 KRW = 실제 USD운임과 일치(EUR환율로 부풀지 않는지)
- [ ] 바이어/컨사이니 FK 세팅
- [ ] 첨부탭에 차량사진+서류
- [ ] 정산계좌(purchase_seller_*) + 도착 알람/뱃지 [관리]에 뜸
- [ ] 판매가/환율은 관리가 그 시점 환율로 미세조정(편집 가능) 확인

## 검증 핵심 (이게 맞으면 설계 OK)
EUR 차량에서 **car-erp 미수금의 운임비 KRW ≈ (shipping_usd × 당시 USD환율)**. 예: 1640 USD·USD환율1400 → 약 2,296,000원. 만약 2,460,000원(=1640×EUR환율1500)처럼 나오면 = 구버그 재발(transport를 USD raw로 받은 것) → car-erp line138 재확인.

## 이 세션의 다른 배포물 (참고, 별개 검증 불요)
기능설정(브랜드 HeymanBoard·영어토글)·로그인 화면 car-erp 정렬·i18n Phase 0(chrome+8업무화면)·내설정 수정·계정삭제 버튼 숨김 — 전부 master 배포됨.

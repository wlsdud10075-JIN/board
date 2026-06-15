# board 연동 전체 흐름 (ssancar → respond.io → board → car-erp)

> 작성 2026-06-15. 실제 respond.io 대화(BMW 320d #6915603, 바이어 Qendrim Selaci/Kosovo)로 점검 완료.
> 연동 A/B 착수 전 참조용. 코드 아님(설계·결정 기록).

## 1. 한 줄 흐름

```
바이어        respond.io           board (업무보드)                         car-erp (원장)
(WhatsApp)    (채팅 허브·협상)      매입예정→현지확인→경매/구매              매입·정산·통관·B/L·DHL
   │①클릭        │②c_no+사진+신원     │③등록 ④검차 ⑤사진전달 ⑥회신 ⑦확정       │⑧차량등록·매입 ⑨이후공정
   └─────────────┴────────────────────┴──────────────────────────────────────┘
                          c_no 가 ①→⑧ 전 구간을 꿰는 조인키
```

## 2. 단계별 (누가/어디 · status · 현재 · 연동후)

| # | 단계 | 시스템/화면 | board status | 현재 | 연동 후 |
|---|---|---|---|---|---|
| ① | 바이어가 ssancar 매물 보고 채팅 | ssancar→respond.io | — | 작동중(외부) | 동일 |
| ② | **거른** 문의가 board로(c_no·번호판·바이어) | respond.io→매입예정 | draft | 🟡 영업 수동등록 | 🔴 연동A-in(영업 태그 트리거→자동전송) |
| ③ | 차량·금액·지역·(입금정보) 입력 | 매입예정(영업) | draft | 🟢 됨 | 🟢 |
| ④ | 지역배정→검차(사진/영상·메모·최종금액) | 현지확인 | draft | 🟢 됨(영상 제외) | 🟢 |
| ⑤ | 사진+최종금액 바이어 전달 | board→respond.io→바이어 | awaiting_buyer | 🟡 상태만(수동전송) | 🔴 연동A-out 자동전송 |
| ⑥ | 바이어 수락/거절 회신 | respond.io→board | accepted/rejected | 🟡 영업 수동기록 | 🔴 연동A-in(버튼+사람확인) |
| ⑦ | 낙찰/구매확정(+입금정보) | 경매/구매 | won/failed | 🟢 됨 | 🟢 |
| ⑧ | car-erp 차량등록+매입진행+영업자동매칭 | board→car-erp | synced | 🔴 미연결(수동등록) | 🔴 연동B 자동push |
| ⑨ | 입금·통관·B/L·DHL | car-erp | — | 작동중 | 동일 |

> 🟢 board 내부(③④⑦)=완성. 🔴 자동화 대상=양 끝(②⑤⑥=연동A, ⑧=연동B). 지금도 전 과정 수동 가능.

## 3. 채팅에서 들어오는 실데이터 → board 필드 (실측)

실제 채팅 머리말:
```
Hi SSANCAR, Is this car still available?
BMW 3Series(6th) 320d (#6915603)
170,583 km · Diesel · Plate: 301수1519
ssancar.com/page/stock_…
```

| 채팅 데이터 | board 필드 | 비고 |
|---|---|---|
| #6915603 | `c_no` | **ssancar 매물번호**(조인키). Encar link 와 별개 |
| Plate 301수1519 | `vehicle_number` | 번호판도 채팅에 옴 → 연동A-in 이 차량번호도 프리필 가능 |
| 바이어(Qendrim/Kosovo/딜러) | `buyer_name` + `respond_contact_id` | 신원·국가 |
| (Encar 별도 링크) | `encar_url` | 영업이 매입 sourcing 으로 입력(ssancar c_no 와 다름) |

## 4. 확정 결정 (실 대화로 검증됨)

1. **board 진입 = 거른 것만 (반자동)**: 에이전트가 국가·신원 먼저 확인 후 진행 → 모든 문의 자동생성 ❌. 트리거 = **영업이 respond.io 에서 태그/단축버튼 클릭**(사람) → respond.io Workflow 가 board webhook 으로 자동 전송(시스템). + board 직접등록(채팅 없는 엔카 직접매입)도 항상 가능.
2. **승인(⑥) = 버튼 + 사람확인 병행**: 실제 거래는 할인·국가·속도압박 협상이라 깔끔한 yes/no 없음. WhatsApp Quick Reply 버튼 가능하나, 텍스트로 답하는 경우 많음 → **버튼 누르면 board 에 '바이어수락' 자동, 안 누르면 영업이 수동 확정**. ⚠️ 바이어 yes ≠ 자동매입 — **실제 매입(⑦ won)은 항상 사람(경매담당)이 누름**(큰돈 결정).
3. **car-erp 관리자 자동 인지 = 됨**: car-erp `users.manager_user_id`(1관리:N영업, 2026-05-22) 존재. board 가 **영업만 이메일로 정확히 매칭**하면 → car-erp 가 그 영업의 담당 [관리]에게 **자동 솔팅 노출**. board 는 manager 신경 불필요. (전제: board 영업 이메일 = car-erp 영업 이메일. 불일치 시 `users.car_erp_salesman_id` 보정.)
4. **배송비 = 차량 크기 기준** (1640/1740/1840 USD). 목적지(국가) 무관 → board 에 목적지 필드 불필요.
5. **입금정보(정산계좌)** = board 캡처(매입예정 선택 입력 → 구매 드로어 자동표시 → 공란이면 구매담당자 입력). 계좌번호 암호화. car-erp 형식(은행 자동완성+은행별 마스킹) 미러. 끝단 저장은 연동B.

## 5. 열린 항목 (연동 착수 전 처리)

- **검차 영상 업로드**: 현재 검차 업로드는 `accept="image/*"`(사진만). 영상 필요 → ① accept 에 video 추가 ② Livewire 업로드 용량 한도 상향(영상 >12MB) ③ S3 저장(car-erp 동일 가능) ④ §28(외관만) 영상에도 적용 ⑤ 연동A-out 으로 바이어에 영상도 전달. → 별도 태스크.
- **car-erp 신규 매입건 알림**: 기본 = car-erp 목록·솔팅에 자동 등장(관리가 봄). 콕 집은 알림(슬랙/메일/뱃지) 원하면 car-erp 추가작업(대표 승인 범위).
- **연동 A 선행**: 도메인+HTTPS(respond.io 가 board webhook 호출하려면 공개 URL) + respond.io Workflow(태그→HTTP Request) 노코드 구성.
- **연동 B 선행**: car-erp purchase-sync API(대표 승인) + 큐 워커 + HMAC.

## 6. 자동화 범위 원칙

**협상은 respond.io 에서 사람(영업)이. board 는 "레일"** — 리드 기록(c_no)·검차 미디어·수락 기록·car-erp 전환. 무인 전자동이 아니라 사람이 거르고 최종확정하는 반자동이 안전(매입은 큰돈). 관련 태스크: 연동B(#1~7) → 연동A(#8~15).

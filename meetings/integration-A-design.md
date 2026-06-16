# 연동 A 설계 확정 (2026-06-15 대표 결정)

> 연동 A(respond.io ↔ board) **착수 전 합의된 설계**. 코딩 시 이 문서가 스펙. CLAUDE.md §2 와 상호참조.
> vin-drift 교훈 → 결정은 여기에 박는다. 바꾸려면 이 문서부터 갱신.

## 역할 분담 (절대 원칙)
- **respond.io** = 사람(리드·컨택트)·대화·자격관리 = **CRM**. 폼 리드·잡담·링크구경은 여기서 시작하고 여기서 끝남.
- **board** = 차 단위 매입·검차·경매 워크플로우. **차가 생겨야** 입장.
- **car-erp** = 매입확정 후 재고·원장 (연동 B).
- board 를 CRM 으로 만들지 말 것 — 리드/대화는 respond.io 가 쥔다.

## 유입 3종류
| 유입 | 정체 | "차" 키 | 들어갈 곳 |
|---|---|---|---|
| ssancar.com 클릭 | 차 문의 | `c_no` (링크에 옴) | board (영업 승격 시) |
| Encar 채팅 링크 | 차 문의 | `encar_id` (URL에서 추출) | board (영업 승격 시) |
| 설문폼 리드 | **사람**(딜러/개인) | 없음 | **respond.io 컨택트로 끝** (board 아님) |

- **설문폼**: `full_name/phone/email + 딜러여부 + 월구매대수`. 차 정보 없음 = 리드. respond.io 워크플로우가 **영어 고정 키**(`are_you_a_car_dealer...`, `how_many_vehicles...`)로 파싱 → contact 속성/태그 → 영업 라우팅. board 무관.

## board 입장 트리거 = 영업 "승격" (대표 결정: 모든 소스 (b) 통일)
- **링크 도착 ≠ 트리거.** 링크는 한 방에 수십 개씩 날아다님(구경·비교) → 자동생성하면 쓰레기 범람.
- **진짜 트리거 = "이 바이어가 이 차를 진짜 산다"는 영업의 판단 = 수동 승격.** ssancar 포함 **전 소스 동일**(2026-06-15 대표: (b)안).
  - ssancar 가 c_no 로 자동 draft 생성하는 (a)안은 **기각** — 자동인 건 c_no *캡처*지 listing 생성이 아님(노이즈 방지).
- **자동화의 역할 = 판단 대행이 아니라 승격을 *한 클릭*으로**: 영업이 링크 붙이면 board 가 `c_no`/`encar_id` 자동추출 + 중복체크 + 대화(`respond_conversation_id`) 자동연결.
- 잡담·구경·그냥 끝난 방 → **board 트리거 0, 깨끗 유지.**

| 상황 | board 트리거? |
|---|---|
| 폼 리드(사람만) | ❌ respond.io 로 끝 |
| 잡담·링크 구경·비교 | ❌ 없음 |
| 영업이 "이 차 진지" → 승격 | ✅ **여기서** listing 생성 (c_no/encar_id 자동추출) |
| 그 차 수락/거절 회신 | ✅ conversation_id 로 매칭 → buyer_verdict |

## 매칭 키
- **`respond_conversation_id`** = 스파인(고객/방 식별, 모든 유입 공통, respond.io 가 항상 부여 → 자체 발번 불필요).
- **`c_no`** = ssancar 차 식별 (있으면 차 자동매칭).
- **`encar_id`** = Encar 차 식별 (URL 정규식 추출, **신규 컬럼**). c_no 와 별도 — 두 의미 섞지 말 것(drift 방지).
- **한 컨택트 : 여러 listing** (시간 지나며 차 관심 누적). board listing 은 차 1개당 1행.

## webhook 선별 (중요)
- respond.io 의 **모든 이벤트를 board 로 쏘면 안 됨.** board inbound webhook = **차 관련 이벤트만** (영업 승격 컨텍스트 / 그 차 수락·거절). 리드폼·잡담은 respond.io 안에서 끝 → board 범람 방지.

## Encar 특이사항
- API 없음 → 바이어가 채팅으로 링크 전송, **한 방에 링크 다수**.
- 처리 = **반자동**: ① inbound 가 conversation_id + 링크들 캡처(고객·링크 안 놓침) ② 영업이 진짜 건만 승격(encar_id 자동추출·중복체크) ③ 회신은 차 1개씩 전송 → 그 차에 귀속(상담원 보조).

## 링크 샘플 분석 + 키 추출 확정 (2026-06-16, 실링크 4+3 샘플 기반)

### Encar — 정규식 확정 ✅ (열린 항목 해소)
실샘플 4개 전부 `fem.encar.com/cars/detail/{숫자}?...` 일관.
- **주 추출**: `/encar\.com\/cars\/detail\/(\d+)/i` → encar_id (서브도메인 fem./www./m. 무관, host 는 `encar.com` 만 고정).
- **보조 폴백**: `/[?&]carid=(\d+)/i` (일부 링크에 carid= 중복 존재).
- 샘플: 42176484 · 42116243 · 42114725 · 42171072.

### ssancar — c_no 단일 스파인 가정 **폐기**, 다형 저장으로 변경 ⚠️
실샘플: ssancar 링크는 **페이지마다 식별자 3종**으로 나옴(대표 확인: 3종 다 채팅에 올 수 있음).
| 페이지 | 파라미터 | 샘플 |
|---|---|---|
| `stock_car_view.php` | `c_no` | 6915603 |
| `inspected_view.php` | `wr_id` | 786 |
| `car_view.php` | `car_no` | 1871585 |

- **⚠️ 제약 발견 (2026-06-16 코드확인)**: `c_no` 컬럼은 **이미 존재 + LIVE 연동 B 가 car-erp 로 전송 중** (`SyncWonListingToCarErp` payload) + listings/auction/manage/audit 4화면·필터인덱스·시더에 박힘. → **c_no 폐기/리네임 금지**(운영 연동 B + car-erp 수신계약 깨짐).
- **결정 (개정)**: `c_no` **유지**(stock_car_view 링크 = c_no 채움, 기존 스파인 의미 그대로). 비-c_no ssancar 링크(`wr_id`/`car_no`) 저장 모델은 **A2(승격/추출 슬라이스)에서 확정** — 후보: 단일 generic `ssancar_ref`("wr_id:786") 추가 vs 풀 source URL 저장. **A1(verdict 수신)에는 불필요** → 미룸.
  - 추출 정규식(확정): `/[?&]c_no=(\d+)/` · `/[?&]wr_id=(\d+)/` · `/[?&]car_no=(\d+)/` (먼저 매치되는 것).
- **근거(드리프트 안전)**: c_no 는 **하드 조인키 아님** — 연동 B 실매칭키 = `vehicle_number + owner_name`(메모리 정정본). c_no/wr_id/car_no 는 ssancar **추적 실**일 뿐. 셋이 동일차인지·ssancar 변환 가능한지 **몰라도 됨**. listing 중복은 `vehicle_number`(IDENTITY_LOCKED) 가 막음.
- **A1 신규 컬럼만**: `respond_conversation_id`(스파인·indexed) + `respond_contact_id` + `encar_id`(indexed). c_no 는 이미 있음.

## ⚠️ respond.io 플랜 제약 (2026-06-16 워크스페이스 실확인) — 층 2 선행조건
- respond.io **Workflow 액션 `HTTP 요청`(임의 끝점 HTTP 전송) = 유료 업그레이드 필요**. 트리거 `수신 웹후크`도 유료.
- **이게 respond.io → board 푸시의 유일한 네이티브 경로** — 무료 액션(메시지/태그/필드/분기/할당/대화열기·닫기/댓글/대기/다른워크플로우 트리거 등)으로는 board 로 임의 데이터 전송 불가.
- **영향**: 층 1(board 수신 코드)은 무관하게 완성·테스트 가능(✅ 2026-06-16 완료). 하지만 **층 2(respond.io 가 실제로 webhook 발사) = 플랜 업그레이드(비용·대표 승인) 선행.**
- **conversation_id/contact_id 변수 주입 확인도 업그레이드 후 가능**(HTTP 요청 설정창을 못 여니까). 단 respond.io 같은 성숙 CRM 은 contact/conversation 변수 노출이 기본 → 위험 낮음.
- verdict 메커니즘 후보(트리거): `연락처 태그 업데이트됨`/`연락처 필드 업데이트됨`(상담원이 수락/거절 태그·필드) 또는 `수동 트리거`/`지름길`(차별 전송 → 다중차 귀속 해결). 어느 쪽이든 액션 단계에서 `HTTP 요청` 필요.

## 착수 때 확정할 열린 항목 (지금 미정)
- **다중 차 방의 verdict 귀속**: 방에 차 N개면 "yes"가 어느 차인지 자동 불명 → 초기엔 상담원 보조(차별 전송→귀속), 볼륨 크면 버튼형 구조화 메시지.
- **재협상 경로**: 검차 중 금액 변동(차상태 따라 ↑↓) → 바이어 재통보 시, 현 상태머신은 accepted/rejected 가 종착이라 재오퍼 경로 없음. 필요하면 "재통보 시 awaiting_buyer 복귀" 추가 검토.
- ~~`encar_id` 추출 정규식~~ → ✅ 위에서 확정.

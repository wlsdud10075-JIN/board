# 인수인계 — car-erp 수신측 (연동 B 확장: 차량사진/첨부)

> **왜 이 문서가 있나**: Claude 세션끼리는 실시간 통신 채널이 없다(컨텍스트·메모리 격리). board↔car-erp 협업의 유일한 다리 = **git 커밋된 파일 + 사용자(Jin)가 옮기는 인계 문서**. board 세션이 만든 이 노트를 Jin 이 car-erp 세션에 전달한다.
>
> - **보내는 절반(권위)** = board `SKILLS.md §12` (연동 B 계약, 이번에 `contract_version: 2` 로 확장).
> - **받는 절반(권위)** = car-erp `docs/integration/purchase-sync-receiver.md` (← car-erp 세션이 갱신·커밋).
> - 상호링크, **복사 금지(drift)**.

## 한 줄 요약
영업이 카톡으로 주던 **차량 사진(외관 5장) + 서류(차량등록증 등)** 를 board 에서 직접 업로드 → 차량이 `won→synced`(연동 B) 될 때 첨부 목록(**S3 키만, 바이트 아님**)을 payload 에 실어 보냄 → car-erp 가 받아 **차량 수정탭 → 기본정보탭 → 차량사진/첨부(최대 10건)** 에 행을 만든다. 이후 [관리] 가 보고, 빠진 건 추가한다. **S3 버킷 공유**(`heysellcar-erp-docs`)라 바이트 복사 없이 키 참조만으로 동작.

---

## ⚠️ 선행 게이트 2개 (착수 전 반드시)

1. **board 서류 보유 = 정책 번복 (board-side 해소됨, 2026-06-22)**
   - board 의 `서류 미보유` 원칙(`CLAUDE.md §6e`)은 payee 예외 때 의식적으로 남긴 레드라인이라 원래 대표 사인오프 대상이었다. **Jin 결정으로 진행**: 근거 = ① 차량등록증은 **주소·RRN 마스킹본** ② car-erp 가 NICE 로 권위데이터 재등록 → board 보유분은 **참고사본** ③ **실행파일만 차단**. 격리 = 전용 prefix + 서류 바이어전송 제외. (Jin≠대표지만 대표 부재 + 위 근거.) → **board 쪽은 게이트 해소, 사진+서류 함께 구현 완료.**
2. **car-erp 무수정 예외 확장 승인 (car-erp-side, 여전히 필요)**
   - 연동 B(purchase-sync) 자체는 승인됐지만, **첨부 수신은 그 위의 신규 변경** → car-erp 쪽에서 별도 대표 승인 필요. `contract_version` bump + 전방호환(모르는 필드 무시) + **car-erp 먼저 배포**.

---

## board 가 보내는 것 (계약 확장 — `contract_version: 2`)

기존 payload(SKILLS §12)에 **`attachments` 배열 추가**. 나머지 필드 불변.

```json
{
  "contract_version": 2,
  "vehicle_number": "...", "owner_name": "...", "source": "encar|auction",
  "final_price": 0, "salesman_email": "...", "car_erp_salesman_id": null,
  "c_no": null, "payee_name": null, "payee_bank": null, "payee_account": null,
  "attachments": [
    { "s3_path": "purchase-board/sales/photos/123/abc.jpg", "original_name": "front.jpg", "kind": "sales_photo", "sort": 1 },
    { "s3_path": "purchase-board/sales/documents/123/reg.pdf", "original_name": "차량등록증.pdf", "kind": "sales_document", "sort": 2 }
  ]
}
```

- **`attachments`** = 영업이 board 에 올린 **별도 세트만**(검차 사진은 포함 안 함 — 그건 바이어 전송 파이프 §28 전용). `kind ∈ {sales_photo, sales_document}`.
- **`s3_path`** = 공유 버킷 `heysellcar-erp-docs` 내 키. **바이트 전송 없음** → payload 가볍다.
- **1회 발사**: `won→synced` 전이 시 한 번(`car_erp_vehicle_id` null 가드로 멱등). **Jin 확인: 영업은 대부분 won 전에 자료 확보** → 재전송 경로 불필요. synced 후 추가/누락 보완은 **car-erp [관리] 몫**(= Jin 이 말한 "관리가 빠진거 추가").
- 전방호환: car-erp 가 아직 `attachments` 미구현이면 **그냥 무시**(기존처럼 차량만 생성). 깨지지 않음.
- HMAC 서명 방식·엔드포인트·응답(`{vehicle_id}`) 전부 기존 §12 그대로(서명 대상 = raw body, 재직렬화 금지).

## car-erp 가 받아서 할 일 (받는 절반 — 권위는 car-erp docs)

1. payload 에 `attachments[]` 있으면, 생성/매칭된 vehicle 에 대해 **차량사진/첨부 테이블에 행 생성**(차량 수정탭 기본정보탭에 보이는 그 테이블).
   - **최대 10건 cap 준수** + **중복 제거**(같은 `s3_path` 재전송 시 스킵 — 1회 발사라 보통 무관하나 방어).
   - `kind` → car-erp 의 사진/문서 구분에 매핑(있으면). `original_name`·`sort` 보존.
2. **S3 객체 접근 방식 — car-erp 의 결정**(둘 중 택1, 트레이드오프만 제시):
   - **(A) board 키 직접 참조**: car-erp 첨부 행이 board prefix(`purchase-board/...`) 키를 그대로 가리킴. 복사 0, 가장 단순. ⚠️ 단 board 가 그 사진을 지우면(현재 `inspection_photos` 는 listing `cascadeOnDelete`) car-erp 행이 **고아(404)** 될 수 있음.
   - **(B) car-erp 가 자기 prefix 로 S3 서버사이드 복사**: 같은 버킷이라 저렴. 소유권·생명주기 깔끔(board 삭제와 무관). car-erp 쪽 복사+키 재기록 작업 추가.
   - → board 는 어느 쪽이든 **키만 보낸다**. 권장은 board 가 seed 후 손 안 대는 모델이라 **(B) 가 안전**하나, car-erp 가 판단.
3. **서류(`sales_document`) PII 처리**: car-erp 는 이미 RRN·서류를 암호화/마스킹 정책으로 보유하는 앱이므로 **서류의 적법한 종착지는 car-erp**. car-erp 기존 문서 보안 정책(접근권한·다운로드 감사)을 그대로 적용.
4. 응답·멱등·영업매칭은 기존 purchase-sync 그대로.

## 배포 순서
1. car-erp 첨부 수신 구현·배포 (전방호환이라 board 신버전 전이라도 안전).
2. board `attachments` 송신 배포 (사진 먼저, 서류는 대표 승인 후).
3. e2e: board 에서 영업 자료 올린 차 하나 won → car-erp 차량 첨부탭에 사진/서류 보이는지.

## car-erp 세션 첫 프롬프트 (그대로 붙여넣기)

```
연동 B(purchase-sync) 수신측에 "차량 첨부 수신"을 추가해줘. board 가 won 차량을
push 할 때 payload 에 attachments[] 를 함께 보낸다(영업이 board 에 올린 차량 사진+서류).
보내는 쪽 권위 스펙 = board SKILLS.md §12 (contract_version: 2, C:\xampp\htdocs\board\SKILLS.md).
이건 승인된 purchase-sync 위의 신규 변경이라 대표 승인 + 무수정 예외 확장이 필요.

[payload 추가 필드]
attachments: [{ s3_path, original_name, kind(sales_photo|sales_document), sort }]
 - s3_path = 공유 버킷 heysellcar-erp-docs 내 키 (바이트 아님, 키만).
 - 모르는 필드/미구현이면 무시(전방호환). contract_version 2.

[처리]
1. attachments 있으면, 생성/매칭된 vehicle 의 차량사진/첨부(기본정보탭, 최대 10건)에 행 생성.
   같은 s3_path 중복 스킵. kind/original_name/sort 보존.
2. S3 접근: (A) board 키 직접 참조 vs (B) car-erp prefix 로 서버사이드 복사 — 네가 결정.
   (A)는 board 가 사진 삭제 시 고아 위험(board 는 listing cascadeOnDelete). 권장 (B).
3. sales_document 는 서류(차량등록증 등, 주소·부분 RRN 포함 가능) → car-erp 기존 문서
   보안 정책(암호화/접근권한/다운로드 감사) 적용.
4. 멱등/영업매칭/응답({vehicle_id})은 기존 purchase-sync 그대로.

[문서] docs/integration/purchase-sync-receiver.md 에 attachments 수신 절 추가
 (board SKILLS §12 와 상호링크, 복사 금지/drift 방지). contract_version 2 명시.
[테스트] attachments 있는 payload → 첨부행 생성 / 없는 payload → 기존대로 / 중복 s3_path 스킵.
```

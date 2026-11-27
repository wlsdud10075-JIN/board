# 실거래 워크플로우 리허설 — 사전 검수 + 다음 세션 TODO

> 작성 2026-06-22 (Jin). 리허설 예정 = 2026-06-23. board↔car-erp 전 구간 코드 검증 결과 + 내일 막힐 지점 + 다음 세션 빌드 대상.

## 0. 한 줄 요약
자동등록 파이프라인(board 등록 → 검차 → 바이어수락 → won → car-erp 차량생성 + 사진/서류/금액/계좌 자동등록)은 **코드상 완성**. 내일 막힐 수 있는 진짜 지점은 ① **서버 S3 copy 권한 미검증** ② **일괄다운로드 미구현** ③ **board 매입계약서 미구현**. ①은 검증 절차 아래, ②③은 다음 세션 빌드 대상.

---

## 1. 코드 검증 결과 (로컬 기준, 전부 OK)

### 1-1. board 송신측 — `app/Jobs/SyncWonListingToCarErp.php`
- 가드: `status==='won' && car_erp_vehicle_id===null`(:51), 안전밸브 base_url/hmac_secret 미설정 시 no-op(:43-45).
- HMAC: `X-Board-Signature: sha256=<hex>`, raw body 서명(재직렬화 금지), `services.car_erp.hmac_secret`(:82-87).
- 대상 URL: `{services.car_erp.base_url}/api/internal/purchase-sync`.
- won→synced 전이 + 응답 `vehicle_id`→`car_erp_vehicle_id` 저장(:110-117). 트리거 = `PurchaseListing::updated` status→won(`PurchaseListing.php:217-221`).
- **payload 필드(:57-79)**: `contract_version=2`, `vehicle_number`, `owner_name`, `source`, `final_price`, `salesman_email`(=`car_erp_salesman_email ?: email`), `car_erp_salesman_id`(nullable), `c_no`, `payee_name`, `payee_bank`, `payee_account`(전송시 평문, 로그는 *** 마스킹), `attachments[]`.
- **attachments[]** = `salesAttachments` (`PurchaseListing.php:254-259`): `inspection_photos` 중 `kind ∈ {sales_photo, sales_document}` 만, **inspection(검차사진) 제외**. 각 원소 `{s3_path, original_name, kind, sort}` — **S3 키만, 바이트 아님**.
- **미전송(의도)**: vin(NICE=car-erp 책임), car_cost, discount_rate, shipping_usd, expected_price (→ final_price 에 합산됨).

### 1-2. board 저장소 — `config/board.php`
- 영업사진 prefix `purchase-board/sales/photos`(:11), 서류 `purchase-board/sales/documents`(:12), 검차 `purchase-board/inspections/vehicle-photos`(:8).
- 첨부 cap `BOARD_ATTACHMENT_MAX=10`(:15). 디스크 `BOARD_PHOTO_DISK`(로컬 public / 운영 s3)(:25).
- s3 디스크(`config/filesystems.php:51-61`): **root/prefix 없음**(bucket 만). 키 = `purchase-board/...` 가 곧 S3 객체 키.

### 1-3. car-erp 수신측 — `app/Http/Controllers/Webhook/PurchaseSyncController.php`
- 라우트 `routes/api.php:20-22` + 미들웨어 `VerifyPurchaseSyncHmac`(타이밍세이프, `services.purchase_sync.hmac_secret`) + `throttle:30,1`.
- 매칭키 = **`vehicle_number`**(VIN 아님), 멱등: 기존차 존재 시 첨부만 보강 후 기존 id 200(:78-91).
- 금액: `final_price → purchase_price`(:105).
- 계좌: `payee_name→purchase_seller_holder`, `payee_bank→purchase_seller_bank`, `payee_account→purchase_seller_account`(AES 자동암호화 `Vehicle.php:83`)(:122-124).
- 영업매칭(:272-294): `car_erp_salesman_id` override → `salesman_email`로 `Salesman` 조회 → `User->salesman` → 실패시 null(수동배정).
- NICE VIN 채우기(:240-266): 신규차 생성 직후 `owner_name` 있으면 조회, 실패 graceful.
- **첨부 수신 v2 `syncAttachments`(:168-233)**: 
  - `targetName=vehicle_docs_disk`, `sourceName=purchase_sync_inbound_disk ?: vehicle_docs_disk`, `sameDisk=source===target`.
  - 운영(둘 다 s3, 같은 버킷) → `targetDisk->copy()` **서버사이드 복사**(:212-213, 바이트 전송 X). 로컬(다름) → 스트림 교차복사.
  - 타겟 = `vehicles/{id}/synced/{md5(src)8}_{basename}` (결정적 → 재전송 멱등 dedup :199-202).
  - `vehicle_photos` 테이블에 등록(path, sort_order만; 원본명·kind 미저장), **cap 10**(:190-191), 원본없음/복사실패 graceful skip.
  - contract_version **1·2 모두 수용**(:32-48), 미지원=422.
- car-erp s3 디스크도 **root 없음**(`config/filesystems.php:71-81`) → board 키 `purchase-board/...` 가 같은 키로 해석됨.

---

## 2. ⚠️ 서버 S3 미검증 — 내일 1순위 확인 (실데이터 안 건드림)

로컬은 `board_inbound` 브리지 디스크로 통과. **운영은 S3 서버사이드 copy 라 한 번도 e2e 안 됨.** 동작 조건 3가지(코드 아닌 서버 .env/IAM):
1. **board 서버**: `BOARD_PHOTO_DISK=s3` + `AWS_BUCKET=heysellcar-erp-docs`.
2. **car-erp 서버**: `VEHICLE_DOCS_DISK=s3`, `PURCHASE_SYNC_INBOUND_DISK` **미설정**(설정 시 sameDisk=false 로 엉뚱한 디스크 읽음), `AWS_BUCKET` = board 와 **동일 버킷**.
3. **car-erp IAM 키**가 `purchase-board/*` prefix 에 `s3:GetObject` + `s3:CopyObject` 권한 보유.

### 검증 절차 (car-erp 서버, SSH `ubuntu@52.79.200.151`, 키 `~/.ssh/car_erp_key`)
```bash
# (1) 디스크 설정 — 둘 다 's3', bucket 일치 확인
php artisan tinker --execute="echo config('filesystems.vehicle_docs_disk').PHP_EOL; echo config('filesystems.purchase_sync_inbound_disk').PHP_EOL; echo config('filesystems.disks.s3.bucket');"

# (2) board prefix 읽기→car-erp prefix 서버사이드 복사 (IAM CopyObject/GetObject 검증)
php artisan tinker --execute="\$d=Storage::disk(config('filesystems.vehicle_docs_disk')); \$d->put('purchase-board/_smoke/t.txt','hi'); \$d->copy('purchase-board/_smoke/t.txt','vehicles/_smoke/c.txt'); var_dump(\$d->exists('vehicles/_smoke/c.txt')); \$d->delete(['purchase-board/_smoke/t.txt','vehicles/_smoke/c.txt']);"
```
- (2) `bool(true)` → 첨부 복사 서버 동작 확정. `false`/예외 → IAM 권한 or 버킷 불일치(=내일 막힐 지점).
- 가장 확실 = **테스트 차 1대 실제 won** → car-erp 차량 기본정보탭 사진 확인 + board `integration_events` 마지막 행 response_status 200/201.

---

## 3. 다음 세션 빌드 대상 (Jin 확정)

### TODO-A. 일괄 다운로드 (4a) — 영업 편의, 내일 리허설용
- **현재 = 미구현**(18개 blade 전수검색 0건). 사진 개별 다운로드 / S3 공유링크만 가능.
- 목표: 영업이 한 차량의 검차사진(또는 사진+서류)을 **zip 한 번에 다운**받아 메신저로 바이어에게 직접 전송(respond.io outbound advance 전까지의 임시 편의).
- 위치 후보: listings 편집드로어 / inspection 드로어 / verdicts. (영업 화면이 listings·verdicts 라 거기가 자연스러움.)
- 주의: §28 프라이버시 — 바이어 전송용이면 **외관사진만**(서류·번호판 제외) 옵션 고려. inspection 사진엔 `share_to_buyer` 토글 이미 있음.

### TODO-B. board 매입계약서 다운로드 (4b)
- **현재 = 미구현**. board 에 있는 "계약서"는 `/portal`의 **car-erp 선적서류**(RORO계약서·인보이스 등, `downloadDocs()` portal:238-269)뿐 — 차가 car-erp 선적단계 가야 나옴.
- 목표: board 매입 단계의 **자체 계약서**(판매자↔구매자 매입계약서) 생성·다운로드. 양식·필드(차량/금액/계좌/판매자)는 Jin 확정 필요.

### TODO-C. 금액 매칭 — Jin 조사 후 결정 (5번)
- 현재 car-erp 로 가는 금액 = **`final_price`(최종 KRW 총액) 하나** → `purchase_price`(매입가). 차값(car_cost)·운임비(shipping_usd)는 **개별 미전송**(총액 합산).
- **할인율(discount_rate) = 전송 불필요 확정.**
- ⏳ **Jin 조사 예정**: board 의 차값/운임비 등 금액이 **car-erp 차량수정>매입 의 어느 필드와 매칭돼야 하는지** 확인 → 필요하면 payload 확장(contract_version, SKILLS §12) + car-erp 수신 매핑 추가(인계문서 필요).

---

## 4. 참조
- 첨부 인계(수신측 권위) = `meetings/handoff-car-erp-vehicle-attachments.md`.
- 연동 B 계약 = `SKILLS.md §12`.
- 송신 = `app/Jobs/SyncWonListingToCarErp.php`, 수신 = car-erp `app/Http/Controllers/Webhook/PurchaseSyncController.php`.

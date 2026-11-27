# 인계 — 연동 B 첨부(사진) S3 복사가 **한 번도 성공한 적 없다** (car-erp 세션)

작성: board 세션, 2026-08-10 · 수신: **car-erp 세션** (수정은 car-erp repo에서)

## 한 줄

`PurchaseSyncController::syncAttachments()` 가 **`Storage::copy()` 반환값을 안 본다.** Laravel S3 디스크는
기본 `throw => false` 라 복사 실패가 **예외 없이 `false` 만 리턴**하는데, 코드는 그대로 `VehiclePhoto` 행을 만든다.
→ **ERP DB엔 사진 행이 있고 S3엔 객체가 없다.** 화면엔 깨진/빈 사진으로 보인다.

## 실측 (heymanerp 운영, 2026-08-10)

버킷 = `heysellcar-erp-docs` (board·car-erp 공용).

| 확인 | 결과 |
|---|---|
| `VehiclePhoto` where path like `%/synced/%` | **15건** |
| S3 `vehicles/**` 중 `/synced/` 객체 | **0건** |
| board 원본 `purchase-board/sales/photos/24/` | **5건 존재** |
| ERP 박스에서 board 원본 `exists()` | **true** (읽기 가능) |
| `vehicles/**` 전체 객체 | 1451건 (프리픽스 자체는 정상) |
| 로그 `[purchase-sync] 첨부 원본 없음` / `첨부 복사 실패` | **0건** (경고가 안 찍혔다) |

즉 **원본도 있고 읽을 수도 있는데 복사만 안 됐고, 아무도 안 알려줬다.**

검증 케이스 2건 — 둘 다 `attachments_added: 5` 로그가 찍혔지만 S3엔 0건:
- `vehicle 246` (36거2620, 2026-07-28) — 예전 것도 마찬가지다. **회귀가 아니라 처음부터 이랬다.**
- `vehicle 271` (67도4322, 2026-08-10)

## 원인 위치

`app/Http/Controllers/Webhook/PurchaseSyncController.php` — `syncAttachments()`

```php
try {
    if (! $targetDisk->exists($target)) {
        if (! $sourceDisk->exists($src)) { Log::warning('첨부 원본 없음'); continue; }
        if ($sameDisk) {
            $targetDisk->copy($src, $target);          // ← 반환값(bool) 무시
        } else {
            $targetDisk->writeStream($target, $sourceDisk->readStream($src));
        }
    }
} catch (\Throwable $e) { Log::warning('첨부 복사 실패'); continue; }

VehiclePhoto::create([...]);   // ← 복사 실패해도 여기까지 온다
```

`Illuminate\Filesystem\FilesystemAdapter::copy()` 는 `UnableToCopyFile` 을 삼키고 `false` 를 리턴한다
(`config/filesystems.php` 의 s3 디스크에 `'throw' => true` 가 없으면). `writeStream` 도 같은 성질이다.

## 고칠 것 (car-erp 세션 판단)

1. **반환값을 본다** — `if (! $targetDisk->copy(...)) { Log::warning(...); continue; }`. `writeStream` 도 동일.
   이것만으로 "행은 있는데 파일은 없는" 상태가 안 생긴다.
2. **복사 후 존재 확인** — `$targetDisk->exists($target)` 로 한 번 더 확인하고, 아니면 행을 만들지 않는다.
   (1번이 통과해도 실제로 안 올라간 이번 케이스를 잡으려면 이게 필요하다.)
3. **왜 실패하는지**는 아직 미확정 — 후보: IAM 이 `s3:PutObject`/`CopyObject` 를 `vehicles/*` 에 대해
   `purchase-board/*` 소스로 못 하게 막고 있을 가능성. `'throw' => true` 로 잠깐 켜서 실제 예외 메시지를
   보는 게 가장 빠르다. (board 는 같은 버킷 `purchase-board/` 프리픽스에 **쓰기가 되고 있다** — 업로드는 정상.)
4. **기존 15건 백필** — 원인 고친 뒤 해당 차량들 재복사. board 원본은 `purchase-board/sales/photos/{listing_id}/`
   에 그대로 남아 있다(삭제 안 함). board 쪽에서 `/manage` → `🔄 car-erp 재전송` 을 눌러도 되지만,
   **재전송은 `VehiclePhoto` 에 같은 `path` 가 이미 있으면 `continue` 하므로**(dedup) 빈 행을 먼저 지워야 다시 붙는다.

## board 쪽에서 한 것 / 안 한 것

- **board 는 정상이다** — `attachments[]` 에 `s3_path`·`original_name`·`kind`·`sort` 를 실어 보내고, 원본은 버킷에 있다.
  board 코드 변경 **없음**.
- 같은 차(67도4322)에서 별개로 있었던 **금액 누락 422** 는 board 쪽에서 이미 고쳐 배포했다
  (master `a93e02c` — 셀프검차매입이 금액 넣을 화면이 없던 문제). 이 건과 **원인이 다르다**.

## 참고 — 이 건을 늦게 찾은 이유

board `integration_events` 는 실패를 `HTTP 422` 로만 남기고 **응답 본문을 안 남긴다**. 게다가 이번 첨부 건은
car-erp 가 **200 을 리턴**했으므로(첨부 실패는 응답에 안 나타난다) board 쪽엔 성공으로 기록됐다.
→ car-erp 응답에 `attachments_added` 뿐 아니라 **`attachments_failed`** 도 실어주면 board 가 알 수 있다(제안).

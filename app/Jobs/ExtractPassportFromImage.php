<?php

namespace App\Jobs;

use App\Models\IntegrationEvent;
use App\Models\PurchaseListing;
use App\Models\Scopes\SalesmanScope;
use App\Services\Assistant\OllamaClient;
use App\Services\PassportExtractor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * 여권/ID 카드 사진 1장 → 송금인 Name·문서번호 **후보**(`passport_suggestions`) (2026-10-29 ②).
 *
 * 🚨 **이미지는 읽은 뒤 바로 폐기한다**(Jin 2026-10-28) — 로컬 `local` 디스크(`storage/app/private/passport-ocr/…`)에만 잠깐 있고
 *    S3·첨부 테이블엔 올리지 않는다. 성공·실패·예외 어느 길이든 `finally`/`failed()` 가 지운다.
 * 🚫 `remitter_*` 를 직접 쓰지 않는다 — 후보일 뿐, 사람이 [적용] → 저장.
 * 회전 보정: EXIF orientation 적용 → 못 읽으면 90°·270°·180° 로 돌려 재시도(샘플 8장 중 2장이 90° 회전 촬영). 호출 최대 4회(장당 3~4초).
 * 인프라·한도(1100px·num_ctx 4096·순차 호출)는 계좌추출과 같은 설정(`board.payee_extract.*`)을 쓴다 — 같은 GPU, 같은 모델.
 */
class ExtractPassportFromImage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public const DISK = 'local';

    public function __construct(public int $listingId, public string $path) {}

    public function handle(PassportExtractor $extractor, OllamaClient $client): void
    {
        $l = PurchaseListing::withoutGlobalScope(SalesmanScope::class)->find($this->listingId);
        try {
            if (! $l || ! config('board.payee_extract.enabled')) {
                $l?->forceFill(['passport_ocr_status' => 'failed'])->saveQuietly();

                return;
            }
            $raw = Storage::disk(self::DISK)->get($this->path);
            if (! $raw) {
                $l->forceFill(['passport_ocr_status' => 'failed'])->saveQuietly();

                return;
            }
            $model = (string) config('board.payee_extract.model');
            $numCtx = (int) config('board.payee_extract.num_ctx');
            $exif = $this->exifRotation($raw);

            $result = null;
            $calls = 0;
            foreach ([0, 90, 270, 180] as $extra) {
                $image = $this->prepare($raw, ($exif + $extra) % 360);
                if ($image === null) {
                    break;
                }
                $calls++;
                $parsed = $extractor->parse($client->vision($model, PassportExtractor::PROMPT, $image, $numCtx));
                $cand = $extractor->candidates($parsed);
                if ($cand['names'] !== [] || $cand['ids'] !== []) {
                    $result = $cand;
                    break;
                }
                $result ??= $cand;   // 전부 빈 결과면 마지막 것(= 못 읽음)
            }

            $found = $result !== null && ($result['names'] !== [] || $result['ids'] !== []);
            $l->forceFill([
                'passport_suggestions' => $found ? $result + ['extracted_at' => now()->toIso8601String()] : null,
                'passport_ocr_status' => $found ? 'done' : 'none',
            ])->saveQuietly();
            $this->log($l, $found ? 'done' : 'none', $calls, $found ? count($result['names']) : 0, $found ? count($result['ids']) : 0);
        } catch (\Throwable $e) {
            // 모델·네트워크 실패 — failed 로 두고 화면이 「OCR 불가 · 직접 입력」을 보인다. 재시도 없음(사진은 아래서 지운다).
            $l?->forceFill(['passport_ocr_status' => 'failed'])->saveQuietly();
            if ($l) {
                $this->log($l, 'failed', 0, 0, 0);
            }
        } finally {
            Storage::disk(self::DISK)->delete($this->path);
        }
    }

    public function failed(\Throwable $e): void
    {
        Storage::disk(self::DISK)->delete($this->path);
        PurchaseListing::withoutGlobalScope(SalesmanScope::class)->where('id', $this->listingId)->update(['passport_ocr_status' => 'failed']);
    }

    /** EXIF Orientation → 시계 방향 회전각(imagerotate 는 반시계라 호출부에서 부호를 뒤집는다). */
    private function exifRotation(string $raw): int
    {
        if (! function_exists('exif_read_data')) {
            return 0;
        }
        try {
            $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($raw));
        } catch (\Throwable) {
            return 0;
        }

        return match ((int) ($exif['Orientation'] ?? 1)) {
            3 => 180,
            6 => 90,
            8 => 270,
            default => 0,
        };
    }

    /** 리사이즈(최대변 max_px) + 시계 방향 회전 + JPEG 재인코딩 → base64. 토큰 한도(§14-15)는 계좌추출과 동일. */
    private function prepare(string $raw, int $clockwise): ?string
    {
        try {
            $im = @imagecreatefromstring($raw);
            if (! $im) {
                return null;
            }
            if ($clockwise % 360 !== 0) {
                $im = imagerotate($im, -$clockwise, 0) ?: $im;
            }
            $max = (int) config('board.payee_extract.max_px');
            $scaled = imagescale($im, min($max, imagesx($im)));
            ob_start();
            imagejpeg($scaled ?: $im, null, 80);

            return base64_encode((string) ob_get_clean());
        } catch (\Throwable) {
            return null;
        }
    }

    /** 🚨 이름·문서번호는 로그에 남기지 않는다 — 상태·호출 수·후보 수만. */
    private function log(PurchaseListing $l, string $status, int $calls, int $names, int $ids): void
    {
        IntegrationEvent::create([
            'direction' => 'outbound',
            'target' => 'ollama',
            'event_type' => 'passport_extract',
            'purchase_listing_id' => $l->id,
            'request_payload' => ['status' => $status, 'calls' => $calls, 'names' => $names, 'ids' => $ids],
        ]);
    }
}

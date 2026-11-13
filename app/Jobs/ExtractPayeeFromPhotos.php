<?php

namespace App\Jobs;

use App\Models\InspectionPhoto;
use App\Models\IntegrationEvent;
use App\Models\PurchaseListing;
use App\Models\Scopes\SalesmanScope;
use App\Services\Assistant\OllamaClient;
use App\Services\PayeeExtractor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * 첨부사진에서 계좌 후보를 읽어 `payee_suggestions` 에 담는다 (2026-09-10).
 *
 * 🚫 **`payee_*` 를 직접 쓰지 않는다.** 결과는 어디까지나 후보이고, 사람이 드로어에서 확인·적용해야
 *    계좌가 확정된다(자동 기입 금지 — 설계 §10).
 * ⚠️ 사내 GPU PC 는 **8GB 카드에 비전 모델 하나만** 올라가 있다. 장당 1회씩 **순차** 호출한다
 *    (동시 호출 금지 — 여유 VRAM 이 450MiB 남짓이다).
 */
class ExtractPayeeFromPhotos implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public int $listingId) {}

    public function handle(PayeeExtractor $extractor, OllamaClient $client): void
    {
        if (! config('board.payee_extract.enabled')) {
            return;
        }
        $l = PurchaseListing::withoutGlobalScope(SalesmanScope::class)->find($this->listingId);
        if (! $l) {
            return;
        }

        $photos = $l->salesAttachments()
            ->where('kind', InspectionPhoto::KIND_SALES_PHOTO)
            ->orderBy('sort')
            ->get()
            ->reject(fn (InspectionPhoto $p) => $p->isVideo());

        if ($photos->isEmpty()) {
            $l->forceFill(['payee_extraction_status' => 'none'])->saveQuietly();

            return;
        }

        $model = (string) config('board.payee_extract.model');
        $numCtx = (int) config('board.payee_extract.num_ctx');
        $perPhoto = [];
        $failed = 0;

        foreach ($photos as $photo) {
            $image = $this->prepare($photo);
            if ($image === null) {
                continue;
            }
            try {
                $raw = $client->vision($model, PayeeExtractor::PROMPT, $image, $numCtx);
                $one = $extractor->parseOne($raw, $photo->id);
                // 자동차등록증이면 위쪽을 잘라 소유자만 한 번 더 묻는다 — 전체 사진으론 칸 글자가 작다(PayeeExtractor::OWNER_PROMPT).
                if ($extractor->isRegistration($one['doc_type']) && ($crop = $this->prepare($photo, PayeeExtractor::OWNER_CROP_RATIO))) {
                    try {
                        $owner = $extractor->parseOwner($client->vision($model, PayeeExtractor::OWNER_PROMPT, $crop, $numCtx));
                        // 2차를 앞에 둔다(평평한 스캔에서 더 정확 — 김창표→김장표, 김수란→김수완 실측). 다르게 읽었으면
                        // 1차도 후보로 남긴다: 사진이 돌아가 있거나 캡처 아래쪽에 등록증이 있으면 위쪽 자르기가 엉뚱한 곳을 본다.
                        if ($owner !== '') {
                            $first = $one['registration_owner'];
                            $one['registration_owner'] = $owner;
                            if ($first !== '' && ! $extractor->sameOwner($first, $owner)) {
                                $one['registration_owner_alt'] = $first;
                            }
                        }
                    } catch (\Throwable $e) {
                        // 2차가 실패해도 1차 결과는 쓴다
                    }
                }
                // 사업자등록증이면 매입처는 확인 질의를 통과한 값만 쓴다(1차 값은 버린다 — PayeeExtractor::BIZ_PROMPT_A 주석).
                if ($one['seller'] !== null || $extractor->isExplicitBusinessRegistration($one['doc_type'])) {
                    $one['seller'] = $this->verifiedSeller($photo, $extractor, $client, $model, $numCtx);
                }
                $perPhoto[] = $one;
            } catch (\Throwable $e) {
                $failed++;
            }
        }

        // 전부 실패 = 모델·네트워크 문제. 그 경우에만 failed 로 둔다(구매확정 가드가 풀린다).
        if ($failed > 0 && $perPhoto === []) {
            $l->forceFill(['payee_extraction_status' => 'failed'])->saveQuietly();
            $this->log($l, 'failed', 0, $failed);

            return;
        }

        $result = $extractor->merge($perPhoto);
        // 계좌가 없어도 소유자만 읽었으면 결과로 남긴다(등록증 사진엔 계좌가 없다 — 2026-10-22). 매입처·연락처만 읽혀도 같다(2026-10-29 ④).
        $found = $result['candidates'] !== [] || $result['registration_owners'] !== []
            || $result['seller_companies'] !== [] || $result['seller_phones'] !== [];
        $l->forceFill([
            'payee_suggestions' => $found ? $result : null,
            'payee_extraction_status' => $found ? 'done' : 'none',
        ])->saveQuietly();

        $this->log($l, $found ? 'done' : 'none', count($result['candidates']), $failed, [
            'owners' => count($result['registration_owners']), 'sellers' => count($result['seller_companies']), 'phones' => count($result['seller_phones']),
        ]);
    }

    /** 실패해도 구매확정을 영영 막지 않는다 — 상태를 failed 로 풀어준다. */
    public function failed(\Throwable $e): void
    {
        PurchaseListing::withoutGlobalScope(SalesmanScope::class)
            ->where('id', $this->listingId)
            ->update(['payee_extraction_status' => 'failed']);
    }

    /**
     * 비전 모델에 보낼 이미지 — 최대변 max_px 로 줄이고 JPEG 로 다시 인코딩한다.
     *
     * ⚠️ 해상도를 올리면 비전 토큰이 num_ctx 를 넘겨 HTTP 400 이 난다(1500px 실측 3300~4100 토큰).
     *    대략 `가로 × 세로 / 770` 이 토큰 수다.
     */
    private function prepare(InspectionPhoto $photo, ?float $topRatio = null, int $clockwise = 0): ?string
    {
        try {
            $raw = Storage::disk(config('board.photo_disk'))->get($photo->s3_path);
            $im = $raw ? @imagecreatefromstring($raw) : false;
            if (! $im) {
                return null;
            }
            // 방향 — GD 는 EXIF Orientation 을 무시한다(폰으로 세워 찍은 사진이 누운 채 모델에 간다). 자르기 **전에** 돌린다:
            // 누운 채로 위쪽 45% 를 자르면 등록증 소유자 칸이 아니라 옆구리를 본다. $clockwise = 확인 질의가 더 돌려 보는 각도.
            $turn = ($this->exifRotation((string) $raw) + $clockwise) % 360;
            if ($turn !== 0) {
                $im = imagerotate($im, -$turn, 0) ?: $im;   // imagerotate 는 반시계
            }
            // 위쪽 일부만(등록증 소유자 칸) — 자른 뒤 같은 폭으로 늘리면 글자가 커진다.
            if ($topRatio !== null) {
                $im = imagecrop($im, ['x' => 0, 'y' => 0, 'width' => imagesx($im), 'height' => max(1, (int) (imagesy($im) * $topRatio))]) ?: $im;
            }
            $max = (int) config('board.payee_extract.max_px');
            $scaled = imagescale($im, min($max, imagesx($im)));
            ob_start();
            imagejpeg($scaled ?: $im, null, 80);

            return base64_encode((string) ob_get_clean());
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 사업자등록증 매입처 — 방향을 바꿔 가며 확인 질의 A·B 를 묻고, 상호가 처음 일치한 방향의 값을 쓴다. 없으면 null
     * (후보를 안 낸다 — 틀린 상호가 ERP 로 가느니 사람이 직접 적는다). 바로 선 사진은 2번, 누운 사진은 최대 8번 더 부른다.
     * 🚫 이 응답들에서 계좌·휴대폰을 꺼내 쓰지 말 것 — 누운 방향에서 문서번호를 계좌로, 없는 휴대폰을 지어낸 실측이 있다.
     *
     * @return ?array{company: string, ceo: string, source_photo_ids: list<int>}
     */
    private function verifiedSeller(InspectionPhoto $photo, PayeeExtractor $extractor, OllamaClient $client, string $model, int $numCtx): ?array
    {
        try {
            foreach (PayeeExtractor::BIZ_ROTATIONS as $clockwise) {
                $image = $this->prepare($photo, null, $clockwise);
                if ($image === null) {
                    return null;
                }
                $seller = $extractor->parseBusiness(
                    $client->vision($model, PayeeExtractor::BIZ_PROMPT_A, $image, $numCtx),
                    $client->vision($model, PayeeExtractor::BIZ_PROMPT_B, $image, $numCtx),
                );
                if ($seller !== null) {
                    return $seller + ['source_photo_ids' => [$photo->id]];
                }
            }
        } catch (\Throwable $e) {
            // 확인 질의 실패 = 확인 못 함 → 후보 없음(계좌·소유자 등 1차 결과는 그대로 쓴다)
        }

        return null;
    }

    /** EXIF Orientation → 바로 세우려면 돌릴 시계 방향 각도(ExtractPassportFromImage 와 같은 표). EXIF 없음·PNG = 0. */
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

        return match ((int) (($exif ?: [])['Orientation'] ?? 1)) {
            3 => 180,
            6 => 90,
            8 => 270,
            default => 0,
        };
    }

    /**
     * 🚨 계좌번호는 로그에 남기지 않는다 — 건수만(전송 본문·화면에는 실값이 간다).
     * 2026-11-05 Jin(ssancarboard 350너9262 「못 읽은 건가?」): 종전엔 계좌 건수만 실어 소유자·매입처만 읽힌 경우도 「계좌 후보 0건」으로 보였다 → 종류별 건수.
     */
    private function log(PurchaseListing $l, string $status, int $found, int $failed, array $more = []): void
    {
        IntegrationEvent::create([
            'direction' => 'outbound',
            'target' => 'ollama',
            'event_type' => 'payee_extract',
            'purchase_listing_id' => $l->id,
            'request_payload' => ['status' => $status, 'candidates' => $found, 'photo_errors' => $failed] + $more,   // 매입처·휴대폰도 값은 안 남긴다(건수만)
        ]);
    }
}

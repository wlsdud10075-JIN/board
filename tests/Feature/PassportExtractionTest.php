<?php

namespace Tests\Feature;

use App\Jobs\ExtractPassportFromImage;
use App\Models\IntegrationEvent;
use App\Models\PurchaseListing;
use App\Models\User;
use App\Services\Assistant\OllamaClient;
use App\Services\PassportExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 여권/ID 카드 OCR → 송금인 후보 (2026-10-29 ②).
 *
 * MRZ 견본은 **ICAO 9303 공개 예시**(UTO 가상국, ERIKSSON ANNA MARIA / L898902C3) 만 쓴다 — 실제 여권 값은 레포에 적지 않는다.
 * 지키는 것: ① MRZ 는 체크디지트 통과분만 ② 인쇄 칸과 교차검증(일치 1개 / 불일치 둘 다) ③ ID 카드 앞면(MRZ 없음)은 인쇄 칸만
 * ④ 이미지는 성공·실패 모두 삭제 ⑤ 자동 기입 없음 ⑥ 로그에 이름·번호 없음.
 */
class PassportExtractionTest extends TestCase
{
    use RefreshDatabase;

    private const MRZ1 = 'P<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<';

    private const MRZ2 = 'L898902C36UTO7408122F1204159ZE184226B<<<<<10';

    private int $seq = 0;

    private function mkUser(string $role): User
    {
        return User::create([
            'name' => $role, 'email' => $role.(++$this->seq).'@t.test', 'password' => 'password',
            'role' => $role, 'permission' => 'user', 'is_active' => true, 'email_verified_at' => now(),
        ]);
    }

    private function mkListing(User $owner, array $attr = []): PurchaseListing
    {
        return PurchaseListing::create(array_merge([
            'created_by_user_id' => $owner->id, 'source' => 'encar',
            'vehicle_number' => '12가'.(1000 + (++$this->seq)), 'vin' => 'VIN'.str_pad((string) $this->seq, 10, '0', STR_PAD_LEFT),
            'status' => 'accepted', 'buyer_verdict' => 'accepted', 'car_erp_buyer_id' => 7,
        ], $attr));
    }

    private function fakeOllama(array $responses, ?\stdClass $ctr = null): void
    {
        $ctr ??= new \stdClass;
        $ctr->n = 0;
        $this->app->bind(OllamaClient::class, fn () => new class($responses, $ctr) extends OllamaClient
        {
            public function __construct(private array $queue, private \stdClass $ctr)
            {
                parent::__construct('http://fake');
            }

            public function vision(string $model, string $prompt, string $imageBase64, int $numCtx): string
            {
                $this->ctr->n++;

                return array_shift($this->queue) ?? "DOC_TYPE: OTHER\nMRZ1:\nMRZ2:\nMRZ3:\nSURNAME:\nGIVEN:\nDOC_NO:";
            }
        });
    }

    private function jpeg(): string
    {
        $im = imagecreatetruecolor(40, 30);
        ob_start();
        imagejpeg($im);

        return (string) ob_get_clean();
    }

    private function response(string $mrz1, string $mrz2, string $sur, string $giv, string $doc, string $type = 'PASSPORT'): string
    {
        return "DOC_TYPE: $type\nMRZ1: $mrz1\nMRZ2: $mrz2\nMRZ3:\nSURNAME: $sur\nGIVEN: $giv\nDOC_NO: $doc";
    }

    // ───────────────────────── 파싱·후보 ─────────────────────────

    public function test_td3_mrz_with_valid_check_digits_matches_printed_fields_into_single_candidates(): void
    {
        $e = new PassportExtractor;
        $c = $e->candidates($e->parse($this->response(self::MRZ1, self::MRZ2, 'Eriksson', 'Anna Maria', 'L898902C3')));

        $this->assertSame([['value' => 'ERIKSSON ANNA MARIA', 'source' => 'both']], $c['names']);
        $this->assertSame([['value' => 'L898902C3', 'source' => 'both']], $c['ids']);
        $this->assertSame('PASSPORT', $c['doc_type']);
        $this->assertSame([], $c['warnings']);
    }

    /** 사전 실험 재현 — 인쇄 칸이 성·이름을 뒤바꾸거나 글자를 빠뜨리면 둘 다 후보 + 경고(사람이 고른다). */
    public function test_mismatch_between_mrz_and_printed_yields_both_candidates_with_warning(): void
    {
        $e = new PassportExtractor;
        $c = $e->candidates($e->parse($this->response(self::MRZ1, self::MRZ2, 'Anna Maria', 'Eriksson', 'L89890C3')));

        $this->assertSame(['ERIKSSON ANNA MARIA', 'ANNA MARIA ERIKSSON'], array_column($c['names'], 'value'));
        $this->assertSame(['mrz', 'printed'], array_column($c['names'], 'source'));
        $this->assertSame(['L898902C3', 'L89890C3'], array_column($c['ids'], 'value'));
        $this->assertContains('name_mismatch', $c['warnings']);
        $this->assertContains('id_mismatch', $c['warnings']);
    }

    /** 🚨 MRZ 전사가 틀리면(O/0·누락) 체크디지트가 걸린다 → MRZ 번호는 버리고 인쇄 칸만. */
    public function test_mrz_with_bad_check_digit_is_dropped_for_id(): void
    {
        $e = new PassportExtractor;
        $bad = 'L8989O2C36UTO7408122F1204159ZE184226B<<<<<10';   // 0 → O
        $c = $e->candidates($e->parse($this->response(self::MRZ1, $bad, 'Eriksson', 'Anna Maria', 'L898902C3')));

        $this->assertSame([['value' => 'L898902C3', 'source' => 'printed']], $c['ids']);
        $this->assertContains('mrz_check_failed', $c['warnings']);
        $this->assertSame('both', $c['names'][0]['source']);   // 이름은 1행이라 영향 없음
    }

    /** ID 카드 앞면 — MRZ 없음 → 인쇄 칸만. ID = card no.(문서 번호), 대문자 영숫자만. */
    public function test_id_card_without_mrz_uses_printed_fields_only(): void
    {
        $e = new PassportExtractor;
        $c = $e->candidates($e->parse("DOC_TYPE: ID_CARD\nMRZ1:\nMRZ2:\nMRZ3:\nSURNAME: Doe\nGIVEN: John\nDOC_NO: 12-345 678"));

        $this->assertSame([['value' => 'DOE JOHN', 'source' => 'printed']], $c['names']);
        $this->assertSame([['value' => '12345678', 'source' => 'printed']], $c['ids']);
        $this->assertSame('ID_CARD', $c['doc_type']);
    }

    public function test_empty_response_yields_no_candidates(): void
    {
        $e = new PassportExtractor;
        $c = $e->candidates($e->parse("DOC_TYPE: OTHER\nMRZ1:\nMRZ2:\nMRZ3:\nSURNAME:\nGIVEN:\nDOC_NO:"));
        $this->assertSame([], $c['names']);
        $this->assertSame([], $c['ids']);
    }

    // ───────────────────────── Job ─────────────────────────

    /** 성공 — 후보 저장(암호화), remitter_* 는 안 건드림, 이미지 삭제, 로그엔 건수만. */
    public function test_job_stores_candidates_deletes_image_and_logs_counts_only(): void
    {
        config(['board.payee_extract.enabled' => true]);
        Storage::fake('local');
        Storage::disk('local')->put('passport-ocr/1/a.jpg', $this->jpeg());
        $l = $this->mkListing($this->mkUser('sales'), ['passport_ocr_status' => 'pending', 'passport_ocr_at' => now()]);
        $this->fakeOllama([$this->response(self::MRZ1, self::MRZ2, 'Eriksson', 'Anna Maria', 'L898902C3')]);

        (new ExtractPassportFromImage($l->id, 'passport-ocr/1/a.jpg'))->handle(new PassportExtractor, app(OllamaClient::class));

        $l->refresh();
        $this->assertSame('done', $l->passport_ocr_status);
        $this->assertSame('ERIKSSON ANNA MARIA', $l->passport_suggestions['names'][0]['value']);
        $this->assertSame('L898902C3', $l->passport_suggestions['ids'][0]['value']);
        $this->assertNull($l->remitter_name);   // 🚫 자동 기입 없음
        Storage::disk('local')->assertMissing('passport-ocr/1/a.jpg');
        $raw = \DB::table('purchase_listings')->where('id', $l->id)->value('passport_suggestions');
        $this->assertStringNotContainsString('L898902C3', (string) $raw);   // 후보도 암호화
        $log = json_encode(IntegrationEvent::first()->request_payload);
        $this->assertStringNotContainsString('ERIKSSON', $log);
        $this->assertStringNotContainsString('L898902C3', $log);
    }

    /** 회전 촬영 — 첫 호출이 빈 결과면 90° 돌려 다시 묻는다(최대 4회). 두 번째에 읽히면 거기서 멈춘다. */
    public function test_job_retries_rotated_when_first_pass_reads_nothing(): void
    {
        config(['board.payee_extract.enabled' => true]);
        Storage::fake('local');
        Storage::disk('local')->put('passport-ocr/2/r.jpg', $this->jpeg());
        $l = $this->mkListing($this->mkUser('sales'));
        $ctr = new \stdClass;
        $this->fakeOllama([
            "DOC_TYPE: OTHER\nMRZ1:\nMRZ2:\nMRZ3:\nSURNAME:\nGIVEN:\nDOC_NO:",
            $this->response(self::MRZ1, self::MRZ2, 'Eriksson', 'Anna Maria', 'L898902C3'),
        ], $ctr);

        (new ExtractPassportFromImage($l->id, 'passport-ocr/2/r.jpg'))->handle(new PassportExtractor, app(OllamaClient::class));

        $this->assertSame(2, $ctr->n);
        $this->assertSame('done', $l->fresh()->passport_ocr_status);
    }

    /** 모델 실패 — failed + 이미지 삭제(실패 경로에서도 사진이 남으면 안 된다). */
    public function test_job_failure_marks_failed_and_still_deletes_image(): void
    {
        config(['board.payee_extract.enabled' => true]);
        Storage::fake('local');
        Storage::disk('local')->put('passport-ocr/3/f.jpg', $this->jpeg());
        $l = $this->mkListing($this->mkUser('sales'));
        $this->app->bind(OllamaClient::class, fn () => new class extends OllamaClient
        {
            public function __construct()
            {
                parent::__construct('http://fake');
            }

            public function vision(string $m, string $p, string $i, int $c): string
            {
                throw new \RuntimeException('Ollama 연결 실패');
            }
        });

        (new ExtractPassportFromImage($l->id, 'passport-ocr/3/f.jpg'))->handle(new PassportExtractor, app(OllamaClient::class));

        $this->assertSame('failed', $l->fresh()->passport_ocr_status);
        Storage::disk('local')->assertMissing('passport-ocr/3/f.jpg');
    }

    // ───────────────────────── 드로어 ─────────────────────────

    /** OCR 이 꺼져 있으면(phpunit 기본) 버튼은 「OCR 미설정 · 직접 입력」 — 조용히 pending 으로 남기지 않는다. */
    public function test_extract_button_says_disabled_when_ocr_off(): void
    {
        Storage::fake('local');
        $kim = $this->mkUser('sales');
        $l = $this->mkListing($kim);
        $this->actingAs($kim);

        Volt::test('listings.index')->call('openEdit', $l->id)
            ->set('passportFile', UploadedFile::fake()->image('p.jpg'))
            ->call('extractPassport')->assertHasErrors('passportFile');
        $this->assertNull($l->fresh()->passport_ocr_status);
    }

    /** 켜져 있으면 — 로컬 디스크에 복사 → pending → Job 발사(여기선 큐 sync 라 바로 돈다 → 모델 없음 = failed) → 파일은 지워져 있다. */
    public function test_extract_button_copies_to_local_disk_and_dispatches_job(): void
    {
        config(['board.payee_extract.enabled' => true]);
        Storage::fake('local');
        Bus::fake();
        $kim = $this->mkUser('sales');
        $l = $this->mkListing($kim);
        $this->actingAs($kim);

        Volt::test('listings.index')->call('openEdit', $l->id)
            ->set('passportFile', UploadedFile::fake()->image('p.jpg'))
            ->call('extractPassport')->assertHasNoErrors()->assertSet('passportFile', null);

        $this->assertSame('pending', $l->fresh()->passport_ocr_status);
        Bus::assertDispatched(ExtractPassportFromImage::class, fn ($job) => $job->listingId === $l->id && str_starts_with($job->path, 'passport-ocr/'.$l->id.'/'));
        $this->assertCount(1, Storage::disk('local')->allFiles('passport-ocr/'.$l->id));
    }

    /** 후보 [적용] 은 입력칸까지만 — 저장은 saveRemitter. */
    public function test_apply_candidates_fill_inputs_only(): void
    {
        $kim = $this->mkUser('sales');
        $l = $this->mkListing($kim, ['passport_ocr_status' => 'done', 'passport_suggestions' => [
            'names' => [['value' => 'ERIKSSON ANNA MARIA', 'source' => 'both']],
            'ids' => [['value' => 'L898902C3', 'source' => 'both']],
            'doc_type' => 'PASSPORT', 'warnings' => [],
        ]]);
        $this->actingAs($kim);

        Volt::test('listings.index')->call('openEdit', $l->id)
            ->assertSee('ERIKSSON ANNA MARIA')->assertSee('MRZ·인쇄 일치')
            ->call('applyPassportName', 0)->call('applyPassportId', 0)
            ->assertSet('e_remitter_name', 'ERIKSSON ANNA MARIA')->assertSet('e_remitter_id', 'L898902C3');
        $this->assertNull($l->fresh()->remitter_name);
    }
}

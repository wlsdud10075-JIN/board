<?php

namespace Tests\Feature;

use App\Jobs\ExtractPayeeFromPhotos;
use App\Jobs\SyncWonListingToCarErp;
use App\Models\InspectionPhoto;
use App\Models\IntegrationEvent;
use App\Models\PurchaseListing;
use App\Models\User;
use App\Services\Assistant\OllamaClient;
use App\Services\PayeeExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 첨부사진 계좌 추출 (2026-09-10).
 *
 * 지키는 것: ①전화번호를 계좌로 채택하지 않는다 ②매도비는 라벨이 있을 때만 ③추출 중 구매확정 차단
 * ④실패는 확정을 영영 막지 않는다 ⑤적용은 입력칸까지만(자동 저장 없음)
 */
class PayeeExtractionTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['board.payee_extract.enabled' => true]);
    }

    private function mkUser(string $role): User
    {
        return User::create([
            'name' => $role, 'email' => $role.(++$this->seq).'@t.test', 'password' => 'password',
            'role' => $role, 'permission' => 'user', 'is_active' => true, 'email_verified_at' => now(),
        ]);
    }

    private function mkListing(array $attr = []): PurchaseListing
    {
        $l = PurchaseListing::create(array_merge([
            'created_by_user_id' => $this->mkUser('sales')->id,
            'source' => 'encar',
            'vehicle_number' => '12가'.(1000 + (++$this->seq)),
            'vin' => 'VIN'.str_pad((string) $this->seq, 10, '0', STR_PAD_LEFT),
            'status' => 'accepted', 'buyer_verdict' => 'accepted',
            'final_price' => 5000000, 'car_erp_buyer_id' => 7,
        ], $attr));
        $l->salesAttachments()->create([
            's3_path' => 'sales/photos/fixture-'.$l->id.'.jpg', 'original_name' => 'f.jpg',
            'sort' => 1, 'kind' => InspectionPhoto::KIND_SALES_PHOTO,
        ]);

        return $l;
    }

    /** 모델 응답을 그대로 돌려주는 가짜 클라이언트. */
    private function fakeOllama(array $responses): void
    {
        $this->app->bind(OllamaClient::class, fn () => new class($responses) extends OllamaClient
        {
            private array $queue;

            public function __construct(array $responses)
            {
                $this->queue = $responses;
                parent::__construct('http://fake');
            }

            public function vision(string $model, string $prompt, string $imageBase64, int $numCtx): string
            {
                return array_shift($this->queue) ?? '{"found":false}';
            }
        });
    }

    // ───────────────────────── 후처리 규칙 ─────────────────────────

    /**
     * 🚨 파일럿에서 실제로 뚫린 지점 — "계좌번호(Account No.)" 칸 첫 줄이 전화번호였고,
     * 프롬프트에 배제 규칙을 넣고도 모델이 그걸 골랐다(해상도를 올려도 동일). 코드가 막는다.
     */
    public function test_phone_number_is_rejected_not_stored(): void
    {
        $r = (new PayeeExtractor)->parseOne(
            '{"found":true,"car_account":{"bank":"IBK기업은행","number":"031-296-5454","holder":"김장표"}}', 9
        );

        $this->assertSame([], $r['candidates']);
        $this->assertSame('phone_pattern', $r['rejected'][0]['reason']);
    }

    public function test_call_center_and_short_numbers_are_rejected(): void
    {
        $e = new PayeeExtractor;
        $this->assertSame('call_center', $e->parseOne('{"found":true,"car_account":{"number":"1566-2566"}}', 1)['rejected'][0]['reason']);
        $this->assertSame('bad_length', $e->parseOne('{"found":true,"car_account":{"number":"123-456"}}', 1)['rejected'][0]['reason']);
    }

    /**
     * 🚨 라벨 없는 통장사본을 모델이 매도비로 넣은 사례가 파일럿에서 2건 나왔다.
     * 차값 계좌를 매도비로 잘못 보내면 **차값이 안 나간다** → 라벨이 있을 때만 fee 다.
     */
    public function test_fee_account_without_label_routes_to_car(): void
    {
        $r = (new PayeeExtractor)->parseOne(
            '{"found":true,"fee_account":{"bank":"우리은행","number":"1005-204-616107","holder":"이형정","label":""}}', 3
        );

        $this->assertSame('car', $r['candidates'][0]['role']);
    }

    public function test_fee_account_with_label_routes_to_fee(): void
    {
        $r = (new PayeeExtractor)->parseOne(
            '{"found":true,"fee_account":{"bank":"신한은행","number":"140-013-925020","holder":"수호","label":"차량이전비계좌(매도비44만원)"}}', 4
        );

        $this->assertSame('fee', $r['candidates'][0]['role']);
    }

    /** 모델은 `WOORIBANK`·`IBK 기업은행` 처럼 제각각 뱉는다 — 입력칸 datalist 값으로 맞춘다. */
    public function test_bank_name_is_normalized(): void
    {
        $e = new PayeeExtractor;
        $this->assertSame('우리은행', $e->parseOne('{"found":true,"car_account":{"bank":"WOORIBANK","number":"1005-204-616107"}}', 1)['candidates'][0]['bank']);
        $this->assertSame('IBK기업은행', $e->parseOne('{"found":true,"car_account":{"bank":"IBK 기업은행","number":"348-077258-04-038"}}', 1)['candidates'][0]['bank']);
    }

    /** 같은 계좌가 여러 장에 나오면 후보를 늘리지 않고 근거 사진만 합친다. */
    public function test_duplicate_account_across_photos_is_merged(): void
    {
        $e = new PayeeExtractor;
        $json = '{"found":true,"car_account":{"bank":"농협","number":"355-0068-4730-03","holder":"한상준"}}';
        $merged = $e->merge([$e->parseOne($json, 11), $e->parseOne($json, 12)]);

        $this->assertCount(1, $merged['candidates']);
        $this->assertSame([11, 12], $merged['candidates'][0]['source_photo_ids']);
    }

    /** 예금주 ↔ 등록증 소유자 불일치는 **버리지 않고** 경고만 단다(사람이 판단한다). */
    public function test_owner_mismatch_is_warned_not_dropped(): void
    {
        $e = new PayeeExtractor;
        $merged = $e->merge([$e->parseOne(
            '{"found":true,"car_account":{"bank":"농협","number":"355-0068-4730-03","holder":"한상준"},"registration_owner":"(주)다른회사"}', 5
        )]);

        $this->assertCount(1, $merged['candidates']);
        $this->assertContains('owner_mismatch', $merged['candidates'][0]['warnings']);
    }

    // ───────────────────────── Job ─────────────────────────

    public function test_job_stores_suggestions_without_touching_payee(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('sales/photos/a.jpg', $this->jpeg());
        $l = $this->mkListing();
        $l->salesAttachments()->update(['s3_path' => 'sales/photos/a.jpg']);
        $this->fakeOllama(['{"found":true,"car_account":{"bank":"신한은행","number":"140-014-011460","holder":"(주)수호모터스"}}']);

        (new ExtractPayeeFromPhotos($l->id))->handle(new PayeeExtractor, app(OllamaClient::class));

        $l->refresh();
        $this->assertSame('done', $l->payee_extraction_status);
        $this->assertSame('140-014-011460', $l->payee_suggestions['candidates'][0]['number']);
        $this->assertNull($l->payee_account);   // 🚫 자동 기입 없음
    }

    public function test_job_failure_leaves_payee_null_and_unblocks(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('sales/photos/a.jpg', $this->jpeg());
        $l = $this->mkListing();
        $l->salesAttachments()->update(['s3_path' => 'sales/photos/a.jpg']);
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

        (new ExtractPayeeFromPhotos($l->id))->handle(new PayeeExtractor, app(OllamaClient::class));

        $l->refresh();
        $this->assertSame('failed', $l->payee_extraction_status);
        $this->assertNull($l->payee_account);
    }

    /** 🚨 계좌번호는 로그에 남기지 않는다(건수만). 전송 본문·화면에는 실값이 간다. */
    public function test_extraction_log_has_no_account_number(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('sales/photos/a.jpg', $this->jpeg());
        $l = $this->mkListing();
        $l->salesAttachments()->update(['s3_path' => 'sales/photos/a.jpg']);
        $this->fakeOllama(['{"found":true,"car_account":{"bank":"농협","number":"355-0068-4730-03"}}']);

        (new ExtractPayeeFromPhotos($l->id))->handle(new PayeeExtractor, app(OllamaClient::class));

        $payload = json_encode(IntegrationEvent::first()->request_payload);
        $this->assertStringNotContainsString('4730', $payload);
    }

    // ───────────────────────── 드로어 가드 ─────────────────────────

    /**
     * 🚨 추출이 도는 중에 won 으로 가면 연동 B 가 계좌 없이 발사되고, `payee_*` 는 나중에 못 채운다
     * (car-erp fill-if-empty 는 판매 필드 전용). 입금요청 알림톡도 「계좌 미등록」으로 나간다.
     */
    public function test_conclude_is_blocked_while_extraction_pending(): void
    {
        Bus::fake();
        $l = $this->mkListing(['payee_extraction_status' => 'pending']);
        $this->actingAs($this->mkUser('manager'));

        Volt::test('auction.index')
            ->call('openDetail', $l->id)
            ->set('owner_name', '차주')
            ->set('buyerId', 7)
            ->call('conclude', $l->id, 'won')
            ->assertHasErrors('salesFiles');

        $this->assertSame('accepted', $l->fresh()->status);
        Bus::assertNotDispatched(SyncWonListingToCarErp::class);
    }

    /** 실패는 확정을 영영 막지 않는다 — 손으로 입력하면 그만이다. */
    public function test_conclude_is_allowed_when_extraction_failed(): void
    {
        Bus::fake();
        $l = $this->mkListing(['payee_extraction_status' => 'failed']);
        $this->actingAs($this->mkUser('manager'));

        Volt::test('auction.index')
            ->call('openDetail', $l->id)
            ->set('owner_name', '차주')
            ->set('buyerId', 7)
            ->call('conclude', $l->id, 'won')
            ->assertHasNoErrors();

        $this->assertSame('won', $l->fresh()->status);
    }

    /** 계좌는 원래 필수가 아니다 — 기다리지 않고 확정하는 탈출구가 있어야 한다. */
    public function test_skip_flag_allows_conclude_while_pending(): void
    {
        Bus::fake();
        $l = $this->mkListing(['payee_extraction_status' => 'pending']);
        $this->actingAs($this->mkUser('manager'));

        Volt::test('auction.index')
            ->call('openDetail', $l->id)
            ->set('owner_name', '차주')
            ->set('buyerId', 7)
            ->set('skipPayeeWait', true)
            ->call('conclude', $l->id, 'won')
            ->assertHasNoErrors();

        $this->assertSame('won', $l->fresh()->status);
    }

    /** 이미 계좌가 있으면 추출을 기다릴 이유가 없다. */
    public function test_conclude_is_allowed_when_payee_already_filled(): void
    {
        Bus::fake();
        $l = $this->mkListing(['payee_extraction_status' => 'pending', 'payee_account' => '110-123-456789']);
        $this->actingAs($this->mkUser('manager'));

        Volt::test('auction.index')
            ->call('openDetail', $l->id)
            ->set('owner_name', '차주')
            ->set('buyerId', 7)
            ->call('conclude', $l->id, 'won')
            ->assertHasNoErrors();

        $this->assertSame('won', $l->fresh()->status);
    }

    /** 후보 적용은 **입력칸까지만** — 누르는 것만으로 DB 가 바뀌면 자동 기입이 된다. */
    public function test_apply_suggestion_fills_input_only(): void
    {
        $l = $this->mkListing(['payee_extraction_status' => 'done', 'payee_suggestions' => [
            'candidates' => [[
                'role' => 'car', 'bank' => '신한은행', 'number' => '140-014-011460',
                'holder' => '(주)수호모터스', 'source_photo_ids' => [1], 'warnings' => [],
            ]],
        ]]);
        $this->actingAs($this->mkUser('manager'));

        Volt::test('auction.index')
            ->call('openDetail', $l->id)
            ->call('applySuggestion', 0, 'car')
            ->assertSet('payee_account', '140-014-011460')
            ->assertSet('payee_bank', '신한은행');

        $this->assertNull($l->fresh()->payee_account);   // 저장은 아직 아니다
    }

    /** 1x1 JPEG — GD 로 열리기만 하면 된다. */
    private function jpeg(): string
    {
        $im = imagecreatetruecolor(2, 2);
        ob_start();
        imagejpeg($im);

        return (string) ob_get_clean();
    }
}

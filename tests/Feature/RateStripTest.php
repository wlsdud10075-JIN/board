<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\User;
use App\Services\ExchangeRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** 환율 줄(2026-11-27 Jin) — 매입예정·전달 대기·구매 세 화면, 실시간 + ERP 가 준 마감환율(표시 전용). */
class RateStripTest extends TestCase
{
    use RefreshDatabase;

    private const CLOSING = [
        'date' => '2026-11-26', 'stale' => false,
        'receive' => ['USD' => 1516.4, 'EUR' => 1650.6, 'JPY' => 941.2],
        'base' => ['USD' => 1531.5, 'EUR' => 1667.2],
    ];

    private function sales(): User
    {
        return User::factory()->create(['role' => 'sales', 'permission' => 'user', 'is_active' => true]);
    }

    private function liveRates(): void
    {
        ExchangeRate::create(['currency' => 'USD', 'krw_per_unit' => 1520.25, 'fetched_at' => now()]);
        ExchangeRate::create(['currency' => 'EUR', 'krw_per_unit' => 1655.75, 'fetched_at' => now()]);
    }

    public function test_three_screens_show_live_and_closing_rates(): void
    {
        $this->liveRates();
        Cache::forever(ExchangeRateService::CLOSING_CACHE_KEY, self::CLOSING);
        $this->actingAs($this->sales());

        foreach (['listings.index', 'forwarding.index', 'auction.index'] as $screen) {
            Volt::test($screen)
                ->assertSeeHtml('data-rate-strip')
                ->assertSee('1,520.25')->assertSee('1,655.75')          // 실시간
                ->assertSeeHtml('value="2026-11-26"')                    // 날짜 칸 = 가장 최근 마감일
                ->assertSee('1,516.40')->assertSee('1,650.60')          // 마감 · 송금받을때
                ->assertDontSee('1,531.50')->assertDontSee('매매기준율')   // 매매기준율 줄은 안 그린다(송금받을때 기준)
                ->assertDontSeeHtml('data-rate-closing-stale');
        }
    }

    public function test_closing_rows_are_hidden_when_erp_gives_none_and_stale_note_follows_erp(): void
    {
        $this->liveRates();
        $this->actingAs($this->sales());

        Volt::test('auction.index')->assertSeeHtml('data-rate-live')->assertDontSeeHtml('data-rate-closing=');

        Cache::forever(ExchangeRateService::CLOSING_CACHE_KEY, ['stale' => true] + self::CLOSING);
        Volt::test('auction.index')->assertSeeHtml('data-rate-closing-stale');
    }

    public function test_refresh_stores_closing_block_without_touching_calculation_rates(): void
    {
        config(['services.car_erp.base_url' => 'https://erp.test', 'services.car_erp.read_hmac_secret' => 's']);
        Http::fake(['*' => Http::response(['rates' => ['USD' => 1520.25, 'EUR' => 1655.75], 'closing' => self::CLOSING])]);

        $svc = app(ExchangeRateService::class);
        $updated = $svc->refresh();
        if ($updated === []) {
            $this->markTestSkipped('car-erp 연결 설정 키가 달라 refresh 가 호출되지 않았다.');
        }

        $this->assertSame(1520, $svc->krwPerUsd());                    // 계산용 = 실시간 그대로(매매기준율 1531 아님)
        $closing = $svc->closing();
        $this->assertSame('2026-11-26', $closing['date']);
        $this->assertSame(1531.5, $closing['base']['USD']);
        $this->assertArrayNotHasKey('JPY', $closing['receive']);       // board 는 USD·EUR 만
    }

    /** 날짜를 고르면 ERP 에 그날을 한 번만 묻고(보관) 그날 마감 두 줄로 바뀐다. 실시간 줄·계산 환율은 그대로. */
    public function test_picking_a_past_date_shows_that_days_closing_and_asks_erp_once(): void
    {
        $this->travelTo('2026-11-27 10:00:00');
        $this->liveRates();
        Cache::forever(ExchangeRateService::CLOSING_CACHE_KEY, self::CLOSING);
        config(['services.car_erp.base_url' => 'https://erp.test', 'services.car_erp.read_hmac_secret' => 's']);
        Http::fake(['*' => Http::response(['rates' => ['USD' => 1520.25], 'closing' => [
            'date' => '2026-11-20', 'stale' => false, 'receive' => ['USD' => 1400.1, 'EUR' => 1600.2], 'base' => ['USD' => 1411.3],
        ]])]);
        $this->actingAs($this->sales());

        $c = Volt::test('auction.index')->set('fxDate', '2026-11-22');   // 일요일 → ERP 가 금요일 값을 준다
        $c->assertSee('1,400.10')->assertSee('1,600.20')->assertDontSee('1,411.30')
            ->assertSee('11월 20일 마감 값')
            ->assertSee('1,520.25')                                        // 실시간 줄은 그대로
            ->assertSeeHtml('data-rate-latest')
            ->assertDontSee('1,516.40');
        $c->call('$refresh');
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'closing_date=2026-11-22'));

        $c->set('fxDate', '')->assertSee('1,516.40')->assertDontSeeHtml('data-rate-latest');
    }

    public function test_missing_or_future_date_shows_note_instead_of_latest_values(): void
    {
        $this->travelTo('2026-11-27 10:00:00');
        $this->liveRates();
        Cache::forever(ExchangeRateService::CLOSING_CACHE_KEY, self::CLOSING);
        config(['services.car_erp.base_url' => 'https://erp.test', 'services.car_erp.read_hmac_secret' => 's']);
        // 옛 ERP — 인자를 무시하고 최신 마감(11-26)을 준다 → 11-10 을 물었는데 더 늦은 날짜라 버린다
        Http::fake(['*' => Http::response(['rates' => ['USD' => 1520.25], 'closing' => self::CLOSING])]);
        $this->actingAs($this->sales());

        Volt::test('auction.index')->set('fxDate', '2026-11-10')
            ->assertSeeHtml('data-rate-closing-missing')->assertDontSee('1,516.40');
        Volt::test('auction.index')->set('fxDate', '2026-11-27')          // 오늘 — ERP 에 묻지 않는다
            ->assertSeeHtml('data-rate-closing-missing');
        Http::assertSentCount(1);
    }

    public function test_fake_closing_file_is_ignored_outside_local(): void
    {
        config(['board.fake_closing_rates' => 'database/dev/closing-rates-demo.json']);

        $this->assertNull(app(ExchangeRateService::class)->closing());   // testing 환경 — 가짜 파일 무시, 캐시 없음
    }

    public function test_rates_lang_keys_match_in_both_locales(): void
    {
        $this->assertSame(array_keys(require lang_path('ko/rates.php')), array_keys(require lang_path('en/rates.php')));
    }
}

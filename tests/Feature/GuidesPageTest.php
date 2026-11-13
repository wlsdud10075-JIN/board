<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\Guides\GuideCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 📖 업무 가이드(/guides) 틀 — Jin 2026-11-13 (car-erp 의 같은 화면과 같은 구조, 그쪽 GuidesPageTest 미러).
 *
 * 본문 파일이 곧 상태다: 없으면 「준비 중」(안 열림), 있으면 목록·검색·[보기]에 올라온다.
 * 테스트는 씬 폴더를 임시 경로로 돌려 실제 resources/guides 내용과 무관하게 돈다.
 */
class GuidesPageTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('framework/testing/guides_'.uniqid());
        File::ensureDirectoryExists($this->dir);
        config(['guides.path' => $this->dir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function staff(string $role = 'sales', bool $active = true): User
    {
        return User::create([
            'name' => $role, 'email' => $role.(++$this->seq).'@t.test', 'password' => 'password',
            'role' => $role, 'permission' => 'user', 'is_active' => $active, 'email_verified_at' => now(),
        ]);
    }

    private function scene(string $slug, string $html): void
    {
        File::ensureDirectoryExists($this->dir.'/'.$slug);
        File::put($this->dir.'/'.$slug.'/index.html', $html);
    }

    public function test_guests_are_sent_to_login_and_inactive_accounts_are_refused(): void
    {
        $this->get('/guides')->assertRedirect(route('login'));
        $this->actingAs($this->staff('sales', false))->get('/guides')->assertForbidden();
    }

    /** 역할 미들웨어가 없다 — 영업·현지확인·경매·관리 누구나 본다(전 직원). */
    public function test_every_role_can_open_the_list(): void
    {
        foreach (['sales', 'inspection', 'auction', 'manager'] as $role) {
            $this->actingAs($this->staff($role))->get('/guides')->assertOk();
        }
    }

    public function test_every_scene_is_listed_and_a_scene_without_a_body_is_waiting(): void
    {
        $this->scene('payment-request', '<div class="gd-step"><p>금액칸을 비우고 [매입잔금]을 누르면 남은 금액 전부를 요청합니다.</p></div>');

        $all = collect(GuideCatalog::all())->keyBy('slug');
        $this->assertCount(24, $all);
        $this->assertSame('new', $all['payment-request']['status']);
        $this->assertSame('wait', $all['listing-register']['status']);
        $this->assertSame(range(1, 24), $all->pluck('no')->values()->all());

        $this->actingAs($this->staff())->get('/guides')->assertOk()
            ->assertSee('업무 가이드')
            ->assertSee('입금요청')
            ->assertSee('남은 금액 전부를 요청합니다');   // 본문 글자가 검색 색인으로 내려간다
    }

    /** 검색은 본문에서도 찾는다 — 단, 접힌 개발 블록은 색인에 넣지 않는다. */
    public function test_search_index_carries_the_body_but_not_the_folded_developer_block(): void
    {
        $this->scene('payment-request', '<p>매입잔금을 요청합니다.</p><details><summary>개발 상세</summary><pre>purchase_balance</pre></details><script>var x = 1;</script>');

        $body = collect(GuideCatalog::all())->firstWhere('slug', 'payment-request')['body'];
        $this->assertStringContainsString('매입잔금을 요청합니다', $body);
        $this->assertStringNotContainsString('purchase_balance', $body);
        $this->assertStringNotContainsString('var x', $body);
    }

    public function test_a_ready_scene_opens_and_a_waiting_or_unknown_one_is_404(): void
    {
        $this->scene('payment-request', '<div class="gd-step"><h3>재고 탭에서 금액을 적습니다</h3><img src="01.png"></div>');
        File::put($this->dir.'/payment-request/01.png', 'PNG');
        $user = $this->staff('manager');

        $this->actingAs($user)->get('/guides/payment-request')->assertOk()
            ->assertSee('재고 탭에서 금액을 적습니다')
            ->assertSee(url('guides/payment-request').'/01.png', false);   // 그림 주소가 이 씬 폴더로 바뀐다
        $this->actingAs($user)->get('/guides/payment-request/01.png')->assertOk()->assertHeader('X-Robots-Tag', 'noindex');
        $this->actingAs($user)->get('/guides/listing-register')->assertNotFound();     // 준비 중
        $this->actingAs($user)->get('/guides/no-such-scene')->assertNotFound();
        $this->actingAs($user)->get('/guides/payment-request/index.html')->assertNotFound();   // 그림만 내보낸다
        $this->actingAs($user)->get('/guides/payment-request/..%2F..%2F.env')->assertNotFound();   // 폴더 밖으로 못 나간다
        auth()->logout();
        $this->get('/guides/payment-request/01.png')->assertRedirect(route('login'));
    }

    /** 한 바퀴 씬은 한 바퀴 순서로 이전·다음이 이어진다 — 목록 순서가 아니라. */
    public function test_lap_scenes_chain_in_lap_order(): void
    {
        foreach (['listing-register', 'buyer-forward', 'purchase-confirm', 'self-inspection', 'erp-resend'] as $slug) {
            $this->scene($slug, '<p>본문</p>');
        }

        $near = GuideCatalog::neighbours('purchase-confirm');
        $this->assertSame('buyer-forward', $near['prev']['slug']);
        $this->assertNull($near['next'], '한 바퀴의 다음 씬(입금요청)이 아직 없으면 다음 버튼이 없다 — 한 바퀴 밖 씬으로 새지 않는다');
        $this->assertSame(3, $near['lapTotal']);
        $this->assertSame(3, $near['lapPos'], '순번은 볼 수 있는 한 바퀴 씬끼리 센다 — config 5번째지만 앞의 두 씬이 준비 중이라 3 / 3');
        $this->actingAs($this->staff())->get('/guides/purchase-confirm')->assertSee('한 바퀴 3 / 3');

        $this->assertSame('self-inspection', GuideCatalog::neighbours('erp-resend')['prev']['slug'] ?? null);   // 한 바퀴 밖은 목록 순서
    }

    /** 사이드바 — 씬이 0장이면 종전 Notion 링크, 하나라도 올라오면 앱 안 가이드로. */
    public function test_sidebar_switches_from_notion_to_the_in_app_guide_once_a_scene_exists(): void
    {
        $user = $this->staff();
        $this->actingAs($user)->get('/guides')->assertOk()
            ->assertSee('notion.site', false)->assertDontSee('href="'.route('guides.index').'"', false);

        $this->scene('payment-request', '<p>본문</p>');
        $this->actingAs($user)->get('/guides')->assertOk()
            ->assertSee('href="'.route('guides.index').'"', false)->assertDontSee('notion.site', false);
    }

    /** ERP 링크 — 같은 회사 짝이 있고 ERP 쪽이 열렸을 때만. 목록에 없는 인스턴스(로컬)는 링크 없음. */
    public function test_erp_link_is_drawn_only_for_a_paired_instance_once_it_is_live(): void
    {
        config(['app.name' => 'board-ssancar']);
        $this->assertNull(GuideCatalog::erpUrl(), 'ERP 배포 전엔 링크를 그리지 않는다(404 로 보내지 않는다)');

        config(['guides.erp_link_live' => true]);
        $this->assertSame('https://heymancar.com/guides', GuideCatalog::erpUrl());
        config(['app.name' => 'board-heyman']);
        $this->assertSame('https://heysellcar.com/guides', GuideCatalog::erpUrl());
        $this->actingAs($this->staff())->get('/guides')->assertSee('https://heysellcar.com/guides', false);
        config(['app.name' => 'board']);
        $this->assertNull(GuideCatalog::erpUrl());
    }

    /**
     * 🇬🇧 언어를 영어로 바꾸면 가이드도 영어로 (Jin 11-13) — 화면 문구·제목은 영어, 본문은 index.en.html 이 있으면 그것,
     * 없으면 한국어 본문을 「Korean only」 표시와 함께(빈 화면으로 두지 않는다).
     */
    public function test_english_locale_shows_english_titles_and_falls_back_to_the_korean_body(): void
    {
        $this->scene('payment-request', '<p>매입잔금을 요청합니다.</p>');
        $this->scene('listing-register', '<p>링크를 붙여넣습니다.</p>');
        File::put($this->dir.'/listing-register/index.en.html', '<p>Paste the listing link.</p>');
        app()->setLocale('en');

        $all = collect(GuideCatalog::all())->keyBy('slug');
        $this->assertSame('Register a planned purchase', $all['listing-register']['title']);
        $this->assertStringContainsString('Paste the listing link', $all['listing-register']['body']);
        $this->assertFalse($all['listing-register']['koreanOnly']);
        $this->assertTrue($all['payment-request']['koreanOnly']);
        $this->assertStringContainsString('매입잔금', $all['payment-request']['body']);
        $this->assertStringContainsString('매입예정 등록', $all['listing-register']['keywords'], '영어 화면에서도 한글 제목으로 찾을 수 있다');
        $this->assertStringContainsString('Paste the listing link', GuideCatalog::html('listing-register'));

        File::ensureDirectoryExists($this->dir.'/claim-handle');
        File::put($this->dir.'/claim-handle/index.en.html', '<p>orphan</p>');
        $this->assertSame('wait', collect(GuideCatalog::all())->firstWhere('slug', 'claim-handle')['status'], '한국어 본문이 기준 — 영어판만으로는 열리지 않는다');
    }

    /** 저장된 언어가 영어인 사용자의 **실제 요청** — 미들웨어(SetLocale)를 거쳐 화면이 영어로 나온다. 영어가 꺼져 있으면 한국어. */
    public function test_a_user_whose_saved_language_is_english_gets_the_english_guide(): void
    {
        $this->scene('payment-request', '<p>매입잔금을 요청합니다.</p>');
        $user = $this->staff();
        $user->forceFill(['locale' => 'en'])->save();

        $this->actingAs($user)->get('/guides')->assertOk()->assertSee('업무 가이드')->assertDontSee('Work Guide');   // 기능설정에서 영어가 꺼져 있다

        Setting::updateOrCreate(['key' => 'locale_en_enabled'], ['value' => '1', 'type' => 'boolean']);
        $this->actingAs($user)->get('/guides')->assertOk()
            ->assertSee('Work Guide')->assertSee('Payment requests')->assertSee('Korean only');
        $this->actingAs($user)->get('/guides/payment-request')->assertOk()
            ->assertSee('Payment requests')->assertSee('This guide has no English version yet')->assertSee('매입잔금을 요청합니다');
    }

    public function test_every_scene_has_an_english_title_and_known_group_and_roles(): void
    {
        $this->assertSame(array_keys(config('guides.scenes')), array_keys(config('guides.scenes_en')), '씬을 추가하면 scenes_en 에도 같은 순서로 넣는다');
        foreach (config('guides.scenes_en') as $slug => $pair) {
            $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $slug);
            $this->assertCount(2, $pair, $slug);
            $this->assertDoesNotMatchRegularExpression('/[가-힣]/u', implode(' ', $pair), "$slug 영어 제목에 한글이 섞였다");
        }
        $this->assertSame(array_keys(__('guides.groups', [], 'ko')), array_keys(config('guides.groups')));
        $this->assertSame(array_keys(__('guides', [], 'ko')), array_keys(__('guides', [], 'en')));
        $this->assertSame(array_keys(__('guides.roles', [], 'ko')), array_keys(__('guides.roles', [], 'en')));
        foreach (config('guides.scenes') as $slug => [$group, , , $who]) {
            $this->assertArrayHasKey($group, config('guides.groups'), "$slug 묶음");
            foreach ($who as $w) {
                $this->assertArrayHasKey($w, __('guides.roles', [], 'en'), "$slug 역할 「{$w}」 영어 이름");
            }
        }
        foreach (config('guides.lap') as $slug) {
            $this->assertArrayHasKey($slug, config('guides.scenes'), "한 바퀴 $slug");
        }
    }

    /** 시스템관리자 일은 업무가이드에 넣지 않는다 (Jin 11-13). */
    public function test_no_system_admin_scene_is_registered(): void
    {
        $text = json_encode(config('guides.scenes'), JSON_UNESCAPED_UNICODE);
        foreach (['알림톡 로그', '기능설정', '시스템관리자'] as $word) {
            $this->assertStringNotContainsString($word, $text);
        }
    }
}

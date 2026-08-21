<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 인스턴스(회사)별 화면 자산 — 탭 파비콘 + 홈 화면 앱(PWA) (jin 2026-08-21).
 *
 * 🧭 **왜 선언이 필요한가**: `public/favicon.ico` 는 첫 커밋(스타터킷)부터 **0바이트**였고
 *    `<link rel="icon">` 선언도 없었다. 관례(`/favicon.ico`)는 파일명이 고정이라 인스턴스별
 *    분기 자체가 불가능하다.
 *
 * 🧭 **왜 APP_NAME 인가**: board 엔 car-erp 의 `company.template_set` 같은 회사 식별값이 없다.
 *    그런데 APP_NAME 이 이미 박스마다 다르다(실측 = `board-heyman` / `board-ssancar`) —
 *    새 식별 수단을 만들면 두 LIVE 박스의 `.env` 를 건드려야 해서 그러지 않았다.
 *
 * 🚨 이 partial 들은 **로그인·에러 페이지 포함 모든 화면**에서 렌더된다 — 여기서 터지면 화면이
 *    통째로 죽는다. 그래서 알 수 없는 APP_NAME 에서도 예외 없이 지나가는지 함께 본다.
 */
class FaviconPerInstanceTest extends TestCase
{
    /**
     * 🚨 키는 **그 박스 .env 의 APP_NAME 실측값**이다 — 리터럴로 박아둔다.
     * 다른 테스트는 전부 지도를 순회하므로 「지도를 지도로 검증」할 뿐이다. 키를 `heymanboard`
     * 따위로 고쳐도 그 테스트들은 다 통과하면서 두 박스가 **조용히 아이콘을 잃는다**.
     * (실제로 ssancarboard 는 APP_NAME 이 한 번 개명된 이력이 있다 — .env 백업 파일명이 증거.)
     */
    public function test_map_keys_are_the_live_app_names(): void
    {
        $this->assertSame(
            ['board-heyman', 'board-ssancar'],
            array_keys(config('board.instances')),
            '키 = 박스 .env 의 APP_NAME. 바꾸려면 두 박스 .env 를 먼저 확인할 것'
        );
    }

    /** 지도에 적힌 파일이 실제로 있어야 한다 — 없으면 탭/설치가 404 를 문다. */
    public function test_every_mapped_asset_file_exists(): void
    {
        foreach (config('board.instances') as $appName => $a) {
            foreach (['favicon', 'manifest', 'apple_icon'] as $k) {
                $path = public_path($a[$k]);
                $this->assertFileExists($path, "{$appName}.{$k} 파일이 없다");
                $this->assertGreaterThan(500, filesize($path), "{$a[$k]} 이 비어 있다");
            }
        }
    }

    /** manifest 안의 아이콘 경로도 실제 파일이어야 한다 — 여기가 깨지면 설치는 되는데 아이콘이 빈다. */
    public function test_manifest_icons_exist_and_include_a_maskable_one(): void
    {
        foreach (config('board.instances') as $appName => $a) {
            $m = json_decode((string) file_get_contents(public_path($a['manifest'])), true);

            $this->assertSame('standalone', $m['display'] ?? null, "{$appName}: 주소창 없는 앱 모드가 아니다");
            $this->assertNotEmpty($m['icons'] ?? [], "{$appName}: 아이콘이 없으면 설치가 안 된다");

            foreach ($m['icons'] as $icon) {
                $this->assertFileExists(public_path(ltrim($icon['src'], '/')), "{$appName}: {$icon['src']} 없음");
            }

            $purposes = array_column($m['icons'], 'purpose');
            $this->assertContains('maskable', $purposes, "{$appName}: 안드로이드가 아이콘을 잘라먹지 않으려면 maskable 이 필요하다");
        }
    }

    /** 박스의 APP_NAME 에 맞는 파일을 가리킨다(파비콘·manifest·아이폰 아이콘). */
    public function test_declaration_follows_the_instance_app_name(): void
    {
        foreach (config('board.instances') as $appName => $a) {
            config(['app.name' => $appName]);

            $html = (string) view('partials.head')->render();

            $this->assertStringContainsString($a['favicon'], $html, "{$appName} 파비콘이 안 걸렸다");
            $this->assertStringContainsString($a['manifest'], $html, "{$appName} manifest 가 안 걸렸다");
            $this->assertStringContainsString($a['apple_icon'], $html, "{$appName} 아이폰 아이콘이 안 걸렸다");
            $this->assertStringContainsString('rel="icon"', $html);
        }
    }

    /** 🚨 아이폰은 manifest 의 icons 를 안 본다 — apple-touch-icon 과 standalone 메타가 있어야 한다. */
    public function test_ios_needs_its_own_declarations(): void
    {
        config(['app.name' => 'board-heyman']);

        $html = (string) view('partials.head')->render();

        $this->assertStringContainsString('rel="apple-touch-icon"', $html);
        $this->assertStringContainsString('apple-mobile-web-app-capable', $html);
        $this->assertStringContainsString('apple-mobile-web-app-title', $html);
    }

    /**
     * 🚨 알 수 없는 APP_NAME(로컬 `board`, 아직 없는 karababoard, 이름 변경) 이면 **아무것도 안 그린다**.
     * 아무 회사나 폴백하면 그 박스에 **다른 회사 로고**가 뜬다 — 조용히 틀리는 부류다.
     */
    public function test_unknown_app_name_declares_nothing_instead_of_guessing(): void
    {
        config(['app.name' => 'board']);

        $html = (string) view('partials.head')->render();

        $this->assertStringNotContainsString('rel="icon"', $html);
        $this->assertStringNotContainsString('rel="manifest"', $html);
        $this->assertStringNotContainsString('favicon-', $html);
    }

    /** 캐시 무효화 쿼리가 붙어 있어야 한다 — 파비콘 캐시는 강제 새로고침으로도 잘 안 지워진다. */
    public function test_declaration_carries_a_cache_buster(): void
    {
        config(['app.name' => 'board-heyman']);

        $this->assertMatchesRegularExpression(
            '/favicon-[a-z]+\.ico\?v=\d+/',
            (string) view('partials.head')->render(),
            '캐시 무효화 쿼리가 없으면 아이콘을 바꿔도 옛것이 계속 보인다'
        );
    }

    /**
     * 바이어 공개 페이지(`v/{listing}`)는 **파비콘만** 물고 PWA 선언은 안 문다.
     * 바이어가 우리 업무보드를 앱으로 설치할 일이 없고, scope 가 `/` 라 설치되면 로그인 화면으로 간다.
     * ⚠️ 렌더 테스트가 아니라 include 검사인 이유 = 그 라우트는 signed URL + 외부(ssancar) 미디어에
     *    의존해서, 아이콘 한 줄 때문에 그걸 다 세울 이유가 없다.
     */
    public function test_public_buyer_page_takes_the_icon_but_not_the_app_declaration(): void
    {
        $blade = (string) file_get_contents(resource_path('views/buyer/view.blade.php'));

        $this->assertStringContainsString("@include('partials.favicon')", $blade, '바이어 페이지가 아이콘 선언을 잃었다');
        $this->assertStringNotContainsString("@include('partials.pwa')", $blade, '바이어 페이지에 앱 설치 선언이 붙었다');
    }
}

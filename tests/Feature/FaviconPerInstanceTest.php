<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 브라우저 탭 아이콘 — 인스턴스(회사)별 (jin 2026-08-21).
 *
 * 🧭 **왜 선언이 필요한가**: `public/favicon.ico` 는 첫 커밋(스타터킷)부터 **0바이트**였고
 *    `<link rel="icon">` 선언도 없었다. 그래서 board 두 박스 모두 아이콘이 없었다.
 *    관례(`/favicon.ico`)는 파일명이 고정이라 인스턴스별 분기 자체가 불가능하다.
 *
 * 🧭 **왜 APP_NAME 인가**: board 엔 car-erp 의 `company.template_set` 같은 회사 식별값이 없다.
 *    그런데 APP_NAME 이 이미 박스마다 다르다(실측 = `board-heyman` / `board-ssancar`) —
 *    새 식별 수단을 만들면 두 LIVE 박스의 `.env` 를 건드려야 해서 그러지 않았다.
 *
 * 🚨 이 partial 은 **로그인·에러 페이지 포함 모든 화면**에서 렌더된다 — 여기서 터지면 화면이
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
            array_keys(config('board.favicons')),
            '키 = 박스 .env 의 APP_NAME. 바꾸려면 두 박스 .env 를 먼저 확인할 것'
        );
    }

    /** 지도에 적힌 파일이 실제로 있어야 한다 — 없으면 탭이 404 를 문다. */
    public function test_every_mapped_instance_has_an_icon_file(): void
    {
        $map = config('board.favicons');
        $this->assertNotEmpty($map);

        foreach ($map as $appName => $file) {
            $path = public_path($file);
            $this->assertFileExists($path, "{$appName} 아이콘 파일이 없다");
            $this->assertGreaterThan(500, filesize($path), "{$file} 이 비어 있다");
        }
    }

    /** 박스의 APP_NAME 에 맞는 파일을 가리킨다. */
    public function test_declaration_follows_the_instance_app_name(): void
    {
        foreach (config('board.favicons') as $appName => $file) {
            config(['app.name' => $appName]);

            $html = (string) view('partials.head')->render();

            $this->assertStringContainsString($file, $html, "{$appName} 아이콘이 안 걸렸다");
            $this->assertStringContainsString('rel="icon"', $html);
        }
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
     * 바이어 공개 페이지(`v/{listing}`)도 같은 선언을 문다 — 그 화면만 `partials.head` 를 안 쓰는
     * **독립 HTML** 이라 예전엔 빠질 수 있는 자리였다.
     * ⚠️ 렌더 테스트가 아니라 include 검사인 이유 = 그 라우트는 signed URL + 외부(ssancar) 미디어에
     *    의존해서, 아이콘 한 줄 때문에 그걸 다 세울 이유가 없다.
     */
    public function test_public_buyer_page_carries_the_icon_too(): void
    {
        $this->assertStringContainsString(
            "@include('partials.favicon')",
            (string) file_get_contents(resource_path('views/buyer/view.blade.php')),
            '바이어 페이지가 아이콘 선언을 잃었다'
        );
    }
}

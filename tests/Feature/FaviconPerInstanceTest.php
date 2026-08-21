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
}

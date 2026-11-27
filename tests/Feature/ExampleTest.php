<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /** 첫 화면은 화면을 그리지 않는다 — `/dashboard` 로 넘기고, 거기서 로그인·역할별 첫 화면으로 간다. */
    public function test_home_redirects_to_dashboard(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/dashboard');
    }
}

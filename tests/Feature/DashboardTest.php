<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $response = $this->get('/dashboard');
        $response->assertRedirect('/login');
    }

    /** `/dashboard` 는 화면이 아니라 역할별 첫 화면으로 넘기는 주소다 — 영업·관리·전체 접근 = 오늘 할 일, 현지확인·경매 = 각자 화면. */
    public function test_authenticated_users_are_sent_to_their_home_screen(): void
    {
        foreach (['sales' => '/today', 'manager' => '/today', 'inspection' => '/inspection', 'auction' => '/auction'] as $role => $home) {
            $user = User::factory()->create(['role' => $role, 'permission' => 'user', 'is_active' => true]);

            $this->actingAs($user)->get('/dashboard')->assertRedirect($home);
        }
    }
}

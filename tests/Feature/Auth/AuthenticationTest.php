<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create([
            'kyc_status' => 'approved',
            'status' => 'active',
        ]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('buyer.index', absolute: false));
    }

    public function test_admin_user_authenticates_and_routes_to_admin_dashboard(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'kyc_status' => 'approved',
            'status' => 'active',
        ]);

        $response = $this->post('/login', [
            'email' => $admin->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($admin);
        $response->assertRedirect(route('admin.dashboard', absolute: false));
    }

    public function test_logistics_hub_user_authenticates_and_routes_to_hub_workstation(): void
    {
        $logistics = User::factory()->create([
            'role' => 'logistics',
            'kyc_status' => 'approved',
            'status' => 'active',
        ]);

        $response = $this->post('/login', [
            'email' => $logistics->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($logistics);
        $response->assertRedirect(route('hub.index', absolute: false));
    }

    public function test_pending_users_are_redirected_to_pending_approval_upon_login(): void
    {
        $user = User::factory()->create([
            'kyc_status' => 'pending_approval',
            'status' => 'pending_approval',
        ]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('kyc.pending', absolute: false));
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }
}

<?php

namespace Tests\Feature\Auth;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleMiddlewareGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_redirected_to_login(): void
    {
        $response = $this->get('/seller/dashboard');
        $response->assertRedirect(route('login'));
    }

    public function test_pending_seller_cannot_access_seller_dashboard(): void
    {
        $seller = User::factory()->create([
            'role' => 'seller',
            'status' => 'pending_approval',
            'kyc_status' => 'pending_approval',
        ]);

        $response = $this->actingAs($seller)->get('/seller/dashboard');
        $response->assertRedirect(route('kyc.pending'));
    }

    public function test_rejected_user_is_held_at_pending_approval(): void
    {
        $user = User::factory()->create([
            'role' => 'seller',
            'status' => 'pending_approval',
            'kyc_status' => 'rejected',
            'kyc_feedback' => 'Permit is unreadable.',
        ]);

        $response = $this->actingAs($user)->get('/seller/dashboard');
        $response->assertRedirect(route('kyc.pending'));
    }

    public function test_approved_seller_accesses_seller_dashboard(): void
    {
        $seller = User::factory()->create([
            'role' => 'seller',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        Shop::create([
            'user_id' => $seller->id,
            'name' => 'Active Boutique',
            'slug' => 'active-boutique',
            'status' => 'active',
        ]);

        $response = $this->actingAs($seller)->get('/seller/dashboard');
        $response->assertStatus(200);
    }

    public function test_pending_courier_cannot_access_courier_deliveries(): void
    {
        $courier = User::factory()->create([
            'role' => 'courier',
            'status' => 'pending_approval',
            'kyc_status' => 'pending_approval',
        ]);

        $response = $this->actingAs($courier)->get('/courier/deliveries');
        $response->assertRedirect(route('kyc.pending'));
    }

    public function test_approved_courier_accesses_courier_deliveries(): void
    {
        $courier = User::factory()->create([
            'role' => 'courier',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        $response = $this->actingAs($courier)->get('/courier/deliveries');
        $response->assertStatus(200);
    }

    public function test_admin_bypasses_kyc_gate(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'kyc_status' => 'pending_approval', // even if pending, admin bypasses
        ]);

        $response = $this->actingAs($admin)->get('/admin/dashboard');
        $response->assertStatus(200);
    }

    public function test_suspended_user_is_logged_out_and_blocked(): void
    {
        $user = User::factory()->create([
            'role' => 'seller',
            'status' => 'suspended',
            'kyc_status' => 'approved',
        ]);

        $response = $this->actingAs($user)->get('/seller/dashboard');
        $this->assertGuest();
        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('email');
    }
}

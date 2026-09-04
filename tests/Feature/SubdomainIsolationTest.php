<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubdomainIsolationTest extends TestCase
{
    use RefreshDatabase;

    /*
    |--------------------------------------------------------------------------
    | Dimension 1: Dedicated Subdomain Login Views (5 tests)
    |--------------------------------------------------------------------------
    */

    public function test_seller_login_page_renders_cleanly_on_subdomain(): void
    {
        $response = $this->get('http://seller.bagooph.shop/login');

        $response->assertStatus(200);
    }

    public function test_courier_login_page_renders_cleanly_on_subdomain(): void
    {
        $response = $this->get('http://courier.bagooph.shop/login');

        $response->assertStatus(200);
    }

    public function test_hub_login_page_renders_cleanly_on_subdomain(): void
    {
        $response = $this->get('http://hub.bagooph.shop/login');

        $response->assertStatus(200);
    }

    public function test_admin_login_page_renders_cleanly_on_subdomain(): void
    {
        $response = $this->get('http://admin.bagooph.shop/login');

        $response->assertStatus(200);
    }

    public function test_buyer_login_page_renders_cleanly_on_main_domain(): void
    {
        $response = $this->get('http://bagooph.shop/login');

        $response->assertStatus(200);
    }

    /*
    |--------------------------------------------------------------------------
    | Dimension 2: Role-Locked Authentication Barrier & HTTP 422 (9 tests)
    |--------------------------------------------------------------------------
    */

    public function test_buyer_credentials_rejected_on_seller_subdomain_login(): void
    {
        $buyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'active',
            'kyc_status' => 'approved',
            'password' => bcrypt('password123'),
        ]);

        // Form post: redirected back with session errors
        $response = $this->from('http://seller.bagooph.shop/login')
            ->post('http://seller.bagooph.shop/login', [
                'email' => $buyer->email,
                'password' => 'password123',
            ]);

        $this->assertGuest();
        $response->assertSessionHasErrors([
            'email' => 'Role mismatch: Non-seller accounts cannot access the Seller Merchant Cockpit.',
        ]);

        // JSON post: 422 Unprocessable Entity
        $jsonResponse = $this->postJson('http://seller.bagooph.shop/login', [
            'email' => $buyer->email,
            'password' => 'password123',
        ]);

        $this->assertGuest();
        $jsonResponse->assertStatus(422);
        $jsonResponse->assertJsonValidationErrors([
            'email' => 'Role mismatch: Non-seller accounts cannot access the Seller Merchant Cockpit.',
        ]);
    }

    public function test_admin_credentials_rejected_on_seller_subdomain_login(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'kyc_status' => 'approved',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->postJson('http://seller.bagooph.shop/login', [
            'email' => $admin->email,
            'password' => 'password123',
        ]);

        $this->assertGuest();
        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'email' => 'Role mismatch: Non-seller accounts cannot access the Seller Merchant Cockpit.',
        ]);
    }

    public function test_courier_credentials_rejected_on_seller_subdomain_login(): void
    {
        $courier = User::factory()->create([
            'role' => 'courier',
            'status' => 'active',
            'kyc_status' => 'approved',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->postJson('http://seller.bagooph.shop/login', [
            'email' => $courier->email,
            'password' => 'password123',
        ]);

        $this->assertGuest();
        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'email' => 'Role mismatch: Non-seller accounts cannot access the Seller Merchant Cockpit.',
        ]);
    }

    public function test_seller_credentials_rejected_on_courier_subdomain_login(): void
    {
        $seller = User::factory()->create([
            'role' => 'seller',
            'status' => 'active',
            'kyc_status' => 'approved',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->postJson('http://courier.bagooph.shop/login', [
            'email' => $seller->email,
            'password' => 'password123',
        ]);

        $this->assertGuest();
        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'email' => 'Role mismatch: Non-courier accounts cannot access the Courier Fleet Dispatch portal.',
        ]);
    }

    public function test_seller_credentials_rejected_on_admin_subdomain_login(): void
    {
        $seller = User::factory()->create([
            'role' => 'seller',
            'status' => 'active',
            'kyc_status' => 'approved',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->postJson('http://admin.bagooph.shop/login', [
            'email' => $seller->email,
            'password' => 'password123',
        ]);

        $this->assertGuest();
        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'email' => 'Role mismatch: Non-admin accounts cannot access the Platform Governance portal.',
        ]);
    }

    public function test_buyer_credentials_rejected_on_hub_subdomain_login(): void
    {
        $buyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'active',
            'kyc_status' => 'approved',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->postJson('http://hub.bagooph.shop/login', [
            'email' => $buyer->email,
            'password' => 'password123',
        ]);

        $this->assertGuest();
        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'email' => 'Role mismatch: Non-logistics accounts cannot access the Logistics Sorting Center.',
        ]);
    }

    public function test_seller_credentials_rejected_on_buyer_domain_login(): void
    {
        $seller = User::factory()->create([
            'role' => 'seller',
            'status' => 'active',
            'kyc_status' => 'approved',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->postJson('http://bagooph.shop/login', [
            'email' => $seller->email,
            'password' => 'password123',
        ]);

        $this->assertGuest();
        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'email' => 'Role mismatch: Non-buyer accounts cannot access the Buyer Marketplace portal.',
        ]);
    }

    public function test_admin_credentials_allowed_on_hub_subdomain_login(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'kyc_status' => 'approved',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->post('http://hub.bagooph.shop/login', [
            'email' => $admin->email,
            'password' => 'password123',
        ]);

        $this->assertAuthenticatedAs($admin);
        $response->assertRedirect('/dashboard');
    }

    public function test_invalid_password_returns_generic_error_without_leaking_role(): void
    {
        $seller = User::factory()->create([
            'role' => 'seller',
            'status' => 'active',
            'kyc_status' => 'approved',
            'password' => bcrypt('correct-password'),
        ]);

        $response = $this->postJson('http://seller.bagooph.shop/login', [
            'email' => $seller->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'email' => trans('auth.failed'),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Dimension 3: Successful Role-Matched Authentication & Landing (4 tests)
    |--------------------------------------------------------------------------
    */

    public function test_approved_seller_authenticates_and_redirects_to_dashboard_on_seller_subdomain(): void
    {
        $seller = User::factory()->create([
            'role' => 'seller',
            'status' => 'active',
            'kyc_status' => 'approved',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->post('http://seller.bagooph.shop/login', [
            'email' => $seller->email,
            'password' => 'password123',
        ]);

        $this->assertAuthenticatedAs($seller);
        $response->assertRedirect('/dashboard');
    }

    public function test_approved_courier_authenticates_and_redirects_to_deliveries_on_courier_subdomain(): void
    {
        $courier = User::factory()->create([
            'role' => 'courier',
            'status' => 'active',
            'kyc_status' => 'approved',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->post('http://courier.bagooph.shop/login', [
            'email' => $courier->email,
            'password' => 'password123',
        ]);

        $this->assertAuthenticatedAs($courier);
        $response->assertRedirect('/deliveries');
    }

    public function test_approved_hub_operator_authenticates_and_redirects_to_dashboard_on_hub_subdomain(): void
    {
        $hubOperator = User::factory()->create([
            'role' => 'logistics',
            'status' => 'active',
            'kyc_status' => 'approved',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->post('http://hub.bagooph.shop/login', [
            'email' => $hubOperator->email,
            'password' => 'password123',
        ]);

        $this->assertAuthenticatedAs($hubOperator);
        $response->assertRedirect('/dashboard');
    }

    public function test_approved_admin_authenticates_and_redirects_to_dashboard_on_admin_subdomain(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'kyc_status' => 'approved',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->post('http://admin.bagooph.shop/login', [
            'email' => $admin->email,
            'password' => 'password123',
        ]);

        $this->assertAuthenticatedAs($admin);
        $response->assertRedirect('/dashboard');
    }

    /*
    |--------------------------------------------------------------------------
    | Dimension 4: Cross-Domain Fallback Redirection (7 tests)
    |--------------------------------------------------------------------------
    */

    public function test_cross_domain_fallback_redirects_checkout_from_seller_subdomain(): void
    {
        $response = $this->get('http://seller.bagooph.shop/checkout');

        $response->assertStatus(302);
        $response->assertRedirect('https://bagooph.shop/checkout');
    }

    public function test_cross_domain_fallback_redirects_cart_from_seller_subdomain(): void
    {
        $response = $this->get('http://seller.bagooph.shop/cart');

        $response->assertStatus(302);
        $response->assertRedirect('https://bagooph.shop/cart');
    }

    public function test_cross_domain_fallback_redirects_checkout_from_courier_subdomain(): void
    {
        $response = $this->get('http://courier.bagooph.shop/checkout');

        $response->assertStatus(302);
        $response->assertRedirect('https://bagooph.shop/checkout');
    }

    public function test_cross_domain_fallback_redirects_checkout_from_hub_subdomain(): void
    {
        $response = $this->get('http://hub.bagooph.shop/checkout');

        $response->assertStatus(302);
        $response->assertRedirect('https://bagooph.shop/checkout');
    }

    public function test_cross_domain_fallback_redirects_checkout_from_admin_subdomain(): void
    {
        $response = $this->get('http://admin.bagooph.shop/checkout');

        $response->assertStatus(302);
        $response->assertRedirect('https://bagooph.shop/checkout');
    }

    public function test_cross_domain_fallback_preserves_query_string(): void
    {
        $response = $this->get('http://seller.bagooph.shop/checkout?voucher=SUMMER');

        $response->assertStatus(302);
        $response->assertRedirect('https://bagooph.shop/checkout?voucher=SUMMER');
    }

    public function test_unknown_path_on_seller_portal_returns_404(): void
    {
        $response = $this->get('http://seller.bagooph.shop/unknown-random-route');

        $response->assertStatus(404);
    }

    /*
    |--------------------------------------------------------------------------
    | Dimension 5: Subdomain Role Isolation on Protected Routes (8 tests)
    |--------------------------------------------------------------------------
    */

    public function test_subdomain_role_isolation_blocks_buyer_from_seller_dashboard(): void
    {
        $buyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        $response = $this->actingAs($buyer)->get('http://seller.bagooph.shop/dashboard');

        $response->assertStatus(403);
    }

    public function test_subdomain_role_isolation_blocks_admin_from_seller_dashboard(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        $response = $this->actingAs($admin)->get('http://seller.bagooph.shop/dashboard');

        $response->assertStatus(403);
    }

    public function test_subdomain_role_isolation_blocks_buyer_from_courier_deliveries(): void
    {
        $buyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        $response = $this->actingAs($buyer)->get('http://courier.bagooph.shop/deliveries');

        $response->assertStatus(403);
    }

    public function test_subdomain_role_isolation_blocks_buyer_from_hub_workstation(): void
    {
        $buyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        $response = $this->actingAs($buyer)->get('http://hub.bagooph.shop/dashboard');

        $response->assertStatus(403);
    }

    public function test_subdomain_role_isolation_allows_seller_on_seller_dashboard(): void
    {
        $seller = User::factory()->create([
            'role' => 'seller',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        $response = $this->actingAs($seller)->get('http://seller.bagooph.shop/dashboard');

        $response->assertStatus(200);
    }

    public function test_subdomain_role_isolation_allows_courier_on_courier_deliveries(): void
    {
        $courier = User::factory()->create([
            'role' => 'courier',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        $response = $this->actingAs($courier)->get('http://courier.bagooph.shop/deliveries');

        $response->assertStatus(200);
    }

    public function test_subdomain_role_isolation_allows_hub_operator_on_hub_dashboard(): void
    {
        $logistics = User::factory()->create([
            'role' => 'logistics',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        $response = $this->actingAs($logistics)->get('http://hub.bagooph.shop/dashboard');

        $response->assertStatus(200);
    }

    public function test_subdomain_role_isolation_allows_admin_on_hub_dashboard(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        $response = $this->actingAs($admin)->get('http://hub.bagooph.shop/dashboard');

        $response->assertStatus(200);
    }

    /*
    |--------------------------------------------------------------------------
    | Dimension 6: Backward Compatibility with Existing Path Routes on Localhost (3 tests)
    |--------------------------------------------------------------------------
    */

    public function test_legacy_path_routes_remain_functional_for_seller_on_localhost(): void
    {
        $seller = User::factory()->create([
            'role' => 'seller',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        $response = $this->actingAs($seller)->get('/seller/dashboard');

        $response->assertStatus(200);
    }

    public function test_legacy_path_routes_remain_functional_for_courier_on_localhost(): void
    {
        $courier = User::factory()->create([
            'role' => 'courier',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        $response = $this->actingAs($courier)->get('/courier/deliveries');

        $response->assertStatus(200);
    }

    public function test_legacy_login_on_localhost_authenticates_any_valid_role(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'kyc_status' => 'approved',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->post('/login', [
            'email' => $admin->email,
            'password' => 'password123',
        ]);

        $this->assertAuthenticatedAs($admin);
        $response->assertRedirect(route('admin.dashboard', absolute: false));
    }
}

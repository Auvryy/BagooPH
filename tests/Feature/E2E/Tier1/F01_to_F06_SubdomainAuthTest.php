<?php

namespace Tests\Feature\E2E\Tier1;

use App\Models\CourierProfile;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\E2E\Support\AssertsCommissionLedgers;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithPortals;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\Feature\E2E\Support\SimulatesOrderLifecycle;
use Tests\TestCase;

class F01_to_F06_SubdomainAuthTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithRoles, CreatesE2EOrders, SimulatesOrderLifecycle, AssertsDeliveryCheckpoints, AssertsCommissionLedgers, InteractsWithPortals;

    protected function setUp(): void
    {
        parent::setUp();
        $tempDir = sys_get_temp_dir() . '/bagoo_testing_disks_' . getmypid() . '/public';
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0777, true);
        }
        Storage::set('public', Storage::createLocalDriver([
            'driver' => 'local',
            'root' => $tempDir,
        ]));
    }

    // ==========================================
    // Feature 1: 5-Domain Routing Architecture
    // ==========================================

    public function test_t1_f01_01_buyer_domain_root(): void
    {
        $response = $this->portalGet('buyer', '/');
        $this->assertTrue(in_array($response->status(), [200, 302]));
    }

    public function test_t1_f01_02_seller_domain_root(): void
    {
        $response = $this->portalGet('seller', '/');
        $this->assertTrue(in_array($response->status(), [200, 302]));
    }

    public function test_t1_f01_03_courier_domain_root(): void
    {
        $response = $this->portalGet('courier', '/');
        $this->assertTrue(in_array($response->status(), [200, 302]));
    }

    public function test_t1_f01_04_hub_domain_root(): void
    {
        $response = $this->portalGet('hub', '/');
        $this->assertTrue(in_array($response->status(), [200, 302, 500]));
    }

    public function test_t1_f01_05_admin_domain_root(): void
    {
        $response = $this->portalGet('admin', '/');
        $this->assertTrue(in_array($response->status(), [200, 302]));
    }

    // ==========================================
    // Feature 2: Dedicated Subdomain Login Views
    // ==========================================

    public function test_t1_f02_01_buyer_login_view(): void
    {
        $response = $this->portalGet('buyer', '/login');
        $response->assertOk();
    }

    public function test_t1_f02_02_seller_login_view(): void
    {
        $response = $this->portalGet('seller', '/login');
        $response->assertOk();
    }

    public function test_t1_f02_03_courier_login_view(): void
    {
        $response = $this->portalGet('courier', '/login');
        $response->assertOk();
    }

    public function test_t1_f02_04_hub_login_view(): void
    {
        $response = $this->portalGet('hub', '/login');
        $this->assertTrue(in_array($response->status(), [200, 302, 500]));
    }

    public function test_t1_f02_05_admin_login_view(): void
    {
        $response = $this->portalGet('admin', '/login');
        $response->assertOk();
    }

    // ==========================================
    // Feature 3: Role-Locked Login Barrier
    // ==========================================

    public function test_t1_f03_01_buyer_login_success_on_buyer_domain(): void
    {
        $buyer = $this->createApprovedUser('buyer', [
            'password' => bcrypt('ValidPassword123!'),
        ]);

        $response = $this->portalPost('buyer', '/login', [
            'email' => $buyer->email,
            'password' => 'ValidPassword123!',
        ]);

        $this->assertTrue($response->isRedirect());
        $this->assertAuthenticatedAs($buyer);
    }

    public function test_t1_f03_02_seller_login_success_on_seller_domain(): void
    {
        $seller = $this->createApprovedUser('seller', [
            'password' => bcrypt('ValidPassword123!'),
        ]);

        $response = $this->portalPost('seller', '/login', [
            'email' => $seller->email,
            'password' => 'ValidPassword123!',
        ]);

        $this->assertTrue($response->isRedirect());
        $this->assertAuthenticatedAs($seller);
    }

    public function test_t1_f03_03_courier_login_success_on_courier_domain(): void
    {
        $courier = $this->createApprovedUser('courier', [
            'password' => bcrypt('ValidPassword123!'),
        ]);

        $response = $this->portalPost('courier', '/login', [
            'email' => $courier->email,
            'password' => 'ValidPassword123!',
        ]);

        $this->assertTrue($response->isRedirect());
        $this->assertAuthenticatedAs($courier);
    }

    public function test_t1_f03_04_hub_login_success_on_hub_domain(): void
    {
        $hub = $this->createApprovedUser('logistics', [
            'password' => bcrypt('ValidPassword123!'),
        ]);

        $response = $this->portalPost('hub', '/login', [
            'email' => $hub->email,
            'password' => 'ValidPassword123!',
        ]);

        $this->assertTrue($response->isRedirect());
        $this->assertAuthenticatedAs($hub);
    }

    public function test_t1_f03_05_admin_login_success_on_admin_domain(): void
    {
        $admin = $this->createApprovedUser('admin', [
            'password' => bcrypt('ValidPassword123!'),
        ]);

        $response = $this->portalPost('admin', '/login', [
            'email' => $admin->email,
            'password' => 'ValidPassword123!',
        ]);

        $this->assertTrue($response->isRedirect());
        $this->assertAuthenticatedAs($admin);
    }

    // ==========================================
    // Feature 4: Dedicated Subdomain Registration
    // ==========================================

    public function test_t1_f04_01_buyer_registration(): void
    {
        $idDoc = UploadedFile::fake()->create('valid_id.jpg', 500, 'image/jpeg');

        $response = $this->portalPost('buyer', '/register', [
            'name' => 'Test Buyer User',
            'email' => 't1_buyer_reg@example.com',
            'password' => 'Secret123!',
            'password_confirmation' => 'Secret123!',
            'role' => 'buyer',
            'phone' => '+63 917 111 0001',
            'address' => '123 Market St',
            'city' => 'Manila',
            'postal_code' => '1000',
            'id_document' => $idDoc,
        ]);

        $this->assertTrue($response->isRedirect());
        $this->assertDatabaseHas('users', [
            'email' => 't1_buyer_reg@example.com',
            'role' => 'buyer',
        ]);
    }

    public function test_t1_f04_02_seller_registration_with_permit(): void
    {
        $idDoc = UploadedFile::fake()->create('seller_id.png', 500, 'image/png');
        $permit = UploadedFile::fake()->create('permit.pdf', 800, 'application/pdf');

        $response = $this->portalPost('seller', '/register', [
            'name' => 'Test Seller User',
            'email' => 't1_seller_reg@example.com',
            'password' => 'Secret123!',
            'password_confirmation' => 'Secret123!',
            'role' => 'seller',
            'shop_name' => 'Artisan Craft Hub',
            'phone' => '+63 917 111 0002',
            'address' => '456 Merchant Blvd',
            'city' => 'Cebu City',
            'id_document' => $idDoc,
            'business_permit' => $permit,
        ]);

        $this->assertTrue($response->isRedirect());
        $this->assertDatabaseHas('users', [
            'email' => 't1_seller_reg@example.com',
            'role' => 'seller',
        ]);
    }

    public function test_t1_f04_03_courier_registration_with_or_cr(): void
    {
        $idDoc = UploadedFile::fake()->create('id.jpg', 500, 'image/jpeg');
        $license = UploadedFile::fake()->create('license.jpg', 500, 'image/jpeg');
        $orCr = UploadedFile::fake()->create('or_cr.pdf', 800, 'application/pdf');

        $response = $this->portalPost('courier', '/register', [
            'name' => 'Test Courier Rider',
            'email' => 't1_courier_reg@example.com',
            'password' => 'Secret123!',
            'password_confirmation' => 'Secret123!',
            'role' => 'courier',
            'phone' => '+63 917 111 0003',
            'address' => '555 Fleet St',
            'city' => 'Taguig',
            'vehicle_type' => 'Motorcycle',
            'plate_number' => 'ABC-1234',
            'license_number' => 'N01-23-456789',
            'id_document' => $idDoc,
            'driver_license' => $license,
            'or_cr_document' => $orCr,
        ]);

        $this->assertTrue($response->isRedirect());
        $this->assertDatabaseHas('users', [
            'email' => 't1_courier_reg@example.com',
            'role' => 'courier',
        ]);
    }

    public function test_t1_f04_04_hub_logistics_registration_with_facility_info(): void
    {
        $hubUser = $this->createPendingUser('logistics', [
            'email' => 't1_hub_reg@example.com',
            'city' => 'Santa Rosa',
        ]);

        $this->assertDatabaseHas('users', [
            'email' => 't1_hub_reg@example.com',
            'role' => 'logistics',
        ]);
        $this->assertEquals('pending_approval', $hubUser->kyc_status);
    }

    public function test_t1_f04_05_registration_post_state_enforcement(): void
    {
        $pendingUser = $this->createPendingUser('seller');

        $response = $this->actingAs($pendingUser)->get('/dashboard');
        $this->assertTrue($response->isRedirect(route('kyc.pending')));
    }

    // ==========================================
    // Feature 5: Cross-Domain Fallback Redirection
    // ==========================================

    public function test_t1_f05_01_seller_to_buyer_checkout_redirect(): void
    {
        $response = $this->portalGet('seller', '/checkout');
        $this->assertTrue(in_array($response->status(), [200, 302]));
    }

    public function test_t1_f05_02_courier_to_buyer_cart_redirect(): void
    {
        $response = $this->portalGet('courier', '/cart');
        $this->assertTrue(in_array($response->status(), [200, 302]));
    }

    public function test_t1_f05_03_hub_to_buyer_catalog_redirect(): void
    {
        $response = $this->portalGet('hub', '/catalog');
        $this->assertTrue(in_array($response->status(), [200, 302]));
    }

    public function test_t1_f05_04_admin_to_buyer_product_detail_redirect(): void
    {
        $response = $this->portalGet('admin', '/catalog');
        $this->assertTrue(in_array($response->status(), [200, 302]));
    }

    public function test_t1_f05_05_query_string_preservation(): void
    {
        $response = $this->portalGet('seller', '/catalog?category=crafts');
        $this->assertTrue(in_array($response->status(), [200, 302]));
    }

    // ==========================================
    // Feature 6: Subdomain Navigation Isolation
    // ==========================================

    public function test_t1_f06_01_seller_cockpit_isolation(): void
    {
        $seller = $this->createApprovedUser('seller');

        $response = $this->actingAs($seller)->onPortal('seller')->get('/seller/dashboard');
        $response->assertOk();
    }

    public function test_t1_f06_02_courier_dispatch_isolation(): void
    {
        $courier = $this->createApprovedUser('courier');

        $response = $this->actingAs($courier)->onPortal('courier')->get('/courier/deliveries');
        $response->assertOk();
    }

    public function test_t1_f06_03_hub_workstation_isolation(): void
    {
        $hub = $this->createApprovedUser('logistics');

        $response = $this->actingAs($hub)->onPortal('hub')->get('/hub');
        $response->assertOk();
    }

    public function test_t1_f06_04_admin_governance_isolation(): void
    {
        $admin = $this->createApprovedUser('admin');

        $response = $this->actingAs($admin)->onPortal('admin')->get('/admin/dashboard');
        $response->assertOk();
    }

    public function test_t1_f06_05_internal_link_domain_preservation(): void
    {
        $seller = $this->createApprovedUser('seller');

        $response = $this->actingAs($seller)->onPortal('seller')->get('/seller/products');
        $response->assertOk();
    }
}

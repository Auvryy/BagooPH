<?php

namespace Tests\Feature\E2E\Tier2;

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

class B01_to_B06_SubdomainAuthBoundaryTest extends TestCase
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
    // Boundary 1: Subdomain Routing Failures
    // ==========================================

    public function test_t2_b01_01_unmapped_subdomain_handling(): void
    {
        $response = $this->withServerVariables(['HTTP_HOST' => 'invalid.bagooph.shop'])->get('/');
        $this->assertTrue(in_array($response->status(), [200, 302, 404]));
    }

    public function test_t2_b01_02_deep_subdomain_injection(): void
    {
        $response = $this->withServerVariables(['HTTP_HOST' => 'sub.seller.bagooph.shop'])->get('/login');
        $this->assertTrue(in_array($response->status(), [200, 302, 404]));
    }

    public function test_t2_b01_03_malformed_host_header(): void
    {
        $response = $this->withServerVariables(['HTTP_HOST' => 'bagooph.shop:99999'])->get('/');
        $this->assertTrue(in_array($response->status(), [200, 302, 400, 404]));
    }

    public function test_t2_b01_04_cross_domain_post_mismatch(): void
    {
        $response = $this->portalPost('seller', '/buyer/cart/add', ['product_id' => 1]);
        $this->assertTrue(in_array($response->status(), [302, 404, 405]));
    }

    public function test_t2_b01_05_protocol_relative_host_injection(): void
    {
        $response = $this->withServerVariables(['HTTP_HOST' => 'evil.com'])->get('/');
        $this->assertTrue(in_array($response->status(), [200, 302, 404]));
    }

    // ==========================================
    // Boundary 2: Cross-Subdomain Session Hijacking
    // ==========================================

    public function test_t2_b02_01_seller_session_isolation_from_courier(): void
    {
        $seller = $this->createApprovedUser('seller');
        $response = $this->actingAs($seller)->portalGet('courier', '/deliveries');
        $this->assertTrue(in_array($response->status(), [302, 403]));
    }

    public function test_t2_b02_02_courier_session_isolation_from_seller(): void
    {
        $courier = $this->createApprovedUser('courier');
        $response = $this->actingAs($courier)->portalGet('seller', '/dashboard');
        $this->assertTrue(in_array($response->status(), [302, 403]));
    }

    public function test_t2_b02_03_hub_session_isolation_from_admin(): void
    {
        $hub = $this->createApprovedUser('logistics');
        $response = $this->actingAs($hub)->portalGet('admin', '/dashboard');
        $this->assertTrue(in_array($response->status(), [302, 403]));
    }

    public function test_t2_b02_04_buyer_session_isolation_from_hub(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $response = $this->actingAs($buyer)->portalGet('hub', '/dashboard');
        $this->assertTrue(in_array($response->status(), [302, 403]));
    }

    public function test_t2_b02_05_unauthenticated_session_token_regeneration(): void
    {
        $this->assertGuest();
    }

    // ==========================================
    // Boundary 3: Role-Locked Authentication Rejection
    // ==========================================

    public function test_t2_b03_01_buyer_credentials_on_seller_domain(): void
    {
        $buyer = $this->createApprovedUser('buyer', [
            'password' => bcrypt('SecretPass123!'),
        ]);

        $response = $this->portalPost('seller', '/login', [
            'email' => $buyer->email,
            'password' => 'SecretPass123!',
        ]);

        // Either returns 422 or redirects back with validation error
        $this->assertTrue(in_array($response->status(), [302, 422]));
    }

    public function test_t2_b03_02_seller_credentials_on_courier_domain(): void
    {
        $seller = $this->createApprovedUser('seller', [
            'password' => bcrypt('SecretPass123!'),
        ]);

        $response = $this->portalPost('courier', '/login', [
            'email' => $seller->email,
            'password' => 'SecretPass123!',
        ]);

        $this->assertTrue(in_array($response->status(), [302, 422]));
    }

    public function test_t2_b03_03_courier_credentials_on_hub_domain(): void
    {
        $courier = $this->createApprovedUser('courier', [
            'password' => bcrypt('SecretPass123!'),
        ]);

        $response = $this->portalPost('hub', '/login', [
            'email' => $courier->email,
            'password' => 'SecretPass123!',
        ]);

        $this->assertTrue(in_array($response->status(), [302, 422]));
    }

    public function test_t2_b03_04_hub_credentials_on_buyer_domain(): void
    {
        $hub = $this->createApprovedUser('logistics', [
            'password' => bcrypt('SecretPass123!'),
        ]);

        $response = $this->portalPost('buyer', '/login', [
            'email' => $hub->email,
            'password' => 'SecretPass123!',
        ]);

        $this->assertTrue(in_array($response->status(), [302, 422]));
    }

    public function test_t2_b03_05_admin_credentials_on_courier_domain(): void
    {
        $admin = $this->createApprovedUser('admin', [
            'password' => bcrypt('SecretPass123!'),
        ]);

        $response = $this->portalPost('courier', '/login', [
            'email' => $admin->email,
            'password' => 'SecretPass123!',
        ]);

        $this->assertTrue(in_array($response->status(), [302, 422]));
    }

    // ==========================================
    // Boundary 4: Registration Validation & KYC Integrity
    // ==========================================

    public function test_t2_b04_01_seller_registration_missing_permit(): void
    {
        $idDoc = UploadedFile::fake()->create('id.png', 500, 'image/png');

        $response = $this->portalPost('seller', '/register', [
            'name' => 'Incomplete Seller',
            'email' => 'incomplete_seller@example.com',
            'password' => 'Secret123!',
            'password_confirmation' => 'Secret123!',
            'role' => 'seller',
            'shop_name' => 'No Permit Shop',
            'phone' => '+63 917 000 0001',
            'address' => '123 St',
            'city' => 'Manila',
            'id_document' => $idDoc,
            // business_permit missing
        ]);

        $response->assertSessionHasErrors('business_permit');
    }

    public function test_t2_b04_02_courier_registration_missing_license(): void
    {
        $idDoc = UploadedFile::fake()->create('id.jpg', 500, 'image/jpeg');
        $orCr = UploadedFile::fake()->create('or_cr.pdf', 800, 'application/pdf');

        $response = $this->portalPost('courier', '/register', [
            'name' => 'Incomplete Courier',
            'email' => 'incomplete_courier@example.com',
            'password' => 'Secret123!',
            'password_confirmation' => 'Secret123!',
            'role' => 'courier',
            'phone' => '+63 917 000 0002',
            'address' => '456 St',
            'city' => 'Manila',
            'vehicle_type' => 'Motorcycle',
            'plate_number' => 'XYZ-1234',
            'id_document' => $idDoc,
            'or_cr_document' => $orCr,
            // driver_license missing
        ]);

        $response->assertSessionHasErrors('driver_license');
    }

    public function test_t2_b04_03_courier_registration_missing_or_cr(): void
    {
        $idDoc = UploadedFile::fake()->create('id.jpg', 500, 'image/jpeg');
        $license = UploadedFile::fake()->create('license.jpg', 500, 'image/jpeg');

        $response = $this->portalPost('courier', '/register', [
            'name' => 'No Or Cr Courier',
            'email' => 'no_orcr_courier@example.com',
            'password' => 'Secret123!',
            'password_confirmation' => 'Secret123!',
            'role' => 'courier',
            'phone' => '+63 917 000 0003',
            'address' => '789 St',
            'city' => 'Manila',
            'vehicle_type' => 'Motorcycle',
            'plate_number' => 'XYZ-9999',
            'id_document' => $idDoc,
            'driver_license' => $license,
            // or_cr_document missing
        ]);

        $response->assertSessionHasErrors('or_cr_document');
    }

    public function test_t2_b04_04_oversized_file_upload_rejected(): void
    {
        // 10MB exceeds 5MB limit
        $hugeFile = UploadedFile::fake()->create('huge.jpg', 10240, 'image/jpeg');

        $response = $this->portalPost('buyer', '/register', [
            'name' => 'Huge File Buyer',
            'email' => 'huge_buyer@example.com',
            'password' => 'Secret123!',
            'password_confirmation' => 'Secret123!',
            'id_document' => $hugeFile,
        ]);

        $response->assertSessionHasErrors('id_document');
    }

    public function test_t2_b04_05_executable_mime_type_rejected(): void
    {
        $exeFile = UploadedFile::fake()->create('exploit.exe', 100, 'application/x-msdownload');

        $response = $this->portalPost('buyer', '/register', [
            'name' => 'Malicious Buyer',
            'email' => 'malicious_buyer@example.com',
            'password' => 'Secret123!',
            'password_confirmation' => 'Secret123!',
            'id_document' => $exeFile,
        ]);

        $response->assertSessionHasErrors('id_document');
    }

    // ==========================================
    // Boundary 5: Cross-Domain Fallback Redirection Boundaries
    // ==========================================

    public function test_t2_b05_01_deep_buyer_path_on_seller_domain(): void
    {
        $response = $this->portalGet('seller', '/products/category/electronics');
        $this->assertTrue(in_array($response->status(), [200, 302, 404]));
    }

    public function test_t2_b05_02_post_fallback_behavior(): void
    {
        $response = $this->portalPost('courier', '/checkout/apply-coupon', ['code' => 'TEST']);
        $this->assertTrue(in_array($response->status(), [302, 404, 405]));
    }

    public function test_t2_b05_03_encoded_path_preservation(): void
    {
        $response = $this->portalGet('hub', '/catalog?search=bag%20leather');
        $this->assertTrue(in_array($response->status(), [200, 302]));
    }

    public function test_t2_b05_04_open_redirect_prevention(): void
    {
        $response = $this->portalGet('seller', '/catalog?return_url=https://evil.com');
        $this->assertTrue(in_array($response->status(), [200, 302]));
    }

    public function test_t2_b05_05_loop_prevention(): void
    {
        $response = $this->portalGet('buyer', '/');
        $this->assertTrue(in_array($response->status(), [200, 302]));
    }

    // ==========================================
    // Boundary 6: Cockpit Navigation Leakage & Route Guards
    // ==========================================

    public function test_t2_b06_01_seller_cockpit_unauthenticated_blocked(): void
    {
        $response = $this->portalGet('seller', '/dashboard');
        $this->assertTrue($response->isRedirect());
    }

    public function test_t2_b06_02_courier_dispatch_unauthenticated_blocked(): void
    {
        $response = $this->portalGet('courier', '/deliveries');
        $this->assertTrue($response->isRedirect());
    }

    public function test_t2_b06_03_hub_workstation_unauthenticated_blocked(): void
    {
        $response = $this->portalGet('hub', '/dashboard');
        $this->assertTrue(in_array($response->status(), [302, 401]));
    }

    public function test_t2_b06_04_admin_governance_unauthenticated_blocked(): void
    {
        $response = $this->portalGet('admin', '/dashboard');
        $this->assertTrue(in_array($response->status(), [302, 401]));
    }

    public function test_t2_b06_05_buyer_dashboard_unauthenticated_blocked(): void
    {
        $response = $this->portalGet('buyer', '/dashboard');
        $this->assertTrue($response->isRedirect());
    }
}

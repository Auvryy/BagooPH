<?php

namespace Tests\Feature\Auth;

use App\Models\CourierProfile;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class Milestone1AdversarialSecurityTest extends TestCase
{
    use RefreshDatabase;

    /*
    |--------------------------------------------------------------------------
    | Dimension 1: Gate Bypasses - Pending Approval & Rejected Status
    |--------------------------------------------------------------------------
    */

    public function test_pending_seller_blocked_from_all_seller_endpoints(): void
    {
        $seller = User::factory()->create([
            'role' => 'seller',
            'status' => 'pending_approval',
            'kyc_status' => 'pending_approval',
        ]);

        Shop::create([
            'user_id' => $seller->id,
            'name' => 'Pending Store',
            'slug' => 'pending-store',
            'status' => 'pending',
        ]);

        $routes = [
            '/seller/dashboard',
            '/seller/products',
            '/seller/orders',
            '/seller/vouchers',
            '/seller/reports',
            '/seller/settings',
        ];

        foreach ($routes as $route) {
            $response = $this->actingAs($seller)->get($route);
            $response->assertRedirect(route('kyc.pending'));
        }
    }

    public function test_pending_courier_blocked_from_all_courier_endpoints(): void
    {
        $courier = User::factory()->create([
            'role' => 'courier',
            'status' => 'pending_approval',
            'kyc_status' => 'pending_approval',
        ]);

        CourierProfile::create([
            'user_id' => $courier->id,
            'vehicle_type' => 'Motorcycle',
            'plate_number' => 'ABC-1234',
            'or_cr_status' => 'Pending Verification',
            'is_available' => false,
        ]);

        $routes = [
            '/courier/deliveries',
            '/courier/earnings',
            '/courier/profile',
            '/courier/messages',
        ];

        foreach ($routes as $route) {
            $response = $this->actingAs($courier)->get($route);
            $response->assertRedirect(route('kyc.pending'));
        }
    }

    public function test_rejected_seller_blocked_from_seller_endpoints(): void
    {
        $seller = User::factory()->create([
            'role' => 'seller',
            'status' => 'pending_approval',
            'kyc_status' => 'rejected',
            'kyc_feedback' => 'Invalid business permit submitted.',
        ]);

        $response = $this->actingAs($seller)->get('/seller/dashboard');
        $response->assertRedirect(route('kyc.pending'));

        $postResponse = $this->actingAs($seller)->post('/seller/products', [
            'name' => 'Adversarial Product',
            'price' => 100,
        ]);
        $postResponse->assertRedirect(route('kyc.pending'));
    }

    public function test_rejected_courier_blocked_from_courier_endpoints(): void
    {
        $courier = User::factory()->create([
            'role' => 'courier',
            'status' => 'pending_approval',
            'kyc_status' => 'rejected',
            'kyc_feedback' => 'OR/CR is blurred.',
        ]);

        $response = $this->actingAs($courier)->get('/courier/deliveries');
        $response->assertRedirect(route('kyc.pending'));
    }

    public function test_pending_and_rejected_users_blocked_from_logistics_hub(): void
    {
        $pendingUser = User::factory()->create([
            'role' => 'logistics',
            'status' => 'pending_approval',
            'kyc_status' => 'pending_approval',
        ]);

        $response = $this->actingAs($pendingUser)->get('/hub');
        $response->assertRedirect(route('kyc.pending'));

        $rejectedUser = User::factory()->create([
            'role' => 'logistics',
            'status' => 'pending_approval',
            'kyc_status' => 'rejected',
        ]);

        $response2 = $this->actingAs($rejectedUser)->get('/hub');
        $response2->assertRedirect(route('kyc.pending'));
    }

    /*
    |--------------------------------------------------------------------------
    | Dimension 2: Suspended Status & Account Lockout
    |--------------------------------------------------------------------------
    */

    public function test_suspended_seller_is_immediately_logged_out_and_session_destroyed(): void
    {
        $suspendedSeller = User::factory()->create([
            'role' => 'seller',
            'status' => 'suspended',
            'kyc_status' => 'approved',
        ]);

        $response = $this->actingAs($suspendedSeller)->get('/seller/dashboard');

        $this->assertGuest();
        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('email');
    }

    public function test_suspended_user_cannot_authenticate_via_login(): void
    {
        $user = User::factory()->create([
            'email' => 'suspended.victim@bagoo.test',
            'password' => Hash::make('password123'),
            'role' => 'buyer',
            'status' => 'suspended',
            'kyc_status' => 'approved',
        ]);

        $response = $this->post('/login', [
            'email' => 'suspended.victim@bagoo.test',
            'password' => 'password123',
        ]);

        $this->assertGuest();
        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('email');
    }

    /*
    |--------------------------------------------------------------------------
    | Dimension 3: Cross-Role Authorization & Role Isolation (403 Forbidden)
    |--------------------------------------------------------------------------
    */

    public function test_approved_buyer_cannot_access_seller_portal(): void
    {
        $buyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        $response = $this->actingAs($buyer)->get('/seller/dashboard');
        $response->assertStatus(403);
    }

    public function test_approved_buyer_cannot_access_courier_dispatch_board(): void
    {
        $buyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        $response = $this->actingAs($buyer)->get('/courier/deliveries');
        $response->assertStatus(403);
    }

    public function test_approved_buyer_cannot_access_logistics_hub(): void
    {
        $buyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        $response = $this->actingAs($buyer)->get('/hub');
        $response->assertStatus(403);
    }

    public function test_approved_seller_cannot_access_courier_portal(): void
    {
        $seller = User::factory()->create([
            'role' => 'seller',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        $response = $this->actingAs($seller)->get('/courier/deliveries');
        $response->assertStatus(403);
    }

    public function test_approved_courier_cannot_access_seller_portal(): void
    {
        $courier = User::factory()->create([
            'role' => 'courier',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        $response = $this->actingAs($courier)->get('/seller/dashboard');
        $response->assertStatus(403);
    }

    /*
    |--------------------------------------------------------------------------
    | Dimension 4: Admin KYC Queue & Privilege Escalation Defenses
    |--------------------------------------------------------------------------
    */

    public function test_unauthenticated_guest_cannot_view_admin_kyc_queue(): void
    {
        $response = $this->get('/admin/kyc');
        $response->assertRedirect(route('login'));
    }

    public function test_buyer_cannot_view_admin_kyc_queue(): void
    {
        $buyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        $response = $this->actingAs($buyer)->get('/admin/kyc');
        $response->assertStatus(403);
    }

    public function test_seller_cannot_view_admin_kyc_queue(): void
    {
        $seller = User::factory()->create([
            'role' => 'seller',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        $response = $this->actingAs($seller)->get('/admin/kyc');
        $response->assertStatus(403);
    }

    public function test_courier_cannot_view_admin_kyc_queue(): void
    {
        $courier = User::factory()->create([
            'role' => 'courier',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        $response = $this->actingAs($courier)->get('/admin/kyc');
        $response->assertStatus(403);
    }

    public function test_unauthorized_user_cannot_call_approve_endpoint(): void
    {
        $pendingUser = User::factory()->create([
            'role' => 'seller',
            'status' => 'pending_approval',
            'kyc_status' => 'pending_approval',
        ]);

        $attackerBuyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        $response = $this->actingAs($attackerBuyer)->post("/admin/kyc/{$pendingUser->id}/approve");
        $response->assertStatus(403);

        $pendingUser->refresh();
        $this->assertEquals('pending_approval', $pendingUser->kyc_status);
    }

    public function test_rejected_user_cannot_self_approve(): void
    {
        $rejectedUser = User::factory()->create([
            'role' => 'seller',
            'status' => 'pending_approval',
            'kyc_status' => 'rejected',
        ]);

        $response = $this->actingAs($rejectedUser)->post("/admin/kyc/{$rejectedUser->id}/approve");
        // Gated by RoleMiddleware or 403
        $this->assertTrue(in_array($response->status(), [302, 403]));

        $rejectedUser->refresh();
        $this->assertEquals('rejected', $rejectedUser->kyc_status);
    }

    public function test_admin_reject_action_requires_valid_reason(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        $pendingUser = User::factory()->create([
            'role' => 'seller',
            'status' => 'pending_approval',
            'kyc_status' => 'pending_approval',
        ]);

        // Empty reason
        $responseEmpty = $this->actingAs($admin)->post("/admin/kyc/{$pendingUser->id}/reject", [
            'reason' => '',
        ]);
        $responseEmpty->assertSessionHasErrors('reason');

        // Short reason (< 5 characters)
        $responseShort = $this->actingAs($admin)->post("/admin/kyc/{$pendingUser->id}/reject", [
            'reason' => 'bad',
        ]);
        $responseShort->assertSessionHasErrors('reason');

        $pendingUser->refresh();
        $this->assertEquals('pending_approval', $pendingUser->kyc_status);
    }

    /*
    |--------------------------------------------------------------------------
    | Dimension 5: KYC Resubmission & File Upload Edge Cases
    |--------------------------------------------------------------------------
    */

    public function test_unauthenticated_user_cannot_access_pending_approval_or_resubmit(): void
    {
        $this->get('/pending-approval')->assertRedirect(route('login'));
        $this->post('/kyc/resubmit')->assertRedirect(route('login'));
    }

    public function test_active_approved_user_accessing_pending_approval_redirects_to_dashboard(): void
    {
        $approvedSeller = User::factory()->create([
            'role' => 'seller',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        $response = $this->actingAs($approvedSeller)->get('/pending-approval');
        $response->assertRedirect(route('dashboard'));
    }

    public function test_resubmit_rejects_disallowed_file_types(): void
    {
        Storage::fake('public');

        $seller = User::factory()->create([
            'role' => 'seller',
            'status' => 'pending_approval',
            'kyc_status' => 'rejected',
            'kyc_feedback' => 'Invalid documents',
        ]);

        // Attacker attempts to upload a PHP / Shell script
        $maliciousFile = UploadedFile::fake()->create('exploit.php', 100, 'application/x-php');

        $response = $this->actingAs($seller)->post('/kyc/resubmit', [
            'business_permit' => $maliciousFile,
        ]);

        $response->assertSessionHasErrors('business_permit');
        $seller->refresh();
        $this->assertEquals('rejected', $seller->kyc_status);
    }

    public function test_resubmit_rejects_oversized_files(): void
    {
        Storage::fake('public');

        $seller = User::factory()->create([
            'role' => 'seller',
            'status' => 'pending_approval',
            'kyc_status' => 'rejected',
            'kyc_feedback' => 'Invalid documents',
        ]);

        // File exceeding 5120 KB limit (e.g. 6000 KB)
        $oversizedFile = UploadedFile::fake()->create('huge_permit.pdf', 6000, 'application/pdf');

        $response = $this->actingAs($seller)->post('/kyc/resubmit', [
            'business_permit' => $oversizedFile,
        ]);

        $response->assertSessionHasErrors('business_permit');
        $seller->refresh();
        $this->assertEquals('rejected', $seller->kyc_status);
    }

    public function test_resubmit_updates_shop_business_permit_and_resets_feedback(): void
    {
        Storage::fake('public');

        $seller = User::factory()->create([
            'role' => 'seller',
            'status' => 'pending_approval',
            'kyc_status' => 'rejected',
            'kyc_feedback' => 'Permit is outdated.',
        ]);

        $shop = Shop::create([
            'user_id' => $seller->id,
            'name' => 'Seller Store',
            'slug' => 'seller-store',
            'status' => 'pending',
            'business_permit_path' => '/storage/old_permit.pdf',
        ]);

        $validPermit = UploadedFile::fake()->create('valid_permit_2026.pdf', 500, 'application/pdf');

        $response = $this->actingAs($seller)->post('/kyc/resubmit', [
            'business_permit' => $validPermit,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $seller->refresh();
        $this->assertEquals('pending_approval', $seller->kyc_status);
        $this->assertEquals('pending_approval', $seller->status);
        $this->assertNull($seller->kyc_feedback);
        $this->assertNotNull($seller->business_permit_path);
    }

    /*
    |--------------------------------------------------------------------------
    | Dimension 6: Universal /dashboard Redirection Correctness
    |--------------------------------------------------------------------------
    */

    public function test_dashboard_routes_unauthenticated_to_login(): void
    {
        $response = $this->get('/dashboard');
        $response->assertRedirect(route('login'));
    }

    public function test_dashboard_routes_pending_user_to_pending_approval(): void
    {
        $pendingBuyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'pending_approval',
            'kyc_status' => 'pending_approval',
        ]);

        $response = $this->actingAs($pendingBuyer)->get('/dashboard');
        $response->assertRedirect(route('kyc.pending'));
    }

    public function test_dashboard_routes_rejected_user_to_pending_approval(): void
    {
        $rejectedSeller = User::factory()->create([
            'role' => 'seller',
            'status' => 'pending_approval',
            'kyc_status' => 'rejected',
        ]);

        $response = $this->actingAs($rejectedSeller)->get('/dashboard');
        $response->assertRedirect(route('kyc.pending'));
    }

    public function test_dashboard_routes_approved_admin_to_admin_dashboard(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        $response = $this->actingAs($admin)->get('/dashboard');
        $response->assertRedirect(route('admin.dashboard'));
    }

    public function test_dashboard_routes_admin_with_pending_kyc_to_admin_dashboard(): void
    {
        $adminPending = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'kyc_status' => 'pending_approval',
        ]);

        $response = $this->actingAs($adminPending)->get('/dashboard');
        $response->assertRedirect(route('admin.dashboard'));
    }

    public function test_dashboard_routes_approved_seller_to_seller_dashboard(): void
    {
        $seller = User::factory()->create([
            'role' => 'seller',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        $response = $this->actingAs($seller)->get('/dashboard');
        $response->assertRedirect(route('seller.dashboard'));
    }

    public function test_dashboard_routes_approved_courier_to_courier_deliveries(): void
    {
        $courier = User::factory()->create([
            'role' => 'courier',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        $response = $this->actingAs($courier)->get('/dashboard');
        $response->assertRedirect(route('courier.deliveries'));
    }

    public function test_dashboard_routes_approved_buyer_to_buyer_index(): void
    {
        $buyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        $response = $this->actingAs($buyer)->get('/dashboard');
        $response->assertRedirect(route('buyer.index'));
    }

    /*
    |--------------------------------------------------------------------------
    | Dimension 7: Registration Validation & Missing Required Documents
    |--------------------------------------------------------------------------
    */

    public function test_seller_registration_strictly_requires_documents_and_shop_name(): void
    {
        $response = $this->post('/register', [
            'name' => 'Fraud Seller',
            'email' => 'fraud.seller@bagoo.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'seller',
            // Missing shop_name, phone, address, city, id_document, business_permit
        ]);

        $response->assertSessionHasErrors(['shop_name', 'phone', 'address', 'city', 'id_document', 'business_permit']);
    }

    public function test_courier_registration_strictly_requires_vehicle_and_license_documents(): void
    {
        $response = $this->post('/register', [
            'name' => 'Fraud Courier',
            'email' => 'fraud.courier@bagoo.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'courier',
            // Missing vehicle_type, plate_number, id_document, driver_license, or_cr_document
        ]);

        $response->assertSessionHasErrors(['vehicle_type', 'plate_number', 'id_document', 'driver_license', 'or_cr_document']);
    }

    /*
    |--------------------------------------------------------------------------
    | Dimension 8: Mass Assignment, Role Escalation & Payload Tampering
    |--------------------------------------------------------------------------
    */

    public function test_registration_blocks_admin_role_escalation_attempt(): void
    {
        $response = $this->post('/register', [
            'name' => 'Hacker Admin',
            'email' => 'hacker.admin@bagoo.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'admin', // disallowed in registration
        ]);

        $response->assertSessionHasErrors('role');
        $this->assertDatabaseMissing('users', ['email' => 'hacker.admin@bagoo.test']);
    }

    public function test_registration_ignores_injected_status_or_kyc_status_payload(): void
    {
        $response = $this->post('/register', [
            'name' => 'Bypass User',
            'email' => 'bypass@bagoo.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'buyer',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        $user = User::where('email', 'bypass@bagoo.test')->first();
        $this->assertNotNull($user);
        $this->assertEquals('pending_approval', $user->status);
        $this->assertEquals('pending_approval', $user->kyc_status);
    }

    /*
    |--------------------------------------------------------------------------
    | Dimension 9: Search & Filter Safety, Idempotency & Null Safety
    |--------------------------------------------------------------------------
    */

    public function test_admin_kyc_queue_handles_sql_injection_and_special_characters_safely(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        $sqliPayloads = [
            "' OR '1'='1",
            "'; DROP TABLE users; --",
            "%' AND (SELECT 1 FROM pg_sleep(0))='1",
            "<script>alert(1)</script>",
        ];

        foreach ($sqliPayloads as $payload) {
            $response = $this->actingAs($admin)->get('/admin/kyc?search=' . urlencode($payload) . '&status=all&role=all');
            $response->assertStatus(200);
        }
    }

    public function test_admin_kyc_approve_handles_user_without_shop_or_courier_gracefully(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        $buyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'pending_approval',
            'kyc_status' => 'pending_approval',
        ]);

        $response = $this->actingAs($admin)->post("/admin/kyc/{$buyer->id}/approve");
        $response->assertRedirect();
        $buyer->refresh();
        $this->assertEquals('approved', $buyer->kyc_status);
        $this->assertEquals('active', $buyer->status);
    }

    public function test_admin_kyc_reject_handles_user_without_shop_or_courier_gracefully(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        $buyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'pending_approval',
            'kyc_status' => 'pending_approval',
        ]);

        $response = $this->actingAs($admin)->post("/admin/kyc/{$buyer->id}/reject", [
            'reason' => 'Government ID is unreadable.',
        ]);
        $response->assertRedirect();
        $buyer->refresh();
        $this->assertEquals('rejected', $buyer->kyc_status);
        $this->assertEquals('Government ID is unreadable.', $buyer->kyc_feedback);
    }

    public function test_admin_approval_and_rejection_are_idempotent(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        $seller = User::factory()->create([
            'role' => 'seller',
            'status' => 'pending_approval',
            'kyc_status' => 'pending_approval',
        ]);

        Shop::create([
            'user_id' => $seller->id,
            'name' => 'Idempotent Shop',
            'slug' => 'idempotent-shop',
            'status' => 'pending',
        ]);

        // Double approval
        $this->actingAs($admin)->post("/admin/kyc/{$seller->id}/approve")->assertRedirect();
        $this->actingAs($admin)->post("/admin/kyc/{$seller->id}/approve")->assertRedirect();
        $seller->refresh();
        $this->assertEquals('approved', $seller->kyc_status);
        $this->assertEquals('active', $seller->status);

        // Double rejection
        $this->actingAs($admin)->post("/admin/kyc/{$seller->id}/reject", ['reason' => 'Duplicate test reason'])->assertRedirect();
        $this->actingAs($admin)->post("/admin/kyc/{$seller->id}/reject", ['reason' => 'Duplicate test reason 2'])->assertRedirect();
        $seller->refresh();
        $this->assertEquals('rejected', $seller->kyc_status);
        $this->assertEquals('Duplicate test reason 2', $seller->kyc_feedback);
    }

    /*
    |--------------------------------------------------------------------------
    | Dimension 10: Dynamic Mid-Session Status State Interceptions
    |--------------------------------------------------------------------------
    */

    public function test_mid_session_rejection_immediately_diverts_subsequent_requests_to_pending_approval(): void
    {
        $seller = User::factory()->create([
            'role' => 'seller',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        Shop::create([
            'user_id' => $seller->id,
            'name' => 'Live Store',
            'slug' => 'live-store',
            'status' => 'active',
        ]);

        // First request is allowed
        $this->actingAs($seller)->get('/seller/dashboard')->assertStatus(200);

        // Administrator revokes approval mid-session
        $seller->update([
            'kyc_status' => 'rejected',
            'kyc_feedback' => 'Audit found forged documents',
        ]);

        // Subsequent request is immediately intercepted
        $this->actingAs($seller)->get('/seller/dashboard')->assertRedirect(route('kyc.pending'));
    }

    public function test_mid_session_suspension_immediately_terminates_session_on_next_request(): void
    {
        $seller = User::factory()->create([
            'role' => 'seller',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        Shop::create([
            'user_id' => $seller->id,
            'name' => 'Live Store 2',
            'slug' => 'live-store-2',
            'status' => 'active',
        ]);

        // First request is allowed
        $this->actingAs($seller)->get('/seller/dashboard')->assertStatus(200);

        // Administrator suspends account mid-session
        $seller->update([
            'status' => 'suspended',
        ]);

        // Next request kills the session and logs user out
        $response = $this->actingAs($seller)->get('/seller/dashboard');
        $this->assertGuest();
        $response->assertRedirect(route('login'));
    }

    public function test_courier_resubmission_updates_driver_license_and_or_cr_paths(): void
    {
        Storage::fake('public');

        $courier = User::factory()->create([
            'role' => 'courier',
            'status' => 'pending_approval',
            'kyc_status' => 'rejected',
            'kyc_feedback' => 'License image was cut off',
        ]);

        $newLicense = UploadedFile::fake()->create('new_license.jpg', 300, 'image/jpeg');
        $newOrCr = UploadedFile::fake()->create('new_or_cr.pdf', 400, 'application/pdf');

        $response = $this->actingAs($courier)->post('/kyc/resubmit', [
            'driver_license' => $newLicense,
            'or_cr_document' => $newOrCr,
        ]);

        $response->assertRedirect();
        $courier->refresh();

        $this->assertEquals('pending_approval', $courier->kyc_status);
        $this->assertNull($courier->kyc_feedback);
        $this->assertNotNull($courier->driver_license_path);
        $this->assertNotNull($courier->or_cr_path);
    }
}

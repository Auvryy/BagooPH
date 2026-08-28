<?php

namespace Tests\Feature\Admin;

use App\Models\CourierProfile;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminKycApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_kyc_queue(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        $pendingSeller = User::factory()->create([
            'role' => 'seller',
            'status' => 'pending_approval',
            'kyc_status' => 'pending_approval',
        ]);

        $response = $this->actingAs($admin)->get('/admin/kyc');
        $response->assertStatus(200);
    }

    public function test_non_admin_cannot_access_kyc_queue(): void
    {
        $seller = User::factory()->create([
            'role' => 'seller',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        $response = $this->actingAs($seller)->get('/admin/kyc');
        $response->assertStatus(403);
    }

    public function test_admin_can_approve_seller_applicant(): void
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

        $shop = Shop::create([
            'user_id' => $seller->id,
            'name' => 'Pending Shop',
            'slug' => 'pending-shop',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($admin)->post("/admin/kyc/{$seller->id}/approve");
        $response->assertRedirect();
        $response->assertSessionHas('success');

        $seller->refresh();
        $this->assertEquals('approved', $seller->kyc_status);
        $this->assertEquals('active', $seller->status);
        $this->assertNotNull($seller->kyc_reviewed_at);
        $this->assertNull($seller->kyc_feedback);

        $shop->refresh();
        $this->assertEquals('active', $shop->status);
    }

    public function test_admin_can_approve_courier_applicant(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        $courier = User::factory()->create([
            'role' => 'courier',
            'status' => 'pending_approval',
            'kyc_status' => 'pending_approval',
        ]);

        $courierProfile = CourierProfile::create([
            'user_id' => $courier->id,
            'vehicle_type' => 'Motorcycle',
            'plate_number' => 'ABC-1234',
            'or_cr_status' => 'Pending Verification',
            'is_available' => false,
        ]);

        $response = $this->actingAs($admin)->post("/admin/kyc/{$courier->id}/approve");
        $response->assertRedirect();

        $courier->refresh();
        $this->assertEquals('approved', $courier->kyc_status);
        $this->assertEquals('active', $courier->status);

        $courierProfile->refresh();
        $this->assertEquals('Verified & Registered', $courierProfile->or_cr_status);
        $this->assertTrue($courierProfile->is_available);
    }

    public function test_admin_can_reject_applicant_with_feedback(): void
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

        $response = $this->actingAs($admin)->post("/admin/kyc/{$seller->id}/reject", [
            'reason' => 'The uploaded business permit is expired. Please submit valid 2026 permit.',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $seller->refresh();
        $this->assertEquals('rejected', $seller->kyc_status);
        $this->assertEquals('pending_approval', $seller->status);
        $this->assertEquals('The uploaded business permit is expired. Please submit valid 2026 permit.', $seller->kyc_feedback);
        $this->assertNotNull($seller->kyc_reviewed_at);
    }

    public function test_rejected_applicant_can_resubmit_documents(): void
    {
        Storage::fake('public');

        $seller = User::factory()->create([
            'role' => 'seller',
            'status' => 'pending_approval',
            'kyc_status' => 'rejected',
            'kyc_feedback' => 'Permit is expired.',
        ]);

        $newPermit = UploadedFile::fake()->create('new_permit_2026.pdf', 1000, 'application/pdf');

        $response = $this->actingAs($seller)->post('/kyc/resubmit', [
            'business_permit' => $newPermit,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $seller->refresh();
        $this->assertEquals('pending_approval', $seller->kyc_status);
        $this->assertEquals('pending_approval', $seller->status);
        $this->assertNull($seller->kyc_feedback);
        $this->assertNotNull($seller->business_permit_path);
    }
}

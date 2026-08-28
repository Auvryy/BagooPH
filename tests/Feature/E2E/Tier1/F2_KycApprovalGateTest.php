<?php

namespace Tests\Feature\E2E\Tier1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\E2E\Support\AssertsCommissionLedgers;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\Feature\E2E\Support\SimulatesOrderLifecycle;
use Tests\TestCase;

class F2_KycApprovalGateTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithRoles, CreatesE2EOrders, SimulatesOrderLifecycle, AssertsDeliveryCheckpoints, AssertsCommissionLedgers;

    public function test_f2_01_pending_user_is_redirected_to_pending_approval_holding_page(): void
    {
        $pendingSeller = $this->createPendingUser('seller');

        $response = $this->actingAs($pendingSeller)->get(route('seller.dashboard'));

        $response->assertRedirect(route('kyc.pending'));
    }

    public function test_f2_02_admin_can_view_pending_kyc_verification_queue(): void
    {
        $this->createPendingUser('seller', ['name' => 'Pending Merchant Alpha']);
        $this->createPendingUser('courier', ['name' => 'Pending Rider Beta']);

        $admin = $this->createApprovedUser('admin');

        $response = $this->actingAs($admin)->get(route('admin.kyc.index'));

        $response->assertOk();
    }

    public function test_f2_03_admin_can_approve_pending_user_activating_account(): void
    {
        $pendingCourier = $this->createPendingUser('courier');
        $admin = $this->createApprovedUser('admin');

        $response = $this->actingAs($admin)->post(route('admin.kyc.approve', $pendingCourier->id));

        $response->assertSessionHas('success');

        $pendingCourier->refresh();
        $this->assertEquals('approved', $pendingCourier->kyc_status);
        $this->assertEquals('active', $pendingCourier->status);
        $this->assertNotNull($pendingCourier->kyc_reviewed_at);
        $this->assertNull($pendingCourier->kyc_feedback);
    }

    public function test_f2_04_admin_can_reject_pending_user_with_feedback_reason(): void
    {
        $pendingUser = $this->createPendingUser('seller');
        $admin = $this->createApprovedUser('admin');

        $response = $this->actingAs($admin)->post(route('admin.kyc.reject', $pendingUser->id), [
            'reason' => 'The uploaded business permit is expired. Please submit a valid 2026 permit.',
        ]);

        $response->assertSessionHas('success');

        $pendingUser->refresh();
        $this->assertEquals('rejected', $pendingUser->kyc_status);
        $this->assertEquals('The uploaded business permit is expired. Please submit a valid 2026 permit.', $pendingUser->kyc_feedback);
        $this->assertNotNull($pendingUser->kyc_reviewed_at);
    }

    public function test_f2_05_approved_user_can_access_role_dashboard_immediately(): void
    {
        $approvedSeller = $this->createApprovedUser('seller');

        $response = $this->actingAs($approvedSeller)->get(route('seller.dashboard'));

        $response->assertOk();
    }
}

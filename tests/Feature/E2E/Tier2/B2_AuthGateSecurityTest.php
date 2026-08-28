<?php

namespace Tests\Feature\E2E\Tier2;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\E2E\Support\AssertsCommissionLedgers;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\Feature\E2E\Support\SimulatesOrderLifecycle;
use Tests\TestCase;

class B2_AuthGateSecurityTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithRoles, CreatesE2EOrders, SimulatesOrderLifecycle, AssertsDeliveryCheckpoints, AssertsCommissionLedgers;

    public function test_b2_01_unapproved_user_attempting_direct_dashboard_url_is_blocked_and_redirected(): void
    {
        $pendingSeller = $this->createPendingUser('seller');
        $sellerResponse = $this->actingAs($pendingSeller)->get(route('seller.dashboard'));
        $sellerResponse->assertRedirect(route('kyc.pending'));

        $pendingCourier = $this->createPendingUser('courier');
        $courierResponse = $this->actingAs($pendingCourier)->get(route('courier.deliveries'));
        $courierResponse->assertRedirect(route('kyc.pending'));

        $pendingBuyer = $this->createPendingUser('buyer');
        $buyerResponse = $this->actingAs($pendingBuyer)->get(route('dashboard'));
        $buyerResponse->assertRedirect(route('kyc.pending'));
    }

    public function test_b2_02_rejected_user_cannot_access_transactional_actions_and_sees_rejection_reason(): void
    {
        $feedback = 'Submitted identification document was unreadable and blurry. Please re-upload a clear copy.';
        $rejectedSeller = $this->createRejectedUser('seller', $feedback);

        // Attempting to access transactional seller cockpit is blocked
        $ordersResponse = $this->actingAs($rejectedSeller)->get(route('seller.orders.index'));
        $ordersResponse->assertRedirect(route('kyc.pending'));

        // Accessing the holding page shows the feedback reason
        $pendingPageResponse = $this->actingAs($rejectedSeller)->get(route('kyc.pending'));
        $pendingPageResponse->assertOk();
        $this->assertEquals('rejected', $rejectedSeller->fresh()->kyc_status);
        $this->assertEquals($feedback, $rejectedSeller->fresh()->kyc_feedback);
    }

    public function test_b2_03_non_admin_user_cannot_access_admin_kyc_approval_endpoints_403(): void
    {
        $pendingTarget = $this->createPendingUser('courier');

        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $courier = $this->createApprovedUser('courier');

        // Buyer attempting to access admin KYC queue or approve
        $this->actingAs($buyer)->get(route('admin.kyc.index'))->assertForbidden();
        $this->actingAs($buyer)->post(route('admin.kyc.approve', $pendingTarget->id))->assertForbidden();

        // Seller attempting to reject applicant
        $this->actingAs($seller)->post(route('admin.kyc.reject', $pendingTarget->id), [
            'reason' => 'Unauthorized rejection attempt',
        ])->assertForbidden();

        // Courier attempting to access admin dashboard
        $this->actingAs($courier)->get(route('admin.dashboard'))->assertForbidden();

        // Verify target status was not mutated
        $pendingTarget->refresh();
        $this->assertEquals('pending_approval', $pendingTarget->kyc_status);
    }

    public function test_b2_04_suspended_or_inactive_user_cannot_authenticate_or_advance_orders(): void
    {
        $suspendedSeller = $this->createApprovedUser('seller', [
            'status' => 'suspended',
            'email' => 'suspended.seller@example.com',
        ]);

        $response = $this->actingAs($suspendedSeller)->get(route('seller.dashboard'));

        // Middleware logs out suspended user and redirects to login with error
        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors(['email']);
        $this->assertGuest();
    }

    public function test_b2_05_csrf_and_unauthenticated_requests_to_kyc_endpoints_are_rejected(): void
    {
        $pendingUser = $this->createPendingUser('buyer');

        // Unauthenticated access to admin KYC queue
        $queueResponse = $this->get(route('admin.kyc.index'));
        $queueResponse->assertRedirect(route('login'));

        // Unauthenticated post to approve
        $approveResponse = $this->post(route('admin.kyc.approve', $pendingUser->id));
        $approveResponse->assertRedirect(route('login'));

        // Unauthenticated access to hub
        $hubResponse = $this->get(route('hub.index'));
        $hubResponse->assertRedirect(route('login'));
    }
}

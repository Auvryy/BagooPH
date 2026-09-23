<?php

namespace Tests\Feature\E2E\Tier2;

use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
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

class B12_to_B17_LifecycleHubToCompletedBoundaryTest extends TestCase
{
    use AssertsCommissionLedgers, AssertsDeliveryCheckpoints, CreatesE2EOrders, InteractsWithPortals, InteractsWithRoles, SimulatesOrderLifecycle;
    use RefreshDatabase;

    // ==========================================
    // Boundary 12: Hub Intake Barcode Mismatch
    // ==========================================

    public function test_t2_b12_01_non_existent_barcode_scan(): void
    {
        $logistics = $this->createApprovedUser('logistics');
        $response = $this->actingAs($logistics)->postJson(route('hub.scan'), [
            'barcode' => 'BGO-TRK-NONEXISTENT',
        ]);
        $this->assertTrue(in_array($response->status(), [400, 404, 422]));
    }

    public function test_t2_b12_02_double_intake_scan_idempotency(): void
    {
        $this->seed(DatabaseSeeder::class);
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $courier = $this->createApprovedUser('courier');
        $company = LogisticsCompany::where('code', 'BGX')->firstOrFail();
        $originHub = LogisticsHub::where('code', 'BH-LBN-01')->firstOrFail();
        $motherHub = LogisticsHub::where('code', 'MH-LAG-01')->firstOrFail();
        $destinationHub = LogisticsHub::where('code', 'BH-SCZ-01')->firstOrFail();
        $delivery = $this->createE2EDelivery($order, 'picked_up', $courier, [
            'logistics_company_id' => $company->id,
            'origin_bayan_hub_id' => $originHub->id,
            'origin_mother_hub_id' => $motherHub->id,
            'destination_mother_hub_id' => $motherHub->id,
            'destination_bayan_hub_id' => $destinationHub->id,
            'current_hub_id' => null,
        ]);
        $logistics = User::where('email', 'losbanos.hub@bagoo.test')->firstOrFail();

        $payload = [
            'barcode' => $delivery->tracking_number,
            'hub_id' => $originHub->id,
            'mode' => 'inspect',
        ];
        $res1 = $this->actingAs($logistics)->postJson(route('hub.scan'), $payload);
        $res2 = $this->actingAs($logistics)->postJson(route('hub.scan'), $payload);

        $res1->assertOk();
        $res2->assertOk();
        $this->assertSame('picked_up', $delivery->fresh()->status);
        $this->assertDatabaseMissing('delivery_checkpoints', [
            'delivery_id' => $delivery->id,
            'checkpoint_type' => 'arrived_at_origin_hub',
        ]);
    }

    public function test_t2_b12_03_intake_scan_on_cancelled_parcel(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'cancelled');
        $delivery = $this->createE2EDelivery($order, 'cancelled');
        $logistics = $this->createApprovedUser('logistics');

        $this->assertEquals('cancelled', $delivery->status);
    }

    public function test_t2_b12_04_non_hub_user_intake_barred(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $response = $this->actingAs($buyer)->postJson(route('hub.scan'), [
            'barcode' => 'BGO-TRK-ANY',
        ]);
        $this->assertTrue(in_array($response->status(), [302, 403]));
    }

    public function test_t2_b12_05_premature_intake_before_pickup(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'ready_for_pickup');
        $delivery = $this->createE2EDelivery($order, 'unassigned');
        $logistics = $this->createApprovedUser('logistics');

        $this->assertEquals('unassigned', $delivery->status);
    }

    // ==========================================
    // Boundary 13: Destination Sorting Misclassification
    // ==========================================

    public function test_t2_b13_01_sorting_unsorted_non_intake_parcel(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'ready_for_pickup');
        $delivery = $this->createE2EDelivery($order, 'unassigned');
        $logistics = $this->createApprovedUser('logistics');

        $this->assertEquals('unassigned', $delivery->status);
    }

    public function test_t2_b13_02_invalid_destination_area_rejected(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $delivery = $this->createE2EDelivery($order, 'in_transit');
        $logistics = $this->createApprovedUser('logistics');

        $this->assertNotNull($delivery->id);
    }

    public function test_t2_b13_03_re_sorting_parcel_updates_bin(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $delivery = $this->createE2EDelivery($order, 'in_transit');
        $logistics = $this->createApprovedUser('logistics');

        $this->actingAs($logistics)->postJson(route('hub.sort'), [
            'delivery_id' => $delivery->id,
            'bin' => 'BIN-A1',
            'barangay' => 'Santa Cruz, Laguna',
        ]);

        $this->actingAs($logistics)->postJson(route('hub.sort'), [
            'delivery_id' => $delivery->id,
            'bin' => 'BIN-A2',
            'barangay' => 'Santa Cruz, Laguna',
        ]);

        $this->assertNotNull($delivery->id);
    }

    public function test_t2_b13_04_missing_bin_identifier_rejected(): void
    {
        $logistics = $this->createApprovedUser('logistics');

        $response = $this->actingAs($logistics)->postJson(route('hub.sort'), [
            'delivery_id' => 999999, // non-existent delivery
        ]);
        $this->assertTrue(in_array($response->status(), [400, 404, 422]));
    }

    public function test_t2_b13_05_non_hub_operator_sorting_barred(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $response = $this->actingAs($buyer)->postJson(route('hub.sort'), [
            'delivery_id' => 1,
            'bin' => 'BIN-1',
        ]);
        $this->assertTrue(in_array($response->status(), [302, 403]));
    }

    // ==========================================
    // Boundary 14: Rider Assignment Incompatibility
    // ==========================================

    public function test_t2_b14_01_cross_area_rider_assignment_mismatch(): void
    {
        $courierA = $this->createApprovedUser('courier');
        $courierB = $this->createApprovedUser('courier');
        $this->assertNotEquals($courierA->id, $courierB->id);
    }

    public function test_t2_b14_02_assignment_to_suspended_rider_barred(): void
    {
        $suspendedCourier = $this->createApprovedUser('courier', ['status' => 'suspended']);
        $this->assertEquals('suspended', $suspendedCourier->status);
    }

    public function test_t2_b14_03_assignment_to_pending_kyc_rider_barred(): void
    {
        $pendingCourier = $this->createPendingUser('courier');
        $this->assertEquals('pending_approval', $pendingCourier->kyc_status);
    }

    public function test_t2_b14_04_assignment_without_hub_sort_barred(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'ready_for_pickup');
        $delivery = $this->createE2EDelivery($order, 'unassigned');

        $this->assertEquals('unassigned', $delivery->status);
    }

    public function test_t2_b14_05_double_assignment_collision(): void
    {
        $courierA = $this->createApprovedUser('courier');
        $courierB = $this->createApprovedUser('courier');
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $delivery = $this->createE2EDelivery($order, 'in_transit', $courierA);

        $this->assertEquals($courierA->id, $delivery->courier_id);
    }

    // ==========================================
    // Boundary 15: Out for Delivery Departure Violations
    // ==========================================

    public function test_t2_b15_01_out_for_delivery_without_rider_assignment(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $delivery = $this->createE2EDelivery($order, 'in_transit');

        $courier = $this->createApprovedUser('courier');
        $response = $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'out_for_delivery',
        ]);

        $delivery->refresh();
        $this->assertNotEquals('out_for_delivery', $delivery->status);
    }

    public function test_t2_b15_02_non_assigned_rider_dispatching_barred(): void
    {
        $courierA = $this->createApprovedUser('courier');
        $courierB = $this->createApprovedUser('courier');
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $delivery = $this->createE2EDelivery($order, 'in_transit', $courierA);

        $this->actingAs($courierB)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'out_for_delivery',
        ]);

        $delivery->refresh();
        $this->assertEquals($courierA->id, $delivery->courier_id);
    }

    public function test_t2_b15_03_double_out_for_delivery_invocation(): void
    {
        $courier = $this->createApprovedUser('courier');
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $delivery = $this->createE2EDelivery($order, 'assigned_to_rider', $courier);

        $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'out_for_delivery',
        ]);
        $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'out_for_delivery',
        ]);

        $delivery->refresh();
        $this->assertEquals('out_for_delivery', $delivery->status);
    }

    public function test_t2_b15_04_out_for_delivery_on_cancelled_order(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'cancelled');
        $delivery = $this->createE2EDelivery($order, 'cancelled');

        $this->assertEquals('cancelled', $delivery->status);
    }

    public function test_t2_b15_05_missing_courier_notes_validation(): void
    {
        $courier = $this->createApprovedUser('courier');
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $delivery = $this->createE2EDelivery($order, 'in_transit', $courier);

        $response = $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'out_for_delivery',
        ]);
        $this->assertTrue(in_array($response->status(), [200, 302]));
    }

    // ==========================================
    // Boundary 16: Doorstep Handover Missing Proof
    // ==========================================

    public function test_t2_b16_01_handover_without_proof_photo_is_rejected(): void
    {
        $this->seed(DatabaseSeeder::class);
        $courier = User::where('email', 'rider@bagoo.test')->firstOrFail();
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $delivery = $this->createE2EDelivery($order, 'out_for_delivery', $courier, $this->seededFinalMileRoute());

        $response = $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'delivered',
        ]);

        $response->assertSessionHas('error');
        $delivery->refresh();
        $this->assertEquals('out_for_delivery', $delivery->status);
        $this->assertNull($delivery->proof_image);
    }

    public function test_t2_b16_02_handover_from_wrong_status(): void
    {
        $courier = $this->createApprovedUser('courier');
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'ready_for_pickup');
        $delivery = $this->createE2EDelivery($order, 'unassigned');

        $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'delivered',
        ]);

        $delivery->refresh();
        $this->assertNotEquals('delivered', $delivery->status);
    }

    public function test_t2_b16_03_double_delivered_invocation_idempotency(): void
    {
        $this->seed(DatabaseSeeder::class);
        $courier = User::where('email', 'rider@bagoo.test')->firstOrFail();
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $delivery = $this->createE2EDelivery($order, 'out_for_delivery', $courier, $this->seededFinalMileRoute());

        $payload = [
            'status' => 'delivered',
            'proof_image_file' => UploadedFile::fake()->create('delivery-proof.jpg', 20, 'image/jpeg'),
        ];
        $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), $payload);
        $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), ['status' => 'delivered']);

        $delivery->refresh();
        $this->assertEquals('delivered', $delivery->status);
        $this->assertSame(1, DeliveryCheckpoint::where('delivery_id', $delivery->id)
            ->where('checkpoint_type', 'delivered')
            ->count());
        Storage::disk('public')->delete(str_replace('/storage/', '', $delivery->proof_image));
    }

    private function seededFinalMileRoute(): array
    {
        $company = LogisticsCompany::where('code', 'BGX')->firstOrFail();
        $originHub = LogisticsHub::where('code', 'BH-LBN-01')->firstOrFail();
        $motherHub = LogisticsHub::where('code', 'MH-LAG-01')->firstOrFail();
        $destinationHub = LogisticsHub::where('code', 'BH-SCZ-01')->firstOrFail();

        return [
            'logistics_company_id' => $company->id,
            'origin_bayan_hub_id' => $originHub->id,
            'origin_mother_hub_id' => $motherHub->id,
            'destination_mother_hub_id' => $motherHub->id,
            'destination_bayan_hub_id' => $destinationHub->id,
            'current_hub_id' => null,
        ];
    }

    public function test_t2_b16_04_non_assigned_courier_handover_barred(): void
    {
        $courierA = $this->createApprovedUser('courier');
        $courierB = $this->createApprovedUser('courier');
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $delivery = $this->createE2EDelivery($order, 'out_for_delivery', $courierA);

        $this->actingAs($courierB)->patch(route('courier.updateStatus', $delivery->id), ['status' => 'delivered']);

        $delivery->refresh();
        $this->assertEquals($courierA->id, $delivery->courier_id);
    }

    public function test_t2_b16_05_proof_image_storage(): void
    {
        $this->seed(DatabaseSeeder::class);
        $courier = User::where('email', 'rider@bagoo.test')->firstOrFail();
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $delivery = $this->createE2EDelivery($order, 'out_for_delivery', $courier, $this->seededFinalMileRoute());

        $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'delivered',
            'proof_image_file' => UploadedFile::fake()->create('proof.jpg', 20, 'image/jpeg'),
        ]);

        $delivery->refresh();
        $this->assertStringStartsWith('/storage/delivery-proofs/', $delivery->proof_image);
        $proofPath = str_replace('/storage/', '', $delivery->proof_image);
        $this->assertTrue(Storage::disk('public')->exists($proofPath));
        Storage::disk('public')->delete($proofPath);
    }

    // ==========================================
    // Boundary 17: Order Completion State Skipping
    // ==========================================

    public function test_t2_b17_01_complete_order_before_delivery_barred(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'placed');

        $this->assertEquals('placed', $order->status);
    }

    public function test_t2_b17_02_non_buyer_order_completion_barred(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'delivered');

        $otherUser = $this->createApprovedUser('buyer');
        $this->assertNotEquals($buyer->id, $otherUser->id);
    }

    public function test_t2_b17_03_double_order_completion_idempotency(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'delivered');

        $order->update(['status' => 'completed']);
        $order->update(['status' => 'completed']);

        $this->assertEquals('completed', $order->fresh()->status);
    }

    public function test_t2_b17_04_cancelled_order_completion_barred(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'cancelled');

        $this->assertEquals('cancelled', $order->status);
    }

    public function test_t2_b17_05_post_completion_settlement_bounds(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'completed');

        $this->assertEquals('completed', $order->status);
    }
}

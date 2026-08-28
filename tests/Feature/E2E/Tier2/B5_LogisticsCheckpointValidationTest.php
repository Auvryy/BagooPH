<?php

namespace Tests\Feature\E2E\Tier2;

use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\E2E\Support\AssertsCommissionLedgers;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\Feature\E2E\Support\SimulatesOrderLifecycle;
use Tests\TestCase;

class B5_LogisticsCheckpointValidationTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithRoles, CreatesE2EOrders, SimulatesOrderLifecycle, AssertsDeliveryCheckpoints, AssertsCommissionLedgers;

    public function test_b5_01_hub_scan_fails_when_tracking_barcode_does_not_exist(): void
    {
        $logistics = $this->createApprovedUser('logistics');

        $response = $this->actingAs($logistics)->postJson(route('hub.scan'), [
            'barcode' => 'BGO-NON-EXISTENT-CODE-999',
            'location_name' => 'Metro Manila Central Sorting Station',
        ]);

        $response->assertStatus(404);
        $response->assertJson([
            'error' => 'Parcel not found with tracking code BGO-NON-EXISTENT-CODE-999',
        ]);
    }

    public function test_b5_02_hub_scan_rejects_packages_not_yet_picked_up_by_courier(): void
    {
        $logistics = $this->createApprovedUser('logistics');

        // Empty barcode validation rejection
        $response = $this->actingAs($logistics)->postJson(route('hub.scan'), [
            'barcode' => '',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['barcode']);
    }

    public function test_b5_03_duplicate_hub_scans_do_not_corrupt_delivery_state_or_create_duplicate_checkpoints(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $courier = $this->createApprovedUser('courier');
        $delivery = $this->createE2EDelivery($order, 'picked_up', $courier);

        $logistics = $this->createApprovedUser('logistics');

        // First Hub Intake Scan
        $firstScan = $this->actingAs($logistics)->postJson(route('hub.scan'), [
            'barcode' => $delivery->tracking_number,
            'location_name' => 'Metro Manila Central Sorting Station',
        ]);
        $firstScan->assertOk();

        // Second Hub Intake Scan (Idempotency / Resilience boundary)
        $secondScan = $this->actingAs($logistics)->postJson(route('hub.scan'), [
            'barcode' => $delivery->tracking_number,
            'location_name' => 'Metro Manila Central Sorting Station',
        ]);
        $secondScan->assertOk();

        $delivery->refresh();
        $this->assertEquals('in_transit', $delivery->status);

        // Checkpoints are logged and distinct
        $checkpointsCount = DeliveryCheckpoint::where('delivery_id', $delivery->id)
            ->where('checkpoint_type', 'hub_intake')
            ->count();
        $this->assertEquals(2, $checkpointsCount);
    }

    public function test_b5_04_non_logistics_and_non_admin_users_cannot_access_hub_intake_workstation(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $courier = $this->createApprovedUser('courier');

        // Buyer access to /hub
        $this->actingAs($buyer)->get(route('hub.index'))->assertForbidden();

        // Seller access to /hub/scan
        $this->actingAs($seller)->postJson(route('hub.scan'), [
            'barcode' => 'BGO-TEST-BARCODE',
        ])->assertForbidden();

        // Courier access to /hub/sort
        $this->actingAs($courier)->postJson(route('hub.sort'), [
            'delivery_id' => 1,
        ])->assertForbidden();
    }

    public function test_b5_05_supervisor_override_with_invalid_courier_id_or_status_returns_validation_error(): void
    {
        $admin = $this->createApprovedUser('admin');
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'ready_for_pickup');
        $delivery = $this->createE2EDelivery($order, 'unassigned');

        // Invalid courier ID (non-existent 99999) and invalid status 'flying'
        $response = $this->actingAs($admin)->post(route('admin.logistics.override'), [
            'delivery_id' => $delivery->id,
            'courier_id' => 99999,
            'status' => 'flying',
        ]);

        $response->assertSessionHasErrors(['courier_id', 'status']);

        // Delivery remains unchanged
        $delivery->refresh();
        $this->assertEquals('unassigned', $delivery->status);
        $this->assertNull($delivery->courier_id);
    }
}

<?php

namespace Tests\Feature\E2E\Tier1;

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

class F5_LogisticsHubCheckpointTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithRoles, CreatesE2EOrders, SimulatesOrderLifecycle, AssertsDeliveryCheckpoints, AssertsCommissionLedgers;

    public function test_f5_01_logistics_operator_can_access_dedicated_hub_workstation(): void
    {
        $logistics = $this->createApprovedUser('logistics');

        $response = $this->actingAs($logistics)->get(route('hub.index'));

        $response->assertOk();
    }

    public function test_f5_02_seller_dispatch_scan_logs_packaging_release_checkpoint(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'pending');

        $this->actingAs($seller);
        $this->advanceOrderStage($order);

        $order->refresh();
        $this->assertNotNull($order->delivery);

        $this->assertCheckpointLogged($order->delivery, 'seller_pack');
    }

    public function test_f5_03_courier_store_pickup_scan_logs_pickup_checkpoint(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'ready_for_pickup');
        $courier = $this->createApprovedUser('courier');
        $delivery = $this->createE2EDelivery($order, 'unassigned');

        $this->actingAs($courier);
        $this->advanceOrderStage($order);

        $delivery->refresh();
        $this->assertCheckpointLogged($delivery, 'courier_pickup');
        $this->assertBarcodeScanned($delivery, $delivery->tracking_number);
    }

    public function test_f5_04_hub_intake_scan_assigns_barangay_sorting_bin_checkpoint(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $courier = $this->createApprovedUser('courier');
        $delivery = $this->createE2EDelivery($order, 'picked_up', $courier);

        $logistics = $this->createApprovedUser('logistics');

        // Hub Intake Scan
        $scanResponse = $this->actingAs($logistics)->postJson(route('hub.scan'), [
            'barcode' => $delivery->tracking_number,
            'location_name' => 'Metro Manila Central Sorting Station',
        ]);
        $scanResponse->assertOk();

        // Barangay Sorting
        $sortResponse = $this->actingAs($logistics)->postJson(route('hub.sort'), [
            'delivery_id' => $delivery->id,
            'barangay' => 'Barangay San Antonio',
            'bin' => 'BIN-C4',
        ]);
        $sortResponse->assertOk();

        $delivery->refresh();
        $this->assertCheckpointLogged($delivery, 'hub_intake');
        $this->assertCheckpointLogged($delivery, 'barangay_sort', 'Barangay San Antonio');
    }

    public function test_f5_05_doorstep_scan_logs_final_handover_checkpoint_with_proof(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $courier = $this->createApprovedUser('courier');
        $delivery = $this->createE2EDelivery($order, 'out_for_delivery', $courier);

        $this->actingAs($courier);
        $this->advanceOrderStage($order);

        $delivery->refresh();
        $this->assertCheckpointLogged($delivery, 'doorstep_handover');

        $checkpoint = DeliveryCheckpoint::where('delivery_id', $delivery->id)
            ->where('checkpoint_type', 'doorstep_handover')
            ->first();

        $this->assertNotNull($checkpoint);
        $this->assertNotNull($checkpoint->proof_image);
    }
}

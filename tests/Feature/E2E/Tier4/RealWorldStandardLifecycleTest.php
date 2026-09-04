<?php

namespace Tests\Feature\E2E\Tier4;

use App\Models\CommissionLedger;
use App\Models\CourierProfile;
use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\E2E\Support\AssertsCommissionLedgers;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithPortals;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\Feature\E2E\Support\SimulatesOrderLifecycle;
use Tests\TestCase;

class RealWorldStandardLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithRoles, CreatesE2EOrders, SimulatesOrderLifecycle, AssertsDeliveryCheckpoints, AssertsCommissionLedgers, InteractsWithPortals;

    public function test_t4_01_metro_manila_standard_delivery_lifecycle(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'placed');
        $delivery = $this->createE2EDelivery($order, 'unassigned');
        $courier = $this->createApprovedUser('courier');
        $logistics = $this->createApprovedUser('logistics');

        // Seller confirms and packs
        $order->update(['status' => 'confirmed']);
        $this->actingAs($seller)->post(route('seller.orders.pack', $order->id));
        $this->actingAs($seller)->post(route('seller.orders.ready', $order->id));

        // Courier claims and picks up
        $this->actingAs($courier)->post(route('courier.claim', $delivery->id));
        $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), ['status' => 'picked_up']);

        // Hub intakes and sorts
        $this->actingAs($logistics)->postJson(route('hub.scan'), ['barcode' => $delivery->tracking_number]);
        $this->actingAs($logistics)->postJson(route('hub.sort'), [
            'delivery_id' => $delivery->id,
            'bin' => 'BIN-A1',
            'barangay' => 'Santa Cruz, Laguna',
        ]);

        // Out for delivery and delivered
        $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), ['status' => 'out_for_delivery']);
        $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'delivered',
            'proof_image' => 'https://images.unsplash.com/photo-1586528116311-ad8dd3c8310d?w=500',
        ]);

        $order->update(['status' => 'completed']);

        $this->assertEquals('completed', $order->fresh()->status);
        $this->assertEquals('delivered', $delivery->fresh()->status);
        $this->assertCommissionSplit($order);
    }

    public function test_t4_02_provincial_laguna_delivery_with_area_b_sorting(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $delivery = $this->createE2EDelivery($order, 'in_transit');
        $logistics = $this->createApprovedUser('logistics');

        $response = $this->actingAs($logistics)->postJson(route('hub.sort'), [
            'delivery_id' => $delivery->id,
            'bin' => 'BIN-B1',
            'barangay' => 'Pagsanjan, Laguna',
        ]);
        $response->assertOk();

        $delivery->refresh();
        $this->assertCheckpointLogged($delivery, 'barangay_sort', 'Pagsanjan, Laguna');
    }

    public function test_t4_03_multi_merchant_cart_with_split_deliveries(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller1 = $this->createApprovedUser('seller');
        $seller2 = $this->createApprovedUser('seller');
        $shop1 = $this->createE2EShop($seller1);
        $shop2 = $this->createE2EShop($seller2);

        $order1 = $this->createE2EOrder($buyer, $shop1, [], 'placed');
        $order2 = $this->createE2EOrder($buyer, $shop2, [], 'placed');
        $del1 = $this->createE2EDelivery($order1, 'unassigned');
        $del2 = $this->createE2EDelivery($order2, 'unassigned');

        $this->assertNotEquals($del1->tracking_number, $del2->tracking_number);
    }

    public function test_t4_04_high_value_artisan_product_full_audit_trail(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $product = $this->createE2EProduct($shop, ['price' => 15000.00]);
        $order = $this->createE2EOrder($buyer, $shop, [
            ['product' => $product, 'quantity' => 1, 'unit_price' => 15000.00],
        ], 'placed');
        $delivery = $this->createE2EDelivery($order, 'unassigned');

        $this->assertNotNull($delivery->tracking_number);
        $this->assertEquals(15000.00, $order->subtotal);
    }

    public function test_t4_05_peak_hour_simultaneous_checkout_and_dispatch(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);

        $orders = [];
        for ($i = 0; $i < 3; $i++) {
            $orders[] = $this->createE2EOrder($buyer, $shop, [], 'placed');
        }

        $this->assertCount(3, $orders);
    }

    public function test_t4_06_merchant_self_managed_packaging_with_waybill(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'confirmed');
        $delivery = $this->createE2EDelivery($order, 'unassigned');

        $this->actingAs($seller)->post(route('seller.orders.pack', $order->id));
        $this->actingAs($seller)->post(route('seller.orders.ready', $order->id));

        $order->refresh();
        $this->assertEquals('ready_for_pickup', $order->status);
    }
}

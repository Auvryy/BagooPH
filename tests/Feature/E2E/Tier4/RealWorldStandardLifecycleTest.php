<?php

namespace Tests\Feature\E2E\Tier4;

use App\Models\Delivery;
use App\Models\LogisticsHub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\E2E\Support\AssertsCommissionLedgers;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithPortals;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\Feature\E2E\Support\SimulatesOrderLifecycle;
use Tests\TestCase;

class RealWorldStandardLifecycleTest extends TestCase
{
    use AssertsCommissionLedgers, AssertsDeliveryCheckpoints, CreatesE2EOrders, InteractsWithPortals, InteractsWithRoles, SimulatesOrderLifecycle;
    use InteractsWithOrderActions;
    use RefreshDatabase;

    public function test_t4_01_standard_delivery_through_mother_hub_and_buyer_confirmation(): void
    {
        $order = $this->newFlowOrder();
        $delivery = $this->flowDelivery($order, 'delivered');
        $this->completeFlowOrder($order);
        $this->settleFlowOrder($order);
        $this->assertCheckpointLogged($delivery, 'arrived_at_mother_hub');
        $this->assertCheckpointLogged($delivery, 'buyer_completed');

        $this->assertCommissionSplit($order, null, (float) $order->shipping_fee);
    }

    public function test_t4_02_provincial_laguna_delivery_with_area_b_sorting(): void
    {
        $order = $this->newFlowOrder('ready_for_pickup', ['shipping_city' => 'Pagsanjan', 'shipping_postal_code' => '4008']);
        $delivery = $this->flowDelivery($order, 'arrived_at_destination_hub');
        $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $this->actingAs($this->flowHandler($hub))->postJson(route('hub.sort'), [
            'delivery_id' => $delivery->id, 'bin' => 'BIN-B1', 'barangay' => $order->destination_barangay,
        ])->assertOk();
        $this->assertSame('Pagsanjan', $hub->city_municipality);
        $this->assertSame('BIN-B1', $delivery->fresh()->destination_bin);
        $this->assertCheckpointLogged($delivery, 'sorted_to_barangay_bin');
    }

    public function test_t4_03_multi_merchant_cart_with_split_deliveries(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $shop1 = $this->createE2EShop($this->createApprovedUser('seller'));
        $shop2 = $this->createE2EShop($this->createApprovedUser('seller'));
        $product1 = $this->createE2EProduct($shop1, ['price' => 100, 'stock' => 5]);
        $product2 = $this->createE2EProduct($shop2, ['price' => 200, 'stock' => 5]);
        $orders = $this->checkoutFlowOrders($buyer, [
            ['product' => $product1, 'quantity' => 2], ['product' => $product2, 'quantity' => 1],
        ]);
        $this->assertCount(2, $orders);
        $this->assertSame([$shop1->id, $shop2->id], $orders->map(fn ($order) => $order->items->first()->shop_id)->all());
        $this->assertCount(2, $orders->pluck('delivery.tracking_number')->unique());
        $this->assertSame([3, 4], [$product1->fresh()->stock, $product2->fresh()->stock]);
        $this->assertSame(['50.00', '50.00'], $orders->pluck('shipping_fee')->all());
        $this->assertDatabaseCount('checkout_submissions', 1);
    }

    public function test_t4_04_high_value_artisan_product_full_audit_trail(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $shop = $this->createE2EShop($this->createApprovedUser('seller'));
        $product = $this->createE2EProduct($shop, ['price' => 15000, 'stock' => 10]);
        $order = $this->checkoutFlowOrder($buyer, $shop, [['product' => $product]]);
        $this->assertEquals(15000, $order->subtotal);
        $delivery = $this->flowDelivery($order, 'delivered');
        $this->completeFlowOrder($order);
        $this->assertCheckpointLogged($delivery, 'arrived_at_mother_hub');
        $this->assertCheckpointLogged($delivery, 'buyer_completed');
        $this->assertSame(9, $product->fresh()->stock);
    }

    public function test_t4_05_sequential_checkout_and_pickup_batch_preserves_stock(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $shop = $this->createE2EShop($this->createApprovedUser('seller'));
        $product = $this->createE2EProduct($shop, ['stock' => 10]);
        $orders = [];
        for ($i = 0; $i < 3; $i++) {
            $order = $this->checkoutFlowOrder($buyer, $shop, [['product' => $product->fresh()]], 'ready_for_pickup');
            $this->flowDelivery($order, 'picked_up');
            $orders[] = $order;
        }
        $this->assertCount(3, $orders);
        $this->assertSame(7, $product->fresh()->stock);
        $this->assertDatabaseCount('checkout_submissions', 3);
        $this->assertSame(3, Delivery::whereRaw('status = ?', ['picked_up'])->count());
    }

    public function test_t4_06_merchant_self_managed_packaging_with_waybill(): void
    {
        $order = $this->newFlowOrder('confirmed');
        $seller = $order->items->first()->product->shop->user;
        $delivery = $order->delivery;
        $waybill = $delivery->tracking_number;
        $this->actingAs($seller)->post(route('seller.orders.pack', $order))->assertSessionHas('success');
        $this->actingAs($seller)->post(route('seller.orders.ready', $order))->assertSessionHas('success');
        $this->assertSame('ready_for_pickup', $order->fresh()->status);
        $this->assertSame($waybill, $delivery->fresh()->tracking_number);
        $this->assertCheckpointSequence($delivery, ['seller_pack', 'ready_for_pickup']);
    }
}

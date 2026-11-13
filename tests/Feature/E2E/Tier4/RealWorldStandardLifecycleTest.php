<?php

namespace Tests\Feature\E2E\Tier4;

use App\Models\Delivery;
use App\Models\LogisticsHub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
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

    private int $contractVersion = 2;

    protected function flowContractVersion(): int
    {
        return $this->contractVersion;
    }

    public static function completionContracts(): array
    {
        return ['historical buyer confirmation' => [1], 'verified delivery completion' => [2]];
    }

    #[DataProvider('completionContracts')]
    public function test_t4_01_standard_delivery_through_mother_hub_and_contract_owned_completion(int $contractVersion): void
    {
        $this->contractVersion = $contractVersion;
        $order = $this->newFlowOrder();
        $item = $order->items->first();
        $review = ['order_item_id' => $item->id, 'rating' => 5, 'comment' => 'The original purchased item arrived safely and matches its listing.'];
        $this->actingAs($order->buyer)->postJson(route('buyer.reviews.store'), $review)->assertUnprocessable();
        $delivery = $this->flowDelivery($order, 'delivered');
        $this->assertSame($contractVersion, $order->logistics_flow_version);
        $this->assertSame($contractVersion === 1 ? 'delivered' : 'completed', $order->fresh()->status);
        $this->completeFlowOrder($order);
        $this->settleFlowOrder($order);
        $this->assertCheckpointLogged($delivery, 'arrived_at_mother_hub');
        $this->assertSame(1, $delivery->checkpoints()->where('checkpoint_type', $contractVersion === 1 ? 'buyer_completed' : 'delivery_completed')
            ->where('scanned_by_id', $contractVersion === 1 ? $order->buyer_id : $delivery->assigned_rider_id)->count());
        $this->assertSame(0, $delivery->checkpoints()->where('checkpoint_type', $contractVersion === 1 ? 'delivery_completed' : 'buyer_completed')->count());

        $this->assertCommissionSplit($order, null, (float) $order->shipping_fee);
        $this->actingAs($order->buyer)->post(route('buyer.reviews.store'), $review)->assertSessionHas('success');
        $this->post(route('buyer.reviews.store'), $review)->assertSessionHas('success');
        $this->assertDatabaseCount('reviews', 1);
        $this->assertDatabaseHas('reviews', ['order_item_id' => $item->id, 'order_id' => $order->id,
            'buyer_id' => $order->buyer_id, 'product_id' => $item->product_id, 'rating' => 5]);
        $this->assertSame('5.00', (string) $item->product->fresh()->rating);
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
        $this->assertSame([2, 2], $orders->pluck('logistics_flow_version')->all());
        $this->assertSame(5000, $this->flowCompany->base_shipping_cents);
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
        $this->assertCheckpointLogged($delivery, 'delivery_completed');
        $this->assertSame(0, $delivery->checkpoints()->where('checkpoint_type', 'buyer_completed')->count());
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

<?php

namespace Tests\Feature\E2E\Tier2;

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

class B07_to_B11_LifecyclePlacedToPickupBoundaryTest extends TestCase
{
    use AssertsCommissionLedgers, AssertsDeliveryCheckpoints, CreatesE2EOrders, InteractsWithPortals, InteractsWithRoles, SimulatesOrderLifecycle;
    use InteractsWithOrderActions;
    use RefreshDatabase;

    // ==========================================
    // Boundary 7: Checkout Stock Exhaustion & Validation
    // ==========================================

    public function test_t2_b07_01_zero_stock_purchase_rejection(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $product = $this->createE2EProduct($shop, ['stock' => 0]);
        $payload = $this->flowCheckoutPayload($buyer, [['product' => $product, 'quantity' => 1]]);
        $this->actingAs($buyer)->post(route('checkout.store'), $payload)->assertSessionHas('error');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('deliveries', 0);
        $this->assertDatabaseCount('checkout_submissions', 0);
        $this->assertSame(0, $product->fresh()->stock);
        $this->assertDatabaseCount('cart_items', 1);
    }

    public function test_t2_b07_02_negative_quantity_boundary(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $product = $this->createE2EProduct($shop);
        $this->actingAs($buyer)->post(route('cart.store'), ['product_id' => $product->id, 'quantity' => -5])
            ->assertSessionHasErrors('quantity');
        $this->assertDatabaseCount('cart_items', 0);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_t2_b07_03_empty_cart_checkout_attempt(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $payload = $this->flowCheckoutPayload($buyer, []);
        $this->actingAs($buyer)->post(route('checkout.store'), $payload)->assertSessionHasErrors('item_ids');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('checkout_submissions', 0);
    }

    public function test_t2_b07_04_missing_shipping_address(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $product = $this->createE2EProduct($shop);
        $payload = $this->flowCheckoutPayload($buyer, [['product' => $product]]);
        $payload['shipping_address'] = '';
        $this->actingAs($buyer)->post(route('checkout.store'), $payload)->assertSessionHasErrors('shipping_address');
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(50, $product->fresh()->stock);
        $this->assertDatabaseCount('cart_items', 1);
    }

    public function test_t2_b07_05_exceeded_available_stock(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $product = $this->createE2EProduct($shop, ['stock' => 3]);
        $payload = $this->flowCheckoutPayload($buyer, [['product' => $product, 'quantity' => 10]]);
        $this->actingAs($buyer)->post(route('checkout.store'), $payload)->assertSessionHas('error');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('deliveries', 0);
        $this->assertDatabaseCount('checkout_submissions', 0);
        $this->assertSame(3, $product->fresh()->stock);
        $this->assertDatabaseCount('cart_items', 1);
    }

    // ==========================================
    // Boundary 8: Seller Confirmation Authorization & Conflict
    // ==========================================

    public function test_t2_b08_01_idor_confirmation_attempt_by_different_seller(): void
    {
        $seller1 = $this->createApprovedUser('seller');
        $shop1 = $this->createE2EShop($seller1);
        $buyer = $this->createApprovedUser('buyer');
        $order = $this->checkoutFlowOrder($buyer, $shop1, [], 'placed');

        $seller2 = $this->createApprovedUser('seller');
        $this->createE2EShop($seller2);

        $response = $this->actingAs($seller2)->post(route('seller.orders.pack', $order->id));
        $this->assertEquals(403, $response->status());
    }

    public function test_t2_b08_02_confirming_already_confirmed_order(): void
    {
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $buyer = $this->createApprovedUser('buyer');
        $order = $this->checkoutFlowOrder($buyer, $shop, [], 'confirmed');
        $this->flowDelivery($order, 'unassigned');

        $response = $this->actingAs($seller)->post(route('seller.orders.accept', $order->id));
        $response->assertSessionHas('error');
        $this->assertSame('confirmed', $order->fresh()->status);
    }

    public function test_t2_b08_03_confirming_cancelled_order_barred(): void
    {
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $buyer = $this->createApprovedUser('buyer');
        $order = $this->checkoutFlowOrder($buyer, $shop);
        $this->actingAs($seller)->post(route('seller.orders.cancel', $order), ['reason' => 'Stock unavailable'])->assertSessionHas('success');

        $before = $order->fresh()->getRawOriginal();
        $this->actingAs($seller)->post(route('seller.orders.accept', $order))->assertSessionHas('error');
        $this->assertSame($before, $order->fresh()->getRawOriginal());
    }

    public function test_t2_b08_04_non_seller_confirmation_attempt(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->checkoutFlowOrder($buyer, $shop, [], 'placed');

        $response = $this->actingAs($buyer)->post(route('seller.orders.pack', $order->id));
        $response->assertForbidden();
    }

    public function test_t2_b08_05_accept_and_pack_retry_preserves_evidence(): void
    {
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $buyer = $this->createApprovedUser('buyer');
        $order = $this->checkoutFlowOrder($buyer, $shop, [], 'placed');
        $this->flowDelivery($order, 'unassigned');

        $this->actingAs($seller)->post(route('seller.orders.acceptAndPack', $order))->assertSessionHas('success');
        $before = $order->delivery->checkpoints()->pluck('id')->all();
        $this->actingAs($seller)->post(route('seller.orders.acceptAndPack', $order))->assertSessionHas('error');
        $this->assertSame($before, $order->delivery->checkpoints()->pluck('id')->all());
        $order->refresh();
        $this->assertEquals('preparing', $order->status);
        $this->assertDatabaseHas('delivery_checkpoints', [
            'delivery_id' => $order->delivery->id,
            'checkpoint_type' => 'seller_pack',
            'scanned_by_id' => $seller->id,
        ]);
    }

    // ==========================================
    // Boundary 9: Packaging Phase Tampering
    // ==========================================

    public function test_t2_b09_01_pack_order_before_confirmation(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->checkoutFlowOrder($buyer, $shop, [], 'placed');
        $this->flowDelivery($order, 'unassigned');

        $response = $this->actingAs($seller)->post(route('seller.orders.pack', $order->id));
        $response->assertRedirect();
        $response->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'currently placed'));
        $this->assertEquals('placed', $order->fresh()->status);
    }

    public function test_t2_b09_02_double_packing_invocation_idempotency(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->checkoutFlowOrder($buyer, $shop, [], 'confirmed');
        $this->flowDelivery($order, 'unassigned');

        $this->actingAs($seller)->post(route('seller.orders.pack', $order))->assertSessionHas('success');
        $before = [$order->fresh()->getRawOriginal(), $order->delivery->checkpoints()->pluck('id')->all()];
        $this->actingAs($seller)->post(route('seller.orders.pack', $order))->assertSessionHas('error');
        $this->assertSame($before, [$order->fresh()->getRawOriginal(), $order->delivery->checkpoints()->pluck('id')->all()]);

        $order->refresh();
        $this->assertSame('preparing', $order->status);
        $this->assertSame(1, $order->delivery->checkpoints()->where('checkpoint_type', 'seller_pack')->count());
    }

    public function test_t2_b09_03_packing_cancelled_order_barred(): void
    {
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $buyer = $this->createApprovedUser('buyer');
        $order = $this->checkoutFlowOrder($buyer, $shop);
        $this->actingAs($seller)->post(route('seller.orders.cancel', $order), ['reason' => 'Stock unavailable'])->assertSessionHas('success');

        $before = $order->fresh()->getRawOriginal();
        $this->actingAs($seller)->post(route('seller.orders.pack', $order))->assertSessionHas('error');
        $this->assertSame($before, $order->fresh()->getRawOriginal());
    }

    public function test_t2_b09_04_idor_packing_attempt_by_non_owner(): void
    {
        $sellerA = $this->createApprovedUser('seller');
        $shopA = $this->createE2EShop($sellerA);
        $buyer = $this->createApprovedUser('buyer');
        $order = $this->checkoutFlowOrder($buyer, $shopA, [], 'confirmed');

        $sellerB = $this->createApprovedUser('seller');
        $shopB = $this->createE2EShop($sellerB);

        $response = $this->actingAs($sellerB)->withSession(['active_seller_shop_id' => $shopB->id])->post(route('seller.orders.pack', $order->id));
        $this->assertEquals(403, $response->status());
    }

    public function test_t2_b09_05_item_quantity_modification_during_packaging(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->checkoutFlowOrder($buyer, $shop, [], 'preparing');

        $before = $order->items()->first()->getRawOriginal();
        $this->actingAs($seller)->post(route('seller.orders.ready', $order), ['quantity' => 99, 'unit_price' => 1])->assertSessionHas('success');
        $this->assertSame($before, $order->items()->first()->getRawOriginal());
    }

    // ==========================================
    // Boundary 10: Ready for Pickup Staging Inconsistencies
    // ==========================================

    public function test_t2_b10_01_ready_without_packing_barred(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'preparing');
        $this->createE2EDelivery($order, 'unassigned');

        $response = $this->actingAs($seller)->post(route('seller.orders.ready', $order->id));
        $response->assertRedirect()->assertSessionHas('error');
        $this->assertEquals('preparing', $order->fresh()->status);
    }

    public function test_t2_b10_02_double_ready_submission_idempotency(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->checkoutFlowOrder($buyer, $shop, [], 'confirmed');
        $this->actingAs($seller)->post(route('seller.orders.pack', $order))->assertSessionHas('success');
        $this->actingAs($seller)->post(route('seller.orders.ready', $order))->assertSessionHas('success');
        $before = [$order->fresh()->getRawOriginal(), $order->delivery->checkpoints()->pluck('id')->all()];
        $this->actingAs($seller)->post(route('seller.orders.ready', $order))->assertSessionHas('error');
        $this->assertSame($before, [$order->fresh()->getRawOriginal(), $order->delivery->checkpoints()->pluck('id')->all()]);

        $this->assertEquals('ready_for_pickup', $order->fresh()->status);
    }

    public function test_t2_b10_03_non_seller_marking_ready_barred(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->checkoutFlowOrder($buyer, $shop, [], 'preparing');

        $response = $this->actingAs($buyer)->post(route('seller.orders.ready', $order->id));
        $response->assertForbidden();
    }

    public function test_t2_b10_04_premature_pickup_scan_before_ready(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->checkoutFlowOrder($buyer, $shop, [], 'placed');
        $delivery = $this->flowDelivery($order, 'unassigned');

        $courier = $this->createApprovedUser('courier');
        $this->flowRider(LogisticsHub::findOrFail($delivery->origin_bayan_hub_id), $courier);
        $response = $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'picked_up',
        ]);

        $delivery->refresh();
        $response->assertSessionHas('error');
        $this->assertSame('unassigned', $delivery->status);
        $this->assertNull($delivery->courier_id);
        $this->assertSame(0, $delivery->checkpoints()->where('checkpoint_type', 'picked_up')->count());
    }

    public function test_t2_b10_05_cancelled_order_marked_ready_barred(): void
    {
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $buyer = $this->createApprovedUser('buyer');
        $order = $this->checkoutFlowOrder($buyer, $shop);
        $this->actingAs($seller)->post(route('seller.orders.cancel', $order), ['reason' => 'Stock unavailable'])->assertSessionHas('success');

        $before = $order->fresh()->getRawOriginal();
        $this->actingAs($seller)->post(route('seller.orders.ready', $order))->assertSessionHas('error');
        $this->assertSame($before, $order->fresh()->getRawOriginal());
    }

    // ==========================================
    // Boundary 11: Courier Pickup Verification & Collision
    // ==========================================

    public function test_t2_b11_01_pickup_claim_without_approved_kyc_barred(): void
    {
        $pendingCourier = $this->createPendingUser('courier');
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->checkoutFlowOrder($buyer, $shop, [], 'ready_for_pickup');
        $delivery = $this->flowDelivery($order, 'unassigned');

        $response = $this->actingAs($pendingCourier)->post(route('courier.claim', $delivery->id));
        $response->assertRedirect('/pending-approval');
        $this->assertNull($delivery->fresh()->courier_id);
        $this->assertSame('unassigned', $delivery->fresh()->status);
    }

    public function test_t2_b11_02_pickup_claim_on_non_ready_parcel_barred(): void
    {
        $courier = $this->createApprovedUser('courier');
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->checkoutFlowOrder($buyer, $shop, [], 'ready_for_pickup');
        $delivery = $this->flowDelivery($order, 'delivered');
        $this->flowRider(LogisticsHub::findOrFail($delivery->origin_bayan_hub_id), $courier);

        $response = $this->actingAs($courier)->post(route('courier.claim', $delivery->id));
        $response->assertSessionHas('error');
        $this->assertSame('delivered', $delivery->fresh()->status);
    }

    public function test_t2_b11_03_sequential_competing_claims_preserve_first_owner(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->checkoutFlowOrder($buyer, $shop, [], 'ready_for_pickup');
        $delivery = $this->flowDelivery($order, 'unassigned');

        $courierA = $this->createApprovedUser('courier');
        $courierB = $this->createApprovedUser('courier');

        $hub = LogisticsHub::findOrFail($delivery->origin_bayan_hub_id);
        $this->flowRider($hub, $courierA);
        $this->flowRider($hub, $courierB);
        $this->actingAs($courierA)->post(route('courier.claim', $delivery))->assertSessionHas('success');
        $before = $delivery->fresh()->getRawOriginal();
        $this->actingAs($courierB)->post(route('courier.claim', $delivery))->assertSessionHas('error');
        $this->assertSame($before, $delivery->fresh()->getRawOriginal());
        $this->assertSame(1, $delivery->checkpoints()->where('checkpoint_type', 'assigned_pickup')->count());

        $delivery->refresh();
        $this->assertEquals($courierA->id, $delivery->courier_id);
    }

    public function test_t2_b11_04_pickup_confirmation_without_claim_barred(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->checkoutFlowOrder($buyer, $shop, [], 'ready_for_pickup');
        $delivery = $this->flowDelivery($order, 'unassigned');

        $courier = $this->createApprovedUser('courier');
        $this->flowRider(LogisticsHub::findOrFail($delivery->origin_bayan_hub_id), $courier);
        $response = $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'picked_up',
        ]);

        $delivery->refresh();
        $response->assertSessionHas('error');
        $this->assertSame('unassigned', $delivery->status);
        $this->assertNull($delivery->courier_id);
        $this->assertSame(0, $delivery->checkpoints()->where('checkpoint_type', 'picked_up')->count());
    }

    public function test_t2_b11_05_non_courier_pickup_attempt_barred(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->checkoutFlowOrder($buyer, $shop, [], 'ready_for_pickup');
        $delivery = $this->flowDelivery($order, 'unassigned');

        $response = $this->actingAs($buyer)->post(route('courier.claim', $delivery->id));
        $response->assertForbidden();
    }
}

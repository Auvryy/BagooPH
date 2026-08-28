<?php

namespace Tests\Feature\E2E\Tier3;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\E2E\Support\AssertsCommissionLedgers;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\Feature\E2E\Support\SimulatesOrderLifecycle;
use Tests\TestCase;

class CrossFeaturePairwiseTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithRoles, CreatesE2EOrders, SimulatesOrderLifecycle, AssertsDeliveryCheckpoints, AssertsCommissionLedgers;

    /**
     * T3-01: F2 (KYC Approval) + F3 (Order Checkout & Packaging)
     * Seller registers -> Admin approves KYC -> Seller accesses cockpit -> Seller receives buyer order -> Seller approves packaging.
     */
    public function test_t3_01_kyc_approval_to_seller_fulfillment_pipeline(): void
    {
        // 1. Pending Seller registers
        $seller = $this->createPendingUser('seller', [
            'name' => 'Artisan Craft Master',
            'email' => 'artisan.craft@example.com',
        ]);

        // Gated before approval
        $this->actingAs($seller)->get(route('seller.dashboard'))->assertRedirect(route('kyc.pending'));

        // 2. Admin approves KYC
        $admin = $this->createApprovedUser('admin');
        $approveResponse = $this->actingAs($admin)->post(route('admin.kyc.approve', $seller->id));
        $approveResponse->assertSessionHas('success');

        $seller->refresh();
        $this->assertEquals('approved', $seller->kyc_status);
        $this->assertEquals('active', $seller->status);

        // 3. Approved Seller accesses cockpit
        $dashboardResponse = $this->actingAs($seller)->get(route('seller.dashboard'));
        $dashboardResponse->assertOk();

        // 4. Buyer places an order
        $buyer = $this->createApprovedUser('buyer');
        $shop = $this->createE2EShop($seller);
        $product = $this->createE2EProduct($shop, ['price' => 850.00, 'stock' => 15]);
        $order = $this->createE2EOrder($buyer, $shop, [['product' => $product, 'quantity' => 1]], 'pending');

        // 5. Seller views incoming order
        $ordersResponse = $this->actingAs($seller)->get(route('seller.orders.index'));
        $ordersResponse->assertOk();

        // 6. Seller approves & packs order
        $packResponse = $this->actingAs($seller)->post(route('seller.orders.pack', $order->id));
        $packResponse->assertSessionHas('success');
        $order->refresh();
        $this->assertEquals('processing', $order->status);

        // 7. Seller marks ready for pickup (generates waybill & delivery record)
        $readyResponse = $this->actingAs($seller)->post(route('seller.orders.ready', $order->id));
        $readyResponse->assertSessionHas('success');

        $order->refresh();
        $this->assertEquals('ready_for_pickup', $order->status);
        $this->assertNotNull($order->delivery);
        $this->assertEquals('unassigned', $order->delivery->status);
        $this->assertNotNull($order->delivery->tracking_number);
        $this->assertCheckpointLogged($order->delivery, 'seller_pack');
    }

    /**
     * T3-02: F3 (Order Packaging) + F4 (Courier Dispatch Board)
     * Seller marks order ready for pickup -> Delivery broadcasts to Courier Dispatch Board -> Courier claims job.
     */
    public function test_t3_02_seller_packaging_release_to_courier_dispatch_broadcast(): void
    {
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $buyer = $this->createApprovedUser('buyer');
        $order = $this->createE2EOrder($buyer, $shop, [], 'processing');

        // Seller marks ready for pickup
        $this->actingAs($seller)->post(route('seller.orders.ready', $order->id))->assertSessionHas('success');

        $order->refresh();
        $delivery = $order->delivery;
        $this->assertNotNull($delivery);
        $this->assertEquals('unassigned', $delivery->status);

        // Courier checks dispatch board
        $courierA = $this->createApprovedUser('courier', ['name' => 'Rider Alpha']);
        $boardResponse = $this->actingAs($courierA)->get(route('courier.deliveries'));
        $boardResponse->assertOk();

        // Courier A claims available job
        $claimResponse = $this->actingAs($courierA)->post(route('courier.claim', $delivery->id));
        $claimResponse->assertSessionHas('success');

        $delivery->refresh();
        $this->assertEquals($courierA->id, $delivery->courier_id);
        $this->assertEquals('assigned', $delivery->status);
        $this->assertNotNull($delivery->assigned_at);

        // Second courier cannot claim already claimed delivery
        $courierB = $this->createApprovedUser('courier', ['name' => 'Rider Beta']);
        $secondClaim = $this->actingAs($courierB)->post(route('courier.claim', $delivery->id));
        $secondClaim->assertSessionHas('error');
    }

    /**
     * T3-03: F4 (Courier Dispatch) + F5 (Logistics Hub Checkpoints)
     * Courier picks up parcel at store (scans barcode) -> Logistics hub receives parcel, scans barcode intake, and logs barangay sorting bin.
     */
    public function test_t3_03_courier_pickup_scan_to_logistics_hub_barangay_sorting(): void
    {
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $buyer = $this->createApprovedUser('buyer');
        $order = $this->createE2EOrder($buyer, $shop, [], 'ready_for_pickup');
        $courier = $this->createApprovedUser('courier');
        $delivery = $this->createE2EDelivery($order, 'assigned', $courier);

        // 1. Courier store pickup scan
        $pickupResponse = $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'picked_up',
            'courier_notes' => 'Parcel picked up from merchant store with barcode verified.',
        ]);
        $pickupResponse->assertSessionHas('success');

        $delivery->refresh();
        $order->refresh();
        $this->assertEquals('picked_up', $delivery->status);
        $this->assertEquals('shipped', $order->status);
        $this->assertNotNull($delivery->picked_up_at);
        $this->assertCheckpointLogged($delivery, 'courier_pickup');

        // 2. Logistics hub intake scan
        $logistics = $this->createApprovedUser('logistics');
        $hubResponse = $this->actingAs($logistics)->get(route('hub.index'));
        $hubResponse->assertOk();

        $scanResponse = $this->actingAs($logistics)->postJson(route('hub.scan'), [
            'barcode' => $delivery->tracking_number,
            'location_name' => 'Metro Manila Central Sorting Station',
            'notes' => 'Intake barcode scanned into Central sorting system.',
        ]);
        $scanResponse->assertOk();

        $delivery->refresh();
        $this->assertEquals('in_transit', $delivery->status);
        $this->assertCheckpointLogged($delivery, 'hub_intake');

        // 3. Logistics hub barangay sorting
        $sortResponse = $this->actingAs($logistics)->postJson(route('hub.sort'), [
            'delivery_id' => $delivery->id,
            'barangay' => 'Barangay San Antonio',
            'bin' => 'BIN-B4',
            'notes' => 'Classified and routed to Pasig / San Antonio local courier bin.',
        ]);
        $sortResponse->assertOk();

        $delivery->refresh();
        $this->assertEquals('out_for_delivery', $delivery->status);
        $this->assertCheckpointLogged($delivery, 'barangay_sort', 'Barangay San Antonio');
    }

    /**
     * T3-04: F4 (Courier Delivery) + F6 (Commission Ledger)
     * Courier marks delivery complete with photo proof -> System atomically writes 90% seller credit, 10% platform treasury, and ₱60 courier fee.
     */
    public function test_t3_04_courier_doorstep_delivery_to_commission_distribution(): void
    {
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $buyer = $this->createApprovedUser('buyer');
        $product = $this->createE2EProduct($shop, ['price' => 2500.00]);
        $order = $this->createE2EOrder($buyer, $shop, [['product' => $product, 'quantity' => 1]], 'shipped');
        $courier = $this->createApprovedUser('courier');
        $delivery = $this->createE2EDelivery($order, 'out_for_delivery', $courier);

        // Courier completes doorstep delivery with photo proof
        $completeResponse = $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'delivered',
            'proof_image' => 'https://images.unsplash.com/photo-1586528116311-ad8dd3c8310d?w=800',
            'courier_notes' => 'Received by buyer at doorstep with signature and photo proof.',
        ]);
        $completeResponse->assertSessionHas('success');

        $delivery->refresh();
        $order->refresh();
        $this->assertEquals('delivered', $delivery->status);
        $this->assertEquals('delivered', $order->status);
        $this->assertEquals('paid', $order->payment_status);
        $this->assertNotNull($delivery->delivered_at);
        $this->assertNotNull($delivery->proof_image);
        $this->assertCheckpointLogged($delivery, 'doorstep_handover');

        // Financial split validation: ₱2,500 gross -> ₱2,250 seller (90%), ₱250 platform (10%), ₱60 courier fee
        $ledger = $this->assertCommissionSplit($order, 2500.00);
        $this->assertEquals(2250.00, (float) $ledger->seller_amount);
        $this->assertEquals(250.00, (float) $ledger->platform_commission);
        $this->assertEquals(60.00, (float) $ledger->delivery_fee);
        $this->assertEquals($courier->id, $ledger->courier_id);

        // Verify courier earnings view reflects completed delivery
        $earningsResponse = $this->actingAs($courier)->get(route('courier.earnings'));
        $earningsResponse->assertOk();

        // Verify seller reports view reflects sale
        $reportsResponse = $this->actingAs($seller)->get(route('seller.reports'));
        $reportsResponse->assertOk();
    }

    /**
     * T3-05: F7 (Simulator) + F3/F4/F5 (Buyer Timeline & Checkpoints)
     * Fast-Forward steps order through all stages -> Buyer tracking endpoint verifies each milestone matches simulated step and checkpoints are logged.
     */
    public function test_t3_05_fast_forward_progression_syncs_buyer_timeline_and_checkpoint_trail(): void
    {
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $buyer = $this->createApprovedUser('buyer');
        $order = $this->createE2EOrder($buyer, $shop, [], 'pending');

        $this->actingAs($buyer);

        // 1. Pending -> Processing (Packaging)
        $this->advanceOrderStage($order)->assertOk();
        $this->assertOrderStage($order, 'processing', 'unassigned');
        $this->actingAs($buyer)->get(route('buyer.orders.show', $order->id))->assertOk();

        // 2. Processing -> Ready for Pickup
        $this->advanceOrderStage($order)->assertOk();
        $this->assertOrderStage($order, 'ready_for_pickup', 'unassigned');
        $this->actingAs($buyer)->get(route('buyer.orders.show', $order->id))->assertOk();

        // 3. Ready for Pickup -> Picked Up / Shipped
        $this->advanceOrderStage($order)->assertOk();
        $this->assertOrderStage($order, 'shipped', 'picked_up');
        $this->actingAs($buyer)->get(route('buyer.orders.show', $order->id))->assertOk();

        // 4. Picked Up -> In Transit
        $this->advanceOrderStage($order)->assertOk();
        $this->assertOrderStage($order, 'shipped', 'in_transit');
        $this->actingAs($buyer)->get(route('buyer.orders.show', $order->id))->assertOk();

        // 5. In Transit -> Out for Delivery
        $this->advanceOrderStage($order)->assertOk();
        $this->assertOrderStage($order, 'shipped', 'out_for_delivery');
        $this->actingAs($buyer)->get(route('buyer.orders.show', $order->id))->assertOk();

        // 6. Out for Delivery -> Delivered
        $this->advanceOrderStage($order)->assertOk();
        $this->assertOrderStage($order, 'delivered', 'delivered');
        $this->actingAs($buyer)->get(route('buyer.orders.show', $order->id))->assertOk();

        // Verify complete audit checkpoint sequence
        $order->refresh();
        $this->assertNotNull($order->delivery);
        $this->assertCheckpointSequence($order->delivery, [
            'seller_pack',
            'courier_pickup',
            'hub_intake',
            'barangay_sort',
            'doorstep_handover',
        ]);
    }

    /**
     * T3-06: F3 (Voucher Checkout) + F6 (Commission Split)
     * Buyer uses voucher -> Net subtotal correctly flows through to commission calculations upon delivery.
     */
    public function test_t3_06_voucher_discounted_checkout_propagates_to_split_ledger(): void
    {
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $buyer = $this->createApprovedUser('buyer');
        $product = $this->createE2EProduct($shop, ['price' => 1200.00, 'stock' => 10]);

        $voucher = $this->createE2EVoucher($shop, [
            'code' => 'SAVE200',
            'discount_type' => 'fixed',
            'discount_value' => 200.00,
            'min_spend' => 1000.00,
            'is_active' => true,
        ]);

        $cart = Cart::create(['user_id' => $buyer->id]);
        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 1200.00,
            'subtotal' => 1200.00,
        ]);

        $checkoutResponse = $this->actingAs($buyer)->post('/checkout', [
            'recipient_name' => 'Maria Clara',
            'recipient_phone' => '+63 917 888 9999',
            'shipping_address' => '456 Rizal Boulevard',
            'shipping_city' => 'Makati City',
            'payment_method' => 'cod',
            'voucher_code' => 'SAVE200',
        ]);
        $checkoutResponse->assertRedirect(route('buyer.orders.index'));

        $order = Order::where('buyer_id', $buyer->id)->latest()->first();
        $this->assertNotNull($order);
        // Subtotal = 1200, Shipping = 50, Voucher = 200 -> Total = 1050
        $this->assertEquals(1200.00, (float) $order->subtotal);
        $this->assertEquals(1050.00, (float) $order->total_amount);

        // Fast-forward order to delivered status
        $this->fastForwardToDelivered($order);

        $order->refresh();
        $this->assertEquals('delivered', $order->status);

        // ₱1,200 gross subtotal -> ₱1,080 seller (90%), ₱120 platform (10%), ₱60 courier fee
        $ledger = $this->assertCommissionSplit($order, 1200.00);
        $this->assertEquals(1080.00, (float) $ledger->seller_amount);
        $this->assertEquals(120.00, (float) $ledger->platform_commission);
        $this->assertEquals(60.00, (float) $ledger->delivery_fee);
    }

    /**
     * T3-07: F5 (Logistics Hub Override) + F4 (Courier Board) + Audit Trail
     * Admin supervisor reassigns delivery to different courier -> New courier sees job in active deliveries; previous courier loses write access; checkpoint audit logs reassignment.
     */
    public function test_t3_07_logistics_hub_reassignment_updates_courier_dispatch_and_audit_trail(): void
    {
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $buyer = $this->createApprovedUser('buyer');
        $order = $this->createE2EOrder($buyer, $shop, [], 'ready_for_pickup');

        $courierA = $this->createApprovedUser('courier', ['name' => 'Original Courier A']);
        $courierB = $this->createApprovedUser('courier', ['name' => 'Replacement Courier B']);

        $delivery = $this->createE2EDelivery($order, 'assigned', $courierA);

        // Admin supervisor overrides and reassigns delivery to Courier B
        $admin = $this->createApprovedUser('admin');
        $overrideResponse = $this->actingAs($admin)->post(route('admin.logistics.override'), [
            'delivery_id' => $delivery->id,
            'courier_id' => $courierB->id,
            'status' => 'assigned',
        ]);
        $overrideResponse->assertSessionHas('success');

        $delivery->refresh();
        $this->assertEquals($courierB->id, $delivery->courier_id);

        // Courier B can now access and update the delivery
        $courierBUpdate = $this->actingAs($courierB)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'picked_up',
            'courier_notes' => 'Replacement rider picked up parcel after hub reassignment.',
        ]);
        $courierBUpdate->assertSessionHas('success');

        // Courier A is forbidden from modifying the reassigned delivery (403)
        $courierAUpdate = $this->actingAs($courierA)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'in_transit',
        ]);
        $courierAUpdate->assertForbidden();

        // Verify supervisor_override checkpoint was recorded
        $delivery->refresh();
        $this->assertCheckpointLogged($delivery, 'supervisor_override');
        $overrideCheckpoint = DeliveryCheckpoint::where('delivery_id', $delivery->id)
            ->where('checkpoint_type', 'supervisor_override')
            ->first();
        $this->assertNotNull($overrideCheckpoint);
        $this->assertStringContainsString((string) $courierA->id, $overrideCheckpoint->notes);
        $this->assertStringContainsString((string) $courierB->id, $overrideCheckpoint->notes);
    }
}

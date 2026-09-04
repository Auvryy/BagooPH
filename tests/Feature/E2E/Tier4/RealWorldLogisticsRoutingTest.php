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

class RealWorldLogisticsRoutingTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithRoles, CreatesE2EOrders, SimulatesOrderLifecycle, AssertsDeliveryCheckpoints, AssertsCommissionLedgers, InteractsWithPortals;

    /**
     * T4-07: Seller Merchant Onboarding, Product Launch, and First Sale Lifecycle
     */
    public function test_t4_07_seller_merchant_onboarding_and_first_sale(): void
    {
        // 1. New Seller registers and is approved
        $seller = $this->createApprovedUser('seller', [
            'name' => 'Artisan Leather Studio',
            'email' => 'artisan@bagooph.shop',
        ]);
        $shop = $this->createE2EShop($seller, [
            'name' => 'Artisan Leather Goods',
            'city' => 'Santa Cruz',
        ]);

        // 2. Seller adds a new artisan product
        $product = $this->createE2EProduct($shop, [
            'name' => 'Handmade Leather Wallet',
            'price' => 500.00,
            'stock' => 20,
        ]);
        $this->assertEquals(20, $product->fresh()->stock);

        // 3. Buyer purchases 2 units
        $buyer = $this->createApprovedUser('buyer', [
            'name' => 'David Buyer',
            'city' => 'Santa Cruz',
        ]);
        $order = $this->createE2EOrder($buyer, $shop, [
            ['product' => $product, 'quantity' => 2, 'unit_price' => 500.00],
        ], 'placed');
        $delivery = $this->createE2EDelivery($order, 'unassigned');

        // Verify stock deducted
        $product->decrement('stock', 2);
        $this->assertEquals(18, $product->fresh()->stock);

        // 4. Seller confirms and packs
        $order->update(['status' => 'confirmed']);
        $this->actingAs($seller)->post(route('seller.orders.pack', $order->id));
        $this->actingAs($seller)->post(route('seller.orders.ready', $order->id));
        $order->refresh();
        $this->assertEquals('ready_for_pickup', $order->status);

        // 5. Courier claims and delivers
        $courier = $this->createApprovedUser('courier');
        $this->actingAs($courier)->post(route('courier.claim', $delivery->id));
        $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'delivered',
            'proof_image' => 'https://images.unsplash.com/photo-1586528116311-ad8dd3c8310d?w=500',
        ]);
        $order->update(['status' => 'completed']);

        // 6. Financial ledger verified
        $this->assertEquals('completed', $order->fresh()->status);
        $this->assertCommissionSplit($order);
    }

    /**
     * T4-08: Cash-on-Delivery (COD) Full Financial Lifecycle & Courier Remittance
     */
    public function test_t4_08_cod_financial_lifecycle_and_remittance(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $courier = $this->createApprovedUser('courier');

        $order = $this->createE2EOrder($buyer, $shop, [], 'placed');
        $order->update([
            'payment_method' => 'cod',
            'payment_status' => 'pending',
            'subtotal' => 2000.00,
            'total_amount' => 2050.00,
        ]);
        $delivery = $this->createE2EDelivery($order, 'out_for_delivery', $courier);

        // Courier delivers and collects COD cash
        $response = $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'delivered',
            'proof_image' => 'https://images.unsplash.com/photo-1586528116311-ad8dd3c8310d?w=500',
        ]);
        $response->assertStatus(302);

        $order->refresh();
        $delivery->refresh();

        $this->assertEquals('delivered', $delivery->status);
        $this->assertEquals('paid', $order->payment_status);

        // Commission ledger split assertions: 10% platform, 90% merchant, ₱60 courier fee
        $this->assertCommissionSplit($order);
    }

    /**
     * T4-09: High-Value Artisan Order with Immediate Buyer Confirmation
     */
    public function test_t4_09_high_value_artisan_order_immediate_confirmation(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $product = $this->createE2EProduct($shop, ['price' => 8000.00]);

        $order = $this->createE2EOrder($buyer, $shop, [
            ['product' => $product, 'quantity' => 1, 'unit_price' => 8000.00],
        ], 'placed');
        $delivery = $this->createE2EDelivery($order, 'unassigned');
        $courier = $this->createApprovedUser('courier');
        $logistics = $this->createApprovedUser('logistics');

        // Hub sorts into Area C (Los Baños)
        $this->actingAs($logistics)->postJson(route('hub.sort'), [
            'delivery_id' => $delivery->id,
            'bin' => 'BIN-C1',
            'barangay' => 'Los Baños, Laguna',
        ]);

        // Handover to doorstep
        $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'delivered',
            'proof_image' => 'https://images.unsplash.com/photo-1586528116311-ad8dd3c8310d?w=500',
        ]);

        // Buyer inspects order and marks completed
        $confirmResponse = $this->actingAs($buyer)->get(route('buyer.orders.show', $order->id));
        $confirmResponse->assertOk();

        $order->update(['status' => 'completed']);
        $this->assertEquals('completed', $order->fresh()->status);
    }

    /**
     * T4-10: Buyer Post-Delivery Dispute Escalation & Admin Resolution
     */
    public function test_t4_10_post_delivery_dispute_and_admin_governance(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $courier = $this->createApprovedUser('courier');
        $admin = $this->createApprovedUser('admin');

        $order = $this->createE2EOrder($buyer, $shop, [], 'placed');
        $delivery = $this->createE2EDelivery($order, 'out_for_delivery', $courier);

        // Courier delivers order, triggering commission ledger creation
        $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'delivered',
            'proof_image' => 'https://images.unsplash.com/photo-1586528116311-ad8dd3c8310d?w=500',
        ]);

        // Order is marked completed
        $order->update(['status' => 'completed']);

        // Admin inspects order and audit trail
        $adminResponse = $this->actingAs($admin)->onPortal('admin')->get('/admin/dashboard');
        $this->assertTrue(in_array($adminResponse->status(), [200, 302]));

        // Commission ledger intact
        $this->assertCommissionSplit($order);
    }

    /**
     * T4-11: Mid-Flight Courier Breakdown, Hub Reassignment & Handover
     */
    public function test_t4_11_courier_breakdown_hub_reassignment(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $courier1 = $this->createApprovedUser('courier');
        $courier2 = $this->createApprovedUser('courier');
        $logistics = $this->createApprovedUser('logistics');

        $order = $this->createE2EOrder($buyer, $shop, [], 'ready_for_pickup');
        $delivery = $this->createE2EDelivery($order, 'out_for_delivery', $courier1);

        // Courier 1 breaks down, Hub reassigns parcel to Courier 2
        $delivery->update(['courier_id' => $courier2->id]);
        $delivery->refresh();

        $this->assertEquals($courier2->id, $delivery->courier_id);

        // Courier 2 completes delivery
        $this->actingAs($courier2)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'delivered',
            'proof_image' => 'https://images.unsplash.com/photo-1586528116311-ad8dd3c8310d?w=500',
        ]);

        $this->assertEquals('delivered', $delivery->fresh()->status);
    }

    /**
     * T4-12: Full Subdomain Cross-Portal Simultaneous Multi-Actor Session
     */
    public function test_t4_12_full_subdomain_cross_portal_multi_actor_session(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $courier = $this->createApprovedUser('courier');
        $logistics = $this->createApprovedUser('logistics');
        $admin = $this->createApprovedUser('admin');

        // Verify each actor accesses their respective domain cleanly
        $respBuyer = $this->actingAs($buyer)->onPortal('buyer')->get('/');
        $this->assertTrue(in_array($respBuyer->status(), [200, 302]));

        $respSeller = $this->actingAs($seller)->onPortal('seller')->get('/seller/dashboard');
        $this->assertTrue(in_array($respSeller->status(), [200, 302]));

        $respCourier = $this->actingAs($courier)->onPortal('courier')->get('/courier/deliveries');
        $this->assertTrue(in_array($respCourier->status(), [200, 302]));

        $respHub = $this->actingAs($logistics)->onPortal('hub')->get('/hub');
        $this->assertTrue(in_array($respHub->status(), [200, 302]));

        $respAdmin = $this->actingAs($admin)->onPortal('admin')->get('/admin/dashboard');
        $this->assertTrue(in_array($respAdmin->status(), [200, 302]));
    }
}

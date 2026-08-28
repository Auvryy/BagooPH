<?php

namespace Tests\Feature\E2E\Tier2;

use App\Models\CommissionLedger;
use App\Models\Delivery;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\E2E\Support\AssertsCommissionLedgers;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\Feature\E2E\Support\SimulatesOrderLifecycle;
use Tests\TestCase;

class B6_CommissionLedgerIdempotencyTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithRoles, CreatesE2EOrders, SimulatesOrderLifecycle, AssertsDeliveryCheckpoints, AssertsCommissionLedgers;

    public function test_b6_01_duplicate_delivered_triggers_do_not_create_duplicate_commission_ledger_entries(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'pending');

        $this->actingAs($buyer);

        // Fast forward to delivered status (creates ledger)
        $deliveredOrder = $this->fastForwardToDelivered($order);
        $this->assertEquals('delivered', $deliveredOrder->status);

        // Trigger advance again (simulating duplicate webhook / retry call)
        $duplicateResponse = $this->advanceOrderStage($deliveredOrder);
        $duplicateResponse->assertOk();

        // Must still have exactly 1 ledger record
        $this->assertLedgerIdempotent($deliveredOrder);
    }

    public function test_b6_02_order_with_100_percent_discount_calculates_commission_without_division_by_zero(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);

        // Free promotional item with ₱0.00 subtotal
        $product = $this->createE2EProduct($shop, ['price' => 0.00]);
        $order = $this->createE2EOrder($buyer, $shop, [['product' => $product, 'quantity' => 1, 'unit_price' => 0.00]], 'pending');

        $this->actingAs($buyer);
        $deliveredOrder = $this->fastForwardToDelivered($order);

        $ledger = CommissionLedger::where('order_id', $deliveredOrder->id)->first();
        $this->assertNotNull($ledger);

        // ₱0.00 calculations
        $this->assertEquals(0.00, (float) $ledger->gross_amount);
        $this->assertEquals(0.00, (float) $ledger->seller_amount);
        $this->assertEquals(0.00, (float) $ledger->platform_commission);
        $this->assertEquals(60.00, (float) $ledger->delivery_fee);
        $this->assertEquals('settled', $ledger->status);
    }

    public function test_b6_03_fractional_centavo_rounding_maintains_exact_double_entry_balance(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);

        // Odd fractional pricing (₱199.99)
        $product = $this->createE2EProduct($shop, ['price' => 199.99]);
        $order = $this->createE2EOrder($buyer, $shop, [['product' => $product, 'quantity' => 1, 'unit_price' => 199.99]], 'pending');

        $this->actingAs($buyer);
        $deliveredOrder = $this->fastForwardToDelivered($order);

        $ledger = CommissionLedger::where('order_id', $deliveredOrder->id)->first();
        $this->assertNotNull($ledger);

        $gross = (float) $ledger->gross_amount;
        $sellerPart = (float) $ledger->seller_amount;
        $platformPart = (float) $ledger->platform_commission;

        // 90% of 199.99 = 179.991 -> 179.99
        $this->assertEquals(179.99, $sellerPart);

        // 10% of 199.99 = 19.999 -> 20.00
        $this->assertEquals(20.00, $platformPart);

        // Sum must equal gross amount exactly (179.99 + 20.00 = 199.99)
        $this->assertEquals($gross, round($sellerPart + $platformPart, 2));
    }

    public function test_b6_04_cancelled_order_never_generates_positive_commission_ledger_records(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);

        $cancelledOrder = $this->createE2EOrder($buyer, $shop, [], 'cancelled');

        // Confirm zero commission ledger entries
        $this->assertEquals(0, CommissionLedger::where('order_id', $cancelledOrder->id)->count());

        // Attempting to advance cancelled order is rejected
        $this->actingAs($buyer);
        $response = $this->advanceOrderStage($cancelledOrder);
        $response->assertStatus(400);

        // Still zero ledger records
        $this->assertEquals(0, CommissionLedger::where('order_id', $cancelledOrder->id)->count());
    }

    public function test_b6_05_unauthorized_user_cannot_tamper_with_or_directly_post_to_commission_ledger(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'pending');

        // Unauthenticated access to simulator advance endpoint is redirected
        $response = $this->post(route('simulator.orders.advance', $order->id));
        $response->assertRedirect(route('login'));

        // Commission ledger is not created for unauthenticated request
        $this->assertDatabaseMissing('commission_ledgers', ['order_id' => $order->id]);
    }
}

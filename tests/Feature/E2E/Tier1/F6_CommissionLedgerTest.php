<?php

namespace Tests\Feature\E2E\Tier1;

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

class F6_CommissionLedgerTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithRoles, CreatesE2EOrders, SimulatesOrderLifecycle, AssertsDeliveryCheckpoints, AssertsCommissionLedgers;

    public function test_f6_01_order_delivery_completion_triggers_atomic_commission_ledger_creation(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $courier = $this->createApprovedUser('courier');
        $delivery = $this->createE2EDelivery($order, 'out_for_delivery', $courier);

        $this->actingAs($courier);
        $this->advanceOrderStage($order);

        $order->refresh();
        $this->assertEquals('delivered', $order->status);

        $ledger = CommissionLedger::where('order_id', $order->id)->first();
        $this->assertNotNull($ledger);
        $this->assertEquals('settled', $ledger->status);
    }

    public function test_f6_02_commission_ledger_credits_90_percent_to_seller(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $product = $this->createE2EProduct($shop, ['price' => 2000.00]);
        $order = $this->createE2EOrder($buyer, $shop, [['product' => $product, 'quantity' => 1]], 'shipped');
        $courier = $this->createApprovedUser('courier');
        $delivery = $this->createE2EDelivery($order, 'out_for_delivery', $courier);

        $this->actingAs($courier);
        $this->advanceOrderStage($order);

        $ledger = $this->assertCommissionSplit($order, 2000.00);

        // 90% of ₱2,000 = ₱1,800.00
        $this->assertEquals(1800.00, (float) $ledger->seller_amount);
    }

    public function test_f6_03_commission_ledger_credits_10_percent_to_platform_treasury(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $product = $this->createE2EProduct($shop, ['price' => 1500.00]);
        $order = $this->createE2EOrder($buyer, $shop, [['product' => $product, 'quantity' => 1]], 'shipped');
        $courier = $this->createApprovedUser('courier');
        $delivery = $this->createE2EDelivery($order, 'out_for_delivery', $courier);

        $this->actingAs($courier);
        $this->advanceOrderStage($order);

        $ledger = $this->assertCommissionSplit($order, 1500.00);

        // 10% of ₱1,500 = ₱150.00
        $this->assertEquals(150.00, (float) $ledger->platform_commission);
    }

    public function test_f6_04_commission_ledger_credits_standard_delivery_fee_to_courier(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $courier = $this->createApprovedUser('courier');
        $delivery = $this->createE2EDelivery($order, 'out_for_delivery', $courier);

        $this->actingAs($courier);
        $this->advanceOrderStage($order);

        $ledger = $this->assertCommissionSplit($order);

        // Courier standard delivery fee = ₱60.00
        $this->assertEquals(60.00, (float) $ledger->delivery_fee);
        $this->assertEquals($courier->id, $ledger->courier_id);
    }

    public function test_f6_05_seller_and_courier_earnings_views_reflect_settled_ledger_records(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $courier = $this->createApprovedUser('courier');
        $delivery = $this->createE2EDelivery($order, 'out_for_delivery', $courier);

        $this->actingAs($courier);
        $this->advanceOrderStage($order);

        // Seller Reports View
        $sellerResponse = $this->actingAs($seller)->get(route('seller.reports'));
        $sellerResponse->assertOk();

        // Courier Earnings View
        $courierResponse = $this->actingAs($courier)->get(route('courier.earnings'));
        $courierResponse->assertOk();
    }
}

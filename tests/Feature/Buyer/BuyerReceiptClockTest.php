<?php

namespace Tests\Feature\Buyer;

use App\Models\CommissionLedger;
use App\Models\SellerSettlement;
use App\Services\Finance\SellerSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class BuyerReceiptClockTest extends TestCase
{
    use AssertsDeliveryCheckpoints, CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    public function test_elapsed_time_and_history_reads_never_replace_cash_reconciliation(): void
    {
        $this->withoutVite();
        Storage::fake('public');
        Storage::fake('local');
        $parcel = $this->flowDelivery($this->newFlowOrder('ready_for_pickup'), 'delivered');
        $order = $parcel->order->fresh();
        // The recorded recipient handoff and exact COD complete the order; the buyer has nothing left to confirm.
        $this->assertSame('completed', $order->status);
        $this->assertNotNull($order->completed_at);
        $this->travel(8)->days();
        $this->actingAs($order->buyer)->get('/buyer/orders?order_status=completed')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('orders.data', 1)->where('orders.data.0.status', 'completed')->where('orders.data.0.can_confirm_receipt', false));
        // Elapsed time alone never settles the seller: the collected cash is not yet reconciled at the hub.
        $this->assertFalse(app(SellerSettlementService::class)->eligibility($order->fresh())['eligible']);
        $this->assertSame(0, CommissionLedger::count());
        $this->assertSame(0, SellerSettlement::count());
    }
}

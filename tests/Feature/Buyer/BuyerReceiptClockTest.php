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

    public function test_elapsed_time_and_history_reads_never_replace_explicit_buyer_receipt_or_cash_reconciliation(): void
    {
        $this->withoutVite();
        Storage::fake('public');
        Storage::fake('local');
        $parcel = $this->flowDelivery($this->newFlowOrder('ready_for_pickup'), 'delivered');
        $order = $parcel->order->fresh();
        $this->travel(8)->days();
        $this->actingAs($order->buyer)->get('/buyer/orders?order_status=delivered')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('orders.data', 1)->where('orders.data.0.status', 'delivered')->where('orders.data.0.can_confirm_receipt', true));
        $this->assertSame('delivered', $order->fresh()->status);
        $this->assertNull($order->fresh()->completed_at);
        $basis = app(SellerSettlementService::class)->eligibility($order->fresh());
        $this->assertFalse($basis['eligible']);
        $this->assertContains('The buyer has not confirmed receipt through the documented order flow.', $basis['blockers']);
        $this->post('/buyer/orders/'.$order->id.'/confirm')->assertSessionHas('success');
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertNotNull($order->fresh()->completed_at);
        $this->assertFalse(app(SellerSettlementService::class)->eligibility($order->fresh())['eligible']);
        $this->assertSame(0, CommissionLedger::count());
        $this->assertSame(0, SellerSettlement::count());
    }
}

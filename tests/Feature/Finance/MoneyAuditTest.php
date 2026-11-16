<?php

namespace Tests\Feature\Finance;

use App\Models\LogisticsEarning;
use App\Models\Order;
use App\Services\Finance\MoneyAuditService;
use App\Services\Wallet\WalletCreditService;
use App\Services\Wallet\WalletPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class MoneyAuditTest extends TestCase
{
    use CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
    }

    public function test_every_peso_adds_up_across_cash_wallet_voucher_heavy_and_cancelled_orders(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $shop = $this->createE2EShop($this->createApprovedUser('seller'));

        // Cash on delivery, delivered, cash handed to the hub and settled.
        $cod = $this->checkoutFlowOrder($buyer, $shop, [['product' => $this->createE2EProduct($shop, ['price' => 2000])]]);
        $this->flowDelivery($cod, 'delivered');
        $this->completeFlowOrder($cod);
        $this->settleFlowOrder($cod);

        // A seller voucher with an odd-centavo subtotal.
        $voucher = $this->createE2EVoucher($shop, ['min_spend' => 100]);
        $discounted = $this->checkoutFlowOrders($buyer, [['product' => $this->createE2EProduct($shop, ['price' => 149.99])]], ['voucher_code' => $voucher->code])->first();
        $this->flowDelivery($discounted, 'delivered');
        $this->completeFlowOrder($discounted);

        // A heavy, multi-unit parcel on the weight-based tariff.
        $heavy = $this->checkoutFlowOrder($buyer, $shop, [['product' => $this->createE2EProduct($shop, ['price' => '333.33', 'weight_kg' => 3.4, 'stock' => 9]), 'quantity' => 3]]);
        $this->flowDelivery($heavy, 'delivered');

        // Paid from the Bagoo Wallet.
        $walletBuyer = $this->createApprovedUser('buyer');
        $wallet = $this->checkoutFlowOrder($walletBuyer, $shop, [['product' => $this->createE2EProduct($shop, ['price' => 450])]], 'placed', ['payment_method' => 'wallet']);
        $credit = app(WalletCreditService::class);
        $request = $credit->request($walletBuyer, ['amount' => '1000.00', 'reason' => 'Audit fixture.', 'request_token' => (string) Str::uuid()]);
        $credit->review($this->createApprovedUser('admin'), $request, ['decision' => 'approved', 'reason' => 'Audit fixture credit.', 'demo_credit_confirmed' => true, 'request_token' => (string) Str::uuid()]);
        app(WalletPaymentService::class)->command($walletBuyer, $wallet->fresh()->walletPayment, 'pay', ['payment_confirmed' => true, 'request_token' => (string) Str::uuid()]);
        $this->flowDelivery($wallet->fresh(), 'delivered');

        // Cancelled before pickup: no shares, no cash.
        $cancelled = $this->checkoutFlowOrder($buyer, $shop, [['product' => $this->createE2EProduct($shop, ['stock' => 5])]]);
        $this->actingAs($shop->user)->post(route('seller.orders.cancel', $cancelled), ['reason' => 'Out of stock / Inventory shortage'])->assertSessionHas('success');

        $this->assertGreaterThan(0, LogisticsEarning::count());
        $result = app(MoneyAuditService::class)->run();
        $this->assertSame([], $result['issues'], json_encode($result['issues'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->assertSame(Order::count(), $result['checked']);
        $this->artisan('bagoo:audit-money')->assertSuccessful();
    }

    public function test_a_wrong_share_is_reported_with_the_order_and_the_amounts(): void
    {
        $order = $this->checkoutFlowOrder($this->createApprovedUser('buyer'), $shop = $this->createE2EShop($this->createApprovedUser('seller')),
            [['product' => $this->createE2EProduct($shop, ['price' => 500])]]);
        $this->flowDelivery($order, 'delivered');
        // Earnings are immutable in the database; lift that guard here only to simulate a corrupted share.
        foreach (DB::select("SELECT name FROM sqlite_master WHERE type = 'trigger' AND tbl_name = 'logistics_earnings'") as $trigger) {
            DB::unprepared('DROP TRIGGER IF EXISTS "'.$trigger->name.'"');
        }
        LogisticsEarning::where('order_id', $order->id)->where('role', 'seller')->update(['amount_cents' => 1]);

        $issues = app(MoneyAuditService::class)->run()['issues'];
        $this->assertContains('Seller share', array_column($issues, 'check'));
        $this->assertSame($order->order_number, $issues[0]['order']);
        $this->artisan('bagoo:audit-money')->assertFailed();
    }
}

<?php

namespace Tests\Feature\Returns;

use App\Models\BuyerReturnAdjustment;
use App\Models\BuyerReturnCase;
use App\Models\BuyerReturnMoneyEvent;
use App\Models\CodAccount;
use App\Models\LogisticsHub;
use App\Models\Order;
use App\Models\User;
use App\Models\Voucher;
use App\Models\WalletAccount;
use App\Models\WalletTransaction;
use App\Services\Finance\CodCashService;
use App\Services\Finance\SellerSettlementService;
use App\Services\Returns\BuyerReturnService;
use App\Services\Wallet\WalletCreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\Feature\Returns\Support\CreatesBuyerReturns;
use Tests\TestCase;

class SelectedProductVoucherReturnTest extends TestCase
{
    use AssertsDeliveryCheckpoints, CreatesBuyerReturns, CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->freezeTime();
        Storage::fake('local');
        Storage::fake('public');
    }

    public static function returns(): array
    {
        return [['cod', 'qualifying', 9000], ['cod', 'other', 70000], ['cod', 'all', 88000],
            ['wallet', 'qualifying', 9000], ['wallet', 'other', 70000], ['wallet', 'all', 88000]];
    }

    private function received(BuyerReturnCase $case): void
    {
        $this->intake($case);
        foreach (range(0, count($case->parcel->hub_ids) - 2) as $index) {
            $this->leg($case);
        }
        $hub = LogisticsHub::findOrFail($case->parcel->hub_ids[count($case->parcel->hub_ids) - 1]);
        $this->actingAs($this->flowHandler($hub))->post('/buyer-returns/'.$case->id.'/custody', $this->custodyPayload($case, 'stage', ['hub_id' => $hub->id]))->assertSessionHasNoErrors();
        $this->actingAs($case->order->shop->user)->post('/buyer-returns/'.$case->id.'/custody', $this->custodyPayload($case, 'seller-receive', [
            'condition' => 'accepted', 'photo' => UploadedFile::fake()->image('selected-product-receipt.png'),
        ]))->assertSessionHasNoErrors();
    }

    private function reconcile(Order $order, User $admin): void
    {
        $cash = CodAccount::where('order_id', $order->id)->sole();
        $handler = $this->flowHandler(LogisticsHub::findOrFail($cash->hub_id));
        $service = app(CodCashService::class);
        $command = fn (User $actor, string $action, array $data) => $service->command($actor, $cash, $action, $data + [
            'expected_version' => $cash->fresh()->version, 'request_token' => (string) Str::uuid(), 'receipt_confirmed' => true,
            'evidence_reference' => 'SCOPE-CASH-'.$cash->id, 'reason' => 'The named recipient checked actual cash against the original collection.']);
        foreach (['rider' => $handler, 'hub' => $admin] as $stage => $recipient) {
            foreach ($cash->fresh()->state['balances'] as $key => $cents) {
                if ($cents > 0 && str_starts_with($key, $stage.':')) {
                    $holder = User::findOrFail(explode(':', $key)[1]);
                    $offer = $command($holder, 'offer', ['holder_id' => $holder->id, 'recipient_id' => $recipient->id,
                        'amount' => SellerSettlementService::pesos($cents)]);
                    $command($recipient, 'receive', ['offer_reference' => $offer->reference, 'received_amount' => SellerSettlementService::pesos($cents)]);
                }
            }
        }
        $command($admin, 'reconcile', []);
    }

    #[DataProvider('returns')]
    public function test_real_return_custody_and_repayment_keep_the_discount_on_its_original_products(string $method, string $selection, int $expected): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $qualifying = $this->createE2EProduct($shop, ['price' => '100.00']);
        $other = $this->createE2EProduct($shop, ['price' => '700.00']);
        $voucher = Voucher::factory()->funded(['product_scope' => 'selected', 'product_ids' => [$qualifying->id], 'percentage_bps' => 1000])
            ->create(['shop_id' => $shop->id, 'discount_type' => 'percent', 'discount_value' => 10, 'min_spend' => 0]);
        $order = $this->checkoutFlowOrder($buyer, $shop, [['product' => $qualifying, 'quantity' => 2], ['product' => $other, 'quantity' => 1]],
            shipping: ['payment_method' => $method, 'shop_voucher_revision_ids' => [$shop->id => $voucher->current_revision_id]]);
        $this->assertSame('940.00', $order->total_amount);
        $admin = $this->createApprovedUser('admin');
        if ($method === 'wallet') {
            $credits = app(WalletCreditService::class);
            $request = $credits->request($buyer, ['amount' => '1000.00', 'reason' => 'Demo credits for a selected-product return.', 'request_token' => (string) Str::uuid()]);
            $credits->review($admin, $request, ['decision' => 'approved', 'reason' => 'Approved isolated demo credits.',
                'demo_credit_confirmed' => true, 'request_token' => (string) Str::uuid()]);
            $this->actingAs($buyer)->postJson('/wallet/payments/'.$order->walletPayment->id.'/pay', ['payment_confirmed' => true, 'request_token' => (string) Str::uuid()])->assertOk();
        }
        $this->flowDelivery($order, 'delivered');
        $this->actingAs($buyer)->post('/buyer/orders/'.$order->id.'/confirm')->assertSessionHas('success');
        if ($method === 'cod') {
            $this->reconcile($order, $admin);
        }
        $items = $order->items()->orderBy('id')->get();
        $chosen = match ($selection) {
            'qualifying' => [['order_item_id' => $items[0]->id, 'quantity' => 1]],
            'other' => [['order_item_id' => $items[1]->id, 'quantity' => 1]],
            default => [['order_item_id' => $items[0]->id, 'quantity' => 2], ['order_item_id' => $items[1]->id, 'quantity' => 1]],
        };
        $service = app(BuyerReturnService::class);
        $case = $service->request($buyer, $order, ['request_token' => $service->form($buyer, $order)['requestToken'],
            'reason' => 'damaged', 'description' => 'The delivered fastening is broken and cannot be used.', 'items' => $chosen], [UploadedFile::fake()->image('damaged-product.png')]);
        $service->command($seller, $case, 'approve', ['request_token' => (string) Str::uuid(), 'expected_version' => 1,
            'reason' => 'The original item evidence supports the return.']);
        $case = $case->fresh(['parcel', 'order']);
        $this->received($case);
        $qualifying->update(['price' => '999.00']);
        $voucher->update(['is_active' => false]);
        $original = $order->fresh()->getRawOriginal();
        $this->actingAs($admin)->post('/buyer-returns/'.$case->id.'/money', ['action' => 'approve-repayment', 'expected_version' => $case->fresh()->version,
            'request_token' => (string) Str::uuid(), 'reason' => 'Reviewed the original returned units and their actual discounted payment.'])->assertSessionHasNoErrors();
        $this->assertSame($expected, BuyerReturnAdjustment::sole()->buyer_cents);
        $payload = ['action' => $method === 'wallet' ? 'refund-wallet' : 'record-repayment', 'expected_version' => $case->fresh()->version,
            'request_token' => (string) Str::uuid(), 'reason' => 'Confirmed the exact original discounted amount for these returned units.',
            ...($method === 'wallet' ? ['wallet_refund_confirmed' => true] : ['payment_confirmed' => true,
                'cash_reference' => 'SCOPED-RETURN-'.$case->id, 'proof' => UploadedFile::fake()->image('actual-return-payment.png')])];
        $this->post('/buyer-returns/'.$case->id.'/money', $payload)->assertSessionHasNoErrors();
        $this->post('/buyer-returns/'.$case->id.'/money', $payload)->assertSessionHasNoErrors();
        if ($method === 'wallet') {
            $this->assertSame($expected + 6000, WalletAccount::where('user_id', $buyer->id)->sole()->balance_cents);
            $this->assertSame($expected, WalletTransaction::where('buyer_return_case_id', $case->id)->sole()->amount_cents);
        } else {
            $payment = BuyerReturnMoneyEvent::where('buyer_return_case_id', $case->id)->where('event_type', 'record-repayment')->sole();
            $this->assertSame($expected, $payment->amount_cents);
            $receipt = ['action' => 'confirm-receipt', 'expected_version' => $case->fresh()->version,
                'request_token' => (string) Str::uuid(), 'source_event_id' => $payment->id,
                'reason' => 'I received the exact original discounted amount for these returned products.'];
            $this->actingAs($buyer)->post('/buyer-returns/'.$case->id.'/money', $receipt)->assertSessionHasNoErrors();
            $this->post('/buyer-returns/'.$case->id.'/money', $receipt)->assertSessionHasNoErrors();
        }
        $this->assertSame('repaid', $case->fresh()->status);
        $this->assertSame(1, $voucher->currentRevision->balance->fresh()->redemption_count);
        $this->assertSame($original, $order->fresh()->getRawOriginal());
        $this->assertDatabaseCount('buyer_return_adjustments', 1);
    }
}

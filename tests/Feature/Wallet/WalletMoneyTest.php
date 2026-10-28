<?php

namespace Tests\Feature\Wallet;

use App\Models\BuyerReturnCase;
use App\Models\LogisticsHub;
use App\Models\Order;
use App\Models\User;
use App\Models\Voucher;
use App\Models\WalletAccount;
use App\Models\WalletEntry;
use App\Models\WalletTransaction;
use App\Services\Returns\BuyerReturnService;
use App\Services\Wallet\WalletCreditService;
use App\Services\Wallet\WalletDeliveryRefundService;
use App\Services\Wallet\WalletSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\Feature\Returns\Support\CreatesBuyerReturns;
use Tests\TestCase;

class WalletMoneyTest extends TestCase
{
    // Preserve explicit settlement and refund controls for retained version-one payments.
    // New automatic virtual settlement is covered by NewEraLocalFlowTest.
    protected function flowContractVersion(): int
    {
        return 1;
    }

    use AssertsDeliveryCheckpoints, CreatesBuyerReturns, CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        Storage::fake('local');
        Storage::fake('public');
    }

    private function purchase(bool $funded = false, int $quantity = 2, string $price = '100.00'): array
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $product = $this->createE2EProduct($shop, ['price' => $price]);
        $shipping = ['payment_method' => 'wallet'];
        if ($funded) {
            $shopOffer = Voucher::factory()->funded()->create(['shop_id' => $shop->id, 'min_spend' => 0, 'discount_type' => 'fixed', 'discount_value' => 10]);
            $platformOffer = Voucher::factory()->funded()->create(['min_spend' => 0, 'discount_type' => 'fixed', 'discount_value' => 20]);
            $shipping += ['shop_voucher_revision_ids' => [$shop->id => $shopOffer->current_revision_id], 'platform_voucher_revision_id' => $platformOffer->current_revision_id];
        }
        $order = $this->checkoutFlowOrder($buyer, $shop, [['product' => $product, 'quantity' => $quantity]], shipping: $shipping);
        $admin = $this->createApprovedUser('admin');
        $credits = app(WalletCreditService::class);
        $request = $credits->request($buyer, ['amount' => '1000.00', 'reason' => 'Demo credits for wallet money acceptance.', 'request_token' => (string) Str::uuid()]);
        $credits->review($admin, $request, ['decision' => 'approved', 'reason' => 'Approved the original buyer demo credit amount.', 'demo_credit_confirmed' => true, 'request_token' => (string) Str::uuid()]);
        $this->actingAs($buyer)->postJson('/wallet/payments/'.$order->walletPayment->id.'/pay', ['payment_confirmed' => true, 'request_token' => (string) Str::uuid()])->assertOk();

        return [$order->fresh(['items', 'buyer']), $admin, $seller];
    }

    private function complete(Order $order): void
    {
        $this->flowDelivery($order, 'delivered');
        $this->actingAs($order->buyer)->post('/buyer/orders/'.$order->id.'/confirm')->assertSessionHas('success');
    }

    private function settlementInput(Order $order): array
    {
        return ['request_token' => (string) Str::uuid(), 'consent' => app(WalletSettlementService::class)->present($order->fresh())['consent'],
            'reason' => 'Reviewed original wallet payment and confirmed buyer receipt.', 'credit_proceeds_confirmed' => true];
    }

    private function receivedReturn(Order $order): BuyerReturnCase
    {
        $order = $order->fresh(['buyer', 'items']);
        $service = app(BuyerReturnService::class);
        $case = $service->request($order->buyer, $order, ['request_token' => $service->form($order->buyer, $order)['requestToken'],
            'reason' => 'damaged', 'description' => 'The delivered fastening is broken and cannot be used.',
            'items' => [['order_item_id' => $order->items->first()->id, 'quantity' => 1]]], [UploadedFile::fake()->image('wallet-damage.png')]);
        $service->command($order->shop->user, $case, 'approve', ['request_token' => (string) Str::uuid(), 'expected_version' => 1,
            'reason' => 'The original evidence supports an actual item return.']);
        $case = $case->fresh(['parcel', 'order']);
        $this->intake($case);
        foreach (range(0, count($case->parcel->hub_ids) - 2) as $index) {
            $this->leg($case);
        }
        $hub = LogisticsHub::findOrFail($case->parcel->hub_ids[count($case->parcel->hub_ids) - 1]);
        $this->actingAs($this->flowHandler($hub))->post('/buyer-returns/'.$case->id.'/custody', $this->custodyPayload($case, 'stage', ['hub_id' => $hub->id]))->assertSessionHasNoErrors();
        $this->actingAs($order->shop->user)->post('/buyer-returns/'.$case->id.'/custody', $this->custodyPayload($case, 'seller-receive', [
            'condition' => 'accepted', 'photo' => UploadedFile::fake()->image('wallet-return-receipt.png'),
        ]))->assertSessionHasNoErrors();

        return $case->fresh(['order', 'items']);
    }

    private function refund(User $admin, BuyerReturnCase $case): void
    {
        $this->actingAs($admin)->post('/buyer-returns/'.$case->id.'/money', ['action' => 'approve-repayment', 'expected_version' => $case->fresh()->version,
            'request_token' => (string) Str::uuid(), 'reason' => 'Reviewed the actual returned units and original wallet amount.'])->assertSessionHasNoErrors();
        $payload = ['action' => 'refund-wallet', 'expected_version' => $case->fresh()->version, 'request_token' => (string) Str::uuid(),
            'wallet_refund_confirmed' => true, 'reason' => substr(str_repeat('Reviewed original returned units and exact wallet credits. ', 20), 0, 1000)];
        $this->post('/buyer-returns/'.$case->id.'/money', $payload)->assertSessionHasNoErrors();
        $this->post('/buyer-returns/'.$case->id.'/money', $payload)->assertSessionHasNoErrors();
        $this->assertSame('repaid', $case->fresh()->status);
        $this->assertSame($payload['reason'], WalletTransaction::where('buyer_return_case_id', $case->id)->sole()->reason);
    }

    public function test_virtual_proceeds_require_buyer_receipt_and_credit_90_percent_without_cash_records(): void
    {
        [$order, $admin, $seller] = $this->purchase();
        $this->actingAs($admin)->postJson('/wallet/proceeds/'.$order->id, $this->settlementInput($order))->assertConflict();
        $this->complete($order);
        $payload = $this->settlementInput($order);
        $reference = $this->actingAs($admin)->postJson('/wallet/proceeds/'.$order->id, $payload)->assertOk()->json('reference');
        $this->postJson('/wallet/proceeds/'.$order->id, $payload)->assertOk()->assertJsonPath('reference', $reference);
        $this->assertSame(18000, WalletAccount::where('user_id', $seller->id)->sole()->balance_cents);
        $this->assertSame(2000, WalletAccount::where('kind', 'commission')->sole()->balance_cents);
        $this->assertSame(6000, WalletAccount::where('kind', 'shipping')->sole()->balance_cents);
        $this->assertSame(0, WalletAccount::where('kind', 'escrow')->sole()->balance_cents);
        $this->assertDatabaseCount('cod_accounts', 0);
        $this->assertDatabaseCount('commission_ledgers', 0);
        $this->assertDatabaseCount('seller_settlements', 0);
        $this->assertSame(0, (int) WalletEntry::sum('delta_cents'));
    }

    #[DataProvider('refundOrderings')]
    public function test_partial_original_wallet_refund_before_or_after_virtual_settlement_preserves_every_cent(bool $settleFirst, bool $funded): void
    {
        [$order, $admin, $seller] = $this->purchase($funded);
        $this->complete($order);
        if ($settleFirst) {
            $this->actingAs($admin)->postJson('/wallet/proceeds/'.$order->id, $this->settlementInput($order))->assertOk();
        }
        $case = $this->receivedReturn($order);
        $this->refund($admin, $case);
        if (! $settleFirst) {
            $this->actingAs($admin)->postJson('/wallet/proceeds/'.$order->id, $this->settlementInput($order))->assertOk();
        }
        $this->assertSame($funded ? 85500 : 84000, WalletAccount::where('user_id', $order->buyer_id)->sole()->balance_cents);
        $this->assertSame($funded ? 8500 : 9000, WalletAccount::where('user_id', $seller->id)->sole()->balance_cents);
        $this->assertSame(1000, WalletAccount::where('kind', 'commission')->sole()->balance_cents);
        $this->assertSame(6000, WalletAccount::where('kind', 'shipping')->sole()->balance_cents);
        $this->assertSame($funded ? -1000 : 0, WalletAccount::where('kind', 'promotion')->sole()->balance_cents);
        $this->assertSame(0, WalletAccount::where('kind', 'escrow')->sole()->balance_cents);
        $this->assertSame(1, WalletTransaction::where('kind', 'wallet_return_refund')->count());
        $this->assertSame(1, WalletTransaction::where('kind', 'wallet_settlement')->count());
        $this->assertSame(0, (int) WalletEntry::sum('delta_cents'));
        $this->assertDatabaseCount('cod_accounts', 0);
        $this->assertDatabaseCount('seller_settlements', 0);
        $this->assertSame('completed', $order->fresh()->status);
    }

    public static function refundOrderings(): array
    {
        return [[false, false], [true, false], [false, true], [true, true]];
    }

    public function test_rounding_a_partial_centavo_return_preserves_the_original_90_10_split(): void
    {
        [$order, $admin, $seller] = $this->purchase(quantity: 3, price: '0.33');
        $this->complete($order);
        $case = $this->receivedReturn($order);
        $this->refund($admin, $case);
        $this->actingAs($admin)->postJson('/wallet/proceeds/'.$order->id, $this->settlementInput($order))->assertOk();
        $this->assertSame(93934, WalletAccount::where('user_id', $order->buyer_id)->sole()->balance_cents);
        $this->assertSame(59, WalletAccount::where('user_id', $seller->id)->sole()->balance_cents);
        $this->assertSame(7, WalletAccount::where('kind', 'commission')->sole()->balance_cents);
        $this->assertSame(0, (int) WalletEntry::sum('delta_cents'));
    }

    public function test_failed_virtual_settlement_rolls_back_all_ledger_sides_and_its_original_reference(): void
    {
        [$order, $admin] = $this->purchase();
        $this->complete($order);
        Event::listen('eloquent.created: '.WalletEntry::class, fn () => throw new RuntimeException('Simulated proceeds journal failure'));
        try {
            app(WalletSettlementService::class)->settle($admin, $order, $this->settlementInput($order));
            $this->fail('Incomplete proceeds must roll back.');
        } catch (RuntimeException $error) {
            $this->assertSame('Simulated proceeds journal failure', $error->getMessage());
        } finally {
            Event::forget('eloquent.created: '.WalletEntry::class);
        }
        $this->assertNull($order->walletPayment->fresh()->settlement_transaction_id);
        $this->assertSame(26000, WalletAccount::where('kind', 'escrow')->sole()->balance_cents);
        $this->assertSame(0, WalletTransaction::where('kind', 'wallet_settlement')->count());
    }

    #[DataProvider('promotionFunding')]
    public function test_failed_delivery_refunds_the_original_total_only_after_the_complete_return_to_its_seller(bool $funded): void
    {
        [$order, $admin, $seller] = $this->purchase($funded);
        $payload = ['request_token' => (string) Str::uuid(), 'payment_reference' => $order->walletPayment->reference,
            'reason' => 'Reviewed the original paid order and actual seller return receipt.', 'wallet_refund_confirmed' => true];
        $url = '/wallet/proceeds/'.$order->id.'/refund-returned';
        $this->actingAs($admin)->postJson($url, $payload)->assertConflict();
        $this->flowDelivery($order, 'out_for_delivery');
        $this->reportFlowFailure($order->delivery->fresh(), 'Customer refused');
        $this->receiveFailureFlow($order->delivery->fresh());
        $this->actingAs($admin)->postJson($url, $payload)->assertConflict();
        $this->returnToSellerFlow($order->delivery->fresh());
        $this->actingAs($seller)->postJson($url, $payload)->assertForbidden();
        $this->assertTrue(app(WalletSettlementService::class)->present($order->fresh())['can_refund_delivery']);
        $reference = $this->actingAs($admin)->postJson($url, $payload)->assertOk()->json('reference');
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('reference', $reference);
        $this->postJson($url, [...$payload, 'request_token' => (string) Str::uuid()])->assertConflict();
        $this->assertSame(100000, WalletAccount::where('user_id', $order->buyer_id)->sole()->balance_cents);
        $this->assertSame(0, WalletAccount::where('kind', 'escrow')->sole()->balance_cents);
        $this->assertSame('returned', $order->fresh()->status);
        $this->assertSame('refunded', $order->fresh()->payment_status);
        $this->assertSame(1, WalletTransaction::where('kind', 'wallet_delivery_refund')->count());
        $this->assertSame($funded ? 2 : 0, DB::table('wallet_voucher_releases')->count());
        $this->assertSame(0, DB::table('wallet_voucher_restorations')->count());
        $this->assertSame(0, (int) DB::table('voucher_revision_balances')->sum('committed_cents'));
        $this->assertSame(0, (int) WalletEntry::sum('delta_cents'));
        $this->assertDatabaseCount('cod_accounts', 0);
        $this->assertDatabaseCount('seller_settlements', 0);
        $this->assertSame($reference, app(WalletSettlementService::class)->present($order->fresh())['refund_reference']);
    }

    public static function promotionFunding(): array
    {
        return [[false], [true]];
    }

    public function test_failed_returned_parcel_credit_rolls_back_its_funding_release_and_payment_state(): void
    {
        [$order, $admin] = $this->purchase(funded: true);
        $this->flowDelivery($order, 'out_for_delivery');
        $this->reportFlowFailure($order->delivery->fresh(), 'Customer refused');
        $this->receiveFailureFlow($order->delivery->fresh());
        $this->returnToSellerFlow($order->delivery->fresh());
        $payload = ['request_token' => (string) Str::uuid(), 'payment_reference' => $order->walletPayment->reference,
            'reason' => 'Reviewed the original paid amount and actual reverse seller receipt.', 'wallet_refund_confirmed' => true];
        Event::listen('eloquent.created: '.WalletEntry::class, fn () => throw new RuntimeException('Simulated returned refund failure'));
        try {
            app(WalletDeliveryRefundService::class)->refund($admin, $order, $payload);
            $this->fail('A partial refund must not release funding or close its original payment.');
        } catch (RuntimeException $error) {
            $this->assertSame('Simulated returned refund failure', $error->getMessage());
        } finally {
            Event::forget('eloquent.created: '.WalletEntry::class);
        }
        $this->assertDatabaseCount('wallet_voucher_releases', 0);
        $this->assertSame(3000, (int) DB::table('voucher_revision_balances')->sum('committed_cents'));
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame(77000, WalletAccount::where('user_id', $order->buyer_id)->sole()->balance_cents);
        $this->assertSame(0, WalletTransaction::where('kind', 'wallet_delivery_refund')->count());
    }
}

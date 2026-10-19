<?php

namespace Tests\Feature\Wallet;

use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Models\Voucher;
use App\Models\WalletAccount;
use App\Models\WalletEntry;
use App\Models\WalletTransaction;
use App\Services\Orders\CheckoutOrderService;
use App\Services\Orders\CheckoutQuoteService;
use App\Services\Orders\CheckoutSubmissionService;
use App\Services\Orders\OrderLifecycleService;
use App\Services\Wallet\WalletCreditService;
use App\Services\Wallet\WalletPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\InteractsWithCheckoutNetwork;
use Tests\TestCase;

class WalletPaymentTest extends TestCase
{
    use InteractsWithCheckoutNetwork;
    use RefreshDatabase;

    private function purchase(string $method = 'wallet', bool $voucher = false): array
    {
        $this->travelTo(now()->setMicrosecond(0));
        $buyer = User::factory()->create(['name' => 'Maria Santos']);
        $shop = Shop::factory()->approved()->create(['city' => 'Manila']);
        $product = Product::factory()->create(['shop_id' => $shop->id, 'price' => '100.00', 'stock' => 20]);
        $cart = Cart::create(['user_id' => $buyer->id]);
        $line = $cart->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => '100.00']);
        $this->createCheckoutNetwork($shop, ['Pasig' => 'Metro Manila']);
        $payload = ['recipient_name' => 'Maria Santos', 'recipient_phone' => '09171234567',
            'shipping_address' => '123 Mabini Street', 'shipping_city' => 'Pasig', 'shipping_province' => 'Metro Manila',
            'shipping_postal_code' => '1600', 'destination_barangay' => 'San Antonio', 'delivery_type' => 'doorstep',
            'payment_method' => $method, 'item_ids' => [$line->id], 'checkout_token' => app(CheckoutSubmissionService::class)->issue($buyer, $cart)];
        $offer = null;
        if ($voucher) {
            $offer = Voucher::factory()->funded(['per_buyer_limit' => 1])->create(['min_spend' => 0, 'discount_type' => 'fixed', 'discount_value' => 10,
                'usage_limit' => 1]);
            $payload['platform_voucher_revision_id'] = $offer->current_revision_id;
        }
        $order = app(CheckoutOrderService::class)->place($buyer, $cart, [$line->id], $payload)->sole();

        return [$buyer, $order, $product, $shop, $offer];
    }

    private function fund(User $buyer, string $amount = '1000.00'): void
    {
        $service = app(WalletCreditService::class);
        $request = $service->request($buyer, ['amount' => $amount, 'reason' => 'Demo credits for a wallet payment.', 'request_token' => (string) Str::uuid()]);
        $service->review(User::factory()->admin()->create(), $request, ['decision' => 'approved', 'reason' => 'Approved credits for an isolated demo.',
            'demo_credit_confirmed' => true, 'request_token' => (string) Str::uuid()]);
    }

    private function command(User $buyer, Order $order, string $action = 'pay', ?string $token = null)
    {
        return app(WalletPaymentService::class)->command($buyer, $order->walletPayment, $action,
            ['request_token' => $token ?? (string) Str::uuid(), 'payment_confirmed' => true]);
    }

    public function test_b9_001_cod_remains_unpaid_on_delivery_and_has_no_wallet_deadline(): void
    {
        [$buyer, $order, $product] = $this->purchase('cod');
        $this->assertSame('cod', $order->payment_method);
        $this->assertSame('pending', $order->payment_status);
        $this->assertSame('150.00', $order->total_amount);
        $this->assertNull($order->walletPayment);
        $this->assertSame(19, $product->fresh()->stock);
        $this->travel(61)->minutes();
        $this->assertSame(0, app(WalletPaymentService::class)->expireDue());
        $this->assertSame('placed', $order->fresh()->status);
    }

    public function test_b9_002_and_005_exact_payment_and_lost_response_recovery_never_charge_twice(): void
    {
        [$buyer, $order, $product] = $this->purchase();
        $this->fund($buyer);
        $token = (string) Str::uuid();
        $first = $this->command($buyer, $order, token: $token);
        $this->assertSame('paid', $first->outcome);
        $this->assertSame($first->id, $this->command($buyer, $order, token: $token)->id);
        $reopened = $this->command($buyer, $order);
        $this->assertSame('paid', $reopened->outcome);
        $this->assertSame($first->wallet_transaction_id, $reopened->wallet_transaction_id);
        $this->assertSame(85000, WalletAccount::where('user_id', $buyer->id)->sole()->balance_cents);
        $this->assertSame(15000, WalletAccount::where('kind', 'escrow')->sole()->balance_cents);
        $this->assertSame(1, WalletTransaction::where('kind', 'wallet_payment')->count());
        $this->assertTrue(app(WalletPaymentService::class)->paid($order->fresh()));
        $this->assertSame(19, $product->fresh()->stock);
        $this->assertSame(0, (int) WalletEntry::sum('delta_cents'));
        $this->assertDatabaseCount('cod_accounts', 0);
    }

    public function test_b9_003_failure_blocks_preparation_and_a_new_retry_can_succeed_after_credit_approval(): void
    {
        [$buyer, $order, , $shop] = $this->purchase();
        $token = (string) Str::uuid();
        $failed = $this->command($buyer, $order, token: $token);
        $this->assertSame('failed', $failed->outcome);
        $this->assertStringContainsString('Payment failed', $failed->message);
        try {
            app(OrderLifecycleService::class)->sellerTransition($order, $shop, $shop->user, 'confirmed');
            $this->fail('An unpaid wallet order must not advance.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertSame('placed', $order->fresh()->status);
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->fund($buyer);
        $this->assertSame($failed->id, $this->command($buyer, $order, token: $token)->id);
        $this->assertSame('paid', $this->command($buyer, $order)->outcome);
        app(OrderLifecycleService::class)->sellerTransition($order->fresh(), $shop, $shop->user, 'confirmed');
        $this->assertSame('confirmed', $order->fresh()->status);
    }

    public function test_b9_004_cancel_payment_leaves_one_unpaid_order_and_unchanged_balance(): void
    {
        [$buyer, $order] = $this->purchase();
        $this->fund($buyer);
        $this->assertSame('cancelled_attempt', $this->command($buyer, $order, 'cancel')->outcome);
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertSame('pending', $order->walletPayment->fresh()->status);
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(100000, WalletAccount::where('user_id', $buyer->id)->sole()->balance_cents);
        $this->assertSame('paid', $this->command($buyer, $order)->outcome);
    }

    public function test_b9_006_expiry_restores_original_stock_and_eligible_voucher_once(): void
    {
        [$buyer, $order, $product, , $offer] = $this->purchase(voucher: true);
        $this->assertFalse(app(CheckoutQuoteService::class)->availableQuery(buyer: $buyer)->whereKey($offer->current_revision_id)->exists());
        $this->travel(60)->minutes();
        $this->artisan('wallet:expire-payments')->assertSuccessful();
        $this->artisan('wallet:expire-payments')->assertSuccessful();
        $this->assertSame(20, $product->fresh()->stock);
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertStringContainsString('Unpaid', $order->fresh()->notes);
        $this->assertSame('expired', $order->walletPayment->fresh()->status);
        $this->assertSame(0, $offer->fresh()->used_count);
        $this->assertSame(0, $offer->currentRevision->balance->fresh()->redemption_count);
        $this->assertSame(0, $offer->currentRevision->balance->fresh()->committed_cents);
        $this->assertTrue(app(CheckoutQuoteService::class)->availableQuery(buyer: $buyer)->whereKey($offer->current_revision_id)->exists());
        $this->assertDatabaseCount('wallet_voucher_restorations', 1);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    #[DataProvider('expiryBoundaries')]
    public function test_b9_007_payment_obeys_server_deadline_even_before_the_scheduler_runs(int $minutes, string $outcome): void
    {
        [$buyer, $order, $product] = $this->purchase();
        $this->fund($buyer);
        $this->travel($minutes)->minutes();
        $this->assertSame($outcome, $this->command($buyer, $order)->outcome);
        $this->assertSame($outcome === 'paid' ? 19 : 20, $product->fresh()->stock);
        $this->assertSame($outcome === 'paid' ? 85000 : 100000, WalletAccount::where('user_id', $buyer->id)->sole()->balance_cents);
        $this->assertSame(0, app(WalletPaymentService::class)->expireDue());
    }

    public static function expiryBoundaries(): array
    {
        return [[59, 'paid'], [60, 'expired'], [61, 'expired']];
    }

    public function test_earlier_payment_wins_over_expiry_and_later_expiry_does_not_restore_sold_stock(): void
    {
        [$buyer, $order, $product] = $this->purchase();
        $this->fund($buyer);
        $this->travel(59)->minutes();
        $this->command($buyer, $order);
        $this->travel(2)->minutes();
        $this->assertSame(0, app(WalletPaymentService::class)->expireDue());
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame(19, $product->fresh()->stock);
    }

    public function test_expiry_still_releases_an_unpaid_reservation_after_the_buyer_is_suspended(): void
    {
        [$buyer, $order, $product] = $this->purchase();
        $buyer->update(['status' => 'suspended']);
        $this->travel(61)->minutes();
        $this->assertSame(1, app(WalletPaymentService::class)->expireDue());
        $this->assertSame(20, $product->fresh()->stock);
        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_pre_dispatch_cancellation_refunds_only_the_original_payment_once(): void
    {
        [$buyer, $order, $product] = $this->purchase();
        $this->fund($buyer);
        $this->command($buyer, $order);
        $service = app(OrderLifecycleService::class);
        $data = ['reason' => 'Ordered by mistake'];
        $service->cancelByBuyer($order->fresh(), $buyer, $data);
        $service->cancelByBuyer($order->fresh(), $buyer, $data);
        $this->assertSame('refunded', $order->fresh()->payment_status);
        $this->assertSame(100000, WalletAccount::where('user_id', $buyer->id)->sole()->balance_cents);
        $this->assertSame(0, WalletAccount::where('kind', 'escrow')->sole()->balance_cents);
        $this->assertSame(20, $product->fresh()->stock);
        $this->assertSame(1, WalletTransaction::where('kind', 'wallet_cancellation_refund')->count());
    }

    public function test_changed_order_amount_cannot_be_charged_using_an_old_payment(): void
    {
        [$buyer, $order] = $this->purchase();
        $this->fund($buyer);
        $order->update(['total_amount' => '1.00']);
        $this->expectException(HttpException::class);
        $this->command($buyer, $order->fresh());
    }

    public function test_foreign_buyer_cannot_pay_or_reuse_another_buyers_payment_token(): void
    {
        [, $order] = $this->purchase();
        $this->expectException(HttpException::class);
        $this->command(User::factory()->create(), $order);
    }

    public function test_journal_failure_rolls_back_debit_paid_state_and_attempt_together(): void
    {
        [$buyer, $order] = $this->purchase();
        $this->fund($buyer);
        Event::listen('eloquent.created: '.WalletEntry::class, fn () => throw new RuntimeException('Simulated payment journal failure'));
        try {
            $this->command($buyer, $order);
            $this->fail('Payment must reject incomplete journal writes.');
        } catch (RuntimeException $error) {
            $this->assertSame('Simulated payment journal failure', $error->getMessage());
        } finally {
            Event::forget('eloquent.created: '.WalletEntry::class);
        }
        $this->assertSame(100000, WalletAccount::where('user_id', $buyer->id)->sole()->balance_cents);
        $this->assertSame('pending', $order->walletPayment->fresh()->status);
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertDatabaseCount('wallet_payment_attempts', 0);
        $this->assertSame(0, WalletTransaction::where('kind', 'wallet_payment')->count());
    }
}

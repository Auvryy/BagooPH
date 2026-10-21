<?php

namespace Tests\Feature\Buyer;

use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherRedemption;
use App\Models\WalletAccount;
use App\Models\WalletTransaction;
use App\Services\Orders\CheckoutQuoteService;
use App\Services\Orders\CheckoutSubmissionService;
use App\Services\Returns\BuyerReturnAmounts;
use App\Services\Vouchers\VoucherChoiceService;
use App\Services\Vouchers\VoucherCollectionService;
use App\Services\Wallet\WalletCreditService;
use App\Services\Wallet\WalletPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\InteractsWithCheckoutNetwork;
use Tests\TestCase;

class SelectedProductVoucherTest extends TestCase
{
    use InteractsWithCheckoutNetwork, RefreshDatabase;

    private Shop $shop;

    private User $buyer;

    private Cart $cart;

    private Product $qualifying;

    private Product $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->freezeTime();
        $this->shop = Shop::factory()->approved()->create(['city' => 'Manila']);
        $this->buyer = User::factory()->buyer()->create();
        $this->cart = Cart::create(['user_id' => $this->buyer->id]);
        $this->qualifying = Product::factory()->create(['shop_id' => $this->shop->id, 'category_id' => $this->shop->root_category_id, 'price' => '100.00', 'stock' => 10]);
        $this->other = Product::factory()->create(['shop_id' => $this->shop->id, 'category_id' => $this->shop->root_category_id, 'price' => '700.00', 'stock' => 10]);
        $this->actingAs($this->buyer);
    }

    private function offer(array $changes = [])
    {
        return Voucher::factory()->funded([...['product_scope' => 'selected', 'product_ids' => [$this->qualifying->id], 'percentage_bps' => 1000], ...$changes])
            ->create(['shop_id' => $this->shop->id, 'discount_type' => 'percent', 'discount_value' => 10, 'min_spend' => 0])->currentRevision;
    }

    private function items(int $quantity = 1)
    {
        $this->cart->items()->create(['product_id' => $this->qualifying->id, 'quantity' => $quantity, 'unit_price' => '1.00']);
        $this->cart->items()->create(['product_id' => $this->other->id, 'quantity' => 1, 'unit_price' => '1.00']);

        return $this->cart->items()->with('product.shop')->orderBy('id')->get();
    }

    private function input(array $changes = []): array
    {
        return [...['checkout_token' => app(CheckoutSubmissionService::class)->issue($this->buyer, $this->cart),
            'item_ids' => $this->cart->items()->pluck('id')->all(), 'recipient_name' => 'Ana Santos', 'recipient_phone' => '09171234567',
            'shipping_address' => '123 Mabini Street', 'shipping_city' => 'Pasig', 'shipping_province' => 'Metro Manila',
            'shipping_postal_code' => '1600', 'destination_barangay' => 'San Antonio', 'delivery_type' => 'doorstep', 'payment_method' => 'cod'], ...$changes];
    }

    public function test_other_products_do_not_meet_the_minimum_or_increase_the_percentage_discount(): void
    {
        $revision = $this->offer(['minimum_cents' => 20000]);
        $items = $this->items();
        $quotes = app(CheckoutQuoteService::class);
        $quote = $quotes->quote($items, 'doorstep', collect([$revision]), $this->buyer);
        $this->assertSame('Add ₱100.00 in qualifying products from this shop to use this voucher.', $quote['voucher_error']);
        $this->assertSame(0, $quote['discount']);
        $items[0]->quantity = 3;
        $quote = $quotes->quote($items, 'doorstep', collect([$revision]), $this->buyer);
        $this->assertNull($quote['voucher_error']);
        $this->assertSame(30, $quote['discount']);
        $this->assertSame(1020, $quote['total']);
        $items = $items->reject(fn ($item) => $item->product_id === $this->qualifying->id);
        $this->assertSame('Choose a qualifying product from this shop to use this voucher.', $quotes->quote($items, 'doorstep', collect([$revision]), $this->buyer)['voucher_error']);
    }

    public function test_product_detail_discovery_and_offer_choices_explain_the_real_product_scope(): void
    {
        $revision = $this->offer();
        $this->get('/product/'.$this->qualifying->slug)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('shopOffers', 1)->where('shopOffers.0.product_scope', 'selected')->where('shopOffers.0.eligible_products.0.id', $this->qualifying->id));
        $this->get('/product/'.$this->other->slug)->assertOk()->assertInertia(fn (Assert $page) => $page->has('shopOffers', 0));
        $this->items();
        $this->postJson(route('buyer.vouchers.choices'), ['item_ids' => $this->cart->items()->pluck('id')->all(), 'shop_id' => $this->shop->id])
            ->assertOk()->assertJsonPath('offers.data.0.product_scope', 'selected')->assertJsonPath('offers.data.0.saving', 10);
        $this->qualifying->update(['status' => 'draft']);
        $presented = app(CheckoutQuoteService::class)->present($revision->fresh());
        $this->assertSame([], $presented['eligible_products']);
        $collection = app(VoucherCollectionService::class)->present($revision->fresh(), $this->buyer);
        $this->assertFalse($collection['eligible']);
        $this->assertSame('The qualifying products are currently unavailable.', $collection['unavailable_reason']);
        $preview = app(CheckoutQuoteService::class)->offers(buyer: $this->buyer)['offers'][0];
        $this->assertFalse($preview['eligible']);
        $this->assertSame('The qualifying products are currently unavailable.', $preview['unavailable_reason']);
    }

    public function test_checkout_replay_and_partial_returns_use_the_original_selected_scope_and_exact_stacking(): void
    {
        $revision = $this->offer();
        $platform = Voucher::factory()->funded(['percentage_bps' => 1000])->create(['discount_type' => 'percent', 'discount_value' => 10, 'min_spend' => 0])->currentRevision;
        $this->items(3);
        $this->createCheckoutNetwork($this->shop, ['Pasig' => 'Metro Manila']);
        $input = $this->input(['shop_voucher_revision_ids' => [$this->shop->id => $revision->id], 'platform_voucher_revision_id' => $platform->id]);
        $this->post('/checkout', $input)->assertSessionHas('success');
        $order = Order::sole();
        $this->assertSame('127.00', $order->voucher_discount);
        $this->assertSame('923.00', $order->total_amount);
        $this->assertSame([$this->qualifying->id], VoucherRedemption::where('voucher_revision_id', $revision->id)->sole()->terms['product_ids']);
        $units = app(BuyerReturnAmounts::class)->original($order)['units'];
        $this->assertSame([1000, 1000, 1000, 0], array_column($units, 'shop_discount_cents'));
        $this->assertSame([8100, 8100, 8100, 63000], array_column($units, 'buyer_cents'));
        $this->assertSame(87300, array_sum(array_column($units, 'buyer_cents')));
        $before = $order->getRawOriginal();
        $this->qualifying->update(['price' => '999.00', 'name' => 'Revised Studio Product']);
        $revision->voucher->update(['is_active' => false]);
        $this->post('/checkout', $input)->assertSessionHas('success');
        $this->assertSame($before, $order->fresh()->getRawOriginal());
        $this->assertSame($units, app(BuyerReturnAmounts::class)->original($order->fresh())['units']);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('voucher_redemptions', 2);
    }

    public function test_cancellation_releases_original_selected_funding_and_restores_inventory_once(): void
    {
        $revision = $this->offer();
        $this->items(2);
        $this->createCheckoutNetwork($this->shop, ['Pasig' => 'Metro Manila']);
        $this->post('/checkout', $this->input(['shop_voucher_revision_ids' => [$this->shop->id => $revision->id]]))->assertSessionHas('success');
        $order = Order::sole();
        $original = $order->getRawOriginal();
        $this->assertSame(2000, $revision->balance->fresh()->committed_cents);
        foreach ([1, 2] as $attempt) {
            $this->post(route('buyer.orders.cancel', $order), ['reason' => 'Ordered by mistake'])->assertSessionHas('success');
        }
        $this->assertSame(0, $revision->balance->fresh()->committed_cents);
        $this->assertSame(2000, $revision->balance->fresh()->released_cents);
        $this->assertSame(1, $revision->balance->fresh()->redemption_count);
        $this->assertSame(10, $this->qualifying->fresh()->stock);
        $this->assertSame(10, $this->other->fresh()->stock);
        $this->assertSame($original['total_amount'], $order->fresh()->getRawOriginal('total_amount'));
        $this->assertDatabaseCount('voucher_funding_entries', 2);
    }

    public function test_selected_variant_prices_and_tiny_seller_shares_cannot_over_discount(): void
    {
        $this->qualifying->update(['price' => '1.00', 'stock' => 10]);
        $this->qualifying->sales()->create(['kind' => 'store', 'name' => 'Tiny selected offer', 'price' => '0.01', 'discount_type' => 'percentage',
            'percentage' => 99, 'starts_at' => now()->subMinute(), 'ends_at' => now()->addDay()]);
        $this->other->update(['price' => '0.04']);
        $revision = $this->offer(['percentage_bps' => 9000]);
        $quote = app(CheckoutQuoteService::class)->quote($this->items(), 'doorstep', collect([$revision]), $this->buyer);
        $this->assertNull($quote['voucher_error']);
        $this->assertSame(0.01, $quote['discount']);
        $this->createCheckoutNetwork($this->shop, ['Pasig' => 'Metro Manila']);
        $this->post('/checkout', $this->input(['shop_voucher_revision_ids' => [$this->shop->id => $revision->id]]))->assertSessionHas('success');
        $units = app(BuyerReturnAmounts::class)->original(Order::sole())['units'];
        $this->assertSame(0, $units[0]['seller_cents']);
        $this->assertSame(0, $units[0]['buyer_cents']);
        $this->assertSame(0, $units[1]['shop_discount_cents']);
    }

    public function test_all_selected_variants_use_their_full_current_sale_price_and_keep_other_products_outside_the_discount(): void
    {
        $this->qualifying->update(['price' => '500.00', 'variants' => ['pricing_mode' => 'independent', 'colors' => [],
            'sizes' => [['name' => 'Compact', 'stock' => 6], ['name' => 'Large', 'stock' => 4]],
            'combinations' => [['color' => null, 'size' => 'Compact', 'stock' => 6, 'price' => '199.95', 'sku' => 'SCOPE-C'],
                ['color' => null, 'size' => 'Large', 'stock' => 4, 'price' => '450.00', 'sku' => 'SCOPE-L']]]]);
        $this->qualifying->sales()->create(['kind' => 'store', 'name' => 'Studio option sale', 'price' => '375.00', 'discount_type' => 'percentage',
            'percentage' => 25, 'starts_at' => now()->subMinute(), 'ends_at' => now()->addDay()]);
        $revision = $this->offer(['percentage_bps' => 2000]);
        $this->post(route('cart.store'), ['product_id' => $this->qualifying->id, 'quantity' => 1, 'size' => 'Large'])->assertSessionHasNoErrors();
        $this->post(route('cart.store'), ['product_id' => $this->qualifying->id, 'quantity' => 1, 'size' => 'Compact'])->assertSessionHasNoErrors();
        $this->post(route('cart.store'), ['product_id' => $this->other->id, 'quantity' => 1])->assertSessionHasNoErrors();
        $this->createCheckoutNetwork($this->shop, ['Pasig' => 'Metro Manila']);
        $input = $this->input(['shop_voucher_revision_ids' => [$this->shop->id => $revision->id]]);
        $this->postJson(route('buyer.vouchers.apply'), $input)->assertOk()->assertJsonPath('quote.discount', 97.49)
            ->assertJsonPath('quote.total', 1139.97);
        $this->post('/checkout', $input)->assertSessionHas('success');
        $order = Order::sole();
        $item = $order->items()->where('product_id', $this->qualifying->id)->where('size', 'Large')->sole();
        $this->assertSame('337.50', $item->unit_price);
        $this->assertSame('SCOPE-L', $item->sku_snapshot);
        $compact = $order->items()->where('product_id', $this->qualifying->id)->where('size', 'Compact')->sole();
        $this->assertSame('149.96', $compact->unit_price);
        $this->assertSame('SCOPE-C', $compact->sku_snapshot);
        $units = app(BuyerReturnAmounts::class)->original($order)['units'];
        $this->assertSame(9749, array_sum(array_column($units, 'shop_discount_cents')));
        $this->assertSame(0, $units[2]['shop_discount_cents']);
    }

    public function test_best_voucher_compares_actual_qualifying_savings_and_preview_clears_a_now_ineligible_choice(): void
    {
        $targeted = $this->offer(['percentage_bps' => 5000]);
        $whole = Voucher::factory()->funded()->create(['shop_id' => $this->shop->id, 'discount_type' => 'percent', 'discount_value' => 10, 'min_spend' => 0])->currentRevision;
        $items = $this->items();
        $best = app(VoucherChoiceService::class)->best($this->buyer, $items, 'doorstep');
        $this->assertSame($whole->id, $best['selection']['shop_voucher_revision_ids'][$this->shop->id]);
        $this->assertSame(80, $best['quote']['discount']);
        $this->postJson(route('buyer.vouchers.apply'), ['item_ids' => [$items[1]->id], 'shop_voucher_revision_ids' => [$this->shop->id => $targeted->id]])
            ->assertUnprocessable()->assertJsonPath('validSelection.shop_voucher_revision_ids.'.$this->shop->id, null)
            ->assertJsonPath('quote.discount', 0)->assertJsonPath('selectionErrors.shop:'.$this->shop->id, 'Choose a qualifying product from this shop to use this voucher.');
    }

    public function test_a_late_checkout_failure_rolls_back_selected_discount_stock_and_all_funding(): void
    {
        $revision = $this->offer();
        $this->items(2);
        $this->createCheckoutNetwork($this->shop, ['Pasig' => 'Metro Manila']);
        $input = $this->input(['shop_voucher_revision_ids' => [$this->shop->id => $revision->id]]);
        $failedAtCommitment = false;
        Event::listen('eloquent.created: '.VoucherRedemption::class, function () use (&$failedAtCommitment) {
            $failedAtCommitment = true;
            throw new RuntimeException('Controlled selected-voucher commitment failure.');
        });
        $this->post('/checkout', $input)->assertRedirect()->assertSessionHas('error', 'We could not save your order. Please try again with the same checkout details.');
        $this->assertTrue($failedAtCommitment);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('voucher_redemptions', 0);
        $this->assertDatabaseCount('voucher_funding_entries', 0);
        $this->assertDatabaseCount('checkout_submissions', 0);
        $this->assertSame(10, $this->qualifying->fresh()->stock);
        $this->assertSame(10, $this->other->fresh()->stock);
        $this->assertSame(0, $revision->balance->fresh()->committed_cents);
        $this->assertSame(2, $this->cart->items()->count());
    }

    public static function walletEndings(): array
    {
        return [['paid_cancel'], ['unpaid_expiry']];
    }

    #[DataProvider('walletEndings')]
    public function test_wallet_cancellation_or_expiry_keeps_original_selected_amounts_and_restores_only_the_documented_use(string $ending): void
    {
        $revision = $this->offer();
        $this->items(2);
        $this->createCheckoutNetwork($this->shop, ['Pasig' => 'Metro Manila']);
        $this->post('/checkout', $this->input(['payment_method' => 'wallet', 'shop_voucher_revision_ids' => [$this->shop->id => $revision->id]]))->assertSessionHas('success');
        $order = Order::sole();
        $this->assertSame('930.00', $order->total_amount);
        $payments = app(WalletPaymentService::class);
        if ($ending === 'paid_cancel') {
            $credits = app(WalletCreditService::class);
            $request = $credits->request($this->buyer, ['amount' => '1000.00', 'reason' => 'Demo credits for a scoped voucher purchase.', 'request_token' => (string) Str::uuid()]);
            $credits->review(User::factory()->admin()->create(), $request, ['decision' => 'approved', 'reason' => 'Approved isolated demo credits.',
                'demo_credit_confirmed' => true, 'request_token' => (string) Str::uuid()]);
            $payments->command($this->buyer, $order->walletPayment, 'pay', ['request_token' => (string) Str::uuid(), 'payment_confirmed' => true]);
            foreach ([1, 2] as $attempt) {
                $this->post(route('buyer.orders.cancel', $order), ['reason' => 'Ordered by mistake'])->assertSessionHas('success');
            }
            $this->assertSame('refunded', $order->fresh()->payment_status);
            $this->assertSame(100000, WalletAccount::where('user_id', $this->buyer->id)->sole()->balance_cents);
            $this->assertSame(1, WalletTransaction::where('kind', 'wallet_cancellation_refund')->count());
            $this->assertSame(1, $revision->balance->fresh()->redemption_count);
        } else {
            $this->travel(61)->minutes();
            $this->assertSame(1, $payments->expireDue());
            $this->assertSame(0, $payments->expireDue());
            $this->assertSame(0, $revision->balance->fresh()->redemption_count);
            $this->assertDatabaseCount('wallet_voucher_restorations', 1);
        }
        $this->assertSame(10, $this->qualifying->fresh()->stock);
        $this->assertSame(0, $revision->balance->fresh()->committed_cents);
        $this->assertSame(2000, $revision->balance->fresh()->released_cents);
        $this->assertSame('20.00', $order->fresh()->voucher_discount);
        $this->assertSame([$this->qualifying->id], VoucherRedemption::sole()->terms['product_ids']);
    }
}

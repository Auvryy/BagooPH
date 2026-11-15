<?php

namespace Tests\Feature\Buyer;

use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductSale;
use App\Models\Shop;
use App\Models\User;
use App\Models\Voucher;
use App\Services\Orders\CheckoutQuoteService;
use App\Services\Orders\CheckoutSubmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\Concerns\InteractsWithCheckoutNetwork;
use Tests\Concerns\InteractsWithCheckoutSubmission;
use Tests\TestCase;

class TimedSalePricingTest extends TestCase
{
    use InteractsWithCheckoutNetwork;
    use InteractsWithCheckoutSubmission;
    use RefreshDatabase;

    private function fixture(): array
    {
        $this->withoutVite();
        $this->travelTo(now()->setTime(2, 0)->startOfMinute());
        $buyer = User::factory()->create();
        $shop = Shop::factory()->approved()->create(['city' => 'Taguig City']);
        $product = Product::factory()->create(['shop_id' => $shop->id, 'stock' => 20, 'price' => 500]);
        $this->createCheckoutNetwork($shop, ['Taguig City' => 'Metro Manila']);

        return [$buyer, $shop, $product];
    }

    private function sale(Product $product, string $kind, string $price, ?int $quantity = null): ProductSale
    {
        return $product->sales()->create(['kind' => $kind, 'name' => 'Bagoo member sale', 'price' => $price,
            'starts_at' => now()->subHour(), 'ends_at' => now()->addHour(), 'quantity' => $quantity]);
    }

    private function bag(User $buyer, Product $product, int $quantity, array $options = []): Cart
    {
        $this->actingAs($buyer)->post(route('cart.store'), ['product_id' => $product->id, 'quantity' => $quantity, ...$options])->assertSessionHasNoErrors();

        return Cart::where('user_id', $buyer->id)->firstOrFail();
    }

    private function input(User $buyer, Cart $cart): array
    {
        return ['checkout_token' => $this->checkoutToken($buyer), 'item_ids' => $cart->items()->pluck('id')->all(),
            'recipient_name' => 'Ana Santos', 'recipient_phone' => '09171234567', 'shipping_address' => '100 Main Street',
            'shipping_city' => 'Taguig City', 'shipping_province' => 'Metro Manila', 'destination_barangay' => 'Fort Bonifacio', 'shipping_postal_code' => '1634', 'payment_method' => 'cod'];
    }

    public function test_store_sale_units_apply_across_quantity_and_cancel_once(): void
    {
        [$buyer, , $product] = $this->fixture();
        $store = $this->sale($product, 'store', '399.00');
        $cart = $this->bag($buyer, $product, 3);
        $ids = $cart->items()->pluck('id')->all();
        $this->postJson(route('buyer.vouchers.apply'), ['item_ids' => $ids])->assertOk()
            ->assertJsonPath('quote.subtotal', 1197)->assertJsonPath('quote.items.0.price_segments.0.quantity', 3)
            ->assertJsonPath('quote.items.0.price_segments.0.unit_price', 399);
        $input = $this->input($buyer, $cart);
        $this->post(route('checkout.store'), [...$input, 'unit_price' => 1])->assertSessionHasNoErrors();
        $order = Order::firstOrFail();
        $this->assertSame('1197.00', $order->subtotal);
        $this->assertSame([3], $order->items()->orderBy('id')->pluck('quantity')->all());
        $this->assertSame([$store->id], $order->items()->orderBy('id')->pluck('product_sale_id')->all());
        $this->assertSame(17, $product->fresh()->stock);
        $store->update(['price' => 200]);
        $this->post(route('checkout.store'), $input)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame('1197.00', $order->fresh()->subtotal);
        $this->post(route('buyer.orders.cancel', $order), ['reason' => 'Ordered by mistake'])->assertSessionHas('success');
        $this->post(route('buyer.orders.cancel', $order), ['reason' => 'Ordered by mistake'])->assertSessionHas('success');
        $this->assertSame(20, $product->fresh()->stock);
        $this->assertSame('399.00', $order->items()->orderBy('id')->first()->unit_price);
    }

    public function test_start_end_and_price_edit_require_fresh_consent(): void
    {
        [$buyer, , $product] = $this->fixture();
        $cart = $this->bag($buyer, $product, 2);
        $store = $this->sale($product, 'store', '350.00');
        $input = $this->input($buyer, $cart);
        $store->update(['price' => 325]);
        $this->postJson(route('checkout.store'), $input)->assertUnprocessable()->assertJsonValidationErrors('checkout_token');
        $store->update(['starts_at' => now()->addMinutes(2)]);
        $input = $this->input($buyer, $cart);
        $this->travel(3)->minutes();
        $this->postJson(route('checkout.store'), $input)->assertUnprocessable()->assertJsonValidationErrors('checkout_token');
        $store->update(['ends_at' => now()->addMinute()]);
        $input = $this->input($buyer, $cart);
        $this->travel(2)->minutes();
        $this->postJson(route('checkout.store'), $input)->assertUnprocessable()->assertJsonValidationErrors('checkout_token');
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(20, $product->fresh()->stock);
    }

    public function test_failure_after_checkout_rolls_back_order_and_stock(): void
    {
        [$buyer, , $product] = $this->fixture();
        $this->sale($product, 'store', '299.00');
        $cart = $this->bag($buyer, $product, 2);
        $input = $this->input($buyer, $cart);
        Event::listen('eloquent.updated: '.Product::class, fn () => throw new RuntimeException('Private storage failure.'));
        $this->post(route('checkout.store'), $input)->assertSessionHas('error', 'We could not save your order. Please try again with the same checkout details.');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('checkout_submissions', 0);
        $this->assertSame(20, $product->fresh()->stock);
        $this->assertSame(2, $cart->items()->first()->quantity);
    }

    public function test_voucher_minimum_uses_charged_sale_prices_and_sale_reductions_are_not_platform_funding(): void
    {
        [$buyer, $shop, $product] = $this->fixture();
        $this->sale($product, 'store', '299.99');
        $cart = $this->bag($buyer, $product, 1);
        Voucher::factory()->funded()->create(['shop_id' => $shop->id, 'code' => 'SALE50', 'discount_type' => 'fixed', 'discount_value' => 50, 'min_spend' => 300]);
        $this->postJson(route('buyer.vouchers.apply'), ['item_ids' => $cart->items()->pluck('id')->all(), 'code' => 'SALE50'])->assertUnprocessable()->assertJsonPath('quote.subtotal', 299.99)->assertJsonPath('quote.discount', 0);
        $this->assertDatabaseCount('voucher_redemptions', 0);
        $this->assertDatabaseCount('voucher_funding_entries', 0);
    }

    public function test_real_matrix_surcharge_is_added_to_each_priced_segment(): void
    {
        [$buyer, , $product] = $this->fixture();
        $product->update(['variants' => ['colors' => [['name' => 'Red']], 'sizes' => [['name' => 'Large', 'stock' => 20, 'extra_price' => 1]],
            'combinations' => [['color' => 'Red', 'size' => 'Large', 'stock' => 20, 'extra_price' => '25.50']]]]);
        $this->sale($product, 'store', '299.00');
        $cart = $this->bag($buyer, $product, 2, ['color' => 'Red', 'size' => 'Large']);
        $this->post(route('checkout.store'), $this->input($buyer, $cart))->assertSessionHasNoErrors();
        $order = Order::firstOrFail();
        $this->assertSame(['324.50'], $order->items()->orderBy('id')->pluck('unit_price')->all());
        $this->assertSame('649.00', $order->subtotal);
        $this->post(route('buyer.orders.cancel', $order), ['reason' => 'Ordered by mistake'])->assertSessionHas('success');
        $this->assertSame(20, $product->fresh()->variants['combinations'][0]['stock']);
    }

    public function test_catalogue_prices_filter_and_sort_by_the_current_offer(): void
    {
        [, , $product] = $this->fixture();
        $this->sale($product, 'store', '299.00');
        $this->get(route('products.index', ['min_price' => '298', 'max_price' => '300', 'sort' => 'price_asc']))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->has('products.data', 1)->where('products.data.0.id', $product->id)->where('products.data.0.sale_offer.effective_price', 299)->missing('products.data.0.sales'));
    }

    public function test_original_displayed_quote_remains_bound_when_an_offer_preview_crosses_the_end_time(): void
    {
        [$buyer, , $product] = $this->fixture();
        $sale = $this->sale($product, 'store', '299.00');
        $sale->update(['ends_at' => now()->addMinute()]);
        $cart = $this->bag($buyer, $product, 1);
        $items = $cart->items()->with('product.shop')->get();
        $first = app(CheckoutQuoteService::class)->quote($items);
        $this->assertSame(299, $first['subtotal']);
        $this->travel(2)->minutes();
        $later = app(CheckoutQuoteService::class)->quote($items);
        $this->assertSame(500, $later['subtotal']);
        $token = app(CheckoutSubmissionService::class)->issue($buyer, $cart, $items);
        $input = $this->input($buyer, $cart);
        $input['checkout_token'] = $token;
        $this->postJson(route('checkout.store'), $input)->assertUnprocessable()->assertJsonValidationErrors('checkout_token');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_sale_end_between_the_initial_price_check_and_final_quote_never_charges_the_higher_price(): void
    {
        [$buyer, , $product] = $this->fixture();
        $sale = $this->sale($product, 'store', '299.00');
        $sale->update(['ends_at' => now()->addMinute()]);
        $cart = $this->bag($buyer, $product, 1);
        $input = $this->input($buyer, $cart);
        $quotes = \Mockery::mock(CheckoutQuoteService::class)->makePartial();
        $quotes->shouldReceive('quote')->once()->andReturnUsing(function (...$arguments) {
            $this->travel(2)->minutes();

            return (new CheckoutQuoteService)->quote(...$arguments);
        });
        app()->instance(CheckoutQuoteService::class, $quotes);
        $this->postJson(route('checkout.store'), $input)->assertUnprocessable()->assertJsonValidationErrors('checkout_token');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('checkout_submissions', 0);
        $this->assertSame(20, $product->fresh()->stock);
    }
}

<?php

namespace Tests\Feature\Buyer;

use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\Orders\CheckoutQuoteService;
use App\Services\Orders\CheckoutSubmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithCheckoutNetwork;
use Tests\Concerns\InteractsWithCheckoutSubmission;
use Tests\TestCase;

class BuyerCheckoutConsentTest extends TestCase
{
    use InteractsWithCheckoutNetwork;
    use InteractsWithCheckoutSubmission;
    use RefreshDatabase;

    private function checkout(): array
    {
        $this->withoutVite();
        $buyer = User::factory()->create();
        $shop = Shop::factory()->approved()->create(['city' => 'Taguig City']);
        $product = Product::factory()->create(['shop_id' => $shop->id, 'price' => '100.00', 'stock' => 10,
            'variants' => ['sizes' => [['name' => 'Large', 'stock' => 10, 'extra_price' => 5]]]]);
        $this->createCheckoutNetwork($shop, ['Taguig City' => 'Metro Manila']);
        $this->actingAs($buyer)->post(route('cart.store'), ['product_id' => $product->id, 'quantity' => 3, 'size' => 'Large'])->assertSessionHasNoErrors();
        $cart = Cart::where('user_id', $buyer->id)->firstOrFail();
        $line = $cart->items()->firstOrFail();
        $input = ['checkout_token' => $this->checkoutToken($buyer), 'item_ids' => [$line->id],
            'recipient_name' => 'Ana Santos', 'recipient_phone' => '09171234567', 'shipping_address' => '100 Main Street',
            'shipping_city' => 'Taguig City', 'shipping_province' => 'Metro Manila', 'shipping_postal_code' => '1634',
            'destination_barangay' => 'Fort Bonifacio', 'payment_method' => 'cod'];

        return [$buyer, $cart, $product, $line, $input];
    }

    public static function priceChanges(): array
    {
        return [['base'], ['size']];
    }

    #[DataProvider('priceChanges')]
    public function test_changed_prices_require_fresh_consent_but_completed_submissions_still_replay(string $kind): void
    {
        [$buyer, $cart, $product, $line, $input] = $this->checkout();
        if ($kind === 'base') {
            $product->update(['price' => '120.00']);
        } else {
            $product->update(['variants' => ['sizes' => [['name' => 'Large', 'stock' => 10, 'extra_price' => 25]]]]);
        }
        $this->postJson(route('checkout.store'), $input)->assertUnprocessable()->assertJsonValidationErrors('checkout_token');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('checkout_submissions', 0);
        $this->assertSame(10, $product->fresh()->stock);
        $this->assertSame(3, $line->fresh()->quantity);
        $this->get(route('checkout.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('quote.subtotal', 375)->where('checkoutToken', fn ($token) => str_starts_with($token, 'v2.')));
        $input['checkout_token'] = $this->checkoutToken($buyer, $cart);
        $this->post(route('checkout.store'), $input)->assertSessionHasNoErrors()->assertRedirect(route('buyer.orders.index'));
        $order = Order::firstOrFail();
        $this->assertSame('375.00', $order->subtotal);
        $product->update(['price' => '150.00']);
        $this->post(route('checkout.store'), $input)->assertSessionHasNoErrors()->assertRedirect(route('buyer.orders.index'));
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame('375.00', $order->fresh()->subtotal);
        $this->assertSame(7, $product->fresh()->stock);
    }

    public function test_buy_now_purchases_exact_requested_quantity_and_preserves_the_existing_matching_bag_amount(): void
    {
        [$buyer, $cart, $product, $line, $input] = $this->checkout();
        $this->post(route('cart.store'), ['product_id' => $product->id, 'quantity' => 2, 'size' => 'Large', 'buy_now' => true])
            ->assertRedirect(route('checkout.index', ['items' => $line->id, 'quantity' => 2]));
        $this->assertSame(5, $line->fresh()->quantity);
        $this->get(route('checkout.index', ['items' => $line->id, 'quantity' => 2]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('items.0.quantity', 2)->where('itemQuantities.'.$line->id, 2)->where('quote.subtotal', 210));
        $input['item_quantities'] = [$line->id => 2];
        $input['checkout_token'] = $this->checkoutToken($buyer, $cart);
        $this->postJson(route('buyer.vouchers.apply'), $input)->assertOk()->assertJsonPath('quote.subtotal', 210);
        $this->postJson(route('buyer.vouchers.choices'), $input)->assertOk();
        $this->postJson(route('buyer.vouchers.best'), $input)->assertOk();
        $this->post(route('checkout.store'), $input)->assertSessionHasNoErrors()->assertRedirect(route('buyer.orders.index'));
        $this->assertSame(2, Order::firstOrFail()->items()->firstOrFail()->quantity);
        $this->assertSame('210.00', Order::firstOrFail()->subtotal);
        $this->assertSame(3, $line->fresh()->quantity);
        $this->assertSame(8, $product->fresh()->stock);
        $this->post(route('checkout.store'), $input)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(3, $line->fresh()->quantity);
        $this->assertSame(8, $product->fresh()->stock);
    }

    public function test_confirmation_is_bound_to_displayed_prices_even_when_a_price_changes_during_page_preparation(): void
    {
        [$buyer, $cart, $product, $line, $input] = $this->checkout();
        $shown = app(CheckoutQuoteService::class)->selectedItems($buyer, [$line->id]);
        $product->update(['price' => '120.00']);
        $input['checkout_token'] = app(CheckoutSubmissionService::class)->issue($buyer, $cart, $shown);
        $this->postJson(route('checkout.store'), $input)->assertUnprocessable()->assertJsonValidationErrors('checkout_token');
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(10, $product->fresh()->stock);
    }

    public static function invalidQuantities(): array
    {
        return [[0], [4], ['1.5'], ['1e1'], ['１'], ['foreign']];
    }

    #[DataProvider('invalidQuantities')]
    public function test_forged_partial_quantities_cannot_exceed_or_replace_the_owned_selection(mixed $quantity): void
    {
        [, , $product, $line, $input] = $this->checkout();
        $input['item_quantities'] = $quantity === 'foreign' ? [$line->id + 100 => 1] : [$line->id => $quantity];
        $response = $this->postJson(route('checkout.store'), $input);
        $response->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(10, $product->fresh()->stock);
        $this->assertSame(3, $line->fresh()->quantity);
    }

    public static function unsupportedPayments(): array
    {
        return [['card'], ['bank_transfer'], ['e_wallet'], [null]];
    }

    #[DataProvider('unsupportedPayments')]
    public function test_cod_is_required_and_an_unsupported_method_never_creates_a_cod_order(mixed $method): void
    {
        [, , $product, $line, $input] = $this->checkout();
        $input['payment_method'] = $method;
        $this->postJson(route('checkout.store'), $input)->assertUnprocessable()->assertJsonValidationErrors('payment_method');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('checkout_submissions', 0);
        $this->assertSame(10, $product->fresh()->stock);
        $this->assertSame(3, $line->fresh()->quantity);
    }
}

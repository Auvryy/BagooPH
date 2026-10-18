<?php

namespace Tests\Feature\Buyer;

use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\Commerce\BuyAgainService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\InteractsWithCheckoutNetwork;
use Tests\Concerns\InteractsWithCheckoutSubmission;
use Tests\TestCase;

class BuyerVariantPricingTest extends TestCase
{
    use InteractsWithCheckoutNetwork;
    use InteractsWithCheckoutSubmission;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function selection(): array
    {
        $buyer = User::factory()->create();
        $shop = Shop::factory()->approved()->create(['city' => 'Taguig City']);
        $product = Product::factory()->create(['shop_id' => $shop->id, 'price' => '500.00', 'stock' => 10,
            'variants' => ['colors' => [['name' => 'Red', 'in_stock' => true], ['name' => 'Blue', 'in_stock' => true]],
                'sizes' => [['name' => 'Large', 'stock' => 10, 'extra_price' => 25.50]]]]);

        return [$buyer, $shop, $product];
    }

    public function test_variant_price_is_consistent_in_bag_quote_order_and_buy_again(): void
    {
        [$buyer, $shop, $product] = $this->selection();
        $this->createCheckoutNetwork($shop, ['Taguig City' => 'Metro Manila']);
        $this->actingAs($buyer)->post(route('cart.store'), ['product_id' => $product->id, 'quantity' => 2, 'color' => 'Red', 'size' => 'Large', 'unit_price' => 1])->assertSessionHasNoErrors();
        $cart = Cart::where('user_id', $buyer->id)->firstOrFail();
        $line = $cart->items()->firstOrFail();
        $this->assertSame('525.50', $line->unit_price);
        $this->get(route('buyer.cart'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('items.0.unit_price', '525.50')->where('quote.subtotal', 1051));
        $this->getJson(route('buyer.cart').'?'.http_build_query(['item_ids' => [$line->id]]))->assertOk()
            ->assertJsonPath('quote.items.0.unit_price', 525.5)->assertJsonPath('quote.total', 1101);
        $this->post(route('checkout.store'), [
            'checkout_token' => $this->checkoutToken($buyer), 'item_ids' => [$line->id],
            'recipient_name' => 'Ana Santos', 'recipient_phone' => '09171234567', 'shipping_address' => '100 Main Street',
            'shipping_city' => 'Taguig City', 'shipping_province' => 'Metro Manila', 'destination_barangay' => 'Fort Bonifacio',
            'shipping_postal_code' => '1634', 'payment_method' => 'cod', 'total' => 1,
        ])->assertSessionHasNoErrors()->assertRedirect(route('buyer.orders.index'));
        $order = Order::with('items')->firstOrFail();
        $this->assertSame('525.50', $order->items->first()->unit_price);
        $this->assertSame('1051.00', $order->subtotal);
        $this->assertSame('1051.00', $order->items->first()->subtotal);
        $this->assertSame(8, $product->fresh()->stock);
        $this->assertSame(8, $product->fresh()->variants['sizes'][0]['stock']);
        $order->update(['status' => 'completed']);
        $preview = app(BuyAgainService::class)->preview($buyer, $order);
        $this->assertSame('525.50', $preview['items'][0]['current_unit_price']);
    }

    public function test_removed_or_sold_out_variant_is_unselectable_without_blocking_other_bag_items(): void
    {
        [$buyer, $shop, $product] = $this->selection();
        $this->actingAs($buyer)->post(route('cart.store'), ['product_id' => $product->id, 'quantity' => 1, 'color' => 'Red', 'size' => 'Large'])->assertSessionHasNoErrors();
        $product->update(['variants' => ['colors' => [['name' => 'Blue', 'in_stock' => true]], 'sizes' => [['name' => 'Large', 'stock' => 0, 'extra_price' => 25.5]]]]);
        $other = Product::factory()->create(['shop_id' => $shop->id, 'price' => 100]);
        $this->post(route('cart.store'), ['product_id' => $other->id, 'quantity' => 1])->assertSessionHasNoErrors();
        $this->get(route('buyer.cart'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('items.0.available_for_purchase', true)->where('items.1.available_for_purchase', false)->where('quote.subtotal', 100));
        $cart = Cart::where('user_id', $buyer->id)->firstOrFail();
        $unavailable = $cart->items()->where('product_id', $product->id)->firstOrFail();
        $this->getJson(route('buyer.cart').'?'.http_build_query(['item_ids' => [$unavailable->id]]))->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_missing_or_forged_options_do_not_add_items_and_buy_now_selects_the_saved_line_explicitly(): void
    {
        [$buyer, $shop, $product] = $this->selection();
        $this->actingAs($buyer);
        foreach ([[], ['color' => 'Red'], ['color' => 'Missing', 'size' => 'Large']] as $options) {
            $this->post(route('cart.store'), ['product_id' => $product->id, 'quantity' => 1, ...$options])->assertSessionHasErrors('quantity');
        }
        $this->assertDatabaseCount('cart_items', 0);
        $other = Product::factory()->create(['shop_id' => $shop->id]);
        $this->post(route('cart.store'), ['product_id' => $other->id, 'quantity' => 2])->assertSessionHasNoErrors();
        $this->post(route('cart.store'), ['product_id' => $product->id, 'quantity' => 1, 'color' => 'Red', 'size' => 'Large', 'buy_now' => true])
            ->assertSessionHasNoErrors()->assertRedirect(route('checkout.index', ['items' => Cart::where('user_id', $buyer->id)->firstOrFail()->items()->where('product_id', $product->id)->firstOrFail()->id]));
        $this->assertDatabaseHas('cart_items', ['product_id' => $other->id, 'quantity' => 2]);
        $this->assertDatabaseCount('orders', 0);
    }
}

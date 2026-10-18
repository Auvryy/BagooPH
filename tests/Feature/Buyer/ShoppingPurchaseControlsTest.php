<?php

namespace Tests\Feature\Buyer;

use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\Commerce\PurchasePolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\InteractsWithCheckoutNetwork;
use Tests\Concerns\InteractsWithCheckoutSubmission;
use Tests\TestCase;

class ShoppingPurchaseControlsTest extends TestCase
{
    use InteractsWithCheckoutNetwork;
    use InteractsWithCheckoutSubmission;
    use RefreshDatabase;

    private function fixture(): array
    {
        $this->withoutVite();
        $buyer = User::factory()->create();
        $shop = Shop::factory()->approved()->create(['city' => 'Taguig City']);
        $product = Product::factory()->create(['shop_id' => $shop->id, 'price' => 100, 'stock' => 20, 'purchase_limit' => 2]);

        return [$buyer, $shop, $product];
    }

    private function input(User $buyer): array
    {
        $cart = Cart::where('user_id', $buyer->id)->firstOrFail();

        return ['checkout_token' => $this->checkoutToken($buyer), 'item_ids' => $cart->items()->pluck('id')->all(),
            'recipient_name' => 'Ana Santos', 'recipient_phone' => '09171234567', 'shipping_address' => '100 Main Street',
            'shipping_city' => 'Taguig City', 'shipping_province' => 'Metro Manila', 'destination_barangay' => 'Fort Bonifacio',
            'shipping_postal_code' => '1634', 'payment_method' => 'cod'];
    }

    public function test_vacation_is_owned_and_keeps_products_visible_without_changing_approval(): void
    {
        [$buyer, $shop, $product] = $this->fixture();
        $review = $shop->review_decision_id;
        $this->actingAs($shop->user)->post(route('seller.settings.update'), ['vacation_mode' => true])->assertSessionHasNoErrors();
        $this->assertTrue($shop->fresh()->vacation_mode);
        $this->assertSame($review, $shop->fresh()->review_decision_id);
        $this->assertTrue(Product::availableForSale()->whereKey($product->id)->exists());
        $this->actingAs($buyer)->get(route('buyer.products.show', $product->slug))->assertOk()->assertInertia(fn (Assert $p) => $p->where('product.shop.vacation_mode', true));
        $this->post(route('cart.store'), ['product_id' => $product->id, 'quantity' => 1])->assertSessionHasErrors('quantity');
        $this->assertDatabaseCount('cart_items', 0);
        $foreign = Shop::factory()->approved()->create();
        $this->actingAs($foreign->user)->post(route('seller.settings.update'), ['shop_id' => $shop->id, 'vacation_mode' => false])->assertForbidden();
        $this->assertTrue($shop->fresh()->vacation_mode);
    }

    public function test_existing_bag_and_stale_checkout_are_blocked_on_vacation_then_recover(): void
    {
        [$buyer, $shop, $product] = $this->fixture();
        $this->createCheckoutNetwork($shop, ['Taguig City' => 'Metro Manila']);
        $this->actingAs($buyer)->post(route('cart.store'), ['product_id' => $product->id, 'quantity' => 1]);
        $input = $this->input($buyer);
        $shop->update(['vacation_mode' => true]);
        $this->get(route('buyer.cart'))->assertInertia(fn (Assert $p) => $p->where('items.0.available_for_purchase', false));
        $this->post(route('checkout.store'), $input)->assertSessionHas('error', fn ($message) => str_contains($message, 'on vacation'));
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(20, $product->fresh()->stock);
        $shop->update(['vacation_mode' => false]);
        $this->post(route('checkout.store'), $this->input($buyer))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_cap_counts_orders_and_cancellation_restores_it_once(): void
    {
        [$buyer, $shop, $product] = $this->fixture();
        $this->createCheckoutNetwork($shop, ['Taguig City' => 'Metro Manila']);
        $this->actingAs($buyer)->post(route('cart.store'), ['product_id' => $product->id, 'quantity' => 2])->assertSessionHasNoErrors();
        $this->post(route('checkout.store'), $this->input($buyer))->assertSessionHasNoErrors();
        $this->post(route('cart.store'), ['product_id' => $product->id, 'quantity' => 1])->assertSessionHasErrors('quantity');
        $this->assertSame(0, app(PurchasePolicyService::class)->remaining($product, $buyer->id));
        $order = Order::firstOrFail();
        $this->post(route('buyer.orders.cancel', $order), ['reason' => 'Ordered by mistake'])->assertSessionHas('success');
        $this->post(route('buyer.orders.cancel', $order), ['reason' => 'Ordered by mistake'])->assertSessionHas('success');
        $this->assertSame(2, app(PurchasePolicyService::class)->remaining($product, $buyer->id));
        $this->post(route('cart.store'), ['product_id' => $product->id, 'quantity' => 2])->assertSessionHasNoErrors();
        $this->assertSame(20, $product->fresh()->stock);
    }

    public function test_stale_cap_and_separate_buyer_are_checked_from_retained_orders(): void
    {
        [$buyer, $shop, $product] = $this->fixture();
        $this->createCheckoutNetwork($shop, ['Taguig City' => 'Metro Manila']);
        $this->actingAs($buyer)->post(route('cart.store'), ['product_id' => $product->id, 'quantity' => 2]);
        $input = $this->input($buyer);
        $otherOrder = Order::factory()->create(['buyer_id' => $buyer->id, 'status' => 'completed']);
        OrderItem::factory()->create(['order_id' => $otherOrder->id, 'product_id' => $product->id, 'shop_id' => $shop->id, 'quantity' => 1]);
        $this->post(route('checkout.store'), $input)->assertSessionHas('error', fn ($message) => str_contains($message, 'limit of 2'));
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(20, $product->fresh()->stock);
        $this->actingAs(User::factory()->create())->post(route('cart.store'), ['product_id' => $product->id, 'quantity' => 2])->assertSessionHasNoErrors();
    }

    public function test_seller_cap_validation_and_removal(): void
    {
        [, $shop, $product] = $this->fixture();
        $payload = ['name' => $product->name, 'price' => $product->price, 'stock' => 20, 'description' => 'A durable everyday item.', 'status' => 'active'];
        $this->actingAs($shop->user);
        foreach ([0, -1, 1.5, 1000001, 'abc'] as $invalid) {
            $this->put(route('seller.products.update', $product), [...$payload, 'purchase_limit' => $invalid])->assertSessionHasErrors('purchase_limit');
        }
        $this->put(route('seller.products.update', $product), [...$payload, 'purchase_limit' => null])->assertSessionHasNoErrors();
        $this->assertNull($product->fresh()->purchase_limit);
    }

    public function test_regular_prices_remain_valid_inputs_for_the_sale_and_money_services(): void
    {
        [, $shop, $product] = $this->fixture();
        $payload = ['name' => $product->name, 'price' => 100, 'stock' => 20, 'description' => 'A durable everyday item.', 'status' => 'active'];
        $this->actingAs($shop->user);
        foreach ([0, '1.001', '100000000.00', '1e3'] as $invalid) {
            $this->put(route('seller.products.update', $product), [...$payload, 'price' => $invalid])->assertSessionHasErrors('price');
            $this->assertSame('100.00', $product->fresh()->price);
        }
        $this->put(route('seller.products.update', $product), [...$payload, 'price' => 250])->assertSessionHasNoErrors();
        $this->assertSame('250.00', $product->fresh()->price);
    }

    public function test_vacation_does_not_stop_the_seller_preparing_an_existing_order(): void
    {
        [$buyer, $shop, $product] = $this->fixture();
        $this->createCheckoutNetwork($shop, ['Taguig City' => 'Metro Manila']);
        $this->actingAs($buyer)->post(route('cart.store'), ['product_id' => $product->id, 'quantity' => 1])->assertSessionHasNoErrors();
        $this->post(route('checkout.store'), $this->input($buyer))->assertSessionHasNoErrors();
        $order = Order::firstOrFail();
        $shop->update(['vacation_mode' => true]);
        $this->actingAs($shop->user)->get(route('seller.orders.index'))->assertOk();
        $this->post(route('seller.orders.acceptAndPack', $order))->assertSessionHasNoErrors();
        $this->assertSame('preparing', $order->fresh()->status);
        $this->assertTrue($shop->fresh()->vacation_mode);
        $this->assertSame(19, $product->fresh()->stock);
    }
}

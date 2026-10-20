<?php

namespace Tests\Feature\Buyer;

use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\Commerce\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCheckoutNetwork;
use Tests\Concerns\InteractsWithCheckoutSubmission;
use Tests\TestCase;

class CombinationInventoryTest extends TestCase
{
    use InteractsWithCheckoutNetwork;
    use InteractsWithCheckoutSubmission;
    use RefreshDatabase;

    private function variants(): array
    {
        return ['colors' => [['name' => 'Red', 'in_stock' => true], ['name' => 'Blue', 'in_stock' => true]],
            'sizes' => [['name' => 'Small', 'stock' => 99, 'extra_price' => 0], ['name' => 'Large', 'stock' => 99, 'extra_price' => 0]],
            'combinations' => [
                ['color' => 'Red', 'size' => 'Small', 'stock' => 0, 'extra_price' => 0],
                ['color' => 'Red', 'size' => 'Large', 'stock' => 2, 'extra_price' => 10.25],
                ['color' => 'Blue', 'size' => 'Small', 'stock' => 3, 'extra_price' => 0],
                ['color' => 'Blue', 'size' => 'Large', 'stock' => 4, 'extra_price' => 20],
            ]];
    }

    private function fixture(): array
    {
        $this->withoutVite();
        $buyer = User::factory()->create();
        $shop = Shop::factory()->approved()->create(['city' => 'Taguig City']);
        $product = Product::factory()->create(['shop_id' => $shop->id, 'stock' => 9, 'price' => 100, 'variants' => $this->variants()]);

        return [$buyer, $shop, $product];
    }

    private function payload(Product $product, array $variants): array
    {
        return ['weight_kg' => $product->weight_kg, 'category_id' => $product->category_id, 'name' => $product->name, 'price' => 100, 'stock' => 999, 'description' => 'Durable everyday item.', 'status' => 'active', 'variants' => json_encode($variants)];
    }

    public function test_only_the_selected_combination_is_sold_and_cancellation_restores_it_once(): void
    {
        [$buyer, $shop, $product] = $this->fixture();
        $this->createCheckoutNetwork($shop, ['Taguig City' => 'Metro Manila']);
        $this->actingAs($buyer)->post(route('cart.store'), ['product_id' => $product->id, 'quantity' => 1, 'color' => 'Red', 'size' => 'Small'])->assertSessionHasErrors('quantity');
        $this->post(route('cart.store'), ['product_id' => $product->id, 'quantity' => 3, 'color' => 'Red', 'size' => 'Large'])->assertSessionHasErrors('quantity');
        $this->post(route('cart.store'), ['product_id' => $product->id, 'quantity' => 2, 'color' => 'Red', 'size' => 'Large'])->assertSessionHasNoErrors();
        $cart = Cart::where('user_id', $buyer->id)->firstOrFail();
        $this->assertSame('110.25', $cart->items()->first()->unit_price);
        $this->post(route('checkout.store'), ['checkout_token' => $this->checkoutToken($buyer), 'item_ids' => $cart->items()->pluck('id')->all(),
            'recipient_name' => 'Ana Santos', 'recipient_phone' => '09171234567', 'shipping_address' => '100 Main Street',
            'shipping_city' => 'Taguig City', 'shipping_province' => 'Metro Manila', 'destination_barangay' => 'Fort Bonifacio', 'shipping_postal_code' => '1634', 'payment_method' => 'cod'])->assertSessionHasNoErrors();
        $order = Order::firstOrFail();
        $this->assertSame('220.50', $order->subtotal);
        $rows = $product->fresh()->variants['combinations'];
        $this->assertSame([0, 0, 3, 4], array_column($rows, 'stock'));
        $this->assertSame(7, $product->fresh()->stock);
        $this->post(route('buyer.orders.cancel', $order), ['reason' => 'Ordered by mistake'])->assertSessionHas('success');
        $this->post(route('buyer.orders.cancel', $order), ['reason' => 'Ordered by mistake'])->assertSessionHas('success');
        $this->assertSame([0, 2, 3, 4], array_column($product->fresh()->variants['combinations'], 'stock'));
        $this->assertSame(9, $product->fresh()->stock);
        $this->assertSame('110.25', $order->items()->first()->unit_price);
    }

    public function test_seller_stock_is_derived_and_quick_total_stock_edit_cannot_desynchronize_it(): void
    {
        [, $shop, $product] = $this->fixture();
        $variants = $this->variants();
        $variants['combinations'][0]['stock'] = 5;
        $this->actingAs($shop->user)->put(route('seller.products.update', $product), $this->payload($product, $variants))->assertSessionHasNoErrors();
        $this->assertSame(14, $product->fresh()->stock);
        $this->patch(route('seller.products.stock.update', $product), ['mode' => 'add', 'quantity' => 4])->assertSessionHasErrors('quantity');
        $this->assertSame(14, $product->fresh()->stock);
    }

    public function test_missing_duplicate_unknown_and_invalid_combination_values_are_rejected(): void
    {
        [, $shop, $product] = $this->fixture();
        $base = $this->variants();
        $missing = $base;
        array_pop($missing['combinations']);
        $duplicate = $base;
        $duplicate['combinations'][0] = $duplicate['combinations'][1];
        $unknown = $base;
        $unknown['combinations'][0]['color'] = 'Missing';
        $fraction = $base;
        $fraction['combinations'][0]['stock'] = 1.5;
        $negative = $base;
        $negative['combinations'][0]['stock'] = -1;
        $price = $base;
        $price['combinations'][0]['extra_price'] = '1.001';
        $this->actingAs($shop->user);
        foreach ([$missing, $duplicate, $unknown, $fraction, $negative, $price] as $invalid) {
            $this->put(route('seller.products.update', $product), $this->payload($product, $invalid))->assertSessionHasErrors();
            $this->assertSame(9, $product->fresh()->stock);
        }
        $foreign = Shop::factory()->approved()->create();
        $this->actingAs($foreign->user)->put(route('seller.products.update', $product), $this->payload($product, $base))->assertForbidden();
    }

    public function test_active_order_options_and_stock_mode_are_retained_until_cancellation_is_possible(): void
    {
        [$buyer, $shop, $product] = $this->fixture();
        $order = Order::factory()->create(['buyer_id' => $buyer->id, 'status' => 'placed']);
        OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $product->id, 'shop_id' => $shop->id, 'color' => 'Red', 'size' => 'Large']);
        $legacy = $this->variants();
        unset($legacy['combinations']);
        $this->actingAs($shop->user)->put(route('seller.products.update', $product), $this->payload($product, $legacy))->assertSessionHasErrors('variants');
        $removed = $this->variants();
        $removed['colors'] = [$removed['colors'][1]];
        $removed['combinations'] = array_slice($removed['combinations'], 2);
        $this->put(route('seller.products.update', $product), $this->payload($product, $removed))->assertSessionHasErrors('variants');
        $order->update(['status' => 'cancelled']);
        $this->put(route('seller.products.update', $product), $this->payload($product, $legacy))->assertSessionHasNoErrors();
    }

    public function test_two_colors_no_longer_share_the_same_size_stock(): void
    {
        [$buyer, , $product] = $this->fixture();
        $this->actingAs($buyer)->post(route('cart.store'), ['product_id' => $product->id, 'quantity' => 2, 'color' => 'Red', 'size' => 'Large'])->assertSessionHasNoErrors();
        $this->post(route('cart.store'), ['product_id' => $product->id, 'quantity' => 4, 'color' => 'Blue', 'size' => 'Large'])->assertSessionHasNoErrors();
        $items = Cart::where('user_id', $buyer->id)->firstOrFail()->items()->get();
        app(InventoryService::class)->assertCheckoutAvailability($items, collect([$product->id => $product]), $buyer->id);
        $this->assertSame([2, 4], $items->pluck('quantity')->all());
    }
}

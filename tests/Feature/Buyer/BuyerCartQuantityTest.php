<?php

namespace Tests\Feature\Buyer;

use App\Models\Cart;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BuyerCartQuantityTest extends TestCase
{
    use RefreshDatabase;

    public function test_add_rejects_a_quantity_above_the_remaining_stock_without_false_success(): void
    {
        [$buyer, $product, $cart] = $this->buyerProductAndCart(stock: 20);
        $item = $cart->items()->create([
            'product_id' => $product->id,
            'quantity' => 19,
            'unit_price' => $product->price,
        ]);

        $response = $this->actingAs($buyer)->post(route('cart.store'), [
            'product_id' => $product->id,
            'quantity' => 2,
        ]);

        $response->assertSessionHasErrors('quantity');
        $response->assertSessionMissing('success');
        $this->assertSame(19, $item->fresh()->quantity);
        $this->assertSame(20, $product->fresh()->stock);
    }

    public function test_add_accepts_the_exact_remaining_quantity_and_does_not_reserve_stock(): void
    {
        [$buyer, $product, $cart] = $this->buyerProductAndCart(stock: 20);
        $item = $cart->items()->create([
            'product_id' => $product->id,
            'quantity' => 19,
            'unit_price' => $product->price,
        ]);

        $response = $this->actingAs($buyer)->post(route('cart.store'), [
            'product_id' => $product->id,
            'quantity' => 1,
        ]);

        $response->assertSessionHas('success');
        $response->assertSessionDoesntHaveErrors('quantity');
        $this->assertSame(20, $item->fresh()->quantity);
        $this->assertSame(20, $product->fresh()->stock);
    }

    public function test_add_rejects_a_zero_stock_product_with_a_clear_error(): void
    {
        [$buyer, $product, $cart] = $this->buyerProductAndCart(stock: 0);

        $this->actingAs($buyer)
            ->post(route('cart.store'), [
                'product_id' => $product->id,
                'quantity' => 1,
            ])
            ->assertSessionHasErrors([
                'quantity' => 'This product is currently out of stock.',
            ])
            ->assertSessionMissing('success');

        $this->assertSame(0, $cart->items()->count());
        $this->assertSame(0, $product->fresh()->stock);
    }

    public function test_cart_limit_counts_every_variant_line_for_the_same_product(): void
    {
        [$buyer, $product, $cart] = $this->buyerProductAndCart(stock: 20, variants: [
            'colors' => [
                ['id' => 'red', 'name' => 'Red', 'hex' => '#ff0000', 'in_stock' => true],
                ['id' => 'blue', 'name' => 'Blue', 'hex' => '#0000ff', 'in_stock' => true],
            ],
            'sizes' => [
                ['id' => 'medium', 'name' => 'M', 'extra_price' => 0, 'stock' => 20],
            ],
        ]);
        $cart->items()->create([
            'product_id' => $product->id,
            'quantity' => 19,
            'unit_price' => $product->price,
            'color' => 'Red',
            'size' => 'M',
        ]);

        $response = $this->actingAs($buyer)->post(route('cart.store'), [
            'product_id' => $product->id,
            'quantity' => 2,
            'color' => 'Blue',
            'size' => 'M',
        ]);

        $response->assertSessionHasErrors('quantity');
        $this->assertSame(1, $cart->items()->count());
        $this->assertSame(19, $cart->items()->sum('quantity'));
    }

    public function test_cart_update_rejects_overstock_and_preserves_the_previous_quantity(): void
    {
        [$buyer, $product, $cart] = $this->buyerProductAndCart(stock: 8);
        $item = $cart->items()->create([
            'product_id' => $product->id,
            'quantity' => 3,
            'unit_price' => $product->price,
        ]);

        $this->actingAs($buyer)
            ->patch(route('cart.update', $item), ['quantity' => 9])
            ->assertSessionHasErrors('quantity');

        $this->assertSame(3, $item->fresh()->quantity);
    }

    public function test_product_page_returns_current_cart_quantities_for_client_side_limits(): void
    {
        [$buyer, $product, $cart] = $this->buyerProductAndCart(stock: 20);
        $cart->items()->create([
            'product_id' => $product->id,
            'quantity' => 19,
            'unit_price' => $product->price,
        ]);

        $this->actingAs($buyer)
            ->get(route('buyer.products.show', $product->slug))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Buyer/ProductDetail')
                ->has('cartQuantities', 1)
                ->where('cartQuantities.0.quantity', 19)
            );
    }

    /**
     * @return array{User, Product, Cart}
     */
    private function buyerProductAndCart(int $stock, ?array $variants = null): array
    {
        $buyer = User::factory()->buyer()->create();
        $seller = User::factory()->seller()->create();
        $shop = Shop::factory()->create([
            'user_id' => $seller->id,
            'status' => 'active',
        ]);
        $product = Product::factory()->create([
            'shop_id' => $shop->id,
            'stock' => $stock,
            'variants' => $variants,
            'status' => 'active',
        ]);
        $cart = Cart::create(['user_id' => $buyer->id]);

        return [$buyer, $product, $cart];
    }
}

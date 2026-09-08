<?php

namespace Tests\Feature\Buyer;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BuyerCartSelectionTest extends TestCase
{
    use RefreshDatabase;

    private function createBuyerWithMultipleCartItems(): array
    {
        $seller = User::factory()->create([
            'role' => 'seller',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        $shop = Shop::create([
            'user_id' => $seller->id,
            'name' => 'Artisan Bagoo Hub',
            'slug' => 'artisan-bagoo-hub',
            'status' => 'active',
        ]);

        $category = Category::create([
            'name' => 'Leather Goods',
            'slug' => 'leather-goods',
            'is_active' => true,
        ]);

        $productA = Product::create([
            'shop_id' => $shop->id,
            'category_id' => $category->id,
            'name' => 'Leather Tote Bag',
            'slug' => 'leather-tote-bag',
            'price' => 500.00,
            'stock' => 20,
            'status' => 'active',
        ]);

        $productB = Product::create([
            'shop_id' => $shop->id,
            'category_id' => $category->id,
            'name' => 'Canvas Backpack',
            'slug' => 'canvas-backpack',
            'price' => 800.00,
            'stock' => 15,
            'status' => 'active',
        ]);

        $buyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        $cart = Cart::create(['user_id' => $buyer->id]);

        $itemA = $cart->items()->create([
            'product_id' => $productA->id,
            'quantity' => 1,
            'unit_price' => 500.00,
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(10),
        ]);

        $itemB = $cart->items()->create([
            'product_id' => $productB->id,
            'quantity' => 2,
            'unit_price' => 800.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$buyer, $cart, $itemA, $itemB, $productA, $productB];
    }

    public function test_cart_page_renders_with_items(): void
    {
        [$buyer, $cart, $itemA, $itemB] = $this->createBuyerWithMultipleCartItems();

        $response = $this->actingAs($buyer)->get('/cart');
        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Cart/Index')
            ->has('items', 2)
        );
    }

    public function test_checkout_page_with_items_query_only_loads_and_charges_specified_item(): void
    {
        [$buyer, $cart, $itemA, $itemB] = $this->createBuyerWithMultipleCartItems();

        // Pass only itemB in the query parameters
        $response = $this->actingAs($buyer)->get("/checkout?items={$itemB->id}");
        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Checkout/Index')
            ->has('items', 1)
            ->where('items.0.id', $itemB->id)
            ->where('subtotal', 1600) // 2 * 800.00
        );
    }

    public function test_checkout_page_without_query_param_defaults_to_most_recent_item(): void
    {
        [$buyer, $cart, $itemA, $itemB] = $this->createBuyerWithMultipleCartItems();

        // Navigate to checkout without query param
        $response = $this->actingAs($buyer)->get('/checkout');
        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Checkout/Index')
            ->has('items', 1)
            ->where('items.0.id', $itemB->id) // Item B is the most recent
            ->where('subtotal', 1600)
        );
    }

    public function test_placing_order_with_item_ids_only_orders_and_deletes_selected_items_leaving_others_in_cart(): void
    {
        [$buyer, $cart, $itemA, $itemB, $productA, $productB] = $this->createBuyerWithMultipleCartItems();

        $this->assertEquals(2, $cart->items()->count());
        $this->assertEquals(20, $productA->fresh()->stock);
        $this->assertEquals(15, $productB->fresh()->stock);

        // Buyer selectively checks out only Item B
        $response = $this->actingAs($buyer)->post('/checkout', [
            'item_ids' => [$itemB->id],
            'recipient_name' => $buyer->name,
            'recipient_phone' => '+63 917 123 4567',
            'shipping_address' => '456 Elm Street',
            'shipping_city' => 'Taguig City',
            'payment_method' => 'cod',
        ]);

        $response->assertRedirect(route('buyer.orders.index'));

        // Verify order contains only 1 item (Product B)
        $order = Order::latest()->first();
        $this->assertNotNull($order);
        $this->assertEquals(1, $order->items()->count());
        $this->assertEquals($productB->id, $order->items->first()->product_id);
        $this->assertEquals(2, $order->items->first()->quantity);
        $this->assertEquals(1600.00, (float) $order->subtotal);

        // Verify stock: Product B decremented by 2, Product A remains untouched
        $this->assertEquals(13, $productB->fresh()->stock);
        $this->assertEquals(20, $productA->fresh()->stock);

        // Verify cart: Item B deleted, but Item A remains intact in the shopping bag
        $remainingCartItems = $cart->fresh()->items;
        $this->assertCount(1, $remainingCartItems);
        $this->assertEquals($itemA->id, $remainingCartItems->first()->id);
        $this->assertEquals($productA->id, $remainingCartItems->first()->product_id);
    }

    public function test_placing_order_without_item_ids_checks_out_entire_cart_for_backwards_compatibility(): void
    {
        [$buyer, $cart, $itemA, $itemB, $productA, $productB] = $this->createBuyerWithMultipleCartItems();

        $response = $this->actingAs($buyer)->post('/checkout', [
            'recipient_name' => $buyer->name,
            'recipient_phone' => '+63 917 123 4567',
            'shipping_address' => '456 Elm Street',
            'shipping_city' => 'Taguig City',
            'payment_method' => 'cod',
        ]);

        $response->assertRedirect(route('buyer.orders.index'));

        // All items cleared when no specific item_ids provided
        $this->assertEquals(0, $cart->fresh()->items()->count());
    }
}

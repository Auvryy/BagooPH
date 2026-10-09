<?php

namespace Tests\Feature\Buyer;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Database\Factories\ShopFactory;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithCheckoutSubmission;
use Tests\TestCase;

class InventoryCheckoutTest extends TestCase
{
    use InteractsWithCheckoutSubmission;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);
        $shop = Shop::whereHas('user', fn ($owner) => $owner->where('email', 'seller@bagoo.test'))->firstOrFail();
        $root = Category::where('name', "Men's Apparel")->whereNull('parent_id')->firstOrFail();
        $shop->update(['root_category_id' => $root->id]);
        foreach ($shop->products()->with('category')->get() as $product) {
            $product->category->update(['parent_id' => $root->id]);
        }
        ShopFactory::recordApprovalFixture($shop);
    }

    public function test_stock_is_decremented_at_checkout_not_when_added_to_the_bag_and_restored_once_on_cancellation(): void
    {
        $buyer = User::where('email', 'buyer@bagoo.test')->firstOrFail();
        $product = Product::where('status', 'active')->firstOrFail();
        $product->update(['stock' => 20, 'sales_count' => 0, 'variants' => null]);
        Cart::where('user_id', $buyer->id)->delete();

        $this->actingAs($buyer)->post(route('cart.store'), [
            'product_id' => $product->id,
            'quantity' => 5,
        ])->assertSessionHas('success');

        $this->assertSame(20, $product->fresh()->stock);
        $cart = Cart::where('user_id', $buyer->id)->with('items')->firstOrFail();

        $this->actingAs($buyer)
            ->post(route('checkout.store'), $this->checkoutPayload($buyer, [$cart->items->first()->id]))
            ->assertRedirect(route('buyer.orders.index'));

        $order = Order::with('items.product.shop.user')->firstOrFail();
        $this->assertSame(15, $product->fresh()->stock);
        $this->assertSame(5, $product->fresh()->sales_count);

        $seller = $order->items->first()->product->shop->user;
        $this->actingAs($seller)
            ->post(route('seller.orders.cancel', $order), [
                'reason' => 'Buyer requested cancellation via chat',
            ])
            ->assertSessionHas('success');

        $this->assertSame(20, $product->fresh()->stock);
        $this->assertSame(0, $product->fresh()->sales_count);

        $this->actingAs($seller)
            ->post(route('seller.orders.cancel', $order), [
                'reason' => 'Repeated cancellation attempt',
            ])
            ->assertSessionHas('error');

        $this->assertSame(20, $product->fresh()->stock);
    }

    public function test_checkout_rejects_aggregate_variant_lines_that_exceed_product_stock(): void
    {
        $buyer = User::where('email', 'buyer@bagoo.test')->firstOrFail();
        $product = Product::where('status', 'active')->firstOrFail();
        $product->update([
            'stock' => 10,
            'variants' => [
                'colors' => [
                    ['id' => 'red', 'name' => 'Red', 'hex' => '#ff0000', 'in_stock' => true],
                    ['id' => 'blue', 'name' => 'Blue', 'hex' => '#0000ff', 'in_stock' => true],
                ],
                'sizes' => [],
            ],
        ]);
        Cart::where('user_id', $buyer->id)->delete();
        $cart = Cart::create(['user_id' => $buyer->id]);
        $red = $cart->items()->create([
            'product_id' => $product->id,
            'quantity' => 6,
            'unit_price' => $product->price,
            'color' => 'Red',
        ]);
        $blue = $cart->items()->create([
            'product_id' => $product->id,
            'quantity' => 6,
            'unit_price' => $product->price,
            'color' => 'Blue',
        ]);

        $this->actingAs($buyer)
            ->from(route('checkout.index'))
            ->post(route('checkout.store'), $this->checkoutPayload($buyer, [$red->id, $blue->id]))
            ->assertRedirect(route('checkout.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(10, $product->fresh()->stock);
        $this->assertSame(2, $cart->items()->count());
    }

    /** @param list<int> $itemIds */
    private function checkoutPayload(User $buyer, array $itemIds): array
    {
        return [
            'checkout_token' => $this->checkoutToken($buyer),
            'recipient_name' => 'Santa Cruz Buyer',
            'recipient_phone' => '+63 917 000 0002',
            'shipping_address' => 'Pedro Guevara Avenue, Poblacion III',
            'shipping_city' => 'Santa Cruz',
            'shipping_province' => 'Laguna',
            'shipping_postal_code' => '4009',
            'destination_barangay' => 'Poblacion III',
            'delivery_type' => 'doorstep',
            'payment_method' => 'cod',
            'item_ids' => $itemIds,
        ];
    }
}

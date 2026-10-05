<?php

namespace Tests\Feature\Seller;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SellerProductCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_is_paginated_and_searches_products_outside_the_current_page(): void
    {
        $seller = User::factory()->seller()->create();
        $shop = Shop::factory()->approved()->create([
            'user_id' => $seller->id,
            'is_default' => true,
        ]);
        $olderProduct = Product::factory()->create([
            'shop_id' => $shop->id,
            'name' => 'Archive Finder Backpack',
            'sku' => 'OLD-FIND-001',
            'created_at' => now()->subMonth(),
            'updated_at' => now()->subMonth(),
        ]);
        Product::factory()->count(14)->create([
            'shop_id' => $shop->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($seller)
            ->get(route('seller.products.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Seller/Products')
                ->has('products.data', 10)
                ->where('products.total', 15)
                ->where('products.last_page', 2)
                ->where('products.current_page', 1)
            );

        $this->actingAs($seller)
            ->get(route('seller.products.index', ['search' => 'Archive Finder']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Seller/Products')
                ->has('products.data', 1)
                ->where('products.data.0.id', $olderProduct->id)
                ->where('filters.search', 'Archive Finder')
            );

        $this->actingAs($seller)
            ->get(route('seller.products.index', ['search' => 'OLD-FIND-001']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('products.data', 1)
                ->where('products.data.0.id', $olderProduct->id)
            );
    }

    public function test_seller_search_is_scoped_to_the_active_shop(): void
    {
        $seller = User::factory()->seller()->create();
        $shop = Shop::factory()->approved()->create(['user_id' => $seller->id, 'is_default' => true]);
        $otherShop = Shop::factory()->approved()->create();
        Product::factory()->create([
            'shop_id' => $shop->id,
            'name' => 'Owned Search Result',
        ]);
        Product::factory()->create([
            'shop_id' => $otherShop->id,
            'name' => 'Owned Search Result Foreign',
        ]);

        $this->actingAs($seller)
            ->get(route('seller.products.index', ['search' => 'Owned Search Result']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('products.data', 1)
                ->where('products.data.0.shop_id', $shop->id)
            );
    }

    public function test_seller_can_set_and_add_available_stock_from_the_catalog(): void
    {
        $seller = User::factory()->seller()->create();
        $shop = Shop::factory()->approved()->create(['user_id' => $seller->id, 'is_default' => true]);
        $product = Product::factory()->create([
            'shop_id' => $shop->id,
            'stock' => 0,
            'variants' => [
                'sizes' => [
                    ['id' => 'standard', 'name' => 'Standard', 'extra_price' => 0, 'stock' => 8],
                ],
            ],
        ]);

        $this->actingAs($seller)
            ->patch(route('seller.products.stock.update', $product), [
                'mode' => 'set',
                'quantity' => 12,
            ])
            ->assertSessionHas('success');

        $this->assertSame(12, $product->fresh()->stock);
        $this->assertSame(8, $product->fresh()->variants['sizes'][0]['stock']);

        $this->actingAs($seller)
            ->patch(route('seller.products.stock.update', $product), [
                'mode' => 'add',
                'quantity' => 5,
            ])
            ->assertSessionHas('success');

        $this->assertSame(17, $product->fresh()->stock);
    }

    public function test_stock_update_validates_quantity_and_rejects_a_foreign_product(): void
    {
        $seller = User::factory()->seller()->create();
        Shop::factory()->approved()->create(['user_id' => $seller->id, 'is_default' => true]);
        $foreignProduct = Product::factory()->create(['stock' => 10]);

        $this->actingAs($seller)
            ->patch(route('seller.products.stock.update', $foreignProduct), [
                'mode' => 'set',
                'quantity' => -1,
            ])
            ->assertSessionHasErrors('quantity');

        $this->actingAs($seller)
            ->patch(route('seller.products.stock.update', $foreignProduct), [
                'mode' => 'add',
                'quantity' => 2,
            ])
            ->assertForbidden();

        $this->assertSame(10, $foreignProduct->fresh()->stock);
    }

    public function test_product_removal_deletes_unused_listings_but_archives_order_history(): void
    {
        $seller = User::factory()->seller()->create();
        $shop = Shop::factory()->approved()->create(['user_id' => $seller->id, 'is_default' => true]);
        $unusedProduct = Product::factory()->create(['shop_id' => $shop->id]);
        $orderedProduct = Product::factory()->create([
            'shop_id' => $shop->id,
            'status' => 'active',
        ]);
        $order = Order::factory()->create();
        $orderItem = OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $orderedProduct->id,
            'shop_id' => $shop->id,
        ]);

        $this->actingAs($seller)
            ->delete(route('seller.products.destroy', $unusedProduct))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('products', ['id' => $unusedProduct->id]);

        $this->actingAs($seller)
            ->delete(route('seller.products.destroy', $orderedProduct))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('products', [
            'id' => $orderedProduct->id,
            'status' => 'archived',
        ]);
        $this->assertDatabaseHas('order_items', ['id' => $orderItem->id]);

        $this->actingAs($seller)
            ->get(route('seller.products.index', ['search' => $orderedProduct->sku]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('products.data.0.id', $orderedProduct->id)
                ->where('products.data.0.order_items_count', 1)
            );
    }
}

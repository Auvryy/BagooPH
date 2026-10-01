<?php

namespace Tests\Feature\Seller;

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
        $shop = Shop::factory()->create([
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
        $shop = Shop::factory()->create(['user_id' => $seller->id, 'is_default' => true]);
        $otherShop = Shop::factory()->create();
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
}

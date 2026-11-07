<?php

namespace Tests\Feature\Buyer;

use App\Models\Category;
use App\Models\Product;
use App\Models\Shop;
use App\Models\SubCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BuyerSubCategoryDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_marketplace_home_provides_subcategories_and_top_curated_collections(): void
    {
        $category = Category::factory()->create(['name' => "Men's Apparel", 'slug' => 'mens-apparel', 'is_active' => true]);
        $sub1 = SubCategory::create(['category_id' => $category->id, 'name' => 'Tees & Tops', 'slug' => 'tees-and-tops', 'sort_order' => 1, 'is_active' => true]);
        $sub2 = SubCategory::create(['category_id' => $category->id, 'name' => 'Outerwear', 'slug' => 'mens-outerwear', 'sort_order' => 2, 'is_active' => true]);

        $shop = Shop::factory()->approved()->create(['root_category_id' => $category->id]);
        $product1 = Product::factory()->create([
            'shop_id' => $shop->id,
            'category_id' => $category->id,
            'sub_category_id' => $sub1->id,
            'status' => 'active',
            'stock' => 10,
        ]);
        $product2 = Product::factory()->create([
            'shop_id' => $shop->id,
            'category_id' => $category->id,
            'sub_category_id' => $sub2->id,
            'status' => 'active',
            'stock' => 10,
        ]);

        $response = $this->get('/?category=mens-apparel');
        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Buyer/Home')
            ->has('subcategories', 2)
            ->where('subcategories.0.slug', 'tees-and-tops')
            ->where('subcategories.1.slug', 'mens-outerwear')
            ->has('topCuratedCollections')
            ->where('feedProducts.total', 2)
        );

        $filterResponse = $this->get('/?category=mens-apparel&sub=tees-and-tops');
        $filterResponse->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Buyer/Home')
            ->where('filters.category', 'mens-apparel')
            ->where('filters.sub', 'tees-and-tops')
            ->where('feedProducts.total', 1)
            ->where('feedProducts.data.0.id', $product1->id)
        );
    }
}

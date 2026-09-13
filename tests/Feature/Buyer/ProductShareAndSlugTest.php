<?php

namespace Tests\Feature\Buyer;

use App\Models\Category;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductShareAndSlugTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;
    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $seller = User::factory()->create([
            'role' => 'seller',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        $this->shop = Shop::factory()->create([
            'user_id' => $seller->id,
            'name' => 'Metro Flagship Store',
            'status' => 'active',
        ]);

        $this->category = Category::factory()->create([
            'name' => 'Apparel',
            'is_active' => true,
        ]);
    }

    public function test_guest_can_view_product_via_public_share_url_without_authenticating(): void
    {
        $product = Product::create([
            'shop_id' => $this->shop->id,
            'category_id' => $this->category->id,
            'name' => 'Premium Heavyweight Oversized Tee',
            'price' => 799.00,
            'stock' => 50,
            'status' => 'active',
            'description' => '100% cotton premium boxy streetwear tee.',
        ]);

        $this->assertGuest();

        // Public shared link route: /product/{slug}
        $response = $this->get("/product/{$product->slug}");
        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Buyer/ProductDetail')
            ->where('product.id', $product->id)
            ->where('product.name', 'Premium Heavyweight Oversized Tee')
        );

        // Buyer prefix shared link route: /buyer/product/{slug}
        $buyerResponse = $this->get(route('buyer.products.show', $product->slug));
        $buyerResponse->assertStatus(200);
        $buyerResponse->assertInertia(fn ($page) => $page
            ->component('Buyer/ProductDetail')
            ->where('product.id', $product->id)
        );
    }

    public function test_identical_product_names_generate_collision_free_unique_slugs(): void
    {
        $product1 = Product::create([
            'shop_id' => $this->shop->id,
            'category_id' => $this->category->id,
            'name' => 'Retro Corduroy Jacket',
            'price' => 1499.00,
            'stock' => 20,
            'status' => 'active',
            'description' => 'Classic autumn corduroy jacket.',
        ]);

        $product2 = Product::create([
            'shop_id' => $this->shop->id,
            'category_id' => $this->category->id,
            'name' => 'Retro Corduroy Jacket',
            'price' => 1599.00,
            'stock' => 15,
            'status' => 'active',
            'description' => 'Same title from another line or merchant.',
        ]);

        $this->assertNotEmpty($product1->slug);
        $this->assertNotEmpty($product2->slug);
        $this->assertNotEquals($product1->slug, $product2->slug);

        // Both slugs must start with base slug
        $this->assertStringStartsWith('retro-corduroy-jacket', $product1->slug);
        $this->assertStringStartsWith('retro-corduroy-jacket', $product2->slug);

        // Both products resolve independently without collision
        $res1 = $this->get("/product/{$product1->slug}");
        $res1->assertStatus(200);
        $res1->assertInertia(fn ($page) => $page->where('product.id', $product1->id));

        $res2 = $this->get("/product/{$product2->slug}");
        $res2->assertStatus(200);
        $res2->assertInertia(fn ($page) => $page->where('product.id', $product2->id));
    }

    public function test_buyer_can_resolve_product_by_numeric_id_fallback(): void
    {
        $product = Product::create([
            'shop_id' => $this->shop->id,
            'category_id' => $this->category->id,
            'name' => 'Ergonomic Mechanical Keyboard',
            'price' => 2999.00,
            'stock' => 10,
            'status' => 'active',
            'description' => 'Custom switches with aluminum body.',
        ]);

        $response = $this->get("/product/{$product->id}");
        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->where('product.id', $product->id));
    }

    public function test_buyer_can_resolve_product_by_slug_with_id_suffix(): void
    {
        $product = Product::create([
            'shop_id' => $this->shop->id,
            'category_id' => $this->category->id,
            'name' => 'Minimalist Leather Wallet',
            'price' => 850.00,
            'stock' => 30,
            'status' => 'active',
            'description' => 'Handcrafted vegetable tanned leather wallet.',
        ]);

        // Access via custom shared URL ending in -{id}
        $response = $this->get("/product/some-shared-url-{$product->id}");
        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->where('product.id', $product->id));
    }

    public function test_inactive_product_returns_404(): void
    {
        $draftProduct = Product::create([
            'shop_id' => $this->shop->id,
            'category_id' => $this->category->id,
            'name' => 'Unreleased Prototype Item',
            'price' => 9999.00,
            'stock' => 0,
            'status' => 'draft',
            'description' => 'Draft product not yet ready for public viewing.',
        ]);

        $response = $this->get("/product/{$draftProduct->slug}");
        $response->assertStatus(404);
    }
}

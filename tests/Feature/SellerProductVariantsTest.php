<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SellerProductVariantsTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;
    private Shop $shop;
    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seller = User::factory()->create([
            'role' => 'seller',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        $this->shop = Shop::create([
            'user_id' => $this->seller->id,
            'name' => 'Artisan Craft Workshop',
            'slug' => 'artisan-craft-workshop',
            'status' => 'active',
        ]);

        $this->category = Category::create([
            'name' => 'Handmade Leather Goods',
            'slug' => 'handmade-leather-goods',
            'is_active' => true,
        ]);
    }

    public function test_seller_can_create_product_with_custom_variants_and_auto_generated_sku(): void
    {
        $variants = [
            'colors' => [
                ['id' => 'c1', 'name' => 'Olive Drab', 'hex' => '#4B5320', 'in_stock' => true],
                ['id' => 'c2', 'name' => 'Espresso Brown', 'hex' => '#3B2F2F', 'in_stock' => true],
            ],
            'sizes' => [
                ['id' => 's1', 'name' => 'Standard Edition', 'extra_price' => 0, 'stock' => 30],
                ['id' => 's2', 'name' => 'Executive Extended', 'extra_price' => 250.00, 'stock' => 15],
            ],
        ];

        $response = $this->actingAs($this->seller)
            ->post(route('seller.products.store'), [
                'name' => 'Field Expedition Duffle Bag',
                'category_id' => $this->category->id,
                'price' => 1899.00,
                'compare_at_price' => 2499.00,
                'stock' => 45,
                'sku' => '', // Left blank to test auto-generation
                'description' => 'Military-spec 1000D Cordura duffle with brass hardware.',
                'variants' => json_encode($variants),
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $product = Product::where('name', 'Field Expedition Duffle Bag')->first();
        $this->assertNotNull($product);

        // Assert auto-generated SKU
        $this->assertNotEmpty($product->sku);
        $this->assertStringStartsWith('BGO-', $product->sku);

        // Assert variants persisted accurately
        $this->assertNotNull($product->variants);
        $this->assertCount(2, $product->variants['colors']);
        $this->assertEquals('Olive Drab', $product->variants['colors'][0]['name']);
        $this->assertEquals('#4B5320', $product->variants['colors'][0]['hex']);

        $this->assertCount(2, $product->variants['sizes']);
        $this->assertEquals('Executive Extended', $product->variants['sizes'][1]['name']);
        $this->assertEquals(250.00, $product->variants['sizes'][1]['extra_price']);
    }

    public function test_seller_custom_sku_is_preserved(): void
    {
        $response = $this->actingAs($this->seller)
            ->post(route('seller.products.store'), [
                'name' => 'Handstitched Cardholder Wallet',
                'category_id' => $this->category->id,
                'price' => 499.00,
                'compare_at_price' => 699.00,
                'stock' => 50,
                'sku' => 'APX-WLT-STITCH-01',
                'description' => 'Full-grain veg-tan leather card wallet.',
                'variants' => json_encode([
                    'colors' => [
                        ['id' => 'c1', 'name' => 'Tan', 'hex' => '#D2B48C', 'in_stock' => true],
                    ],
                    'sizes' => [],
                ]),
            ]);

        $response->assertRedirect();
        $product = Product::where('name', 'Handstitched Cardholder Wallet')->first();
        $this->assertNotNull($product);
        $this->assertEquals('APX-WLT-STITCH-01', $product->sku);
    }

    public function test_seller_can_update_product_variants(): void
    {
        $product = Product::create([
            'shop_id' => $this->shop->id,
            'category_id' => $this->category->id,
            'name' => 'Vintage Canvas Messenger Bag',
            'slug' => 'vintage-canvas-messenger-bag',
            'description' => 'Waxed canvas messenger with leather strap.',
            'price' => 1299.00,
            'stock' => 20,
            'sku' => 'BGO-VINT-101',
            'status' => 'active',
            'variants' => [
                'colors' => [
                    ['id' => 'c1', 'name' => 'Tan', 'hex' => '#D2B48C', 'in_stock' => true],
                ],
                'sizes' => [],
            ],
        ]);

        $updatedVariants = [
            'colors' => [
                ['id' => 'c1', 'name' => 'Tan', 'hex' => '#D2B48C', 'in_stock' => true],
                ['id' => 'c2', 'name' => 'Charcoal Grey', 'hex' => '#333333', 'in_stock' => true],
            ],
            'sizes' => [
                ['id' => 's1', 'name' => '13-inch Laptop Size', 'extra_price' => 0, 'stock' => 10],
                ['id' => 's2', 'name' => '16-inch Pro Size', 'extra_price' => 200.00, 'stock' => 10],
            ],
        ];

        $response = $this->actingAs($this->seller)
            ->put(route('seller.products.update', $product->id), [
                'name' => 'Vintage Canvas Messenger Bag Pro',
                'category_id' => $this->category->id,
                'price' => 1399.00,
                'stock' => 20,
                'status' => 'active',
                'description' => 'Updated waxed canvas messenger with laptop sleeve.',
                'variants' => json_encode($updatedVariants),
            ]);

        $response->assertRedirect();

        $product->refresh();
        $this->assertEquals('Vintage Canvas Messenger Bag Pro', $product->name);
        $this->assertCount(2, $product->variants['colors']);
        $this->assertEquals('Charcoal Grey', $product->variants['colors'][1]['name']);
        $this->assertCount(2, $product->variants['sizes']);
        $this->assertEquals('16-inch Pro Size', $product->variants['sizes'][1]['name']);
    }

    public function test_buyer_product_detail_serves_merchant_defined_variants(): void
    {
        $customVariants = [
            'colors' => [
                ['id' => 'c1', 'name' => 'Forest Green', 'hex' => '#228B22', 'in_stock' => true],
            ],
            'sizes' => [
                ['id' => 's1', 'name' => 'Medium', 'extra_price' => 0, 'stock' => 12],
                ['id' => 's2', 'name' => 'Large', 'extra_price' => 80.00, 'stock' => 8],
            ],
        ];

        $product = Product::create([
            'shop_id' => $this->shop->id,
            'category_id' => $this->category->id,
            'name' => 'Heavyweight Workwear Overshirt',
            'slug' => 'heavyweight-workwear-overshirt',
            'description' => '100% Cotton canvas overshirt.',
            'price' => 899.00,
            'stock' => 20,
            'sku' => 'BGO-SHRT-001',
            'status' => 'active',
            'variants' => $customVariants,
        ]);

        $response = $this->get(route('buyer.products.show', $product->slug));
        $response->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->component('Buyer/ProductDetail')
            ->where('product.id', $product->id)
            ->where('variations.colors.0.name', 'Forest Green')
            ->where('variations.sizes.1.name', 'Large')
            ->where('variations.sizes.1.extra_price', 80)
        );
    }

    public function test_buyer_can_add_custom_variant_to_cart(): void
    {
        $buyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'active',
        ]);

        $product = Product::create([
            'shop_id' => $this->shop->id,
            'category_id' => $this->category->id,
            'name' => 'Minimalist Everyday Backpack',
            'slug' => 'minimalist-everyday-backpack',
            'description' => 'Waterproof urban pack.',
            'price' => 1599.00,
            'stock' => 15,
            'sku' => 'BGO-PACK-MIN',
            'status' => 'active',
            'variants' => [
                'colors' => [
                    ['id' => 'c1', 'name' => 'Storm Grey', 'hex' => '#708090', 'in_stock' => true],
                ],
                'sizes' => [
                    ['id' => 's1', 'name' => '20 Liters', 'extra_price' => 0, 'stock' => 15],
                ],
            ],
        ]);

        $response = $this->actingAs($buyer)->post(route('cart.store'), [
            'product_id' => $product->id,
            'quantity' => 1,
            'color' => 'Storm Grey',
            'size' => '20 Liters',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('cart_items', [
            'product_id' => $product->id,
            'color' => 'Storm Grey',
            'size' => '20 Liters',
            'quantity' => 1,
        ]);
    }
}

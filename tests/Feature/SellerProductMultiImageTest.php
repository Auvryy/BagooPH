<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SellerProductMultiImageTest extends TestCase
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
            'name' => 'Apex Artisan Store',
            'slug' => 'apex-artisan-store',
            'status' => 'active',
        ]);

        $this->category = Category::create([
            'name' => 'Leather Bags',
            'slug' => 'leather-bags',
            'is_active' => true,
        ]);
    }

    public function test_seller_can_create_product_with_multi_image_files_and_manifest(): void
    {
        Storage::fake('public');

        $file1 = UploadedFile::fake()->image('cover.jpg', 600, 600)->size(1500);
        $file2 = UploadedFile::fake()->image('detail.png', 600, 600)->size(1800);

        $manifest = [
            ['type' => 'file', 'file_index' => 0],
            ['type' => 'file', 'file_index' => 1],
        ];

        $payload = [
            'name' => 'Tactical Expedition Duffel 60L',
            'category_id' => $this->category->id,
            'price' => '2499.00',
            'compare_at_price' => '3299.00',
            'stock' => 50,
            'sku' => 'EXP-DUF-60L',
            'description' => 'Heavy duty 1000D Cordura tactical duffel bag.',
            'image_files' => [$file1, $file2],
            'gallery_manifest' => json_encode($manifest),
        ];

        $response = $this->actingAs($this->seller)
            ->post('/seller/products', $payload);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        $product = Product::where('name', 'Tactical Expedition Duffel 60L')->first();
        $this->assertNotNull($product);
        $this->assertEquals($this->shop->id, $product->shop_id);
        $this->assertEquals(2499.00, (float) $product->price);
        $this->assertEquals(3299.00, (float) $product->compare_at_price);

        $images = ProductImage::where('product_id', $product->id)
            ->orderBy('sort_order')
            ->get();

        $this->assertCount(2, $images);
        $this->assertTrue((bool) $images[0]->is_primary);
        $this->assertEquals(0, $images[0]->sort_order);
        $this->assertFalse((bool) $images[1]->is_primary);
        $this->assertEquals(1, $images[1]->sort_order);
        $this->assertEquals($images[0]->image_url, $product->featured_image);
    }

    public function test_seller_can_reorder_product_images(): void
    {
        $product = Product::create([
            'shop_id' => $this->shop->id,
            'name' => 'Urban Canvas Rucksack',
            'slug' => 'urban-canvas-rucksack',
            'price' => 1899.00,
            'compare_at_price' => 2499.00,
            'stock' => 20,
            'description' => 'Waxed canvas daily commuter bag.',
            'featured_image' => 'https://example.com/img1.jpg',
            'status' => 'active',
        ]);

        ProductImage::create([
            'product_id' => $product->id,
            'image_url' => 'https://example.com/img1.jpg',
            'is_primary' => true,
            'sort_order' => 0,
        ]);

        ProductImage::create([
            'product_id' => $product->id,
            'image_url' => 'https://example.com/img2.jpg',
            'is_primary' => false,
            'sort_order' => 1,
        ]);

        // Seller reverses order: img2 becomes primary cover (position 0), img1 becomes secondary (position 1)
        $manifest = [
            ['type' => 'existing', 'url' => 'https://example.com/img2.jpg'],
            ['type' => 'existing', 'url' => 'https://example.com/img1.jpg'],
        ];

        $payload = [
            'name' => 'Urban Canvas Rucksack Updated',
            'category_id' => $this->category->id,
            'price' => '1899.00',
            'compare_at_price' => '2499.00',
            'stock' => 25,
            'description' => 'Updated waxed canvas daily commuter bag.',
            'status' => 'active',
            'gallery_manifest' => json_encode($manifest),
        ];

        $response = $this->actingAs($this->seller)
            ->put("/seller/products/{$product->id}", $payload);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        $product->refresh();
        $this->assertEquals('https://example.com/img2.jpg', $product->featured_image);

        $images = ProductImage::where('product_id', $product->id)
            ->orderBy('sort_order')
            ->get();

        $this->assertCount(2, $images);
        $this->assertEquals('https://example.com/img2.jpg', $images[0]->image_url);
        $this->assertTrue((bool) $images[0]->is_primary);
        $this->assertEquals(0, $images[0]->sort_order);

        $this->assertEquals('https://example.com/img1.jpg', $images[1]->image_url);
        $this->assertFalse((bool) $images[1]->is_primary);
        $this->assertEquals(1, $images[1]->sort_order);
    }

    public function test_slashed_price_must_be_strictly_greater_than_regular_price(): void
    {
        // Equal price (compare_at_price == price) must fail
        $responseEqual = $this->actingAs($this->seller)
            ->post('/seller/products', [
                'name' => 'Test Slashed Equal',
                'category_id' => $this->category->id,
                'price' => '1500.00',
                'compare_at_price' => '1500.00',
                'stock' => 10,
                'description' => 'Testing equal slashed price validation.',
            ]);

        $responseEqual->assertSessionHasErrors(['compare_at_price']);

        // Lower slashed price (compare_at_price < price) must fail
        $responseLower = $this->actingAs($this->seller)
            ->post('/seller/products', [
                'name' => 'Test Slashed Lower',
                'category_id' => $this->category->id,
                'price' => '1500.00',
                'compare_at_price' => '1200.00',
                'stock' => 10,
                'description' => 'Testing lower slashed price validation.',
            ]);

        $responseLower->assertSessionHasErrors(['compare_at_price']);

        // Higher slashed price (compare_at_price > price) must pass
        $responseHigher = $this->actingAs($this->seller)
            ->post('/seller/products', [
                'name' => 'Test Slashed Higher',
                'category_id' => $this->category->id,
                'price' => '1500.00',
                'compare_at_price' => '1999.00',
                'stock' => 10,
                'description' => 'Testing higher slashed price validation.',
            ]);

        $responseHigher->assertSessionHasNoErrors();
    }

    public function test_image_file_exceeding_5mb_fails_validation(): void
    {
        Storage::fake('public');

        // Create a 6MB file (6144 KB)
        $oversizedFile = UploadedFile::fake()->image('huge.jpg')->size(6144);

        $response = $this->actingAs($this->seller)
            ->post('/seller/products', [
                'name' => 'Test Large Image',
                'category_id' => $this->category->id,
                'price' => '1000.00',
                'stock' => 5,
                'description' => 'Testing oversized upload validation.',
                'image_files' => [$oversizedFile],
            ]);

        $response->assertSessionHasErrors(['image_files.0']);
    }

    public function test_non_image_file_type_fails_validation(): void
    {
        Storage::fake('public');

        $textFile = UploadedFile::fake()->create('document.txt', 100, 'text/plain');

        $response = $this->actingAs($this->seller)
            ->post('/seller/products', [
                'name' => 'Test Invalid File Type',
                'category_id' => $this->category->id,
                'price' => '1000.00',
                'stock' => 5,
                'description' => 'Testing invalid file type validation.',
                'image_files' => [$textFile],
            ]);

        $response->assertSessionHasErrors(['image_files.0']);
    }
}

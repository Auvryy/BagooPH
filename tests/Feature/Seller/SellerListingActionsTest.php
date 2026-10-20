<?php

namespace Tests\Feature\Seller;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class SellerListingActionsTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('public');
        $this->shop = Shop::factory()->approved()->create();
        $this->actingAs($this->shop->user);
    }

    public static function portals(): array
    {
        return [['/seller'], ['http://seller.localhost']];
    }

    private function listing(array $extra = []): array
    {
        return [...['name' => 'Everyday Canvas Carryall', 'price' => '199.00', 'stock' => 20,
            'description' => 'A sturdy canvas carryall with reinforced handles.', 'weight_kg' => '0.50', 'category_id' => $this->shop->root_category_id,
            'featured_image' => '/storage/products/carryall.jpg', 'status' => 'active'], ...$extra];
    }

    #[DataProvider('portals')]
    public function test_name_only_draft_is_private_and_must_be_completed_before_publication(string $portal): void
    {
        $this->post($portal.'/products', ['name' => 'Draft Canvas Carryall', 'status' => 'draft'])
            ->assertSessionHasNoErrors()->assertSessionHas('success', 'Product draft saved.');
        $product = Product::sole();
        $this->assertSame('draft', $product->status);
        $this->assertSame('0.00', $product->price);
        $this->assertSame(0, $product->stock);
        $this->assertFalse(Product::availableForSale()->whereKey($product->id)->exists());
        $this->get('http://localhost:8000/product/'.$product->slug)->assertNotFound();
        $this->putJson($portal.'/products/'.$product->id, ['name' => $product->name, 'status' => 'active'])
            ->assertUnprocessable()->assertJsonValidationErrors(['price', 'stock', 'description']);
        $this->assertSame('draft', $product->fresh()->status);
        $this->put($portal.'/products/'.$product->id, $this->listing())->assertSessionHasNoErrors();
        $this->assertSame('active', $product->fresh()->status);
    }

    #[DataProvider('portals')]
    public function test_duplicate_copies_details_gallery_order_and_options_into_one_distinct_private_draft(string $portal): void
    {
        $original = Product::factory()->create(['shop_id' => $this->shop->id, 'weight_kg' => '0.50', 'category_id' => $this->shop->root_category_id,
            'stock' => 13, 'price' => '249.00', 'variants' => ['sizes' => [['name' => 'Medium', 'extra_price' => 20, 'stock' => 13]]]]);
        $original->images()->create(['image_url' => '/storage/products/second.jpg', 'sort_order' => 1, 'is_primary' => false]);
        $original->images()->create(['image_url' => '/storage/products/first.jpg', 'sort_order' => 0, 'is_primary' => true]);
        $before = $original->fresh()->getRawOriginal();
        $token = (string) Str::uuid();
        for ($n = 0; $n < 2; $n++) {
            $this->post($portal.'/products/'.$original->id.'/duplicate', ['request_token' => $token])->assertSessionHasNoErrors();
        }
        $this->assertDatabaseCount('products', 2);
        $copy = Product::whereKeyNot($original->id)->sole();
        $this->assertSame('draft', $copy->status);
        $this->assertSame($original->name, $copy->name);
        $this->assertSame($original->variants, $copy->variants);
        $this->assertSame(13, $copy->stock);
        $this->assertNotSame($original->sku, $copy->sku);
        $this->assertNotSame($original->slug, $copy->slug);
        $this->assertSame(['/storage/products/first.jpg', '/storage/products/second.jpg'], $copy->images()->orderBy('sort_order')->pluck('image_url')->all());
        $this->assertTrue($copy->images()->where('sort_order', 0)->sole()->is_primary);
        $this->assertSame($before, $original->fresh()->getRawOriginal());
        $this->assertFalse(Product::availableForSale()->whereKey($copy->id)->exists());
    }

    public function test_publication_retry_has_one_product_one_upload_and_rejects_a_conflicting_or_deleted_result(): void
    {
        $input = $this->listing(['request_token' => (string) Str::uuid(), 'image_file' => UploadedFile::fake()->image('cover.jpg')]);
        $this->post('/seller/products', $input)->assertSessionHasNoErrors();
        $original = Product::sole();
        $this->post('/seller/products', $input)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('seller_product_submissions', 1);
        $this->assertCount(1, Storage::disk('public')->allFiles());
        $this->postJson('/seller/products', [...$input, 'name' => 'Different Canvas Carryall'])
            ->assertUnprocessable()->assertJsonValidationErrors('request_token');
        $this->assertSame($input['name'], $original->fresh()->name);
        $this->delete('/seller/products/'.$original->id)->assertSessionHasNoErrors();
        $this->postJson('/seller/products', $input)->assertUnprocessable()->assertJsonValidationErrors('request_token');
        $this->assertDatabaseCount('products', 0);
    }

    public function test_rejected_save_does_not_consume_its_key_or_upload_and_can_be_corrected(): void
    {
        $input = $this->listing(['request_token' => (string) Str::uuid(), 'name' => 'Bad', 'image_file' => UploadedFile::fake()->image('cover.jpg')]);
        $this->postJson('/seller/products', $input)->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertDatabaseCount('seller_product_submissions', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->post('/seller/products', [...$input, 'name' => 'Corrected Canvas Carryall'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('products', 1);
    }

    #[DataProvider('portals')]
    public function test_delete_blocks_unfinished_orders_and_bulk_reports_skips_without_hiding_them(string $portal): void
    {
        $products = Product::factory()->count(3)->create(['shop_id' => $this->shop->id, 'weight_kg' => '0.50', 'category_id' => $this->shop->root_category_id]);
        $order = Order::factory()->create(['status' => 'preparing']);
        OrderItem::factory()->create(['order_id' => $order->id, 'shop_id' => $this->shop->id, 'product_id' => $products[0]->id]);
        $before = $products[0]->fresh()->getRawOriginal();
        $this->deleteJson($portal.'/products/'.$products[0]->id)->assertUnprocessable()->assertJsonValidationErrors('product');
        $this->assertSame($before, $products[0]->fresh()->getRawOriginal());
        $this->post($portal.'/products/bulk', ['action' => 'deactivate', 'product_ids' => $products->modelKeys()])->assertSessionHasNoErrors();
        $this->assertSame(['archived', 'archived', 'archived'], Product::orderBy('id')->pluck('status')->all());
        Product::query()->update(['status' => 'active']);
        $this->post($portal.'/products/bulk', ['action' => 'delete', 'product_ids' => $products->modelKeys()])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('products', 1);
        $this->assertSame('active', $products[0]->fresh()->status);
        $this->get($portal.'/products')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('bulkResults', 3)->where('bulkResults.0.result', 'skipped')->where('bulkResults.1.result', 'deleted'));
        $this->assertDatabaseHas('order_items', ['product_id' => $products[0]->id]);
    }

    public function test_foreign_bulk_selection_rejects_every_write_and_restricted_products_cannot_be_copied(): void
    {
        $owned = Product::factory()->create(['shop_id' => $this->shop->id]);
        $foreign = Product::factory()->create();
        $this->postJson('/seller/products/bulk', ['action' => 'deactivate', 'product_ids' => [$owned->id, $foreign->id]])->assertForbidden();
        $this->assertSame('active', $owned->fresh()->status);
        $this->postJson('/seller/products/'.$foreign->id.'/duplicate')->assertForbidden();
        $owned->forceFill(['compliance_restricted' => true])->save();
        $this->postJson('/seller/products/'.$owned->id.'/duplicate')->assertUnprocessable()->assertJsonValidationErrors('product');
        foreach ([[], [$owned->id, $owned->id], [0], ['bad']] as $ids) {
            $this->postJson('/seller/products/bulk', ['action' => 'delete', 'product_ids' => $ids])->assertUnprocessable();
        }
        $this->assertDatabaseCount('products', 2);
    }

    public function test_server_price_ranges_match_existing_variant_prices_and_scheduled_base_adjustments(): void
    {
        $product = Product::factory()->create(['shop_id' => $this->shop->id, 'price' => '199.00', 'variants' => ['sizes' => [
            ['name' => 'Small', 'extra_price' => 0, 'stock' => 5], ['name' => 'Medium', 'extra_price' => 50, 'stock' => 5], ['name' => 'Large', 'extra_price' => 100, 'stock' => 5],
        ]]]);
        $this->assertSame(['min' => '199.00', 'max' => '299.00'], $product->price_range);
        $product->sales()->create(['kind' => 'store', 'name' => 'Store sale', 'price' => '179.00', 'starts_at' => now()->subMinute(), 'ends_at' => now()->addHour()]);
        $product->unsetRelation('sales');
        $this->assertSame(['min' => '179.00', 'max' => '279.00'], $product->price_range);
        $product->update(['variants' => ['combinations' => [['extra_price' => '20.00'], ['extra_price' => '80.00']]]]);
        $this->assertSame(['min' => '199.00', 'max' => '259.00'], $product->price_range);
        $this->assertSame('199.00', $product->price);
    }

    public function test_gallery_write_failure_rolls_back_the_new_product_and_removes_only_its_new_files(): void
    {
        Event::listen('eloquent.created: '.ProductImage::class, fn () => throw new RuntimeException('Image record could not be saved.'));
        try {
            $this->post('/seller/products', $this->listing(['request_token' => (string) Str::uuid(), 'image_file' => UploadedFile::fake()->image('cover.jpg')]))->assertServerError();
        } finally {
            Event::forget('eloquent.created: '.ProductImage::class);
        }
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('seller_product_submissions', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_failed_gallery_replacement_retains_original_listing_images_and_file(): void
    {
        Storage::disk('public')->put('products/original.jpg', 'retained original');
        $product = Product::factory()->create(['shop_id' => $this->shop->id, 'featured_image' => '/storage/products/original.jpg']);
        $product->images()->create(['image_url' => '/storage/products/original.jpg', 'sort_order' => 0, 'is_primary' => true]);
        $before = $product->fresh()->getRawOriginal();
        Event::listen('eloquent.created: '.ProductImage::class, fn () => throw new RuntimeException('Replacement image record could not be saved.'));
        try {
            $this->put('/seller/products/'.$product->id, $this->listing(['image_file' => UploadedFile::fake()->image('replacement.jpg')]))->assertServerError();
        } finally {
            Event::forget('eloquent.created: '.ProductImage::class);
        }
        $this->assertSame($before, $product->fresh()->getRawOriginal());
        $this->assertSame('/storage/products/original.jpg', $product->images()->sole()->image_url);
        $this->assertSame(['products/original.jpg'], Storage::disk('public')->allFiles());
    }
}

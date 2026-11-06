<?php

namespace Tests\Feature\Seller;

use App\Models\Product;
use App\Models\Shop;
use App\Services\Commerce\ProductSaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SellerListingBoundaryTest extends TestCase
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

    private function payload(array $changes = []): array
    {
        return array_replace(['name' => 'Woven Market Tote', 'description' => 'A handwoven bag with strong handles and a lined interior.',
            'price' => '450.00', 'stock' => 20, 'status' => 'active', 'weight_kg' => '0.50', 'category_id' => $this->shop->root_category_id,
            'featured_image' => '/storage/products/tote.jpg'], $changes);
    }

    private function tinyPng(string $name): UploadedFile
    {
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/+NEAAAAASUVORK5CYII=');

        return UploadedFile::fake()->createWithContent($name, $bytes)->mimeType('image/png');
    }

    public static function fields(): array
    {
        return [
            'short name' => ['name', 'Tote', false], 'minimum name' => ['name', 'Totes', true],
            'maximum name' => ['name', str_repeat('N', 120), true], 'long name' => ['name', str_repeat('N', 121), false],
            'short description' => ['description', str_repeat('d', 19), false],
            'minimum description' => ['description', str_repeat('d', 20), true],
            'maximum description' => ['description', str_repeat('d', 3000), true],
            'long description' => ['description', str_repeat('d', 3001), false],
            'zero price' => ['price', '0', false], 'negative price' => ['price', '-50', false],
            'under one peso' => ['price', '0.99', false], 'minimum price' => ['price', '1.00', true],
            'above minimum price' => ['price', '1.01', true], 'three decimals' => ['price', '199.999', false],
            'leading zero price' => ['price', '0234', false], 'leading zero decimal price' => ['price', '01.00', false],
            'below maximum price' => ['price', '999999.99', true], 'maximum price' => ['price', '1000000.00', true],
            'excessive price' => ['price', '1000000.01', false], 'text price' => ['price', 'abc', false],
            'missing price' => ['price', '', false],
            'negative stock' => ['stock', -1, false], 'zero stock' => ['stock', 0, true],
            'one stock' => ['stock', 1, true], 'maximum stock' => ['stock', 99999, true],
            'excessive stock' => ['stock', 100000, false], 'fractional stock' => ['stock', '2.5', false],
            'leading zero stock' => ['stock', '09999', false], 'double zero stock' => ['stock', '00', false],
        ];
    }

    #[DataProvider('fields')]
    public function test_create_and_update_share_the_listing_boundaries(string $field, mixed $value, bool $accepted): void
    {
        $payload = $this->payload([$field => $value]);
        $create = $this->post('/seller/products', $payload);
        if ($accepted) {
            $create->assertSessionHasNoErrors()->assertSessionHas('success');
            $product = Product::sole();
            $this->assertEquals($value, $product->$field);
            $this->put('/seller/products/'.$product->id, $payload)->assertSessionHasNoErrors()->assertSessionHas('success');
            $this->assertEquals($value, $product->fresh()->$field);
        } else {
            $create->assertSessionHasErrors($field);
            $this->assertDatabaseCount('products', 0);
            $product = Product::factory()->create(['shop_id' => $this->shop->id]);
            $before = $product->fresh()->getRawOriginal();
            $this->put('/seller/products/'.$product->id, $payload)->assertSessionHasErrors($field);
            $this->assertSame($before, $product->fresh()->getRawOriginal());
            $this->assertSame([], Storage::disk('public')->allFiles());
        }
    }

    public function test_gallery_accepts_one_and_nine_images_and_retains_them_after_a_tenth_is_rejected(): void
    {
        $this->post('/seller/products', $this->payload(['image_file' => $this->tinyPng('cover.jpg')]))
            ->assertSessionHasNoErrors();
        $product = Product::sole();
        $this->assertSame(1, $product->images()->count());
        $manifest = array_map(fn ($i) => ['type' => 'url', 'url' => '/storage/products/tote-'.$i.'.jpg'], range(1, 9));
        $this->put('/seller/products/'.$product->id, $this->payload(['gallery_manifest' => json_encode($manifest)]))
            ->assertSessionHasNoErrors();
        $before = $product->images()->orderBy('sort_order')->get()->map->getAttributes()->all();
        $this->assertCount(9, $before);
        $this->put('/seller/products/'.$product->id, $this->payload(['featured_image' => null]))->assertSessionHasNoErrors();
        $this->assertSame(array_column($before, 'image_url'), $product->images()->orderBy('sort_order')->pluck('image_url')->all());
        $before = $product->images()->orderBy('sort_order')->get()->map->getAttributes()->all();
        $manifest[] = ['type' => 'url', 'url' => '/storage/products/tenth.jpg'];
        $this->put('/seller/products/'.$product->id, $this->payload(['gallery_manifest' => json_encode($manifest)]))
            ->assertSessionHasErrors('gallery_manifest');
        $this->assertSame($before, $product->images()->orderBy('sort_order')->get()->map->getAttributes()->all());
    }

    public function test_missing_empty_and_malformed_galleries_cannot_publish_or_store_files(): void
    {
        foreach (['', '[]', '{}', 'invalid json', '[{"type":"file","file_index":2}]'] as $manifest) {
            $this->post('/seller/products', $this->payload(['featured_image' => '', 'image_files' => null, 'gallery_manifest' => $manifest]))
                ->assertSessionHasErrors('gallery_manifest');
            $this->assertDatabaseCount('products', 0);
        }
        $this->post('/seller/products', $this->payload(['featured_image' => '/storage/products/placeholder.webp']))
            ->assertSessionHasErrors('gallery_manifest');
        $this->post('/seller/products', $this->payload(['featured_image' => '', 'status' => 'draft']))
            ->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame('draft', Product::sole()->status);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_uploads_count_toward_the_gallery_limit_and_invalid_mixed_input_saves_nothing(): void
    {
        $files = array_map(fn ($i) => $this->tinyPng('photo-'.$i.'.jpg'), range(1, 10));
        $this->post('/seller/products', $this->payload(['image_files' => $files]))->assertSessionHasErrors('image_files');
        $this->assertDatabaseCount('products', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->post('/seller/products', $this->payload(['image_files' => array_slice($files, 0, 9)]))
            ->assertSessionHasNoErrors();
        $this->assertSame(9, Product::sole()->images()->count());
    }

    public function test_product_image_accepts_one_byte_under_and_exactly_five_mib_but_rejects_one_byte_over(): void
    {
        $limit = 5 * 1024 * 1024;
        $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/+NEAAAAASUVORK5CYII=');
        foreach ([$limit - 1, $limit] as $size) {
            $image = UploadedFile::fake()->createWithContent('cover.png', $pngBytes)->mimeType('image/png');
            $image->sizeToReport = $size;
            $this->post('/seller/products', $this->payload(['name' => 'Canvas Tote '.$size, 'image_file' => $image]))
                ->assertSessionHasNoErrors()->assertSessionHas('success');
        }
        $this->assertSame(2, Product::count());

        $tooLarge = UploadedFile::fake()->createWithContent('cover.png', $pngBytes)->mimeType('image/png');
        $tooLarge->sizeToReport = $limit + 1;
        $this->post('/seller/products', $this->payload(['image_file' => $tooLarge]))
            ->assertSessionHasErrors('image_file');
        $this->assertSame(2, Product::count());
    }

    public function test_each_multi_upload_image_rejects_one_byte_over_five_mib(): void
    {
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/+NEAAAAASUVORK5CYII=');
        $image = UploadedFile::fake()->createWithContent('large.png', $bytes)->mimeType('image/png');
        $image->sizeToReport = (5 * 1024 * 1024) + 1;
        $manifest = json_encode([['type' => 'file', 'file_index' => 0]]);

        $this->post('/seller/products', $this->payload(['featured_image' => '', 'gallery_manifest' => $manifest, 'image_files' => [$image]]))
            ->assertSessionHasErrors('image_files.0');

        $this->assertDatabaseCount('products', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_product_upload_rejects_unsupported_mime_even_when_the_extension_looks_like_an_image(): void
    {
        $file = UploadedFile::fake()->create('document.jpg', 100, 'text/plain');
        $this->post('/seller/products', $this->payload(['image_file' => $file]))
            ->assertSessionHasErrors('image_file');
        $this->assertSame(0, Product::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_variant_titles_and_values_accept_100_characters_and_reject_101(): void
    {
        $variants = ['option1_name' => str_repeat('G', 100), 'colors' => [['name' => str_repeat('C', 100)]]];
        $this->post('/seller/products', $this->payload(['variants' => json_encode($variants)]))->assertSessionHasNoErrors();
        $product = Product::sole();
        $this->assertSame(100, mb_strlen($product->variants['option1_name']));
        $this->assertSame(100, mb_strlen($product->variants['colors'][0]['name']));

        $variants['option1_name'] = str_repeat('G', 101);
        $this->put('/seller/products/'.$product->id, $this->payload(['variants' => json_encode($variants)]))->assertSessionHasErrors('option1_name');
        $variants['option1_name'] = str_repeat('G', 100);
        $variants['colors'][0]['name'] = str_repeat('C', 101);
        $this->put('/seller/products/'.$product->id, $this->payload(['variants' => json_encode($variants)]))->assertSessionHasErrors('colors.0.name');
        $this->assertSame(100, mb_strlen($product->fresh()->variants['colors'][0]['name']));
    }

    public function test_variant_stock_and_prices_reject_text_excess_precision_and_leading_zero_forms(): void
    {
        $product = Product::factory()->create(['shop_id' => $this->shop->id, 'category_id' => $this->shop->root_category_id]);
        $base = ['colors' => [['name' => 'Natural']], 'pricing_mode' => 'independent', 'combinations' => [
            ['color' => 'Natural', 'size' => null, 'stock' => '99999', 'price' => '1000000.00', 'sku' => 'TOTE-NATURAL'],
        ]];
        $this->put('/seller/products/'.$product->id, $this->payload(['variants' => json_encode($base)]))->assertSessionHasNoErrors();
        $this->assertSame(99999, $product->fresh()->stock);
        $this->assertSame('1000000.00', $product->fresh()->variants['combinations'][0]['price']);

        foreach ([['stock' => '09999'], ['stock' => '100000'], ['price' => '01.00'], ['price' => '123.456'], ['price' => 'text']] as $change) {
            $invalid = $base;
            $invalid['combinations'][0] = [...$invalid['combinations'][0], ...$change];
            $field = array_key_exists('stock', $change) ? 'combinations.0.stock' : 'combinations.0.price';
            $this->put('/seller/products/'.$product->id, $this->payload(['variants' => json_encode($invalid)]))->assertSessionHasErrors($field);
            $this->assertSame('1000000.00', $product->fresh()->variants['combinations'][0]['price']);
        }
    }

    public function test_each_variant_uses_a_unique_assigned_photo_while_extra_gallery_photos_remain_unassigned(): void
    {
        $manifest = [
            ['type' => 'url', 'url' => 'https://images.example.test/cover.jpg'],
            ['type' => 'url', 'url' => 'https://images.example.test/natural.jpg'],
            ['type' => 'url', 'url' => 'https://images.example.test/detail.jpg'],
        ];
        $variants = ['colors' => [
            ['id' => 'natural', 'name' => 'Natural', 'image_url' => $manifest[1]['url'], 'gallery_index' => 1],
            ['id' => 'black', 'name' => 'Black', 'image_url' => null, 'gallery_index' => null],
        ]];
        $this->post('/seller/products', $this->payload([
            'featured_image' => $manifest[0]['url'], 'gallery_manifest' => json_encode($manifest), 'variants' => json_encode($variants),
        ]))->assertSessionHasNoErrors();
        $product = Product::sole();
        $this->assertSame(3, $product->images()->count());
        $this->assertSame($manifest[1]['url'], $product->fresh()->variants['colors'][0]['image_url']);
        $this->assertNull($product->fresh()->variants['colors'][1]['image_url']);

        $variants['colors'][1] = ['id' => 'black', 'name' => 'Black', 'image_url' => $manifest[1]['url'], 'gallery_index' => 1];
        $before = $product->fresh()->getRawOriginal();
        $this->put('/seller/products/'.$product->id, $this->payload([
            'featured_image' => $manifest[0]['url'], 'gallery_manifest' => json_encode($manifest), 'variants' => json_encode($variants),
        ]))->assertSessionHasErrors('variants');
        $this->assertSame($before, $product->fresh()->getRawOriginal());
    }

    public function test_mixed_existing_and_uploaded_images_cannot_bypass_the_nine_image_limit(): void
    {
        $this->post('/seller/products', $this->payload())->assertSessionHasNoErrors();
        $product = Product::sole();
        $existing = array_map(fn ($i) => ['type' => 'existing', 'url' => '/storage/products/detail-'.$i.'.jpg'], range(1, 8));
        $manifest = [...$existing, ['type' => 'file', 'file_index' => 0]];
        $this->put('/seller/products/'.$product->id, $this->payload(['gallery_manifest' => json_encode($manifest),
            'image_files' => [$this->tinyPng('ninth.jpg')]]))->assertSessionHasNoErrors();
        $this->assertSame(9, $product->images()->count());
        $before = $product->images()->orderBy('sort_order')->get()->map->getAttributes()->all();
        $files = Storage::disk('public')->allFiles();
        $manifest = array_map(fn ($row) => ['type' => 'existing', 'url' => $row['image_url']], $before);
        $manifest[] = ['type' => 'file', 'file_index' => 0];
        $this->put('/seller/products/'.$product->id, $this->payload(['gallery_manifest' => json_encode($manifest),
            'image_files' => [$this->tinyPng('tenth.jpg')]]))->assertSessionHasErrors('gallery_manifest');
        $this->assertSame($before, $product->images()->orderBy('sort_order')->get()->map->getAttributes()->all());
        $this->assertSame($files, Storage::disk('public')->allFiles());
    }

    public function test_twenty_options_save_but_the_twenty_first_and_excessive_matrix_total_do_not(): void
    {
        $options = array_map(fn ($i) => ['name' => 'Color '.$i], range(1, 20));
        $this->post('/seller/products', $this->payload(['variants' => json_encode(['colors' => $options])]))
            ->assertSessionHasNoErrors();
        $product = Product::sole();
        $before = $product->getRawOriginal();
        $options[] = ['name' => 'Color 21'];
        $this->put('/seller/products/'.$product->id, $this->payload(['variants' => json_encode(['colors' => $options])]))
            ->assertSessionHasErrors('colors');
        $this->assertSame($before, $product->fresh()->getRawOriginal());
        $matrix = ['colors' => [['name' => 'Natural'], ['name' => 'Brown']], 'combinations' => [
            ['color' => 'Natural', 'size' => null, 'stock' => 50000, 'extra_price' => '0.00'],
            ['color' => 'Brown', 'size' => null, 'stock' => 50000, 'extra_price' => '0.00'],
        ]];
        $this->put('/seller/products/'.$product->id, $this->payload(['stock' => 0, 'variants' => json_encode($matrix)]))
            ->assertSessionHasErrors('variants');
        $this->assertSame($before, $product->fresh()->getRawOriginal());
    }

    public function test_manual_stock_bounds_and_addition_overflow_retain_the_previous_stock(): void
    {
        $product = Product::factory()->create(['shop_id' => $this->shop->id, 'category_id' => $this->shop->root_category_id]);
        foreach ([0, 99999] as $quantity) {
            $this->patch('/seller/products/'.$product->id.'/stock', ['mode' => 'set', 'quantity' => $quantity])
                ->assertSessionHasNoErrors()->assertSessionHas('success');
            $this->assertSame($quantity, $product->fresh()->stock);
        }
        foreach ([-1, 100000, '2.5', 'abc'] as $quantity) {
            $this->patch('/seller/products/'.$product->id.'/stock', ['mode' => 'set', 'quantity' => $quantity])
                ->assertSessionHasErrors('quantity');
            $this->assertSame(99999, $product->fresh()->stock);
        }
        $this->patch('/seller/products/'.$product->id.'/stock', ['mode' => 'add', 'quantity' => 1])
            ->assertSessionHasErrors('quantity');
        $this->assertSame(99999, $product->fresh()->stock);
    }

    public function test_shop_description_and_real_five_mb_branding_boundaries(): void
    {
        $this->post('/seller/settings', ['description' => str_repeat('d', 500)])->assertSessionHasNoErrors();
        $this->post('/seller/settings', ['description' => str_repeat('d', 501)])->assertSessionHasErrors('description');
        $this->assertSame(str_repeat('d', 500), $this->shop->fresh()->description);
        $image = $this->tinyPng('brand.jpg');
        $jpeg = file_get_contents($image->getRealPath());
        foreach (['logo_file' => 'logo', 'banner_file' => 'banner'] as $field => $column) {
            foreach ([5138022, 5242880] as $size) {
                $source = UploadedFile::fake()->createWithContent('brand.jpg', $jpeg.str_repeat(' ', $size - strlen($jpeg)));
                $this->post('/seller/settings', [$field => new UploadedFile($source->getRealPath(), 'brand.jpg', null, null, true)])
                    ->assertSessionHasNoErrors()->assertSessionHas('success');
                Storage::disk('public')->assertExists(substr($this->shop->fresh()->$column, strlen('/storage/')));
            }
            $before = $this->shop->fresh()->$column;
            foreach (['large.jpg' => $jpeg.str_repeat(' ', 5347738 - strlen($jpeg)), 'price.docx' => 'invalid document',
                'installer.exe' => 'MZ executable content', 'renamed.jpg' => 'MZ executable content'] as $name => $content) {
                $source = UploadedFile::fake()->createWithContent($name, $content);
                $this->post('/seller/settings', [$field => new UploadedFile($source->getRealPath(), $name, null, null, true)])
                    ->assertSessionHasErrors($field);
                $this->assertSame($before, $this->shop->fresh()->$column);
            }
        }
    }

    public static function salePrices(): array
    {
        return [['450.01', false], ['450.00', false], ['449.99', true], ['0.99', false], ['1.00', true]];
    }

    #[DataProvider('salePrices')]
    public function test_sale_price_boundaries_do_not_create_invalid_schedules(string $price, bool $accepted): void
    {
        $product = Product::factory()->create(['shop_id' => $this->shop->id, 'price' => 450]);
        $input = ['kind' => 'store', 'name' => 'Tote sale', 'price' => $price,
            'starts_at' => now('Asia/Manila')->addMinutes(2)->format('Y-m-d\TH:i'),
            'ends_at' => now('Asia/Manila')->addHours(2)->format('Y-m-d\TH:i')];
        try {
            $sale = app(ProductSaleService::class)->save($product, $input);
            $this->assertTrue($accepted, 'An invalid sale price was saved.');
            $this->assertSame($price, $sale->price);
        } catch (ValidationException $error) {
            $this->assertFalse($accepted, 'An allowed sale price was rejected.');
            $this->assertArrayHasKey('price', $error->errors());
            $this->assertSame(0, $product->sales()->count());
        }
    }
}

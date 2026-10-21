<?php

namespace Tests\Feature\Seller;

use App\Models\Product;
use App\Models\Shop;
use App\Models\StoreCategory;
use App\Models\User;
use App\Services\Commerce\InventoryService;
use App\Services\Commerce\ProductSkuService;
use App\Services\Commerce\SellerListingActions;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VariantCatalogueAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->shop = Shop::factory()->approved()->create();
        $this->actingAs($this->shop->user);
    }

    private function variants(): array
    {
        return ['pricing_mode' => 'independent', 'option1_name' => 'Finish', 'option2_name' => 'Capacity',
            'colors' => [['name' => 'Natural'], ['name' => 'Black']], 'sizes' => [['name' => 'Compact'], ['name' => 'Large']],
            'combinations' => [
                ['color' => 'Natural', 'size' => 'Compact', 'stock' => 2, 'price' => '199.95', 'sku' => 'BGO-NAT-C'],
                ['color' => 'Natural', 'size' => 'Large', 'stock' => 3, 'price' => '349.50', 'sku' => 'BGO-NAT-L'],
                ['color' => 'Black', 'size' => 'Compact', 'stock' => 4, 'price' => '249.99', 'sku' => 'BGO-BLK-C'],
                ['color' => 'Black', 'size' => 'Large', 'stock' => 1, 'price' => '450.00', 'sku' => 'BGO-BLK-L'],
            ]];
    }

    private function listing(array $variants): array
    {
        return ['name' => 'Studio Canvas Travel Bag', 'description' => 'A durable canvas travel bag with reinforced stitching.',
            'category_id' => $this->shop->root_category_id, 'price' => '500.00', 'stock' => 10, 'weight_kg' => '0.80',
            'featured_image' => '/storage/products/canvas-bag.jpg', 'status' => 'active', 'sku' => 'BGO-CANVAS',
            'variants' => json_encode($variants), 'brand' => 'Bagoo Studio', 'condition' => 'used',
            'length_cm' => '30.00', 'width_cm' => '20.00', 'height_cm' => '5.00'];
    }

    public static function portals(): array
    {
        return [['/seller'], ['http://seller.localhost']];
    }

    #[DataProvider('portals')]
    public function test_complete_independent_rows_survive_reload_and_reach_buyer_prices_and_specs(string $portal): void
    {
        $this->post($portal.'/products', $this->listing($this->variants()))->assertSessionHasNoErrors();
        $product = Product::sole();
        $this->assertSame(10, $product->stock);
        $this->assertSame($this->variants()['combinations'], $product->variants['combinations']);
        $inventory = app(InventoryService::class);
        $this->assertSame('199.95', $inventory->regularUnitPrice($product, 'Natural', 'Compact'));
        $this->assertSame('450.00', $inventory->regularUnitPrice($product, 'Black', 'Large'));
        $this->assertSame('BGO-BLK-L', app(ProductSkuService::class)->selected($product, 'Black', 'Large'));
        $this->assertDatabaseCount('product_sku_claims', 5);
        $visible = StoreCategory::create(['shop_id' => $this->shop->id, 'name' => 'Travel bags', 'normalized_name' => 'travel bags', 'position' => 0, 'is_visible' => true]);
        $hidden = StoreCategory::create(['shop_id' => $this->shop->id, 'name' => 'Private collection', 'normalized_name' => 'private collection', 'position' => 1, 'is_visible' => false]);
        $product->storeCategories()->sync([$visible->id, $hidden->id]);
        foreach ([null, User::factory()->buyer()->create()] as $buyer) {
            if ($buyer) {
                $this->actingAs($buyer);
            } else {
                $this->app['auth']->forgetGuards();
            }
            $this->get('http://localhost:8000/product/'.$product->slug)->assertOk()->assertInertia(fn (Assert $page) => $page->component('Buyer/ProductDetail')
                ->where('product.condition', 'used')->where('product.brand', 'Bagoo Studio')
                ->where('product.weight_kg', '0.80')->where('product.length_cm', '30.00')->where('product.width_cm', '20.00')->where('product.height_cm', '5.00')
                ->where('product.price_range.min', '199.95')->where('product.price_range.max', '450.00')
                ->where('variations.pricing_mode', 'independent')->where('variations.combinations.3.sku', 'BGO-BLK-L')
                ->has('product.store_categories', 1)->where('product.store_categories.0.name', 'Travel bags'));
        }
    }

    public static function invalidRows(): array
    {
        return [
            ['price', ''], ['price', '0'], ['price', '-1'], ['price', '1.001'], ['price', '1e2'], ['price', '1000000.01'],
            ['stock', ''], ['stock', '-1'], ['stock', '1.5'], ['stock', '1e2'], ['stock', '100000'],
            ['sku', ''], ['sku', str_repeat('A', 51)], ['sku', 'BGO BAD'],
        ];
    }

    #[DataProvider('invalidRows')]
    public function test_invalid_row_fields_are_rejected_at_the_affected_field_without_partial_writes(string $field, string $value): void
    {
        $variants = $this->variants();
        $variants['combinations'][1][$field] = $value;
        $response = $this->postJson('/seller/products', $this->listing($variants))->assertUnprocessable()->assertJsonValidationErrors('combinations.1.'.$field);
        $this->assertSame(['combinations.1.'.$field], array_keys($response->json('errors')));
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('product_sku_claims', 0);
    }

    public function test_duplicate_case_insensitive_skus_and_cross_listing_collisions_cannot_publish(): void
    {
        $variants = $this->variants();
        $variants['combinations'][1]['sku'] = strtolower($variants['combinations'][0]['sku']);
        $this->postJson('/seller/products', $this->listing($variants))->assertUnprocessable()->assertJsonValidationErrors(['combinations.0.sku', 'combinations.1.sku']);
        $other = Product::factory()->create(['sku' => 'BGO-EXISTING']);
        $variants = $this->variants();
        $variants['combinations'][1]['sku'] = 'bgo-existing';
        $this->postJson('/seller/products', $this->listing($variants))->assertUnprocessable()->assertJsonValidationErrors('combinations.1.sku');
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('product_sku_claims', 1);
        $this->assertDatabaseHas('product_sku_claims', ['sku_key' => 'BGO-EXISTING', 'product_id' => $other->id]);
    }

    public function test_sku_registry_rejects_a_main_sku_collision_and_rolls_back_the_listing_edit(): void
    {
        $this->post('/seller/products', $this->listing($this->variants()))->assertSessionHasNoErrors();
        $product = Product::sole();
        $other = Product::factory()->create(['sku' => 'BGO-OTHER']);
        $before = $other->fresh()->getRawOriginal();
        try {
            $other->update(['sku' => 'bgo-blk-l', 'price' => '1.00']);
            $this->fail('A listing SKU cannot reuse another product’s variant SKU.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('sku', $exception->errors());
        }
        $this->assertSame($before, $other->fresh()->getRawOriginal());
        $this->assertDatabaseHas('product_sku_claims', ['sku_key' => 'BGO-BLK-L', 'product_id' => $product->id]);
        try {
            DB::table('product_sku_claims')->insert(['sku_key' => 'BGO-BLK-L', 'product_id' => $other->id]);
            $this->fail('The database must enforce the shared SKU key.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('UNIQUE', $exception->getMessage());
        }
    }

    public function test_copy_uses_fresh_skus_without_changing_original_combinations(): void
    {
        $this->post('/seller/products', $this->listing($this->variants()))->assertSessionHasNoErrors();
        $original = Product::sole();
        $copy = DB::transaction(fn () => app(SellerListingActions::class)->duplicate($this->shop, $original));
        $this->assertSame('draft', $copy->status);
        $this->assertSame($this->variants()['combinations'], $original->fresh()->variants['combinations']);
        $this->assertEmpty(array_intersect(array_column($original->variants['combinations'], 'sku'), array_column($copy->variants['combinations'], 'sku')));
        $this->assertSame(array_column($original->variants['combinations'], 'price'), array_column($copy->variants['combinations'], 'price'));
    }

    public function test_option_duplicates_empty_names_and_a_third_group_are_rejected(): void
    {
        $variants = $this->variants();
        $variants['colors'][1]['name'] = 'natural';
        $this->postJson('/seller/products', $this->listing($variants))->assertUnprocessable()->assertJsonValidationErrors(['colors.0.name', 'colors.1.name']);
        $variants = $this->variants();
        $variants['sizes'][1]['name'] = ' ';
        $this->postJson('/seller/products', $this->listing($variants))->assertUnprocessable()->assertJsonValidationErrors('sizes.1.name');
        $variants = $this->variants();
        $variants['option3_name'] = 'Material';
        $this->postJson('/seller/products', $this->listing($variants))->assertUnprocessable()->assertJsonValidationErrors('variants');
        $this->assertDatabaseCount('products', 0);
    }

    public function test_numeric_skus_and_expanding_case_normalization_keep_their_distinct_keys(): void
    {
        $variants = $this->variants();
        $variants['combinations'][0]['sku'] = '123456';
        $variants['combinations'][1]['sku'] = '0123456';
        $this->post('/seller/products', $this->listing($variants))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('product_sku_claims', ['sku_key' => '123456']);
        $this->assertDatabaseHas('product_sku_claims', ['sku_key' => '0123456']);
        $product = Product::factory()->create(['sku' => str_repeat('ß', 50)]);
        $this->assertDatabaseHas('product_sku_claims', ['product_id' => $product->id, 'sku_key' => str_repeat('SS', 50)]);
    }
}

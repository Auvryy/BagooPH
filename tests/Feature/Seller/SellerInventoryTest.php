<?php

namespace Tests\Feature\Seller;

use App\Models\Category;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\AccountRestrictionService;
use App\Services\ProductModerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SellerInventoryTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seller = User::factory()->seller()->create();
        $this->shop = Shop::factory()->approved()->create(['user_id' => $this->seller->id]);
    }

    private function listing(array $changes = []): array
    {
        return array_replace([
            'name' => 'Everyday Pet Carrier',
            'category_id' => $this->shop->root_category_id,
            'price' => '250.00',
            'stock' => 12,
            'sku' => 'BGO-INVENTORY',
            'description' => 'A lightweight carrier for everyday travel.',
            'status' => 'draft',
        ], $changes);
    }

    public static function portals(): array
    {
        return [['/seller'], ['http://seller.localhost']];
    }

    #[DataProvider('portals')]
    public function test_valid_drafts_stay_private_and_can_be_published_and_archived(string $portal): void
    {
        $this->actingAs($this->seller)->post($portal.'/products', $this->listing())
            ->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success', 'Product draft saved.');
        $product = Product::sole();
        $this->assertSame('draft', $product->status);
        $this->assertSame($this->shop->id, $product->shop_id);
        $this->assertFalse(Product::availableForSale()->whereKey($product->id)->exists());
        $this->get('http://localhost:8000/product/'.$product->slug)->assertNotFound();
        $this->get($portal.'/products?status=draft')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('products.data', 1)->where('products.data.0.id', $product->id));

        $this->put($portal.'/products/'.$product->id, $this->listing(['status' => 'active']))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue(Product::availableForSale()->whereKey($product->id)->exists());
        $this->get('http://localhost:8000/product/'.$product->fresh()->slug)->assertOk();

        $this->put($portal.'/products/'.$product->id, $this->listing(['status' => 'archived']))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('archived', $product->fresh()->status);
        $this->assertFalse(Product::availableForSale()->whereKey($product->id)->exists());
    }

    #[DataProvider('portals')]
    public function test_creation_supports_explicit_publish_and_existing_requests_without_status(string $portal): void
    {
        $input = $this->listing(['status' => 'active']);
        $this->actingAs($this->seller)->post($portal.'/products', $input)
            ->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success', 'Product published successfully.');
        unset($input['status']);
        $input['sku'] = 'BGO-LEGACY';
        $this->post($portal.'/products', $input)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(['active', 'active'], Product::orderBy('id')->pluck('status')->all());
    }

    public static function invalidCreationStatuses(): array
    {
        return [['archived'], ['suspended'], ['published'], [''], [null], [['active']]];
    }

    #[DataProvider('portals')]
    public function test_duplicate_custom_skus_reject_creation_and_publication_without_changing_listings(string $portal): void
    {
        Product::factory()->create(['sku' => 'BGO-TAKEN']);
        $owned = Product::factory()->create(['shop_id' => $this->shop->id, 'status' => 'draft']);
        $before = $owned->fresh()->getAttributes();
        $this->actingAs($this->seller)->postJson($portal.'/products', $this->listing(['sku' => 'BGO-TAKEN']))
            ->assertUnprocessable()->assertJsonValidationErrors('sku');
        $this->putJson($portal.'/products/'.$owned->id, $this->listing(['sku' => 'BGO-TAKEN', 'status' => 'active']))
            ->assertUnprocessable()->assertJsonValidationErrors('sku');
        $this->assertDatabaseCount('products', 2);
        $this->assertSame($before, $owned->fresh()->getAttributes());
        $this->put($portal.'/products/'.$owned->id, $this->listing(['sku' => $owned->sku, 'status' => 'active']))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('active', $owned->fresh()->status);
    }

    #[DataProvider('invalidCreationStatuses')]
    public function test_creation_rejects_every_other_listing_status(mixed $status): void
    {
        $this->actingAs($this->seller)->postJson('/seller/products', $this->listing(['status' => $status]))
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertDatabaseCount('products', 0);
    }

    #[DataProvider('portals')]
    public function test_drafts_still_require_valid_listing_fields_and_approved_category_scope(string $portal): void
    {
        $this->actingAs($this->seller)->postJson($portal.'/products', $this->listing(['price' => 0, 'stock' => -1, 'description' => '', 'name' => '']))
            ->assertUnprocessable()->assertJsonValidationErrors(['price', 'stock', 'description', 'name']);
        $foreign = Category::factory()->create();
        $this->postJson($portal.'/products', $this->listing(['category_id' => $foreign->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('category_id');
        $child = Category::factory()->create(['parent_id' => $this->shop->root_category_id, 'is_active' => false]);
        $this->postJson($portal.'/products', $this->listing(['category_id' => $child->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('category_id');
        $this->assertDatabaseCount('products', 0);
    }

    #[DataProvider('portals')]
    public function test_zero_variant_stock_survives_save_and_reload_while_null_uses_listing_stock(string $portal): void
    {
        $variants = ['sizes' => [
            ['id' => 'sold-out', 'name' => 'Small', 'stock' => 0],
            ['id' => 'fallback', 'name' => 'Large', 'stock' => null],
            ['id' => 'missing', 'name' => 'Medium'],
        ]];
        $this->actingAs($this->seller)->post($portal.'/products', $this->listing(['variants' => json_encode($variants)]))
            ->assertRedirect()->assertSessionHasNoErrors();
        $product = Product::sole();
        $this->assertSame([0, 12, 12], array_column($product->variants['sizes'], 'stock'));
        $this->put($portal.'/products/'.$product->id, $this->listing(['stock' => 9, 'variants' => json_encode($product->variants)]))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->get($portal.'/products?status=draft')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('products.data.0.stock', 9)->where('products.data.0.variants.sizes.0.stock', 0)
            ->where('products.data.0.variants.sizes.1.stock', 12)->where('products.data.0.variants.sizes.2.stock', 12));
    }

    #[DataProvider('portals')]
    public function test_publishing_a_moderated_draft_cannot_lift_its_restriction_or_change_history(string $portal): void
    {
        $product = Product::factory()->create(['shop_id' => $this->shop->id, 'status' => 'draft']);
        $admin = User::factory()->admin()->create();
        $moderation = app(ProductModerationService::class);
        $decision = $moderation->decide($admin, $product, [
            'action' => 'remove', 'reason' => 'Correct the product information before another review.',
            'source_token' => app(AccountRestrictionService::class)->token($moderation->state($product->fresh())),
        ]);
        $history = $decision->fresh()->getAttributes();
        $this->actingAs($this->seller)->put($portal.'/products/'.$product->id, $this->listing(['status' => 'active']))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('active', $product->fresh()->status);
        $this->assertTrue($product->fresh()->compliance_restricted);
        $this->assertSame(1, $product->fresh()->moderation_version);
        $this->assertSame($history, $decision->fresh()->getAttributes());
        $this->assertFalse(Product::availableForSale()->whereKey($product->id)->exists());
        $this->get('http://localhost:8000/product/'.$product->fresh()->slug)->assertNotFound();
        $this->putJson($portal.'/products/'.$product->id, $this->listing(['status' => 'active', 'compliance_restricted' => false, 'moderation_version' => 0]))
            ->assertUnprocessable()->assertJsonValidationErrors(['compliance_restricted', 'moderation_version']);
        $this->get($portal.'/products')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('products.data.0.compliance_restricted', true)
            ->where('products.data.0.compliance_feedback.reason', $decision->reason));
    }

    #[DataProvider('portals')]
    public function test_foreign_draft_publishing_is_forbidden_and_owned_category_changes_are_validated(string $portal): void
    {
        $foreign = Product::factory()->create(['status' => 'draft']);
        $before = $foreign->fresh()->getAttributes();
        $this->actingAs($this->seller)->putJson($portal.'/products/'.$foreign->id, $this->listing(['status' => 'active']))->assertForbidden();
        $this->assertSame($before, $foreign->fresh()->getAttributes());
        $owned = Product::factory()->create(['shop_id' => $this->shop->id, 'status' => 'draft']);
        $outside = Category::factory()->create();
        $this->putJson($portal.'/products/'.$owned->id, $this->listing(['status' => 'active', 'category_id' => $outside->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('category_id');
        $this->assertSame('draft', $owned->fresh()->status);
    }

    public static function restrictionPortals(): array
    {
        $cases = [];
        foreach (['/seller', 'http://seller.localhost'] as $portal) {
            foreach (['seller_suspended', 'seller_rejected', 'shop_suspended', 'shop_unreviewed', 'root_inactive'] as $change) {
                $cases[$portal.' '.$change] = [$portal, $change];
            }
        }

        return $cases;
    }

    #[DataProvider('restrictionPortals')]
    public function test_changed_eligibility_after_loading_blocks_draft_creation_and_publishing(string $portal, string $change): void
    {
        $product = Product::factory()->create(['shop_id' => $this->shop->id, 'status' => 'draft']);
        $before = $product->fresh()->getAttributes();
        $this->actingAs($this->seller)->get($portal.'/products')->assertOk();
        match ($change) {
            'seller_suspended' => $this->seller->update(['status' => 'suspended']),
            'seller_rejected' => $this->seller->update(['kyc_status' => 'rejected']),
            'shop_suspended' => $this->shop->update(['status' => 'suspended']),
            'shop_unreviewed' => $this->shop->update(['review_status' => 'legacy']),
            'root_inactive' => Category::whereKey($this->shop->root_category_id)->update(['is_active' => false]),
        };
        $this->post($portal.'/products', $this->listing())->assertRedirect();
        $this->actingAs($this->seller)->put($portal.'/products/'.$product->id, $this->listing(['status' => 'active']))->assertRedirect();
        $this->assertDatabaseCount('products', 1);
        $this->assertSame($before, $product->fresh()->getAttributes());
    }

    #[DataProvider('portals')]
    public function test_combined_filters_search_all_owned_listings_and_retain_stable_page_order(string $portal): void
    {
        $category = Category::factory()->create(['parent_id' => $this->shop->root_category_id, 'is_active' => true]);
        $rows = Product::factory()->count(13)->create([
            'shop_id' => $this->shop->id, 'category_id' => $category->id, 'name' => 'BGO-FILTER Carrier',
            'status' => 'active', 'stock' => 5, 'updated_at' => now()->subDay(),
        ]);
        Product::factory()->create(['shop_id' => $this->shop->id, 'category_id' => $category->id, 'name' => 'BGO-FILTER Carrier', 'status' => 'draft', 'stock' => 2]);
        Product::factory()->create(['shop_id' => $this->shop->id, 'name' => 'BGO-FILTER Carrier', 'status' => 'active', 'stock' => 1]);
        Product::factory()->create(['shop_id' => $this->shop->id, 'category_id' => $category->id, 'name' => 'BGO-FILTER Carrier', 'stock' => 6]);
        Product::factory()->create(['name' => 'BGO-FILTER Carrier', 'stock' => 5]);
        $filters = ['search' => '  bgo-filter  ', 'category_id' => $category->id, 'status' => 'active', 'stock' => 'low_stock'];
        $expected = $rows->pluck('id')->reverse()->values()->all();
        $this->actingAs($this->seller)->get($portal.'/products?'.http_build_query([...$filters, 'unused' => 'ignore']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->has('products.data', 10)->where('products.total', 13)
            ->where('filters', [...$filters, 'search' => 'bgo-filter'])
            ->where('products.data', fn ($data) => collect($data)->pluck('id')->all() === array_slice($expected, 0, 10))
            ->where('products.next_page_url', function ($url) use ($filters) {
                parse_str(parse_url($url, PHP_URL_QUERY), $query);

                return $query == ['search' => 'bgo-filter', 'category_id' => (string) $filters['category_id'], 'status' => 'active', 'stock' => 'low_stock', 'page' => '2'];
            }));
        $this->get($portal.'/products?'.http_build_query([...$filters, 'page' => 2]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('products.current_page', 2)->has('products.data', 3)
                ->where('products.data', fn ($data) => collect($data)->pluck('id')->all() === array_slice($expected, 10)));
    }

    public function test_dashboard_alerts_match_active_only_worklists_at_stock_boundaries(): void
    {
        foreach ([0, 1, 5, 6] as $stock) {
            Product::factory()->create(['shop_id' => $this->shop->id, 'stock' => $stock]);
        }
        Product::factory()->create(['shop_id' => $this->shop->id, 'stock' => 20, 'variants' => ['sizes' => [['id' => 'small', 'name' => 'Small', 'stock' => 0]]]]);
        foreach (['draft', 'archived'] as $status) {
            foreach ([0, 3] as $stock) {
                Product::factory()->create(['shop_id' => $this->shop->id, 'stock' => $stock, 'status' => $status]);
            }
        }
        Product::factory()->create(['stock' => 1]);
        Product::factory()->create(['stock' => 0]);
        $this->actingAs($this->seller)->get('/seller/dashboard')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('stats.lowStockCount', 2)->where('stats.outOfStockCount', 1));
        foreach (['low_stock' => 2, 'out_of_stock' => 1, 'in_stock' => 4] as $stock => $count) {
            $this->get('/seller/products?stock='.$stock)->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('products.total', $count)->where('products.data', fn ($data) => collect($data)->every(fn ($product) => $product['status'] === 'active')));
        }
        $this->get('/seller/products?status=draft&stock=low_stock')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('products.total', 0));
        foreach (['draft', 'archived'] as $status) {
            $this->get('/seller/products?status='.$status)->assertOk()->assertInertia(fn (Assert $page) => $page->where('products.total', 2));
        }
    }

    #[DataProvider('portals')]
    public function test_stale_filtered_pages_clamp_after_publication_and_for_empty_worklists(string $portal): void
    {
        $rows = Product::factory()->count(13)->create(['shop_id' => $this->shop->id, 'status' => 'draft']);
        $this->actingAs($this->seller)->get($portal.'/products?status=draft&page=999999')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('products.current_page', 2)->has('products.data', 3));
        foreach ($rows->take(3) as $product) {
            $this->put($portal.'/products/'.$product->id, $this->listing(['status' => 'active', 'sku' => $product->sku]))->assertRedirect()->assertSessionHasNoErrors();
        }
        $this->get($portal.'/products?status=draft&page=2')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('products.current_page', 1)->where('products.last_page', 1)->where('products.total', 10)
            ->where('filters.status', 'draft')->has('products.data', 10));
        $this->get($portal.'/products?stock=out_of_stock&page=2')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('products.current_page', 1)->where('products.total', 0)->has('products.data', 0));
    }

    public static function invalidFilters(): array
    {
        return [
            ['status', 'published'], ['status', ['active']], ['stock', 'negative'], ['stock', ['low_stock']],
            ['search', ['name']], ['search', str_repeat('a', 101)], ['page', 0], ['page', '1.5'],
            ['page', '1e2'], ['page', 1000001], ['page', ['1']], ['category_id', '1.5'], ['category_id', ['1']],
        ];
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_filter_shapes_and_values_are_rejected(string $field, mixed $value): void
    {
        $this->actingAs($this->seller)->getJson('/seller/products?'.http_build_query([$field => $value]))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    #[DataProvider('portals')]
    public function test_category_filter_cannot_select_foreign_or_inactive_scope(string $portal): void
    {
        $foreign = Category::factory()->create();
        $inactive = Category::factory()->create(['parent_id' => $this->shop->root_category_id, 'is_active' => false]);
        foreach ([$foreign->id, $inactive->id, 999999] as $id) {
            $this->actingAs($this->seller)->getJson($portal.'/products?category_id='.$id)
                ->assertUnprocessable()->assertJsonValidationErrors('category_id');
        }
    }
}

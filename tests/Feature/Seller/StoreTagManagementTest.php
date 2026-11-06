<?php

namespace Tests\Feature\Seller;

use App\Models\Category;
use App\Models\Product;
use App\Models\Shop;
use App\Models\StoreCategory;
use App\Models\StoreTag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StoreTagManagementTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['ai.api_key' => 'test-key', 'ai.model' => 'test-model', 'ai.base_url' => 'https://assistant.invalid/v1beta']);
        $this->shop = Shop::factory()->approved()->create();
        $this->actingAs($this->shop->user);
    }

    private function fakeTags(array $tags): void
    {
        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode(['tags' => $tags])]]]]]])]);
    }

    private function listing(array $extra = []): array
    {
        return [...[
            'name' => 'Everyday Canvas Carryall',
            'description' => 'A reinforced canvas carryall with durable handles.',
            'price' => '450.00', 'stock' => 20, 'weight_kg' => '0.80',
            'category_id' => $this->shop->root_category_id,
            'featured_image' => '/storage/products/carryall.jpg', 'status' => 'active',
            'tag_names' => [],
        ], ...$extra];
    }

    public static function portals(): array
    {
        return [['/seller'], ['http://seller.localhost']];
    }

    #[DataProvider('portals')]
    public function test_seller_tag_page_and_flat_tag_manager_work_on_both_seller_hosts(string $portal): void
    {
        $this->fakeTags(['carryall']);
        $this->post($portal.'/store-tags', ['name' => 'carryall', 'product_name' => 'Everyday Canvas Carryall'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $tag = StoreTag::sole();
        $this->get($portal.'/store-tags')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Seller/StoreTags')->where('tags.0.name', 'carryall')->missing('tags.0.parent_id'));
        $this->assertSame($this->shop->id, $tag->shop_id);
        Http::assertSent(fn ($request) => str_contains($request->body(), 'Pet Supplies')
            && str_contains($request->body(), 'Everyday Canvas Carryall')
            && str_contains($request->body(), 'carryall'));
    }

    public function test_inline_tag_check_accepts_relevant_tags_without_creating_rows(): void
    {
        $this->fakeTags(['carryall']);
        $this->postJson('/seller/products/assist-tags', ['product_name' => 'Everyday Canvas Carryall', 'tag' => 'carryall'])
            ->assertOk()->assertJsonPath('tag', 'carryall');
        $this->assertDatabaseCount('store_tags', 0);
    }

    public function test_inline_tag_check_rejects_off_department_tags_without_creating_rows(): void
    {
        $this->fakeTags([]);
        $this->postJson('/seller/products/assist-tags', ['product_name' => 'Everyday Canvas Carryall', 'tag' => 'hollow blocks'])
            ->assertUnprocessable()->assertJsonValidationErrors('tag_names');
        $this->assertDatabaseCount('store_tags', 0);
    }

    public function test_tag_manager_requires_a_product_name_before_gemini_relevance_review(): void
    {
        Http::fake();
        $this->postJson('/seller/store-tags', ['name' => 'carryall'])
            ->assertUnprocessable()->assertJsonValidationErrors('product_name');

        Http::assertNothingSent();
        $this->assertDatabaseCount('store_tags', 0);
    }

    public function test_unused_tag_cannot_be_renamed_without_product_context(): void
    {
        $tag = StoreTag::create(['shop_id' => $this->shop->id, 'name' => 'carryall', 'normalized_name' => 'carryall']);
        Http::fake();

        $this->putJson('/seller/store-tags/'.$tag->id, ['name' => 'travel pouch'])
            ->assertUnprocessable()->assertJsonValidationErrors('product_name');

        Http::assertNothingSent();
        $this->assertSame('carryall', $tag->fresh()->name);
    }

    public function test_renaming_a_shared_tag_checks_relevance_against_every_linked_product(): void
    {
        $first = Product::factory()->create(['shop_id' => $this->shop->id, 'name' => 'Everyday Canvas Carryall', 'category_id' => $this->shop->root_category_id]);
        $second = Product::factory()->create(['shop_id' => $this->shop->id, 'name' => 'Compact Weekend Case', 'category_id' => $this->shop->root_category_id]);
        $tag = StoreTag::create(['shop_id' => $this->shop->id, 'name' => 'carryall', 'normalized_name' => 'carryall']);
        $first->storeTags()->sync([$tag->id]);
        $second->storeTags()->sync([$tag->id]);
        $this->fakeTags([]);

        $this->putJson('/seller/store-tags/'.$tag->id, ['name' => 'travel pouch'])
            ->assertUnprocessable()->assertJsonValidationErrors('tag_names');

        Http::assertSent(fn ($request) => str_contains($request->body(), 'Everyday Canvas Carryall')
            && str_contains($request->body(), 'Compact Weekend Case')
            && str_contains($request->body(), 'product_names'));
        $this->assertSame('carryall', $tag->fresh()->name);
        $this->assertSame([$tag->id], $first->fresh()->storeTags()->pluck('store_tags.id')->all());
        $this->assertSame([$tag->id], $second->fresh()->storeTags()->pluck('store_tags.id')->all());
    }

    public function test_product_save_rechecks_new_tags_and_search_finds_the_saved_product_after_a_fresh_request(): void
    {
        $this->fakeTags(['weekend trip']);
        $this->post('/seller/products', $this->listing(['tag_names' => ['weekend trip']]))->assertRedirect()->assertSessionHasNoErrors();
        $product = Product::sole();
        $this->assertSame([$this->shop->root_category_id], [$product->fresh()->category_id]);
        $this->assertSame(['weekend trip'], $product->storeTags()->pluck('name')->all());
        Http::assertSent(fn ($request) => str_contains($request->body(), 'approved_department')
            && str_contains($request->body(), 'Pet Supplies') && str_contains($request->body(), 'submitted_tags'));

        $this->get('/buyer/search?search=weekend')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('products.total', 1)->where('products.data.0.id', $product->id));
    }

    public function test_same_search_request_refreshes_after_a_new_product_is_published(): void
    {
        $this->get('/buyer/search?search=brief')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('filters.search', 'brief')->where('products.total', 0));

        $this->post('/seller/products', $this->listing(['name' => 'Everyday Brief Case', 'tag_names' => []]))
            ->assertRedirect()->assertSessionHasNoErrors();
        $product = Product::sole();

        $this->get('/buyer/search?search=brief')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('filters.search', 'brief')->where('products.total', 1)->where('products.data.0.id', $product->id));
    }

    public function test_assist_tags_returns_read_only_department_scoped_suggestions(): void
    {
        $this->fakeTags(['carryall', 'canvas bag']);
        $this->postJson('/seller/products/assist-tags', ['product_name' => 'Everyday Canvas Carryall', 'tags' => ['carryall']])
            ->assertOk()->assertExactJson(['tags' => ['carryall', 'canvas bag']]);
        $this->assertDatabaseCount('store_tags', 0);
        Http::assertSent(fn ($request) => str_contains($request->body(), 'Pet Supplies')
            && str_contains($request->body(), 'Everyday Canvas Carryall'));
    }

    public function test_rejected_tags_fail_closed_without_a_partial_listing_or_tag(): void
    {
        $this->fakeTags([]);
        $this->postJson('/seller/products', $this->listing(['tag_names' => ['unrelated medical cure']]))
            ->assertUnprocessable()->assertJsonValidationErrors('tag_names');
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('store_tags', 0);
    }

    public static function invalidTagSets(): array
    {
        return [
            'empty name' => [['']],
            'overlong name' => [[str_repeat('x', 51)]],
            'markup' => [['<b>canvas</b>']],
            'duplicate normalized names' => [['Canvas', ' canvas  ']],
            'too many names' => [array_map(fn ($index) => 'tag '.$index, range(1, 21))],
        ];
    }

    #[DataProvider('invalidTagSets')]
    public function test_product_tag_bounds_and_unique_names_are_enforced_before_ai(array $names): void
    {
        Http::fake();
        $this->postJson('/seller/products', $this->listing(['tag_names' => $names]))->assertUnprocessable();
        Http::assertNothingSent();
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('store_tags', 0);
    }

    public function test_one_character_tag_is_accepted(): void
    {
        $this->fakeTags(['x']);
        $this->post('/seller/store-tags', ['name' => 'x', 'product_name' => 'Everyday Canvas Carryall'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('store_tags', 1);
    }

    public function test_case_insensitive_duplicate_tag_names_are_rejected(): void
    {
        StoreTag::create(['shop_id' => $this->shop->id, 'name' => 'x', 'normalized_name' => 'x']);
        Http::fake();
        $this->post('/seller/store-tags', ['name' => ' X ', 'product_name' => 'Everyday Canvas Carryall'])
            ->assertSessionHasErrors('name');
        Http::assertNothingSent();
        $this->assertDatabaseCount('store_tags', 1);
    }

    public function test_fifty_character_tag_is_accepted(): void
    {
        $name = str_repeat('a', 50);
        $this->fakeTags([$name]);
        $this->post('/seller/store-tags', ['name' => $name, 'product_name' => 'Everyday Canvas Carryall'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('store_tags', ['shop_id' => $this->shop->id, 'name' => $name]);
    }

    public function test_new_tags_on_existing_product_are_rechecked_and_failure_keeps_prior_listing_and_tags(): void
    {
        $this->fakeTags(['carryall']);
        $this->post('/seller/products', $this->listing(['tag_names' => ['carryall']]))->assertSessionHasNoErrors();
        $product = Product::sole();
        $before = $product->fresh()->getRawOriginal();
        $this->fakeTags([]);
        $this->putJson('/seller/products/'.$product->id, $this->listing(['tag_names' => ['carryall', 'unrelated']]))
            ->assertUnprocessable()->assertJsonValidationErrors('tag_names');
        $this->assertSame($before, $product->fresh()->getRawOriginal());
        $this->assertSame(['carryall'], $product->storeTags()->pluck('store_tags.name')->all());
        $this->assertDatabaseCount('store_tags', 1);
    }

    public function test_shop_storefront_filters_by_flat_tag_and_legacy_tree_urls_redirect(): void
    {
        $this->fakeTags(['carryall']);
        $this->post('/seller/products', $this->listing(['tag_names' => ['carryall']]))->assertSessionHasNoErrors();
        $product = Product::sole();
        $tag = StoreTag::sole();
        $this->get('/shop/'.$this->shop->slug.'?tag='.$tag->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('products.total', 1)->where('products.data.0.id', $product->id)->where('filters.tag', (string) $tag->id)
            ->where('storeTags.0.name', 'carryall'));
        $this->get('/shop/'.$this->shop->slug.'?tag=999999')->assertNotFound();
        $this->get('/shop/'.$this->shop->slug.'/categories/1')->assertRedirect('/shop/'.$this->shop->slug);
    }

    public function test_historical_categories_and_product_links_are_retained_and_no_longer_mutable(): void
    {
        $legacy = StoreCategory::create(['shop_id' => $this->shop->id, 'name' => 'Old collection', 'normalized_name' => 'old collection',
            'position' => 0, 'is_visible' => true]);
        $product = Product::factory()->create(['shop_id' => $this->shop->id, 'category_id' => $this->shop->root_category_id]);
        $product->storeCategories()->sync([$legacy->id]);
        $beforeCategories = Category::orderBy('id')->get()->toArray();

        $this->get('/seller/store-categories')->assertRedirect('/seller/store-tags');
        $this->postJson('/seller/store-categories', ['name' => 'New tree row', 'is_visible' => true])->assertStatus(405);
        $this->assertDatabaseHas('store_categories', ['id' => $legacy->id, 'name' => 'Old collection']);
        $this->assertSame([$legacy->id], $product->storeCategories()->pluck('store_categories.id')->all());
        $this->assertSame($beforeCategories, Category::orderBy('id')->get()->toArray());

        $this->put('/seller/products/'.$product->id, $this->listing(['tag_names' => []]))->assertSessionHasNoErrors();
        $this->assertSame([$legacy->id], $product->fresh()->storeCategories()->pluck('store_categories.id')->all());
        $this->assertSame($this->shop->root_category_id, $product->fresh()->category_id);
    }

    public function test_product_department_is_exactly_the_approved_signup_root(): void
    {
        $child = Category::factory()->create(['parent_id' => $this->shop->root_category_id]);
        $this->postJson('/seller/products', $this->listing(['category_id' => $child->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('category_id');
        $this->assertDatabaseCount('products', 0);
        $this->post('/seller/products', $this->listing())->assertSessionHasNoErrors();
        $this->assertSame($this->shop->root_category_id, Product::sole()->category_id);
    }
}

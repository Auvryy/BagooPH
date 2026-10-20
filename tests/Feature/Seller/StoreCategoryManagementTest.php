<?php

namespace Tests\Feature\Seller;

use App\Models\Category;
use App\Models\Product;
use App\Models\Shop;
use App\Models\StoreCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StoreCategoryManagementTest extends TestCase
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

    public static function portals(): array
    {
        return [['/seller'], ['http://seller.localhost']];
    }

    private function category(string $name, ?StoreCategory $parent = null, array $extra = []): StoreCategory
    {
        return StoreCategory::create([...['shop_id' => $this->shop->id, 'parent_id' => $parent?->id,
            'name' => $name, 'normalized_name' => mb_strtolower($name), 'position' => StoreCategory::count(), 'is_visible' => true], ...$extra]);
    }

    private function listing(array $extra = []): array
    {
        return [...['name' => 'Everyday Travel Carrier', 'description' => 'A durable travel carrier for everyday use.',
            'price' => '450.00', 'stock' => 20, 'weight_kg' => '0.80', 'category_id' => $this->shop->root_category_id,
            'featured_image' => '/storage/products/carrier.jpg', 'status' => 'active'], ...$extra];
    }

    private function product(array $categoryIds): Product
    {
        $product = Product::factory()->create(['shop_id' => $this->shop->id]);
        $product->storeCategories()->sync($categoryIds);

        return $product;
    }

    private function storefront(string $query = ''): string
    {
        return 'http://localhost:8000/shop/'.$this->shop->slug.$query;
    }

    #[DataProvider('portals')]
    public function test_create_assign_and_browse_store_category_with_owned_product_count(string $portal): void
    {
        $this->post($portal.'/store-categories', ['name' => 'Everyday travel', 'parent_id' => '', 'is_visible' => true])->assertSessionHasNoErrors();
        $category = StoreCategory::sole();
        $this->post($portal.'/products', $this->listing(['store_category_ids' => [$category->id]]))->assertSessionHasNoErrors();
        $product = Product::sole();
        $this->get($portal.'/store-categories')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Seller/StoreCategories')->where('categories.0.products_count', 1));
        $this->get($this->storefront('/categories/'.$category->id))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('products.total', 1)->where('products.data.0.id', $product->id)->where('filters.store_category', (string) $category->id));
    }

    public function test_category_name_boundaries_spaces_and_normalized_same_store_duplicates(): void
    {
        foreach (['', '   ', str_repeat('x', 51), ['array'], '<b>Category</b>'] as $name) {
            $this->postJson('/seller/store-categories', ['name' => $name, 'is_visible' => true])->assertUnprocessable()->assertJsonValidationErrors('name');
        }
        $this->assertDatabaseCount('store_categories', 0);
        foreach (['A', str_repeat('x', 50), '  Travel   supplies  '] as $name) {
            $this->post('/seller/store-categories', ['name' => $name, 'is_visible' => true])->assertSessionHasNoErrors();
        }
        $original = StoreCategory::where('name', 'Travel supplies')->sole();
        foreach (['Travel supplies', 'TRAVEL SUPPLIES', ' travel  supplies '] as $name) {
            $this->postJson('/seller/store-categories', ['name' => $name, 'is_visible' => true])->assertUnprocessable()->assertJsonValidationErrors('name');
        }
        $other = StoreCategory::where('name', 'A')->sole();
        $this->putJson('/seller/store-categories/'.$other->id, ['name' => 'travel supplies', 'is_visible' => true])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertSame('A', $other->fresh()->name);
        $this->assertSame('Travel supplies', $original->fresh()->name);
        $this->assertDatabaseCount('store_categories', 3);
    }

    public function test_same_name_is_allowed_in_another_store_without_affecting_the_first(): void
    {
        $original = $this->category('Daily essentials');
        $other = Shop::factory()->approved()->create();
        $this->actingAs($other->user)->post('/seller/store-categories', ['name' => 'DAILY ESSENTIALS', 'is_visible' => true])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('store_categories', 2);
        $this->assertSame('Daily essentials', $original->fresh()->name);
        $this->assertDatabaseHas('store_categories', ['shop_id' => $other->id, 'normalized_name' => $original->normalized_name]);
    }

    public function test_rename_and_reorder_survive_reload_and_keep_product_memberships(): void
    {
        $first = $this->category('Everyday');
        $second = $this->category('Travel');
        $product = $this->product([$second->id]);
        $this->put('/seller/store-categories/'.$second->id, ['name' => 'Weekend travel', 'is_visible' => true])->assertSessionHasNoErrors();
        $this->post('/seller/store-categories/reorder', ['category_ids' => [$second->id, $first->id]])->assertSessionHasNoErrors();
        $this->get('/seller/store-categories')->assertOk()->assertInertia(fn (Assert $page) => $page->where('categories.0.name', 'Weekend travel'));
        $this->get($this->storefront())->assertOk()->assertInertia(fn (Assert $page) => $page->where('storeCategories.0.id', $second->id));
        $this->get($this->storefront('?store_category='.$second->id))->assertOk()->assertInertia(fn (Assert $page) => $page->where('products.data.0.id', $product->id));
        $this->assertSame([$second->id], $product->storeCategories()->pluck('store_categories.id')->all());
    }

    public function test_hiding_a_parent_hides_its_children_and_urls_but_not_the_products_and_show_restores_position(): void
    {
        $root = $this->category('Travel');
        $child = $this->category('Compact carriers', $root);
        $product = $this->product([$child->id]);
        $position = $root->position;
        $this->put('/seller/store-categories/'.$root->id, ['name' => $root->name, 'is_visible' => false])->assertSessionHasNoErrors();
        $this->get($this->storefront())->assertOk()->assertInertia(fn (Assert $page) => $page->has('storeCategories', 0)->where('products.total', 1));
        foreach ([$root, $child] as $category) {
            $this->get($this->storefront('/categories/'.$category->id))->assertNotFound();
            $this->get($this->storefront('?store_category='.$category->id))->assertNotFound();
        }
        $this->get('http://localhost:8000/product/'.$product->slug)->assertOk();
        $this->get('http://localhost:8000/products?search='.urlencode($product->name))->assertOk()->assertInertia(fn (Assert $page) => $page->where('products.total', 1));
        $this->put('/seller/store-categories/'.$root->id, ['name' => $root->name, 'is_visible' => true])->assertSessionHasNoErrors();
        $this->assertSame($position, $root->fresh()->position);
        $this->get($this->storefront('/categories/'.$root->id))->assertOk()->assertInertia(fn (Assert $page) => $page->where('products.total', 1));
    }

    #[DataProvider('portals')]
    public function test_leaf_delete_preview_and_confirmation_uncategorize_without_deleting_or_hiding_products(string $portal): void
    {
        $category = $this->category('Daily essentials');
        $product = $this->product([$category->id]);
        $before = $product->fresh()->getRawOriginal();
        $preview = $this->getJson($portal.'/store-categories/'.$category->id.'/preview')->assertOk()->assertJsonPath('product_count', 1)->json();
        $this->assertDatabaseHas('store_categories', ['id' => $category->id]);
        $this->assertSame([$category->id], $product->storeCategories()->pluck('store_categories.id')->all());
        $this->deleteJson($portal.'/store-categories/'.$category->id, ['version' => str_repeat('a', 64)])->assertUnprocessable();
        $this->delete($portal.'/store-categories/'.$category->id, ['version' => $preview['version']])->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('store_categories', ['id' => $category->id]);
        $this->assertSame($before, $product->fresh()->getRawOriginal());
        $this->get($this->storefront('?store_category=uncategorized'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('products.total', 1)->where('products.data.0.id', $product->id));
    }

    public function test_parent_delete_lists_children_and_distinct_products_and_preserves_unrelated_memberships(): void
    {
        $root = $this->category('Daily essentials');
        $one = $this->category('Compact', $root);
        $two = $this->category('Roomy', $root);
        $other = $this->category('Weekend');
        $first = $this->product([$root->id, $one->id]);
        $second = $this->product([$two->id, $other->id]);
        $preview = $this->getJson('/seller/store-categories/'.$root->id.'/preview')->assertOk()->assertJsonCount(2, 'children')->assertJsonPath('product_count', 2)->json();
        $this->delete('/seller/store-categories/'.$root->id, ['version' => $preview['version']])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('store_categories', 1);
        $this->assertDatabaseCount('products', 2);
        $this->assertSame(0, $first->storeCategories()->count());
        $this->assertSame([$other->id], $second->storeCategories()->pluck('store_categories.id')->all());
        $this->assertSame('active', $first->fresh()->status);
        $this->assertSame('active', $second->fresh()->status);
    }

    public function test_depth_cycles_foreign_parents_and_mixed_reorder_are_rejected_before_writes(): void
    {
        $root = $this->category('Everyday');
        $this->post('/seller/store-categories', ['name' => 'Compact', 'parent_id' => $root->id, 'is_visible' => true])->assertSessionHasNoErrors();
        $child = StoreCategory::where('name', 'Compact')->sole();
        $other = $this->category('Weekend');
        $foreignShop = Shop::factory()->approved()->create();
        $foreign = $this->category('Foreign', extra: ['shop_id' => $foreignShop->id]);
        foreach ([$child->id, $foreign->id] as $parent) {
            $this->postJson('/seller/store-categories', ['name' => 'Too deep or foreign', 'parent_id' => $parent, 'is_visible' => true])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        }
        foreach ([$root->id, $other->id] as $parent) {
            $this->putJson('/seller/store-categories/'.$root->id, ['name' => $root->name, 'parent_id' => $parent, 'is_visible' => true])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        }
        foreach ([[$root->id], [$root->id, $child->id], [$root->id, $foreign->id], [$root->id, $root->id]] as $ids) {
            $this->postJson('/seller/store-categories/reorder', ['category_ids' => $ids])->assertUnprocessable();
        }
        $this->assertDatabaseCount('store_categories', 4);
        $this->assertNull($root->fresh()->parent_id);
    }

    public function test_three_memberships_are_accepted_four_rejected_and_empty_form_data_can_clear_them(): void
    {
        $categories = collect(['Everyday', 'Weekend', 'Compact', 'Roomy'])->map(fn ($name) => $this->category($name));
        $ids = $categories->take(3)->pluck('id')->all();
        $this->post('/seller/products', $this->listing(['store_category_ids' => $ids]))->assertSessionHasNoErrors();
        $product = Product::sole();
        foreach ($ids as $id) {
            $this->get($this->storefront('?store_category='.$id))->assertOk()->assertInertia(fn (Assert $page) => $page->where('products.total', 1));
        }
        $this->putJson('/seller/products/'.$product->id, $this->listing(['store_category_ids' => $categories->pluck('id')->all()]))->assertUnprocessable()->assertJsonValidationErrors('store_category_ids');
        $this->assertSame($ids, $product->storeCategories()->pluck('store_categories.id')->all());
        $this->put('/seller/products/'.$product->id, $this->listing(['store_category_ids' => '[]']))->assertSessionHasNoErrors();
        $this->assertSame(0, $product->storeCategories()->count());
        $this->assertSame($this->shop->root_category_id, $product->fresh()->category_id);
        foreach (['[1,1]', '{}', 'bad', [999999], ['bad']] as $invalid) {
            $this->putJson('/seller/products/'.$product->id, $this->listing(['store_category_ids' => $invalid]))->assertUnprocessable();
        }
        $this->assertSame(0, $product->storeCategories()->count());
    }

    public function test_store_taxonomy_does_not_modify_the_approved_platform_taxonomy_and_foreign_categories_are_private(): void
    {
        $before = Category::orderBy('id')->get()->toArray();
        $category = $this->category('Different merchandising label');
        $product = $this->product([$category->id]);
        $this->put('/seller/store-categories/'.$category->id, ['name' => 'New store label', 'is_visible' => true])->assertSessionHasNoErrors();
        $this->assertSame($before, Category::orderBy('id')->get()->toArray());
        $this->assertSame($this->shop->root_category_id, $product->fresh()->category_id);
        $otherShop = Shop::factory()->approved()->create();
        $this->actingAs($otherShop->user);
        $this->putJson('/seller/store-categories/'.$category->id, ['name' => 'Foreign edit', 'is_visible' => true])->assertNotFound();
        $this->getJson('/seller/store-categories/'.$category->id.'/preview')->assertNotFound();
        $this->postJson('/seller/products', $this->listing(['store_category_ids' => [$category->id], 'category_id' => $otherShop->root_category_id]))->assertUnprocessable()->assertJsonValidationErrors('store_category_ids');
        $this->get('http://localhost:8000/shop/'.$otherShop->slug.'/categories/'.$category->id)->assertNotFound();
        $this->assertSame('New store label', $category->fresh()->name);
    }

    public function test_stale_delete_preview_and_restricted_seller_cannot_change_categories(): void
    {
        $category = $this->category('Everyday');
        $preview = $this->getJson('/seller/store-categories/'.$category->id.'/preview')->json();
        $this->product([$category->id]);
        $this->deleteJson('/seller/store-categories/'.$category->id, ['version' => $preview['version']])->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->shop->user->update(['status' => 'suspended']);
        $this->post('/seller/store-categories', ['name' => 'Unavailable', 'is_visible' => true])->assertRedirect();
        $this->assertDatabaseCount('store_categories', 1);
    }
}

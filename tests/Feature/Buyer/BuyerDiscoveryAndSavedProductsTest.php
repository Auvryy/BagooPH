<?php

namespace Tests\Feature\Buyer;

use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Models\SavedProduct;
use App\Models\Shop;
use App\Models\User;
use App\Services\Commerce\SavedProductService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BuyerDiscoveryAndSavedProductsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public static function invalidFilters(): array
    {
        return [
            ['search[]=bag', 'search'], ['search='.str_repeat('a', 101), 'search'],
            ['category[]=all', 'category'], ['category=missing', 'category'],
            ['sort[]=relevance', 'sort'], ['sort=unknown', 'sort'],
            ['min_price[]=1', 'min_price'], ['min_price=-1', 'min_price'],
            ['min_price=1e2', 'min_price'], ['max_price=1.001', 'max_price'],
            ['min_price=200&max_price=100', 'max_price'], ['max_price=999999999', 'max_price'],
            ['rating[]=4', 'rating'], ['rating=6', 'rating'], ['rating=4.5', 'rating'],
            ['in_stock=maybe', 'in_stock'], ['page=0', 'page'], ['page=1e1', 'page'],
            ['page[]=1', 'page'], ['page=1000001', 'page'],
        ];
    }

    #[DataProvider('invalidFilters')]
    public function test_public_search_and_home_reject_malformed_filters(string $query, string $field): void
    {
        foreach (['/', '/buyer/search', '/products', '/catalog'] as $path) {
            $this->get($path.'?'.$query)->assertSessionHasErrors($field);
        }
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('saved_products', 0);
    }

    public function test_wildcards_and_escape_character_are_literal_search_values(): void
    {
        $shop = Shop::factory()->approved()->create();
        $literal = Product::factory()->create(['shop_id' => $shop->id, 'name' => 'Bag 50%_! edition', 'description' => '', 'sku' => 'BAG-LITERAL']);
        Product::factory()->create(['shop_id' => $shop->id, 'name' => 'Bag 500 other edition', 'description' => '', 'sku' => 'BAG-OTHER']);
        foreach (['/', '/buyer/search', '/catalog'] as $path) {
            $key = $path === '/' ? 'feedProducts' : 'products';
            $this->get($path.'?'.http_build_query(['search' => '  50%_!  ']))->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('filters.search', '50%_!')->has($key.'.data', 1)->where($key.'.data.0.id', $literal->id));
        }
    }

    public function test_filters_survive_stable_tied_price_pagination_and_out_of_range_recovery(): void
    {
        $shop = Shop::factory()->approved()->create();
        $products = Product::factory()->count(25)->create(['shop_id' => $shop->id, 'price' => '100.00', 'stock' => 2, 'sales_count' => 0]);
        Product::factory()->create(['shop_id' => $shop->id, 'stock' => 0]);
        Product::factory()->create(['shop_id' => $shop->id, 'status' => 'draft']);
        $query = http_build_query(['category' => $shop->rootCategory->slug, 'sort' => 'price_asc', 'min_price' => 0, 'max_price' => 100, 'in_stock' => 1]);
        $this->get('/buyer/search?'.$query)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('products.total', 25)->has('products.data', 24)->where('products.data.0.id', $products->last()->id)
            ->where('products.next_page_url', fn ($url) => str_contains($url, 'min_price=0') && str_contains($url, 'in_stock=1') && str_contains($url, 'category=')));
        foreach ([2, 99] as $pageNumber) {
            $this->get('/buyer/search?'.$query.'&page='.$pageNumber)->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('products.current_page', 2)->has('products.data', 1)->where('products.data.0.id', $products->first()->id));
        }
    }

    public function test_home_discovery_tabs_preserve_the_existing_new_arrivals_alias(): void
    {
        foreach (['top_sales', 'top_rated', 'new_arrivals'] as $tab) {
            $this->get('/?tab='.$tab.'&sort='.$tab)->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('filters.tab', $tab)->where('filters.sort', $tab === 'new_arrivals' ? 'newest' : $tab));
        }
    }

    public function test_saves_and_removals_are_owned_retry_safe_and_persist_after_sign_out(): void
    {
        $buyer = User::factory()->create();
        $other = User::factory()->create();
        $product = Product::factory()->create(['stock' => 0]);
        $this->actingAs($buyer)->postJson(route('buyer.saved-products.store', $product), ['buyer_id' => $other->id])->assertOk()->assertJson(['saved' => true]);
        $this->postJson(route('buyer.saved-products.store', $product))->assertOk();
        $this->assertDatabaseCount('saved_products', 1);
        $this->assertDatabaseHas('saved_products', ['buyer_id' => $buyer->id, 'product_id' => $product->id]);
        $this->post('/logout')->assertRedirect();
        $this->get(route('buyer.saved-products.index'))->assertRedirect(route('login'));
        $this->actingAs($buyer)->get(route('buyer.saved-products.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('entries.total', 1)->where('entries.data.0.available', false)->where('savedProductIds', [$product->id]));
        $this->actingAs($other)->get(route('buyer.saved-products.index').'?buyer_id='.$buyer->id)->assertOk()->assertInertia(fn (Assert $page) => $page->where('entries.total', 0)->where('savedProductIds', []));
        $this->deleteJson(route('buyer.saved-products.destroy', $product), ['buyer_id' => $buyer->id])->assertOk();
        $this->assertDatabaseCount('saved_products', 1);
        $this->actingAs($buyer)->deleteJson(route('buyer.saved-products.destroy', $product))->assertOk()->assertJson(['saved' => false]);
        $this->deleteJson(route('buyer.saved-products.destroy', $product))->assertOk();
        $this->assertDatabaseCount('saved_products', 0);
        $this->assertSame(0, $product->fresh()->stock);
        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_rating_filter_and_sort_use_verified_purchases_and_put_unrated_last(): void
    {
        $buyer = User::factory()->create();
        $shop = Shop::factory()->approved()->create();
        $rated = Product::factory()->create(['shop_id' => $shop->id]);
        $unrated = Product::factory()->create(['shop_id' => $shop->id, 'rating' => 5]);
        $order = Order::factory()->create(['buyer_id' => $buyer->id, 'status' => 'completed']);
        $line = $order->items()->create(['product_id' => $rated->id, 'shop_id' => $shop->id, 'quantity' => 1, 'unit_price' => 100, 'subtotal' => 100]);
        Review::create(['buyer_id' => $buyer->id, 'product_id' => $rated->id, 'order_id' => $order->id, 'order_item_id' => $line->id, 'rating' => 4]);
        Review::create(['buyer_id' => $buyer->id, 'product_id' => $unrated->id, 'order_id' => $order->id, 'rating' => 5]);
        $this->get('/buyer/search?sort=top_rated')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('products.data.0.id', $rated->id)->where('products.data.1.id', $unrated->id));
        $this->get('/buyer/search?rating=4')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('products.total', 1)->where('products.data.0.id', $rated->id));
    }

    public function test_saved_pages_are_stable_owned_and_recover_when_the_current_page_is_removed(): void
    {
        $buyer = User::factory()->create();
        $shop = Shop::factory()->approved()->create();
        $products = Product::factory()->count(25)->create(['shop_id' => $shop->id]);
        foreach ($products as $product) {
            SavedProduct::create(['buyer_id' => $buyer->id, 'product_id' => $product->id]);
        }
        SavedProduct::create(['buyer_id' => User::factory()->create()->id, 'product_id' => $products->first()->id]);
        $this->actingAs($buyer)->get('/buyer/saved-products')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('entries.total', 25)->has('entries.data', 24)->where('entries.data.0.product_id', $products->last()->id));
        $this->get('/buyer/saved-products?page=99')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('entries.current_page', 2)->where('entries.data.0.product_id', $products->first()->id));
        $this->deleteJson('/buyer/saved-products/'.$products->first()->id)->assertOk();
        $this->get('/buyer/saved-products?page=2')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('entries.current_page', 1)->where('entries.total', 24));
        $this->get('/buyer/saved-products?page[]=1')->assertSessionHasErrors('page');
    }

    public static function unavailable(): array
    {
        return [['archived'], ['draft'], ['restricted'], ['shop_suspended'], ['seller_suspended'], ['category_inactive']];
    }

    #[DataProvider('unavailable')]
    public function test_unavailable_entries_remain_owned_without_current_listing_details(string $state): void
    {
        $buyer = User::factory()->create();
        $product = Product::factory()->create(['name' => 'Private current details']);
        app(SavedProductService::class)->save($buyer, $product);
        match ($state) {
            'restricted' => $product->forceFill(['compliance_restricted' => true])->save(),
            'shop_suspended' => $product->shop->update(['status' => 'suspended']),
            'seller_suspended' => $product->shop->user->update(['status' => 'suspended']),
            'category_inactive' => $product->category->update(['is_active' => false]),
            default => $product->update(['status' => $state]),
        };
        $this->actingAs($buyer)->get(route('buyer.saved-products.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('entries.total', 1)->where('entries.data.0.product', null)->where('entries.data.0.available', false));
        $this->postJson(route('buyer.saved-products.store', $product))->assertOk();
        $this->actingAs(User::factory()->create())->postJson(route('buyer.saved-products.store', $product))->assertUnprocessable()->assertJsonValidationErrors('product_id');
        $this->assertDatabaseCount('saved_products', 1);
    }

    public function test_saved_products_are_archived_by_seller_and_block_direct_deletion(): void
    {
        $buyer = User::factory()->create();
        $product = Product::factory()->create();
        app(SavedProductService::class)->save($buyer, $product);
        $this->actingAs($product->shop->user)->delete(route('seller.products.destroy', $product))->assertSessionHas('success');
        $this->assertSame('archived', $product->fresh()->status);
        $this->assertDatabaseCount('saved_products', 1);
        $this->expectException(LogicException::class);
        $product->fresh()->delete();
    }

    public function test_database_enforces_one_owned_saved_entry_per_product(): void
    {
        $buyer = User::factory()->create();
        $product = Product::factory()->create();
        SavedProduct::create(['buyer_id' => $buyer->id, 'product_id' => $product->id]);
        $this->expectException(QueryException::class);
        SavedProduct::create(['buyer_id' => $buyer->id, 'product_id' => $product->id]);
    }

    public static function blockedBuyers(): array
    {
        return [['active', 'none'], ['active', 'pending_approval'], ['active', 'rejected'], ['suspended', 'approved'], ['inactive', 'verified']];
    }

    #[DataProvider('blockedBuyers')]
    public function test_holding_or_restricted_accounts_cannot_use_saved_products(string $status, string $kyc): void
    {
        $buyer = User::factory()->create(['status' => $status, 'kyc_status' => $kyc]);
        $product = Product::factory()->create();
        foreach (['http://localhost', 'http://buyer.localhost'] as $host) {
            $this->actingAs($buyer)->get($host.'/buyer/saved-products')->assertRedirect(route('kyc.pending'));
            $this->post($host.'/buyer/saved-products/'.$product->id)->assertRedirect(route('kyc.pending'));
            $this->delete($host.'/buyer/saved-products/'.$product->id)->assertRedirect(route('kyc.pending'));
        }
        $this->assertDatabaseCount('saved_products', 0);
        $this->expectException(AuthorizationException::class);
        app(SavedProductService::class)->save($buyer, $product);
    }

    public function test_guest_nonbuyer_and_stale_account_have_no_saved_list_access(): void
    {
        $product = Product::factory()->create();
        $this->post(route('buyer.saved-products.store', $product))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->seller()->create())->get(route('buyer.saved-products.index'))->assertForbidden();
        $buyer = User::factory()->create();
        User::whereKey($buyer->id)->update(['status' => 'suspended']);
        $this->expectException(AuthorizationException::class);
        app(SavedProductService::class)->save($buyer, $product);
    }
}

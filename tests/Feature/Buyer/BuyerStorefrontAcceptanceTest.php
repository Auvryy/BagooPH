<?php

namespace Tests\Feature\Buyer;

use App\Models\Category;
use App\Models\Product;
use App\Models\Shop;
use App\Models\ShopFollow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BuyerStorefrontAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_follows_are_owned_persistent_idempotent_and_counted_from_records(): void
    {
        $buyer = User::factory()->create();
        $other = User::factory()->create();
        $shop = Shop::factory()->approved()->create();
        $this->actingAs($buyer)->postJson(route('buyer.followed-shops.store', $shop), ['buyer_id' => $other->id])->assertOk()->assertJson(['following' => true]);
        $this->postJson(route('buyer.followed-shops.store', $shop))->assertOk();
        $this->assertDatabaseCount('shop_follows', 1);
        $this->assertDatabaseHas('shop_follows', ['buyer_id' => $buyer->id, 'shop_id' => $shop->id]);
        $this->get(route('shop.show', $shop->slug))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('isFollowing', true)->where('followerCount', 1)->where('isOwner', false));
        $this->post('/logout')->assertRedirect();
        $this->actingAs($buyer)->get(route('buyer.followed-shops.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('entries.data', 1)->where('entries.data.0.shop.id', $shop->id));
        $this->actingAs($other)->get(route('buyer.followed-shops.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('entries.data', 0));
        $this->deleteJson(route('buyer.followed-shops.destroy', $shop))->assertOk();
        $this->assertDatabaseCount('shop_follows', 1);
        $this->actingAs($buyer)->deleteJson(route('buyer.followed-shops.destroy', $shop))->assertOk();
        $this->deleteJson(route('buyer.followed-shops.destroy', $shop))->assertOk();
        $this->assertDatabaseCount('shop_follows', 0);
    }

    public function test_unavailable_followed_shop_is_private_and_can_still_be_removed(): void
    {
        $buyer = User::factory()->create();
        $shop = Shop::factory()->approved()->create();
        ShopFollow::create(['buyer_id' => $buyer->id, 'shop_id' => $shop->id]);
        $shop->update(['status' => 'inactive']);
        $this->actingAs($buyer)->get(route('buyer.followed-shops.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('entries.data', 1)->where('entries.data.0.shop', null));
        $this->deleteJson(route('buyer.followed-shops.destroy', $shop))->assertOk();
        $this->postJson(route('buyer.followed-shops.store', $shop))->assertUnprocessable()->assertJsonValidationErrors('shop_id');
        $this->assertDatabaseCount('shop_follows', 0);
    }

    public function test_guests_other_roles_and_restricted_buyers_cannot_create_follows(): void
    {
        $shop = Shop::factory()->approved()->create();
        $this->postJson(route('buyer.followed-shops.store', $shop))->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'seller']))->postJson(route('buyer.followed-shops.store', $shop))->assertForbidden();
        $this->actingAs(User::factory()->create(['status' => 'suspended']))->postJson(route('buyer.followed-shops.store', $shop))->assertRedirect(route('kyc.pending'));
        $this->assertDatabaseCount('shop_follows', 0);
    }

    public function test_storefront_filters_all_pages_inside_its_approved_category_scope(): void
    {
        $shop = Shop::factory()->approved()->create();
        $category = Category::factory()->create(['parent_id' => $shop->root_category_id]);
        $products = Product::factory()->count(20)->create(['shop_id' => $shop->id, 'category_id' => $category->id, 'name' => 'Everyday Backpack', 'price' => 100]);
        Product::factory()->create(['shop_id' => $shop->id, 'name' => 'Other item']);
        Product::factory()->create(['name' => 'Everyday Backpack', 'price' => 100]);
        $query = ['search' => '  EVERYDAY  ', 'category' => $category->slug, 'sort' => 'price_asc'];
        $this->get(route('shop.show', $shop->slug).'?'.http_build_query($query))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('products.total', 20)->has('products.data', 18)->where('products.data.0.id', $products->last()->id)
            ->where('filters.search', 'EVERYDAY')->has('categories', 2)
            ->where('products.next_page_url', fn ($url) => str_contains($url, 'category='.$category->slug) && str_starts_with($url, '/shop/')));
        $this->get(route('shop.show', $shop->slug).'?'.http_build_query([...$query, 'page' => 99]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('products.current_page', 2)->has('products.data', 2)->where('products.data.1.id', $products->first()->id));
        $this->get(route('shop.show', $shop->slug).'?min_price=200&max_price=100')->assertSessionHasErrors('max_price');
        $this->get(route('shop.show', $shop->slug).'?preview=1')->assertOk()->assertInertia(fn (Assert $page) => $page->where('isPreview', false));
    }

    public function test_typo_suggestion_respects_filters_and_never_uses_restricted_listings(): void
    {
        Product::factory()->create(['name' => 'Everyday Backpack', 'price' => 100]);
        Product::factory()->create(['name' => 'Secret Neckband', 'status' => 'draft']);
        $this->get('/buyer/search?search=backpak')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('products.data', 0)->where('suggestions', ['backpack']));
        $this->get('/buyer/search?search=backpak&max_price=10')->assertOk()->assertInertia(fn (Assert $page) => $page->where('suggestions', []));
        $this->get('/buyer/search?search=neckban')->assertOk()->assertInertia(fn (Assert $page) => $page->where('suggestions', []));
        $this->get('/buyer/search?search=backpack')->assertOk()->assertInertia(fn (Assert $page) => $page->where('suggestions', []));
        $this->get('/buyer/search?search=backpak', ['X-Inertia' => 'true', 'X-Inertia-Version' => Inertia::getVersion() ?? '', 'X-Inertia-Partial-Component' => 'Buyer/Search', 'X-Inertia-Partial-Data' => 'products,filters,suggestions'])
            ->assertOk()->assertJsonPath('props.suggestions.0', 'backpack');
    }
}

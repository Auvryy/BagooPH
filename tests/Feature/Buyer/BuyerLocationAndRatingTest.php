<?php

namespace Tests\Feature\Buyer;

use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Models\Shop;
use App\Models\User;
use App\Services\Commerce\PhilippineAddressCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BuyerLocationAndRatingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function product(string $city, array $attributes = []): Product
    {
        return Product::factory()->create([...$attributes, 'shop_id' => Shop::factory()->approved()->create(['city' => $city])->id]);
    }

    private function rate(Product $product, array $ratings): void
    {
        foreach ($ratings as $rating) {
            $order = Order::factory()->create(['status' => 'completed']);
            $line = $order->items()->create(['product_id' => $product->id, 'shop_id' => $product->shop_id, 'quantity' => 1, 'unit_price' => 100, 'subtotal' => 100]);
            Review::create(['buyer_id' => $order->buyer_id, 'product_id' => $product->id, 'order_id' => $order->id, 'order_item_id' => $line->id, 'rating' => $rating]);
        }
    }

    public function test_area_uses_reviewed_pickup_city_and_never_an_ambiguous_name_or_account_residence(): void
    {
        $catalog = app(PhilippineAddressCatalog::class);
        $ncr = $catalog->provinceForUniqueCity('Makati City');
        $laguna = $catalog->provinceForUniqueCity('Los Banos');
        $this->assertNotNull($ncr);
        $this->assertNotSame($ncr, $laguna);
        $this->assertNull($catalog->provinceForUniqueCity('Santa Cruz'));
        $this->assertNull($catalog->provinceForUniqueCity('Unknown district'));
        $manila = $this->product('  MAKATI CITY  ');
        $outside = $this->product('Los Baños');
        $ambiguous = $this->product('Santa Cruz');
        $unknown = $this->product('Unknown district');
        $outside->shop->user->update(['province' => 'Metro Manila', 'city' => 'Makati']);
        $private = $this->product('Makati', ['status' => 'draft']);

        foreach (['/' => 'feedProducts', '/buyer/search' => 'products', '/catalog' => 'products'] as $path => $key) {
            $this->get($path.'?location='.$ncr)->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('filters.location', $ncr)->where($key.'.total', 1)->where($key.'.data.0.id', $manila->id));
            $this->get($path.'?location='.$laguna)->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where($key.'.total', 1)->where($key.'.data.0.id', $outside->id));
            $this->get($path)->assertOk()->assertInertia(fn (Assert $page) => $page->where($key.'.total', 4));
        }
        $this->get('/buyer/search?location='.$ncr)->assertInertia(fn (Assert $page) => $page
            ->has('locations', 85)->where('locations', fn ($areas) => collect($areas)->contains(fn ($area) => $area['value'] === $ncr && $area['label'] === 'Metro Manila')));
    }

    public function test_fractional_average_rating_combines_with_area_category_price_and_stock_without_rounding_up(): void
    {
        $area = app(PhilippineAddressCatalog::class)->provinceForUniqueCity('Makati');
        $match = $this->product('Makati', ['price' => 100, 'stock' => 2, 'name' => 'Everyday Bag']);
        $this->rate($match, [4, 5]);
        $below = $this->product('Makati', ['price' => 100, 'stock' => 2]);
        $this->rate($below, [4, 4, 5]);
        $otherArea = $this->product('Los Banos', ['price' => 100, 'stock' => 2]);
        $this->rate($otherArea, [5]);
        $this->product('Makati', ['rating' => 5, 'stock' => 2, 'price' => 100]);
        $out = $this->product('Makati', ['price' => 100, 'stock' => 0]);
        $this->rate($out, [5]);
        $expensive = $this->product('Makati', ['price' => 101, 'stock' => 2]);
        $this->rate($expensive, [5]);
        $filters = ['category' => $match->category->slug, 'location' => $area, 'rating' => '4.5', 'max_price' => 100, 'in_stock' => 1, 'sort' => 'top_rated'];
        $this->get('/buyer/search?'.http_build_query($filters))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('products.total', 1)->where('products.data.0.id', $match->id)->where('filters.rating', '4.5')->has('relatedProducts', 0));
        $this->get('/buyer/search?'.http_build_query([...$filters, 'search' => 'everydya']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('products.total', 0)->where('suggestions.0', 'everyday')->has('relatedProducts', 1)->where('relatedProducts.0.id', $match->id));
        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_location_and_rating_survive_pagination_and_empty_area_is_a_real_empty_result(): void
    {
        $area = app(PhilippineAddressCatalog::class)->provinceForUniqueCity('Makati');
        $shop = Shop::factory()->approved()->create(['city' => 'City of Makati']);
        foreach (Product::factory()->count(25)->create(['shop_id' => $shop->id]) as $product) {
            $this->rate($product, [5]);
        }
        $this->get('/buyer/search?location='.$area.'&rating=4.5&sort=newest')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('products.total', 25)->where('products.next_page_url', fn ($url) => str_contains($url, 'location='.$area) && str_contains($url, 'rating=4.5')));
        $this->get('/buyer/search?location='.$area.'&rating=4.5&page=2')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('products.data', 1)->where('filters.location', $area)->where('filters.rating', '4.5'));
        $empty = app(PhilippineAddressCatalog::class)->provinceForUniqueCity('City of Cebu');
        $this->get('/buyer/search?location='.$empty)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('products.total', 0)->has('relatedProducts', 0)->where('suggestions', []));
    }
}

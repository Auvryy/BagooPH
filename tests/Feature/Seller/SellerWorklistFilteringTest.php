<?php

namespace Tests\Feature\Seller;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Review;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SellerWorklistFilteringTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private Shop $shop;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seller = User::factory()->seller()->create();
        $this->shop = Shop::factory()->approved()->create(['user_id' => $this->seller->id]);
        $this->product = Product::factory()->create(['shop_id' => $this->shop->id]);
    }

    public static function portals(): array
    {
        return [['/seller'], ['http://seller.localhost']];
    }

    private function order(string $number, string $at, string $status = 'placed', ?Product $product = null): Order
    {
        $product ??= $this->product;
        $order = Order::factory()->create(['order_number' => $number, 'created_at' => $at, 'status' => $status]);
        OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $product->id, 'shop_id' => $product->shop_id]);

        return $order;
    }

    #[DataProvider('portals')]
    public function test_owned_order_filters_search_every_page_and_keep_filtered_counts_and_dates(string $portal): void
    {
        for ($n = 0; $n < 13; $n++) {
            $this->order('BGO-MATCH-'.$n, '2026-10-20 04:00:00');
        }
        $last = $this->order('BGO-MATCH-COMPLETE', '2026-10-20 05:00:00', 'completed');
        $this->order('BGO-MATCH-OTHER-DAY', '2026-10-19 15:59:59');
        $this->order('BGO-OTHER-NUMBER', '2026-10-20 04:00:00');
        $foreign = Product::factory()->create();
        $this->order('BGO-MATCH-FOREIGN', '2026-10-20 04:00:00', product: $foreign);
        $query = ['search' => ' match ', 'from' => '2026-10-20', 'to' => '2026-10-20'];
        $this->actingAs($this->seller)->get($portal.'/orders?'.http_build_query($query))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('orders.total', 14)->has('orders.data', 10)
                ->where('counts.all', 14)->where('counts.to_pack', 13)->where('counts.completed', 1)
                ->where('orders.data.0.id', $last->id)->where('filters.search', 'match')
                ->where('orders.next_page_url', fn ($url) => str_contains($url, 'page=2') && str_contains($url, 'from=2026-10-20') && str_contains($url, 'search=match')));
        $this->get($portal.'/orders?'.http_build_query([...$query, 'status' => 'to_pack', 'page' => 2]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('orders.total', 13)->has('orders.data', 3)->where('counts.completed', 1));
        $this->get($portal.'/orders?search=BGO-MATCH-0')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('orders.total', 1));
    }

    public function test_philippine_day_is_inclusive_and_each_date_can_be_used_alone(): void
    {
        foreach (['2026-10-19 15:59:59', '2026-10-19 16:00:00', '2026-10-20 15:59:59', '2026-10-20 16:00:00'] as $n => $at) {
            $this->order('BGO-BOUNDARY-'.$n, $at);
        }
        $this->actingAs($this->seller);
        foreach ([['from=2026-10-20&to=2026-10-20', 2], ['from=2026-10-20', 3], ['to=2026-10-20', 3]] as [$query, $count]) {
            $this->get('/seller/orders?'.$query)->assertOk()->assertInertia(fn (Assert $page) => $page->where('orders.total', $count));
        }
        $this->order('BGO-100%_MATCH', '2026-10-20 04:00:00');
        $this->get('/seller/orders?search='.urlencode('%_'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('orders.total', 1));
        foreach (['from=bad', 'from=2026-02-30', 'from=2026-10-21&to=2026-10-20', 'search[]=bad', 'page=0'] as $query) {
            $this->getJson('/seller/orders?'.$query)->assertUnprocessable();
        }
    }

    #[DataProvider('portals')]
    public function test_review_star_filter_is_owned_paginated_and_exposes_only_verified_variant_snapshots(string $portal): void
    {
        $buyer = User::factory()->buyer()->create();
        $order = Order::factory()->create(['buyer_id' => $buyer->id, 'status' => 'completed']);
        $item = OrderItem::factory()->create(['order_id' => $order->id, 'shop_id' => $this->shop->id, 'product_id' => $this->product->id,
            'color' => 'Clay', 'size' => 'Medium', 'sku_snapshot' => 'BGO-CLAY-M']);
        $review = Review::create(['product_id' => $this->product->id, 'buyer_id' => $buyer->id, 'order_id' => $order->id,
            'order_item_id' => $item->id, 'rating' => 1, 'comment' => 'Review with original purchased options.', 'images' => ['/storage/reviews/photo.jpg']]);
        for ($n = 0; $n < 15; $n++) {
            Review::create(['product_id' => $this->product->id, 'buyer_id' => $buyer->id, 'rating' => 1, 'comment' => 'Older review '.$n]);
        }
        Review::create(['product_id' => $this->product->id, 'buyer_id' => $buyer->id, 'rating' => 5, 'comment' => 'Five star review.']);
        Review::create(['product_id' => Product::factory()->create()->id, 'buyer_id' => $buyer->id, 'rating' => 1, 'comment' => 'Foreign review.']);
        $this->actingAs($this->seller)->get($portal.'/reviews?rating=1')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('reviews.total', 16)->has('reviews.data', 15)
                ->where('filters.rating', 1)->where('reviews.data.0.purchased_variant', null)
                ->where('reviews.next_page_url', fn ($url) => str_contains($url, 'rating=1') && str_contains($url, 'page=2')));
        $this->get($portal.'/reviews?rating=1&page=2')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('reviews.data.0.id', $review->id)->where('reviews.data.0.purchased_variant.color', 'Clay')
            ->where('reviews.data.0.purchased_variant.size', 'Medium')->where('reviews.data.0.purchased_variant.sku_snapshot', 'BGO-CLAY-M')
            ->has('reviews.data.0.images', 1)->missing('reviews.data.0.order_item'));
        $this->get($portal.'/reviews?rating=5&page=99')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('reviews.total', 1)->where('reviews.current_page', 1));
        foreach (['rating=0', 'rating=6', 'rating[]=1', 'rating=1.5'] as $query) {
            $this->getJson($portal.'/reviews?'.$query)->assertUnprocessable();
        }
    }
}

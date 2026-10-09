<?php

namespace Tests\Feature\Seller;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Review;
use App\Models\Shop;
use App\Models\User;
use App\Services\Commerce\ReviewService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SellerReviewReplyTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private Shop $shop;

    private Review $review;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seller = User::factory()->seller()->create();
        $this->shop = Shop::factory()->approved()->create(['user_id' => $this->seller->id]);
        $product = Product::factory()->create(['shop_id' => $this->shop->id, 'category_id' => $this->shop->root_category_id]);
        $buyer = User::factory()->buyer()->create();
        $order = Order::factory()->create(['buyer_id' => $buyer->id, 'status' => 'completed']);
        $item = OrderItem::factory()->create(['order_id' => $order->id, 'shop_id' => $this->shop->id, 'product_id' => $product->id]);
        $this->review = app(ReviewService::class)->create($buyer, ['order_item_id' => $item->id, 'rating' => 3, 'comment' => 'The item arrived safely.']);
    }

    public function test_reply_persists_and_edit_preserves_original_attribution_and_buyer_review(): void
    {
        $before = $this->review->toArray();
        $this->actingAs($this->seller)->post(route('seller.reviews.reply', $this->review->id), ['reply_text' => 'Thank you for sharing your review.'])->assertSessionHas('success');
        $first = $this->review->reply()->firstOrFail();
        $this->travel(5)->minutes();
        $this->post(route('seller.reviews.reply', $this->review->id), ['reply_text' => 'Thank you. We have noted your feedback.'])->assertSessionHas('success');
        $reply = $first->fresh();
        $this->assertSame($first->id, $reply->id);
        $this->assertSame($this->seller->id, $reply->seller_id);
        $this->assertSame($this->shop->id, $reply->shop_id);
        $this->assertEquals($first->created_at, $reply->created_at);
        $this->assertTrue($reply->updated_at->greaterThan($reply->created_at));
        $this->assertEquals($before, $this->review->fresh()->toArray());
        $this->assertDatabaseCount('review_replies', 1);
        $this->get(route('seller.reviews.index'))->assertInertia(fn (Assert $page) => $page
            ->where('reviews.data.0.reply.text', 'Thank you. We have noted your feedback.')
            ->where('reviews.data.0.verified_purchase', true)->where('stats.average_rating', 3)->where('stats.response_rate', '100%'));
    }

    public function test_foreign_seller_cannot_reply_to_another_shop_review(): void
    {
        $other = User::factory()->seller()->create();
        Shop::factory()->approved()->create(['user_id' => $other->id]);
        $this->actingAs($other)->postJson(route('seller.reviews.reply', $this->review->id), ['reply_text' => 'Foreign reply.'])->assertNotFound();
        $this->assertDatabaseCount('review_replies', 0);
    }

    public function test_reply_rejects_markup_empty_and_overlong_text(): void
    {
        $this->actingAs($this->seller);
        foreach (['', '<b>Thanks</b>', '!!!', str_repeat('a', 501)] as $text) {
            $this->postJson(route('seller.reviews.reply', $this->review->id), ['reply_text' => $text])
                ->assertUnprocessable()->assertJsonValidationErrors('reply_text');
        }
        $this->assertDatabaseCount('review_replies', 0);
    }

    public function test_restricted_shop_cannot_create_a_reply_through_the_service(): void
    {
        $this->shop->update(['status' => 'suspended']);
        try {
            app(ReviewService::class)->reply($this->seller, $this->shop, $this->review->id, ['reply_text' => 'Restricted reply.']);
            $this->fail('A restricted shop must not be able to reply.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertDatabaseCount('review_replies', 0);
    }

    public function test_stale_seller_approval_is_rechecked_before_saving(): void
    {
        User::whereKey($this->seller->id)->update(['status' => 'suspended']);
        $this->expectException(AuthorizationException::class);
        app(ReviewService::class)->reply($this->seller, $this->shop, $this->review->id, ['reply_text' => 'Stale actor reply.']);
    }

    public function test_reply_rate_counts_only_verified_reviews_and_is_separate_from_chat(): void
    {
        Review::create(['product_id' => $this->review->product_id, 'buyer_id' => $this->review->buyer_id, 'rating' => 5, 'comment' => 'Older unverified review.']);
        $before = app(ReviewService::class)->shopSummary($this->shop);
        $this->assertSame(1, $before['review_count']);
        $this->assertSame(3.0, $before['rating']);
        $this->assertSame('0%', $before['response_rate']);
        app(ReviewService::class)->reply($this->seller, $this->shop, $this->review->id, ['reply_text' => 'Thank you for your feedback.']);
        $this->assertSame('100%', app(ReviewService::class)->shopSummary($this->shop)['response_rate']);
    }

    public function test_original_reply_attribution_cannot_be_changed_through_the_model(): void
    {
        $reply = app(ReviewService::class)->reply($this->seller, $this->shop, $this->review->id, ['reply_text' => 'Thank you for your review.']);
        $this->expectException(\LogicException::class);
        $reply->update(['seller_id' => User::factory()->seller()->create()->id]);
    }
}

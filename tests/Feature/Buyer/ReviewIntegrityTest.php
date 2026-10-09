<?php

namespace Tests\Feature\Buyer;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Review;
use App\Models\Shop;
use App\Models\User;
use App\Services\Commerce\ReviewService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReviewIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $buyer;

    private User $seller;

    private Shop $shop;

    private Product $product;

    private Order $order;

    private OrderItem $item;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->buyer = User::factory()->buyer()->create();
        $this->seller = User::factory()->seller()->create();
        $this->shop = Shop::factory()->approved()->create(['user_id' => $this->seller->id]);
        $this->product = Product::factory()->create(['shop_id' => $this->shop->id, 'category_id' => $this->shop->root_category_id, 'status' => 'active', 'rating' => 0]);
        $this->order = Order::factory()->create(['buyer_id' => $this->buyer->id, 'status' => 'completed']);
        $this->item = OrderItem::factory()->create(['order_id' => $this->order->id, 'shop_id' => $this->shop->id, 'product_id' => $this->product->id]);
    }

    private function input(array $extra = []): array
    {
        return ['order_item_id' => $this->item->id, 'rating' => 4, 'comment' => 'The parcel arrived safely.', ...$extra];
    }

    private function savedReview(array $extra = []): Review
    {
        return app(ReviewService::class)->create($this->buyer, $this->input($extra));
    }

    public function test_purchase_identity_is_derived_and_the_order_detail_marks_the_item_reviewed(): void
    {
        $this->actingAs($this->buyer)->post(route('buyer.reviews.store'), $this->input())->assertSessionHas('success');
        $this->assertDatabaseHas('reviews', ['order_item_id' => $this->item->id, 'buyer_id' => $this->buyer->id,
            'order_id' => $this->order->id, 'product_id' => $this->product->id, 'rating' => 4]);
        $this->assertSame('4.00', (string) $this->product->fresh()->rating);
        $this->get(route('buyer.orders.show', $this->order))->assertInertia(fn (Assert $page) => $page
            ->where('order.items.0.review.order_item_id', $this->item->id));
    }

    public function test_unchanged_retry_returns_the_original_review_and_changed_retry_rejects(): void
    {
        $review = $this->savedReview();
        $this->assertSame($review->id, $this->savedReview()->id);
        $this->actingAs($this->buyer)->postJson(route('buyer.reviews.store'), $this->input(['rating' => 5]))
            ->assertUnprocessable()->assertJsonValidationErrors('order_item_id');
        $this->assertDatabaseCount('reviews', 1);
        $this->assertSame(4, $review->fresh()->rating);
    }

    public function test_image_retry_does_not_create_orphan_photos(): void
    {
        $image = UploadedFile::fake()->image('parcel.jpg');
        $review = $this->savedReview(['images' => [$image]]);
        $this->assertSame($review->id, $this->savedReview(['images' => [$image]])->id);
        $this->assertCount(1, Storage::disk('public')->allFiles('reviews'));
        $this->assertCount(1, $review->images);
        $this->actingAs($this->buyer)->postJson(route('buyer.reviews.store'), $this->input([
            'images' => [UploadedFile::fake()->image('different.png')],
        ]))->assertUnprocessable();
        $this->assertCount(1, Storage::disk('public')->allFiles('reviews'));
    }

    public function test_duplicate_product_variants_are_reviewed_by_item_and_legacy_ambiguity_rejects(): void
    {
        $second = OrderItem::factory()->create(['order_id' => $this->order->id, 'shop_id' => $this->shop->id,
            'product_id' => $this->product->id, 'color' => 'Blue', 'size' => 'Large']);
        $this->actingAs($this->buyer)->postJson(route('buyer.reviews.store'), [
            'order_id' => $this->order->id, 'product_id' => $this->product->id, 'rating' => 4,
        ])->assertUnprocessable()->assertJsonValidationErrors('order_item_id');
        $this->savedReview();
        $this->savedReview(['order_item_id' => $second->id, 'rating' => 2]);
        $this->assertDatabaseCount('reviews', 2);
        $this->assertSame('3.00', (string) $this->product->fresh()->rating);
    }

    public function test_legacy_order_product_request_remains_supported_for_one_item(): void
    {
        $this->actingAs($this->buyer)->post(route('buyer.reviews.store'), [
            'order_id' => $this->order->id, 'product_id' => $this->product->id, 'rating' => 3,
        ])->assertSessionHas('success');
        $this->assertDatabaseHas('reviews', ['order_item_id' => $this->item->id]);
    }

    public function test_missing_foreign_and_incomplete_purchases_cannot_be_reviewed(): void
    {
        $this->actingAs($this->buyer)->postJson(route('buyer.reviews.store'), $this->input(['order_item_id' => 999999]))->assertNotFound();
        $other = User::factory()->buyer()->create();
        $this->actingAs($other)->postJson(route('buyer.reviews.store'), $this->input())->assertForbidden();
        $this->order->update(['status' => 'delivered']);
        $this->actingAs($this->buyer)->postJson(route('buyer.reviews.store'), $this->input())->assertUnprocessable();
        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_identity_injection_and_mismatched_identifiers_reject(): void
    {
        $this->actingAs($this->buyer)->postJson(route('buyer.reviews.store'), $this->input(['buyer_id' => $this->seller->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('buyer_id');
        $this->postJson(route('buyer.reviews.store'), $this->input(['order_id' => 999999]))->assertUnprocessable();
        $this->postJson(route('buyer.reviews.store'), $this->input(['product_id' => 999999]))->assertUnprocessable();
        $this->assertDatabaseCount('reviews', 0);
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_ratings_text_and_item_ids_are_rejected(array $input, string $field): void
    {
        $this->actingAs($this->buyer)->postJson(route('buyer.reviews.store'), $this->input($input))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('reviews', 0);
    }

    public static function invalidInputs(): array
    {
        return [
            'zero rating' => [['rating' => 0], 'rating'],
            'six rating' => [['rating' => 6], 'rating'],
            'fraction rating' => [['rating' => 2.5], 'rating'],
            'rating array' => [['rating' => [5]], 'rating'],
            'markup' => [['comment' => '<script>bad</script>'], 'comment'],
            'meaningless' => [['comment' => '!!!'], 'comment'],
            'overlong' => [['comment' => str_repeat('a', 1001)], 'comment'],
            'hidden characters' => [['comment' => "Good\u{200B}parcel"], 'comment'],
            'item float' => [['order_item_id' => 1.5], 'order_item_id'],
            'item array' => [['order_item_id' => [1]], 'order_item_id'],
        ];
    }

    public function test_invalid_image_types_sizes_and_counts_reject_without_saving_files(): void
    {
        $this->actingAs($this->buyer);
        foreach ([[UploadedFile::fake()->create('fake.jpg', 1, 'text/plain')],
            [UploadedFile::fake()->image('parcel.gif')], [UploadedFile::fake()->image('large.png')->size(5121)],
            array_map(fn () => UploadedFile::fake()->image('parcel.jpg'), range(1, 6))] as $files) {
            $this->postJson(route('buyer.reviews.store'), $this->input(['images' => $files]))->assertUnprocessable();
        }
        $this->postJson(route('buyer.reviews.store'), $this->input(['images' => 'not-files']))->assertUnprocessable();
        $this->assertDatabaseCount('reviews', 0);
        $this->assertSame([], Storage::disk('public')->allFiles('reviews'));
    }

    public function test_failed_rating_update_rolls_back_the_review_and_removes_its_uploaded_photo(): void
    {
        DB::unprepared("CREATE TRIGGER reject_review_rating BEFORE UPDATE OF rating ON products BEGIN SELECT RAISE(ABORT, 'Injected rating failure'); END");
        try {
            $this->savedReview(['images' => [UploadedFile::fake()->image('parcel.jpg')]]);
            $this->fail('The rating failure should reject the whole review.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('Injected rating failure', $error->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER reject_review_rating');
        }
        $this->assertDatabaseCount('reviews', 0);
        $this->assertSame([], Storage::disk('public')->allFiles('reviews'));
        $this->assertSame('0.00', (string) $this->product->fresh()->rating);
    }

    public function test_stale_restricted_buyer_actor_is_reauthorized_by_the_service(): void
    {
        $this->buyer->newQuery()->whereKey($this->buyer->id)->update(['status' => 'suspended']);
        $this->expectException(AuthorizationException::class);
        $this->savedReview();
    }

    public function test_guest_and_non_buyer_requests_cannot_create_reviews(): void
    {
        $this->postJson(route('buyer.reviews.store'), $this->input())->assertUnauthorized();
        $this->actingAs($this->seller)->postJson(route('buyer.reviews.store'), $this->input())->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->postJson(route('buyer.reviews.store'), $this->input())->assertForbidden();
        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_legacy_backfill_links_only_unambiguous_completed_purchases_without_changing_content(): void
    {
        $legacy = Review::create(['buyer_id' => $this->buyer->id, 'order_id' => $this->order->id,
            'product_id' => $this->product->id, 'rating' => 4, 'comment' => 'Original legacy review.', 'images' => ['/storage/reviews/original.jpg']]);
        $before = $legacy->only(['comment', 'images', 'created_at', 'updated_at']);
        $unverified = [];
        foreach (['ambiguous item', 'duplicate review', 'foreign buyer', 'incomplete order', 'invalid rating', 'no purchase'] as $kind) {
            $order = Order::factory()->create(['buyer_id' => $this->buyer->id, 'status' => $kind === 'incomplete order' ? 'delivered' : 'completed']);
            OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $this->product->id, 'shop_id' => $this->shop->id]);
            if ($kind === 'ambiguous item') {
                OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $this->product->id, 'shop_id' => $this->shop->id]);
            }
            $values = ['order_id' => $kind === 'no purchase' ? null : $order->id,
                'buyer_id' => $kind === 'foreign buyer' ? $this->seller->id : $this->buyer->id,
                'product_id' => $this->product->id, 'rating' => $kind === 'invalid rating' ? 6 : 5,
                'comment' => 'Preserved older review.', 'images' => ['/storage/reviews/older.jpg']];
            $unverified[] = Review::create($values)->id;
            if ($kind === 'duplicate review') {
                $unverified[] = Review::create($values)->id;
            }
        }
        $migration = require database_path('migrations/2026_10_09_100000_link_purchase_reviews_and_create_seller_replies.php');
        $migration->backfillLegacyReviews();
        $this->assertSame($this->item->id, $legacy->fresh()->order_item_id);
        $this->assertEquals($before, $legacy->fresh()->only(array_keys($before)));
        $this->assertSame(7, Review::whereIn('id', $unverified)->whereNull('order_item_id')->count());
        $this->assertSame(1, Review::verifiedPurchase()->count());
        $this->assertSame('4.00', (string) $this->product->fresh()->rating);
        $this->assertSame(1, app(ReviewService::class)->shopSummary($this->shop)['review_count']);
    }

    public function test_public_reviews_hide_order_identity_private_buyer_fields_and_fingerprints(): void
    {
        $review = $this->savedReview();
        app(ReviewService::class)->reply($this->seller, $this->shop, $review->id, ['reply_text' => 'Thank you for your feedback.']);
        $this->get(route('buyer.products.show', $this->product->slug))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('product.rating', 4)->where('product.verified_review_count', 1)
            ->where('product.reviews.0.verified_purchase', true)
            ->where('product.reviews.0.reply.text', 'Thank you for your feedback.')
            ->missing('product.reviews.0.order_id')->missing('product.reviews.0.order_item_id')
            ->missing('product.reviews.0.buyer_id')->missing('product.reviews.0.submission_fingerprint')
            ->missing('product.reviews.0.buyer.email')->missing('product.reviews.0.buyer.birthday')
            ->missing('product.reviews.0.reply.seller_id')->where('shopStats.response_rate', '100%'));
    }

    public function test_public_rating_has_an_honest_empty_state_and_ignores_legacy_ratings(): void
    {
        $this->product->update(['rating' => 5]);
        Review::create(['buyer_id' => $this->buyer->id, 'product_id' => $this->product->id, 'rating' => 5, 'comment' => 'No linked purchase.']);
        $this->get(route('buyer.products.show', $this->product->slug))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('product.rating', null)->where('product.verified_review_count', 0)
            ->where('product.reviews.0.verified_purchase', false)->where('shopStats.rating', null)
            ->where('shopStats.response_rate', null)->where('shopStats.joined', $this->shop->created_at->toDateString()));
        $this->get(route('buyer.search', ['rating' => 4]))->assertInertia(fn (Assert $page) => $page->has('products.data', 0));
    }

    public function test_database_unique_purchase_constraint_prevents_direct_duplicate_insert(): void
    {
        $review = $this->savedReview();
        $this->expectException(QueryException::class);
        Review::create([...$review->only(['order_item_id', 'order_id', 'product_id', 'buyer_id', 'rating']), 'comment' => 'Another review.']);
    }

    public function test_review_retention_blocks_destructive_migration_rollback(): void
    {
        $this->savedReview();
        $migration = require database_path('migrations/2026_10_09_100000_link_purchase_reviews_and_create_seller_replies.php');
        $this->expectException(\LogicException::class);
        $migration->down();
    }
}

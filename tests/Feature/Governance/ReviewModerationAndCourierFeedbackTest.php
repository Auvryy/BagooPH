<?php

namespace Tests\Feature\Governance;

use App\Models\DeliveryFeedback;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Review;
use App\Models\ReviewDispute;
use App\Models\ShipmentFinancialPlan;
use App\Models\Shop;
use App\Models\User;
use App\Services\Commerce\ReviewService;
use App\Services\Governance\EnforcementService;
use App\Services\Returns\BuyerReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class ReviewModerationAndCourierFeedbackTest extends TestCase
{
    use AssertsDeliveryCheckpoints;
    use CreatesE2EOrders;
    use InteractsWithOrderActions;
    use InteractsWithRoles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_thirty_day_creation_window_and_review_restricted_sanction(): void
    {
        $buyer = User::factory()->buyer()->create();
        $seller = User::factory()->seller()->create();
        $shop = Shop::factory()->approved()->create(['user_id' => $seller->id]);
        $product = Product::factory()->create(['shop_id' => $shop->id, 'category_id' => $shop->root_category_id, 'status' => 'active']);
        $admin = $this->createApprovedUser('admin');

        $expiredOrder = Order::factory()->create([
            'buyer_id' => $buyer->id,
            'status' => 'completed',
            'completed_at' => now()->subDays(31),
        ]);
        $expiredItem = OrderItem::factory()->create([
            'order_id' => $expiredOrder->id,
            'shop_id' => $shop->id,
            'product_id' => $product->id,
        ]);

        $this->actingAs($buyer)->postJson(route('buyer.reviews.store'), [
            'order_item_id' => $expiredItem->id,
            'rating' => 5,
            'comment' => 'Great product but over 30 days later.',
        ])->assertUnprocessable();

        $freshOrder = Order::factory()->create([
            'buyer_id' => $buyer->id,
            'status' => 'completed',
            'completed_at' => now()->subDays(5),
        ]);
        $freshItem = OrderItem::factory()->create([
            'order_id' => $freshOrder->id,
            'shop_id' => $shop->id,
            'product_id' => $product->id,
        ]);

        app(EnforcementService::class)->issueSanction($admin, [
            'subject_type' => 'buyer',
            'subject_id' => $buyer->id,
            'scope' => 'review_restricted',
            'reason_code' => 'review_policy_abuse',
            'reason' => 'Verified coordinated fake review flood.',
            'evidence_summary' => 'Verified multiple URL-spam submissions.',
        ]);

        $this->actingAs($buyer)->postJson(route('buyer.reviews.store'), [
            'order_item_id' => $freshItem->id,
            'rating' => 1,
            'comment' => 'Should be blocked while review_restricted.',
        ])->assertForbidden();
    }

    public function test_return_fault_and_refusal_gating_for_product_reviews(): void
    {
        $sellerOrder = $this->flowDelivery($this->newFlowOrder(), 'delivered')->order->fresh(['items', 'buyer']);
        $buyerService = app(BuyerReturnService::class);
        $sellerCase = $buyerService->request($sellerOrder->buyer, $sellerOrder, [
            'request_token' => $buyerService->form($sellerOrder->buyer, $sellerOrder)['requestToken'],
            'reason' => 'damaged',
            'description' => 'Zipper broke before delivery.',
            'items' => [['order_item_id' => $sellerOrder->items->first()->id, 'quantity' => 1]],
        ], [UploadedFile::fake()->image('evidence1.png')]);

        $this->actingAs(User::findOrFail($sellerCase->seller_id))
            ->post('/buyer-returns/'.$sellerCase->id.'/actions', [
                'action' => 'approve',
                'expected_version' => $sellerCase->fresh()->version,
                'request_token' => (string) Str::uuid(),
                'reason' => 'Confirmed defective item on Seller packaging.',
                'fault' => 'seller',
            ])->assertSessionHasNoErrors();

        $sellerOrder->update(['status' => 'completed', 'completed_at' => now()]);
        $allowedReview = app(ReviewService::class)->create($sellerOrder->buyer, [
            'order_item_id' => $sellerOrder->items->first()->id,
            'rating' => 2,
            'comment' => 'Seller item stitching came loose cleanly verified.',
        ]);
        $this->assertSame('published', $allowedReview->moderation_status);

        // Courier-fault return blocks product review while allowing courier feedback.
        $courierOrder = $this->flowDelivery($this->newFlowOrder(), 'delivered')->order->fresh(['items', 'buyer', 'delivery']);
        $courierCase = $buyerService->request($courierOrder->buyer, $courierOrder, [
            'request_token' => $buyerService->form($courierOrder->buyer, $courierOrder)['requestToken'],
            'reason' => 'damaged',
            'description' => 'Outer parcel crushed by courier.',
            'items' => [['order_item_id' => $courierOrder->items->first()->id, 'quantity' => 1]],
        ], [UploadedFile::fake()->image('evidence2.png')]);

        $admin = $this->createApprovedUser('admin');
        $this->actingAs($admin)->post('/buyer-returns/'.$courierCase->id.'/actions', [
            'action' => 'review-fault',
            'expected_version' => $courierCase->fresh()->version,
            'request_token' => (string) Str::uuid(),
            'reason' => 'Courier transit damage verified from parcel box inspection.',
            'fault' => 'courier',
            'fault_evidence' => 'The original transfer receipt records the observed transit damage.',
        ])->assertSessionHasNoErrors();

        $courierOrder->update(['status' => 'completed', 'completed_at' => now()]);
        $this->actingAs($courierOrder->buyer)->postJson(route('buyer.reviews.store'), [
            'order_item_id' => $courierOrder->items->first()->id,
            'rating' => 1,
            'comment' => 'Courier crushed the parcel box.',
        ])->assertUnprocessable();

        $this->actingAs($courierOrder->buyer)->postJson(route('buyer.deliveries.feedback', $courierOrder->delivery), [
            'rating' => 1,
            'timeliness_rating' => 2,
            'handling_rating' => 1,
            'comment' => 'Outer box was wet and dented on handover.',
            'tags' => ['damaged_outer_box'],
        ])->assertCreated();

        // Refused COD order blocks product review via ShipmentFinancialPlan.
        $refusalDelivery = $this->flowDelivery($this->newFlowOrder(), 'out_for_delivery');
        $this->reportFlowFailure($refusalDelivery, 'Customer refused');
        $refusalOrder = $refusalDelivery->order->fresh(['items', 'buyer']);
        $refusalOrder->update(['status' => 'completed', 'completed_at' => now()]);
        $refusalItem = $refusalOrder->items->first();
        DB::table('shipment_financial_plans')->insert([
            'reference' => (string) Str::uuid(),
            'order_id' => $refusalOrder->id,
            'delivery_id' => $refusalDelivery->id,
            'buyer_id' => $refusalOrder->buyer_id,
            'seller_id' => $refusalOrder->items->first()->shop->user_id,
            'logistics_company_id' => $refusalDelivery->logistics_company_id,
            'actor_id' => $admin->id,
            'kind' => 'buyer_refusal',
            'snapshot' => json_encode(['note' => 'refusal']),
            'reason' => 'Buyer refused COD package at doorstep.',
            'proof_path' => 'proofs/refusal.png',
            'proof_mime' => 'image/png',
            'proof_bytes' => 128,
            'proof_hash' => hash('sha256', 'refusal-proof'),
            'request_token_hash' => hash('sha256', (string) Str::uuid()),
            'request_fingerprint' => hash('sha256', 'refusal-fingerprint'),
            'created_at' => now(),
        ]);

        try {
            app(ReviewService::class)->create($refusalOrder->buyer, [
                'order_item_id' => $refusalItem->id,
                'rating' => 1,
                'comment' => 'Refused delivery review attempt.',
            ]);
            $this->fail('Refused COD orders should not submit product ratings.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('order_item_id', $e->errors());
        }
    }

    public function test_velocity_hold_courier_exclusion_visibility_and_independent_buyer_appeal_restore(): void
    {
        $orderA = $this->flowDelivery($this->newFlowOrder(), 'delivered')->order->fresh(['items', 'buyer', 'delivery']);
        $orderA->update(['status' => 'completed', 'completed_at' => now()]);
        $shop = $orderA->shop;
        $seller = $shop->user;
        $product = $orderA->items->first()->product;
        $buyer = $orderA->buyer;

        $adjudicatorAdmin = $this->createApprovedUser('admin');
        $appealAdmin = $this->createApprovedUser('admin');

        // Separate 5-star review establishes genuine baseline rating.
        $otherBuyer = $this->createApprovedUser('buyer');
        $goodOrder = Order::factory()->create(['buyer_id' => $otherBuyer->id, 'status' => 'completed', 'completed_at' => now()]);
        $goodItem = OrderItem::factory()->create(['order_id' => $goodOrder->id, 'shop_id' => $shop->id, 'product_id' => $product->id]);
        $goodReview = app(ReviewService::class)->create($otherBuyer, [
            'order_item_id' => $goodItem->id,
            'rating' => 5,
            'comment' => 'Sturdy stitching and true sizing.',
        ]);
        $this->assertSame('5.00', (string) $product->fresh()->rating);

        // Buyer submits a 1-star review mentioning late courier delivery.
        $courierCommentReview = app(ReviewService::class)->create($buyer, [
            'order_item_id' => $orderA->items->first()->id,
            'rating' => 1,
            'comment' => 'Late delivery and rude courier rider ruined the weekend.',
        ]);

        // Non-punitive courier keyword signal recorded; average currently 3.00.
        $this->assertDatabaseHas('moderation_signals', [
            'signal_type' => 'review_courier_keyword',
            'review_id' => $courierCommentReview->id,
            'is_punitive' => false,
        ]);
        $this->assertSame('3.00', (string) $product->fresh()->rating);

        // Anti-spam rejects external URLs in product reviews.
        try {
            $spamOrder = Order::factory()->create(['buyer_id' => $otherBuyer->id, 'status' => 'completed', 'completed_at' => now()]);
            $spamItem = OrderItem::factory()->create(['order_id' => $spamOrder->id, 'shop_id' => $shop->id, 'product_id' => $product->id]);
            app(ReviewService::class)->create($otherBuyer, [
                'order_item_id' => $spamItem->id,
                'rating' => 5,
                'comment' => 'Visit https://spam-links.example now',
            ]);
            $this->fail('External links in product reviews must be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('comment', $e->errors());
        }

        // Separate velocity-flood buyer & product triggers velocity hold without touching product baseline score or auto-banning buyer.
        $floodBuyer = $this->createApprovedUser('buyer');
        $floodProduct = Product::factory()->create(['shop_id' => $shop->id, 'category_id' => $shop->root_category_id, 'status' => 'active']);
        $held = null;
        for ($i = 0; $i < 6; $i++) {
            $extraOrder = Order::factory()->create(['buyer_id' => $floodBuyer->id, 'status' => 'completed', 'completed_at' => now()]);
            $extraItem = OrderItem::factory()->create(['order_id' => $extraOrder->id, 'shop_id' => $shop->id, 'product_id' => $floodProduct->id]);
            $created = app(ReviewService::class)->create($floodBuyer, [
                'order_item_id' => $extraItem->id,
                'rating' => 5,
                'comment' => "Followup order review item #{$i}.",
            ]);
            if ($created->moderation_status === 'held_velocity_review') {
                $held = $created;
            }
        }
        $this->assertNotNull($held);
        $this->assertDatabaseHas('moderation_signals', [
            'signal_type' => 'review_velocity_burst',
            'review_id' => $held->id,
            'is_punitive' => false,
        ]);
        // Velocity hold can be removed cleanly via admin governance endpoint.
        $this->actingAs($adjudicatorAdmin)->postJson(route('governance.moderation.reviews.velocity-decision', $held), [
            'decision' => 'remove',
            'reason' => 'Test cleanup removing velocity-held sample review.',
        ])->assertOk();
        $this->assertSame('3.00', (string) $product->fresh()->rating);

        // Seller disputes the 1-star courier complaint review.
        $this->actingAs($seller)->post(route('seller.reviews.dispute', $courierCommentReview), [
            'reason_code' => 'courier_delivery_complaint',
            'seller_evidence' => 'Order was handed over inside SLA; complaint is purely courier delay.',
        ])->assertSessionHas('success');

        $dispute = ReviewDispute::sole();
        $this->actingAs($adjudicatorAdmin)->postJson(route('governance.moderation.review-disputes.adjudicate', $dispute), [
            'outcome' => 'upheld_excluded_courier',
            'adjudication_notes' => 'Comment targets courier speed rather than product craftsmanship.',
        ])->assertOk();

        // Excluded review no longer lowers product/shop score (back to 5.00), YET remains publicly visible!
        $this->assertSame('excluded_courier_issue', $courierCommentReview->fresh()->moderation_status);
        $this->assertSame(5.0, round((float) $product->fresh()->rating, 2));
        $this->assertSame(5.0, round((float) $shop->fresh()->rating, 2));

        app(ReviewService::class)->publicReviews($product->fresh());
        $publicList = $product->reviews;
        app(ReviewService::class)->publicReviews($product);
        $excludedEntry = collect($product->reviews)->firstWhere('id', $courierCommentReview->id);
        $this->assertNotNull($excludedEntry);
        $this->assertTrue($excludedEntry['score_excluded']);

        // Buyer appeals on Order Detail and independent admin restores review score.
        $this->actingAs($buyer)->postJson(route('buyer.reviews.appeal', $courierCommentReview), [
            'appeal_reason' => 'Product finish actually peeled off in transit as well.',
        ])->assertCreated();

        $this->assertSame('buyer_appealed', $dispute->fresh()->status);

        // Same adjudicator admin cannot decide the buyer's appeal -> 403!
        $this->actingAs($adjudicatorAdmin)->postJson(route('governance.moderation.review-disputes.appeal-decision', $dispute), [
            'outcome' => 'appeal_overturned',
            'appeal_notes' => 'Conflict of interest check.',
        ])->assertForbidden();

        // Different independent admin overturns exclusion -> restored & rating recalculates to 3.00!
        $this->actingAs($appealAdmin)->postJson(route('governance.moderation.review-disputes.appeal-decision', $dispute), [
            'outcome' => 'appeal_overturned',
            'appeal_notes' => 'Buyer appeal corroborated product issue; restored into rating.',
        ])->assertOk();

        $this->assertSame('restored', $courierCommentReview->fresh()->moderation_status);
        $this->assertSame('3.00', (string) $product->fresh()->rating);
    }

    public function test_courier_delivery_feedback_is_decoupled_from_product_rating_and_editable_once(): void
    {
        $order = $this->flowDelivery($this->newFlowOrder(), 'delivered')->order->fresh(['items', 'buyer', 'delivery']);
        $order->update(['status' => 'completed', 'completed_at' => now()]);
        $product = $order->items->first()->product;
        $product->update(['rating' => 5.00]);

        $this->actingAs($order->buyer)->get(route('buyer.orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('deliveryFeedbackUrl', route('buyer.deliveries.feedback', $order->delivery, absolute: false)));

        $this->actingAs($order->buyer)->postJson(route('buyer.deliveries.feedback', $order->delivery), [
            'rating' => 2,
            'timeliness_rating' => 1,
            'handling_rating' => 4,
            'communication_rating' => 3,
            'comment' => 'Rider arrived two hours late.',
            'tags' => ['late_delivery'],
        ])->assertCreated();

        $feedback = DeliveryFeedback::sole();
        $this->assertSame(2, $feedback->rating);
        // Product rating remains completely untouched by courier rating.
        $this->assertSame('5.00', (string) $product->fresh()->rating);

        // Can edit once; second edit blocked cleanly.
        $this->actingAs($order->buyer)->postJson(route('buyer.deliveries.feedback', $order->delivery), [
            'rating' => 3,
            'comment' => 'Updated after rider apologized.',
            'tags' => ['late_delivery', 'polite_rider'],
        ])->assertCreated();
        $this->assertNotNull($feedback->fresh()->edited_at);

        $this->actingAs($order->buyer)->postJson(route('buyer.deliveries.feedback', $order->delivery), [
            'rating' => 5,
        ])->assertUnprocessable();
    }
}

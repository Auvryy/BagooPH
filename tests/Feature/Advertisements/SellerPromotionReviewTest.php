<?php

namespace Tests\Feature\Advertisements;

use App\Models\Advertisement;
use App\Models\AdvertisementReview;
use App\Models\AdvertisementSubmission;
use App\Models\Category;
use App\Models\NotificationDelivery;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\Advertisements\AdvertisementManager;
use App\Services\Advertisements\AdvertisementReader;
use App\Services\Advertisements\SellerPromotionService;
use App\Services\Notifications\NotificationDeliveryService;
use Database\Factories\ShopFactory;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SellerPromotionReviewTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private User $seller;

    private User $admin;

    private Product $product;

    private SellerPromotionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        config(['advertisements.enabled' => true]);
        $this->travelTo(now()->setDate(2026, 10, 15)->setTime(12, 0));
        $this->shop = Shop::factory()->approved()->create();
        $this->seller = $this->shop->user;
        $this->admin = User::factory()->admin()->create();
        $this->product = Product::factory()->create(['shop_id' => $this->shop->id, 'category_id' => $this->shop->root_category_id, 'name' => 'Bagoo everyday essential']);
        $this->service = app(SellerPromotionService::class);
    }

    private function payload(array $changes = []): array
    {
        return array_replace(['title' => 'A feature from our shop', 'alt_text' => 'Everyday essentials from our Bagoo shop',
            'summary' => 'Explore our available products.', 'purpose' => 'feature', 'destination_type' => 'product',
            'destination_id' => $this->product->id, 'product_ids' => [], 'offer_revision_id' => null,
            'starts_at' => '2026-10-15T20:00', 'ends_at' => '2026-10-16T20:00',
            'source_version' => 0, 'request_key' => (string) Str::uuid(),
            'image' => new UploadedFile(public_path('images/buyer/advertisements/collection.webp'), 'collection.webp', 'image/webp', null, true)], $changes);
    }

    private function draft(array $changes = []): Advertisement
    {
        return $this->service->saveDraft($this->seller, $this->payload($changes));
    }

    private function command(Advertisement $ad, array $changes = []): array
    {
        return array_replace(['source_version' => $ad->fresh()->version, 'request_key' => (string) Str::uuid()], $changes);
    }

    private function submit(Advertisement $ad): AdvertisementSubmission
    {
        $this->service->act($this->seller, $ad, $this->command($ad, ['action' => 'submit', 'revision_id' => $ad->fresh()->latestRevision->id]));

        return $ad->submissions()->latest('id')->firstOrFail();
    }

    private function approve(Advertisement $ad, AdvertisementSubmission $submission): void
    {
        $this->service->decide($this->admin, $ad, $this->command($ad, ['decision' => 'approved', 'submission_id' => $submission->id,
            'revision_id' => $submission->revision_id, 'claims_reviewed' => true]));
    }

    private function place(Advertisement $ad, AdvertisementSubmission $submission, array $changes = []): void
    {
        app(AdvertisementManager::class)->act($this->admin, $ad, $this->command($ad, array_replace([
            'action' => 'publish', 'submission_id' => $submission->id, 'slot' => 'main_1', 'claims_reviewed' => true, 'publish_now' => true,
        ], $changes)));
    }

    private function denied(callable $work, int $status, ?string $field = null): void
    {
        try {
            $work();
            $this->fail('The unsafe promotion action was accepted.');
        } catch (ValidationException $error) {
            $this->assertSame(422, $status);
            if ($field) {
                $this->assertArrayHasKey($field, $error->errors());
            }
        } catch (HttpException $error) {
            $this->assertSame($status, $error->getStatusCode());
        } catch (ModelNotFoundException) {
            $this->assertSame(404, $status);
        }
    }

    public function test_seller_submission_review_placement_and_buyer_path_keep_separate_states(): void
    {
        $ad = $this->draft();
        $this->assertSame($this->shop->id, $ad->shop_id);
        $this->assertNull($ad->latestRevision->slot);
        $this->assertSame([], app(AdvertisementReader::class)->homepage());
        $submission = $this->submit($ad);
        $this->assertSame('pending', $submission->state());
        $this->denied(fn () => $this->place($ad, $submission), 422);
        $this->approve($ad, $submission);
        $this->assertSame('approved', $submission->fresh()->state());
        $this->assertSame([], app(AdvertisementReader::class)->homepage());
        $this->place($ad, $submission);
        $banners = app(AdvertisementReader::class)->homepage();
        $this->assertCount(1, $banners);
        $this->get($banners[0]['href'])->assertRedirect(route('products.show', $this->product->slug));
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_submit_review_and_withdraw_retries_preserve_one_original_result(): void
    {
        $ad = $this->draft();
        $submit = $this->command($ad, ['action' => 'submit', 'revision_id' => $ad->latestRevision->id]);
        $this->service->act($this->seller, $ad, $submit);
        $this->service->act($this->seller, $ad, $submit);
        $submission = $ad->submissions()->sole();
        $review = $this->command($ad, ['decision' => 'approved', 'submission_id' => $submission->id, 'revision_id' => $submission->revision_id, 'claims_reviewed' => true]);
        $this->service->decide($this->admin, $ad, $review);
        $this->service->decide($this->admin, $ad, $review);
        $withdraw = $this->command($ad, ['action' => 'withdraw', 'submission_id' => $submission->id]);
        $this->service->act($this->seller, $ad, $withdraw);
        $this->service->act($this->seller, $ad, $withdraw);
        $this->assertDatabaseCount('advertisement_submissions', 1);
        $this->assertDatabaseCount('advertisement_reviews', 2);
        $this->assertDatabaseCount('advertisement_actions', 4);
        $this->denied(fn () => $this->service->act($this->seller, $ad, [...$withdraw, 'action' => 'stop']), 409);
    }

    public function test_editing_a_pending_submission_prevents_an_old_admin_decision_and_retains_evidence(): void
    {
        $ad = $this->draft();
        $submission = $this->submit($ad);
        $review = $this->command($ad, ['decision' => 'approved', 'submission_id' => $submission->id, 'revision_id' => $submission->revision_id, 'claims_reviewed' => true]);
        $raw = $submission->revision->getRawOriginal();
        $this->service->saveDraft($this->seller, $this->payload(['title' => 'A corrected feature', 'source_version' => $ad->fresh()->version, 'image' => null]), $ad);
        $this->assertSame('superseded', $submission->fresh()->state());
        $this->denied(fn () => $this->service->decide($this->admin, $ad, $review), 409);
        $this->denied(fn () => $this->approve($ad, $submission), 409);
        $this->assertSame($raw, $submission->revision->fresh()->getRawOriginal());
        $next = $this->submit($ad);
        $this->approve($ad, $next);
        $this->assertSame('approved', $next->fresh()->state());
    }

    public function test_a_new_unreviewed_draft_does_not_replace_the_approved_live_revision(): void
    {
        $ad = $this->draft();
        $submission = $this->submit($ad);
        $this->approve($ad, $submission);
        $this->place($ad, $submission);
        $publication = $ad->publications()->sole();
        $this->service->saveDraft($this->seller, $this->payload(['title' => 'New artwork proposal', 'source_version' => $ad->fresh()->version, 'image' => null]), $ad);
        $next = $this->submit($ad);
        $this->assertSame('pending', $next->state());
        $this->assertSame($publication->id, app(AdvertisementReader::class)->homepage()[0]['id']);
        $this->denied(fn () => $this->place($ad, $next), 422);
        $this->service->act($this->seller, $ad, $this->command($ad, ['action' => 'stop']));
        $this->assertSame([], app(AdvertisementReader::class)->homepage());
        $this->assertSame('withdrawn', $submission->fresh()->state());
        $this->assertSame('withdrawn', $next->fresh()->state());
        $this->assertSame('approved', $submission->reviews()->oldest('id')->first()->decision);
    }

    public function test_foreign_shop_products_drafts_and_submissions_are_not_available_to_a_seller(): void
    {
        $other = Shop::factory()->approved()->create();
        $foreign = Product::factory()->create(['shop_id' => $other->id, 'category_id' => $other->root_category_id]);
        $this->denied(fn () => $this->draft(['destination_id' => $foreign->id]), 422, 'advertisement');
        $this->denied(fn () => $this->draft(['destination_type' => 'shop', 'destination_id' => $other->id]), 422, 'advertisement');
        $ad = $this->draft();
        $this->denied(fn () => $this->service->find($other->user, $ad->id), 404);
        $this->denied(fn () => $this->service->saveDraft($other->user, $this->payload(['source_version' => $ad->version]), $ad), 404);
        $submission = $this->submit($ad);
        $this->denied(fn () => $this->service->act($other->user, $ad, $this->command($ad, ['action' => 'withdraw', 'submission_id' => $submission->id])), 404);
    }

    #[DataProvider('forgedFields')]
    public function test_sellers_cannot_submit_admin_publication_fields(array $changes, string $field): void
    {
        $this->denied(fn () => $this->draft($changes), 422, $field);
        $this->assertDatabaseCount('advertisements', 0);
    }

    public static function forgedFields(): array
    {
        return [
            [['slot' => 'main_1'], 'slot'], [['status' => 'live'], 'status'], [['shop_id' => 1], 'shop_id'],
            [['claims_reviewed' => true], 'claims_reviewed'], [['published_by_id' => 1], 'published_by_id'],
            [['destination_type' => 'collection'], 'destination_type'], [['destination_type' => 'category'], 'destination_type'],
            [['purpose' => 'event'], 'purpose'], [['destination_url' => 'https://example.invalid'], 'destination_url'],
        ];
    }

    public function test_admin_cannot_edit_seller_content_or_review_another_submissions_revision(): void
    {
        $ad = $this->draft();
        $submission = $this->submit($ad);
        $this->denied(fn () => app(AdvertisementManager::class)->saveDraft($this->admin, [...$this->payload(['source_version' => $ad->fresh()->version]), 'slot' => 'main_1'], $ad), 422);
        $this->denied(fn () => $this->service->decide($this->admin, $ad, $this->command($ad, ['decision' => 'approved', 'claims_reviewed' => true,
            'submission_id' => $submission->id, 'revision_id' => $submission->revision_id + 1])), 409);
        $this->denied(fn () => $this->service->decide($this->seller, $ad, $this->command($ad, ['decision' => 'approved', 'claims_reviewed' => true,
            'submission_id' => $submission->id, 'revision_id' => $submission->revision_id])), 403);
        $this->assertSame('pending', $submission->fresh()->state());
    }

    public function test_admin_requires_useful_feedback_and_claim_confirmation(): void
    {
        $ad = $this->draft();
        $submission = $this->submit($ad);
        $base = ['submission_id' => $submission->id, 'revision_id' => $submission->revision_id];
        $this->denied(fn () => $this->service->decide($this->admin, $ad, $this->command($ad, [...$base, 'decision' => 'rejected'])), 422, 'reason');
        $this->denied(fn () => $this->service->decide($this->admin, $ad, $this->command($ad, [...$base, 'decision' => 'approved', 'claims_reviewed' => false])), 422, 'claims_reviewed');
        $this->service->decide($this->admin, $ad, $this->command($ad, [...$base, 'decision' => 'changes_requested', 'reason' => 'Please replace the outdated artwork.']));
        $this->assertSame('changes_requested', $submission->fresh()->state());
        $this->assertSame('Please replace the outdated artwork.', $submission->fresh()->latestReview->reason);
    }

    public function test_current_actor_and_target_restrictions_apply_before_submission_review_and_display(): void
    {
        $ad = $this->draft();
        $submission = $this->submit($ad);
        $this->shop->update(['status' => 'suspended']);
        $this->denied(fn () => $this->approve($ad, $submission), 422, 'advertisement');
        $this->shop->update(['status' => 'active']);
        $this->approve($ad, $submission);
        $this->place($ad, $submission);
        $this->product->forceFill(['compliance_restricted' => true])->save();
        $this->assertSame([], app(AdvertisementReader::class)->homepage());
        DB::table('users')->where('id', $this->seller->id)->update(['status' => 'suspended']);
        $this->denied(fn () => $this->service->saveDraft($this->seller, $this->payload()), 403);
        $this->assertSame([], app(AdvertisementReader::class)->homepage());
    }

    public function test_rescheduling_retains_the_exact_approval_and_original_publication_and_period_bounds(): void
    {
        $ad = $this->draft();
        $submission = $this->submit($ad);
        $this->approve($ad, $submission);
        $this->place($ad, $submission);
        $first = $ad->publications()->sole();
        $originalEnd = $first->ends_at->toIso8601String();
        $this->place($ad, $submission, ['action' => 'reschedule', 'publication_id' => $first->id,
            'slot' => 'side_1', 'starts_at' => '2026-10-15T21:00', 'publish_now' => false]);
        $second = $ad->publications()->latest('id')->first();
        $this->assertSame($first->revision_id, $second->revision_id);
        $this->assertSame($first->id, $second->replaces_publication_id);
        $this->assertSame($originalEnd, $first->fresh()->ends_at->toIso8601String());
        $this->assertCount(1, app(AdvertisementReader::class)->homepage());
        $this->travel(1)->hours();
        $this->assertSame($second->id, app(AdvertisementReader::class)->homepage()[0]['id']);
        $this->denied(fn () => $this->place($ad, $submission, ['action' => 'reschedule', 'publication_id' => $second->id,
            'ends_at' => '2026-10-17T20:00']), 422);
        $this->assertDatabaseCount('advertisement_reviews', 1);
        $this->assertDatabaseCount('advertisement_publications', 2);
    }

    public function test_review_failure_rolls_back_decision_and_notice_outage_does_not_undo_review(): void
    {
        $ad = $this->draft();
        $submission = $this->submit($ad);
        $version = $ad->fresh()->version;
        DB::unprepared("CREATE TRIGGER fail_promotion_review BEFORE INSERT ON advertisement_actions WHEN NEW.action = 'review_approved' BEGIN SELECT RAISE(ABORT, 'Simulated failure'); END");
        try {
            $this->approve($ad, $submission);
            $this->fail('The review should have failed.');
        } catch (QueryException) {
        }
        $this->assertSame('pending', $submission->fresh()->state());
        $this->assertSame($version, $ad->fresh()->version);
        DB::unprepared('DROP TRIGGER fail_promotion_review');
        DB::unprepared("CREATE TRIGGER fail_promotion_notice BEFORE INSERT ON notifications BEGIN SELECT RAISE(ABORT, 'Simulated delivery failure'); END");
        $this->approve($ad, $submission);
        $this->assertSame('approved', $submission->fresh()->state());
        $intent = NotificationDelivery::where('recipient_id', $this->seller->id)->where('source_key', 'governance:advertisement_review:'.AdvertisementReview::sole()->id)->sole();
        $this->assertNull($intent->delivered_at);
        DB::unprepared('DROP TRIGGER fail_promotion_notice');
        $this->travel(2)->minutes();
        app(NotificationDeliveryService::class)->deliverPending();
        app(NotificationDeliveryService::class)->deliverPending();
        $this->assertNotNull($intent->fresh()->delivered_at);
        $this->assertSame(1, $this->seller->notifications()->where('source_key', $intent->source_key)->count());
    }

    public function test_failed_future_replacement_restores_the_original_booking_and_keeps_its_image_private(): void
    {
        $ad = $this->draft(['starts_at' => '2026-10-15T21:00']);
        $submission = $this->submit($ad);
        $this->approve($ad, $submission);
        $this->place($ad, $submission, ['publish_now' => false]);
        $original = $ad->publications()->sole();
        $manager = app(AdvertisementManager::class);
        $other = $manager->saveDraft($this->admin, $this->payload(['slot' => 'side_1', 'starts_at' => '2026-10-15T21:00']));
        $manager->act($this->admin, $other, $this->command($other, ['action' => 'publish', 'claims_reviewed' => true, 'publish_now' => false]));
        $before = $ad->fresh()->version;
        $this->denied(fn () => $this->place($ad, $submission, ['action' => 'reschedule', 'publication_id' => $original->id,
            'slot' => 'side_1', 'starts_at' => '2026-10-15T22:00', 'publish_now' => false]), 422, 'advertisement');
        $this->assertNull($original->fresh()->ended_at);
        $this->assertNull($original->fresh()->superseded_at);
        $this->assertSame($before, $ad->fresh()->version);
        $this->assertDatabaseCount('advertisement_publications', 2);
        $this->get('/advertisement-artwork/'.$original->revision->asset->token)->assertNotFound();
        $this->travel(1)->hours();
        $this->assertCount(2, app(AdvertisementReader::class)->homepage());
    }

    public function test_retained_submissions_and_reviews_reject_raw_sql_updates_and_deletes(): void
    {
        $ad = $this->draft();
        $submission = $this->submit($ad);
        $this->approve($ad, $submission);
        foreach (['advertisement_submissions', 'advertisement_reviews'] as $table) {
            foreach (['update', 'delete'] as $operation) {
                try {
                    DB::transaction(fn () => $operation === 'update'
                        ? DB::table($table)->update(['updated_at' => now()->addSecond()]) : DB::table($table)->delete());
                    $this->fail('Retained review evidence was changed.');
                } catch (QueryException $error) {
                    $this->assertStringContainsString('immutable', $error->getMessage());
                }
            }
        }
        $this->assertSame('approved', $submission->fresh()->state());
    }

    public function test_admin_archive_resolves_pending_reviews_without_erasing_original_submissions(): void
    {
        $ad = $this->draft();
        $submission = $this->submit($ad);
        app(AdvertisementManager::class)->act($this->admin, $ad, $this->command($ad, ['action' => 'archive']));
        $this->assertSame('withdrawn', $submission->fresh()->state());
        $this->assertSame('admin', $submission->fresh()->latestReview->actor_role);
        $this->assertDatabaseCount('advertisement_submissions', 1);
        $this->denied(fn () => $this->approve($ad, $submission), 422);
        $this->denied(fn () => $this->service->saveDraft($this->seller, $this->payload(['source_version' => $ad->fresh()->version]), $ad), 422);
    }

    public function test_reviewed_category_changes_need_a_fresh_promotion_even_after_the_shop_is_approved_again(): void
    {
        $ad = $this->draft(['destination_type' => 'shop', 'destination_id' => $this->shop->id]);
        $submission = $this->submit($ad);
        $this->approve($ad, $submission);
        $this->place($ad, $submission);
        $scope = $submission->shop_scope;
        $category = Category::firstOrCreate(['name' => 'Books and Media', 'parent_id' => null], ['slug' => 'books-and-media', 'is_active' => true]);
        $this->shop->update(['root_category_id' => $category->id]);
        ShopFactory::recordApprovalFixture($this->shop->fresh());
        $newProduct = Product::factory()->create(['shop_id' => $this->shop->id, 'category_id' => $category->id]);
        $this->assertTrue(Shop::eligible()->whereKey($this->shop->id)->exists());
        $this->assertTrue(Product::availableForSale()->whereKey($newProduct->id)->exists());
        $this->assertSame($scope, $submission->fresh()->shop_scope);
        $this->assertFalse($this->service->contextCurrent($submission->fresh()));
        $this->assertSame([], app(AdvertisementReader::class)->homepage());
        $this->denied(fn () => $this->place($ad, $submission), 422, 'advertisement');
        $this->service->saveDraft($this->seller, $this->payload(['destination_type' => 'shop', 'destination_id' => $this->shop->id,
            'source_version' => $ad->fresh()->version, 'image' => null]), $ad);
        $next = $this->submit($ad);
        $this->assertSame($category->id, $next->shop_scope['root_category_id']);
        $this->approve($ad, $next);
        $this->place($ad, $next);
        $this->assertCount(1, app(AdvertisementReader::class)->homepage());
    }
}

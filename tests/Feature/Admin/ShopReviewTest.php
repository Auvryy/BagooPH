<?php

namespace Tests\Feature\Admin;

use App\Models\KycDecision;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shop;
use App\Models\ShopReviewDecision;
use App\Models\User;
use App\Services\ShopEligibilityService;
use App\Services\ShopReviewService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\InteractsWithKycReviews;
use Tests\TestCase;

class ShopReviewTest extends TestCase
{
    use InteractsWithKycReviews, RefreshDatabase;

    private function application(): array
    {
        $admin = User::factory()->admin()->create();
        $seller = User::factory()->seller()->create(['birthday' => '1995-05-10']);
        $this->addKycEvidence($seller);
        $shop = Shop::create(['user_id' => $seller->id, 'name' => 'Pet Corner', 'slug' => 'pet-corner',
            'root_category_id' => $this->validMasterCategory()->id, 'phone' => '+639171234567', 'address' => '123 Rizal Street', 'city' => 'Manila',
            'status' => 'pending', 'review_status' => 'pending_approval', 'review_submitted_at' => now(),
            'business_permit_path' => $seller->getRawOriginal('business_permit_path')]);

        return [$admin, $seller, $shop];
    }

    private function payload(Shop $shop): array
    {
        $service = app(ShopReviewService::class);

        return ['review_token' => $service->token($service->submission($shop->fresh())), 'evidence_confirmed' => true];
    }

    private function inspect(User $admin, Shop $shop): void
    {
        foreach (['id', 'permit'] as $document) {
            $this->actingAs($admin)->get('/shop-verification-documents/'.$shop->id.'/'.$document.'.pdf')->assertOk();
        }
    }

    private function approve(User $admin, Shop $shop, string $prefix = '/admin'): void
    {
        $this->inspect($admin, $shop);
        $this->actingAs($admin)->post($prefix.'/shops/'.$shop->id.'/approve', $this->payload($shop))->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_current_inspected_shop_gets_its_own_approval_without_changing_the_account_or_other_shops(): void
    {
        [$admin, $seller, $shop] = $this->application();
        $other = Shop::factory()->create(['status' => 'pending']);
        $before = $seller->fresh()->getAttributes();
        $this->approve($admin, $shop);

        $this->assertSame('active', $shop->fresh()->status);
        $this->assertSame('approved', $shop->fresh()->review_status);
        $this->assertSame('pending', $other->fresh()->status);
        $this->assertNull($other->fresh()->review_status);
        $this->assertSame($before, $seller->fresh()->getAttributes());
        $this->assertTrue(app(ShopEligibilityService::class)->isEligible($shop));
        $decision = ShopReviewDecision::sole();
        $this->assertSame('pending', $decision->before_state['status']);
        $this->assertSame('active', $decision->after_state['status']);
        $this->assertSame($admin->id, $decision->reviewer_id);
        $this->assertSame($shop->id, $decision->shop_id);
        $this->assertDatabaseCount('kyc_decisions', 0);
    }

    public function test_initial_account_review_records_only_its_original_shop(): void
    {
        [$admin, $seller, $shop] = $this->application();
        $seller->update(['status' => 'pending_approval', 'kyc_status' => 'pending_approval']);
        $extra = Shop::factory()->create(['status' => 'pending']);
        $this->actingAs($admin)->post('/admin/kyc/'.$seller->id.'/approve', $this->prepareKycReview($admin, $seller))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame($shop->id, ShopReviewDecision::sole()->shop_id);
        $this->assertSame(KycDecision::sole()->id, ShopReviewDecision::sole()->kyc_decision_id);
        $this->assertTrue(app(ShopEligibilityService::class)->isEligible($shop->fresh()));
        $this->assertNull($extra->fresh()->review_status);
        $this->assertSame('pending', $extra->fresh()->status);
    }

    public function test_approval_requires_both_current_documents_to_be_inspected_in_the_same_admin_session(): void
    {
        [$admin, , $shop] = $this->application();
        $this->actingAs($admin)->post('/admin/shops/'.$shop->id.'/approve', $this->payload($shop))->assertSessionHasErrors('review');
        $this->get('/shop-verification-documents/'.$shop->id.'/id.pdf')->assertOk();
        $this->post('/admin/shops/'.$shop->id.'/approve', $this->payload($shop))->assertSessionHasErrors('review');
        $this->get('/shop-verification-documents/'.$shop->id.'/permit.pdf')->assertOk();
        $this->post('/admin/shops/'.$shop->id.'/approve', $this->payload($shop))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('shop_review_decisions', 1);
    }

    public function test_inspection_by_another_admin_does_not_authorize_the_reviewer(): void
    {
        [$admin, , $shop] = $this->application();
        $this->inspect($admin, $shop);
        $other = User::factory()->admin()->create();
        $this->actingAs($other)->post('/admin/shops/'.$shop->id.'/approve', $this->payload($shop))->assertSessionHasErrors('review');
        $this->assertDatabaseCount('shop_review_decisions', 0);
    }

    public function test_identical_retry_preserves_the_first_decision_and_competing_review_conflicts(): void
    {
        [$admin, , $shop] = $this->application();
        $data = $this->payload($shop);
        $this->approve($admin, $shop);
        $before = ShopReviewDecision::sole()->getAttributes();
        $this->travel(20)->minutes();
        $this->actingAs($admin)->post('/admin/shops/'.$shop->id.'/approve', $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/admin/shops/'.$shop->id.'/reject', $data + ['reason' => 'The permit belongs to a different shop.'])->assertConflict();
        $this->assertSame($before, ShopReviewDecision::sole()->getAttributes());
        $this->assertDatabaseCount('shop_review_decisions', 1);
    }

    #[DataProvider('submissionChanges')]
    public function test_changed_current_submission_requires_a_new_version_and_inspection(string $change): void
    {
        [$admin, $seller, $shop] = $this->application();
        $data = $this->payload($shop);
        $this->inspect($admin, $shop);
        if ($change === 'category') {
            $shop->rootCategory->update(['is_active' => false]);
        } elseif ($change === 'evidence') {
            Storage::disk('local')->put($shop->business_permit_path, "%PDF-1.4\nReplacement shop permit\n%%EOF");
        } elseif ($change === 'identity') {
            $seller->update(['birthday' => '1996-05-10']);
        } elseif ($change === 'account_name') {
            $seller->update(['name' => 'Updated Seller Name']);
        } else {
            $shop->update(['address' => '456 Updated Rizal Street']);
        }
        $this->actingAs($admin)->post('/admin/shops/'.$shop->id.'/approve', $data)->assertConflict();
        $this->post('/admin/shops/'.$shop->id.'/approve', $this->payload($shop))->assertSessionHasErrors('review');
        $this->assertDatabaseCount('shop_review_decisions', 0);
    }

    public static function submissionChanges(): array
    {
        return [['category'], ['evidence'], ['identity'], ['account_name'], ['details']];
    }

    #[DataProvider('restrictions')]
    public function test_review_preserves_separate_account_and_shop_restrictions(string $subject, string $status): void
    {
        [$admin, $seller, $shop] = $this->application();
        ($subject === 'account' ? $seller : $shop)->update(['status' => $status]);
        $this->approve($admin, $shop);
        $this->assertSame($status, ($subject === 'account' ? $seller : $shop)->fresh()->status);
        $this->assertSame('approved', $shop->fresh()->review_status);
        $this->assertFalse(app(ShopEligibilityService::class)->isEligible($shop));
    }

    public static function restrictions(): array
    {
        return [['account', 'suspended'], ['account', 'inactive'], ['shop', 'suspended'], ['shop', 'inactive'], ['shop', 'unknown']];
    }

    public function test_review_write_failure_rolls_back_state_and_decision_together(): void
    {
        [$admin, , $shop] = $this->application();
        $this->inspect($admin, $shop);
        $before = $shop->fresh()->getAttributes();
        Event::listen('eloquent.created: '.ShopReviewDecision::class, fn () => throw new RuntimeException('Review write failed'));
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($admin)->post('/admin/shops/'.$shop->id.'/approve', $this->payload($shop));
            $this->fail('The injected failure must reach the caller.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Review write failed', $exception->getMessage());
        } finally {
            Event::forget('eloquent.created: '.ShopReviewDecision::class);
        }
        $this->assertSame($before, $shop->fresh()->getAttributes());
        $this->assertDatabaseCount('shop_review_decisions', 0);
    }

    public function test_rejection_has_feedback_and_a_versioned_retry_can_be_resubmitted(): void
    {
        [$admin, $seller, $shop] = $this->application();
        $data = $this->payload($shop) + ['reason' => 'Upload a readable permit for this shop.'];
        $this->actingAs($admin)->post('/admin/shops/'.$shop->id.'/reject', $data)->assertRedirect()->assertSessionHasNoErrors();
        $before = ShopReviewDecision::sole()->getAttributes();
        $this->post('/admin/shops/'.$shop->id.'/reject', $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/admin/shops/'.$shop->id.'/reject', array_replace($data, ['reason' => 'Changed feedback after the review.']))->assertConflict();
        $this->assertSame($before, ShopReviewDecision::sole()->getAttributes());
        $shop = $shop->fresh();
        $this->actingAs($seller)->post('/seller/shops/'.$shop->id.'/resubmit', $shop->only(ShopEligibilityService::DETAILS) + $this->payload($shop))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('pending_approval', $shop->fresh()->review_status);
        $this->approve($admin, $shop->fresh());
        $this->assertDatabaseCount('shop_review_decisions', 2);
        $this->assertSame($before, ShopReviewDecision::first()->getAttributes());
    }

    public function test_an_identical_approval_retry_never_clears_a_later_restriction_or_taxonomy_change(): void
    {
        [$admin, , $shop] = $this->application();
        $data = $this->payload($shop);
        $this->approve($admin, $shop);
        $before = ShopReviewDecision::sole()->getAttributes();
        $shop->update(['status' => 'suspended']);
        $shop->rootCategory->update(['is_active' => false]);
        $this->actingAs($admin)->post('/admin/shops/'.$shop->id.'/approve', $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('suspended', $shop->fresh()->status);
        $this->assertSame($before, ShopReviewDecision::sole()->getAttributes());
        $this->assertFalse(app(ShopEligibilityService::class)->isEligible($shop));
    }

    public function test_legacy_review_is_explicit_and_preserves_products_and_orders(): void
    {
        [$admin, $seller, $shop] = $this->application();
        $shop->update(['status' => 'active', 'review_status' => null, 'business_permit_path' => null, 'review_submitted_at' => null]);
        $product = Product::factory()->create(['shop_id' => $shop->id, 'category_id' => $shop->root_category_id]);
        $item = OrderItem::factory()->create(['product_id' => $product->id, 'shop_id' => $shop->id]);
        $this->assertFalse(app(ShopEligibilityService::class)->isEligible($shop));
        $this->actingAs($seller)->get('/seller/shops')->assertInertia(fn (Assert $page) => $page->component('Seller/Shops')->where('shop.review_status', null)->where('shop.can_submit', true));
        $data = $shop->only(ShopEligibilityService::DETAILS) + $this->payload($shop) + [
            'business_permit' => UploadedFile::fake()->createWithContent('permit.pdf', "%PDF-1.4\nCurrent legacy shop permit\n%%EOF"),
        ];
        $this->actingAs($seller)->post('/seller/shops/'.$shop->id.'/resubmit', $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->approve($admin, $shop->fresh());
        $this->assertTrue(app(ShopEligibilityService::class)->isEligible($shop));
        $this->assertDatabaseHas('products', ['id' => $product->id, 'shop_id' => $shop->id]);
        $this->assertDatabaseHas('order_items', ['id' => $item->id, 'shop_id' => $shop->id]);
        $this->assertDatabaseCount('shop_review_decisions', 1);
    }

    public function test_private_shop_evidence_and_history_recheck_ownership_and_privileged_access(): void
    {
        [$admin, $seller, $shop] = $this->application();
        $this->approve($admin, $shop);
        $decision = ShopReviewDecision::sole();
        $url = '/shop-verification-documents/'.$shop->id.'/permit.pdf?decision='.$decision->id;
        $this->actingAs($seller)->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAs(User::factory()->seller()->create())->get($url)->assertForbidden();
        $admin->update(['status' => 'inactive']);
        $this->actingAs($admin)->get($url)->assertForbidden();
        $this->actingAs($seller)->get('/storage/'.$shop->business_permit_path)->assertNotFound();
        Storage::disk('local')->put($shop->business_permit_path, "%PDF-1.4\nChanged historical evidence\n%%EOF");
        $this->get($url)->assertConflict();
    }

    public function test_owner_cannot_approve_and_an_inactive_admin_cannot_review(): void
    {
        [$admin, $seller, $shop] = $this->application();
        $this->actingAs($seller)->post('/admin/shops/'.$shop->id.'/approve', $this->payload($shop))->assertForbidden();
        $admin->update(['status' => 'inactive']);
        $this->actingAs($admin)->post('/admin/shops/'.$shop->id.'/approve', $this->payload($shop))->assertRedirect('/login');
        $this->assertDatabaseCount('shop_review_decisions', 0);
    }

    public function test_admin_queue_distinguishes_legacy_review_and_account_activity_without_exposing_paths(): void
    {
        [$admin, , $shop] = $this->application();
        $this->actingAs($admin)->get('/admin/shops')->assertInertia(fn (Assert $page) => $page->component('Admin/ShopReviews')
            ->where('shops.data.0.review_status', 'pending_approval')->where('shops.data.0.user.kyc_status', 'approved')
            ->missing('shops.data.0.business_permit_path')->missing('shops.data.0.review_decision_id'));
        $shop->update(['review_status' => null]);
        $this->get('/admin/shops?status=legacy')->assertInertia(fn (Assert $page) => $page->has('shops.data', 1));
    }

    public function test_database_history_cannot_be_rewritten_or_deleted(): void
    {
        [$admin, , $shop] = $this->application();
        $this->approve($admin, $shop);
        foreach (['update', 'delete'] as $operation) {
            try {
                $query = DB::table('shop_review_decisions');
                $operation === 'update' ? $query->update(['reason' => 'Replacement feedback']) : $query->delete();
                $this->fail('History must be immutable.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('immutable', $exception->getMessage());
            }
        }
        $this->assertDatabaseCount('shop_review_decisions', 1);
    }

    public function test_admin_subdomain_uses_the_same_review_and_inspection_contract(): void
    {
        [$admin, , $shop] = $this->application();
        $this->inspect($admin, $shop);
        $this->actingAs($admin)->post('http://admin.localhost/shops/'.$shop->id.'/approve', $this->payload($shop))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('approved', $shop->fresh()->review_status);
    }
}

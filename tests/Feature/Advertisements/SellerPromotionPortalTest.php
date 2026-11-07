<?php

namespace Tests\Feature\Advertisements;

use App\Models\Advertisement;
use App\Models\AdvertisementReview;
use App\Models\NotificationDelivery;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Models\Voucher;
use App\Services\Advertisements\AdvertisementManager;
use App\Services\Advertisements\AdvertisementReader;
use App\Services\Advertisements\SellerPromotionService;
use App\Services\Notifications\NotificationCenterService;
use App\Services\Notifications\NotificationDeliveryService;
use App\Services\Vouchers\VoucherCampaignService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SellerPromotionPortalTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private User $seller;

    private User $admin;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Storage::fake('public');
        config(['advertisements.enabled' => true]);
        $this->travelTo(now()->setDate(2026, 10, 15)->setTime(12, 0));
        $this->shop = Shop::factory()->approved()->create();
        $this->seller = $this->shop->user;
        $this->admin = User::factory()->admin()->create();
        $this->product = Product::factory()->create(['shop_id' => $this->shop->id, 'category_id' => $this->shop->root_category_id]);
    }

    private function data(array $changes = []): array
    {
        return array_replace(['title' => 'Our shop promotion', 'alt_text' => 'Selected products from our shop', 'summary' => '',
            'purpose' => 'feature', 'destination_type' => 'product', 'destination_id' => $this->product->id,
            'product_ids' => [], 'offer_revision_id' => null, 'starts_at' => '2026-10-15T20:00', 'ends_at' => '2026-10-16T20:00',
            'image' => new UploadedFile(public_path('images/buyer/advertisements/collection.webp'), 'collection.webp', 'image/webp', null, true),
            'request_key' => (string) Str::uuid(), 'source_version' => 0], $changes);
    }

    private function command(Advertisement $ad, array $data): array
    {
        return ['source_version' => $ad->fresh()->version, 'request_key' => (string) Str::uuid(), ...$data];
    }

    private function draft(): Advertisement
    {
        $data = $this->data();
        unset($data['product_ids']); // Empty lists are omitted by real multipart forms.
        $this->actingAs($this->seller)->post('/seller/promotions', $data)->assertSessionHasNoErrors()->assertSessionHas('success');

        return Advertisement::latest('id')->firstOrFail();
    }

    private function submitted(): Advertisement
    {
        $ad = $this->draft();
        $this->post('/seller/promotions/'.$ad->id.'/actions', $this->command($ad, ['action' => 'submit', 'revision_id' => $ad->latestRevision->id]))->assertSessionHas('success');

        return $ad->fresh();
    }

    private function approved(): Advertisement
    {
        $ad = $this->submitted();
        $submission = $ad->submissions()->sole();
        $this->actingAs($this->admin)->post('/admin/advertisements/'.$ad->id.'/review', $this->command($ad, [
            'decision' => 'approved', 'submission_id' => $submission->id, 'revision_id' => $submission->revision_id, 'claims_reviewed' => true,
        ]))->assertSessionHasNoErrors()->assertSessionHas('success');

        return $ad->fresh();
    }

    public function test_public_routes_follow_the_complete_seller_review_and_buyer_journey(): void
    {
        $ad = $this->submitted();
        $this->get('/seller/promotions/'.$ad->id)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertInertia(fn (Assert $page) => $page->component('Seller/Promotions/Edit')->where('promotion.review_state', 'pending')->where('promotion.state', 'draft')->has('promotion.submissions', 1)->missing('promotion.draft.source_path')->missing('promotion.draft.asset_id'));
        $this->actingAs($this->admin)->get('/admin/advertisements?scope=seller&review=pending')->assertInertia(fn (Assert $page) => $page->has('advertisements.data', 1)->where('advertisements.data.0.owner_type', 'seller'));
        $this->get('/admin/advertisements/'.$ad->id)->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Advertisements/Review')->where('advertisement.submissions.0.can_review', true));
        $submission = $ad->submissions()->sole();
        $this->post('/admin/advertisements/'.$ad->id.'/review', $this->command($ad, ['decision' => 'approved', 'submission_id' => $submission->id, 'revision_id' => $submission->revision_id, 'claims_reviewed' => true]))->assertSessionHas('success');
        $this->assertDatabaseCount('advertisement_publications', 0);
        $this->post('/admin/advertisements/'.$ad->id.'/actions', $this->command($ad, ['action' => 'publish', 'submission_id' => $submission->id, 'slot' => 'side_1', 'publish_now' => true, 'claims_reviewed' => true]))->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->app['auth']->forgetGuards();
        $this->get('/')->assertInertia(fn (Assert $page) => $page->has('banners', 1)->where('banners.0.placement', 'side'));
        $this->get('/advertisement/'.$ad->slug)->assertRedirect(route('products.show', $this->product->slug));
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('voucher_redemptions', 0);
    }

    public function test_owned_pages_assets_and_preview_revisions_do_not_leak_across_sellers_or_guests(): void
    {
        $ad = $this->draft();
        $other = Shop::factory()->approved()->create();
        $asset = $ad->latestRevision->asset;
        $this->get('/seller/promotions')->assertInertia(fn (Assert $page) => $page->component('Seller/Promotions/Index')->has('promotions.data', 1)->where('promotions.data.0.id', $ad->id));
        $this->get('/seller/promotions/'.$ad->id.'/preview')->assertInertia(fn (Assert $page) => $page->component('Seller/Promotions/Preview')->where('revision.id', $ad->latestRevision->id));
        $this->get('/seller/promotions/artwork/'.$asset->id)->assertOk()->assertHeader('Content-Type', 'image/webp')->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAs($other->user)->get('/seller/promotions/'.$ad->id)->assertNotFound();
        $this->get('/seller/promotions/'.$ad->id.'/preview')->assertNotFound();
        $this->get('/seller/promotions/artwork/'.$asset->id)->assertNotFound();
        $this->get('/seller/promotions')->assertInertia(fn (Assert $page) => $page->has('promotions.data', 0));
        $this->post('/logout')->assertRedirect();
        $this->app['auth']->forgetGuards();
        $this->getJson('/seller/promotions')->assertUnauthorized();
        $this->get('/advertisement-artwork/'.$asset->token)->assertNotFound();
        $this->get('/promotions/'.$ad->slug)->assertNotFound();
    }

    public function test_seller_subdomain_uses_the_same_authority_writer_and_private_previews(): void
    {
        $this->actingAs($this->seller)->post('http://seller.localhost/promotions', $this->data())->assertSessionHasNoErrors()->assertSessionHas('success');
        $ad = Advertisement::sole();
        $this->get('http://seller.localhost/promotions/'.$ad->id)->assertOk()->assertInertia(fn (Assert $page) => $page->where('baseUrl', '/promotions')->where('promotion.id', $ad->id));
        $this->get('http://seller.localhost/promotions/'.$ad->id.'/preview')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->post('http://seller.localhost/promotions/'.$ad->id.'/actions', $this->command($ad, ['action' => 'submit', 'revision_id' => $ad->latestRevision->id]))->assertRedirect('/promotions/'.$ad->id);
    }

    public function test_seller_promotion_writer_repairs_a_missing_calendar_mutex_row_without_a_503(): void
    {
        DB::table('advertisement_schedule_locks')->where('id', 1)->delete();

        $this->actingAs($this->seller)->post('/seller/promotions', $this->data())
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertDatabaseHas('advertisement_schedule_locks', ['id' => 1]);
        $this->assertDatabaseCount('advertisements', 1);
        $this->assertDatabaseCount('advertisement_revisions', 1);
    }

    public function test_gemini_can_generate_promotion_copy_and_unavailable_assistance_does_not_block_saving(): void
    {
        config(['ai.api_key' => 'test-assistant-key', 'ai.model' => 'gemini-test', 'ai.base_url' => 'https://assistant.invalid/v1beta']);
        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode([
            'title' => 'Everyday woven carryall', 'alt_text' => 'Natural woven carryall with a roomy shape',
            'summary' => 'A practical carryall for everyday errands.',
        ])]]]]]], 200)]);

        $this->actingAs($this->seller)->postJson('/seller/promotions/assist', ['action' => 'generate', 'title' => 'Woven carryall',
            'alt_text' => '', 'summary' => 'Roomy woven bag for everyday errands.', 'purpose' => 'feature'])
            ->assertOk()->assertJsonPath('content.title', 'Everyday woven carryall')
            ->assertJsonPath('content.alt_text', 'Natural woven carryall with a roomy shape');
        Http::assertSent(fn ($request) => str_contains($request->url(), 'gemini-test:generateContent')
            && $request['generationConfig']['responseMimeType'] === 'application/json');

        config(['ai.api_key' => null]);
        $this->postJson('/seller/promotions/assist', ['action' => 'improve', 'title' => 'Woven carryall', 'summary' => 'Roomy woven bag.'])
            ->assertStatus(503)->assertJsonPath('message', 'Gemini is unavailable right now. Your promotion draft is still available to save manually.');
        $this->post('/seller/promotions', $this->data())->assertSessionHasNoErrors()->assertSessionHas('success');
    }

    public function test_gemini_can_improve_seller_copy_and_flag_mismatched_promotion_text(): void
    {
        config(['ai.api_key' => 'test-assistant-key', 'ai.model' => 'gemini-test', 'ai.base_url' => 'https://assistant.invalid/v1beta']);
        Http::fake(['*' => Http::sequence()
            ->push(['candidates' => [['content' => ['parts' => [['text' => json_encode([
                'title' => 'Everyday woven carryall', 'alt_text' => 'Natural woven carryall for daily use',
                'summary' => 'A practical carryall made for everyday errands.',
            ])]]]]]], 200)
            ->push(['candidates' => [['content' => ['parts' => [['text' => json_encode([
                'valid' => false, 'findings' => ['The discount claim is not supported by the supplied offer.'],
                'advice' => 'Remove the discount claim or provide the matching offer terms.',
            ])]]]]]], 200)]);

        $this->actingAs($this->seller)->postJson('/seller/promotions/assist', [
            'action' => 'improve', 'title' => 'Woven carryall', 'alt_text' => 'A woven bag',
            'summary' => 'Roomy woven bag for everyday errands.', 'purpose' => 'feature',
        ])->assertOk()->assertJsonPath('content.title', 'Everyday woven carryall');

        $this->postJson('/seller/promotions/assist', [
            'action' => 'check', 'title' => '90% off every item', 'alt_text' => 'Woven carryall',
            'summary' => 'A woven bag for everyday errands.', 'purpose' => 'offer',
        ])->assertOk()->assertJsonPath('review.valid', false)
            ->assertJsonPath('review.findings.0', 'The discount claim is not supported by the supplied offer.')
            ->assertJsonPath('review.artwork_checked', false);
    }

    public function test_admin_can_generate_and_improve_platform_promotion_copy_without_changing_the_saved_revision(): void
    {
        config(['ai.api_key' => 'test-assistant-key', 'ai.model' => 'gemini-test', 'ai.base_url' => 'https://assistant.invalid/v1beta']);
        Http::fake(['*' => Http::sequence()
            ->push(['candidates' => [['content' => ['parts' => [['text' => json_encode([
                'title' => 'Bagoo seasonal collection', 'alt_text' => 'Seasonal products available on Bagoo',
                'summary' => 'Explore products selected for the season.',
            ])]]]]]], 200)
            ->push(['candidates' => [['content' => ['parts' => [['text' => json_encode([
                'title' => 'Thoughtful seasonal finds', 'alt_text' => 'A selection of seasonal Bagoo products',
                'summary' => 'Browse useful products chosen for the season.',
            ])]]]]]], 200)]);

        $this->actingAs($this->admin)->postJson('/admin/advertisements/assist', [
            'action' => 'generate', 'title' => 'Seasonal products', 'summary' => 'Explore our collection.',
        ])->assertOk()->assertJsonPath('content.title', 'Bagoo seasonal collection');

        $ad = app(AdvertisementManager::class)->saveDraft($this->admin, $this->data(['title' => 'Admin platform promotion', 'slot' => 'main_1']));
        $revisionBefore = $ad->latestRevision->getRawOriginal();
        $this->postJson('/admin/advertisements/'.$ad->id.'/assist', [
            'action' => 'improve', 'title' => 'Admin platform promotion',
            'alt_text' => 'Selected products from our shop', 'summary' => 'Explore selected products.',
        ])->assertOk()->assertJsonPath('content.title', 'Thoughtful seasonal finds');

        $this->assertSame($revisionBefore, $ad->fresh()->latestRevision->getRawOriginal());
    }

    public function test_gemini_connection_failure_returns_the_manual_save_503_feedback(): void
    {
        config(['ai.api_key' => 'test-assistant-key', 'ai.model' => 'gemini-test', 'ai.base_url' => 'https://assistant.invalid/v1beta']);
        Http::fake(['*' => Http::failedConnection('Simulated Gemini timeout')]);

        $this->actingAs($this->seller)->postJson('/seller/promotions/assist', [
            'action' => 'improve', 'title' => 'Woven carryall', 'alt_text' => 'Woven carryall for everyday errands',
            'summary' => 'Roomy bag for everyday errands.', 'purpose' => 'feature',
        ])->assertStatus(503)
            ->assertJsonPath('message', 'Gemini is unavailable right now. Your promotion draft is still available to save manually.');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'gemini-test:generateContent'));
    }

    public function test_gemini_promotion_check_validates_uploaded_artwork_and_admin_checks_the_exact_submission(): void
    {
        config(['ai.api_key' => 'test-assistant-key', 'ai.model' => 'gemini-test', 'ai.base_url' => 'https://assistant.invalid/v1beta']);
        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode([
            'valid' => true, 'findings' => [], 'advice' => 'The supplied text and artwork appear consistent.',
        ])]]]]]], 200)]);

        $image = new UploadedFile(public_path('images/buyer/advertisements/collection.webp'), 'collection.webp', 'image/webp', null, true);
        $this->actingAs($this->seller)->post('/seller/promotions/assist', ['action' => 'check', 'title' => 'Our shop promotion',
            'alt_text' => 'Selected products from our shop', 'summary' => 'Selected shop products.', 'image' => $image], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('review.valid', true)->assertJsonPath('review.artwork_checked', true);
        Http::assertSent(fn ($request) => isset($request['contents'][0]['parts'][1]['inline_data']['mime_type'])
            && $request['contents'][0]['parts'][1]['inline_data']['mime_type'] === 'image/webp');

        $ad = $this->submitted();
        $submission = $ad->submissions()->sole();
        $this->actingAs($this->admin)->postJson('/admin/advertisements/'.$ad->id.'/assist', ['action' => 'check',
            'submission_id' => $submission->id, 'title' => 'Tampered title', 'alt_text' => 'Tampered text'])
            ->assertOk()->assertJsonPath('review.artwork_checked', true);
        $this->assertSame('pending', $submission->fresh()->state());
        Http::assertSent(fn ($request) => str_contains($request['contents'][0]['parts'][0]['text'], 'Selected products from our shop')
            && ! str_contains($request['contents'][0]['parts'][0]['text'], 'Tampered title'));
        $this->assertDatabaseCount('advertisement_reviews', 0);
    }

    public function test_other_roles_and_currently_ineligible_accounts_cannot_create_or_review_requests(): void
    {
        foreach (['buyer', 'courier', 'logistics', 'admin'] as $role) {
            $actor = User::factory()->create(['role' => $role]);
            $this->actingAs($actor)->postJson('/seller/promotions', $this->data())->assertForbidden();
        }
        DB::table('users')->where('id', $this->seller->id)->update(['email_verified_at' => null]);
        $this->actingAs($this->seller->fresh())->postJson('/seller/promotions', $this->data())->assertForbidden();
        DB::table('users')->where('id', $this->seller->id)->update(['email_verified_at' => now(), 'status' => 'suspended']);
        $this->actingAs($this->seller->fresh())->postJson('/seller/promotions', $this->data())->assertForbidden();
        $this->assertDatabaseCount('advertisements', 0);
    }

    public function test_branding_edits_preserve_shop_approval_and_do_not_create_a_promotion_request(): void
    {
        $ad = $this->approved();
        $submission = $ad->submissions()->sole();
        app(AdvertisementManager::class)->act($this->admin, $ad, $this->command($ad, ['action' => 'publish', 'submission_id' => $submission->id,
            'slot' => 'main_1', 'publish_now' => true, 'claims_reviewed' => true]));
        $before = $this->shop->fresh()->only(['root_category_id', 'review_decision_id', 'review_status', 'review_version']);
        $this->actingAs($this->seller)->post('/seller/settings', ['description' => 'Updated everyday shop details.', 'phone' => '09171234567',
            'logo' => UploadedFile::fake()->image('logo.png', 200, 200), 'banner' => UploadedFile::fake()->image('banner.png', 940, 440)])
            ->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame($before, $this->shop->fresh()->only(array_keys($before)));
        $this->assertSame('Updated everyday shop details.', $this->shop->fresh()->description);
        $this->assertTrue(Product::availableForSale()->whereKey($this->product->id)->exists());
        $this->assertDatabaseCount('advertisement_submissions', 1);
        $this->assertDatabaseCount('advertisement_reviews', 1);
        $this->assertDatabaseCount('advertisements', 1);
        $this->assertCount(1, app(AdvertisementReader::class)->homepage());
    }

    public function test_restricted_shop_retains_owned_history_and_stop_but_cannot_start_new_work(): void
    {
        $ad = $this->approved();
        $submission = $ad->submissions()->sole();
        app(AdvertisementManager::class)->act($this->admin, $ad, $this->command($ad, ['action' => 'publish', 'submission_id' => $submission->id, 'slot' => 'main_1', 'claims_reviewed' => true, 'publish_now' => true]));
        $this->shop->update(['status' => 'suspended']);
        $this->actingAs($this->seller)->get('/seller/promotions/'.$ad->id)->assertOk()->assertInertia(fn (Assert $page) => $page->where('canSubmit', false)->where('promotion.can_stop', true));
        $this->postJson('/seller/promotions', $this->data())->assertUnprocessable()->assertJsonValidationErrors('advertisement');
        $this->post('/seller/promotions/'.$ad->id.'/actions', $this->command($ad, ['action' => 'stop']))->assertSessionHas('success');
        $this->assertSame('withdrawn', $submission->fresh()->state());
        $this->assertSame([], app(AdvertisementReader::class)->homepage());
    }

    public function test_notifications_keep_owned_links_and_cannot_open_another_sellers_promotion(): void
    {
        $ad = $this->approved();
        $submission = $ad->submissions()->sole();
        $sellerNotice = $this->seller->notifications()->where('source_key', 'governance:advertisement_review:'.AdvertisementReview::sole()->id)->sole();
        $adminNotice = $this->admin->notifications()->where('source_key', 'governance:advertisement_submission:'.$submission->id)->sole();
        $center = app(NotificationCenterService::class);
        $this->assertSame('/seller/promotions/'.$ad->id.'#submission-'.$submission->id, $center->present($sellerNotice, $this->seller)['href']);
        $this->assertSame('/admin/advertisements/'.$ad->id.'#submission-'.$submission->id, $center->present($adminNotice, $this->admin)['href']);
        $other = Shop::factory()->approved()->create();
        $this->actingAs($other->user)->patchJson('/notifications/'.$sellerNotice->id.'/read')->assertNotFound();
        $intent = app(NotificationDeliveryService::class)->record($other->user_id, 'governance-event', 'test:foreign-promotion', $sellerNotice->data);
        $foreignNotice = $other->user->notifications()->findOrFail($intent->id);
        $this->assertNull($center->present($foreignNotice, $other->user)['href']);
        DB::table('users')->where('id', $this->seller->id)->update(['status' => 'inactive']);
        $this->assertNull($center->present($sellerNotice, $this->seller->fresh())['href']);
        $this->assertSame(1, NotificationDelivery::where('recipient_id', $this->seller->id)->where('source_key', $sellerNotice->source_key)->count());
    }

    private function voucher(?Shop $shop = null): Voucher
    {
        $service = app(VoucherCampaignService::class);
        $actor = $shop?->user ?? $this->admin;
        $voucher = $service->saveDraft($actor, ['code' => ($shop ? 'SHOP' : 'BGO').strtoupper(Str::random(8)), 'name' => 'Published Bagoo savings', 'description' => 'Savings from the published issuer.',
            'discount_type' => 'fixed', 'discount_value' => '100.00', 'min_spend' => '500.00', 'max_discount' => '100.00', 'budget' => '5000.00',
            'usage_limit' => 50, 'per_buyer_limit' => 2, 'starts_at' => now()->toIso8601String(), 'ends_at' => now()->addDays(3)->toIso8601String(),
            'source_version' => 0, 'request_key' => (string) Str::uuid()], shop: $shop);
        $service->act($actor, $voucher, ['action' => 'publish', 'source_version' => $voucher->fresh()->campaign_version, 'request_key' => (string) Str::uuid()], $shop);

        return $voucher->fresh();
    }

    public function test_promotion_dates_report_linked_voucher_boundaries_beside_the_fields(): void
    {
        $voucher = $this->voucher($this->shop);
        $data = $this->data(['offer_revision_id' => $voucher->current_revision_id,
            'starts_at' => '2026-10-15T19:59', 'ends_at' => '2026-10-18T20:01']);
        $this->actingAs($this->seller)->postJson('/seller/promotions', $data)
            ->assertUnprocessable()->assertJsonValidationErrors(['starts_at', 'ends_at'])->assertJsonMissingPath('errors.advertisement')
            ->assertJsonPath('errors.starts_at.0', 'Start on or after Oct 15, 2026 8:00 PM (Asia/Manila), when the linked voucher begins.')
            ->assertJsonPath('errors.ends_at.0', 'End on or before Oct 18, 2026 8:00 PM (Asia/Manila), when the linked voucher ends.');
        $this->assertDatabaseCount('advertisements', 0);
        $this->post('/seller/promotions', $this->data(['offer_revision_id' => $voucher->current_revision_id,
            'starts_at' => '2026-10-15T20:00', 'ends_at' => '2026-10-18T20:00']))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('advertisements', 1);
    }

    public function test_invalid_offer_dates_on_a_draft_update_are_field_specific_and_leave_saved_dates_unchanged(): void
    {
        $voucher = $this->voucher($this->shop);
        $ad = $this->draft();
        $savedStart = $ad->latestRevision->starts_at->toIso8601String();
        $savedEnd = $ad->latestRevision->ends_at->toIso8601String();

        $this->actingAs($this->seller)->postJson('/seller/promotions/'.$ad->id.'/draft', $this->data([
            'image' => null, 'source_version' => $ad->version, 'offer_revision_id' => $voucher->current_revision_id,
            'starts_at' => '2026-10-15T19:59', 'ends_at' => '2026-10-18T20:00',
        ]))->assertUnprocessable()->assertJsonValidationErrors('starts_at')
            ->assertJsonMissingPath('errors.advertisement')
            ->assertJsonPath('errors.starts_at.0', 'Start on or after Oct 15, 2026 8:00 PM (Asia/Manila), when the linked voucher begins.');

        $this->assertDatabaseCount('advertisement_revisions', 1);
        $this->assertSame($savedStart, $ad->latestRevision->fresh()->starts_at->toIso8601String());
        $this->assertSame($savedEnd, $ad->latestRevision->fresh()->ends_at->toIso8601String());
    }

    public function test_promotion_dates_reject_backdated_start_and_expired_end(): void
    {
        $this->actingAs($this->seller)->postJson('/seller/promotions', $this->data(['starts_at' => '2026-10-14T23:59']))
            ->assertUnprocessable()->assertJsonValidationErrors('starts_at');
        $this->postJson('/seller/promotions', $this->data(['starts_at' => '2026-10-15T10:00', 'ends_at' => '2026-10-15T11:00']))
            ->assertUnprocessable()->assertJsonValidationErrors('ends_at');
        $this->assertDatabaseCount('advertisements', 0);
    }

    public function test_only_owned_published_shop_offers_and_matching_products_can_be_submitted(): void
    {
        $own = $this->voucher($this->shop);
        $other = Shop::factory()->approved()->create();
        $foreign = $this->voucher($other);
        $platform = $this->voucher();
        $data = $this->data(['purpose' => 'offer', 'destination_type' => 'offer', 'destination_id' => null, 'product_ids' => [$this->product->id], 'offer_revision_id' => $own->current_revision_id]);
        $this->actingAs($this->seller)->get('/seller/promotions/create')->assertInertia(fn (Assert $page) => $page->has('options.offers', 1)->where('options.offers.0.id', $own->current_revision_id)->has('options.shops', 1));
        $this->postJson('/seller/promotions', [...$data, 'offer_revision_id' => $foreign->current_revision_id])->assertUnprocessable()->assertJsonValidationErrors('advertisement');
        $this->postJson('/seller/promotions', [...$data, 'offer_revision_id' => $platform->current_revision_id])->assertUnprocessable()->assertJsonValidationErrors('advertisement');
        $foreignProduct = Product::factory()->create(['shop_id' => $other->id, 'category_id' => $other->root_category_id]);
        $this->postJson('/seller/promotions', [...$data, 'product_ids' => [$foreignProduct->id]])->assertUnprocessable()->assertJsonValidationErrors('advertisement');
        $this->postJson('/seller/promotions', [...$data, 'ends_at' => '2026-10-20T20:00'])->assertUnprocessable()
            ->assertJsonValidationErrors('ends_at')->assertJsonMissingPath('errors.advertisement');
        $this->post('/seller/promotions', $data)->assertSessionHasNoErrors()->assertSessionHas('success');
        $ad = Advertisement::sole();
        $service = app(SellerPromotionService::class);
        $service->act($this->seller, $ad, $this->command($ad, ['action' => 'submit', 'revision_id' => $ad->latestRevision->id]));
        $submission = $ad->submissions()->sole();
        $service->decide($this->admin, $ad, $this->command($ad, ['decision' => 'approved', 'submission_id' => $submission->id, 'revision_id' => $submission->revision_id, 'claims_reviewed' => true]));
        app(AdvertisementManager::class)->act($this->admin, $ad, $this->command($ad, ['action' => 'publish', 'submission_id' => $submission->id, 'slot' => 'main_1', 'publish_now' => true, 'claims_reviewed' => true]));
        $this->get('/promotions/'.$ad->slug)->assertInertia(fn (Assert $page) => $page->component('Buyer/Promotion')->where('promotion.offer.code', $own->code)->missing('promotion.offer.budget_cents')->has('products', 1));
        $own->update(['is_active' => false]);
        $this->assertSame([], app(AdvertisementReader::class)->homepage());
    }

    public function test_malformed_upload_and_old_review_fields_preserve_saved_content_and_return_useful_errors(): void
    {
        $ad = $this->draft();
        $image = $ad->latestRevision->asset->image_path;
        $this->postJson('/seller/promotions/'.$ad->id.'/draft', $this->data(['source_version' => $ad->version,
            'image' => UploadedFile::fake()->createWithContent('pretend.jpg', '<html>not a picture</html>')]))->assertUnprocessable()->assertJsonValidationErrors('image');
        $this->assertDatabaseCount('advertisement_revisions', 1);
        Storage::disk('local')->assertExists($image);
        $this->post('/seller/promotions/'.$ad->id.'/draft', $this->data(['image' => null, 'source_version' => 0]), ['X-Inertia' => 'true'])->assertSessionHasErrors('advertisement');
        $this->postJson('/seller/promotions/'.$ad->id.'/actions', $this->command($ad, ['action' => 'submit', 'revision_id' => $ad->latestRevision->id, 'slot' => 'main_1']))->assertUnprocessable()->assertJsonValidationErrors('slot');
        $this->assertDatabaseCount('advertisement_submissions', 0);
    }

    public function test_missing_seller_installation_preserves_platform_management_and_ordinary_browsing(): void
    {
        Schema::drop('advertisement_reviews');
        $this->actingAs($this->seller)->get('/seller/promotions')->assertOk()->assertInertia(fn (Assert $page) => $page->where('available', false));
        $this->postJson('/seller/promotions', $this->data())->assertStatus(503);
        $this->actingAs($this->admin)->post('/admin/advertisements', $this->data(['slot' => 'main_1']))->assertSessionHas('success');
        $ad = Advertisement::sole();
        $this->post('/admin/advertisements/'.$ad->id.'/actions', $this->command($ad, ['action' => 'publish', 'publish_now' => true, 'claims_reviewed' => true]))->assertSessionHas('success');
        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->has('banners', 1));
    }

    public function test_seller_text_boundaries_types_and_unicode_are_validated_before_storage(): void
    {
        foreach ([['title', str_repeat('A', 81)], ['alt_text', str_repeat('B', 181)], ['summary', str_repeat('C', 601)],
            ['title', '---'], ['title', "Bad\u{200B}title"], ['title', "\tBagoo"], ['alt_text', "Bagoo\n"], ['source_version', true], ['source_version', 1.5]] as [$field, $value]) {
            $this->actingAs($this->seller)->postJson('/seller/promotions', $this->data([$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->post('/seller/promotions', $this->data(['title' => '  Ｂａｇｏｏ  ', 'alt_text' => str_repeat('D', 180), 'summary' => str_repeat('E', 600)]))->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame('Bagoo', Advertisement::sole()->latestRevision->title);
        $this->assertSame(180, mb_strlen(Advertisement::sole()->latestRevision->alt_text));
        $this->assertDatabaseCount('advertisement_assets', 1);
    }

    public function test_foreign_revision_and_submission_ids_cannot_be_attached_to_an_owned_request(): void
    {
        $first = $this->submitted();
        $second = $this->submitted();
        $foreign = $second->submissions()->sole();
        $this->postJson('/seller/promotions/'.$first->id.'/actions', $this->command($first, ['action' => 'withdraw', 'submission_id' => $foreign->id]))->assertNotFound();
        $this->get('/seller/promotions/'.$first->id.'/preview?revision='.$foreign->revision_id)->assertNotFound();
        $this->assertSame('pending', $foreign->fresh()->state());
        $this->assertSame('pending', $first->submissions()->sole()->state());
    }
}

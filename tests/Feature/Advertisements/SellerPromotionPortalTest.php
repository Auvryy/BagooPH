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
        $voucher = $service->saveDraft($actor, ['code' => ($shop ? 'SHOP_' : 'BGO_').strtoupper(Str::random(8)), 'name' => 'Published Bagoo savings', 'description' => 'Savings from the published issuer.',
            'discount_type' => 'fixed', 'discount_value' => '100.00', 'min_spend' => '500.00', 'max_discount' => '100.00', 'budget' => '5000.00',
            'usage_limit' => 50, 'per_buyer_limit' => 2, 'starts_at' => now()->subDay()->toIso8601String(), 'ends_at' => now()->addDays(3)->toIso8601String(),
            'source_version' => 0, 'request_key' => (string) Str::uuid()], shop: $shop);
        $service->act($actor, $voucher, ['action' => 'publish', 'source_version' => $voucher->fresh()->campaign_version, 'request_key' => (string) Str::uuid()], $shop);

        return $voucher->fresh();
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
        $this->postJson('/seller/promotions', [...$data, 'ends_at' => '2026-10-20T20:00'])->assertUnprocessable()->assertJsonValidationErrors('advertisement');
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

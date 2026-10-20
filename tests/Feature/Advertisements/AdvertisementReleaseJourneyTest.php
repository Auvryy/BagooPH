<?php

namespace Tests\Feature\Advertisements;

use App\Models\Advertisement;
use App\Models\AdvertisementPublication;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\Advertisements\AdvertisementManager;
use App\Services\Advertisements\AdvertisementReader;
use App\Services\Vouchers\VoucherCampaignService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdvertisementReleaseJourneyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Shop $shop;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        config(['advertisements.enabled' => true, 'advertisements.measurement_enabled' => true]);
        $this->travelTo(CarbonImmutable::parse('2026-10-15T02:00:00Z'));
        $this->admin = User::factory()->admin()->create();
        $this->shop = Shop::factory()->approved()->create();
        $this->product = Product::factory()->create(['shop_id' => $this->shop->id, 'category_id' => $this->shop->root_category_id]);
    }

    private function data(array $changes = []): array
    {
        return array_replace(['title' => 'Everyday essentials', 'alt_text' => 'Everyday essentials available from our shop',
            'purpose' => 'feature', 'destination_type' => 'product', 'destination_id' => $this->product->id, 'product_ids' => [],
            'slot' => 'main_1', 'starts_at' => '2026-10-15T10:00', 'ends_at' => '2026-10-15T11:00',
            'image' => UploadedFile::fake()->image('essentials.png', 940, 440), 'source_version' => 0, 'request_key' => (string) Str::uuid()], $changes);
    }

    private function command(Advertisement $ad, array $input): array
    {
        return ['source_version' => $ad->fresh()->version, 'request_key' => (string) Str::uuid(), ...$input];
    }

    private function platform(array $changes, bool $now = true): AdvertisementPublication
    {
        $manager = app(AdvertisementManager::class);
        $ad = $manager->saveDraft($this->admin, $this->data($changes));
        $manager->act($this->admin, $ad, $this->command($ad, ['action' => 'publish', 'publish_now' => $now, 'claims_reviewed' => true]));

        return $ad->publications()->sole();
    }

    public function test_platform_collection_shop_and_real_offer_keep_their_destinations_expiry_and_recorded_results(): void
    {
        $collection = $this->platform(['title' => 'Everyday essentials collection', 'destination_type' => 'collection', 'destination_id' => null, 'product_ids' => [$this->product->id]]);
        $shop = $this->platform(['title' => 'Explore our approved shop', 'slot' => 'side_1', 'destination_type' => 'shop', 'destination_id' => $this->shop->id]);
        $service = app(VoucherCampaignService::class);
        $voucher = $service->saveDraft($this->admin, ['code' => 'BGOESSENTIAL', 'name' => 'Everyday savings', 'description' => 'Save PHP 100 on eligible purchases of PHP 500 or more.',
            'discount_type' => 'fixed', 'discount_value' => '100.00', 'min_spend' => '500.00', 'max_discount' => '100.00', 'budget' => '5000.00',
            'usage_limit' => 50, 'per_buyer_limit' => 2, 'starts_at' => now()->subDay()->toIso8601String(), 'ends_at' => now()->addDay()->toIso8601String(),
            'source_version' => 0, 'request_key' => (string) Str::uuid()]);
        $service->act($this->admin, $voucher, ['action' => 'publish', 'source_version' => $voucher->fresh()->campaign_version, 'request_key' => (string) Str::uuid()]);
        $offer = $this->platform(['title' => 'PHP 100 off eligible essentials', 'purpose' => 'offer', 'slot' => 'main_2', 'destination_type' => 'offer', 'destination_id' => null,
            'product_ids' => [$this->product->id], 'offer_revision_id' => $voucher->fresh()->current_revision_id, 'starts_at' => '2026-10-15T10:01', 'ends_at' => '2026-10-15T10:02'], false);
        $reader = app(AdvertisementReader::class);
        $this->assertCount(2, $reader->homepage());
        $this->get('/advertisement/'.$shop->advertisement->slug)->assertRedirect(route('shop.show', $this->shop->slug));
        $this->get('/promotions/'.$collection->advertisement->slug)->assertInertia(fn (Assert $page) => $page->where('promotion.available', true)->where('products.0.id', $this->product->id));
        $this->get('/promotions/'.$offer->advertisement->slug)->assertNotFound();
        $this->travel(1)->minutes();
        $this->assertCount(3, $reader->homepage());
        $banners = collect($reader->homepage());
        $token = $banners->firstWhere('id', $offer->id)['measurement']['token'];
        $collectionToken = $banners->firstWhere('id', $collection->id)['measurement']['token'];
        $this->travel(1)->seconds();
        foreach (['impression', 'click', 'click'] as $type) {
            $this->postJson('/advertisement-events', ['token' => $token, 'type' => $type])->assertNoContent();
        }
        $this->get('/promotions/'.$offer->advertisement->slug)->assertInertia(fn (Assert $page) => $page->where('promotion.offer.value_cents', 10000)->where('promotion.offer.minimum_cents', 50000)->has('products', 1));
        app(AdvertisementManager::class)->act($this->admin, $collection->advertisement, $this->command($collection->advertisement, ['action' => 'pause', 'publication_id' => $collection->id]));
        $this->postJson('/advertisement-events', ['token' => $collectionToken, 'type' => 'click'])->assertNoContent();
        $this->assertCount(2, $reader->homepage());
        $this->travelTo(CarbonImmutable::parse('2026-10-15T02:02:00Z'));
        $this->assertCount(1, $reader->homepage());
        $this->get('/promotions/'.$offer->advertisement->slug.'?publication='.$offer->id)->assertInertia(fn (Assert $page) => $page->where('promotion.available', false)->where('promotion.offer', null)->has('products', 0));
        $this->product->update(['status' => 'inactive']);
        $this->assertSame([], $reader->homepage());
        $this->actingAs($this->admin)->get('/admin/advertisements/performance?publication_id='.$offer->id)
            ->assertInertia(fn (Assert $page) => $page->where('totals.impressions', 1)->where('totals.clicks', 1)->where('placements.data.0.state', 'ended'));
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('voucher_redemptions', 0);
    }

    public function test_seller_feedback_resubmission_review_publication_and_withdrawal_retain_exact_revision_results(): void
    {
        $data = $this->data();
        unset($data['slot']);
        $this->actingAs($this->shop->user)->post('/seller/promotions', $data)->assertSessionHasNoErrors();
        $ad = Advertisement::sole();
        $this->post('/seller/promotions/'.$ad->id.'/actions', $this->command($ad, ['action' => 'submit', 'revision_id' => $ad->latestRevision->id]))->assertSessionHasNoErrors();
        $original = $ad->submissions()->sole();
        $this->actingAs($this->admin)->post('/admin/advertisements/'.$ad->id.'/review', $this->command($ad, ['decision' => 'changes_requested', 'submission_id' => $original->id,
            'revision_id' => $original->revision_id, 'reason' => 'Please clarify the featured product in your message.']))->assertSessionHasNoErrors();
        $this->actingAs($this->shop->user)->get('/seller/promotions/'.$ad->id)->assertInertia(fn (Assert $page) => $page->where('promotion.review_state', 'changes_requested')->where('promotion.submissions.0.reviews.0.reason', 'Please clarify the featured product in your message.'));
        $revised = [...$this->data(['title' => 'Our reviewed everyday essential']), ...$this->command($ad, [])];
        unset($revised['slot']);
        $this->post('/seller/promotions/'.$ad->id.'/draft', $revised)->assertSessionHasNoErrors();
        $this->post('/seller/promotions/'.$ad->id.'/actions', $this->command($ad, ['action' => 'submit', 'revision_id' => $ad->fresh()->latestRevision->id]))->assertSessionHasNoErrors();
        $submission = $ad->submissions()->latest('id')->firstOrFail();
        $this->actingAs($this->admin)->postJson('/admin/advertisements/'.$ad->id.'/review', $this->command($ad, ['decision' => 'approved', 'submission_id' => $original->id,
            'revision_id' => $original->revision_id, 'claims_reviewed' => true]))->assertConflict();
        $this->post('/admin/advertisements/'.$ad->id.'/review', $this->command($ad, ['decision' => 'approved', 'submission_id' => $submission->id,
            'revision_id' => $submission->revision_id, 'claims_reviewed' => true]))->assertSessionHasNoErrors();
        $this->post('/admin/advertisements/'.$ad->id.'/actions', $this->command($ad, ['action' => 'publish', 'submission_id' => $submission->id, 'slot' => 'side_1',
            'publish_now' => true, 'claims_reviewed' => true]))->assertSessionHasNoErrors();
        $publication = $ad->publications()->sole();
        $banner = app(AdvertisementReader::class)->homepage()[0];
        $this->travel(1)->seconds();
        foreach (['impression', 'click'] as $type) {
            $this->postJson('/advertisement-events', ['token' => $banner['measurement']['token'], 'type' => $type])->assertNoContent();
        }
        $this->actingAs($this->shop->user)->get('/seller/promotions/performance?advertisement_id='.$ad->id.'&revision_id='.$submission->revision_id)
            ->assertInertia(fn (Assert $page) => $page->where('totals.impressions', 1)->where('totals.clicks', 1)->where('placements.data.0.title', 'Our reviewed everyday essential'));
        $newDraft = [...$this->data(['title' => 'Private unsubmitted replacement']), ...$this->command($ad, [])];
        unset($newDraft['slot']);
        $this->post('/seller/promotions/'.$ad->id.'/draft', $newDraft)->assertSessionHasNoErrors();
        $this->assertSame('Our reviewed everyday essential', app(AdvertisementReader::class)->homepage()[0]['title']);
        $this->post('/seller/promotions/'.$ad->id.'/actions', $this->command($ad, ['action' => 'withdraw', 'submission_id' => $submission->id]))->assertSessionHasNoErrors();
        $this->assertSame([], app(AdvertisementReader::class)->homepage());
        $this->postJson('/advertisement-events', ['token' => $banner['measurement']['token'], 'type' => 'click'])->assertNoContent();
        $this->get('/seller/promotions/performance?publication_id='.$publication->id)->assertInertia(fn (Assert $page) => $page->where('totals.impressions', 1)->where('totals.clicks', 1)->where('placements.data.0.revision_id', $submission->revision_id));
        $this->get('/promotions/'.$ad->slug.'?publication='.$publication->id)->assertInertia(fn (Assert $page) => $page->where('promotion.available', false)->has('products', 0));
        $this->assertSame('changes_requested', $original->fresh()->state());
        $this->assertSame('withdrawn', $submission->fresh()->state());
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('voucher_redemptions', 0);
    }
}

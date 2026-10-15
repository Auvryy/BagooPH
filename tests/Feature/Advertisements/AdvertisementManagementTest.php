<?php

namespace Tests\Feature\Advertisements;

use App\Models\Advertisement;
use App\Models\AdvertisementAction;
use App\Models\AdvertisementAsset;
use App\Models\AdvertisementPublication;
use App\Models\Product;
use App\Models\User;
use App\Services\Advertisements\AdvertisementManager;
use App\Services\Advertisements\AdvertisementReader;
use App\Services\Vouchers\VoucherCampaignService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AdvertisementManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        config(['advertisements.enabled' => true]);
        $this->travelTo(now()->setDate(2026, 10, 15)->setTime(2, 0));
        $this->admin = User::factory()->admin()->create();
        $this->product = Product::factory()->create(['name' => 'Everyday bag', 'featured_image' => null]);
    }

    private function payload(array $changes = []): array
    {
        return array_replace(['title' => 'Bagoo everyday collection', 'alt_text' => 'Everyday bags in the Bagoo collection',
            'summary' => 'Explore the current collection.', 'purpose' => 'feature', 'destination_type' => 'product',
            'destination_id' => $this->product->id, 'product_ids' => [], 'offer_revision_id' => null,
            'slot' => 'main_1', 'starts_at' => '2026-10-15T10:00', 'ends_at' => '2026-10-16T10:00',
            'request_key' => (string) Str::uuid(), 'source_version' => 0,
            'image' => new UploadedFile(public_path('images/buyer/advertisements/collection.webp'), 'collection.webp', 'image/webp', null, true)], $changes);
    }

    private function draft(array $changes = []): Advertisement
    {
        $this->actingAs($this->admin)->post('/admin/advertisements', $this->payload($changes))->assertSessionHasNoErrors()->assertSessionHas('success');

        return Advertisement::latest('id')->firstOrFail();
    }

    private function action(Advertisement $ad, string $action = 'publish', array $changes = []): array
    {
        return array_replace(['action' => $action, 'source_version' => $ad->fresh()->version, 'request_key' => (string) Str::uuid()],
            $action === 'publish' ? ['claims_reviewed' => true, 'publish_now' => true] : [], $changes);
    }

    private function publish(Advertisement $ad, array $changes = []): AdvertisementPublication
    {
        $this->post('/admin/advertisements/'.$ad->id.'/actions', $this->action($ad, changes: $changes))->assertSessionHasNoErrors()->assertSessionHas('success');

        return $ad->publications()->latest('id')->firstOrFail();
    }

    public function test_draft_is_private_and_publication_reaches_the_exact_product_without_touching_checkout(): void
    {
        $ad = $this->draft();
        $asset = AdvertisementAsset::sole();
        $this->assertSame([], app(AdvertisementReader::class)->homepage());
        $this->get('/advertisement-artwork/'.$asset->token)->assertNotFound();
        $this->get('/promotions/'.$ad->slug)->assertNotFound();
        $this->get('/admin/advertisements/'.$ad->id.'/preview')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get('/admin/advertisements/artwork/'.$asset->id)->assertOk()->assertHeader('Content-Type', 'image/webp');
        $publication = $this->publish($ad);
        $this->assertCount(1, app(AdvertisementReader::class)->homepage());
        $this->app['auth']->forgetGuards();
        $this->get('/advertisement/'.$ad->slug.'?publication='.$publication->id)->assertRedirect(route('products.show', $this->product->slug));
        $this->get('/advertisement-artwork/'.$asset->token)->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('voucher_redemptions', 0);
    }

    public function test_draft_and_publication_retries_are_idempotent_and_changed_or_stale_commands_conflict(): void
    {
        $data = $this->payload();
        $this->actingAs($this->admin)->post('/admin/advertisements', $data)->assertSessionHas('success');
        $this->post('/admin/advertisements', $data)->assertSessionHas('success');
        $ad = Advertisement::sole();
        $publish = $this->action($ad);
        $this->post('/admin/advertisements/'.$ad->id.'/actions', $publish)->assertSessionHas('success');
        $this->post('/admin/advertisements/'.$ad->id.'/actions', $publish)->assertSessionHas('success');
        $this->assertDatabaseCount('advertisement_revisions', 1);
        $this->assertDatabaseCount('advertisement_publications', 1);
        $this->assertDatabaseCount('advertisement_actions', 2);
        $this->assertCount(2, Storage::disk('local')->allFiles('advertisements'));
        $this->postJson('/admin/advertisements', [...$data, 'title' => 'Changed title'])->assertConflict();
        $this->postJson('/admin/advertisements/'.$ad->id.'/actions', [...$this->action($ad, 'pause'), 'source_version' => 0])->assertConflict();
        $this->post('/admin/advertisements/'.$ad->id.'/actions', [...$this->action($ad, 'pause'), 'source_version' => 0], ['X-Inertia' => 'true'])->assertSessionHasErrors('advertisement');
    }

    public function test_scheduled_windows_use_manila_half_open_boundaries_without_a_scheduler(): void
    {
        $ad = $this->draft(['starts_at' => '2026-10-15T10:01', 'ends_at' => '2026-10-15T10:02']);
        $publication = $this->publish($ad, ['publish_now' => false]);
        $this->assertSame('2026-10-15 02:01:00', $publication->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame([], app(AdvertisementReader::class)->homepage());
        $this->get('/promotions/'.$ad->slug)->assertNotFound();
        $this->travel(1)->minutes();
        $this->assertCount(1, app(AdvertisementReader::class)->homepage());
        $this->travel(1)->minutes();
        $this->assertSame([], app(AdvertisementReader::class)->homepage());
        $this->get('/promotions/'.$ad->slug.'?publication='.$publication->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Buyer/Promotion')->where('promotion.available', false)->has('products', 0));
    }

    public function test_pause_keeps_a_position_end_releases_it_and_adjacent_windows_are_allowed(): void
    {
        $first = $this->draft();
        $publication = $this->publish($first);
        $this->post('/admin/advertisements/'.$first->id.'/actions', $this->action($first, 'pause', ['publication_id' => $publication->id]))->assertSessionHas('success');
        $this->assertSame([], app(AdvertisementReader::class)->homepage());
        $second = $this->draft();
        $this->postJson('/admin/advertisements/'.$second->id.'/actions', $this->action($second))->assertUnprocessable()->assertJsonValidationErrors('advertisement');
        $this->assertDatabaseCount('advertisement_publications', 1);
        $this->post('/admin/advertisements/'.$first->id.'/actions', $this->action($first, 'end', ['publication_id' => $publication->id]))->assertSessionHas('success');
        $this->publish($second);
        $third = $this->draft(['starts_at' => '2026-10-16T10:00', 'ends_at' => '2026-10-17T10:00']);
        $this->publish($third, ['publish_now' => false]);
        $this->assertCount(1, app(AdvertisementReader::class)->homepage());
    }

    public function test_current_authority_is_required_even_for_a_previously_loaded_admin(): void
    {
        $data = $this->payload();
        $this->actingAs($this->product->shop->user)->postJson('/admin/advertisements', $data)->assertForbidden();
        $admin = $this->admin;
        DB::table('users')->where('id', $admin->id)->update(['status' => 'suspended']);
        try {
            app(AdvertisementManager::class)->saveDraft($admin, $data);
            $this->fail('A stale admin instance must not authorize a write.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('advertisements', 0);
    }

    public function test_import_is_an_incomplete_unpublished_candidate_and_never_a_live_sale(): void
    {
        $data = ['artwork' => 'mega-sale', 'request_key' => (string) Str::uuid(), 'source_version' => 0];
        $this->actingAs($this->admin)->post('/admin/advertisements/import', $data)->assertSessionHas('success');
        $this->post('/admin/advertisements/import', $data)->assertSessionHas('success');
        $ad = Advertisement::sole();
        $this->assertTrue($ad->latestRevision->imported_candidate);
        $this->postJson('/admin/advertisements/'.$ad->id.'/actions', $this->action($ad))->assertUnprocessable();
        $this->assertSame([], app(AdvertisementReader::class)->homepage());
        $this->assertDatabaseCount('advertisement_publications', 0);
        $this->assertDatabaseCount('advertisement_actions', 1);
        $this->get('/promotions/'.$ad->slug)->assertNotFound();
    }

    public function test_direct_status_and_arbitrary_urls_are_rejected_and_approval_is_required(): void
    {
        $this->actingAs($this->admin)->postJson('/admin/advertisements', $this->payload(['status' => 'live', 'url' => 'javascript:alert(1)']))
            ->assertUnprocessable()->assertJsonValidationErrors(['status', 'url']);
        $ad = $this->draft();
        $this->postJson('/admin/advertisements/'.$ad->id.'/actions', $this->action($ad, changes: ['claims_reviewed' => false]))
            ->assertUnprocessable()->assertJsonValidationErrors('claims_reviewed');
        $this->assertDatabaseCount('advertisement_publications', 0);
        $this->assertSame(1, AdvertisementAction::count());
    }

    public function test_new_drafts_do_not_change_live_content_and_replacement_pins_old_links_to_old_history(): void
    {
        $ad = $this->draft();
        $first = $this->publish($ad);
        $original = $ad->latestRevision->getRawOriginal();
        $this->post('/admin/advertisements/'.$ad->id.'/draft', $this->payload([
            'title' => 'Updated Bagoo feature', 'source_version' => $ad->fresh()->version, 'image' => null,
            'slot' => 'side_1', 'starts_at' => '2026-10-15T10:01',
        ]))->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame($first->id, app(AdvertisementReader::class)->homepage()[0]['id']);
        $this->assertSame($original, $first->revision->fresh()->getRawOriginal());
        $this->assertDatabaseCount('advertisement_assets', 1);
        $next = $this->publish($ad, ['publish_now' => false]);
        $this->assertCount(1, app(AdvertisementReader::class)->homepage());
        $this->travel(1)->minutes();
        $this->assertSame([$next->id], array_column(app(AdvertisementReader::class)->homepage(), 'id'));
        $this->get('/advertisement/'.$ad->slug.'?publication='.$first->id)->assertRedirect(route('promotions.show', ['slug' => $ad->slug, 'publication' => $first->id]));
        $this->get('/promotions/'.$ad->slug.'?publication='.$first->id)->assertInertia(fn (Assert $page) => $page->where('promotion.available', false)->where('promotion.title', 'Promotion unavailable'));
    }

    public function test_current_catalogue_restrictions_missing_artwork_and_disable_take_effect_on_fresh_reads(): void
    {
        $ad = $this->draft();
        $publication = $this->publish($ad);
        $this->get('/')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertInertia(fn (Assert $page) => $page->has('banners', 1));
        $this->product->shop->update(['status' => 'suspended']);
        $this->assertSame([], app(AdvertisementReader::class)->homepage());
        $this->get('/promotions/'.$ad->slug)->assertInertia(fn (Assert $page) => $page->where('promotion.available', false)->has('products', 0));
        $this->product->shop->update(['status' => 'active']);
        config(['advertisements.enabled' => false]);
        $this->assertSame([], app(AdvertisementReader::class)->homepage());
        config(['advertisements.enabled' => true]);
        $this->assertCount(1, app(AdvertisementReader::class)->homepage());
        Storage::disk('local')->delete($publication->revision->asset->image_path);
        $this->assertSame([], app(AdvertisementReader::class)->homepage());
        $this->get('/advertisement/'.$ad->slug)->assertRedirect(route('promotions.show', ['slug' => $ad->slug, 'publication' => $publication->id]));
    }

    public function test_ending_a_future_placement_never_exposes_its_private_image(): void
    {
        $ad = $this->draft(['starts_at' => '2026-10-16T10:00', 'ends_at' => '2026-10-17T10:00']);
        $publication = $this->publish($ad, ['publish_now' => false]);
        $this->post('/admin/advertisements/'.$ad->id.'/actions', $this->action($ad, 'end', ['publication_id' => $publication->id]))->assertSessionHas('success');
        $this->travel(2)->days();
        $this->get('/advertisement-artwork/'.$publication->revision->asset->token)->assertNotFound();
    }

    public function test_publication_failure_rolls_back_replacement_and_storage_failure_preserves_the_working_image(): void
    {
        $ad = $this->draft();
        $original = $this->publish($ad);
        $files = Storage::disk('local')->allFiles('advertisements');
        $this->post('/admin/advertisements/'.$ad->id.'/draft', $this->payload(['source_version' => $ad->fresh()->version, 'image' => null]))->assertSessionHas('success');
        DB::unprepared("CREATE TRIGGER fail_ad_decision BEFORE INSERT ON advertisement_actions WHEN NEW.action = 'publish' BEGIN SELECT RAISE(ABORT, 'Simulated decision failure'); END");
        $this->postJson('/admin/advertisements/'.$ad->id.'/actions', $this->action($ad))->assertServerError();
        $this->assertDatabaseCount('advertisement_publications', 1);
        $this->assertNull($original->fresh()->superseded_at);
        $this->assertSame($original->id, app(AdvertisementReader::class)->homepage()[0]['id']);
        DB::unprepared('DROP TRIGGER fail_ad_decision');

        $disk = Storage::disk('local');
        $filesystemManager = Storage::getFacadeRoot();
        $failingDisk = \Mockery::mock($disk);
        $failingDisk->shouldReceive('put')->andReturn(false);
        Storage::shouldReceive('disk')->with('local')->andReturn($failingDisk);
        $this->postJson('/admin/advertisements/'.$ad->id.'/draft', $this->payload(['source_version' => $ad->fresh()->version]))
            ->assertUnprocessable()->assertJsonValidationErrors('image');
        Storage::swap($filesystemManager);
        $this->assertSame($files, $disk->allFiles('advertisements'));
        // A failing database insert happens after both image files have been written.
        DB::unprepared("CREATE TRIGGER fail_ad_revision BEFORE INSERT ON advertisement_revisions BEGIN SELECT RAISE(ABORT, 'Simulated revision failure'); END");
        $this->postJson('/admin/advertisements/'.$ad->id.'/draft', $this->payload(['source_version' => $ad->fresh()->version]))->assertServerError();
        $this->assertSame($files, Storage::disk('local')->allFiles('advertisements'));
        $this->assertSame($original->id, app(AdvertisementReader::class)->homepage()[0]['id']);
    }

    #[DataProvider('invalidFields')]
    public function test_invalid_fields_cannot_save_a_revision(array $changes, string $field): void
    {
        $this->actingAs($this->admin)->postJson('/admin/advertisements', $this->payload($changes))->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('advertisement_revisions', 0);
    }

    public static function invalidFields(): array
    {
        return [
            'blank title' => [['title' => '   '], 'title'],
            'long title' => [['title' => str_repeat('a', 81)], 'title'],
            'long description' => [['alt_text' => str_repeat('a', 181)], 'alt_text'],
            'long summary' => [['summary' => str_repeat('a', 601)], 'summary'],
            'script text' => [['title' => '<script>alert(1)</script>'], 'title'],
            'hidden text' => [['alt_text' => "Bagoo\u{200B} image"], 'alt_text'],
            'bad dates' => [['starts_at' => '2026-02-30T10:00'], 'starts_at'],
            'equal dates' => [['ends_at' => '2026-10-15T10:00'], 'ends_at'],
            'unknown placement' => [['slot' => 'main_4'], 'slot'],
            'unavailable product' => [['destination_id' => 99999999], 'advertisement'],
            'empty collection' => [['destination_type' => 'collection', 'destination_id' => null], 'product_ids'],
            'duplicate collection' => [['destination_type' => 'collection', 'destination_id' => null, 'product_ids' => [1, 1]], 'product_ids.0'],
            'offer without published terms' => [['purpose' => 'offer'], 'offer_revision_id'],
            'missing offer revision' => [['purpose' => 'offer', 'offer_revision_id' => 99999999], 'advertisement'],
            'unknown destination type' => [['destination_type' => 'external'], 'destination_type'],
            'too many selected products' => [['destination_type' => 'collection', 'destination_id' => null, 'product_ids' => range(1, 25)], 'product_ids'],
        ];
    }

    public function test_exact_text_boundaries_are_trimmed_and_kept(): void
    {
        $ad = $this->draft(['title' => '  A  ', 'alt_text' => str_repeat('B', 180), 'summary' => str_repeat('C', 600)]);
        $this->assertSame('A', $ad->latestRevision->title);
        $this->assertSame(180, mb_strlen($ad->latestRevision->alt_text));
        $this->post('/admin/advertisements/'.$ad->id.'/draft', $this->payload(['title' => str_repeat('D', 80), 'alt_text' => 'E', 'source_version' => $ad->version, 'image' => null]))->assertSessionHas('success');
        $this->assertSame(80, mb_strlen($ad->fresh()->latestRevision->title));
    }

    public function test_retained_revisions_and_actions_reject_direct_database_mutation(): void
    {
        $ad = $this->draft();
        foreach (['advertisement_assets', 'advertisement_revisions', 'advertisement_actions'] as $table) {
            try {
                DB::table($table)->where('id', 1)->update(['updated_at' => now()->addSecond()]);
                $this->fail('Retained evidence must not be mutable.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('immutable', $exception->getMessage());
            }
        }
        $this->assertDatabaseHas('advertisements', ['id' => $ad->id]);
    }

    public function test_real_offer_terms_are_public_without_funding_and_changing_them_suppresses_the_old_ad(): void
    {
        $service = app(VoucherCampaignService::class);
        $terms = ['code' => 'BAGOO_SHIP', 'name' => 'Bagoo delivery savings', 'description' => 'A capped shipping discount.',
            'discount_type' => 'free_shipping', 'discount_value' => '50.00', 'min_spend' => '100.00', 'max_discount' => '30.00',
            'budget' => '5000.00', 'usage_limit' => 100, 'per_buyer_limit' => 2, 'starts_at' => now()->subDay()->toIso8601String(),
            'ends_at' => now()->addDays(2)->toIso8601String(), 'source_version' => 0, 'request_key' => (string) Str::uuid()];
        $offer = $service->saveDraft($this->admin, $terms);
        $service->act($this->admin, $offer, ['action' => 'publish', 'source_version' => $offer->fresh()->campaign_version, 'request_key' => (string) Str::uuid()]);
        $offer = $offer->fresh();
        $ad = $this->draft(['purpose' => 'offer', 'offer_revision_id' => $offer->current_revision_id]);
        $publication = $this->publish($ad);
        $this->get('/advertisement/'.$ad->slug)->assertRedirect(route('promotions.show', ['slug' => $ad->slug, 'publication' => $publication->id]));
        $this->get('/promotions/'.$ad->slug)->assertInertia(fn (Assert $page) => $page->component('Buyer/Promotion')
            ->where('promotion.available', true)->where('promotion.offer.maximum_cents', 3000)->where('promotion.offer.code', 'BAGOO_SHIP')
            ->missing('promotion.offer.budget_cents')->missing('promotion.offer.funding_party')->has('products', 1));
        $offer->currentRevision->balance->update(['redemption_count' => 100]);
        $this->assertSame([], app(AdvertisementReader::class)->homepage());
        $offer->currentRevision->balance->update(['redemption_count' => 0]);
        $service->saveDraft($this->admin, [...$terms, 'name' => 'Revised delivery savings', 'request_key' => (string) Str::uuid(), 'source_version' => $offer->campaign_version], $offer);
        $service->act($this->admin, $offer, ['action' => 'publish', 'source_version' => $offer->fresh()->campaign_version, 'request_key' => (string) Str::uuid()]);
        $this->assertSame([], app(AdvertisementReader::class)->homepage());
        $this->get('/promotions/'.$ad->slug)->assertInertia(fn (Assert $page) => $page->where('promotion.available', false)->where('promotion.offer', null));
    }

    public function test_guest_and_pending_buyer_can_browse_a_collection_but_cannot_bypass_checkout(): void
    {
        $ad = $this->draft(['destination_type' => 'collection', 'destination_id' => null, 'product_ids' => [$this->product->id]]);
        $this->publish($ad);
        $this->app['auth']->forgetGuards();
        $this->get('/promotions/'.$ad->slug)->assertOk()->assertInertia(fn (Assert $page) => $page->has('products', 1));
        $buyer = User::factory()->create(['status' => 'pending', 'kyc_status' => 'pending']);
        $this->actingAs($buyer)->get('/promotions/'.$ad->slug)->assertOk()->assertInertia(fn (Assert $page) => $page->has('products', 1));
        $this->get('/buyer/checkout')->assertForbidden();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_admin_domain_uses_the_same_writer_and_private_preview_cannot_read_another_ads_revision(): void
    {
        $ad = $this->draft();
        $other = $this->draft(['title' => 'Another feature']);
        $this->get('/admin/advertisements/'.$ad->id.'/preview?revision='.$other->latestRevision->id)->assertNotFound();
        $this->get('http://admin.localhost/advertisements')->assertOk()->assertInertia(fn (Assert $page) => $page->where('baseUrl', '/advertisements'));
        $this->post('http://admin.localhost/advertisements/'.$ad->id.'/actions', $this->action($ad))->assertRedirect('/advertisements/'.$ad->id);
        $this->actingAs(User::factory()->logistics()->create())->get('/admin/advertisements/'.$ad->id.'/preview')->assertForbidden();
        $this->get('/admin/advertisements/artwork/'.$ad->latestRevision->asset_id)->assertForbidden();
    }

    public function test_missing_installation_keeps_the_marketplace_available_and_blocks_writers_cleanly(): void
    {
        Schema::drop('advertisement_schedule_locks');
        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->has('banners', 0));
        $this->actingAs($this->admin)->get('/admin/advertisements')->assertOk()->assertInertia(fn (Assert $page) => $page->where('available', false));
        $this->postJson('/admin/advertisements', $this->payload())->assertStatus(503);
    }

    public function test_archive_retains_history_and_draft_filter_includes_unpublished_edits_to_a_live_ad(): void
    {
        $ad = $this->draft();
        $publication = $this->publish($ad);
        $this->post('/admin/advertisements/'.$ad->id.'/draft', $this->payload(['source_version' => $ad->fresh()->version, 'image' => null]))->assertSessionHas('success');
        $this->get('/admin/advertisements?state=draft')->assertInertia(fn (Assert $page) => $page->has('advertisements.data', 1)->where('advertisements.data.0.has_draft', true)->where('advertisements.data.0.state', 'live'));
        $this->post('/admin/advertisements/'.$ad->id.'/actions', $this->action($ad, 'archive'))->assertSessionHas('success');
        $this->assertSame([], app(AdvertisementReader::class)->homepage());
        $this->assertDatabaseCount('advertisement_revisions', 2);
        $this->assertDatabaseCount('advertisement_publications', 1);
        $this->postJson('/admin/advertisements/'.$ad->id.'/actions', $this->action($ad, 'resume', ['publication_id' => $publication->id]))->assertUnprocessable();
        $this->assertDatabaseCount('advertisement_actions', 4);
    }

    public function test_decoded_formats_and_exact_source_size_limits_produce_private_sources_and_optimized_images(): void
    {
        foreach (['jpg', 'png', 'webp'] as $extension) {
            $image = $extension === 'webp' ? $this->payload()['image'] : UploadedFile::fake()->image('banner.'.$extension, 940, 440);
            $ad = $this->draft(['image' => $image]);
            $asset = $ad->latestRevision->asset;
            $bytes = Storage::disk('local')->get($asset->image_path);
            $this->assertSame(IMAGETYPE_WEBP, getimagesizefromstring($bytes)[2]);
            $this->assertLessThanOrEqual(358400, strlen($bytes));
            Storage::disk('local')->assertExists($asset->source_path);
            $this->get('/advertisement-artwork/'.$asset->token)->assertNotFound();
        }
        $jpegFile = UploadedFile::fake()->image('limit.jpg', 940, 440);
        $jpeg = file_get_contents($jpegFile->getRealPath());
        $exact = str_pad($jpeg, 5242880, "\0");
        $ad = $this->draft(['image' => UploadedFile::fake()->createWithContent('limit.jpg', $exact)]);
        $this->assertSame(5242880, $ad->latestRevision->asset->source_bytes);
        $this->postJson('/admin/advertisements', $this->payload(['image' => UploadedFile::fake()->createWithContent('limit.jpg', $exact."\0")]))
            ->assertUnprocessable()->assertJsonValidationErrors('image');
    }

    public function test_disguised_unsupported_and_corrupt_files_and_wrong_dimensions_never_create_assets(): void
    {
        $files = [
            UploadedFile::fake()->createWithContent('pretend.jpg', '<html>not an image</html>'),
            UploadedFile::fake()->createWithContent('document.pdf', '%PDF-1.7 example'),
            UploadedFile::fake()->createWithContent('drawing.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'),
            UploadedFile::fake()->createWithContent('video.webp', 'not a real video or image'),
            UploadedFile::fake()->image('animated.gif', 940, 440),
            UploadedFile::fake()->image('small.jpg', 939, 440),
            UploadedFile::fake()->image('wrong-ratio.jpg', 940, 500),
            UploadedFile::fake()->createWithContent('corrupt.webp', substr(file_get_contents($this->payload()['image']->getRealPath()), 0, 40)),
        ];
        $pngFile = UploadedFile::fake()->image('dimensions.png', 1, 1);
        $png = file_get_contents($pngFile->getRealPath());
        $files[] = UploadedFile::fake()->createWithContent('huge.png', substr_replace($png, pack('NN', 5000, 5000), 16, 8));
        foreach ($files as $file) {
            $this->actingAs($this->admin)->postJson('/admin/advertisements', $this->payload(['image' => $file]))->assertUnprocessable()->assertJsonValidationErrors('image');
        }
        $this->assertDatabaseCount('advertisement_assets', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('advertisements'));
    }

    public function test_five_positions_are_bounded_and_foreign_publications_cannot_be_paused(): void
    {
        $ads = [];
        foreach (['main_1', 'main_2', 'main_3', 'side_1', 'side_2'] as $slot) {
            $ad = $this->draft(['slot' => $slot]);
            $ads[] = [$ad, $this->publish($ad)];
        }
        $banners = app(AdvertisementReader::class)->homepage();
        $this->assertCount(5, $banners);
        $this->assertCount(3, array_filter($banners, fn ($banner) => $banner['placement'] === 'main'));
        $this->postJson('/admin/advertisements/'.$ads[0][0]->id.'/actions', $this->action($ads[0][0], 'pause', ['publication_id' => $ads[1][1]->id]))->assertUnprocessable();
        $sixth = $this->draft();
        $this->postJson('/admin/advertisements/'.$sixth->id.'/actions', $this->action($sixth))->assertUnprocessable()->assertJsonValidationErrors('advertisement');
        $this->assertCount(5, app(AdvertisementReader::class)->homepage());
    }

    public function test_shop_and_category_clicks_use_their_existing_destinations_and_resume_rechecks_eligibility(): void
    {
        foreach (['shop' => $this->product->shop_id, 'category' => $this->product->category_id] as $type => $id) {
            $ad = $this->draft(['destination_type' => $type, 'destination_id' => $id, 'slot' => $type === 'shop' ? 'side_1' : 'side_2']);
            $publication = $this->publish($ad);
            $target = $type === 'shop' ? route('shop.show', $this->product->shop->slug) : route('buyer.search', ['category' => $this->product->category->slug]);
            $this->get('/advertisement/'.$ad->slug)->assertRedirect($target);
            $this->post('/admin/advertisements/'.$ad->id.'/actions', $this->action($ad, 'pause', ['publication_id' => $publication->id]))->assertSessionHas('success');
            $this->product->update(['stock' => 0]);
            $this->postJson('/admin/advertisements/'.$ad->id.'/actions', $this->action($ad, 'resume', ['publication_id' => $publication->id]))->assertUnprocessable();
            $this->product->update(['stock' => 5]);
            $this->post('/admin/advertisements/'.$ad->id.'/actions', $this->action($ad, 'resume', ['publication_id' => $publication->id]))->assertSessionHas('success');
        }
        $this->assertCount(2, app(AdvertisementReader::class)->homepage());
    }
}

<?php

namespace Tests\Feature\Advertisements;

use App\Models\Advertisement;
use App\Models\AdvertisementPublication;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\Advertisements\AdvertisementAdminService;
use App\Services\Advertisements\AdvertisementManager;
use App\Services\Advertisements\AdvertisementMeasurementService;
use App\Services\Advertisements\AdvertisementReader;
use App\Services\Advertisements\SellerPromotionService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdvertisementMeasurementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Shop $shop;

    private Product $product;

    private AdvertisementMeasurementService $measurement;

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
        $this->measurement = app(AdvertisementMeasurementService::class);
    }

    private function data(array $changes = []): array
    {
        return array_replace(['title' => 'Everyday Bagoo essentials', 'alt_text' => 'A selection of everyday essentials', 'purpose' => 'feature',
            'destination_type' => 'product', 'destination_id' => $this->product->id, 'product_ids' => [], 'offer_revision_id' => null,
            'slot' => 'main_1', 'starts_at' => '2026-10-15T10:00', 'ends_at' => '2026-10-25T10:00',
            'image' => UploadedFile::fake()->image('essentials.png', 940, 440), 'source_version' => 0, 'request_key' => (string) Str::uuid()], $changes);
    }

    private function command(Advertisement $ad, array $data): array
    {
        return ['source_version' => $ad->fresh()->version, 'request_key' => (string) Str::uuid(), ...$data];
    }

    private function published(array $changes = []): AdvertisementPublication
    {
        $manager = app(AdvertisementManager::class);
        $ad = $manager->saveDraft($this->admin, $this->data($changes));
        $manager->act($this->admin, $ad, $this->command($ad, ['action' => 'publish', 'publish_now' => true, 'claims_reviewed' => true]));

        return $ad->publications()->sole();
    }

    private function token(AdvertisementPublication $publication): string
    {
        return collect(app(AdvertisementReader::class)->homepage())->firstWhere('id', $publication->id)['measurement']['token'];
    }

    private function event(string $token, string $type = 'impression'): void
    {
        $this->postJson('/advertisement-events', ['token' => $token, 'type' => $type])->assertNoContent();
    }

    private function sellerPublication(Shop $shop, string $slot): AdvertisementPublication
    {
        $product = Product::factory()->create(['shop_id' => $shop->id, 'category_id' => $shop->root_category_id]);
        $data = $this->data(['destination_id' => $product->id]);
        unset($data['slot']);
        $service = app(SellerPromotionService::class);
        $ad = $service->saveDraft($shop->user, $data);
        $service->act($shop->user, $ad, $this->command($ad, ['action' => 'submit', 'revision_id' => $ad->latestRevision->id]));
        $submission = $ad->submissions()->sole();
        $service->decide($this->admin, $ad, $this->command($ad, ['decision' => 'approved', 'submission_id' => $submission->id,
            'revision_id' => $submission->revision_id, 'claims_reviewed' => true]));
        app(AdvertisementManager::class)->act($this->admin, $ad, $this->command($ad, ['action' => 'publish', 'submission_id' => $submission->id,
            'slot' => $slot, 'publish_now' => true, 'claims_reviewed' => true]));

        return $ad->publications()->sole();
    }

    public function test_homepage_issues_short_lived_tokens_without_counting_unrendered_images_or_previews(): void
    {
        $publication = $this->published();
        $first = $this->token($publication);
        $second = $this->token($publication);
        $this->assertNotSame($first, $second);
        $payload = json_decode(Crypt::decryptString($first), true);
        $this->assertSame(['publication', 'revision', 'page', 'issued'], array_keys($payload));
        $this->assertSame($publication->id, $payload['publication']);
        $this->assertSame(32, strlen($payload['page']));
        $preview = app(AdvertisementAdminService::class)->preview($this->admin, $publication->advertisement, null);
        $this->assertArrayNotHasKey('measurement', $preview['banners']->first());
        $this->get('/advertisement/'.$publication->advertisement->slug)->assertRedirect();
        $this->get('/')->assertOk();
        $this->assertDatabaseCount('advertisement_events', 0);
        $this->assertDatabaseCount('advertisement_daily_statistics', 0);
    }

    public function test_impressions_require_one_second_and_each_type_is_deduplicated_per_page_and_revision(): void
    {
        $publication = $this->published();
        $token = $this->measurement->issue($publication, str_repeat('a', 32))['token'];
        $this->event($token);
        $this->assertDatabaseCount('advertisement_events', 0);
        $this->travel(1)->seconds();
        $this->event($token);
        $this->event($token);
        $otherToken = $this->measurement->issue($publication, str_repeat('a', 32))['token'];
        $this->travel(1)->seconds();
        $this->event($otherToken);
        $this->event($otherToken, 'click');
        $this->event($token, 'click');
        $this->assertDatabaseCount('advertisement_events', 2);
        $this->assertDatabaseHas('advertisement_daily_statistics', ['publication_id' => $publication->id, 'day' => '2026-10-15', 'impressions' => 1, 'clicks' => 1]);
        $this->event($this->token($publication), 'click');
        $this->assertDatabaseHas('advertisement_daily_statistics', ['impressions' => 1, 'clicks' => 2]);
    }

    public function test_tampered_expired_future_and_mismatched_tokens_do_not_reveal_or_change_records(): void
    {
        $publication = $this->published();
        $token = $this->token($publication);
        $payload = json_decode(Crypt::decryptString($token), true);
        $this->event(substr($token, 0, -4).'xxxx', 'click');
        foreach ([['publication' => 999999], ['revision' => $publication->revision_id + 1], ['issued' => now()->getTimestampMs() + 1000], ['page' => 'invalid'], ['issued' => []]] as $change) {
            $this->event(Crypt::encryptString(json_encode(array_replace($payload, $change))), 'click');
        }
        $this->travel(30)->minutes();
        $this->event($token, 'click');
        $this->assertDatabaseCount('advertisement_events', 0);
    }

    public function test_paused_ended_restricted_and_disabled_placements_reject_old_event_tokens(): void
    {
        $publication = $this->published();
        $token = $this->token($publication);
        $ad = $publication->advertisement;
        app(AdvertisementManager::class)->act($this->admin, $ad, $this->command($ad, ['action' => 'pause', 'publication_id' => $publication->id]));
        $this->event($token, 'click');
        app(AdvertisementManager::class)->act($this->admin, $ad, $this->command($ad, ['action' => 'resume', 'publication_id' => $publication->id]));
        $this->product->forceFill(['compliance_restricted' => true])->save();
        $this->event($token, 'click');
        $this->product->forceFill(['compliance_restricted' => false])->save();
        config(['advertisements.measurement_enabled' => false]);
        $this->event($token, 'click');
        $this->assertArrayNotHasKey('measurement', app(AdvertisementReader::class)->homepage()[0]);
        config(['advertisements.measurement_enabled' => true, 'advertisements.enabled' => false]);
        $this->event($token, 'click');
        config(['advertisements.enabled' => true]);
        app(AdvertisementManager::class)->act($this->admin, $ad, $this->command($ad, ['action' => 'end', 'publication_id' => $publication->id]));
        $this->event($token, 'click');
        $this->assertDatabaseCount('advertisement_events', 0);
    }

    public static function invalidEvents(): array
    {
        return [
            'missing token' => [['type' => 'click']], 'array token' => [['token' => [], 'type' => 'click']],
            'large token' => [['token' => str_repeat('a', 2049), 'type' => 'click']],
            'unknown type' => [['token' => 'invalid', 'type' => 'purchase']],
            'array type' => [['token' => 'invalid', 'type' => ['click']]],
            'identity field' => [['token' => 'invalid', 'type' => 'click', 'email' => 'person@bagoo.test']],
            'forged total' => [['token' => 'invalid', 'type' => 'click', 'clicks' => 999]],
        ];
    }

    #[DataProvider('invalidEvents')]
    public function test_event_validation_rejects_unsupported_payloads(array $input): void
    {
        $this->postJson('/advertisement-events', $input)->assertUnprocessable();
        $this->assertDatabaseCount('advertisement_events', 0);
    }

    public function test_event_endpoint_limits_body_format_size_and_request_rate(): void
    {
        $this->post('/advertisement-events', ['token' => 'invalid', 'type' => 'click'])->assertStatus(415);
        $this->postJson('/advertisement-events', ['token' => str_repeat('x', 5000), 'type' => 'click'])->assertStatus(413);
        for ($i = 0; $i < 58; $i++) {
            $this->event('invalid', 'click');
        }
        $this->postJson('/advertisement-events', ['token' => 'invalid', 'type' => 'click'])->assertTooManyRequests();
        $this->assertDatabaseCount('advertisement_events', 0);
    }

    public function test_public_events_keep_web_csrf_protection(): void
    {
        $this->app->instance('env', 'production');
        $this->postJson('/advertisement-events', ['token' => 'invalid', 'type' => 'click'])->assertStatus(419);
    }

    public function test_exact_event_boundaries_accept_the_limit_and_reject_one_byte_or_character_more(): void
    {
        $this->postJson('/advertisement-events', ['token' => str_repeat('a', 2048), 'type' => 'click'])->assertNoContent();
        $this->postJson('/advertisement-events', ['token' => str_repeat('a', 2049), 'type' => 'click'])->assertUnprocessable();
        $body = str_pad(json_encode(['token' => 'invalid', 'type' => 'click']), 4096, ' ');
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        $this->call('POST', '/advertisement-events', [], [], [], $headers, $body)->assertNoContent();
        $this->call('POST', '/advertisement-events', [], [], [], $headers, $body.' ')->assertStatus(413);
        $this->assertDatabaseCount('advertisement_events', 0);
    }

    public function test_counter_failure_rolls_back_the_dedupe_record_without_breaking_buyer_navigation(): void
    {
        $publication = $this->published();
        $token = $this->token($publication);
        DB::unprepared("CREATE TRIGGER fail_advertisement_totals BEFORE INSERT ON advertisement_daily_statistics BEGIN SELECT RAISE(ABORT, 'Storage unavailable'); END");
        $this->eventStorageFailure($token);
        $this->assertDatabaseCount('advertisement_events', 0);
        $this->get('/advertisement/'.$publication->advertisement->slug)->assertRedirect(route('products.show', $this->product->slug));
        DB::unprepared('DROP TRIGGER fail_advertisement_totals');
        $this->event($token, 'click');
        $this->assertDatabaseCount('advertisement_events', 1);
        $this->assertDatabaseHas('advertisement_daily_statistics', ['clicks' => 1]);
    }

    private function eventStorageFailure(string $token): void
    {
        Log::spy();
        $response = $this->postJson('/advertisement-events', ['token' => $token, 'type' => 'click'])->assertStatus(503);
        $this->assertTrue(Str::isUuid($response->json('request_id')));
        $this->assertSame($response->json('request_id'), $response->headers->get('X-Request-ID'));
        $response->assertDontSee($token, false)->assertDontSee('Storage unavailable');
        Log::shouldHaveReceived('warning')->once()->with('Advertisement reporting is unavailable.', \Mockery::on(fn ($context) => $context['request_id'] === $response->json('request_id') && $context['exception_type'] === QueryException::class
            && array_keys($context) === ['request_id', 'exception_type', 'code']));
    }

    public function test_retention_removes_old_anonymous_records_without_losing_daily_totals(): void
    {
        $publication = $this->published();
        $token = $this->token($publication);
        $this->travel(1)->seconds();
        $this->event($token);
        $this->event($token, 'click');
        $this->assertEqualsCanonicalizing(['id', 'publication_id', 'event_type', 'dedupe_hash', 'recorded_at'], Schema::getColumnListing('advertisement_events'));
        $this->assertSame(64, strlen(DB::table('advertisement_events')->value('dedupe_hash')));
        $this->travel(7)->days();
        $this->assertSame(0, $this->measurement->prune());
        $this->travel(1)->seconds();
        $this->artisan('advertisements:prune-events')->assertSuccessful();
        $scheduled = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command ?? '', 'advertisements:prune-events'));
        $this->assertSame('0 0 * * *', $scheduled?->expression);
        $this->assertDatabaseCount('advertisement_events', 0);
        $this->assertDatabaseHas('advertisement_daily_statistics', ['day' => '2026-10-15', 'impressions' => 1, 'clicks' => 1]);
        $this->event($token, 'click');
        $this->assertSame(0, $this->measurement->prune());
        $this->assertDatabaseHas('advertisement_daily_statistics', ['clicks' => 1]);
        $migration = require database_path('migrations/2026_10_15_223000_add_advertisement_measurement.php');
        try {
            $migration->down();
            $this->fail('Retained performance must not be removed by rollback.');
        } catch (\LogicException $error) {
            $this->assertStringContainsString('Retain recorded advertisement totals', $error->getMessage());
        }
        $this->assertDatabaseHas('advertisement_daily_statistics', ['impressions' => 1, 'clicks' => 1]);
    }

    public function test_reporting_uses_manila_dates_actual_counts_and_no_rate_without_views(): void
    {
        $publication = $this->published();
        $this->travelTo(CarbonImmutable::parse('2026-10-15T16:00:00Z'));
        $token = $this->token($publication);
        $this->event($token, 'click');
        $this->actingAs($this->admin)->get('/admin/advertisements/performance?from=2026-10-16&to=2026-10-16')
            ->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertInertia(fn (Assert $page) => $page->component('Shared/AdvertisementPerformance')->where('totals.impressions', 0)->where('totals.clicks', 1)->where('totals.click_through_rate', null)->has('placements.data', 1));
        $this->travel(1)->seconds();
        $this->event($token);
        $this->get('/admin/advertisements/performance?from=2026-10-16&to=2026-10-16&publication_id='.$publication->id)
            ->assertInertia(fn (Assert $page) => $page->where('totals.click_through_rate', 100)->where('placements.data.0.revision_id', $publication->revision_id)->where('placements.data.0.impressions', 1));
        $this->get('/admin/advertisements/performance?from=2026-10-15&to=2026-10-15')
            ->assertInertia(fn (Assert $page) => $page->where('totals.clicks', 0)->where('totals.impressions', 0)->where('totals.click_through_rate', null));
    }

    public function test_seller_reports_scope_totals_choices_and_all_filter_ids_to_the_original_owner(): void
    {
        $owned = $this->sellerPublication($this->shop, 'side_1');
        $foreign = $this->sellerPublication(Shop::factory()->approved()->create(), 'side_2');
        $platform = $this->published();
        foreach ([$owned, $foreign, $platform] as $publication) {
            $this->event($this->token($publication), 'click');
        }
        $this->actingAs($this->shop->user)->get('/seller/promotions/performance')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('seller', true)->where('totals.clicks', 1)->has('placements.data', 1)
                ->where('placements.data.0.advertisement_id', $owned->advertisement_id)->has('options.advertisements', 1)->has('options.revisions', 1)->has('options.placements', 1));
        foreach (['advertisement_id' => $foreign->advertisement_id, 'revision_id' => $foreign->revision_id, 'publication_id' => $foreign->id] as $field => $id) {
            $this->getJson('/seller/promotions/performance?'.$field.'='.$id)->assertNotFound();
        }
        $this->get('http://seller.localhost/promotions/performance')->assertOk()->assertInertia(fn (Assert $page) => $page->where('baseUrl', '/promotions')->where('totals.clicks', 1));
        $this->actingAs($this->admin)->get('/admin/advertisements/performance')->assertInertia(fn (Assert $page) => $page->where('totals.clicks', 3)->has('placements.data', 3));
        $this->getJson('/admin/advertisements/performance?advertisement_id='.$owned->advertisement_id.'&revision_id='.$foreign->revision_id)->assertNotFound();
        $this->get('http://admin.localhost/advertisements/performance')->assertOk()->assertInertia(fn (Assert $page) => $page->where('baseUrl', '/advertisements'));
    }

    public function test_reports_recheck_role_verification_and_current_account_activity(): void
    {
        foreach (['buyer', 'courier', 'logistics'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->getJson('/admin/advertisements/performance')->assertForbidden();
            $this->getJson('/seller/promotions/performance')->assertForbidden();
        }
        foreach ([['status' => 'suspended'], ['email_verified_at' => null]] as $change) {
            $this->admin->forceFill($change)->save();
            $this->actingAs($this->admin->fresh())->getJson('/admin/advertisements/performance')->assertForbidden();
            $this->admin->forceFill(['status' => 'active', 'email_verified_at' => now()])->save();
        }
    }

    public function test_report_filter_boundaries_and_missing_measurement_are_distinct_from_zero(): void
    {
        $this->published();
        $this->actingAs($this->admin);
        foreach (['from=2026-02-30', 'from=2026-10-16', 'from=2025-01-01', 'from=2026-10-15&to=2026-10-14', 'from=2026-01-01&to=2026-10-15', 'publication_id[]=1', 'revision_id=1.5', 'page=0'] as $query) {
            $this->getJson('/admin/advertisements/performance?'.$query)->assertUnprocessable();
        }
        $this->get('/admin/advertisements/performance?from=2026-07-18&to=2026-10-15')->assertOk();
        $this->getJson('/admin/advertisements/performance?from=2026-07-17&to=2026-10-15')->assertUnprocessable();
        $this->get('/admin/advertisements/performance?from=2025-10-15&to=2025-10-15')->assertOk();
        config(['advertisements.measurement_enabled' => false]);
        $this->get('/admin/advertisements/performance')->assertInertia(fn (Assert $page) => $page->where('available', true)->where('collecting', false)->where('totals.impressions', 0));
        Schema::drop('advertisement_daily_statistics');
        $this->get('/admin/advertisements/performance')->assertInertia(fn (Assert $page) => $page->where('available', false)->where('totals', null));
        $this->assertCount(1, app(AdvertisementReader::class)->homepage());
        $this->assertArrayNotHasKey('measurement', app(AdvertisementReader::class)->homepage()[0]);
        $this->get('/')->assertOk();
    }

    public function test_report_pages_retain_period_filters_and_exact_revision_totals_after_replacement(): void
    {
        $first = $this->published();
        $ad = $first->advertisement;
        $this->event($this->token($first), 'click');
        $manager = app(AdvertisementManager::class);
        for ($i = 0; $i < 25; $i++) {
            $this->travel(1)->seconds();
            $data = [...$this->data(['title' => 'Replacement '.($i + 1)]), ...$this->command($ad, [])];
            unset($data['image']);
            $manager->saveDraft($this->admin, $data, $ad);
            $manager->act($this->admin, $ad, $this->command($ad, ['action' => 'publish', 'publish_now' => true, 'claims_reviewed' => true]));
        }
        $last = $ad->publications()->latest('id')->firstOrFail();
        $this->event($this->token($last), 'click');
        $query = 'from=2026-10-15&to=2026-10-15&advertisement_id='.$ad->id;
        $this->actingAs($this->admin)->get('/admin/advertisements/performance?'.$query)->assertInertia(fn (Assert $page) => $page
            ->where('totals.clicks', 2)->has('placements.data', 25)->where('placements.total', 26)
            ->where('placements.next_page_url', fn ($url) => str_contains($url, 'from=2026-10-15') && str_contains($url, 'advertisement_id='.$ad->id) && str_contains($url, 'page=2')));
        $this->get('/admin/advertisements/performance?'.$query.'&page=2')->assertInertia(fn (Assert $page) => $page
            ->where('totals.clicks', 2)->has('placements.data', 1)->where('placements.data.0.id', $first->id)->where('placements.data.0.clicks', 1));
        $this->get('/admin/advertisements/performance?'.$query.'&revision_id='.$last->revision_id)->assertInertia(fn (Assert $page) => $page->where('totals.clicks', 1)->has('placements.data', 1)->where('placements.data.0.id', $last->id));
    }

    public function test_a_report_storage_failure_has_a_safe_reference_and_does_not_fabricate_zero(): void
    {
        $this->published();
        Schema::table('advertisement_daily_statistics', fn ($table) => $table->renameColumn('clicks', 'unavailable_clicks'));
        Log::spy();
        $this->actingAs($this->admin)->get('/admin/advertisements/performance')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('available', false)->where('totals', null)
                ->where('failureReference', fn ($value) => Str::isUuid($value)));
        Log::shouldHaveReceived('warning')->once();
    }
}

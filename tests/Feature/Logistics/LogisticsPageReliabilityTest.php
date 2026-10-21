<?php

namespace Tests\Feature\Logistics;

use App\Models\HubHandler;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class LogisticsPageReliabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['app.debug' => true]);
        $this->get('/')->assertOk();
        $this->withHeader('X-Inertia-Version', Inertia::getVersion() ?? '');
    }

    public static function portals(): array
    {
        return [['http://localhost/hub'], ['http://hub.localhost']];
    }

    public static function operators(): array
    {
        $cases = [];
        foreach (self::portals() as [$base]) {
            foreach (['company', 'bayan', 'mother', 'unassigned'] as $kind) {
                $cases[$base.' '.$kind] = [$base, $kind];
            }
        }

        return $cases;
    }

    private function operator(string $kind): array
    {
        $owner = User::factory()->create(['role' => 'logistics', 'status' => 'active', 'kyc_status' => 'approved']);
        $company = LogisticsCompany::create(['user_id' => $owner->id, 'name' => 'Bagoo Page Checks '.$owner->id,
            'slug' => 'page-checks-'.$owner->id, 'code' => 'PAGE-'.$owner->id, 'status' => 'active', 'is_active' => true]);
        $hub = LogisticsHub::create(['logistics_company_id' => $company->id, 'name' => 'Bagoo Page Check Hub '.$owner->id,
            'code' => 'PAGE-HUB-'.$owner->id, 'tier' => $kind === 'mother' ? 'regional_mother_hub' : 'local_bayan_hub',
            'province' => 'Laguna', 'city_municipality' => 'Santa Cruz', 'address' => 'Page Check Road',
            'is_active' => true, 'allows_self_pickup' => $kind !== 'mother']);
        if ($kind === 'company') {
            return [$owner, $hub];
        }
        $handler = User::factory()->create(['role' => 'logistics', 'status' => 'active', 'kyc_status' => 'approved']);
        if ($kind !== 'unassigned') {
            HubHandler::create(['user_id' => $handler->id, 'hub_id' => $hub->id, 'is_active' => true]);
        }

        return [$handler, $hub];
    }

    #[DataProvider('operators')]
    public function test_authorized_pages_load_without_framework_errors_and_have_real_components(string $base, string $kind): void
    {
        [$actor] = $this->operator($kind);
        $this->actingAs($actor);
        $pages = ['dashboard', 'network', 'fleet', 'manifests', 'deliveries', 'delivery-recovery', 'parcel-recovery', 'counter', 'cod', 'roadmap'];
        if (in_array($kind, ['bayan', 'mother'], true)) {
            $pages[] = 'scan';
        }
        if ($kind === 'company') {
            $pages[] = 'resources';
        }
        foreach ($pages as $path) {
            $response = $this->get($base.'/'.$path)->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('auth.user.role', 'logistics')->where('auth.user.id', $actor->id));
            $this->assertFileExists(resource_path('js/Pages/'.$response->viewData('page')['component'].'.tsx'));
        }
        $origin = str_contains($base, 'hub.localhost') ? 'http://hub.localhost' : 'http://localhost';
        $common = ['/buyer-returns', '/handover-issues', '/notifications', '/profile', '/account/security'];
        if ($kind === 'company') {
            $common = [...$common, '/governance-history', '/exceptions', '/financial-oversight'];
        }
        foreach ($common as $path) {
            $this->get($origin.$path)->assertOk()->assertInertia(fn (Assert $page) => $page->where('auth.user.id', $actor->id));
        }
    }

    #[DataProvider('operators')]
    public function test_scan_navigation_matches_current_handler_permission(string $base, string $kind): void
    {
        [$actor] = $this->operator($kind);
        $allowed = in_array($kind, ['bayan', 'mother'], true);
        $this->actingAs($actor)->get($base.'/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.canScanParcels', $allowed)
            ->where('scope.can_scan', $allowed)
            ->where('attentionQueue.3.href', fn ($url) => str_contains($url, $allowed ? '/scan' : '/deliveries')));
        $response = $this->get($base.'/scan');
        if ($allowed) {
            $response->assertOk();
        } else {
            $response->assertForbidden()->assertSee('BagooPH')->assertDontSee('vendor/laravel');
        }
    }

    #[DataProvider('portals')]
    public function test_missing_pages_and_records_keep_404_without_showing_framework_details(string $base): void
    {
        [$actor] = $this->operator('company');
        $this->actingAs($actor);
        foreach (['missing-page', 'manifests/999999', 'parcel-recovery/999999', 'cod/999999'] as $path) {
            $this->get($base.'/'.$path)->assertNotFound()->assertSee('BagooPH')->assertSee('Page unavailable')
                ->assertDontSee('vendor/laravel')->assertDontSee('Stack trace')->assertDontSee('No query results');
            $this->get($base.'/'.$path, ['X-Inertia' => 'true', 'X-Inertia-Version' => Inertia::getVersion() ?? ''])
                ->assertNotFound()->assertHeader('X-Inertia', 'true')->assertJsonPath('component', 'Hub/Error')
                ->assertJsonPath('props.status', 404)->assertJsonMissingPath('props.auth');
        }
    }

    #[DataProvider('portals')]
    public function test_forbidden_and_wrong_facility_links_remain_denied_with_safe_recovery(string $base): void
    {
        [$actor, $hub] = $this->operator('bayan');
        [, $foreign] = $this->operator('company');
        $this->actingAs($actor);
        foreach (['resources', 'deliveries?hub_id='.$foreign->id] as $path) {
            $this->get($base.'/'.$path)->assertForbidden()->assertSee('BagooPH')->assertSee('Access unavailable')
                ->assertDontSee($foreign->name)->assertDontSee('vendor/laravel');
        }
        $this->get($base.'/deliveries?hub_id='.$hub->id)->assertOk();
        $this->assertDatabaseCount('delivery_checkpoints', 0);
        $this->assertDatabaseCount('cod_custody_entries', 0);
    }

    public function test_unexpected_failures_are_safe_for_html_inertia_and_json_with_a_log_reference(): void
    {
        [$actor] = $this->operator('company');
        $this->actingAs($actor);
        Log::spy();
        Route::middleware('web')->get('/hub/page-failure-check', fn () => throw new RuntimeException('PRIVATE_FAILURE_DETAIL'));
        $this->get('/hub/page-failure-check')->assertStatus(500)->assertSee('BagooPH')->assertSee('Could not open this page')
            ->assertDontSee('PRIVATE_FAILURE_DETAIL')->assertHeader('Cache-Control', 'no-store, private');
        $response = $this->get('/hub/page-failure-check', ['X-Inertia' => 'true', 'X-Inertia-Version' => Inertia::getVersion() ?? ''])
            ->assertStatus(500)->assertHeader('X-Inertia', 'true')->assertJsonPath('component', 'Hub/Error')
            ->assertJsonPath('props.retryUrl', '/hub/page-failure-check')->assertDontSee('PRIVATE_FAILURE_DETAIL');
        $reference = $response->json('props.requestId');
        $this->assertNotEmpty($reference);
        $response->assertHeader('X-Request-ID', $reference);
        Log::shouldHaveReceived('error')->with('Logistics request failed.', \Mockery::on(fn ($context) => $context['request_id'] === $reference))->once();
        $this->getJson('/hub/page-failure-check')->assertStatus(500)->assertJsonStructure(['message', 'request_id'])
            ->assertJsonMissingPath('exception')->assertJsonMissingPath('trace')->assertDontSee('PRIVATE_FAILURE_DETAIL');
    }

    public function test_failure_recovery_does_not_resolve_broken_shared_database_props_or_repeat_updates(): void
    {
        [$actor] = $this->operator('company');
        $this->actingAs($actor);
        Inertia::share('brokenSharedData', fn () => DB::select('select * from page_check_missing_table'));
        Route::middleware('web')->get('/hub/database-failure-check', fn () => DB::select('select * from page_check_missing_table'));
        $this->get('/hub/database-failure-check', ['X-Inertia' => 'true', 'X-Inertia-Version' => Inertia::getVersion() ?? ''])
            ->assertStatus(500)->assertJsonPath('component', 'Hub/Error')->assertJsonMissingPath('props.brokenSharedData')
            ->assertDontSee('SQLSTATE')->assertDontSee('page_check_missing_table');
        $writes = 0;
        Route::middleware('web')->post('/hub/update-failure-check', function () use (&$writes) {
            $writes++;
            throw new RuntimeException('PRIVATE_UPDATE_DETAIL');
        });
        $this->post('/hub/update-failure-check', [], ['X-Inertia' => 'true', 'X-Inertia-Version' => Inertia::getVersion() ?? ''])
            ->assertStatus(500)->assertJsonPath('props.retryUrl', null)->assertDontSee('PRIVATE_UPDATE_DETAIL');
        $this->assertSame(1, $writes);
        $this->assertDatabaseCount('delivery_checkpoints', 0);
        $this->assertDatabaseCount('cod_custody_entries', 0);
    }

    public function test_expiry_throttling_and_wrong_methods_have_safe_responses_without_losing_headers(): void
    {
        [$actor] = $this->operator('company');
        $this->actingAs($actor);
        Route::middleware('web')->post('/hub/expired-form-check', fn () => abort(419));
        Route::middleware('web')->get('/hub/throttle-check', fn () => abort(429, 'PRIVATE_THROTTLE_DETAIL', ['Retry-After' => '30']));
        $this->post('/hub/expired-form-check', [], ['X-Inertia' => 'true', 'X-Inertia-Version' => Inertia::getVersion() ?? ''])
            ->assertStatus(419)->assertJsonPath('component', 'Hub/Error')->assertJsonPath('props.retryUrl', null)
            ->assertJsonPath('props.loginUrl', '/hub/login');
        $this->get('/hub/throttle-check')->assertStatus(429)->assertHeader('Retry-After', '30')
            ->assertSee('Please wait a moment')->assertDontSee('PRIVATE_THROTTLE_DETAIL');
        $this->post('/hub/dashboard')->assertStatus(405)->assertHeader('Allow')->assertSee('BagooPH');
    }

    public function test_validation_and_native_api_responses_keep_their_existing_contracts(): void
    {
        [$actor] = $this->operator('bayan');
        $this->actingAs($actor)->postJson('/hub/scan', [])->assertUnprocessable()->assertJsonValidationErrors('barcode');
        $this->postJson('/api/v1/rider/home')->assertStatus(405)->assertJsonMissingPath('component');
    }

    #[DataProvider('portals')]
    public function test_stale_facility_recovery_explicitly_opens_an_owned_current_facility(string $base): void
    {
        [$actor, $hub] = $this->operator('bayan');
        $this->actingAs($actor)->withSession(['active_hub_id' => 999999]);
        $response = $this->get($base.'/deliveries', ['X-Inertia' => 'true'])->assertForbidden();
        $target = $response->json('props.overviewUrl');
        $this->assertSame((str_contains($base, 'hub.localhost') ? '' : '/hub').'/dashboard?hub_id='.$hub->id, $target);
        $origin = str_contains($base, 'hub.localhost') ? 'http://hub.localhost' : 'http://localhost';
        $this->get($origin.$target)->assertOk()->assertInertia(fn (Assert $page) => $page->where('scope.selected_hub_id', $hub->id));
        $this->assertSame($hub->id, session('active_hub_id'));
        $this->assertDatabaseCount('delivery_checkpoints', 0);
    }

    public function test_asset_version_recovery_keeps_the_existing_full_visit_contract(): void
    {
        [$actor] = $this->operator('company');
        $this->actingAs($actor);
        Route::middleware('web')->get('/hub/version-failure-check', fn () => throw new RuntimeException('PRIVATE_VERSION_DETAIL'));
        $this->get('/hub/version-failure-check', ['X-Inertia' => 'true', 'X-Inertia-Version' => 'older-assets'])
            ->assertStatus(409)->assertHeader('X-Inertia-Location')->assertDontSee('PRIVATE_VERSION_DETAIL');
        $this->get('/hub/version-failure-check')->assertStatus(500)->assertSee('BagooPH')->assertDontSee('PRIVATE_VERSION_DETAIL');
    }
}

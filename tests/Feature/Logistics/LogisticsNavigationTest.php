<?php

namespace Tests\Feature\Logistics;

use App\Models\DeliveryCheckpoint;
use App\Models\LogisticsHub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class LogisticsNavigationTest extends TestCase
{
    use CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->get('/')->assertOk();
        $this->withHeader('X-Inertia-Version', Inertia::getVersion() ?? '');
    }

    public static function portals(): array
    {
        return [['/hub'], ['http://hub.localhost']];
    }

    #[DataProvider('portals')]
    public function test_switch_replaces_the_previous_explicit_hub_and_keeps_filters_with_fresh_coherent_results(string $base): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'picked_up');
        $origin = LogisticsHub::findOrFail($parcel->origin_bayan_hub_id);
        $destination = LogisticsHub::findOrFail($parcel->destination_bayan_hub_id);
        $this->actingAs($origin->company->user);
        $this->get($base.'/deliveries?hub_id='.$origin->id.'&status=in_hub&search=BGO&page=8')->assertOk();
        $switch = $this->post($base.'/switch-hub', ['hub_id' => $destination->id])->assertRedirect();
        $target = $switch->headers->get('Location');
        parse_str(parse_url($target, PHP_URL_QUERY), $query);
        $this->assertSame((string) $destination->id, $query['hub_id']);
        $this->assertSame('in_hub', $query['status']);
        $this->assertSame('BGO', $query['search']);
        $this->assertArrayNotHasKey('page', $query);
        $response = $this->get($target, ['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'Hub/Deliveries',
            'X-Inertia-Partial-Data' => 'auth,activeHub,deliveries,counts,filters,eligibleRiders,canScan'])->assertOk();
        $this->assertSame($destination->id, $response->json('props.activeHub.id'));
        $this->assertSame($destination->id, $response->json('props.auth.user.activeHub.id'));
        $this->assertSame(0, $response->json('props.deliveries.total'));
        $this->assertSame(0, $response->json('props.counts.all'));
        $this->assertSame('BGO', $response->json('props.filters.search'));
        $this->assertFalse($response->json('props.canScan'));
        $this->assertSame($destination->id, session('active_hub_id'));
        $this->actingAs($this->flowHandler($origin))->post($base.'/switch-hub', ['hub_id' => $destination->id])->assertForbidden();
    }

    #[DataProvider('portals')]
    public function test_counts_only_reads_skip_parcel_rows_and_private_preparation_cannot_change_facility_or_tokens(string $base): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'picked_up');
        $origin = LogisticsHub::findOrFail($parcel->origin_bayan_hub_id);
        $this->actingAs($origin->company->user)->withSession(['active_hub_id' => $origin->id]);
        DB::enableQueryLog();
        $response = $this->get($base.'/deliveries', ['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'Hub/Deliveries',
            'X-Inertia-Partial-Data' => 'auth,activeHub,counts'])->assertOk();
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();
        $this->assertFalse($queries->contains(fn ($sql) => preg_match('/^select (?:["`]deliveries["`]\.)?\* from ["`]deliveries["`]/i', $sql) === 1));
        $this->assertArrayNotHasKey('deliveries', $response->json('props'));
        $this->assertArrayNotHasKey('eligibleRiders', $response->json('props'));
        $before = DeliveryCheckpoint::count();
        session()->forget('manifest_creation_token');
        foreach (['dashboard', 'network', 'fleet', 'deliveries', 'scan', 'counter', 'manifests'] as $page) {
            $this->get($base.'/'.$page.'?hub_id='.$parcel->destination_bayan_hub_id, ['Purpose' => 'prefetch', 'X-Inertia' => 'true'])->assertStatus(409);
        }
        $this->assertSame($origin->id, session('active_hub_id'));
        $this->assertNull(session('manifest_creation_token'));
        $this->assertSame($before, DeliveryCheckpoint::count());
        $this->assertDatabaseCount('pickup_claims', 0);
    }

    #[DataProvider('portals')]
    public function test_page_boundaries_and_foreign_return_urls_cannot_override_the_selected_facility(string $base): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'picked_up');
        $origin = LogisticsHub::findOrFail($parcel->origin_bayan_hub_id);
        $this->actingAs($origin->company->user);
        foreach (['0', '-1', '1.5', '1000001', '01', '١', '1e2', ['1']] as $page) {
            $this->getJson($base.'/deliveries?'.http_build_query(['page' => $page]))->assertUnprocessable()->assertJsonValidationErrors('page');
        }
        $this->get($base.'/deliveries?page=1', ['X-Inertia' => 'true'])->assertOk()->assertJsonPath('props.deliveries.current_page', 1);
        $response = $this->from('https://foreign.test/hub/deliveries?secret=untrusted')->post($base.'/switch-hub', ['hub_id' => $origin->id])->assertRedirect();
        $path = parse_url($response->headers->get('Location'), PHP_URL_PATH);
        $this->assertSame($base === '/hub' ? '/hub/dashboard' : '/dashboard', $path);
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame(['hub_id' => (string) $origin->id], $query);
    }
}

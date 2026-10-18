<?php

namespace Tests\Performance;

use App\Models\LogisticsHub;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

/** Opt-in HTTP-kernel measurements. These do not measure a browser or deployment. */
class NavigationBenchmarkTest extends TestCase
{
    use CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    public function test_measure_representative_fresh_navigation_with_isolated_fixtures(): void
    {
        $output = getenv('NAVIGATION_BENCHMARK_OUTPUT');
        if (! $output) {
            $this->markTestSkipped('Set NAVIGATION_BENCHMARK_OUTPUT to a repository-local JSON path.');
        }
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertStringStartsWith(base_path().DIRECTORY_SEPARATOR, $output);
        $this->withoutVite();
        $this->travelTo(now()->setDate(2026, 10, 18)->setTime(12, 0));
        fake()->seed(405);
        $admin = User::factory()->admin()->create();
        $buyers = User::factory()->buyer()->count(24)->sequence(fn ($sequence) => [
            'name' => 'Bench Buyer '.$sequence->index, 'email' => 'bench-buyer-'.$sequence->index.'@bagoo.test',
        ])->create();
        $shop = Shop::factory()->approved()->create(['name' => 'Bagoo Benchmark Shop']);
        Product::factory()->count(36)->sequence(fn ($sequence) => [
            'shop_id' => $shop->id, 'name' => 'Benchmark item '.$sequence->index,
            'description' => str_repeat('A durable everyday item. ', 40),
            'featured_image' => '/images/product-placeholder.svg',
        ])->create();
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'picked_up');
        $hub = LogisticsHub::findOrFail($parcel->origin_bayan_hub_id);
        $this->get('/')->assertOk();
        $headers = ['X-Inertia' => 'true', 'X-Inertia-Version' => Inertia::getVersion() ?? ''];
        $cases = [
            ['catalogue', $buyers->first(), '/buyer/search?search=Benchmark&page=2', 'Buyer/Search', null, 'products'],
            ['buyer', $buyers->first(), '/buyer/profile?tab=orders', 'Buyer/Profile', null, 'orders'],
            ['seller', $shop->user, '/seller/products', 'Seller/Products', null, 'products'],
            ['rider', $parcel->courier, '/courier/deliveries', 'Courier/Deliveries', null, null],
            ['logistics', $hub->company->user, '/hub/deliveries?hub_id='.$hub->id, 'Hub/Deliveries', null, 'deliveries'],
            ['admin_users', $admin, '/admin/users?search=Bench&role=buyer&page=2', 'Admin/Users', null, 'users'],
            ['admin_users_context', $admin, '/admin/users?search=Bench&role=buyer&page=2', 'Admin/Users', 'auth,filters,activityBaseUrl,flash,errors', null],
            ['admin_products', $admin, '/admin/products?page=2', 'Admin/Products', null, 'products'],
        ];
        $results = [];
        foreach ($cases as [$name, $actor, $url, $component, $partial, $records]) {
            $samples = [];
            // One warm-up is excluded; each case then gets ten measured HTTP-kernel reads.
            for ($index = -1; $index < 10; $index++) {
                $this->actingAs($actor->fresh());
                $requestHeaders = $headers + ($partial ? [
                    'X-Inertia-Partial-Component' => $component, 'X-Inertia-Partial-Data' => $partial,
                ] : []);
                DB::flushQueryLog();
                DB::enableQueryLog();
                $start = hrtime(true);
                $response = $this->get($url, $requestHeaders);
                $milliseconds = (hrtime(true) - $start) / 1e6;
                $queries = DB::getQueryLog();
                DB::disableQueryLog();
                $response->assertOk()->assertJsonPath('component', $component);
                if ($index >= 0) {
                    $samples[] = ['endpoint_ms' => round($milliseconds, 3), 'queries' => count($queries),
                        'database_ms' => round(array_sum(array_column($queries, 'time')), 3),
                        'response_bytes' => strlen($response->getContent()),
                        'record_ids' => $records ? array_column($response->json('props.'.$records.'.data') ?? [], 'id') : []];
                }
            }
            $results[$name] = ['route' => $url, 'component' => $component, 'partial' => $partial,
                'samples' => $samples];
        }
        file_put_contents($output, json_encode(['schema' => 1, 'environment' => 'Laravel HTTP kernel; isolated SQLite :memory:; no network/browser',
            'fixture' => ['users' => User::count(), 'products' => Product::count(), 'shops' => Shop::count(), 'parcels' => 1,
                'seed' => 405, 'clock' => now()->toIso8601String()],
            'warmups_per_case' => 1, 'samples_per_case' => 10, 'cases' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }
}

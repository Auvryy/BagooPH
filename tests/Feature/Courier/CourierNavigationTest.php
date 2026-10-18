<?php

namespace Tests\Feature\Courier;

use App\Models\CodCashEvent;
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

class CourierNavigationTest extends TestCase
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
        return [['/courier'], ['http://courier.localhost']];
    }

    #[DataProvider('portals')]
    public function test_context_only_reads_skip_work_queues_and_actual_activation_reads_current_duty(string $base): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'assigned_pickup');
        $rider = $parcel->courier;
        $this->actingAs($rider);
        DB::enableQueryLog();
        $response = $this->get($base.'/deliveries', [
            'X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'Courier/Deliveries',
            'X-Inertia-Partial-Data' => 'auth,scope,isOnline',
        ])->assertOk();
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();
        $this->assertFalse($queries->contains(fn ($sql) => preg_match('/from ["`]deliveries["`]/i', $sql) === 1));
        $this->assertArrayNotHasKey('queues', $response->json('props'));
        $this->assertArrayNotHasKey('recoveryRequestToken', $response->json('props'));
        $rider->courierProfile->update(['is_available' => false]);
        $response = $this->get($base.'/deliveries', ['X-Inertia' => 'true'])->assertOk();
        $this->assertFalse($response->json('props.isOnline'));
        $this->assertFalse($response->json('props.auth.user.courier_profile.is_available'));
        $this->assertSame($parcel->id, $response->json('props.queues.pickupTasks.0.id'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $rider->update(['status' => 'inactive']);
        $this->get($base.'/deliveries')->assertRedirect();
    }

    #[DataProvider('portals')]
    public function test_private_speculation_cannot_read_work_or_change_activity(string $base): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'picked_up');
        $before = DeliveryCheckpoint::count();
        $cashEvents = CodCashEvent::count();
        $rider = $parcel->courier;
        $this->actingAs($rider);
        foreach (['deliveries', 'earnings', 'profile', 'messages', 'cod'] as $page) {
            $this->get($base.'/'.$page, ['Purpose' => 'prefetch', 'X-Inertia' => 'true'])->assertStatus(409);
        }
        $this->assertSame($before, DeliveryCheckpoint::count());
        $this->assertSame('picked_up', $parcel->fresh()->status);
        $this->assertSame($cashEvents, CodCashEvent::count());
        $hub = LogisticsHub::findOrFail($parcel->origin_bayan_hub_id);
        $rider->courierProfile->update(['assigned_hub_id' => $parcel->destination_bayan_hub_id]);
        $response = $this->get($base.'/deliveries', ['X-Inertia' => 'true'])->assertOk();
        $this->assertSame([], $response->json('props.queues.pickupTasks'));
        $this->assertNotSame($hub->id, $response->json('props.auth.user.courier_profile.assigned_hub_id'));
    }
}

<?php

namespace Tests\Feature\Courier;

use App\Models\Delivery;
use App\Models\LogisticsHub;
use App\Models\User;
use App\Services\Courier\CourierOperationsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Inertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class RiderParcelWorkspaceTest extends TestCase
{
    use CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->get('/')->assertOk();
        $this->withHeader('X-Inertia-Version', Inertia::getVersion() ?? '');
    }

    public static function phases(): array
    {
        $cases = [];
        foreach (['/courier', 'http://courier.localhost'] as $base) {
            foreach (['assigned_pickup' => false, 'picked_up' => true, 'assigned_to_rider' => false, 'out_for_delivery' => true] as $stage => $carrying) {
                $cases[] = [$base, $stage, $carrying];
            }
        }

        return $cases;
    }

    #[DataProvider('phases')]
    public function test_workspace_distinguishes_assignment_from_actual_retained_custody(string $base, string $stage, bool $carrying): void
    {
        $order = $this->newFlowOrder();
        $parcel = $this->flowDelivery($order, $stage);
        $pickup = in_array($stage, ['assigned_pickup', 'picked_up'], true);
        $rider = User::findOrFail($pickup ? $parcel->courier_id : $parcel->assigned_rider_id);
        $queue = $pickup ? 'pickupTasks' : 'finalMileTasks';
        $response = $this->actingAs($rider)->get($base.'/deliveries', ['X-Inertia' => 'true'])->assertOk();
        $task = $response->json('props.queues.'.$queue.'.0');
        $this->assertSame($parcel->id, $task['id']);
        $this->assertSame($carrying, $task['retainedByCurrentCourier']);
        $this->assertSame($parcel->tracking_number, $task['trackingNumber']);
        $this->assertSame($order->order_number, $task['orderNumber']);
        $this->assertNotSame($task['trackingNumber'], $task['orderNumber']);
        $this->assertSame($order->items->sum('quantity'), $task['itemCount']);
        $this->assertSame($order->items->first()->product->name, $task['items'][0]['name']);
        $this->assertSame($order->items->first()->quantity, $task['items'][0]['quantity']);
        $this->assertFalse(app(CourierOperationsService::class)->retainsParcel($parcel->fresh(), $this->createApprovedUser('courier')));
    }

    public function test_available_pickup_is_a_preview_with_contents_and_no_rider_custody(): void
    {
        $order = $this->newFlowOrder('ready_for_pickup');
        $parcel = $order->delivery;
        $rider = $this->flowRider(LogisticsHub::findOrFail($parcel->origin_bayan_hub_id));
        $response = $this->actingAs($rider)->get('/courier/deliveries', ['X-Inertia' => 'true'])->assertOk();
        $task = $response->json('props.queues.availablePickups.0');
        $this->assertSame($parcel->id, $task['id']);
        $this->assertFalse($task['retainedByCurrentCourier']);
        $this->assertSame('claim_pickup', $task['nextAction']);
        $this->assertSame($order->items->first()->product->name, $task['items'][0]['name']);
        $this->assertNull($parcel->fresh()->courier_id);
    }

    public function test_an_assignment_or_status_label_cannot_invent_carrying_without_retained_evidence(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'assigned_pickup');
        $rider = $parcel->courier;
        Delivery::whereKey($parcel->id)->update(['status' => 'picked_up']);
        $response = $this->actingAs($rider)->get('/courier/deliveries', ['X-Inertia' => 'true'])->assertOk();
        $this->assertFalse($response->json('props.queues.pickupTasks.0.retainedByCurrentCourier'));
    }

    public function test_actual_origin_intake_removes_the_parcel_from_the_pickup_riders_workspace(): void
    {
        $order = $this->newFlowOrder();
        $parcel = $this->flowDelivery($order, 'picked_up');
        $rider = $parcel->courier;
        $this->assertTrue(app(CourierOperationsService::class)->retainsParcel($parcel->fresh(), $rider));
        $this->confirmFlowScan($parcel, LogisticsHub::findOrFail($parcel->origin_bayan_hub_id), 'RECEIVE_FROM_PICKUP_RIDER');
        $this->assertFalse(app(CourierOperationsService::class)->retainsParcel($parcel->fresh(), $rider));
        $response = $this->actingAs($rider)->get('/courier/deliveries', ['X-Inertia' => 'true'])->assertOk();
        $this->assertSame([], $response->json('props.queues.pickupTasks'));
    }
}

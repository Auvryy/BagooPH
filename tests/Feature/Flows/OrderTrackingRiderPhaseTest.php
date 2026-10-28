<?php

namespace Tests\Feature\Flows;

use App\Services\Orders\OrderTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class OrderTrackingRiderPhaseTest extends TestCase
{
    use AssertsDeliveryCheckpoints, CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    public static function phases(): array
    {
        return [
            ['picked_up', 'Pickup rider (handover recorded)'],
            ['out_for_delivery', 'Delivery rider (handover recorded)'],
        ];
    }

    #[DataProvider('phases')]
    public function test_same_bayan_hub_rider_is_described_by_the_actual_custody_phase(string $stage, string $holder): void
    {
        $order = $this->newFlowOrder(shipping: ['shipping_city' => 'Los Banos']);
        $parcel = $this->flowDelivery($order, $stage);
        $this->assertSame($parcel->origin_bayan_hub_id, $parcel->destination_bayan_hub_id);
        if ($stage === 'out_for_delivery') {
            $this->assertSame($parcel->courier_id, $parcel->assigned_rider_id);
            $this->assertFalse($parcel->checkpoints()->where('checkpoint_type', 'arrived_at_mother_hub')->exists());
            $this->assertTrue($parcel->same_hub_bypass);
        }
        $order = $order->fresh(OrderTrackingService::relations());
        $tracking = app(OrderTrackingService::class)->present($order);
        $this->assertNull($tracking['notice']);
        $this->assertSame($holder, $tracking['custodian']);
    }
}

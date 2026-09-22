<?php

namespace Tests\Feature\Logistics;

use App\Models\CourierProfile;
use App\Models\Delivery;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\Order;
use App\Models\User;
use App\Services\Logistics\OrderStateMachineService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogisticsOrderCustodyFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_waybill_scans_record_origin_and_mother_hub_custody_one_step_at_a_time(): void
    {
        $logistics = User::where('email', 'logistics@bagoo.test')->firstOrFail();
        $pickupRider = User::where('email', 'rider@bagoo.test')->firstOrFail();
        $originHub = LogisticsHub::where('code', 'BH-SCZ-01')->firstOrFail();
        $motherHub = LogisticsHub::where('code', 'MH-LAG-01')->firstOrFail();
        $destinationHub = LogisticsHub::where('code', 'BH-LBN-01')->firstOrFail();
        $company = LogisticsCompany::where('code', 'BGX')->firstOrFail();
        $order = $this->createOrder('picked_up', 'Batong Malake');
        $delivery = Delivery::factory()->create([
            'order_id' => $order->id,
            'courier_id' => $pickupRider->id,
            'logistics_company_id' => $company->id,
            'origin_bayan_hub_id' => $originHub->id,
            'origin_mother_hub_id' => $motherHub->id,
            'destination_mother_hub_id' => $motherHub->id,
            'destination_bayan_hub_id' => $destinationHub->id,
            'current_hub_id' => $originHub->id,
            'delivery_type' => 'doorstep',
            'status' => OrderStateMachineService::STATUS_PICKED_UP,
        ]);

        $this->actingAs($logistics)->postJson(route('hub.scan'), [
            'barcode' => $delivery->tracking_number,
            'hub_id' => $originHub->id,
        ])->assertOk();

        $this->assertSame(OrderStateMachineService::STATUS_ARRIVED_AT_ORIGIN_HUB, $delivery->fresh()->status);
        $this->assertSame('at_sorting_center', $order->fresh()->status);

        $this->actingAs($logistics)->postJson(route('hub.scan'), [
            'barcode' => $delivery->tracking_number,
            'hub_id' => $originHub->id,
        ])->assertOk();

        $delivery->refresh();
        $this->assertSame(OrderStateMachineService::STATUS_IN_TRANSIT_TO_MOTHER_HUB, $delivery->status);
        $this->assertNotNull($delivery->shuttle_manifest_number);

        $this->actingAs($logistics)->postJson(route('hub.scan'), [
            'barcode' => $delivery->tracking_number,
            'hub_id' => $motherHub->id,
        ])->assertOk();

        $delivery->refresh();
        $this->assertSame(OrderStateMachineService::STATUS_ARRIVED_AT_MOTHER_HUB, $delivery->status);
        $this->assertSame($motherHub->id, $delivery->current_hub_id);
    }

    public function test_hub_sorts_then_assigns_before_only_the_selected_rider_can_dispatch(): void
    {
        $logistics = User::where('email', 'logistics@bagoo.test')->firstOrFail();
        $assignedRider = User::where('email', 'rider@bagoo.test')->firstOrFail();
        $destinationHub = LogisticsHub::where('code', 'BH-SCZ-01')->firstOrFail();
        $company = LogisticsCompany::where('code', 'BGX')->firstOrFail();
        $order = $this->createOrder('at_sorting_center', 'Poblacion III');
        $delivery = Delivery::factory()->create([
            'order_id' => $order->id,
            'logistics_company_id' => $company->id,
            'destination_bayan_hub_id' => $destinationHub->id,
            'current_hub_id' => $destinationHub->id,
            'delivery_type' => 'doorstep',
            'destination_bin' => 'BIN: BRGY-POBLACION-III',
            'status' => OrderStateMachineService::STATUS_ARRIVED_AT_DEST_HUB,
        ]);

        $this->actingAs($logistics)->postJson(route('hub.sort'), [
            'delivery_id' => $delivery->id,
            'barangay' => 'Poblacion III',
            'bin' => 'BIN: BRGY-POBLACION-III',
        ])->assertOk();

        $this->assertSame(OrderStateMachineService::STATUS_SORTED_TO_BARANGAY_BIN, $delivery->fresh()->status);
        $this->assertSame('sorted', $order->fresh()->status);

        $this->actingAs($logistics)->postJson(route('hub.assignRider', $delivery), [
            'rider_id' => $assignedRider->id,
        ])->assertOk();

        $delivery->refresh();
        $this->assertSame(OrderStateMachineService::STATUS_ASSIGNED_TO_RIDER, $delivery->status);
        $this->assertSame($assignedRider->id, $delivery->assigned_rider_id);
        $this->assertSame('assigned_to_rider', $order->fresh()->status);

        $otherRider = User::factory()->courier()->create([
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);
        CourierProfile::factory()->create([
            'user_id' => $otherRider->id,
            'assigned_hub_id' => $destinationHub->id,
            'assigned_barangay' => 'Poblacion III',
            'is_available' => true,
        ]);

        $this->actingAs($otherRider)->patch(route('courier.updateStatus', $delivery), [
            'status' => 'out_for_delivery',
        ]);
        $this->assertSame(OrderStateMachineService::STATUS_ASSIGNED_TO_RIDER, $delivery->fresh()->status);

        $this->actingAs($assignedRider)->patch(route('courier.updateStatus', $delivery), [
            'status' => 'out_for_delivery',
        ]);

        $this->assertSame(OrderStateMachineService::STATUS_OUT_FOR_DELIVERY, $delivery->fresh()->status);
        $this->assertSame('out_for_delivery', $order->fresh()->status);
    }

    private function createOrder(string $status, string $barangay): Order
    {
        $buyer = User::where('email', 'buyer@bagoo.test')->firstOrFail();

        return Order::factory()->create([
            'buyer_id' => $buyer->id,
            'status' => $status,
            'delivery_type' => 'doorstep',
            'destination_barangay' => $barangay,
            'shipping_city' => 'Santa Cruz',
        ]);
    }
}

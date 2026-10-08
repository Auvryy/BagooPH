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
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithCodCollection;
use Tests\TestCase;

class LogisticsOrderCustodyFlowTest extends TestCase
{
    use InteractsWithCodCollection;
    use RefreshDatabase, \Tests\Feature\E2E\Support\CreatesE2EOrders, \Tests\Feature\E2E\Support\InteractsWithOrderActions, \Tests\Feature\E2E\Support\InteractsWithRoles;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);
    }

    public function test_waybill_scans_record_origin_and_mother_hub_custody_one_step_at_a_time(): void
    {
        $originHandler = User::where('email', 'losbanos.hub@bagoo.test')->firstOrFail();
        $motherHandler = User::where('email', 'motherhub@bagoo.test')->firstOrFail();
        $pickupRider = User::where('email', 'pickup.rider@bagoo.test')->firstOrFail();
        $originHub = LogisticsHub::where('code', 'BH-LBN-01')->firstOrFail();
        $motherHub = LogisticsHub::where('code', 'MH-LAG-01')->firstOrFail();
        $destinationHub = LogisticsHub::where('code', 'BH-SCZ-01')->firstOrFail();
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
            'current_hub_id' => null,
            'delivery_type' => 'doorstep',
            'status' => OrderStateMachineService::STATUS_PICKED_UP,
        ]);

        $this->confirmScan(
            $originHandler,
            $delivery,
            $originHub,
            'RECEIVE_FROM_PICKUP_RIDER',
            OrderStateMachineService::STATUS_PICKED_UP
        );

        $this->assertSame(OrderStateMachineService::STATUS_ARRIVED_AT_ORIGIN_HUB, $delivery->fresh()->status);
        $this->assertSame('at_sorting_center', $order->fresh()->status);

        $this->confirmScan(
            $originHandler,
            $delivery,
            $originHub,
            'DISPATCH_TO_FEEDER',
            OrderStateMachineService::STATUS_ARRIVED_AT_ORIGIN_HUB
        );

        $delivery->refresh();
        $this->assertSame(OrderStateMachineService::STATUS_IN_TRANSIT_TO_MOTHER_HUB, $delivery->status);
        $this->assertNotNull($delivery->shuttle_manifest_number);

        $this->confirmScan(
            $motherHandler,
            $delivery,
            $motherHub,
            'RECEIVE_AT_MOTHER_HUB',
            OrderStateMachineService::STATUS_IN_TRANSIT_TO_MOTHER_HUB
        );

        $delivery->refresh();
        $this->assertSame(OrderStateMachineService::STATUS_ARRIVED_AT_MOTHER_HUB, $delivery->status);
        $this->assertSame($motherHub->id, $delivery->current_hub_id);
    }

    public function test_confirmed_origin_scan_immediately_returns_the_next_action(): void
    {
        $originHandler = User::where('email', 'losbanos.hub@bagoo.test')->firstOrFail();
        $pickupRider = User::where('email', 'pickup.rider@bagoo.test')->firstOrFail();
        $originHub = LogisticsHub::where('code', 'BH-LBN-01')->firstOrFail();
        $motherHub = LogisticsHub::where('code', 'MH-LAG-01')->firstOrFail();
        $destinationHub = LogisticsHub::where('code', 'BH-SCZ-01')->firstOrFail();
        $company = LogisticsCompany::where('code', 'BGX')->firstOrFail();
        $order = $this->createOrder('picked_up', 'Poblacion III');
        $delivery = Delivery::factory()->create([
            'order_id' => $order->id,
            'courier_id' => $pickupRider->id,
            'logistics_company_id' => $company->id,
            'origin_bayan_hub_id' => $originHub->id,
            'origin_mother_hub_id' => $motherHub->id,
            'destination_mother_hub_id' => $motherHub->id,
            'destination_bayan_hub_id' => $destinationHub->id,
            'current_hub_id' => null,
            'status' => OrderStateMachineService::STATUS_PICKED_UP,
        ]);

        $response = $this->actingAs($originHandler)->postJson(route('hub.scan'), [
            'barcode' => $delivery->tracking_number,
            'hub_id' => $originHub->id,
            'mode' => 'confirm',
            'action' => 'RECEIVE_FROM_PICKUP_RIDER',
            'expected_status' => OrderStateMachineService::STATUS_PICKED_UP,
        ]);

        $response->assertOk()
            ->assertHeader('Cache-Control')
            ->assertJsonPath('confirmed', true)
            ->assertJsonPath('delivery.status', OrderStateMachineService::STATUS_ARRIVED_AT_ORIGIN_HUB)
            ->assertJsonPath('prompt.action', 'DISPATCH_TO_FEEDER')
            ->assertJsonPath('prompt.expected_status', OrderStateMachineService::STATUS_ARRIVED_AT_ORIGIN_HUB)
            ->assertJsonPath('prompt.requires_confirmation', false)
            ->assertJsonPath('prompt.manifest_url', '/hub/manifests');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $this->actingAs($originHandler)->postJson(route('hub.scan'), [
            'barcode' => $delivery->tracking_number,
            'hub_id' => $originHub->id,
            'mode' => 'confirm',
            'action' => 'DISPATCH_TO_FEEDER',
            'expected_status' => OrderStateMachineService::STATUS_ARRIVED_AT_ORIGIN_HUB,
        ])->assertConflict();
        $this->assertSame(OrderStateMachineService::STATUS_ARRIVED_AT_ORIGIN_HUB, $delivery->fresh()->status);
        $this->confirmFlowManifest($delivery, $originHub, true, $originHandler);
        $this->assertSame(OrderStateMachineService::STATUS_IN_TRANSIT_TO_MOTHER_HUB, $delivery->fresh()->status);
    }

    public function test_origin_hub_can_scan_immediately_after_rider_pickup_without_navigation(): void
    {
        $originHandler = User::where('email', 'losbanos.hub@bagoo.test')->firstOrFail();
        $pickupRider = User::where('email', 'pickup.rider@bagoo.test')->firstOrFail();
        $originHub = LogisticsHub::where('code', 'BH-LBN-01')->firstOrFail();
        $motherHub = LogisticsHub::where('code', 'MH-LAG-01')->firstOrFail();
        $destinationHub = LogisticsHub::where('code', 'BH-SCZ-01')->firstOrFail();
        $company = LogisticsCompany::where('code', 'BGX')->firstOrFail();
        $order = $this->createOrder(OrderStateMachineService::STATUS_READY_FOR_PICKUP, 'Poblacion III');
        $delivery = Delivery::factory()->create([
            'order_id' => $order->id,
            'courier_id' => $pickupRider->id,
            'logistics_company_id' => $company->id,
            'origin_bayan_hub_id' => $originHub->id,
            'origin_mother_hub_id' => $motherHub->id,
            'destination_mother_hub_id' => $motherHub->id,
            'destination_bayan_hub_id' => $destinationHub->id,
            'current_hub_id' => null,
            'status' => 'assigned_pickup',
        ]);

        $this->actingAs($pickupRider)
            ->patch(route('courier.updateStatus', $delivery), [
                'status' => 'picked_up',
                'barcode' => $delivery->tracking_number,
            ])
            ->assertSessionHas('success');

        $this->actingAs($originHandler)
            ->postJson(route('hub.scan'), [
                'barcode' => $delivery->tracking_number,
                'hub_id' => $originHub->id,
                'mode' => 'inspect',
            ])
            ->assertOk()
            ->assertJsonPath('delivery.status', OrderStateMachineService::STATUS_PICKED_UP)
            ->assertJsonPath('prompt.action', 'RECEIVE_FROM_PICKUP_RIDER')
            ->assertJsonPath('prompt.requires_confirmation', true);
    }

    public function test_destination_intake_immediately_returns_the_sorting_step(): void
    {
        $order = $this->newFlowOrder();
        $delivery = $this->flowDelivery($order, 'in_transit_to_destination_hub');
        $destinationHub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $destinationHandler = $this->flowHandler($destinationHub);
        $this->confirmFlowManifest($delivery, $destinationHub, false, $destinationHandler);
        $this->assertSame(OrderStateMachineService::STATUS_ARRIVED_AT_DEST_HUB, $delivery->fresh()->status);
        $this->actingAs($destinationHandler)->postJson(route('hub.scan'), [
            'barcode' => $delivery->tracking_number, 'hub_id' => $destinationHub->id, 'mode' => 'inspect',
        ])->assertOk()
            ->assertJsonPath('delivery.status', OrderStateMachineService::STATUS_ARRIVED_AT_DEST_HUB)
            ->assertJsonPath('prompt.action', 'AWAIT_BARANGAY_SORT')
            ->assertJsonPath('prompt.expected_status', OrderStateMachineService::STATUS_ARRIVED_AT_DEST_HUB)
            ->assertJsonPath('prompt.requires_confirmation', false);
    }

    public static function courierApprovals(): array
    {
        return [['approved'], ['verified']];
    }

    public static function ineligibleFinalMileAccounts(): array
    {
        return [
            ['courier', 'active', 'pending_approval', false],
            ['courier', 'active', 'rejected', false],
            ['courier', 'inactive', 'verified', false],
            ['courier', 'suspended', 'approved', false],
            ['admin', 'active', 'approved', false],
            ['buyer', 'active', 'verified', false],
            ['courier', 'active', 'verified', true],
        ];
    }

    #[DataProvider('ineligibleFinalMileAccounts')]
    public function test_assignment_rejects_unapproved_accounts_and_out_of_scope_legacy_riders(string $role, string $status, string $approval, bool $wrongHub): void
    {
        $operator = User::where('email', 'logistics@bagoo.test')->firstOrFail();
        $seededProfile = User::where('email', 'rider@bagoo.test')->firstOrFail()->courierProfile;
        $rider = User::factory()->create(['role' => $role, 'status' => $status, 'kyc_status' => $approval]);
        $hub = LogisticsHub::where('code', 'BH-SCZ-01')->firstOrFail();
        $company = LogisticsCompany::where('code', 'BGX')->firstOrFail();
        CourierProfile::factory()->create([
            'user_id' => $rider->id,
            'logistics_company_id' => $seededProfile->logistics_company_id,
            'assigned_hub_id' => $wrongHub ? LogisticsHub::where('code', 'BH-LBN-01')->firstOrFail()->id : $seededProfile->assigned_hub_id,
            'assigned_barangay' => $seededProfile->assigned_barangay,
            'vehicle_id' => $seededProfile->vehicle_id,
            'or_cr_status' => $seededProfile->or_cr_status,
            'is_available' => $seededProfile->is_available,
        ]);
        $order = $this->createOrder('sorted', 'Poblacion III');
        $delivery = Delivery::factory()->create([
            'order_id' => $order->id, 'logistics_company_id' => $company->id,
            'destination_bayan_hub_id' => $hub->id, 'current_hub_id' => $hub->id,
            'delivery_type' => 'doorstep', 'status' => OrderStateMachineService::STATUS_SORTED_TO_BARANGAY_BIN,
        ]);

        $this->actingAs($operator)->get(route('hub.deliveries'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('eligibleRiders', fn ($riders) => ! collect($riders)->contains('id', $rider->id)));
        $this->postJson(route('hub.assignRider', $delivery), ['rider_id' => $rider->id])->assertStatus(422);

        try {
            app(OrderStateMachineService::class)->transition($delivery, OrderStateMachineService::STATUS_ASSIGNED_TO_RIDER, $operator, [
                'hub_id' => $hub->id, 'rider_id' => $rider->id,
            ]);
            $this->fail('An ineligible rider must not be assigned through the custody service.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('not eligible', $exception->getMessage());
        }
        $this->assertNull($delivery->fresh()->assigned_rider_id);
        $this->assertSame('sorted', $order->fresh()->status);
        $this->assertDatabaseCount('delivery_checkpoints', 0);
    }

    #[DataProvider('courierApprovals')]
    public function test_hub_sorts_then_assigns_before_only_the_selected_rider_can_dispatch(string $approval): void
    {
        $order = $this->newFlowOrder();
        $delivery = $this->flowDelivery($order, 'arrived_at_destination_hub');
        $destinationHub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $logistics = $this->flowHandler($destinationHub);
        $assignedRider = $this->flowRider($destinationHub);
        $assignedRider->update(['kyc_status' => $approval]);

        $this->actingAs($logistics)->postJson(route('hub.sort'), [
            'delivery_id' => $delivery->id,
            'barangay' => 'Poblacion III',
            'bin' => 'BIN: BRGY-POBLACION-III',
        ])->assertOk();

        $this->assertSame(OrderStateMachineService::STATUS_SORTED_TO_BARANGAY_BIN, $delivery->fresh()->status);
        $this->assertSame('sorted', $order->fresh()->status);

        $this->actingAs($logistics)->get(route('hub.deliveries'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('eligibleRiders', fn ($riders) => collect($riders)->contains('id', $assignedRider->id)));

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
            'barcode' => $delivery->tracking_number,
        ]);
        $this->assertSame(OrderStateMachineService::STATUS_ASSIGNED_TO_RIDER, $delivery->fresh()->status);

        $this->actingAs($assignedRider)->patch(route('courier.updateStatus', $delivery), [
            'status' => 'out_for_delivery',
            'barcode' => $delivery->tracking_number,
        ]);

        $this->assertSame(OrderStateMachineService::STATUS_OUT_FOR_DELIVERY, $delivery->fresh()->status);
        $this->assertSame('out_for_delivery', $order->fresh()->status);

        Storage::fake('public');
        $assignedRider->courierProfile->update(['is_available' => false]);
        $this->actingAs($assignedRider)->patch(route('courier.updateStatus', $delivery), [
            ...$this->codCollectionInput($delivery),
            'status' => 'delivered',
            'proof_image_file' => UploadedFile::fake()->create('proof.jpg', 20, 'image/jpeg'),
        ])->assertSessionHas('success');
        $this->assertSame('delivered', $delivery->fresh()->status);
        $this->assertSame('delivered', $order->fresh()->status);
        $this->assertSame($approval, $assignedRider->fresh()->kyc_status);
    }

    public function test_handler_scan_station_cannot_switch_to_another_facility(): void
    {
        $originHandler = User::where('email', 'losbanos.hub@bagoo.test')->firstOrFail();
        $originHub = LogisticsHub::where('code', 'BH-LBN-01')->firstOrFail();
        $destinationHub = LogisticsHub::where('code', 'BH-SCZ-01')->firstOrFail();

        $this->actingAs($originHandler)
            ->get(route('hub.scan.station', ['hub_id' => $destinationHub->id]))
            ->assertForbidden();
        $this->get(route('hub.scan.station'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Hub/ScanStation')
                ->where('activeHub.id', $originHub->id)
                ->has('hubs', 1)
                ->where('hubs.0.id', $originHub->id)
            );
    }

    public function test_inspection_is_read_only_and_wrong_facility_or_tampered_action_is_rejected(): void
    {
        $originHandler = User::where('email', 'losbanos.hub@bagoo.test')->firstOrFail();
        $destinationHandler = User::where('email', 'logistics@bagoo.test')->firstOrFail();
        $pickupRider = User::where('email', 'pickup.rider@bagoo.test')->firstOrFail();
        $originHub = LogisticsHub::where('code', 'BH-LBN-01')->firstOrFail();
        $motherHub = LogisticsHub::where('code', 'MH-LAG-01')->firstOrFail();
        $destinationHub = LogisticsHub::where('code', 'BH-SCZ-01')->firstOrFail();
        $company = LogisticsCompany::where('code', 'BGX')->firstOrFail();
        $order = $this->createOrder('picked_up', 'Poblacion III');
        $delivery = Delivery::factory()->create([
            'order_id' => $order->id,
            'courier_id' => $pickupRider->id,
            'logistics_company_id' => $company->id,
            'origin_bayan_hub_id' => $originHub->id,
            'origin_mother_hub_id' => $motherHub->id,
            'destination_mother_hub_id' => $motherHub->id,
            'destination_bayan_hub_id' => $destinationHub->id,
            'current_hub_id' => null,
            'delivery_type' => 'doorstep',
            'status' => OrderStateMachineService::STATUS_PICKED_UP,
        ]);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->actingAs($originHandler)->postJson(route('hub.scan'), [
                'barcode' => $delivery->tracking_number,
                'hub_id' => $originHub->id,
                'mode' => 'inspect',
            ])->assertOk()->assertJsonPath('prompt.action', 'RECEIVE_FROM_PICKUP_RIDER');
        }

        $this->assertSame(OrderStateMachineService::STATUS_PICKED_UP, $delivery->fresh()->status);
        $this->assertDatabaseMissing('delivery_checkpoints', [
            'delivery_id' => $delivery->id,
            'checkpoint_type' => OrderStateMachineService::STATUS_ARRIVED_AT_ORIGIN_HUB,
        ]);

        $this->actingAs($destinationHandler)->postJson(route('hub.scan'), [
            'barcode' => $delivery->tracking_number,
            'hub_id' => $originHub->id,
            'mode' => 'inspect',
        ])->assertForbidden();

        $this->actingAs($originHandler)->postJson(route('hub.scan'), [
            'barcode' => $delivery->tracking_number,
            'hub_id' => $originHub->id,
            'mode' => 'confirm',
            'action' => 'DISPATCH_LINE_HAUL',
            'expected_status' => OrderStateMachineService::STATUS_PICKED_UP,
        ])->assertStatus(409);

        $this->assertSame(OrderStateMachineService::STATUS_PICKED_UP, $delivery->fresh()->status);
    }

    public function test_rider_cannot_view_or_claim_another_company_pickup_job(): void
    {
        $bagooCompany = LogisticsCompany::where('code', 'BGX')->firstOrFail();
        $originHub = LogisticsHub::where('code', 'BH-LBN-01')->firstOrFail();
        $motherHub = LogisticsHub::where('code', 'MH-LAG-01')->firstOrFail();
        $destinationHub = LogisticsHub::where('code', 'BH-SCZ-01')->firstOrFail();
        $order = $this->createOrder('ready_for_pickup', 'Poblacion III');
        $delivery = Delivery::factory()->create([
            'order_id' => $order->id,
            'logistics_company_id' => $bagooCompany->id,
            'origin_bayan_hub_id' => $originHub->id,
            'origin_mother_hub_id' => $motherHub->id,
            'destination_mother_hub_id' => $motherHub->id,
            'destination_bayan_hub_id' => $destinationHub->id,
            'status' => 'unassigned',
        ]);

        $foreignCompany = LogisticsCompany::create([
            'name' => 'Independent Road Carrier',
            'slug' => 'independent-road-carrier',
            'code' => 'IRC',
            'status' => 'active',
            'is_active' => true,
        ]);
        $foreignHub = LogisticsHub::create([
            'logistics_company_id' => $foreignCompany->id,
            'name' => 'Foreign Bayan Hub',
            'code' => 'BH-IRC-01',
            'tier' => 'local_bayan_hub',
            'province' => 'Laguna',
            'city_municipality' => 'Bay',
            'barangay' => 'Dila',
            'address' => 'Bay, Laguna',
            'is_active' => true,
        ]);
        $foreignRider = User::factory()->courier()->create([
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);
        CourierProfile::factory()->create([
            'user_id' => $foreignRider->id,
            'logistics_company_id' => $foreignCompany->id,
            'assigned_hub_id' => $foreignHub->id,
            'is_available' => true,
        ]);

        $this->actingAs($foreignRider)->get(route('courier.deliveries'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Courier/Deliveries')
                ->has('queues.availablePickups', 0)
            );

        $this->actingAs($foreignRider)->post(route('courier.claim', $delivery))
            ->assertSessionHas('error');
        $this->assertNull($delivery->fresh()->courier_id);
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

    private function confirmScan(
        User $operator,
        Delivery $delivery,
        LogisticsHub $hub,
        string $action,
        string $expectedStatus
    ): void {
        $this->assertSame($expectedStatus, $delivery->fresh()->status);
        if (in_array($action, ['DISPATCH_TO_FEEDER', 'DISPATCH_LINE_HAUL', 'RECEIVE_AT_MOTHER_HUB', 'RECEIVE_AT_DESTINATION_HUB'], true)) {
            $this->confirmFlowManifest($delivery, $hub, str_starts_with($action, 'DISPATCH'), $operator);

            return;
        }
        $this->actingAs($operator)->postJson(route('hub.scan'), [
            'barcode' => $delivery->tracking_number,
            'hub_id' => $hub->id,
            'mode' => 'inspect',
        ])->assertOk()->assertJsonPath('prompt.action', $action);

        $this->actingAs($operator)->postJson(route('hub.scan'), [
            'barcode' => $delivery->tracking_number,
            'hub_id' => $hub->id,
            'mode' => 'confirm',
            'action' => $action,
            'expected_status' => $expectedStatus,
        ])->assertOk()->assertJsonPath('confirmed', true);
    }
}

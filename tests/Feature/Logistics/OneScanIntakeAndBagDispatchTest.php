<?php

namespace Tests\Feature\Logistics;

use App\Models\HubHandler;
use App\Models\LogisticsFleet;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifest;
use App\Models\MotherBag;
use App\Models\User;
use App\Services\Logistics\MotherBagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

/**
 * The pickup rider drops a parcel at the hub: one scan by the right hub's worker receives it, and it is
 * straight away ready for a Mother Bag. The bag is then sealed, put on a truck and dispatched in one step.
 */
class OneScanIntakeAndBagDispatchTest extends TestCase
{
    use CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function worker(LogisticsHub $hub, string $job): User
    {
        $worker = $this->createApprovedUser('logistics');
        $worker->forceFill(['password' => 'Password1234', 'must_change_password' => false])->save();
        HubHandler::create(['user_id' => $worker->id, 'logistics_company_id' => $hub->logistics_company_id, 'hub_id' => $hub->id,
            'job_role' => $job, 'role_title' => HubHandler::roleTitleFor($job), 'is_active' => true]);

        return $worker;
    }

    private function phoneToken(User $worker): string
    {
        return $this->postJson('/api/v1/logistics/auth/tokens', ['email' => $worker->email, 'password' => 'Password1234', 'device_name' => 'Worker phone'])
            ->assertOk()->json('data.token');
    }

    private function truck(LogisticsHub $hub): LogisticsFleet
    {
        $driver = $this->createApprovedUser('courier');
        $truck = LogisticsFleet::create(['logistics_company_id' => $hub->logistics_company_id, 'hub_id' => $hub->id,
            'plate_number' => 'ONE-'.Str::upper(Str::random(4)), 'vehicle_type' => 'l300_van', 'model' => 'Feeder van', 'capacity_kg' => 1000,
            'assigned_driver_id' => $driver->id, 'status' => 'active']);
        $driver->courierProfile->update(['logistics_company_id' => $hub->logistics_company_id, 'assigned_hub_id' => $hub->id,
            'vehicle_id' => $truck->id, 'is_available' => true]);

        return $truck;
    }

    public function test_one_web_scan_receives_the_parcel_and_it_is_ready_for_a_mother_bag(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder('ready_for_pickup'), 'picked_up');
        $origin = LogisticsHub::findOrFail($parcel->origin_bayan_hub_id);
        $intake = $this->worker($origin, HubHandler::JOB_ROLE_INTAKE);

        $this->actingAs($intake)->postJson('/hub/scan/batch', ['hub_id' => $origin->id, 'direct_mode' => true, 'items' => [[
            'barcode' => $parcel->tracking_number, 'request_token' => (string) Str::uuid(), 'client_scanned_at' => now()->toIso8601String(),
        ]]])->assertOk()->assertJsonPath('items.0.outcome', 'confirmed');

        $parcel->refresh();
        $this->assertSame('arrived_at_origin_hub', $parcel->status);
        $this->assertSame($origin->id, $parcel->current_hub_id);
        // No sorting or confirm click in between: the next step is packing it for the Mother Hub.
        $this->actingAs($intake)->postJson(route('hub.scan'), ['barcode' => $parcel->tracking_number, 'hub_id' => $origin->id, 'mode' => 'inspect'])
            ->assertOk()->assertJsonPath('prompt.action', 'DISPATCH_TO_FEEDER');
    }

    public function test_worker_phone_receives_with_one_tap_and_wrong_job_or_wrong_hub_is_refused(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder('ready_for_pickup'), 'picked_up');
        $origin = LogisticsHub::findOrFail($parcel->origin_bayan_hub_id);
        $mother = LogisticsHub::findOrFail($parcel->origin_mother_hub_id);

        $bagger = $this->phoneToken($this->worker($origin, HubHandler::JOB_ROLE_PACK_DISPATCH));
        $this->withToken($bagger)->postJson('/api/v1/logistics/scan/batch', ['hub_id' => $origin->id, 'direct_mode' => true, 'items' => [[
            'barcode' => $parcel->tracking_number, 'request_token' => (string) Str::uuid()]]])->assertForbidden();

        $elsewhere = $this->phoneToken($this->worker($mother, HubHandler::JOB_ROLE_INTAKE));
        $this->withToken($elsewhere)->postJson('/api/v1/logistics/scan', ['barcode' => $parcel->tracking_number, 'hub_id' => $origin->id, 'mode' => 'inspect'])
            ->assertForbidden();
        $this->assertSame('picked_up', $parcel->fresh()->status);

        // The phone app checks the parcel and confirms in the same tap.
        $phone = $this->phoneToken($this->worker($origin, HubHandler::JOB_ROLE_INTAKE));
        $prompt = $this->withToken($phone)->postJson('/api/v1/logistics/scan', ['barcode' => $parcel->tracking_number, 'hub_id' => $origin->id, 'mode' => 'inspect'])
            ->assertOk()->assertJsonPath('prompt.action', 'RECEIVE_FROM_PICKUP_RIDER')->json('prompt');
        $this->withToken($phone)->postJson('/api/v1/logistics/scan', ['barcode' => $parcel->tracking_number, 'hub_id' => $origin->id, 'mode' => 'confirm',
            'action' => $prompt['action'], 'expected_status' => $prompt['expected_status']])->assertOk()->assertJsonPath('confirmed', true);
        $this->assertSame('arrived_at_origin_hub', $parcel->fresh()->status);
    }

    public function test_bag_is_sealed_loaded_and_dispatched_in_one_step_from_web_and_phone(): void
    {
        $first = $this->flowDelivery($this->newFlowOrder('ready_for_pickup'), 'picked_up');
        $origin = LogisticsHub::findOrFail($first->origin_bayan_hub_id);
        $mother = LogisticsHub::findOrFail($first->origin_mother_hub_id);
        $this->confirmFlowScan($first, $origin, 'RECEIVE_FROM_PICKUP_RIDER');
        $bagger = $this->worker($origin, HubHandler::JOB_ROLE_PACK_DISPATCH);
        $truck = $this->truck($origin);
        $bags = app(MotherBagService::class);
        $handling = ['weight_kg' => 1, 'length_cm' => 20, 'width_cm' => 20, 'height_cm' => 20, 'confirmed' => true];

        // The owner monitors only: it can neither pack nor dispatch.
        $owner = $origin->company->user;
        $this->actingAs($owner)->postJson('/hub/mother-bags', ['source_hub_id' => $origin->id, 'destination_hub_id' => $mother->id,
            'creation_token' => (string) Str::uuid(), 'capacity_preset' => 'compact'])->assertForbidden();

        $bag = $bags->create($bagger, ['source_hub_id' => $origin->id, 'destination_hub_id' => $mother->id, 'creation_token' => (string) Str::uuid(), 'capacity_preset' => 'compact']);
        $bag = $bags->command($bag, $bagger, 'add', ['version' => $bag->version, 'barcode' => $first->tracking_number, 'handling' => $handling]);
        $this->actingAs($owner)->postJson("/hub/mother-bags/{$bag->id}/dispatch", ['vehicle_id' => $truck->id, 'seal_number' => 'SL-OWNER-1',
            'request_token' => (string) Str::uuid()])->assertForbidden();

        $token = (string) Str::uuid();
        $this->actingAs($bagger)->postJson("/hub/mother-bags/{$bag->id}/dispatch", ['vehicle_id' => $truck->id, 'request_token' => $token])
            ->assertStatus(409)->assertJsonPath('message', 'Enter the seal number before dispatching.');
        $this->actingAs($bagger)->postJson("/hub/mother-bags/{$bag->id}/dispatch", ['vehicle_id' => $truck->id, 'seal_number' => 'SL-ONE-001', 'request_token' => $token])
            ->assertOk()->assertJsonPath('bag.status', 'in_transit')->assertJsonPath('manifest.status', 'dispatched');
        $manifest = LogisticsManifest::sole();
        $this->assertSame([$origin->id, $mother->id, $truck->id], [$manifest->source_hub_id, $manifest->destination_hub_id, $manifest->vehicle_id]);
        $this->assertSame('in_transit_to_mother_hub', $first->fresh()->status);
        // A double tap returns the same dispatch instead of creating another manifest.
        $this->actingAs($bagger)->postJson("/hub/mother-bags/{$bag->id}/dispatch", ['vehicle_id' => $truck->id, 'seal_number' => 'SL-ONE-001', 'request_token' => $token])
            ->assertOk()->assertJsonPath('manifest.id', $manifest->id);
        $this->assertSame(1, LogisticsManifest::count());

        // Same flow from the bagging worker's phone.
        $second = $this->flowDelivery($this->newFlowOrder('ready_for_pickup'), 'picked_up');
        $this->assertSame($origin->id, $second->origin_bayan_hub_id);
        $this->confirmFlowScan($second, $origin, 'RECEIVE_FROM_PICKUP_RIDER');
        $phone = $this->phoneToken($bagger);
        $this->withToken($phone)->getJson('/api/v1/logistics/mother-bags?hub_id='.$origin->id)->assertOk()
            ->assertJsonCount(0, 'data.vehicles'); // the first truck is on the road, so it is not offered
        $created = $this->withToken($phone)->postJson('/api/v1/logistics/mother-bags', ['source_hub_id' => $origin->id, 'destination_hub_id' => $mother->id,
            'creation_token' => (string) Str::uuid(), 'capacity_preset' => 'compact'])->assertCreated()->json('data');
        $added = $this->withToken($phone)->postJson("/api/v1/logistics/mother-bags/{$created['id']}/add", ['version' => $created['version'],
            'barcode' => $second->tracking_number, 'handling' => $handling])->assertOk()->json('data');
        $this->withToken($phone)->postJson("/api/v1/logistics/mother-bags/{$created['id']}/dispatch", ['vehicle_id' => $truck->id,
            'seal_number' => 'SL-ONE-002', 'request_token' => (string) Str::uuid()])
            ->assertStatus(422);
        $this->assertSame('open', MotherBag::findOrFail($created['id'])->status, 'A refused dispatch leaves the bag as it was.');
        $secondTruck = $this->truck($origin);
        $this->withToken($phone)->getJson('/api/v1/logistics/mother-bags?hub_id='.$origin->id)->assertOk()->assertJsonPath('data.vehicles.0.id', $secondTruck->id);
        $this->withToken($phone)->postJson("/api/v1/logistics/mother-bags/{$created['id']}/dispatch", ['vehicle_id' => $secondTruck->id,
            'seal_number' => 'SL-ONE-002', 'request_token' => (string) Str::uuid()])->assertOk()->assertJsonPath('manifest.status', 'dispatched');
        $this->assertSame('in_transit', MotherBag::findOrFail($added['id'])->status);
        $this->assertSame('in_transit_to_mother_hub', $second->fresh()->status);
    }
}

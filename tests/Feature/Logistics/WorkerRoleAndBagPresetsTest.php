<?php

namespace Tests\Feature\Logistics;

use App\Models\HubHandler;
use App\Models\LogisticsHub;
use App\Models\MotherBag;
use App\Models\User;
use App\Services\Logistics\CargoHandlingService;
use App\Services\Logistics\LogisticsPlacementService;
use App\Services\Logistics\MotherBagService;
use Database\Seeders\HubWorkerRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\Support\RoutingCheckoutSeeder;
use Tests\TestCase;

class WorkerRoleAndBagPresetsTest extends TestCase
{
    use CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_manager_can_lock_and_replace_single_worker_job_role_exclusively(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder('ready_for_pickup'), 'arrived_at_origin_hub');
        $sourceHub = $parcel->originBayanHub;
        $motherHub = $parcel->originMotherHub;
        $owner = $sourceHub->company->user;

        $worker = $this->createApprovedUser('logistics');
        $worker->forceFill(['password' => 'Password1234', 'must_change_password' => false])->save();
        $placement = app(LogisticsPlacementService::class)->assignHandler(
            $owner,
            $sourceHub,
            $worker,
            HubHandler::JOB_ROLE_INTAKE,
        );

        $this->assertSame(HubHandler::JOB_ROLE_INTAKE, $placement->job_role);
        $this->assertSame(1, HubHandler::where('user_id', $worker->id)->where('hub_id', $sourceHub->id)->where('is_active', true)->count());

        // Entry / Intake worker can inspect/receive intake scans but cannot create or pack Mother Bags.
        $login = $this->postJson('/api/v1/logistics/auth/tokens', [
                'email' => $worker->email,
                'password' => 'Password1234',
                'device_name' => 'Worker Phone',
            ])
            ->assertOk()
            ->assertJsonPath('data.hubs.0.job_role', 'intake')
            ->assertJsonPath('data.hubs.0.can_intake', true)
            ->assertJsonPath('data.hubs.0.can_pack_dispatch', false);

        $token = $login->json('data.token');

        $this->withToken($token)->postJson('/api/v1/logistics/mother-bags', [
            'source_hub_id' => $sourceHub->id,
            'destination_hub_id' => $motherHub->id,
            'creation_token' => (string) Str::uuid(),
        ])->assertForbidden();

        // Manager switches the worker exclusively to pack_dispatch (no multi-role overlap).
        $this->actingAs($owner)
            ->patchJson(route('hub.handlers.job-role', $placement->id), [
                'job_role' => HubHandler::JOB_ROLE_PACK_DISPATCH,
            ])
            ->assertOk()
            ->assertJsonPath('job_role', HubHandler::JOB_ROLE_PACK_DISPATCH);

        $this->assertSame(HubHandler::JOB_ROLE_PACK_DISPATCH, $placement->fresh()->job_role);
        $this->app['auth']->forgetGuards();

        // Now intake custody confirmation is blocked with 403 while creating & packing Mother Bags is allowed.
        $this->withToken($token)->postJson('/api/v1/logistics/scan', [
            'barcode' => $parcel->tracking_number,
            'hub_id' => $sourceHub->id,
            'mode' => 'confirm',
            'action' => 'RECEIVE_PARCEL',
            'expected_status' => 'PICKED_UP',
        ])->assertForbidden();

        $created = $this->withToken($token)->postJson('/api/v1/logistics/mother-bags', [
            'source_hub_id' => $sourceHub->id,
            'destination_hub_id' => $motherHub->id,
            'creation_token' => (string) Str::uuid(),
            'capacity_preset' => 'custom',
            'max_count' => 75,
            'max_weight_kg' => 35,
            'max_volume_m3' => 0.45,
        ])->assertCreated();

        $bagId = $created->json('data.id');
        $this->assertSame(75, $created->json('data.capacity.max_count'));
        $this->assertEquals(35.0, (float) $created->json('data.capacity.max_weight_kg'));

        // Missing dimensions return needs_measurements: true so mobile shows popup.
        $this->withToken($token)->postJson("/api/v1/logistics/mother-bags/{$bagId}/add", [
            'version' => 1,
            'barcode' => $parcel->tracking_number,
        ])->assertStatus(422)->assertJsonPath('needs_measurements', true);

        // Pack with dimensions, then revert, then re-pack and seal via mobile API.
        $added = $this->withToken($token)->postJson("/api/v1/logistics/mother-bags/{$bagId}/add", [
            'version' => 1,
            'barcode' => $parcel->tracking_number,
            'handling' => ['weight_kg' => 2, 'length_cm' => 20, 'width_cm' => 20, 'height_cm' => 20, 'confirmed' => true],
        ])->assertOk();
        $this->assertSame(1, $added->json('data.parcels_count'));

        $removed = $this->withToken($token)->postJson("/api/v1/logistics/mother-bags/{$bagId}/remove", [
            'version' => $added->json('data.version'),
            'barcode' => $parcel->tracking_number,
            'notes' => 'Mis-scanned parcel',
        ])->assertOk();
        $this->assertSame(0, $removed->json('data.parcels_count'));

        $repacked = $this->withToken($token)->postJson("/api/v1/logistics/mother-bags/{$bagId}/add", [
            'version' => $removed->json('data.version'),
            'barcode' => $parcel->tracking_number,
            'handling' => ['weight_kg' => 2, 'length_cm' => 20, 'width_cm' => 20, 'height_cm' => 20, 'confirmed' => true],
        ])->assertOk();

        $sealed = $this->withToken($token)->postJson("/api/v1/logistics/mother-bags/{$bagId}/seal", [
            'version' => $repacked->json('data.version'),
            'seal_number' => 'SL-MOBILE-009',
        ])->assertOk();
        $this->assertSame('sealed', $sealed->json('data.status'));
        $this->assertSame('SL-MOBILE-009', $sealed->json('data.seal_number'));
    }

    public function test_custom_and_preset_mother_bag_capacities_enforce_configured_limits(): void
    {
        $parcelOne = $this->flowDelivery($this->newFlowOrder('ready_for_pickup'), 'arrived_at_origin_hub');
        $parcelTwo = $this->flowDelivery($this->newFlowOrder('ready_for_pickup'), 'arrived_at_origin_hub');
        $sourceHub = $parcelOne->originBayanHub;
        $motherHub = $parcelOne->originMotherHub;
        $handler = $this->flowHandler($sourceHub);

        $customBag = app(MotherBagService::class)->create($handler, [
            'source_hub_id' => $sourceHub->id,
            'destination_hub_id' => $motherHub->id,
            'creation_token' => (string) Str::uuid(),
            'capacity_preset' => 'custom',
            'max_count' => 1,
            'max_weight_kg' => 20,
            'max_volume_m3' => 0.25,
        ]);

        $this->assertSame('custom', $customBag->capacity_preset);
        $this->assertSame(1, $customBag->max_count);
        $this->assertSame(250000, $customBag->max_volume_cm3);

        $customBag = app(MotherBagService::class)->command($customBag, $handler, 'add', [
            'version' => $customBag->version,
            'barcode' => $parcelOne->tracking_number,
            'handling' => ['weight_kg' => 1.5, 'length_cm' => 20, 'width_cm' => 20, 'height_cm' => 20, 'confirmed' => true],
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('This bag would exceed 1 parcels');

        app(MotherBagService::class)->command($customBag, $handler, 'add', [
            'version' => $customBag->version,
            'barcode' => $parcelTwo->tracking_number,
            'handling' => ['weight_kg' => 1.5, 'length_cm' => 20, 'width_cm' => 20, 'height_cm' => 20, 'confirmed' => true],
        ]);
    }

    public function test_worker_roles_seeder_provisions_exclusive_intake_and_pack_workers_per_hub(): void
    {
        $this->seed([RoutingCheckoutSeeder::class, HubWorkerRolesSeeder::class]);

        $hub = LogisticsHub::where('code', 'BH-LBN-01')->firstOrFail();

        $intakeHandlers = HubHandler::where('hub_id', $hub->id)
            ->where('job_role', HubHandler::JOB_ROLE_INTAKE)
            ->where('is_active', true)
            ->count();
        $packHandlers = HubHandler::where('hub_id', $hub->id)
            ->where('job_role', HubHandler::JOB_ROLE_PACK_DISPATCH)
            ->where('is_active', true)
            ->count();

        $this->assertGreaterThanOrEqual(1, $intakeHandlers);
        $this->assertGreaterThanOrEqual(1, $packHandlers);
    }
}

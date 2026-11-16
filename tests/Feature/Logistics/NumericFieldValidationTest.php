<?php

namespace Tests\Feature\Logistics;

use App\Models\HubHandler;
use App\Models\LogisticsHub;
use App\Models\MotherBag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

/** Number fields take numbers only: letters or blanks are refused with a clear message, never replaced by defaults. */
class NumericFieldValidationTest extends TestCase
{
    use CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function bagger(LogisticsHub $hub): User
    {
        $worker = $this->createApprovedUser('logistics');
        HubHandler::create(['user_id' => $worker->id, 'logistics_company_id' => $hub->logistics_company_id, 'hub_id' => $hub->id,
            'job_role' => HubHandler::JOB_ROLE_PACK_DISPATCH, 'role_title' => HubHandler::roleTitleFor(HubHandler::JOB_ROLE_PACK_DISPATCH), 'is_active' => true]);

        return $worker;
    }

    public function test_custom_bag_limits_and_parcel_readings_reject_letters_and_blanks(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder('ready_for_pickup'), 'picked_up');
        $origin = LogisticsHub::findOrFail($parcel->origin_bayan_hub_id);
        $mother = LogisticsHub::findOrFail($parcel->origin_mother_hub_id);
        $this->confirmFlowScan($parcel, $origin, 'RECEIVE_FROM_PICKUP_RIDER');
        $bagger = $this->bagger($origin);
        $bag = fn (array $limits) => $this->actingAs($bagger)->postJson('/hub/mother-bags', ['source_hub_id' => $origin->id, 'destination_hub_id' => $mother->id,
            'creation_token' => (string) Str::uuid(), 'capacity_preset' => 'custom', ...$limits]);

        $bag(['max_count' => 40, 'max_weight_kg' => 'xf', 'max_volume_m3' => 0.3])->assertUnprocessable()->assertJsonValidationErrors('max_weight_kg');
        $bag(['max_count' => '4o', 'max_weight_kg' => 20, 'max_volume_m3' => 0.3])->assertUnprocessable()->assertJsonValidationErrors('max_count');
        $bag(['max_count' => 40, 'max_volume_m3' => 0.3])->assertUnprocessable()->assertJsonValidationErrors('max_weight_kg');
        $this->assertSame(0, MotherBag::count(), 'A refused size never becomes a standard bag.');

        $this->assertContains($bag(['max_count' => 40, 'max_weight_kg' => '12.5', 'max_volume_m3' => '0.25'])->status(), [200, 201, 302]);
        $bagModel = MotherBag::sole();
        $this->assertSame([40, 12.5, 250000], [(int) $bagModel->max_count, (float) $bagModel->max_weight_kg, (int) $bagModel->max_volume_cm3]);

        $this->actingAs($bagger)->postJson("/hub/mother-bags/{$bagModel->id}/add", ['version' => $bagModel->version, 'barcode' => $parcel->tracking_number,
            'handling' => ['weight_kg' => 'xf', 'length_cm' => 20, 'width_cm' => 20, 'height_cm' => 20, 'confirmed' => true]])
            ->assertUnprocessable()->assertJsonValidationErrors('handling.weight_kg');
        $this->assertSame(0, $bagModel->fresh()->parcels()->count());
    }
}

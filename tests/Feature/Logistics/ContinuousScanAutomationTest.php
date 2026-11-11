<?php

namespace Tests\Feature\Logistics;

use App\Services\Logistics\ContinuousScanAutomationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class ContinuousScanAutomationTest extends TestCase
{
    use CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    public static function automaticInputs(): array
    {
        return [['continuous', true], ['auto_advance', true], ['action', 'AUTO_PROGRESS'], ['scanned_by_id', 999], ['custody_after', ['kind' => 'hub']]];
    }

    #[DataProvider('automaticInputs')]
    public function test_inspection_cannot_be_changed_into_automatic_movement(string $field, mixed $value): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'picked_up');
        $hub = $parcel->originBayanHub;
        $before = [$parcel->fresh()->getAttributes(), $parcel->checkpoints()->count()];
        $this->actingAs($this->flowHandler($hub))->postJson('/hub/scan', ['barcode' => $parcel->tracking_number,
            'hub_id' => $hub->id, 'mode' => 'inspect', $field => $value])->assertUnprocessable();
        $this->assertSame($before, [$parcel->fresh()->getAttributes(), $parcel->checkpoints()->count()]);
    }

    public function test_deprecated_progression_service_cannot_invent_a_seal_or_advance_an_online_scan(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'picked_up');
        $hub = $parcel->originBayanHub;
        $actor = $this->flowHandler($hub);
        $service = app(ContinuousScanAutomationService::class);
        $this->assertFalse($service->canAutomate($parcel, $hub, $actor));
        $this->expectException(\DomainException::class);
        $service->executeAutomatedProgression($parcel, $hub, $actor);
    }

    public function test_intake_leaves_sorting_and_assignment_for_their_separate_actions(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'picked_up');
        $hub = $parcel->originBayanHub;
        $this->actingAs($this->flowHandler($hub));
        $input = ['barcode' => $parcel->tracking_number, 'hub_id' => $hub->id];
        $preview = $this->postJson('/hub/scan', [...$input, 'mode' => 'inspect'])->assertOk()->assertJsonPath('confirmed', false);
        $this->postJson('/hub/scan', [...$input, 'mode' => 'confirm', 'action' => $preview->json('prompt.action'),
            'expected_status' => $preview->json('prompt.expected_status')])->assertOk()->assertJsonPath('confirmed', true)
            ->assertJsonPath('delivery.can_auto_progress', false)->assertJsonPath('scan_evidence.latest_record.actor_role', 'logistics');
        $this->assertSame('arrived_at_origin_hub', $parcel->fresh()->status);
        $this->assertNull($parcel->fresh()->assigned_rider_id);
        $this->assertDatabaseCount('logistics_manifests', 0);
        $this->assertDatabaseCount('mother_bags', 0);
    }
}

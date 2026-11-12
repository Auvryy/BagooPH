<?php

namespace Tests\Feature\Logistics;

use App\Models\Delivery;
use App\Models\LogisticsFleet;
use App\Models\MotherBag;
use App\Services\Logistics\DispatchStagingService;
use App\Services\Logistics\FleetDispatchEngine;
use App\Services\Logistics\LogisticsManifestService;
use App\Services\Logistics\LogisticsRoutingEngine;
use App\Services\Logistics\MotherBagService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class DispatchStagingTest extends TestCase
{
    use CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    private function parcel(): Delivery
    {
        return $this->flowDelivery($this->newFlowOrder(), 'arrived_at_origin_hub');
    }

    private function vehicle(Delivery $parcel, float $capacity = 100): LogisticsFleet
    {
        $driver = $this->createApprovedUser('courier');
        $fleet = LogisticsFleet::create(['logistics_company_id' => $parcel->logistics_company_id,
            'hub_id' => $parcel->current_hub_id, 'vehicle_type' => 'l300_van', 'capacity_kg' => $capacity,
            'assigned_driver_id' => $driver->id, 'status' => 'active', 'plate_number' => 'QA-'.Str::upper(Str::random(6))]);
        $driver->courierProfile->update(['logistics_company_id' => $parcel->logistics_company_id,
            'assigned_hub_id' => $parcel->current_hub_id, 'vehicle_id' => $fleet->id, 'is_available' => true]);

        return $fleet;
    }

    private function pack(Delivery $parcel, float $weight = 7.125): MotherBag
    {
        return app(MotherBagService::class)->pack($this->flowHandler($parcel->currentHub), [
            'source_hub_id' => $parcel->current_hub_id, 'barcode' => $parcel->tracking_number, 'request_token' => (string) Str::uuid(),
            'handling' => ['weight_kg' => $weight, 'length_cm' => 20, 'width_cm' => 25, 'height_cm' => 30, 'confirmed' => true],
        ]);
    }

    public function test_staging_uses_retained_readings_and_only_sealed_cargo_enables_preparation_without_writes(): void
    {
        $parcel = $this->parcel();
        $vehicle = $this->vehicle($parcel);
        $bag = $this->pack($parcel);
        $service = app(DispatchStagingService::class);
        $before = [$parcel->checkpoints()->count(), $parcel->fresh()->getAttributes(), $bag->fresh()->getAttributes()];
        $lane = $service->corridors($parcel->currentHub)->sole();
        $this->assertSame(7.125, $lane['total_weight_kg']);
        $this->assertSame(0.015, $lane['total_cbm']);
        $this->assertSame(1, $lane['total_parcels']);
        $this->assertSame($vehicle->id, $lane['matched_vehicle']['id']);
        $this->assertFalse($lane['can_dispatch']);
        $this->assertSame($before, [$parcel->checkpoints()->count(), $parcel->fresh()->getAttributes(), $bag->fresh()->getAttributes()]);
        app(MotherBagService::class)->command($bag, $this->flowHandler($parcel->currentHub), 'seal', ['version' => $bag->version, 'seal_number' => 'QA-DISPATCH-SEAL']);
        $lane = $service->corridors($parcel->currentHub)->sole();
        $this->assertTrue($lane['can_dispatch']);
        $this->assertSame(1, $lane['sealed_bags_count']);
        $this->assertNull($lane['sizing']['max_cbm']);
        $this->assertDatabaseCount('logistics_manifests', 0);
    }

    public function test_unknown_multi_item_package_is_not_represented_as_a_measured_loose_parcel(): void
    {
        $parcel = $this->parcel();
        $this->vehicle($parcel);
        $lane = app(DispatchStagingService::class)->corridors($parcel->currentHub)->sole();
        $this->assertNull($lane['total_weight_kg']);
        $this->assertNull($lane['total_cbm']);
        $this->assertSame(1, $lane['unknown_readings_count']);
        $this->assertSame(0, $lane['loose_cargo_count']);
        $this->assertFalse($lane['can_dispatch']);
        $preview = app(FleetDispatchEngine::class)->previewMotherBagSlotting($parcel, $parcel->currentHub, $parcel->originMotherHub);
        $this->assertSame('readings_required', $preview['classification']);
        $this->assertDatabaseCount('mother_bags', 0);
    }

    public static function unavailable(): array
    {
        return [['other_hub'], ['wrong_type'], ['too_small'], ['no_driver'], ['unavailable'], ['suspended'], ['maintenance']];
    }

    #[DataProvider('unavailable')]
    public function test_vehicle_guidance_rejects_ineligible_fallbacks(string $reason): void
    {
        $parcel = $this->parcel();
        $vehicle = $this->vehicle($parcel);
        $bag = $this->pack($parcel);
        if ($reason === 'other_hub') {
            $vehicle->update(['hub_id' => $parcel->origin_mother_hub_id]);
        } elseif ($reason === 'wrong_type') {
            $vehicle->update(['vehicle_type' => 'wing_truck']);
        } elseif ($reason === 'too_small') {
            $vehicle->update(['capacity_kg' => 7.124]);
        } elseif ($reason === 'no_driver') {
            $vehicle->update(['assigned_driver_id' => null]);
        } elseif ($reason === 'unavailable') {
            $vehicle->driver->courierProfile->update(['is_available' => false]);
        } elseif ($reason === 'suspended') {
            $vehicle->driver->update(['status' => 'suspended']);
        } else {
            $vehicle->update(['status' => 'maintenance']);
        }
        $lane = app(DispatchStagingService::class)->corridors($parcel->currentHub)->sole();
        $this->assertNull($lane['matched_vehicle']);
        $this->assertFalse($lane['can_dispatch']);
        $this->assertSame('open', $bag->fresh()->status);
    }

    public function test_next_hop_uses_the_purchased_corridor_and_never_guesses_a_foreign_or_unknown_station(): void
    {
        $parcel = $this->parcel();
        $hub = $parcel->currentHub;
        $next = $parcel->originMotherHub;
        $parcel->origin_mother_hub_id = $parcel->destination_bayan_hub_id;
        $routing = app(LogisticsRoutingEngine::class);
        $this->assertSame($next->id, $routing->computeNextHop($parcel, $hub)['hub_id']);
        $this->assertSame($next->id, app(LogisticsManifestService::class)->nextHub($parcel));
        $hub->logistics_company_id = -1;
        $this->assertNull($routing->computeNextHop($parcel, $hub));
    }

    public function test_same_child_delivery_uses_local_shelf_without_a_transport_lane(): void
    {
        $seller = $this->createApprovedUser('seller');
        $shop = $seller->shop;
        $this->flowNetwork($shop, $shop->city);
        $order = $this->checkoutFlowOrder($this->createApprovedUser('buyer'), $shop, shipping: ['shipping_city' => $shop->city, 'shipping_postal_code' => '4030']);
        $parcel = $this->flowDelivery($order, 'arrived_at_destination_hub');
        $hop = app(LogisticsRoutingEngine::class)->computeNextHop($parcel, $parcel->currentHub);
        $this->assertSame($parcel->current_hub_id, $hop['hub_id']);
        $this->assertSame('BIN: BRGY-POBLACION-III', $hop['recommended_bin']);
        $this->assertCount(0, app(DispatchStagingService::class)->corridors($parcel->currentHub));
        $this->expectException(DomainException::class);
        app(LogisticsManifestService::class)->nextHub($parcel);
    }

    public function test_old_service_shortcuts_cannot_write_unmeasured_membership_or_automatic_departure(): void
    {
        $parcel = $this->parcel();
        $engine = app(FleetDispatchEngine::class);
        foreach (['slotIntoMotherBag', 'autoDispatchCorridor'] as $method) {
            try {
                $method === 'slotIntoMotherBag'
                    ? $engine->$method($parcel, $parcel->currentHub, $parcel->originMotherHub, $this->flowHandler($parcel->currentHub))
                    : $engine->$method($parcel->currentHub, $parcel->originMotherHub, $this->flowHandler($parcel->currentHub));
                $this->fail('An obsolete writer accepted a physical action without its command evidence.');
            } catch (DomainException) {
                $this->assertDatabaseCount('mother_bags', 0);
                $this->assertDatabaseCount('logistics_manifests', 0);
            }
        }
    }
}

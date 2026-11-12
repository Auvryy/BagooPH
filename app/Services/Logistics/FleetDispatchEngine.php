<?php

namespace App\Services\Logistics;

use App\Models\Delivery;
use App\Models\LogisticsHub;
use App\Models\MotherBag;
use App\Models\MotherBagParcel;
use App\Models\User;
use DomainException;
use Illuminate\Support\Collection;

class FleetDispatchEngine
{
    /**
     * Bagoo volumetric divisor; this synthetic preview never reprices a purchase.
     */
    public const DIM_DIVISOR = 3500;

    /**
     * Maximum baggable dimensions (cm) and single-parcel weight (kg).
     */
    public const MAX_BAGGABLE_LENGTH_CM = 60.0;

    public const MAX_BAGGABLE_WIDTH_CM = 60.0;

    public const MAX_BAGGABLE_HEIGHT_CM = 60.0;

    public const MAX_BAGGABLE_WEIGHT_KG = 15.0;

    /**
     * Standard Mother Bag capacity boundaries.
     */
    public const MOTHER_BAG_MAX_PARCELS = 50;

    public const MOTHER_BAG_MAX_WEIGHT_KG = 25.0;

    public const MOTHER_BAG_VOLUME_CBM = 0.30;

    /**
     * Vehicle specifications and nominal capacities.
     */
    public const VEHICLE_PROFILES = [
        'motorcycle' => [
            'name' => 'Motorcycle / Cargo Trike',
            'max_payload_kg' => 120.0,
            'max_cbm' => 0.6,
            'max_bags' => 1,
            'primary_role' => 'first_last_mile',
        ],
        'l300_van' => [
            'name' => 'L300 Cargo Van',
            'max_payload_kg' => 1000.0,
            'max_cbm' => 6.5,
            'max_bags' => 16,
            'primary_role' => 'feeder',
        ],
        'closed_van_4w' => [
            'name' => '4-Wheeler Closed Van',
            'max_payload_kg' => 2500.0,
            'max_cbm' => 14.0,
            'max_bags' => 35,
            'primary_role' => 'feeder_heavy',
        ],
        'wing_truck' => [
            'name' => '10-Wheeler Wing Van',
            'max_payload_kg' => 12000.0,
            'max_cbm' => 55.0,
            'max_bags' => 160,
            'primary_role' => 'line_haul',
        ],
    ];

    public function __construct(
        private readonly LogisticsRoutingEngine $routingEngine,
        private readonly LogisticsManifestService $manifestService,
        private readonly LogisticsEligibilityService $eligibility
    ) {}

    /**
     * Compute the DWS (Dimension, Weight, Scanning) profile of a parcel.
     */
    public function getDwsProfile(Delivery $delivery, ?LogisticsHub $currentHub = null): array
    {
        $order = $delivery->order;
        $items = $order?->items;

        // Determine parcel physical measurements from items or use standard express envelope defaults
        $itemCount = max(1, $items?->sum('quantity') ?? 1);
        $nominalWeightGrams = 450 * $itemCount;
        $actualWeightKg = round($nominalWeightGrams / 1000.0, 2);

        // Standard e-commerce carton/pouch dimensions
        $lengthCm = 25.0 + min(30.0, ($itemCount - 1) * 3.0);
        $widthCm = 18.0 + min(20.0, ($itemCount - 1) * 2.0);
        $heightCm = 6.0 + min(20.0, ($itemCount - 1) * 2.5);

        $volumetricWeightKg = round(($lengthCm * $widthCm * $heightCm) / self::DIM_DIVISOR, 2);
        $chargeableWeightKg = max($actualWeightKg, $volumetricWeightKg);
        $volumeCbm = round(($lengthCm * $widthCm * $heightCm) / 1000000.0, 4);

        $isOversized = ($lengthCm > self::MAX_BAGGABLE_LENGTH_CM)
            || ($widthCm > self::MAX_BAGGABLE_WIDTH_CM)
            || ($heightCm > self::MAX_BAGGABLE_HEIGHT_CM)
            || ($actualWeightKg > self::MAX_BAGGABLE_WEIGHT_KG);

        // Assign sorter chute according to next facility hop
        $hub = $currentHub ?? $delivery->currentHub ?? $delivery->originBayanHub;
        $nextHub = null;
        $chuteId = 'CHUTE-01';

        if ($hub) {
            $nextHop = $this->routingEngine->computeNextHop($delivery, $hub);
            $nextHub = isset($nextHop['hub_id']) ? LogisticsHub::find($nextHop['hub_id']) : null;
            $chuteId = $nextHop['recommended_bin'] ?? 'ROUTE-REVIEW';
        }

        return [
            'length_cm' => $lengthCm,
            'width_cm' => $widthCm,
            'height_cm' => $heightCm,
            'actual_weight_kg' => $actualWeightKg,
            'volumetric_weight_kg' => $volumetricWeightKg,
            'chargeable_weight_kg' => $chargeableWeightKg,
            'volume_cbm' => $volumeCbm,
            'is_oversized' => $isOversized,
            'is_baggable' => ! $isOversized,
            'chute' => $chuteId,
            'next_facility_name' => $nextHub?->name ?? 'Local Facility',
            'next_facility_code' => $nextHub?->code ?? 'LOCAL',
        ];
    }

    /**
     * Preview Mother Bag slotting for inspection without writing to database.
     */
    public function previewMotherBagSlotting(Delivery $delivery, LogisticsHub $currentHub, ?LogisticsHub $destinationHub = null): ?array
    {
        if (! $destinationHub || $delivery->current_hub_id !== $currentHub->id || app(HandoverHoldService::class)->held($delivery)) {
            return null;
        }
        try {
            if ($this->manifestService->nextHub($delivery) !== $destinationHub->id) {
                return null;
            }
        } catch (DomainException) {
            return null;
        }
        $cargo = app(CargoHandlingService::class);
        if (! $cargo->available()) {
            return ['bagged' => false, 'classification' => 'packing_unavailable',
                'reason' => 'Apply the existing additive packing migration before preparing this bag.'];
        }
        $member = MotherBagParcel::where('active_delivery_id', $delivery->id)->with('bag')->first();
        try {
            $profile = $member?->handling_snapshot ?? $cargo->profile($delivery, $currentHub->company->user);
        } catch (DomainException) {
            return ['bagged' => false, 'classification' => 'readings_required',
                'reason' => 'Enter actual outer-package readings in Mother Bags before packing.'];
        }
        if (! $profile['baggable']) {
            return ['bagged' => false, 'classification' => 'loose_cargo',
                'reason' => 'Load this package individually in Transfers & manifests.'];
        }
        $bag = $member?->bag;
        if (! $bag) {
            $lane = hash('sha256', $currentHub->logistics_company_id.':'.$currentHub->id.':'.$destinationHub->id);
            $bag = MotherBag::where('logistics_company_id', $currentHub->logistics_company_id)
                ->where('source_hub_id', $currentHub->id)->where('destination_hub_id', $destinationHub->id)
                ->where('automatic_lane', $lane)
                ->where('status', 'open')->whereNull('manifest_id')->orderBy('id')->get()->first(function ($candidate) use ($cargo, $profile) {
                    try {
                        $cargo->assertFits($candidate, $profile);

                        return true;
                    } catch (DomainException) {
                        return false;
                    }
                });
        }
        $capacity = $bag ? $cargo->capacity($bag) : ['count' => 0, 'weight_kg' => 0, 'volume_cm3' => 0];
        $count = $capacity['count'] + ($member ? 0 : 1);
        $weight = $capacity['weight_kg'] === null ? null : round($capacity['weight_kg'] + ($member ? 0 : $profile['weight_kg']), 3);

        return ['bagged' => $member !== null, 'classification' => 'mother_bag', 'mother_bag_id' => $bag?->id,
            'reference' => $bag?->reference, 'seal_number' => $bag?->seal_number, 'status' => $bag?->status ?? 'new_bag',
            'parcel_count' => $count, 'max_parcels' => CargoHandlingService::BAG_PARCELS,
            'capacity_pct' => round(100 * $count / CargoHandlingService::BAG_PARCELS, 1),
            'weight_kg' => $weight, 'estimated_weight_kg' => $weight, 'measurement_source' => $profile['source'],
            'reason' => $member ? 'Recorded bag membership.' : 'Suggested packing only. Scan the waybill in Mother Bags to save membership.'];
    }

    /**
     * Automatically allocate a parcel into an open Mother Bag for its outbound corridor,
     * or flag it as loose cargo if oversized.
     */
    public function slotIntoMotherBag(Delivery $delivery, LogisticsHub $currentHub, LogisticsHub $destinationHub, User $actor): array
    {
        throw new DomainException('Use the Mother Bags packing command with actual readings and a saved request token. Inspection cannot create membership or a seal.');
    }

    /**
     * Compute the real-time freight staging and vehicle sizing summary for all outbound
     * corridors departing from the specified hub.
     */
    public function corridorStagingSummary(LogisticsHub $hub): Collection
    {
        return app(DispatchStagingService::class)->corridors($hub);
    }

    /**
     * Find an available active fleet vehicle and assigned driver matching the required type.
     */
    public function matchAvailableFleetVehicle(LogisticsHub $hub, string $vehicleType): ?array
    {
        return app(DispatchStagingService::class)->vehicle($hub, $vehicleType);
    }

    /**
     * Fully automated Corridor Dispatch: seals open bags, creates manifest, binds optimal vehicle,
     * generates e-BOL, and produces the Digital QR Gate Pass.
     */
    public function autoDispatchCorridor(LogisticsHub $hub, LogisticsHub $destinationHub, User $actor, ?int $vehicleId = null): array
    {
        throw new DomainException('Prepare the actual manifest, load its cargo, verify the seal and record departure. No custody was changed.');
    }
}

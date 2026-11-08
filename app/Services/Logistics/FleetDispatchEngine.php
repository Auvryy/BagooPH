<?php

namespace App\Services\Logistics;

use App\Models\CourierProfile;
use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\LogisticsFleet;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifest;
use App\Models\LogisticsManifestParcel;
use App\Models\MotherBag;
use App\Models\MotherBagParcel;
use App\Models\User;
use App\Services\Logistics\OrderStateMachineService;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FleetDispatchEngine
{
    /**
     * Standard dimensional weight divisor (IATA / domestic express standard).
     */
    public const DIM_DIVISOR = 5000;

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
            $nextHub = $nextHop['next_hub'] ?? null;
            $chuteId = $nextHub ? ('CHUTE-'.str_pad((string) (($nextHub->id % 6) + 1), 2, '0', STR_PAD_LEFT)) : 'CHUTE-LOCAL';
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
        if (! $destinationHub) {
            return null;
        }

        $dws = $this->getDwsProfile($delivery, $currentHub);

        if ($dws['is_oversized']) {
            return [
                'bagged' => false,
                'classification' => 'loose_cargo',
                'reason' => 'Parcel exceeds Mother Bag dimensions or weight limit. Staged as loose freight.',
                'dws' => $dws,
            ];
        }

        $existing = MotherBagParcel::where('delivery_id', $delivery->id)
            ->whereNull('removed_at')
            ->whereHas('bag', fn ($q) => $q->where('status', '!=', 'opened'))
            ->with('bag')
            ->first();

        if ($existing) {
            $bag = $existing->bag;
            $parcelsCount = $bag->parcels()->whereNull('removed_at')->count();

            return [
                'bagged' => true,
                'classification' => 'mother_bag',
                'mother_bag_id' => $bag->id,
                'reference' => $bag->reference,
                'seal_number' => $bag->seal_number,
                'status' => $bag->status,
                'parcel_count' => $parcelsCount,
                'max_parcels' => self::MOTHER_BAG_MAX_PARCELS,
                'capacity_pct' => min(100, round(($parcelsCount / self::MOTHER_BAG_MAX_PARCELS) * 100, 1)),
                'estimated_weight_kg' => round($parcelsCount * 0.6, 2),
                'dws' => $dws,
            ];
        }

        $bag = MotherBag::where('logistics_company_id', $currentHub->logistics_company_id)
            ->where('source_hub_id', $currentHub->id)
            ->where('destination_hub_id', $destinationHub->id)
            ->where('status', 'open')
            ->first();

        $parcelsCount = $bag ? ($bag->parcels()->whereNull('removed_at')->count() + 1) : 1;
        $ref = $bag ? $bag->reference : ('PREVIEW-BAG-'.$currentHub->code.'-'.$destinationHub->code);

        return [
            'bagged' => true,
            'classification' => 'mother_bag',
            'mother_bag_id' => $bag?->id,
            'reference' => $ref,
            'seal_number' => $bag?->seal_number,
            'status' => $bag?->status ?? 'staging',
            'parcel_count' => $parcelsCount,
            'max_parcels' => self::MOTHER_BAG_MAX_PARCELS,
            'capacity_pct' => min(100, round(($parcelsCount / self::MOTHER_BAG_MAX_PARCELS) * 100, 1)),
            'estimated_weight_kg' => round($parcelsCount * 0.6, 2),
            'dws' => $dws,
        ];
    }

    /**
     * Automatically allocate a parcel into an open Mother Bag for its outbound corridor,
     * or flag it as loose cargo if oversized.
     */
    public function slotIntoMotherBag(Delivery $delivery, LogisticsHub $currentHub, LogisticsHub $destinationHub, User $actor): array
    {
        $dws = $this->getDwsProfile($delivery);

        if ($dws['is_oversized']) {
            return [
                'bagged' => false,
                'classification' => 'loose_cargo',
                'reason' => 'Parcel exceeds Mother Bag dimensions or weight limit. Staged as loose freight.',
                'dws' => $dws,
            ];
        }

        return DB::transaction(function () use ($delivery, $currentHub, $destinationHub, $actor, $dws) {
            // Check if already in an active bag
            $existing = MotherBagParcel::where('delivery_id', $delivery->id)
                ->whereNull('removed_at')
                ->whereHas('bag', fn ($q) => $q->where('status', '!=', 'opened'))
                ->with('bag')
                ->first();

            if ($existing) {
                $bag = $existing->bag;
                $parcelsCount = $bag->parcels()->whereNull('removed_at')->count();
                return [
                    'bagged' => true,
                    'classification' => 'mother_bag',
                    'mother_bag_id' => $bag->id,
                    'reference' => $bag->reference,
                    'seal_number' => $bag->seal_number,
                    'status' => $bag->status,
                    'parcel_count' => $parcelsCount,
                    'max_parcels' => self::MOTHER_BAG_MAX_PARCELS,
                    'capacity_pct' => min(100, round(($parcelsCount / self::MOTHER_BAG_MAX_PARCELS) * 100, 1)),
                    'estimated_weight_kg' => round($parcelsCount * 0.6, 2),
                    'dws' => $dws,
                ];
            }

            // Find or create an open Mother Bag for this specific corridor
            $bag = MotherBag::where('logistics_company_id', $currentHub->logistics_company_id)
                ->where('source_hub_id', $currentHub->id)
                ->where('destination_hub_id', $destinationHub->id)
                ->where('status', 'open')
                ->lockForUpdate()
                ->first();

            if (! $bag) {
                $ref = 'BAG-'.$currentHub->code.'-'.$destinationHub->code.'-'.date('ymd').'-'.strtoupper(Str::random(4));
                $bag = MotherBag::create([
                    'reference' => $ref,
                    'logistics_company_id' => $currentHub->logistics_company_id,
                    'source_hub_id' => $currentHub->id,
                    'destination_hub_id' => $destinationHub->id,
                    'created_by_id' => $actor->id,
                    'creation_token' => (string) Str::uuid(),
                    'status' => 'open',
                    'version' => 1,
                ]);
            }

            // Add parcel to Mother Bag
            $bag->parcels()->create([
                'delivery_id' => $delivery->id,
                'active_delivery_id' => $delivery->id,
                'loaded_by_id' => $actor->id,
                'barcode_scanned' => $delivery->tracking_number,
                'source_state' => DeliveryCheckpoint::state($delivery),
            ]);

            $parcelsCount = $bag->parcels()->whereNull('removed_at')->count();
            $estimatedWeightKg = round($parcelsCount * 0.6, 2);
            $autoSealTriggered = false;

            // Check auto-seal threshold (50 items or 25kg)
            if ($parcelsCount >= self::MOTHER_BAG_MAX_PARCELS || $estimatedWeightKg >= self::MOTHER_BAG_MAX_WEIGHT_KG) {
                $sealNumber = 'SL-'.$currentHub->code.'-'.date('ymd').'-'.strtoupper(Str::random(4));
                DB::table('mother_bag_seals')->insert([
                    'mother_bag_id' => $bag->id,
                    'logistics_company_id' => $currentHub->logistics_company_id,
                    'seal_number' => $sealNumber,
                ]);
                $bag->update([
                    'status' => 'sealed',
                    'seal_number' => $sealNumber,
                    'sealed_by_id' => $actor->id,
                    'sealed_at' => now(),
                    'version' => $bag->version + 1,
                ]);
                $autoSealTriggered = true;
            }

            return [
                'bagged' => true,
                'classification' => 'mother_bag',
                'mother_bag_id' => $bag->id,
                'reference' => $bag->reference,
                'seal_number' => $bag->seal_number,
                'status' => $bag->status,
                'parcel_count' => $parcelsCount,
                'max_parcels' => self::MOTHER_BAG_MAX_PARCELS,
                'capacity_pct' => min(100, round(($parcelsCount / self::MOTHER_BAG_MAX_PARCELS) * 100, 1)),
                'estimated_weight_kg' => $estimatedWeightKg,
                'auto_seal_triggered' => $autoSealTriggered,
                'dws' => $dws,
            ];
        });
    }

    /**
     * Compute the real-time freight staging and vehicle sizing summary for all outbound
     * corridors departing from the specified hub.
     */
    public function corridorStagingSummary(LogisticsHub $hub): Collection
    {
        // 1. Gather all destinations from company
        $destinations = LogisticsHub::where('logistics_company_id', $hub->logistics_company_id)
            ->where('id', '!=', $hub->id)
            ->get();

        $summaries = collect();

        foreach ($destinations as $dest) {
            // Count sealed Mother Bags ready for manifest
            $sealedBags = MotherBag::where('logistics_company_id', $hub->logistics_company_id)
                ->where('source_hub_id', $hub->id)
                ->where('destination_hub_id', $dest->id)
                ->where('status', 'sealed')
                ->whereNull('manifest_id')
                ->withCount(['parcels' => fn ($q) => $q->whereNull('removed_at')])
                ->get();

            // Count open Mother Bags still packing
            $openBags = MotherBag::where('logistics_company_id', $hub->logistics_company_id)
                ->where('source_hub_id', $hub->id)
                ->where('destination_hub_id', $dest->id)
                ->where('status', 'open')
                ->withCount(['parcels' => fn ($q) => $q->whereNull('removed_at')])
                ->get();

            // Count loose unbagged candidate parcels at this hub ready for next-hop transit
            $candidateParcels = Delivery::where('logistics_company_id', $hub->logistics_company_id)
                ->where('current_hub_id', $hub->id)
                ->whereNotIn('id', MotherBagParcel::whereNotNull('active_delivery_id')->select('delivery_id'))
                ->whereNotIn('id', LogisticsManifestParcel::whereNotNull('active_delivery_id')->select('delivery_id'))
                ->where(function ($q) {
                    $q->whereIn('status', [
                        OrderStateMachineService::STATUS_ARRIVED_AT_ORIGIN_HUB,
                        OrderStateMachineService::STATUS_SORTED_TO_LINE_HAUL,
                        OrderStateMachineService::STATUS_ARRIVED_AT_MOTHER_HUB,
                    ]);
                })
                ->get()
                ->filter(fn (Delivery $del) => $this->manifestService->nextHub($del) === $dest->id);

            $sealedBagsCount = $sealedBags->count();
            $openBagsCount = $openBags->count();
            $looseParcelsCount = $candidateParcels->count();

            $totalParcels = $sealedBags->sum('parcels_count') + $openBags->sum('parcels_count') + $looseParcelsCount;

            // Only report corridors that have freight or are direct parent/child connections
            if ($totalParcels === 0 && $dest->parent_hub_id !== $hub->id && $hub->parent_hub_id !== $dest->id) {
                continue;
            }

            // Estimate aggregate weight and CBM
            $bagWeightKg = ($sealedBagsCount + $openBagsCount) * 14.5;
            $looseWeightKg = $looseParcelsCount * 1.2;
            $totalWeightKg = round($bagWeightKg + $looseWeightKg, 1);

            $bagCbm = ($sealedBagsCount + $openBagsCount) * self::MOTHER_BAG_VOLUME_CBM;
            $looseCbm = $looseParcelsCount * 0.015;
            $totalCbm = round($bagCbm + $looseCbm, 2);

            // Determine leg type and optimal vehicle sizing
            $legType = ($hub->isMotherHub() && $dest->isMotherHub()) ? 'line_haul' : 'feeder';

            $vehicleSizing = $this->determineOptimalVehicleClass($legType, $totalWeightKg, $totalCbm, $sealedBagsCount + $openBagsCount);

            // Match available fleet vehicle at this hub or company
            $availableFleet = $this->matchAvailableFleetVehicle($hub, $vehicleSizing['required_type']);

            $summaries->push([
                'corridor_id' => $hub->id.'-'.$dest->id,
                'source_hub_id' => $hub->id,
                'destination_hub' => [
                    'id' => $dest->id,
                    'name' => $dest->name,
                    'code' => $dest->code,
                    'tier' => $dest->tier,
                    'city_municipality' => $dest->city_municipality,
                    'province' => $dest->province,
                ],
                'leg_type' => $legType,
                'sealed_bags_count' => $sealedBagsCount,
                'open_bags_count' => $openBagsCount,
                'loose_cargo_count' => $looseParcelsCount,
                'total_containers' => $sealedBagsCount + $openBagsCount + $looseParcelsCount,
                'total_parcels' => $totalParcels,
                'total_weight_kg' => $totalWeightKg,
                'total_cbm' => $totalCbm,
                'sizing' => $vehicleSizing,
                'matched_vehicle' => $availableFleet,
                'can_dispatch' => $totalParcels > 0 && ($availableFleet !== null),
            ]);
        }

        return $summaries->values();
    }

    /**
     * Determine optimal vehicle class based on physical constraints and leg rules.
     */
    protected function determineOptimalVehicleClass(string $legType, float $weightKg, float $cbm, int $bagCount): array
    {
        if ($legType === 'line_haul') {
            $profile = self::VEHICLE_PROFILES['wing_truck'];
            $payloadPct = min(100, round(($weightKg / $profile['max_payload_kg']) * 100, 1));
            $volumePct = min(100, round(($cbm / $profile['max_cbm']) * 100, 1));

            return [
                'required_type' => 'wing_truck',
                'vehicle_name' => $profile['name'],
                'max_payload_kg' => $profile['max_payload_kg'],
                'max_cbm' => $profile['max_cbm'],
                'payload_utilization_pct' => $payloadPct,
                'volume_utilization_pct' => $volumePct,
                'is_upgrade' => false,
                'recommendation_reason' => 'Line-haul heavy freight corridor (Mother Hub to Mother Hub).',
            ];
        }

        // Feeder transport: evaluate L300 Van vs 4-Wheeler / Heavy Wing Truck
        $l300 = self::VEHICLE_PROFILES['l300_van'];
        $fitsL300 = ($weightKg <= $l300['max_payload_kg']) && ($cbm <= $l300['max_cbm']) && ($bagCount <= $l300['max_bags']);

        if ($fitsL300) {
            $payloadPct = min(100, round(($weightKg / $l300['max_payload_kg']) * 100, 1));
            $volumePct = min(100, round(($cbm / $l300['max_cbm']) * 100, 1));

            return [
                'required_type' => 'l300_van',
                'vehicle_name' => $l300['name'],
                'max_payload_kg' => $l300['max_payload_kg'],
                'max_cbm' => $l300['max_cbm'],
                'payload_utilization_pct' => $payloadPct,
                'volume_utilization_pct' => $volumePct,
                'is_upgrade' => false,
                'recommendation_reason' => 'Standard municipal feeder run within L300 envelope.',
            ];
        }

        // Volume or weight exceeded L300 capacity -> auto-upgrade to 4-Wheeler or Heavy Wing Van
        $truck = self::VEHICLE_PROFILES['wing_truck'];
        $payloadPct = min(100, round(($weightKg / $truck['max_payload_kg']) * 100, 1));
        $volumePct = min(100, round(($cbm / $truck['max_cbm']) * 100, 1));

        return [
            'required_type' => 'wing_truck',
            'vehicle_name' => $truck['name'],
            'max_payload_kg' => $truck['max_payload_kg'],
            'max_cbm' => $truck['max_cbm'],
            'payload_utilization_pct' => $payloadPct,
            'volume_utilization_pct' => $volumePct,
            'is_upgrade' => true,
            'recommendation_reason' => 'Volume/weight spike exceeded L300 van capacity. Auto-upgraded to high-capacity linehaul.',
        ];
    }

    /**
     * Find an available active fleet vehicle and assigned driver matching the required type.
     */
    public function matchAvailableFleetVehicle(LogisticsHub $hub, string $vehicleType): ?array
    {
        $vehicle = LogisticsFleet::where('logistics_company_id', $hub->logistics_company_id)
            ->where('status', 'active')
            ->where('vehicle_type', $vehicleType)
            ->whereNotNull('assigned_driver_id')
            ->whereNotIn('id', LogisticsManifest::where('status', 'dispatched')->select('vehicle_id'))
            ->whereHas('driver.courierProfile', fn ($profile) => $profile->operational()->where('is_available', true))
            ->orderByRaw('CASE WHEN hub_id = ? THEN 0 ELSE 1 END', [$hub->id])
            ->first();

        // If exact vehicle_type not found, fallback to l300_van if wing_truck was requested or vice versa
        if (! $vehicle) {
            $fallbackType = ($vehicleType === 'wing_truck') ? 'l300_van' : 'wing_truck';
            $vehicle = LogisticsFleet::where('logistics_company_id', $hub->logistics_company_id)
                ->where('status', 'active')
                ->where('vehicle_type', $fallbackType)
                ->whereNotNull('assigned_driver_id')
                ->whereNotIn('id', LogisticsManifest::where('status', 'dispatched')->select('vehicle_id'))
                ->whereHas('driver.courierProfile', fn ($profile) => $profile->operational()->where('is_available', true))
                ->orderByRaw('CASE WHEN hub_id = ? THEN 0 ELSE 1 END', [$hub->id])
                ->first();
        }

        if (! $vehicle) {
            return null;
        }

        return [
            'id' => $vehicle->id,
            'plate_number' => $vehicle->plate_number,
            'vehicle_type' => $vehicle->vehicle_type,
            'model' => $vehicle->model,
            'capacity_kg' => $vehicle->capacity_kg,
            'driver_id' => $vehicle->assigned_driver_id,
            'driver_name' => $vehicle->driver?->name ?? 'Assigned Driver',
        ];
    }

    /**
     * Fully automated Corridor Dispatch: seals open bags, creates manifest, binds optimal vehicle,
     * generates e-BOL, and produces the Digital QR Gate Pass.
     */
    public function autoDispatchCorridor(LogisticsHub $hub, LogisticsHub $destinationHub, User $actor, ?int $vehicleId = null): array
    {
        return DB::transaction(function () use ($hub, $destinationHub, $actor, $vehicleId) {
            $this->eligibility->lockNetwork($hub->logistics_company_id, [$actor->id], [$hub->id, $destinationHub->id]);

            // 1. Gather all candidate open bags and auto-seal them
            $openBags = MotherBag::where('logistics_company_id', $hub->logistics_company_id)
                ->where('source_hub_id', $hub->id)
                ->where('destination_hub_id', $destinationHub->id)
                ->where('status', 'open')
                ->lockForUpdate()
                ->get();

            $seals = [];
            foreach ($openBags as $openBag) {
                $sealNumber = 'SL-'.$hub->code.'-'.date('ymd').'-'.strtoupper(Str::random(4));
                DB::table('mother_bag_seals')->insert([
                    'mother_bag_id' => $openBag->id,
                    'logistics_company_id' => $hub->logistics_company_id,
                    'seal_number' => $sealNumber,
                ]);
                $openBag->update([
                    'status' => 'sealed',
                    'seal_number' => $sealNumber,
                    'sealed_by_id' => $actor->id,
                    'sealed_at' => now(),
                    'version' => $openBag->version + 1,
                ]);
                $seals[] = $sealNumber;
            }

            // 2. Fetch all sealed bags for this corridor ready to load
            $sealedBags = MotherBag::where('logistics_company_id', $hub->logistics_company_id)
                ->where('source_hub_id', $hub->id)
                ->where('destination_hub_id', $destinationHub->id)
                ->where('status', 'sealed')
                ->whereNull('manifest_id')
                ->lockForUpdate()
                ->get();

            // 3. Fetch loose candidate parcels
            $looseDeliveries = Delivery::where('logistics_company_id', $hub->logistics_company_id)
                ->where('current_hub_id', $hub->id)
                ->whereNotIn('id', MotherBagParcel::whereNotNull('active_delivery_id')->select('delivery_id'))
                ->whereNotIn('id', LogisticsManifestParcel::whereNotNull('active_delivery_id')->select('delivery_id'))
                ->where(function ($q) {
                    $q->whereIn('status', [
                        OrderStateMachineService::STATUS_ARRIVED_AT_ORIGIN_HUB,
                        OrderStateMachineService::STATUS_SORTED_TO_LINE_HAUL,
                        OrderStateMachineService::STATUS_ARRIVED_AT_MOTHER_HUB,
                    ]);
                })
                ->lockForUpdate()
                ->get()
                ->filter(fn (Delivery $del) => $this->manifestService->nextHub($del) === $destinationHub->id);

            if ($sealedBags->isEmpty() && $looseDeliveries->isEmpty()) {
                throw new DomainException("No sealed bags or queued parcels available for transfer to {$destinationHub->name}.");
            }

            // 4. Vehicle Selection
            $legType = ($hub->isMotherHub() && $destinationHub->isMotherHub()) ? 'line_haul' : 'feeder';
            $fleetVehicle = null;

            if ($vehicleId) {
                $fleetVehicle = LogisticsFleet::where('logistics_company_id', $hub->logistics_company_id)
                    ->where('id', $vehicleId)
                    ->first();
            }

            if (! $fleetVehicle) {
                $matched = $this->matchAvailableFleetVehicle($hub, $legType === 'line_haul' ? 'wing_truck' : 'l300_van');
                if ($matched) {
                    $fleetVehicle = LogisticsFleet::find($matched['id']);
                }
            }

            if (! $fleetVehicle) {
                // Fallback to any active fleet with assigned driver
                $fleetVehicle = LogisticsFleet::where('logistics_company_id', $hub->logistics_company_id)
                    ->where('status', 'active')
                    ->whereNotNull('assigned_driver_id')
                    ->first();
            }

            if (! $fleetVehicle) {
                throw new DomainException("No active fleet vehicle or driver available at {$hub->name}. Check Fleet management.");
            }

            // 5. Create Logistics Manifest
            $manifestType = $this->manifestService->legType($hub, $destinationHub);
            $manifestToken = (string) Str::uuid();
            $manifest = LogisticsManifest::create([
                'reference' => 'MNF-'.strtoupper(Str::random(8)),
                'creation_token' => $manifestToken,
                'creation_fingerprint' => hash('sha256', $manifestToken),
                'logistics_company_id' => $hub->logistics_company_id,
                'source_hub_id' => $hub->id,
                'destination_hub_id' => $destinationHub->id,
                'vehicle_id' => $fleetVehicle->id,
                'driver_id' => $fleetVehicle->assigned_driver_id,
                'created_by_id' => $actor->id,
                'type' => $manifestType,
                'direction' => 'outbound',
                'status' => 'draft',
                'version' => 1,
            ]);

            // 6. Bind Mother Bags to manifest
            $allLoadedDeliveries = collect();
            foreach ($sealedBags as $bag) {
                $bag->update([
                    'manifest_id' => $manifest->id,
                    'loaded_by_id' => $actor->id,
                    'loaded_at' => now(),
                    'master_barcode_scanned' => $bag->reference,
                    'version' => $bag->version + 1,
                ]);

                if ($bag->seal_number && ! in_array($bag->seal_number, $seals, true)) {
                    $seals[] = $bag->seal_number;
                }

                $bagParcels = Delivery::whereIn('id', $bag->parcels()->whereNull('removed_at')->select('delivery_id'))->get();
                $allLoadedDeliveries = $allLoadedDeliveries->merge($bagParcels);
            }

            // Add loose deliveries
            $allLoadedDeliveries = $allLoadedDeliveries->merge($looseDeliveries)->unique('id');

            // 7. Load deliveries into manifest parcels & events
            $loadEvents = [];
            foreach ($allLoadedDeliveries as $parcel) {
                $manifestParcel = $manifest->parcels()->create([
                    'delivery_id' => $parcel->id,
                    'active_delivery_id' => $parcel->id,
                    'tracking_number_snapshot' => $parcel->tracking_number,
                    'route_snapshot' => $this->manifestService->route($parcel),
                    'source_state' => DeliveryCheckpoint::state($parcel),
                    'included' => true,
                ]);

                $loadScan = $manifest->events()->create([
                    'manifest_parcel_id' => $manifestParcel->id,
                    'actor_id' => $actor->id,
                    'event_type' => 'load',
                    'barcode_scanned' => $parcel->tracking_number,
                    'reference' => 'EVT-'.strtoupper(Str::random(10)),
                    'hub_id' => $hub->id,
                    'source_state' => DeliveryCheckpoint::state($parcel),
                    'target_state' => ['delivery_id' => $parcel->id],
                    'payload' => [
                        'delivery_id' => $parcel->id,
                        'manifest_id' => $manifest->id,
                    ],
                ]);
                $loadEvents[$parcel->id] = $loadScan;
            }

            // 8. Seal and Dispatch Manifest atomically
            $manifest->update([
                'status' => 'dispatched',
                'sealed_at' => now(),
                'dispatched_at' => now(),
                'dispatcher_id' => $actor->id,
                'version' => $manifest->version + 1,
            ]);

            // 9. Advance all parcel delivery statuses to in-transit
            $transitStatus = $this->manifestService->transitStatus($manifest);
            $orderStateMachine = app(OrderStateMachineService::class);
            foreach ($allLoadedDeliveries as $parcel) {
                $manifestParcel = $manifest->parcels()->where('delivery_id', $parcel->id)->first();
                $scanEvent = $manifest->events()->create([
                    'manifest_parcel_id' => $manifestParcel?->id,
                    'actor_id' => $actor->id,
                    'event_type' => 'parcel_dispatched',
                    'barcode_scanned' => $parcel->tracking_number,
                    'reference' => 'EVT-'.strtoupper(Str::random(10)),
                    'hub_id' => $hub->id,
                    'source_state' => DeliveryCheckpoint::state($parcel),
                    'target_state' => ['delivery_status' => $transitStatus],
                    'payload' => [
                        'delivery_status' => $transitStatus,
                        'source_scan_reference' => $loadEvents[$parcel->id]->reference,
                        'vehicle_plate' => $fleetVehicle->plate_number,
                        'vehicle_type' => $fleetVehicle->vehicle_type,
                    ],
                ]);

                $orderStateMachine->transition(
                    delivery: $parcel,
                    targetStatus: $transitStatus,
                    actor: $actor,
                    scanMetadata: [
                        'hub_id' => $hub->id,
                        'barcode' => $parcel->tracking_number,
                        'manifest_scan_event_id' => $scanEvent->id,
                        'manifest_number' => $manifest->reference,
                        'expected_status' => $parcel->status,
                        'location_name' => "{$hub->name} ({$hub->code})",
                        'facility_code' => $hub->code,
                        'notes' => "Dispatched on Automated Manifest #{$manifest->reference} to {$destinationHub->name}",
                        'destination_hub_id' => $destinationHub->id,
                    ]
                );
            }

            // 10. Generate Digital Gate Pass
            $totalWeightKg = round(($sealedBags->count() * 14.5) + ($looseDeliveries->count() * 1.2), 1);
            $capacityKg = (float) ($fleetVehicle->capacity_kg ?? 1200.0);
            $utilizationPct = min(100.0, round(($totalWeightKg / $capacityKg) * 100, 1));
            $gatePassCode = 'GP-'.date('Ymd').'-'.$manifest->id.'-'.strtoupper(Str::random(4));

            $gatePass = [
                'gate_pass_code' => $gatePassCode,
                'issued_at' => now()->toIso8601String(),
                'departure_gate' => 'Gate Bay '.((($hub->id + $destinationHub->id) % 4) + 1),
                'manifest_id' => $manifest->id,
                'manifest_reference' => $manifest->reference,
                'source_hub' => $hub->only(['id', 'name', 'code', 'tier']),
                'destination_hub' => $destinationHub->only(['id', 'name', 'code', 'tier']),
                'driver_name' => $fleetVehicle->driver?->name ?? 'Assigned Driver',
                'driver_phone' => $fleetVehicle->driver?->phone ?? 'N/A',
                'vehicle_plate' => $fleetVehicle->plate_number,
                'vehicle_type' => $fleetVehicle->vehicle_type,
                'total_mother_bags' => $sealedBags->count(),
                'total_loose_cargo' => $looseDeliveries->count(),
                'total_parcels_enclosed' => $allLoadedDeliveries->count(),
                'total_weight_kg' => $totalWeightKg,
                'vehicle_capacity_kg' => $capacityKg,
                'utilization_pct' => $utilizationPct,
                'seals' => $seals,
                'verification_hash' => hash('sha256', $manifest->reference.$gatePassCode.now()->toDateString()),
            ];

            return [
                'manifest' => $manifest,
                'gate_pass' => $gatePass,
                'mother_bags_count' => $sealedBags->count(),
                'parcels_count' => $allLoadedDeliveries->count(),
            ];
        });
    }
}

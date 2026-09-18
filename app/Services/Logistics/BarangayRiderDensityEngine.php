<?php

namespace App\Services\Logistics;

use App\Models\CourierProfile;
use App\Models\Delivery;
use App\Models\LogisticsHub;
use Carbon\Carbon;

class BarangayRiderDensityEngine
{
    /**
     * Threshold parcel capacity for a single dedicated barangay rider per day.
     */
    public const MAX_PARCELS_PER_RIDER = 60;

    /**
     * Calculate morning parcel volume density across covered barangays of a Bayan Hub.
     */
    public function calculateDensity(LogisticsHub $hub, ?Carbon $date = null): array
    {
        $targetDate = $date ?? Carbon::today();
        $coveredBarangays = $hub->coverage_barangays ?? [];

        // 1. Get deliveries arrived at destination bayan hub or ready for delivery on target date
        $deliveries = Delivery::where('destination_bayan_hub_id', $hub->id)
            ->whereIn('status', [
                OrderStateMachineService::STATUS_ARRIVED_AT_DEST_HUB,
                OrderStateMachineService::STATUS_SORTED_TO_BARANGAY_BIN,
                OrderStateMachineService::STATUS_OUT_FOR_DELIVERY,
            ])
            ->get();

        // 2. Map deliveries to barangays
        $volumeByBarangay = [];
        foreach ($deliveries as $del) {
            $brgy = $del->order?->destination_barangay ?? 'General Poblacion';
            $volumeByBarangay[$brgy] = ($volumeByBarangay[$brgy] ?? 0) + 1;
        }

        // 3. Query all riders assigned to this Hub
        $riders = CourierProfile::with('user')
            ->where('assigned_hub_id', $hub->id)
            ->where('is_available', true)
            ->get();

        $primaryRidersMap = [];
        $unassignedRiders = [];

        foreach ($riders as $rider) {
            if ($rider->assigned_barangay) {
                $primaryRidersMap[$rider->assigned_barangay] = $rider;
            } else {
                $unassignedRiders[] = $rider;
            }
        }

        // 4. Build density telemetry
        $allBarangays = array_unique(array_merge($coveredBarangays, array_keys($volumeByBarangay)));
        $densityReports = [];

        foreach ($allBarangays as $brgy) {
            $count = $volumeByBarangay[$brgy] ?? 0;
            $primaryRider = $primaryRidersMap[$brgy] ?? null;

            $densityLevel = 'normal';
            if ($count > self::MAX_PARCELS_PER_RIDER) {
                $densityLevel = 'critical_overflow';
            } elseif ($count >= 40) {
                $densityLevel = 'heavy';
            }

            $requiresAuxiliary = $count > self::MAX_PARCELS_PER_RIDER;
            $neededAuxiliaryCount = $requiresAuxiliary
                ? (int) ceil($count / self::MAX_PARCELS_PER_RIDER) - 1
                : 0;

            $densityReports[] = [
                'barangay' => $brgy,
                'parcel_count' => $count,
                'capacity_threshold' => self::MAX_PARCELS_PER_RIDER,
                'density_level' => $densityLevel,
                'primary_rider' => $primaryRider ? [
                    'id' => $primaryRider->user_id,
                    'name' => $primaryRider->user->name,
                    'phone' => $primaryRider->user->phone,
                    'vehicle' => $primaryRider->vehicle_type ?? 'Motorcycle',
                ] : null,
                'requires_auxiliary' => $requiresAuxiliary,
                'recommended_auxiliary_count' => $neededAuxiliaryCount,
                'alert_message' => $requiresAuxiliary
                    ? "ALERT: {$brgy} has {$count} packages (exceeds standard limit of " . self::MAX_PARCELS_PER_RIDER . "). Assign {$neededAuxiliaryCount} auxiliary rider(s)."
                    : null,
            ];
        }

        return [
            'hub' => [
                'id' => $hub->id,
                'name' => $hub->name,
                'code' => $hub->code,
                'city' => $hub->city_municipality,
            ],
            'date' => $targetDate->toDateString(),
            'total_inbound_parcels' => $deliveries->count(),
            'available_auxiliary_riders' => array_map(fn($r) => [
                'id' => $r->user_id,
                'name' => $r->user->name,
                'phone' => $r->user->phone,
            ], $unassignedRiders),
            'barangay_densities' => $densityReports,
        ];
    }
}

<?php

namespace App\Services\Logistics;

use App\Models\Delivery;
use App\Models\LogisticsFleet;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifest;
use App\Models\LogisticsManifestParcel;
use App\Models\MotherBag;
use App\Models\MotherBagParcel;
use DomainException;
use Illuminate\Support\Collection;

class DispatchStagingService
{
    public function __construct(private readonly LogisticsManifestService $manifests, private readonly CargoHandlingService $cargo) {}

    /** Read-only guidance. Preparing a trip never establishes sealing or departure. */
    public function corridors(LogisticsHub $hub): Collection
    {
        if (! LogisticsHub::eligible()->whereKey($hub->id)->exists()) {
            return collect();
        }
        $lanes = collect();
        $bags = MotherBag::where('logistics_company_id', $hub->logistics_company_id)->where('source_hub_id', $hub->id)
            ->whereIn('status', ['open', 'sealed'])->whereNull('manifest_id')->with('parcels.delivery.order')->get();
        foreach ($bags as $bag) {
            foreach ($bag->parcels->whereNull('removed_at') as $member) {
                $parcel = $member->delivery;
                $next = $this->next($parcel, $hub);
                // Stale membership is visible in its bag; it is not dispatchable freight.
                if ($next !== $bag->destination_hub_id) {
                    continue;
                }
                $lanes->push(['next' => $next, 'bag' => $bag->id, 'sealed' => $bag->status === 'sealed',
                    'profile' => $member->handling_snapshot]);
            }
        }
        $parcels = Delivery::where('logistics_company_id', $hub->logistics_company_id)->where('current_hub_id', $hub->id)
            ->whereIn('status', ['arrived_at_origin_hub', 'arrived_at_mother_hub', 'sorted_to_line_haul'])
            ->whereNotIn('id', MotherBagParcel::whereNotNull('active_delivery_id')->select('active_delivery_id'))
            ->whereNotIn('id', LogisticsManifestParcel::whereNotNull('active_delivery_id')->select('active_delivery_id'))
            ->with('order')->get();
        foreach ($parcels as $parcel) {
            if (! $next = $this->next($parcel, $hub)) {
                continue;
            }
            try {
                $profile = $this->cargo->profile($parcel, $hub->company->user);
            } catch (DomainException) {
                $profile = null;
            }
            $lanes->push(['next' => $next, 'bag' => null, 'sealed' => false, 'profile' => $profile]);
        }

        return $lanes->groupBy('next')->map(function (Collection $members, int $next) use ($hub) {
            $destination = LogisticsHub::findOrFail($next);
            $known = $members->every(fn ($member) => $member['profile'] !== null);
            $weight = $known ? round($members->sum(fn ($member) => $member['profile']['weight_kg']), 3) : null;
            $volume = $known ? $members->sum(fn ($member) => $member['profile']['volume_cm3']) / 1000000 : null;
            $sealed = $members->where('sealed', true)->pluck('bag')->unique()->count();
            $open = $members->where('sealed', false)->whereNotNull('bag')->pluck('bag')->unique()->count();
            $unpacked = $members->whereNull('bag');
            $loose = $unpacked->filter(fn ($member) => $member['profile'] && ! $member['profile']['baggable'])->count();
            $type = $this->manifests->legType($hub, $destination) === 'line_haul' ? 'wing_truck' : 'l300_van';
            $vehicle = $known ? $this->vehicle($hub, $type, $weight) : null;
            $blocker = ! $known ? 'Complete outer-package readings in Mother Bags or Transfers & manifests.'
                : (! $vehicle ? 'No available source-hub vehicle and driver can carry this corridor load. Review Fleet & drivers or split the load.'
                    : ($sealed + $loose === 0 ? 'Pack and seal the parcels in Mother Bags before loading a manifest.' : null));

            return ['corridor_id' => $hub->id.'-'.$next, 'source_hub_id' => $hub->id,
                'destination_hub' => $destination->only(['id', 'name', 'code', 'tier', 'city_municipality', 'province']),
                'leg_type' => $type === 'wing_truck' ? 'line_haul' : 'feeder',
                'sealed_bags_count' => $sealed, 'open_bags_count' => $open, 'loose_cargo_count' => $loose,
                'unpacked_count' => $unpacked->count(), 'unknown_readings_count' => $members->whereNull('profile')->count(),
                'total_containers' => $sealed + $open + $loose, 'total_parcels' => $members->count(),
                'total_weight_kg' => $weight, 'total_cbm' => $volume, 'measurement_basis' => 'retained_handling_or_purchased_single_package',
                'sizing' => ['required_type' => $type, 'vehicle_name' => $type === 'wing_truck' ? 'Wing truck' : 'Closed van',
                    'max_payload_kg' => $vehicle['capacity_kg'] ?? null, 'max_cbm' => null,
                    'payload_utilization_pct' => $vehicle && $weight !== null ? round(100 * $weight / $vehicle['capacity_kg'], 1) : null,
                    'volume_utilization_pct' => null, 'is_upgrade' => false, 'recommendation_reason' => 'Purchased next leg and configured kg capacity.'],
                'matched_vehicle' => $vehicle, 'can_dispatch' => $blocker === null, 'blocker' => $blocker];
        })->values();
    }

    private function next(Delivery $parcel, LogisticsHub $hub): ?int
    {
        if ($parcel->current_hub_id !== $hub->id || app(HandoverHoldService::class)->held($parcel)) {
            return null;
        }
        try {
            return $this->manifests->direction($parcel) === 'outbound' ? $this->manifests->nextHub($parcel) : null;
        } catch (DomainException) {
            return null;
        }
    }

    public function vehicle(LogisticsHub $hub, string $type, float $weightKg = 0): ?array
    {
        if (! in_array($type, ['l300_van', 'wing_truck'], true)) {
            return null;
        }
        $vehicles = LogisticsFleet::ready()->where('logistics_company_id', $hub->logistics_company_id)
            ->where('hub_id', $hub->id)->where('vehicle_type', $type)->whereNotNull('assigned_driver_id')
            ->where('capacity_kg', '>', 0)->where('capacity_kg', '>=', $weightKg)
            ->whereHas('driver.courierProfile', fn ($q) => $q->operational()->where('is_available', true))
            ->whereNotIn('id', LogisticsManifest::where(fn ($query) => $query->where('status', 'sealed')->orWhere(fn ($query) => $query->awaitingPhysicalReceipt()))->select('vehicle_id'))
            ->whereNotIn('assigned_driver_id', LogisticsManifest::where(fn ($query) => $query->where('status', 'sealed')->orWhere(fn ($query) => $query->awaitingPhysicalReceipt()))->select('driver_id'))
            ->with('driver')->orderBy('capacity_kg')->orderBy('id')->get();
        $vehicle = $vehicles->first(fn ($fleet) => ! Delivery::riderHasActiveWork($fleet->assigned_driver_id));

        return $vehicle ? ['id' => $vehicle->id, 'plate_number' => $vehicle->plate_number, 'vehicle_type' => $vehicle->vehicle_type,
            'model' => $vehicle->model, 'capacity_kg' => $vehicle->capacity_kg,
            'driver_id' => $vehicle->assigned_driver_id, 'driver_name' => $vehicle->driver->name] : null;
    }
}

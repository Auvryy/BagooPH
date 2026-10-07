<?php

namespace App\Services\Logistics;

use App\Models\CourierProfile;
use App\Models\Delivery;
use App\Models\HubHandler;
use App\Models\LogisticsCompany;
use App\Models\LogisticsFleet;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifest;
use App\Models\LogisticsPlacementRecord;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class LogisticsPlacementService
{
    public function __construct(private readonly LogisticsEligibilityService $eligibility) {}

    public function assignHandler(User $actor, LogisticsHub $hub, User $handler): HubHandler
    {
        return DB::transaction(function () use ($actor, $hub, $handler) {
            $company = $this->lockOwnedNetwork($actor, $hub, $handler);
            if (! User::eligibleLogisticsAccounts()->whereKey($handler->id)->exists()
                || LogisticsCompany::where('user_id', $handler->id)->whereKeyNot($company->id)->exists()
                || HubHandler::where('user_id', $handler->id)->where(fn ($query) => $query
                    ->whereNull('logistics_company_id')->orWhere('logistics_company_id', '!=', $company->id))->exists()) {
                throw new DomainException('Choose an approved logistics account belonging to this network or awaiting its first assignment.');
            }
            $assignment = HubHandler::where('user_id', $handler->id)->where('hub_id', $hub->id)->lockForUpdate()->first();
            if ($assignment) {
                if (! $assignment->is_active) {
                    throw new DomainException('An inactive handler assignment requires a separate reviewed reactivation.');
                }

                return $assignment;
            }
            $assignment = HubHandler::create(['user_id' => $handler->id, 'logistics_company_id' => $company->id, 'hub_id' => $hub->id, 'is_active' => true]);
            $this->record($actor, $handler, $company, 'handler', null, $assignment->only(['logistics_company_id', 'hub_id', 'is_active']));

            return $assignment;
        });
    }

    public function placeCourier(
        User $actor,
        LogisticsHub $hub,
        User $courier,
        ?int $vehicleId = null,
        ?string $barangay = null,
        ?int $expectedHubId = null,
    ): CourierProfile {
        return DB::transaction(function () use ($actor, $hub, $courier, $vehicleId, $barangay, $expectedHubId) {
            $previous = CourierProfile::where('user_id', $courier->id)->first();
            $company = $this->lockOwnedNetwork($actor, $hub, $courier, [$previous?->assigned_hub_id]);
            $hub = LogisticsHub::findOrFail($hub->id);
            if (! User::eligibleCouriers()->whereKey($courier->id)->exists()) {
                throw new DomainException('Only a currently approved and active courier can be placed in this network.');
            }
            $profile = CourierProfile::where('user_id', $courier->id)->lockForUpdate()->first();
            if (! $profile || ($profile->logistics_company_id && $profile->logistics_company_id !== $company->id)) {
                throw new DomainException('This courier is missing a profile or belongs to another logistics company.');
            }
            if ($profile->vehicle_id && ! $vehicleId) {
                throw new DomainException('Choose the current or another eligible fleet vehicle. Removing a fleet assignment requires separate review.');
            }
            $fleet = LogisticsFleet::whereIn('id', array_filter([$profile->vehicle_id, $vehicleId]))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $vehicle = $vehicleId ? $fleet->get($vehicleId) : null;
            if ($vehicleId && (! $vehicle || ! LogisticsFleet::ready()->whereKey($vehicleId)->exists()
                || $vehicle->logistics_company_id !== $company->id || $vehicle->hub_id !== $hub->id
                || ($vehicle->assigned_driver_id && $vehicle->assigned_driver_id !== $courier->id)
                || CourierProfile::where('vehicle_id', $vehicleId)->where('user_id', '!=', $courier->id)->exists())) {
                throw new DomainException('The selected vehicle is not available at this company facility.');
            }
            $barangay = filled($barangay) ? trim($barangay) : null;
            if ($barangay && (! $hub->isBayanHub() || ($hub->coverage_barangays && ! $hub->coversBarangay($barangay)))) {
                throw new DomainException('Choose an assigned barangay covered by the selected Bayan Hub.');
            }
            $before = $profile->only(['logistics_company_id', 'assigned_hub_id', 'vehicle_id', 'assigned_barangay']);
            $after = ['logistics_company_id' => $company->id, 'assigned_hub_id' => $hub->id, 'vehicle_id' => $vehicleId, 'assigned_barangay' => $barangay];
            if ($before === $after) {
                $this->eligibility->assertCourierScope($profile, $company->id, $hub->id);

                return $profile;
            }
            if ($profile->assigned_hub_id !== $expectedHubId) {
                throw new DomainException('The courier placement changed. Check its current facility before trying again.');
            }
            if (Delivery::riderHasActiveWork($courier->id) || LogisticsManifest::where('driver_id', $courier->id)->where('status', 'dispatched')->exists()) {
                throw new DomainException('Finish or recover the courier’s existing parcel custody before changing placement.');
            }
            $oldVehicle = $profile->vehicle_id ? $fleet->get($profile->vehicle_id) : null;
            if ($oldVehicle && ($oldVehicle->logistics_company_id !== $company->id || $oldVehicle->hub_id !== $profile->assigned_hub_id
                || $oldVehicle->assigned_driver_id !== $courier->id)) {
                throw new DomainException('The previous vehicle relationship needs review before changing placement.');
            }
            if ($oldVehicle && $oldVehicle->id !== $vehicleId) {
                $oldVehicle->update(['assigned_driver_id' => null]);
            }
            if ($vehicle) {
                $vehicle->update(['assigned_driver_id' => $courier->id]);
            }
            // A placement changes administrative scope only; the courier explicitly goes on duty later.
            $profile->fill([...$after, 'is_available' => false])->save();
            $this->eligibility->assertCourierScope($profile, $company->id, $hub->id);
            $this->record($actor, $courier, $company, 'courier', $before, $after);

            return $profile;
        });
    }

    private function lockOwnedNetwork(User $actor, LogisticsHub $hub, User $subject, array $previousHubs = []): LogisticsCompany
    {
        $currentHub = LogisticsHub::find($hub->id);
        if (! $currentHub) {
            throw new DomainException('The requested facility is unavailable.');
        }
        abort_unless($this->eligibility->isCompanyAdministrator($actor, $currentHub->logistics_company_id), 403, 'Only the owning company administrator can manage placement.');
        $company = $this->eligibility->lockNetwork($currentHub->logistics_company_id, [$actor->id, $subject->id], [$currentHub->id, ...$previousHubs]);
        abort_unless($company->user_id === $actor->id, 403);

        return $company;
    }

    private function record(User $actor, User $subject, LogisticsCompany $company, string $kind, ?array $before, array $after): void
    {
        LogisticsPlacementRecord::create([
            'logistics_company_id' => $company->id, 'actor_id' => $actor->id, 'user_id' => $subject->id,
            'kind' => $kind, 'before_state' => $before, 'after_state' => $after, 'recorded_at' => now(),
        ]);
    }
}

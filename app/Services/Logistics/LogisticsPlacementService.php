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
use App\Models\RiderCompanyConsent;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class LogisticsPlacementService
{
    public function __construct(private readonly LogisticsEligibilityService $eligibility) {}

    public function assignHandler(User $actor, LogisticsHub $hub, User $handler, string $jobRole = HubHandler::JOB_ROLE_ALL): HubHandler
    {
        if (! in_array($jobRole, HubHandler::JOB_ROLES, true)) {
            throw new DomainException('Choose a valid exclusive worker job role.');
        }

        return DB::transaction(function () use ($actor, $hub, $handler, $jobRole) {
            $company = $this->lockOwnedNetwork($actor, $hub, $handler);
            if (! User::eligibleLogisticsAccounts()->whereKey($handler->id)->exists()
                || LogisticsCompany::where('user_id', $handler->id)->exists()
                || HubHandler::where('user_id', $handler->id)->where(fn ($query) => $query
                    ->whereNull('logistics_company_id')->orWhere('logistics_company_id', '!=', $company->id))->exists()) {
                throw new DomainException('Choose an approved logistics account belonging to this network or awaiting its first assignment.');
            }
            $assignment = HubHandler::where('user_id', $handler->id)->where('hub_id', $hub->id)->lockForUpdate()->first();
            if ($assignment) {
                if (! $assignment->is_active) {
                    throw new DomainException('An inactive handler assignment requires a separate reviewed reactivation.');
                }
                if ($assignment->job_role !== $jobRole) {
                    $before = $assignment->only(['logistics_company_id', 'hub_id', 'is_active', 'job_role']);
                    $assignment->forceFill([
                        'job_role' => $jobRole,
                        'role_title' => HubHandler::roleTitleFor($jobRole),
                    ])->save();
                    $this->record($actor, $handler, $company, 'handler_role', $before, $assignment->only(['logistics_company_id', 'hub_id', 'is_active', 'job_role']));
                }

                return $assignment;
            }
            $assignment = HubHandler::create([
                'user_id' => $handler->id,
                'logistics_company_id' => $company->id,
                'hub_id' => $hub->id,
                'job_role' => $jobRole,
                'role_title' => HubHandler::roleTitleFor($jobRole),
                'is_active' => true,
            ]);
            $this->record($actor, $handler, $company, 'handler', null, $assignment->only(['logistics_company_id', 'hub_id', 'is_active', 'job_role']));

            return $assignment;
        });
    }

    public function updateHandlerJobRole(User $actor, HubHandler $assignment, string $jobRole): HubHandler
    {
        if (! in_array($jobRole, HubHandler::JOB_ROLES, true)) {
            throw new DomainException('Choose a single valid hub job role for this worker.');
        }

        return DB::transaction(function () use ($actor, $assignment, $jobRole) {
            $hub = LogisticsHub::eligible()->findOrFail($assignment->hub_id);
            $subject = User::findOrFail($assignment->user_id);
            $company = $this->lockOwnedNetwork($actor, $hub, $subject);
            $locked = HubHandler::whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            if (! $locked->is_active || $locked->logistics_company_id !== $company->id) {
                throw new DomainException('Only an active handler at this company facility can change job lock.');
            }
            $before = $locked->only(['logistics_company_id', 'hub_id', 'job_role']);
            if (($locked->job_role ?? HubHandler::JOB_ROLE_ALL) === $jobRole) {
                return $locked;
            }
            $locked->forceFill([
                'job_role' => $jobRole,
                'role_title' => HubHandler::roleTitleFor($jobRole),
            ])->save();
            $this->record($actor, $subject, $company, 'handler_role', $before, $locked->only(['logistics_company_id', 'hub_id', 'job_role']));

            return $locked;
        });
    }

    public function placeCourier(
        User $actor,
        LogisticsHub $hub,
        User $courier,
        ?int $vehicleId = null,
        ?string $barangay = null,
        ?int $expectedHubId = null,
        ?RiderCompanyConsent $consent = null,
    ): CourierProfile {
        return DB::transaction(function () use ($actor, $hub, $courier, $vehicleId, $barangay, $expectedHubId, $consent) {
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
            $barangay = filled($barangay) ? trim($barangay) : null;
            $acceptedSource = $profile->logistics_company_id === null
                ? app(RiderCompanyOfferService::class)->authorizePlacement($consent, $actor, $company, $courier, $hub, $vehicleId, $barangay)
                : null;
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
            if (Delivery::riderHasActiveWork($courier->id) || LogisticsManifest::awaitingPhysicalReceipt()->where('driver_id', $courier->id)->exists()) {
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
            $this->record($actor, $courier, $company, 'courier', $before, $after, $acceptedSource);

            return $profile;
        });
    }

    private function lockOwnedNetwork(User $actor, LogisticsHub $hub, User $subject, array $previousHubs = []): LogisticsCompany
    {
        $currentHub = LogisticsHub::find($hub->id);
        if (! $currentHub) {
            throw new DomainException('The requested facility is unavailable.');
        }
        // Each hub's full-access account runs its own people. Only the company owner (admin-like, never scanning)
        // can move people between hubs.
        $owner = $this->eligibility->isCompanyManager($actor, $currentHub->logistics_company_id);
        abort_unless($owner || $this->canManageOwnHubOnly($actor, $currentHub, $subject, $previousHubs), 403, 'Only this hub’s full-access account or the company owner can manage its team.');
        $company = $this->eligibility->lockNetwork($currentHub->logistics_company_id, [$actor->id, $subject->id], [$currentHub->id, ...array_filter($previousHubs)]);
        abort_unless($owner ? $this->eligibility->isCompanyManager($actor, $company->id) : $this->eligibility->canManageHubTeam($actor, $currentHub), 403);

        return $company;
    }

    /**
     * A hub operator manages only people who are new to the company or already belong to their own hub,
     * so one hub can never pull workers or riders away from a sibling hub.
     */
    private function canManageOwnHubOnly(User $actor, LogisticsHub $hub, User $subject, array $previousHubs): bool
    {
        if ($actor->id === $subject->id || ! $this->eligibility->canManageHubTeam($actor, $hub)) {
            return false;
        }
        if (collect($previousHubs)->filter()->contains(fn ($id) => (int) $id !== $hub->id)) {
            return false;
        }

        return ! HubHandler::where('user_id', $subject->id)->where('hub_id', '!=', $hub->id)->exists();
    }

    private function record(User $actor, User $subject, LogisticsCompany $company, string $kind, ?array $before, array $after, ?RiderCompanyConsent $consent = null): void
    {
        LogisticsPlacementRecord::create([
            'logistics_company_id' => $company->id, 'actor_id' => $actor->id, 'user_id' => $subject->id,
            'kind' => $kind, 'before_state' => $before, 'after_state' => $after, 'recorded_at' => $consent?->accepted_at ?? now(),
            'rider_company_consent_id' => $consent?->id,
        ]);
    }
}

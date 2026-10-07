<?php

namespace App\Services\Logistics;

use App\Models\CourierProfile;
use App\Models\Delivery;
use App\Models\HubHandler;
use App\Models\LogisticsCompany;
use App\Models\LogisticsFleet;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifest;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class LogisticsEligibilityService
{
    public function accessibleHubs(?User $actor): Builder
    {
        $query = LogisticsHub::query()->eligible()->with('company');
        $actor = $actor ? User::find($actor->id) : null;
        if (! $actor?->canAccessPortal() || (! $actor->isLogistics() && ! $actor->isAdmin())) {
            return $query->whereKey(0);
        }
        if ($actor->isAdmin()) {
            return $query;
        }
        $companyId = LogisticsCompany::where('user_id', $actor->id)->value('id');

        return $companyId
            ? $query->where('logistics_company_id', $companyId)
            : $query->whereIn('id', HubHandler::eligible()->where('user_id', $actor->id)->select('hub_id'));
    }

    public function hubContext(Request $request, bool $strict = false): array
    {
        $hubs = $this->accessibleHubs($request->user())->orderBy('tier')->orderBy('name')->orderBy('id')->get();
        $id = $request->input('hub_id') ?? $request->session()->get('active_hub_id');
        if ($id !== null) {
            $valid = (is_int($id) || is_string($id)) && preg_match('/\A[1-9][0-9]*\z/', (string) $id) === 1;
            $hub = $valid ? $hubs->firstWhere('id', $id) : null;
            if (! $hub) {
                abort_if($strict, 403, 'The selected facility is no longer available to this account.');

                return [null, $hubs->take(0)];
            }

            return [$hub, $hubs];
        }

        return [$hubs->first(), $hubs];
    }

    public function isCompanyAdministrator(User $actor, ?int $companyId = null): bool
    {
        return LogisticsCompany::eligible()->where('user_id', $actor->id)
            ->when($companyId !== null, fn (Builder $query) => $query->whereKey($companyId))->exists();
    }

    public function canScan(User $actor, LogisticsHub $hub): bool
    {
        return HubHandler::eligible()->where('user_id', $actor->id)->where('hub_id', $hub->id)->exists();
    }

    public function isOperational(?CourierProfile $profile): bool
    {
        return $profile && CourierProfile::operational()->whereKey($profile->id)->exists();
    }

    public function riderCandidates(?LogisticsHub $hub): Builder
    {
        $query = CourierProfile::operational()->with('user')->where('is_available', true);
        if (! $hub || ! LogisticsHub::eligible()->whereKey($hub->id)->where('tier', 'local_bayan_hub')->exists()) {
            return $query->whereKey(0);
        }

        $statuses = implode(',', array_fill(0, count(Delivery::RIDER_ACTIVE_STATUSES), '?'));
        $busy = fn () => Delivery::query()->whereRaw("deliveries.status in ({$statuses})", Delivery::RIDER_ACTIVE_STATUSES);

        return $query->where('logistics_company_id', $hub->logistics_company_id)->where('assigned_hub_id', $hub->id)
            ->whereNotIn('user_id', LogisticsManifest::where('status', 'dispatched')->select('driver_id'))
            ->whereNotIn('user_id', $busy()->whereNotNull('courier_id')->select('courier_id'))
            ->whereNotIn('user_id', $busy()->whereNotNull('assigned_rider_id')->select('assigned_rider_id'));
    }

    public function canReceivePickups(?CourierProfile $profile): bool
    {
        $profile = $profile ? CourierProfile::operational()->whereKey($profile->id)->where('is_available', true)->first() : null;
        if (! $profile || ! LogisticsHub::eligible()->whereKey($profile->assigned_hub_id)->where('tier', 'local_bayan_hub')->exists()
            || Delivery::activePickupCount($profile->user_id) >= Delivery::MAX_ACTIVE_PICKUPS_PER_RIDER
            || LogisticsManifest::where('driver_id', $profile->user_id)->where('status', 'dispatched')->exists()) {
            return false;
        }
        $statuses = implode(',', array_fill(0, count(Delivery::RIDER_ACTIVE_STATUSES), '?'));

        return ! Delivery::where('assigned_rider_id', $profile->user_id)
            ->whereRaw("deliveries.status in ({$statuses})", Delivery::RIDER_ACTIVE_STATUSES)->exists();
    }

    /**
     * Called inside the mutation transaction, after any order/parcel locks.
     * Lock accounts by ID, then company, hubs, profiles, and fleet in that order.
     */
    public function lockNetwork(int $companyId, array $accountIds = [], array $hubIds = []): LogisticsCompany
    {
        $ownerId = LogisticsCompany::whereKey($companyId)->value('user_id');
        User::whereIn('id', collect([...$accountIds, $ownerId])->filter()->unique()->sort()->values())
            ->orderBy('id')->lockForUpdate()->get();
        $company = LogisticsCompany::whereKey($companyId)->lockForUpdate()->first();
        if (! $company || $company->user_id !== $ownerId || ! LogisticsCompany::eligible()->whereKey($companyId)->exists()) {
            throw new DomainException('This logistics company is not currently eligible for operations.');
        }
        $ids = collect($hubIds)->filter()->unique()->sort()->values();
        $hubs = LogisticsHub::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        if ($hubs->count() !== $ids->count() || $hubs->contains(fn (LogisticsHub $hub) => $hub->logistics_company_id !== $companyId || ! LogisticsHub::eligible()->whereKey($hub->id)->exists())) {
            throw new DomainException('The working facility is not eligible for this logistics company.');
        }

        return $company;
    }

    public function assertCourierScope(?CourierProfile $profile, int $companyId, int $hubId, bool $newWork = false): void
    {
        if ($profile?->vehicle_id) {
            LogisticsFleet::whereKey($profile->vehicle_id)->lockForUpdate()->first();
        }
        if (! $profile || $profile->logistics_company_id !== $companyId || $profile->assigned_hub_id !== $hubId
            || ! $this->isOperational($profile) || ($newWork && (! $profile->is_available
                || LogisticsManifest::where('driver_id', $profile->user_id)->where('status', 'dispatched')->exists()))) {
            throw new DomainException('The rider, company, working facility, or assigned vehicle is not eligible for this operation.');
        }
    }

    public function assertBayanHub(int $hubId): void
    {
        if (! LogisticsHub::eligible()->whereKey($hubId)->where('tier', 'local_bayan_hub')->exists()) {
            throw new DomainException('Pickup and final-mile work require the expected active Bayan Hub.');
        }
    }
}

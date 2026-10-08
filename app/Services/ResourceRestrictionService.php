<?php

namespace App\Services;

use App\Models\CourierProfile;
use App\Models\HubHandler;
use App\Models\LogisticsCompany;
use App\Models\LogisticsFleet;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifestParcel;
use App\Models\Order;
use App\Models\RestrictionDecision;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ResourceRestrictionService
{
    public const TYPES = ['shop' => Shop::class, 'company' => LogisticsCompany::class,
        'hub' => LogisticsHub::class, 'handler' => HubHandler::class, 'fleet' => LogisticsFleet::class];

    public function __construct(private readonly AccountRestrictionService $decisions) {}

    public function currentActor(User $actor): User
    {
        $actor = User::find($actor->id);
        abort_unless($actor?->canAccessPortal() && ($actor->isAdmin() || ($actor->isLogistics()
            && LogisticsCompany::eligible()->where('user_id', $actor->id)->exists())), 403);

        return $actor;
    }

    public function types(User $actor): array
    {
        return $this->currentActor($actor)->isAdmin() ? array_keys(self::TYPES) : ['hub', 'handler', 'fleet'];
    }

    public function query(User $actor, string $type): Builder
    {
        $actor = $this->currentActor($actor);
        abort_unless(isset(self::TYPES[$type]), 404);
        $query = self::TYPES[$type]::query();
        if (! $actor->isAdmin()) {
            abort_unless(in_array($type, ['hub', 'handler', 'fleet'], true), 403);
            $query->where('logistics_company_id', LogisticsCompany::eligible()->where('user_id', $actor->id)->value('id'));
            if (in_array($type, ['handler', 'fleet'], true)) {
                $query->whereHas('hub', fn ($hub) => $hub->whereColumn('logistics_hubs.logistics_company_id', $query->getModel()->getTable().'.logistics_company_id'));
            }
            if ($type === 'fleet') {
                $query->where(fn ($driver) => $driver->whereNull('assigned_driver_id')->orWhereHas('driver.courierProfile', fn ($profile) => $profile
                    ->whereColumn('courier_profiles.logistics_company_id', 'logistics_fleet.logistics_company_id')));
            }
        }

        return $query;
    }

    public function find(User $actor, string $type, int $id): Model
    {
        $actor = $this->currentActor($actor);
        $resource = $this->query($actor, $type)->findOrFail($id);
        if (! $actor->isAdmin() && in_array($type, ['handler', 'fleet'], true)) {
            $hub = $this->hub($type, $resource);
            abort_if($hub && $hub->logistics_company_id !== $resource->logistics_company_id, 404);
            if ($type === 'fleet' && $resource->assigned_driver_id) {
                $profile = CourierProfile::where('user_id', $resource->assigned_driver_id)->first();
                abort_if($profile && $profile->logistics_company_id !== $resource->logistics_company_id, 404);
            }
        }

        return $resource;
    }

    public function summary(string $type, Model $resource): array
    {
        return ['id' => $resource->id, 'type' => $type, 'name' => $this->name($type, $resource),
            'status' => $this->status($type, $resource), 'parents' => $this->parents($type, $resource),
            'eligible' => $this->eligible($type, $resource)];
    }

    public function presentation(User $actor, string $type, int $id): array
    {
        $actor = $this->currentActor($actor);
        $resource = $this->find($actor, $type, $id);
        $state = $this->state($actor, $type, $resource);
        $token = $this->decisions->token($state);
        // Company oversight includes expected operational COD, never seller settlement records.
        if (! $actor->isAdmin()) {
            foreach ($state['work'] as &$work) {
                unset($work['cash']['ledger_id'], $work['cash']['ledger_state']);
            }
            unset($work);
        }

        return [...$this->summary($type, $resource), 'approval' => $type === 'shop' ? $resource->review_status : null,
            'source_token' => $token, 'version' => $resource->restriction_version,
            'actions' => $this->actions($type, $resource), 'state' => $state,
            'affected_work' => $state['work'], 'history' => $this->decisions->history($type, $id),
            'legacy_activity' => $resource->restriction_version === 0,
            'reactivation_status' => $type === 'fleet' ? $this->fleetRestoreStatus($resource) : 'active',
            'reactivation_mode_recorded' => $type === 'fleet' && $this->fleetRecordedMode($resource) !== null,
            'work_note' => $type === 'fleet'
                ? 'Rider assignments and recorded manifests identify linked work. Legacy feeder and linehaul labels are retained for review; a label alone does not prove which vehicle carried a parcel.'
                : ($type === 'handler' ? 'Current facility work and recorded scans need review. A facility assignment does not establish personal parcel or cash custody.' : null),
            'cash_note' => 'Retain recorded cash holders, handovers and unresolved differences. Payment labels and commission rows do not prove cash custody.',
        ];
    }

    public function decide(User $actor, string $type, int $id, array $input): RestrictionDecision
    {
        $this->find($actor, $type, $id);
        $data = $this->decisions->validate($input);
        Validator::make($input, array_fill_keys(['is_active', 'user_id', 'logistics_company_id', 'hub_id', 'assigned_driver_id',
            'root_category_id', 'review_status', 'review_decision_id', 'review_version', 'vehicle_type', 'plate_number'], 'prohibited'))->validate();

        return DB::transaction(function () use ($actor, $type, $id, $data) {
            $this->decisions->lockGuard();
            $actor = $this->currentActor($actor);
            $resource = $this->find($actor, $type, $id);
            $identity = $this->identity($type, $resource);
            $workIds = $this->ordersFor($actor, $type, $resource)->orderBy('id')->pluck('id');
            $this->decisions->lockWork($workIds);
            // Same order as account governance and custody writers: work, accounts, then resource scope.
            $this->lockScope($actor, $type, $resource);
            $actor = $this->currentActor($actor);
            $resource = $this->find($actor, $type, $id);
            $this->decisions->requireCurrent($identity === $this->identity($type, $resource), ['id' => $id], 'The resource parent or assignment changed. Reload its current scope.');
            $before = $this->state($actor, $type, $resource);
            $current = $before['subject'];
            $this->decisions->requireCurrent($workIds->all() === array_column($before['work'], 'order_id'), $current, 'Affected work changed. Reload and review its current responsibilities.', $this->actions($type, $resource));
            if ($existing = $this->decisions->retry($type, $id, $actor, $data, $current)) {
                return $existing;
            }
            $this->decisions->requireCurrent(hash_equals($this->decisions->token($before), $data['source_token']), $current, 'The resource, its parent or its work changed. Reload before deciding.', $this->actions($type, $resource));
            $this->decisions->requireCurrent(in_array($data['action'], $this->actions($type, $resource), true), $current, 'This action is not allowed from the current resource state.', $this->actions($type, $resource));
            if ($data['action'] === 'reactivate') {
                $this->assertReactivation($type, $resource);
            }
            $updates = ['restriction_version' => $resource->restriction_version + 1];
            $status = match ($data['action']) {
                'suspend' => 'suspended', 'deactivate' => 'inactive',
                'reactivate' => $type === 'fleet' ? $this->fleetRestoreStatus($resource) : 'active',
            };
            if (in_array($type, ['shop', 'company', 'fleet'], true)) {
                $updates['status'] = $status;
            }
            if (in_array($type, ['company', 'hub', 'handler'], true)) {
                $updates['is_active'] = $data['action'] === 'reactivate';
            }
            $resource->forceFill($updates)->save();

            $after = $this->state($actor, $type, $resource);
            // Boolean activity has no separate suspension field; its reasoned meaning comes from this event.
            $after['subject']['status'] = $status;

            return $this->decisions->record($type, $id, $actor, $data, $before, $after, $actor);
        }, 3);
    }

    public function actions(string $type, Model $resource): array
    {
        $status = $this->status($type, $resource);
        if (in_array($status, ['pending', 'idle', 'maintenance'], true)) {
            return ['suspend', 'deactivate'];
        }

        return $this->decisions->actions($status);
    }

    public function status(string $type, Model $resource): string
    {
        if (in_array($type, ['company', 'hub', 'handler'], true)
            && ! in_array($resource->getRawOriginal('is_active'), [true, false, 0, 1, '0', '1'], true)) {
            return 'unknown';
        }
        if ($type === 'company') {
            if (! in_array($resource->status, ['active', 'pending', 'inactive', 'suspended'], true)) {
                return 'unknown';
            }

            return $resource->status === 'active' && ! $resource->is_active ? 'inactive' : $resource->status;
        }
        if (in_array($type, ['hub', 'handler'], true)) {
            if ($resource->is_active) {
                return 'active';
            }
            $latest = RestrictionDecision::where('subject_type', $type)->where('subject_id', $resource->id)->latest('id')->first();

            return $latest?->action === 'suspend' && ($latest->after_state['subject']['restriction_version'] ?? null) === $resource->restriction_version ? 'suspended' : 'inactive';
        }

        return in_array($resource->status, ['active', 'inactive', 'suspended', ...($type === 'fleet' ? ['idle', 'maintenance'] : ['pending'])], true) ? $resource->status : 'unknown';
    }

    public function state(User $actor, string $type, Model $resource): array
    {
        $subject = $resource->only(match ($type) {
            'shop' => ['id', 'user_id', 'name', 'phone', 'address', 'city', 'root_category_id', 'review_status', 'review_decision_id', 'review_version', 'status', 'restriction_version'],
            'company' => ['id', 'user_id', 'name', 'code', 'status', 'is_active', 'restriction_version'],
            'hub' => ['id', 'logistics_company_id', 'tier', 'is_active', 'restriction_version'],
            'handler' => ['id', 'user_id', 'logistics_company_id', 'hub_id', 'is_active', 'restriction_version'],
            'fleet' => ['id', 'logistics_company_id', 'hub_id', 'assigned_driver_id', 'vehicle_type', 'plate_number', 'status', 'restriction_version'],
        });
        if (isset($subject['status'])) {
            $subject['resource_status'] = $subject['status'];
        }
        $subject['status'] = $this->status($type, $resource);
        $company = $this->company($type, $resource);
        $hub = $this->hub($type, $resource);
        $owner = $type === 'shop' ? User::find($resource->user_id) : $company?->user;
        $member = $type === 'handler' ? User::find($resource->user_id) : ($type === 'fleet' ? User::find($resource->assigned_driver_id) : null);

        return ['subject' => $subject,
            'reviewer_scope' => $actor->isAdmin() ? 'platform' : $company?->id,
            'owner' => $owner?->only(['id', 'role', 'status', 'kyc_status', 'kyc_reviewed_at', 'birthday', 'restriction_version']),
            'company' => $company?->only(['id', 'user_id', 'status', 'is_active', 'restriction_version']),
            'hub' => $hub?->only(['id', 'logistics_company_id', 'tier', 'is_active', 'restriction_version']),
            'member' => $member?->only(['id', 'role', 'status', 'kyc_status', 'birthday', 'restriction_version']),
            'profile' => $type === 'fleet' ? CourierProfile::where('user_id', $resource->assigned_driver_id)->first()?->only(['id', 'user_id', 'logistics_company_id', 'assigned_hub_id', 'vehicle_id', 'is_available']) : null,
            'parents' => $this->parents($type, $resource),
            'reviewed_scope' => $type === 'shop' ? app(ShopEligibilityService::class)->reviewedShops(Shop::whereKey($resource->id))->exists() : null,
            'work' => $this->decisions->work($this->ordersFor($actor, $type, $resource))];
    }

    public function ordersFor(User $actor, string $type, Model $resource): Builder
    {
        $orders = Order::query();
        if ($type === 'shop') {
            $orders->whereHas('items', fn ($item) => $item->where('shop_id', $resource->id));
        } else {
            $orders->where(function ($scope) use ($actor, $type, $resource) {
                $scope->whereHas('delivery', function ($parcel) use ($actor, $type, $resource) {
                    if (! $actor->isAdmin()) {
                        $parcel->where('logistics_company_id', $resource->logistics_company_id);
                    }
                    if ($type === 'company') {
                        $parcel->where('logistics_company_id', $resource->id);
                    } elseif ($type === 'hub') {
                        $parcel->where(fn ($q) => $this->atHub($q, $resource->id));
                    } elseif ($type === 'handler') {
                        $parcel->where('logistics_company_id', $resource->logistics_company_id)->where(fn ($q) => $q
                            ->where('current_hub_id', $resource->hub_id)->orWhereHas('checkpoints', fn ($scan) => $scan->where('hub_id', $resource->hub_id)->where('scanned_by_id', $resource->user_id)));
                    } else {
                        $drivers = CourierProfile::where('vehicle_id', $resource->id)->pluck('user_id')->push($resource->assigned_driver_id)->filter()->unique();
                        $parcel->where('logistics_company_id', $resource->logistics_company_id)->where(function ($q) use ($resource, $drivers) {
                            $q->whereIn('courier_id', $drivers)->orWhereIn('assigned_rider_id', $drivers)
                                ->orWhereIn('id', LogisticsManifestParcel::whereHas('manifest', fn ($manifest) => $manifest->where('vehicle_id', $resource->id))->select('delivery_id'));
                            if (in_array($resource->vehicle_type, ['l300_van', 'wing_truck'], true)) {
                                $q->orWhere(fn ($manifest) => $manifest
                                    ->where(fn ($m) => $m->whereNotNull('shuttle_manifest_number')->orWhereNotNull('truck_manifest_number'))
                                    ->when($resource->hub_id, fn ($m) => $m->where(fn ($h) => $this->atHub($h, $resource->hub_id))));
                            }
                        });
                    }
                });
                if ($type === 'hub') {
                    $scope->orWhere(fn ($pickup) => $pickup->where('pickup_hub_id', $resource->id)
                        ->when(! $actor->isAdmin(), fn ($q) => $q->whereHas('delivery', fn ($parcel) => $parcel->where('logistics_company_id', $resource->logistics_company_id))));
                }
                if (in_array($type, ['company', 'hub', 'handler'], true)) {
                    $scope->orWhereHas('codAccount', function ($cash) use ($actor, $type, $resource) {
                        if (! $actor->isAdmin()) {
                            $cash->where('logistics_company_id', $type === 'company' ? $resource->id : $resource->logistics_company_id);
                        }
                        if ($type === 'company') {
                            $cash->where('logistics_company_id', $resource->id);
                        } elseif ($type === 'hub') {
                            $cash->where('hub_id', $resource->id);
                        } else {
                            $cash->where(fn ($parties) => $parties->where('collector_id', $resource->user_id)->orWhereHas('events', fn ($events) => $events
                                ->where(fn ($party) => $party->where('from_user_id', $resource->user_id)->orWhere('to_user_id', $resource->user_id))));
                        }
                    });
                }
            });
        }

        return $this->decisions->openOrUnverifiedCash($orders);
    }

    private function atHub(Builder $query, int $id): void
    {
        $query->where('current_hub_id', $id);
        foreach (['origin_bayan_hub_id', 'origin_mother_hub_id', 'destination_mother_hub_id', 'destination_bayan_hub_id'] as $field) {
            $query->orWhere($field, $id);
        }
    }

    private function identity(string $type, Model $resource): array
    {
        return [$resource->only(['id', 'user_id', 'logistics_company_id', 'hub_id', 'assigned_driver_id']),
            $this->company($type, $resource)?->user_id,
            $type === 'fleet' ? CourierProfile::where('user_id', $resource->assigned_driver_id)->first()?->only(['id', 'logistics_company_id', 'assigned_hub_id', 'vehicle_id']) : null];
    }

    private function lockScope(User $actor, string $type, Model $resource): void
    {
        $company = $this->company($type, $resource);
        $companies = collect([$company?->id, $resource->logistics_company_id,
            $actor->isAdmin() ? null : LogisticsCompany::eligible()->where('user_id', $actor->id)->value('id')])->filter()->unique();
        $accounts = [$actor->id, $company?->user_id, $type === 'shop' || $type === 'handler' ? $resource->user_id : null, $type === 'fleet' ? $resource->assigned_driver_id : null];
        User::whereIn('id', array_filter($accounts))->orderBy('id')->lockForUpdate()->get();
        LogisticsCompany::whereIn('id', $companies)->orderBy('id')->lockForUpdate()->get();
        LogisticsHub::whereIn('logistics_company_id', $companies)->orderBy('id')->lockForUpdate()->get();
        CourierProfile::whereIn('logistics_company_id', $companies)->orderBy('id')->lockForUpdate()->get();
        HubHandler::whereIn('logistics_company_id', $companies)->orderBy('id')->lockForUpdate()->get();
        LogisticsFleet::whereIn('logistics_company_id', $companies)->orderBy('id')->lockForUpdate()->get();
        if ($type === 'shop') {
            Shop::whereKey($resource->id)->lockForUpdate()->first();
            app(ShopEligibilityService::class)->lockCategories();
        }
    }

    private function assertReactivation(string $type, Model $resource): void
    {
        $company = $this->company($type, $resource);
        $hub = $this->hub($type, $resource);
        $valid = match ($type) {
            'shop' => $resource->user?->isSeller() && $resource->user->canAccessPortal()
                && app(ShopEligibilityService::class)->reviewedShops(Shop::whereKey($resource->id))->exists(),
            'company' => $resource->user?->isLogistics() && $resource->user->canAccessPortal()
                && $resource->user->logisticsCompany()->count() === 1,
            'hub' => $company && LogisticsCompany::eligible()->whereKey($company->id)->exists()
                && in_array($resource->tier, ['local_bayan_hub', 'regional_mother_hub'], true),
            'handler' => $hub && LogisticsHub::eligible()->whereKey($hub->id)->exists()
                && $hub->logistics_company_id === $resource->logistics_company_id
                && $resource->user?->isLogistics() && $resource->user->canAccessPortal(),
            'fleet' => $hub && LogisticsHub::eligible()->whereKey($hub->id)->exists()
                && $hub->logistics_company_id === $resource->logistics_company_id
                && in_array($resource->vehicle_type, ['motorcycle', 'tricycle', 'l300_van', 'wing_truck'], true)
                && $this->validDriver($resource),
        };
        if (! $valid) {
            throw ValidationException::withMessages(['action' => 'Reactivation needs eligible reviewed parents and a valid resource scope. Separate child restrictions and rider duty stay in place.']);
        }
    }

    private function validDriver(LogisticsFleet $fleet): bool
    {
        if (! $fleet->assigned_driver_id) {
            return true;
        }
        $driver = User::find($fleet->assigned_driver_id);
        $profile = CourierProfile::where('user_id', $fleet->assigned_driver_id)->first();

        return $driver?->isEligibleCourier() && $profile && $profile->logistics_company_id === $fleet->logistics_company_id
            && $profile->assigned_hub_id === $fleet->hub_id && $profile->vehicle_id === $fleet->id;
    }

    private function fleetRestoreStatus(LogisticsFleet $fleet): string
    {
        return $this->fleetRecordedMode($fleet) ?? 'active';
    }

    private function fleetRecordedMode(LogisticsFleet $fleet): ?string
    {
        foreach (RestrictionDecision::where('subject_type', 'fleet')->where('subject_id', $fleet->id)->orderByDesc('id')->get() as $decision) {
            $status = $decision->before_state['subject']['resource_status'] ?? null;
            if (in_array($status, ['active', 'idle', 'maintenance'], true)) {
                return $status;
            }
        }

        return null;
    }

    private function name(string $type, Model $resource): string
    {
        return match ($type) {
            'handler' => ($resource->user?->name ?? 'Missing account').' — handler assignment #'.$resource->id,
            'fleet' => $resource->plate_number,
            default => $resource->name,
        };
    }

    private function company(string $type, Model $resource): ?LogisticsCompany
    {
        return $type === 'company' ? $resource : ($type === 'shop' ? null : LogisticsCompany::find($resource->logistics_company_id));
    }

    private function hub(string $type, Model $resource): ?LogisticsHub
    {
        return $type === 'hub' ? $resource : (in_array($type, ['handler', 'fleet'], true) ? LogisticsHub::find($resource->hub_id) : null);
    }

    private function eligible(string $type, Model $resource): bool
    {
        return $type === 'fleet' ? LogisticsFleet::ready()->whereKey($resource->id)->exists()
            : self::TYPES[$type]::eligible()->whereKey($resource->id)->exists();
    }

    private function parents(string $type, Model $resource): array
    {
        $company = $this->company($type, $resource);
        $hub = $this->hub($type, $resource);
        $owner = $type === 'shop' ? User::find($resource->user_id) : $company?->user;
        $parents = [['label' => $type === 'shop' ? 'Seller account' : 'Company owner', 'name' => $owner?->name ?? 'Missing account',
            'status' => $owner?->status ?? 'unknown', 'eligible' => $owner && $owner->canAccessPortal()
                && ($type === 'shop' ? $owner->isSeller() : $owner->isLogistics())]];
        if ($type !== 'shop' && $type !== 'company') {
            $parents[] = ['label' => 'Company', 'name' => $company?->name ?? 'Missing company', 'status' => $company ? $this->status('company', $company) : 'unknown',
                'eligible' => $company && LogisticsCompany::eligible()->whereKey($company->id)->exists()];
        }
        if ($type !== 'hub' && in_array($type, ['handler', 'fleet'], true)) {
            $parents[] = ['label' => 'Assigned facility', 'name' => $hub?->name ?? 'Missing facility', 'status' => $hub ? $this->status('hub', $hub) : 'unknown',
                'eligible' => $hub && $hub->logistics_company_id === $resource->logistics_company_id && LogisticsHub::eligible()->whereKey($hub->id)->exists()];
        }
        if ($type === 'handler' || ($type === 'fleet' && $resource->assigned_driver_id)) {
            $member = User::find($type === 'handler' ? $resource->user_id : $resource->assigned_driver_id);
            $parents[] = ['label' => $type === 'handler' ? 'Handler account' : 'Assigned driver', 'name' => $member?->name ?? 'Missing account',
                'status' => $member?->status ?? 'unknown', 'eligible' => $type === 'handler'
                    ? $member && $member->isLogistics() && $member->canAccessPortal() : $this->validDriver($resource)];
        }

        return $parents;
    }

    public function hasHistory(Model $resource): bool
    {
        $type = array_search($resource::class, self::TYPES, true);
        if ($type === false) {
            return false;
        }
        $query = RestrictionDecision::where(fn ($q) => $q->where('subject_type', $type)->where('subject_id', $resource->id));
        if ($type === 'company' || $type === 'hub') {
            foreach (['hub', 'handler', 'fleet'] as $childType) {
                if ($type === 'hub' && $childType === 'hub') {
                    continue;
                }
                $children = self::TYPES[$childType]::where($type === 'company' ? 'logistics_company_id' : 'hub_id', $resource->id)->select('id');
                $query->orWhere(fn ($q) => $q->where('subject_type', $childType)->whereIn('subject_id', $children));
            }
        }

        return $query->exists();
    }

    public function ownerHasHistory(User $owner): bool
    {
        return Shop::where('user_id', $owner->id)->get()->contains(fn ($shop) => $this->hasHistory($shop))
            || LogisticsCompany::where('user_id', $owner->id)->get()->contains(fn ($company) => $this->hasHistory($company))
            || HubHandler::where('user_id', $owner->id)->get()->contains(fn ($handler) => $this->hasHistory($handler));
    }
}

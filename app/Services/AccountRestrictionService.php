<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\CourierProfile;
use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\HubHandler;
use App\Models\LogisticsCompany;
use App\Models\LogisticsFleet;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifest;
use App\Models\LogisticsManifestParcel;
use App\Models\Order;
use App\Models\RestrictionAffectedWork;
use App\Models\RestrictionDecision;
use App\Models\Shop;
use App\Models\User;
use App\Rules\ApplicationText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AccountRestrictionService
{
    public function validate(array $input): array
    {
        if (is_string($input['reason'] ?? null)) {
            $input['reason'] = trim(\Normalizer::normalize($input['reason'], \Normalizer::FORM_KC), ' ');
        }

        return Validator::make($input, [
            'action' => 'required|in:suspend,deactivate,reactivate',
            'source_token' => 'required|string|regex:/\A[a-f0-9]{64}\z/',
            'reason' => ['bail', 'required', 'string', new ApplicationText('notes', 5, 1000)],
            'affected_work_confirmed' => 'required|accepted',
            'role' => 'prohibited', 'status' => 'prohibited', 'kyc_status' => 'prohibited',
            'is_available' => 'prohibited', 'actor_id' => 'prohibited', 'restriction_version' => 'prohibited',
        ])->validate();
    }

    public function token(array $state): string
    {
        return hash_hmac('sha256', json_encode($state, JSON_THROW_ON_ERROR), config('app.key'));
    }

    public function currentActor(User $actor): User
    {
        $actor = User::find($actor->id);
        abort_unless($actor?->isAdmin() && $actor->canAccessPortal(), 403);

        return $actor;
    }

    public function presentation(User $actor, User $subject): array
    {
        $this->currentActor($actor);
        $subject = User::findOrFail($subject->id);
        $state = $this->state($subject);

        return [
            'type' => 'account', 'id' => $subject->id, 'name' => $subject->name,
            'role' => $subject->role, 'status' => $subject->status, 'approval' => $subject->kyc_status,
            'source_token' => $this->token($state), 'version' => $subject->restriction_version,
            'actions' => $subject->closed_at === null ? $this->actions($subject->status) : [], 'state' => $state,
            'affected_work' => $state['work'], 'history' => $this->history('account', $subject->id),
            'legacy_activity' => $subject->restriction_version === 0,
            'cash_note' => 'Cash custody and reconciliation are not recorded yet. Payment labels and commission rows do not prove who holds money.',
        ];
    }

    public function history(string $type, int $id): array
    {
        return RestrictionDecision::where('subject_type', $type)->where('subject_id', $id)
            ->withCount('affectedWork')->orderByDesc('id')->get()->map(fn ($decision) => [
                'id' => $decision->id, 'action' => $decision->action, 'reason' => $decision->reason,
                'actor' => $decision->actor_name, 'decided_at' => $decision->decided_at->toISOString(),
                'before_status' => $decision->before_state['subject']['status'],
                'after_status' => $decision->after_state['subject']['status'],
                'affected_work_count' => $decision->affected_work_count,
            ])->all();
    }

    public function actions(?string $status): array
    {
        return match ($status) {
            'active', 'pending_approval' => ['suspend', 'deactivate'],
            'suspended' => ['deactivate', 'reactivate'],
            'inactive' => ['suspend', 'reactivate'],
            default => [],
        };
    }

    public function decide(User $actor, User $subject, array $input): RestrictionDecision
    {
        $this->currentActor($actor);
        $data = $this->validate($input);

        return DB::transaction(function () use ($actor, $subject, $data) {
            $this->lockGuard();
            $subject = User::findOrFail($subject->id);
            $workIds = $this->ordersFor($subject)->orderBy('id')->pluck('id');
            $this->lockWork($workIds);
            $parents = $this->parentAccountIds($subject);
            // Existing custody/receipt writers use Order -> Delivery -> User; never reverse those locks.
            $users = User::where(fn ($q) => $q->whereIn('id', [...$parents, $actor->id, $subject->id])->orWhere('role', 'admin'))
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $actor = $users->get($actor->id);
            $subject = $users->get($subject->id);
            abort_unless($actor?->isAdmin() && $actor->canAccessPortal(), 403);
            abort_unless($subject && UserRole::tryFrom($subject->role), 409, 'This account has an unknown role and needs controlled review.');
            abort_unless($subject->closed_at === null, 409, 'A closed account remains inactive. Reopening requires a separate policy.');
            $this->lockScope($subject);
            $currentIds = $this->ordersFor($subject)->orderBy('id')->pluck('id');
            $this->requireCurrent($workIds->all() === $currentIds->all(), $subject->only(['id', 'status']), 'Affected work changed. Reload and review its current responsibilities.');
            $before = $this->state($subject);
            if ($existing = $this->retry('account', $subject->id, $actor, $data, $before['subject'])) {
                return $existing;
            }
            $this->requireCurrent(hash_equals($this->token($before), $data['source_token']), $before['subject'], 'The account, its scope or its work changed. Reload before deciding.');
            $this->requireCurrent(in_array($data['action'], $this->actions($subject->status), true), $before['subject'], 'This action is not allowed from the current account state.');
            $status = match ($data['action']) {
                'suspend' => 'suspended', 'deactivate' => 'inactive', 'reactivate' => 'active'
            };
            if ($status === 'active') {
                $this->assertReactivation($subject);
            }
            $eligibleAdmins = $users->filter(fn ($user) => $user->isAdmin() && $user->canAccessPortal());
            if ($subject->isAdmin() && $subject->canAccessPortal() && $status !== 'active') {
                $this->requireCurrent($eligibleAdmins->count() > 1, $before['subject'], 'The last eligible Platform Admin cannot be restricted. Arrange a controlled replacement first.');
            }
            $responsible = $actor->id === $subject->id && $status !== 'active'
                ? $eligibleAdmins->first(fn ($user) => $user->id !== $subject->id) : $actor;
            $subject->forceFill(['status' => $status, 'restriction_version' => $subject->restriction_version + 1])->save();

            return $this->record('account', $subject->id, $actor, $data, $before, $this->state($subject), $responsible);
        }, 3);
    }

    public function lockGuard(): void
    {
        abort_unless(DB::table('governance_guards')->where('name', 'platform-admin-continuity')->lockForUpdate()->first(), 503, 'The governance migration must be applied before decisions.');
    }

    public function lockWork(Collection $ids): void
    {
        Order::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        Delivery::whereIn('order_id', $ids)->orderBy('id')->lockForUpdate()->get();
    }

    public function requireCurrent(bool $condition, array $current, string $message, ?array $actions = null): void
    {
        if (! $condition) {
            throw new HttpResponseException(response()->json([
                'message' => $message, 'current_state' => $current,
                'permitted_actions' => $actions ?? $this->actions($current['status'] ?? null),
            ], 409));
        }
    }

    public function retry(string $type, int $id, User $actor, array $data, array $current): ?RestrictionDecision
    {
        $existing = RestrictionDecision::where('subject_type', $type)->where('subject_id', $id)->where('source_token', $data['source_token'])->first();
        if ($existing) {
            $this->requireCurrent($existing->actor_id === $actor->id && $existing->action === $data['action'] && $existing->reason === $data['reason'], $current, 'This source was already decided differently. Reload its current state.');
        }

        return $existing;
    }

    public function record(string $type, int $id, User $actor, array $data, array $before, array $after, User $responsible): RestrictionDecision
    {
        $decision = RestrictionDecision::create([
            'subject_type' => $type, 'subject_id' => $id, 'actor_id' => $actor->id,
            'actor_role' => $actor->role, 'actor_name' => $actor->name,
            'source_token' => $data['source_token'], 'action' => $data['action'], 'reason' => $data['reason'],
            'before_state' => $before, 'after_state' => $after, 'decided_at' => now(),
        ]);
        if ($data['action'] !== 'reactivate') {
            foreach ($before['work'] as $work) {
                RestrictionAffectedWork::create([
                    'restriction_decision_id' => $decision->id, 'order_id' => $work['order_id'],
                    'delivery_id' => $work['parcel']['id'] ?? null, 'responsible_user_id' => $responsible->id,
                    'snapshot' => $work, 'recorded_at' => $decision->decided_at,
                ]);
            }
        }

        return $decision;
    }

    private function parentAccountIds(User $subject): array
    {
        $companyIds = CourierProfile::where('user_id', $subject->id)->pluck('logistics_company_id')
            ->merge(HubHandler::where('user_id', $subject->id)->pluck('logistics_company_id'))->filter()->unique();

        return LogisticsCompany::whereIn('id', $companyIds)->pluck('user_id')->filter()->all();
    }

    private function lockScope(User $subject): void
    {
        $companyIds = CourierProfile::where('user_id', $subject->id)->pluck('logistics_company_id')
            ->merge(HubHandler::where('user_id', $subject->id)->pluck('logistics_company_id'))
            ->merge(LogisticsCompany::where('user_id', $subject->id)->pluck('id'))->filter()->unique();
        LogisticsCompany::whereIn('id', $companyIds)->orderBy('id')->lockForUpdate()->get();
        LogisticsHub::whereIn('logistics_company_id', $companyIds)->orderBy('id')->lockForUpdate()->get();
        CourierProfile::where('user_id', $subject->id)->lockForUpdate()->get();
        HubHandler::where('user_id', $subject->id)->orderBy('id')->lockForUpdate()->get();
        LogisticsFleet::whereIn('logistics_company_id', $companyIds)->orderBy('id')->lockForUpdate()->get();
        Shop::where('user_id', $subject->id)->orderBy('id')->lockForUpdate()->get();
        if ($subject->isSeller()) {
            app(ShopEligibilityService::class)->lockCategories();
        }
    }

    public function state(User $subject): array
    {
        $profile = CourierProfile::where('user_id', $subject->id)->first();
        $companies = LogisticsCompany::where('user_id', $subject->id)->orderBy('id')->get();
        $handlers = HubHandler::where('user_id', $subject->id)->orderBy('id')->get();

        return [
            'subject' => $subject->only(['id', 'role', 'status', 'kyc_status', 'kyc_reviewed_at', 'birthday', 'restriction_version', 'closed_at']),
            'shops' => Shop::where('user_id', $subject->id)->orderBy('id')->get()->map(fn ($shop) => [
                ...$shop->only(['id', 'status', 'review_status', 'review_decision_id', 'review_version', 'root_category_id', 'restriction_version']),
                'reviewed_scope' => app(ShopEligibilityService::class)->reviewedShops(Shop::whereKey($shop->id))->exists(),
            ])->all(),
            'courier' => $profile?->only(['id', 'is_available', 'logistics_company_id', 'assigned_hub_id', 'vehicle_id', 'vehicle_type', 'plate_number']),
            'companies' => $companies->map(fn ($company) => $company->only(['id', 'user_id', 'status', 'is_active', 'restriction_version']))->all(),
            'handlers' => $handlers->map(fn ($handler) => [
                ...$handler->only(['id', 'hub_id', 'logistics_company_id', 'is_active', 'restriction_version']),
                'company' => $handler->hub?->company?->only(['id', 'user_id', 'status', 'is_active', 'restriction_version']),
                'owner' => $handler->hub?->company?->user?->only(['id', 'role', 'status', 'kyc_status', 'birthday', 'restriction_version']),
                'hub' => $handler->hub?->only(['id', 'logistics_company_id', 'is_active', 'tier', 'restriction_version']),
            ])->all(),
            'network_eligible' => $profile ? $this->courierScopeValid($profile) : null,
            'network' => $profile ? $this->networkState($profile) : null,
            'work' => $this->work($this->ordersFor($subject)),
        ];
    }

    public function ordersFor(User $subject): Builder
    {
        $query = Order::query();
        match ($subject->role) {
            'buyer' => $query->where('buyer_id', $subject->id),
            'seller' => $query->whereHas('items.shop', fn ($shop) => $shop->where('user_id', $subject->id)),
            'courier' => $query->whereHas('delivery', fn ($parcel) => $parcel->where(fn ($q) => $q->where('courier_id', $subject->id)->orWhere('assigned_rider_id', $subject->id)
                ->orWhereIn('id', LogisticsManifestParcel::whereHas('manifest', fn ($manifest) => $manifest->where('driver_id', $subject->id))->select('delivery_id')))),
            'logistics' => $query->whereHas('delivery', function ($parcel) use ($subject) {
                $companies = LogisticsCompany::where('user_id', $subject->id)->pluck('id');
                $hubs = HubHandler::where('user_id', $subject->id)->pluck('hub_id');
                $parcel->where(function ($scope) use ($companies, $hubs) {
                    $scope->whereIn('logistics_company_id', $companies);
                    foreach (['current_hub_id', 'origin_bayan_hub_id', 'origin_mother_hub_id', 'destination_mother_hub_id', 'destination_bayan_hub_id'] as $field) {
                        $scope->orWhereIn($field, $hubs);
                    }
                });
            }),
            default => $query->whereKey(0),
        };

        return $this->openOrUnverifiedCash(Order::where(fn ($scope) => $scope
            ->whereIn('orders.id', $query->select('orders.id'))
            ->orWhereIn('orders.id', RestrictionAffectedWork::where('responsible_user_id', $subject->id)->select('order_id'))));
    }

    public function openOrUnverifiedCash(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('orders.status')->orWhereRaw("orders.status NOT IN ('completed', 'cancelled', 'returned')")
            ->orWhere(fn ($cash) => $cash->where('payment_method', 'cod')->where(fn ($evidence) => $evidence
                ->where('payment_status', 'paid')->orWhereHas('commissionLedger')->orWhereHas('delivery', fn ($parcel) => $parcel->where(fn ($q) => $q->whereNotNull('picked_up_at')
                ->orWhereRaw("deliveries.status IN ('delivered', 'customer_collected', 'delivery_failed', 'failed', 'return_to_sender', 'returned')"))))));
    }

    public function work(Builder $query): array
    {
        return $query->with(['delivery' => fn ($q) => $q->with(['checkpoints' => fn ($q) => $q->orderBy('id')]), 'commissionLedger'])
            ->orderBy('id')->get()->map(function ($order) {
                $parcel = $order->delivery;
                $checkpoint = $parcel?->checkpoints->last();

                return [
                    'order_id' => $order->id, 'order_number' => $order->order_number, 'order_status' => $order->status,
                    'parcel' => $parcel?->only(['id', 'status', 'courier_id', 'assigned_rider_id', 'logistics_company_id', 'current_hub_id', 'origin_bayan_hub_id', 'origin_mother_hub_id', 'destination_mother_hub_id', 'destination_bayan_hub_id', 'shuttle_manifest_number', 'truck_manifest_number']),
                    'last_checkpoint' => $checkpoint?->only(['id', 'checkpoint_type', 'hub_id', 'scanned_by_id', 'manifest_number']),
                    'custody' => $parcel ? DeliveryCheckpoint::lastCustody($parcel) : null,
                    'manifests' => $parcel ? LogisticsManifest::where('logistics_company_id', $parcel->logistics_company_id)->whereHas('parcels', fn ($members) => $members->where('delivery_id', $parcel->id))->orderBy('id')->get()
                        ->map(fn ($manifest) => $manifest->state())->all() : [],
                    'proof_recorded' => filled($parcel?->proof_image),
                    'cash' => ['method' => $order->payment_method, 'payment_label' => $order->payment_status,
                        'expected_amount' => $order->total_amount, 'ledger_id' => $order->commissionLedger?->id,
                        'ledger_state' => $order->commissionLedger?->status, 'holder' => null,
                        'evidence' => $order->payment_method === 'cod' ? 'unverified' : 'not_cod'],
                ];
            })->all();
    }

    private function assertReactivation(User $subject): void
    {
        $valid = $subject->isKycApproved() && $subject->hasEligibleBirthDate();
        if ($subject->isSeller()) {
            $valid = $valid && app(ShopEligibilityService::class)->reviewedShops(Shop::where('user_id', $subject->id))->exists();
        }
        if ($subject->isCourier()) {
            $profile = CourierProfile::where('user_id', $subject->id)->first();
            $valid = $valid && $profile && filled($profile->vehicle_type) && filled($profile->plate_number) && $this->courierScopeValid($profile);
        }
        if ($subject->isLogistics()) {
            $companies = LogisticsCompany::where('user_id', $subject->id)->get();
            $valid = $valid && $companies->count() <= 1 && ! $companies->contains(fn ($company) => ! in_array($company->status, ['active', 'suspended', 'inactive'], true));
            foreach (HubHandler::where('user_id', $subject->id)->get() as $handler) {
                $hub = LogisticsHub::find($handler->hub_id);
                $company = LogisticsCompany::find($handler->logistics_company_id);
                $valid = $valid && $hub && $company && $hub->logistics_company_id === $company->id
                    && in_array($hub->tier, ['local_bayan_hub', 'regional_mother_hub'], true)
                    && in_array($company->status, ['active', 'suspended', 'inactive'], true)
                    && $company->user?->isLogistics() && $company->user->logisticsCompany()->count() === 1
                    && ($company->user_id === $subject->id || $company->user->canAccessPortal());
            }
        }
        if (! $valid) {
            throw ValidationException::withMessages(['action' => 'Reactivation needs valid reviewed identity and profile scope. Separate shop, company and assignment restrictions stay in place.']);
        }
    }

    private function courierScopeValid(CourierProfile $profile): bool
    {
        if (! $profile->logistics_company_id && ! $profile->assigned_hub_id && ! $profile->vehicle_id) {
            return true;
        }
        $company = LogisticsCompany::find($profile->logistics_company_id);
        $hub = LogisticsHub::find($profile->assigned_hub_id);
        if (! $company || ! $hub || $hub->logistics_company_id !== $company->id || ! $company->user?->canAccessPortal()
            || ! $company->user->isLogistics() || $company->user->logisticsCompany()->count() !== 1 || ! in_array($company->status, ['active', 'suspended', 'inactive'], true)
            || ! in_array($hub->tier, ['local_bayan_hub', 'regional_mother_hub'], true)) {
            return false;
        }
        $vehicle = $profile->vehicle_id ? LogisticsFleet::find($profile->vehicle_id) : null;

        return ! $profile->vehicle_id || ($vehicle && $vehicle->logistics_company_id === $company->id
            && $vehicle->hub_id === $hub->id && $vehicle->assigned_driver_id === $profile->user_id
            && in_array($vehicle->vehicle_type, ['motorcycle', 'tricycle', 'l300_van', 'wing_truck'], true)
            && in_array($vehicle->status, ['active', 'idle', 'maintenance', 'inactive', 'suspended'], true));
    }

    private function networkState(CourierProfile $profile): array
    {
        $company = LogisticsCompany::find($profile->logistics_company_id);

        return [
            'company' => $company?->only(['id', 'user_id', 'status', 'is_active', 'restriction_version']),
            'owner' => $company?->user?->only(['id', 'role', 'status', 'kyc_status', 'birthday', 'restriction_version']),
            'hub' => LogisticsHub::find($profile->assigned_hub_id)?->only(['id', 'logistics_company_id', 'tier', 'is_active', 'restriction_version']),
            'vehicle' => LogisticsFleet::find($profile->vehicle_id)?->only(['id', 'logistics_company_id', 'hub_id', 'status', 'assigned_driver_id', 'restriction_version']),
        ];
    }
}

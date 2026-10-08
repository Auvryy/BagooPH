<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\AccountClosure;
use App\Models\CommissionLedger;
use App\Models\CourierProfile;
use App\Models\HubHandler;
use App\Models\LogisticsCompany;
use App\Models\LogisticsFleet;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifestParcel;
use App\Models\Order;
use App\Models\Shop;
use App\Models\User;
use App\Rules\ApplicationText;
use App\Services\Notifications\GovernanceNoticeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AccountClosureService
{
    private ?array $foreignReferences = null;

    public function __construct(private AccountRestrictionService $restrictions) {}

    private function authorize(User $actor, int $subjectId, bool $self): User
    {
        $actor = User::find($actor->id);
        abort_unless($actor && $actor->closed_at === null
            && ($self ? $actor->id === $subjectId : $actor->isAdmin() && $actor->canAccessPortal()), 403);

        return $actor;
    }

    public function ordersFor(User $subject): Builder
    {
        // Scope by actual references as well as role, including legacy ownership mismatches.
        $shops = Shop::where('user_id', $subject->id)->pluck('id');
        $companies = LogisticsCompany::where('user_id', $subject->id)->pluck('id');
        $hubs = LogisticsHub::whereIn('logistics_company_id', $companies)->pluck('id')
            ->merge(HubHandler::where('user_id', $subject->id)->pluck('hub_id'))->unique();

        return Order::where(function (Builder $query) use ($subject, $shops, $companies, $hubs) {
            $query->where('buyer_id', $subject->id)
                ->orWhereIn('pickup_hub_id', $hubs)
                ->orWhereHas('items', fn ($items) => $items->whereIn('shop_id', $shops))
                ->orWhereHas('commissionLedger', fn ($ledger) => $ledger->where('seller_id', $subject->id)->orWhere('courier_id', $subject->id))
                ->orWhereIn('id', DB::table('restriction_affected_work')->where('responsible_user_id', $subject->id)->select('order_id'))
                ->orWhereHas('delivery', function ($parcel) use ($subject, $companies, $hubs) {
                    $parcel->where(function ($scope) use ($subject, $companies, $hubs) {
                        $scope->where('courier_id', $subject->id)->orWhere('assigned_rider_id', $subject->id)
                            ->orWhereIn('id', LogisticsManifestParcel::whereHas('manifest', fn ($manifest) => $manifest->where('driver_id', $subject->id))->select('delivery_id'))
                            ->orWhereIn('logistics_company_id', $companies);
                        foreach (['current_hub_id', 'origin_bayan_hub_id', 'origin_mother_hub_id', 'destination_mother_hub_id', 'destination_bayan_hub_id'] as $field) {
                            $scope->orWhereIn($field, $hubs);
                        }
                        $scope->orWhereHas('checkpoints', fn ($checkpoint) => $checkpoint->where('scanned_by_id', $subject->id));
                    });
                });
        });
    }

    private function referenceColumns(): array
    {
        if ($this->foreignReferences !== null) {
            return $this->foreignReferences;
        }
        $references = [];
        foreach (Schema::getTables() as $table) {
            // Email ownership and one-time challenges are account settings, not retained work history.
            if (in_array($table['name'], ['account_emails', 'email_otps'], true)) {
                continue;
            }
            foreach (Schema::getForeignKeys($table['name']) as $key) {
                if ($key['foreign_table'] === 'users' && $key['foreign_columns'] === ['id'] && count($key['columns']) === 1) {
                    $references[$table['name']][] = $key['columns'][0];
                }
            }
        }
        // Polymorphic governance subjects do not have a user foreign key.
        $references['restriction_decisions'][] = 'subject_id';
        ksort($references);

        return $this->foreignReferences = $references;
    }

    private function references(User $subject): array
    {
        $references = [];
        foreach ($this->referenceColumns() as $table => $columns) {
            $rows = DB::table($table)->where(function ($query) use ($table, $columns, $subject) {
                foreach (array_unique($columns) as $column) {
                    $query->orWhere(function ($scope) use ($table, $column, $subject) {
                        $scope->where($column, $subject->id);
                        if ($table === 'restriction_decisions' && $column === 'subject_id') {
                            $scope->where('subject_type', 'account');
                        }
                    });
                }
            })->orderBy('id')->get();
            if ($rows->isNotEmpty()) {
                $references[] = ['table' => $table, 'count' => $rows->count(), 'ids' => $rows->pluck('id')->all(),
                    'digest' => hash('sha256', json_encode($rows->all(), JSON_THROW_ON_ERROR))];
            }
        }
        if (array_filter($subject->only(['id_document_path', 'business_permit_path', 'driver_license_path', 'or_cr_path', 'avatar']))) {
            $references[] = ['table' => 'private_or_profile_files', 'count' => 1, 'ids' => [], 'digest' => $this->restrictions->token($subject->only(['id_document_path', 'business_permit_path', 'driver_license_path', 'or_cr_path', 'avatar']))];
        }

        return $references;
    }

    private function resources(User $subject): array
    {
        $companyIds = LogisticsCompany::where('user_id', $subject->id)->pluck('id');

        return [
            'shops' => Shop::where('user_id', $subject->id)->orderBy('id')->get(['id', 'status', 'review_status'])->toArray(),
            'companies' => LogisticsCompany::whereIn('id', $companyIds)->orderBy('id')->get(['id', 'status', 'is_active'])->toArray(),
            'hubs' => LogisticsHub::whereIn('logistics_company_id', $companyIds)->orderBy('id')->get(['id', 'is_active'])->toArray(),
            'handlers' => HubHandler::where('user_id', $subject->id)->orWhereIn('logistics_company_id', $companyIds)->orderBy('id')->get(['id', 'user_id', 'hub_id', 'is_active'])->toArray(),
            'riders' => CourierProfile::where('user_id', $subject->id)->orWhereIn('logistics_company_id', $companyIds)->orderBy('id')->get(['id', 'user_id', 'is_available', 'logistics_company_id', 'assigned_hub_id', 'vehicle_id'])->toArray(),
            'fleet' => LogisticsFleet::whereIn('logistics_company_id', $companyIds)->orWhere('assigned_driver_id', $subject->id)->orderBy('id')->get(['id', 'status', 'assigned_driver_id'])->toArray(),
        ];
    }

    public function state(User $subject): array
    {
        $work = $this->ordersFor($subject)->with(['delivery', 'commissionLedger'])->orderBy('id')->get()->map(fn ($order) => [
            'id' => $order->id, 'number' => $order->order_number, 'status' => $order->status,
            'payment_method' => $order->payment_method, 'payment_status' => $order->payment_status, 'total' => $order->total_amount,
            'parcel' => $order->delivery?->only(['id', 'status', 'courier_id', 'assigned_rider_id', 'current_hub_id']),
            'ledger' => $order->commissionLedger?->only(['id', 'status', 'gross_amount', 'seller_amount', 'platform_commission', 'delivery_fee']),
        ])->all();
        $resources = $this->resources($subject);
        $blockers = [];
        $add = function (string $code, string $message, string $next, array $ids = []) use (&$blockers): void {
            $blockers[] = ['code' => $code, 'message' => $message, 'next' => $next, 'ids' => $ids];
        };
        if (! UserRole::tryFrom($subject->role) || ! in_array($subject->status, ['active', 'inactive', 'suspended', 'pending_approval'], true)) {
            $add('unknown_account', 'The account has an unsupported role or activity state.', 'Platform Admin must review the original account records.');
        }
        if ($subject->closed_at !== null) {
            $add('already_closed', 'This account is already closed and retained.', 'Review the recorded closure; reopening is not available.');
        }
        $terminal = [...OrderStatus::completedCommerceStatuses(), ...OrderStatus::unrealizedCommerceStatuses()];
        foreach ($work as $order) {
            if (! in_array($order['status'], $terminal, true)) {
                $add('active_order', 'Order '.$order['number'].' still needs resolution.', 'The buyer, seller and assigned logistics staff must finish or recover its documented flow.', [$order['id']]);
            }
            if ($order['parcel'] && ! in_array($order['parcel']['status'], ['delivered', 'returned', 'cancelled'], true)) {
                $add('parcel_custody', 'Parcel custody for '.$order['number'].' remains unresolved.', 'The assigned logistics team must record the required handover or recovery.', [$order['parcel']['id']]);
            }
            if ($order['payment_method'] === 'cod') {
                $add('cash_unverified', 'Cash reconciliation for '.$order['number'].' cannot be verified from the available records.', 'Platform finance must establish recorded collection, remittance and reconciliation. Payment and commission labels do not prove cash custody.', [$order['id']]);
            }
            if ($order['ledger'] && ! in_array($order['ledger']['status'], ['settled', 'refunded'], true)) {
                $add('pending_proceeds', 'Recorded proceeds for '.$order['number'].' are unresolved.', 'Platform finance must resolve the recorded obligation without changing its original amounts.', [$order['ledger']['id']]);
            } elseif ($order['status'] === 'completed' && (! $order['ledger'] || ! in_array($order['payment_status'], ['paid', 'refunded'], true))) {
                $add('settlement_unverified', 'Settlement for '.$order['number'].' lacks sufficient records.', 'Platform finance must verify payment and proceeds before account closure.', [$order['id']]);
            }
            if (in_array($order['status'], OrderStatus::unrealizedCommerceStatuses(), true) && $order['payment_status'] === 'paid') {
                $add('returned_payment', 'Payment for the cancelled or returned order '.$order['number'].' remains unresolved.', 'Platform finance must review the recorded payment and any required return of money separately.', [$order['id']]);
            }
        }
        // A ledger can refer directly to an account even if the order owner has another role.
        $pending = CommissionLedger::where(fn ($q) => $q->where('seller_id', $subject->id)->orWhere('courier_id', $subject->id))
            ->whereNotIn('status', ['settled', 'refunded'])->pluck('id')->all();
        if ($pending) {
            $add('pending_ledger', 'This account has unresolved recorded proceeds.', 'Platform finance must resolve these entries.', $pending);
        }
        foreach ($resources as $kind => $records) {
            $active = array_filter($records, fn ($record) => match ($kind) {
                'shops' => ! in_array($record['status'], ['inactive', 'suspended', 'rejected'], true),
                'companies' => $record['is_active'] || ! in_array($record['status'], ['inactive', 'suspended', 'rejected'], true),
                'hubs', 'handlers' => $record['is_active'],
                'riders' => $record['user_id'] !== $subject->id || $record['is_available'] || $record['logistics_company_id'] || $record['assigned_hub_id'] || $record['vehicle_id'],
                'fleet' => $record['assigned_driver_id'] !== null || ! in_array($record['status'], ['inactive', 'suspended'], true),
            });
            if ($active) {
                $add('active_'.$kind, 'Operating '.$kind.' still depend on this account.', 'The responsible company or Platform Admin must review and release these responsibilities separately.', array_column($active, 'id'));
            }
        }
        if ($subject->isAdmin() && $subject->canAccessPortal() && ! User::where('role', 'admin')->whereKeyNot($subject->id)->get()->contains(fn ($user) => $user->canAccessPortal())) {
            $add('last_admin', 'The last eligible Platform Admin cannot close.', 'Arrange a controlled eligible replacement first.');
        }
        $references = $this->references($subject);

        $accountFields = array_filter($subject->getAttributes(), fn ($name) => ! preg_match('/password|token|secret|otp|two_factor/i', $name), ARRAY_FILTER_USE_KEY);

        return ['subject' => $subject->only(['id', 'role', 'status', 'kyc_status', 'identity_version', 'restriction_version', 'closed_at']),
            'account_digest' => $this->restrictions->token($accountFields),
            'work' => $work, 'resources' => $resources, 'references' => $references, 'blockers' => $blockers,
            'outcome' => $references ? 'retained' : 'deleted'];
    }

    public function canHardDelete(User $subject): bool
    {
        return $subject->closed_at === null && $this->references($subject) === [];
    }

    public function canSelfDelete(User $subject): bool
    {
        if (! $subject->isBuyer() || ! $subject->canAccessPortal()) {
            return false;
        }
        $state = $this->state($subject);

        return $state['outcome'] === 'deleted' && $state['blockers'] === [];
    }

    public function presentation(User $actor, int $subjectId, bool $self = false): array
    {
        $this->authorize($actor, $subjectId, $self);
        $subject = User::find($subjectId);
        $closure = AccountClosure::where('subject_id', $subjectId)->first();
        abort_unless($subject || $closure, 404);
        $state = $subject ? $this->state($subject) : null;
        $blockers = $state['blockers'] ?? [];
        if ($self && $subject && (! $subject->isBuyer() || ($state['outcome'] ?? null) === 'retained')) {
            $blockers[] = ['code' => 'admin_review', 'message' => 'Closure needs Platform Admin review to preserve identity and referenced history.', 'next' => 'Ask Platform Admin to review this account and its remaining responsibilities.', 'ids' => []];
        }

        return ['id' => $subjectId, 'name' => $subject?->name ?? $closure->subject_name, 'role' => $subject?->role ?? $closure->subject_role,
            'source_token' => $state ? $this->restrictions->token($state) : null, 'outcome' => $state['outcome'] ?? $closure->outcome,
            'allowed' => ! $closure && $blockers === [], 'blockers' => $blockers, 'work' => $state['work'] ?? [],
            'references' => array_map(fn ($ref) => ['table' => $ref['table'], 'count' => $ref['count']], $state['references'] ?? []),
            'closure' => $closure?->only(['id', 'outcome', 'reason', 'actor_name', 'closed_at']),
            'retention_note' => 'Closing an account ends sign-in. Related identity details, supporting documents, messages, order history and recorded amounts remain available to authorized reviewers. This action does not erase those records.'];
    }

    private function lockScope(User $subject): void
    {
        $companies = LogisticsCompany::where('user_id', $subject->id)->pluck('id')
            ->merge(CourierProfile::where('user_id', $subject->id)->pluck('logistics_company_id'))
            ->merge(HubHandler::where('user_id', $subject->id)->pluck('logistics_company_id'))->filter()->unique();
        LogisticsCompany::whereIn('id', $companies)->orderBy('id')->lockForUpdate()->get();
        LogisticsHub::whereIn('logistics_company_id', $companies)->orderBy('id')->lockForUpdate()->get();
        CourierProfile::where('user_id', $subject->id)->orWhereIn('logistics_company_id', $companies)->orderBy('id')->lockForUpdate()->get();
        HubHandler::where('user_id', $subject->id)->orWhereIn('logistics_company_id', $companies)->orderBy('id')->lockForUpdate()->get();
        LogisticsFleet::whereIn('logistics_company_id', $companies)->orWhere('assigned_driver_id', $subject->id)->orderBy('id')->lockForUpdate()->get();
        Shop::where('user_id', $subject->id)->orderBy('id')->lockForUpdate()->get();
    }

    public function close(User $actor, int $subjectId, array $input, bool $self = false): AccountClosure
    {
        $actor = $this->authorize($actor, $subjectId, $self);
        $input['reason'] ??= $self ? 'The account owner requested account closure.' : null;
        $data = Validator::make($input, ['password' => 'required|string', 'source_token' => [$self ? 'nullable' : 'required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'reason' => ['required', 'string', new ApplicationText('notes', 5, 1000)], 'role' => 'prohibited', 'status' => 'prohibited', 'outcome' => 'prohibited'])->validate();
        if (! Hash::check($data['password'], $actor->getAuthPassword())) {
            throw ValidationException::withMessages(['password' => 'The password is incorrect.']);
        }

        return DB::transaction(function () use ($actor, $subjectId, $data, $self) {
            $this->restrictions->lockGuard();
            $actor = $this->authorize($actor, $subjectId, $self);
            if ($prior = AccountClosure::where('subject_id', $subjectId)->first()) {
                abort_unless($prior->actor_id === $actor->id && $prior->reason === $data['reason']
                    && isset($data['source_token']) && hash_equals($prior->source_token, $data['source_token']), 409, 'This account already has a different recorded closure.');

                return $prior;
            }
            $subject = User::findOrFail($subjectId);
            $workIds = $this->ordersFor($subject)->orderBy('id')->pluck('id');
            $this->restrictions->lockWork($workIds);
            CommissionLedger::whereIn('order_id', $workIds)->orderBy('id')->lockForUpdate()->get();
            $companyIds = CourierProfile::where('user_id', $subjectId)->pluck('logistics_company_id')
                ->merge(HubHandler::where('user_id', $subjectId)->pluck('logistics_company_id'))->filter()->unique();
            $parents = LogisticsCompany::whereIn('id', $companyIds)->pluck('user_id')->filter()->all();
            $users = User::where(fn ($q) => $q->whereIn('id', [$actor->id, $subjectId, ...$parents])->orWhere('role', 'admin'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $subject = $users->get($subjectId);
            $actor = $this->authorize($users->get($actor->id), $subjectId, $self);
            abort_unless($subject, 404);
            if (! Hash::check($data['password'], $actor->getAuthPassword())) {
                throw ValidationException::withMessages(['password' => 'The account credentials changed. Confirm the current password.']);
            }
            $this->lockScope($subject);
            abort_unless($workIds->all() === $this->ordersFor($subject)->orderBy('id')->pluck('id')->all(), 409, 'Responsibilities changed. Reload the closure review.');
            $before = $this->state($subject);
            $token = $this->restrictions->token($before);
            abort_unless(! isset($data['source_token']) || hash_equals($token, $data['source_token']), 409, 'The account or its responsibilities changed. Reload the closure review.');
            if ($self && (! $subject->isBuyer() || ! $subject->canAccessPortal() || $before['outcome'] !== 'deleted')) {
                throw ValidationException::withMessages(['password' => 'Self-service deletion is unavailable. Platform Admin must review custody, money and preserved identity evidence.']);
            }
            if ($before['blockers']) {
                throw ValidationException::withMessages([$self ? 'password' : 'closure' => implode(' ', array_column($before['blockers'], 'message'))]);
            }
            $closure = AccountClosure::create(['subject_id' => $subjectId, 'subject_role' => $subject->role, 'subject_name' => $subject->name,
                'actor_id' => $actor->id, 'actor_role' => $actor->role, 'actor_name' => $actor->name, 'source_token' => $token,
                'reason' => $data['reason'], 'outcome' => $before['outcome'], 'before_state' => $before, 'closed_at' => now()]);
            if ($before['outcome'] === 'retained') {
                $subject->forceFill(['status' => 'inactive', 'closed_at' => $closure->closed_at, 'remember_token' => null])->save();
            } else {
                // An unreferenced identity is made inactive and deleted in this same transaction.
                $subject->forceFill(['status' => 'inactive'])->save();
                abort_unless($this->canHardDelete($subject), 409, 'New account references appeared during closure.');
                $subject->delete();
            }
            // Revoke only disposable authentication state; protected domain records and files are untouched.
            DB::table('sessions')->where('user_id', $subjectId)->delete();
            app(GovernanceNoticeService::class)->record($closure);

            return $closure;
        }, 3);
    }
}

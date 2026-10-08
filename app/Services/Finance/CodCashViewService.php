<?php

namespace App\Services\Finance;

use App\Models\CodAccount;
use App\Models\CodCashEvent;
use App\Models\CodCustodyEntry;
use App\Models\CourierProfile;
use App\Models\Delivery;
use App\Models\HubHandler;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\User;
use App\Services\Logistics\LogisticsEligibilityService;
use Illuminate\Database\Eloquent\Builder;

class CodCashViewService
{
    public function __construct(private readonly CodCashService $cash, private readonly LogisticsEligibilityService $eligibility) {}

    private function state(CodAccount $account): array
    {
        // Reads show the retained journal, never an independently edited balance projection.
        return $account->events()->latest('sequence')->firstOrFail()->target_state;
    }

    public function status(CodAccount $account): string
    {
        $state = $this->state($account);
        if ($state['collected_cents'] === 0) {
            return 'unverified';
        }
        if ($state['reconciled_reference']) {
            return 'reconciled';
        }
        if (collect($state['discrepancies'])->contains(fn ($difference) => ! $difference['resolved_by'])) {
            return 'difference';
        }
        if ($state['pending']) {
            return 'awaiting_receipt';
        }

        return $this->cash->atStage($state, 'platform') === $account->expected_cents ? 'at_platform' : 'cash_held';
    }

    public function page(User $actor, array $filters): array
    {
        $actor = $this->cash->current($actor);
        $scope = $this->cash->scoped($actor);
        $totals = ['expected' => 0, 'collected' => 0, 'rider' => 0, 'hub' => 0, 'platform' => 0, 'reconciled' => 0, 'excess' => 0, 'differences' => 0, 'unverified' => 0];
        foreach ((clone $scope)->cursor() as $account) {
            $state = $this->state($account);
            $totals['expected'] += $account->expected_cents;
            $totals['collected'] += $state['collected_cents'];
            $totals['reconciled'] += $state['reconciled_cents'];
            $totals['excess'] += array_sum($state['excess']);
            $totals['unverified'] += $state['collected_cents'] === 0 ? 1 : 0;
            $totals['differences'] += collect($state['discrepancies'])->filter(fn ($difference) => ! $difference['resolved_by'])->count();
            foreach (['rider', 'hub', 'platform'] as $stage) {
                $totals[$stage] += $this->cash->atStage($state, $stage);
            }
        }
        if (! empty($filters['q'])) {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['q']).'%';
            $scope->where(fn (Builder $query) => $query->whereHas('order', fn ($order) => $order->whereRaw("order_number LIKE ? ESCAPE '!'", [$term]))
                ->orWhereHas('delivery', fn ($parcel) => $parcel->whereRaw("tracking_number LIKE ? ESCAPE '!'", [$term])));
        }
        $accounts = $scope->orderByDesc('updated_at')->orderByDesc('id')->paginate(12)->withQueryString()
            ->through(fn (CodAccount $account) => $this->present($account, $actor, false));
        $legacy = $actor->isAdmin() ? Delivery::with('order')->whereDoesntHave('codAccount')->whereHas('order', fn ($orders) => $orders
            ->where('payment_method', 'cod')->where(fn ($candidate) => $candidate->where('payment_status', 'paid')->orWhereIn('status', ['delivered', 'completed'])))
            ->orderBy('id')->get()->map(fn ($parcel) => ['delivery_id' => $parcel->id, 'order_number' => $parcel->order->order_number,
                'tracking_number' => $parcel->tracking_number, 'status' => $parcel->status, 'due_cents' => CodMoney::cents($parcel->order->total_amount),
                'has_counter_source' => CodCustodyEntry::where('delivery_id', $parcel->id)->exists()])->all() : [];

        return ['accounts' => $accounts, 'totals' => $totals, 'legacy' => $legacy,
            'mode' => $this->mode($actor), 'isOnline' => (bool) $actor->courierProfile?->is_available,
            'can_reconcile' => $actor->isAdmin(), 'filters' => $filters];
    }

    public function mode(User $actor): string
    {
        if ($actor->isAdmin()) {
            return 'admin';
        }
        if ($actor->isCourier() && $actor->canAccessPortal() && CourierProfile::operational()->where('user_id', $actor->id)->exists()) {
            return 'courier';
        }
        if ($actor->isLogistics() && $actor->canAccessPortal() && ($this->eligibility->isCompanyAdministrator($actor)
            || HubHandler::eligible()->where('user_id', $actor->id)->exists())) {
            return 'hub';
        }

        return 'recovery';
    }

    public function present(CodAccount $account, User $actor, bool $details = true): array
    {
        $state = $this->state($account);
        $manager = $actor->isLogistics() && $actor->canAccessPortal() && $this->eligibility->isCompanyAdministrator($actor, $account->logistics_company_id);
        $holders = collect($state['balances'])->filter(fn ($amount) => $amount > 0)->map(function ($amount, $key) use ($actor, $manager) {
            [$stage, $id] = explode(':', $key);
            $user = User::findOrFail($id);

            return ['user_id' => (int) $id, 'name' => $user->name, 'stage' => $stage, 'amount_cents' => $amount,
                'restricted' => ! $user->canAccessPortal(), 'can_offer' => $stage !== 'platform'
                    && (($actor->id === (int) $id && ! $actor->isAdmin()) || ($manager && $stage === 'hub'))];
        })->values()->all();
        $pending = $state['pending'];
        if ($pending) {
            $pending += ['holder_name' => User::findOrFail($pending['holder_id'])->name, 'recipient_name' => User::findOrFail($pending['recipient_id'])->name,
                'can_receive' => $pending['recipient_id'] === $actor->id,
                'can_cancel' => in_array($actor->id, [$pending['holder_id'], $pending['offered_by_id']], true) || $manager || $actor->isAdmin()];
        }
        $payload = ['id' => $account->id, 'reference' => $account->reference, 'order_number' => $account->order->order_number,
            'tracking_number' => $account->delivery->tracking_number, 'hub_name' => LogisticsHub::findOrFail($account->hub_id)->name,
            'company_name' => LogisticsCompany::findOrFail($account->logistics_company_id)->name, 'source_kind' => $account->source_kind,
            'status' => $this->status($account), 'version' => $account->events()->max('sequence'),
            'expected_cents' => $account->expected_cents, 'product_subtotal_cents' => $account->product_subtotal_cents,
            'discount_cents' => $account->discount_cents, 'shipping_cents' => $account->shipping_cents,
            'collected_cents' => $state['collected_cents'], 'reconciled_cents' => $state['reconciled_cents'],
            'excess_cents' => array_sum($state['excess']), 'holders' => $holders, 'pending' => $pending,
            'recovery' => $state['recovery'], 'updated_at' => $account->updated_at->toIso8601String()];
        if (! $details) {
            return $payload;
        }
        $platform = User::where('role', 'admin')->where('status', 'active')->whereNull('closed_at')->whereNotNull('email_verified_at')
            ->orderBy('name')->get()->filter(fn ($user) => $user->canAccessPortal())->map(fn ($user) => $user->only(['id', 'name']))->values()->all();
        $hub = HubHandler::eligible()->with('user')->where('hub_id', $account->hub_id)->get()->filter(fn ($handler) => $handler->user->email_verified_at !== null)
            ->map(fn ($handler) => $handler->user->only(['id', 'name']))->unique('id')->values()->all();
        $history = $account->events()->orderBy('sequence')->get()->map(fn (CodCashEvent $event) => $event->only(['reference', 'sequence', 'event_type',
            'amount_cents', 'expected_cents', 'received_cents', 'discrepancy_cents', 'from_user_id', 'to_user_id', 'from_stage', 'to_stage', 'actor_role', 'provenance', 'evidence_reference', 'reason', 'created_at'])
            + ['actor_name' => User::findOrFail($event->actor_id)->name,
                'from_name' => $event->from_user_id ? User::findOrFail($event->from_user_id)->name : null,
                'to_name' => $event->to_user_id ? User::findOrFail($event->to_user_id)->name : null,
                'source_reference' => $event->source_event_id ? CodCashEvent::findOrFail($event->source_event_id)->reference : null])->all();

        return $payload + ['history' => $history, 'discrepancies' => $state['discrepancies'],
            'excess_holders' => collect($state['excess'])->filter()->map(function ($amount, $key) {
                [$stage, $id] = explode(':', $key);

                return ['stage' => $stage, 'name' => User::findOrFail($id)->name, 'amount_cents' => $amount];
            })->values()->all(), 'recipients' => ['hub' => $hub, 'platform' => $platform],
            'can_authorize_recovery' => $actor->isAdmin() && ! LogisticsHub::eligible()->whereKey($account->hub_id)->exists()];
    }

    public function evidence(?Delivery $parcel): array
    {
        $account = $parcel ? CodAccount::where('delivery_id', $parcel->id)->first() : null;
        if (! $account) {
            $old = $parcel ? CodCustodyEntry::where('delivery_id', $parcel->id)->where('entry_type', 'counter_collection')->first() : null;

            return ['reference' => $old?->reference, 'amount_cents' => $old?->amount_cents, 'holder_user_id' => $old?->holder_user_id,
                'holderName' => $old ? User::find($old->holder_user_id)?->name : null, 'evidence' => $old ? 'counter_collection' : 'unverified', 'reconciliation' => 'unverified'];
        }
        $state = $this->state($account);
        $holders = collect($state['balances'])->filter()->map(fn ($amount, $key) => ['user_id' => (int) explode(':', $key)[1], 'amount_cents' => $amount,
            'stage' => explode(':', $key)[0]])->values()->all();

        return ['reference' => $account->reference, 'amount_cents' => $state['collected_cents'],
            'holder_user_id' => count($holders) === 1 ? $holders[0]['user_id'] : null,
            'holderName' => count($holders) === 1 ? User::find($holders[0]['user_id'])?->name : (count($holders) > 1 ? 'Multiple recorded holders' : null),
            'evidence' => $state['collected_cents'] ? $account->source_kind : 'unverified', 'holders' => $holders,
            'excess_cents' => array_sum($state['excess']), 'version' => $account->events()->max('sequence'),
            'reconciliation' => $state['reconciled_reference'] ? 'reconciled' : 'pending'];
    }
}

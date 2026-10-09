<?php

namespace App\Services\Finance;

use App\Models\CodAccount;
use App\Models\LogisticsCompany;
use App\Models\Order;
use App\Models\User;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use UnexpectedValueException;

class FinancialOversightService
{
    public const STATES = ['not_collected', 'unverified', 'difference', 'awaiting_receipt', 'cash_held', 'at_platform',
        'reconciled', 'pending', 'eligible', 'authorized', 'settled', 'void'];

    private const CASH_METRICS = ['collected', 'held', 'remitted', 'reconciled', 'excess', 'shipping_charges'];

    private const PROCEEDS_METRICS = ['pending', 'eligible', 'authorized', 'settled', 'commission', 'discounts'];

    public function __construct(private readonly SellerSettlementService $settlements) {}

    public function current(User $actor): User
    {
        $actor = User::findOrFail($actor->id);
        abort_unless($actor->closed_at === null && $actor->email_verified_at !== null, 403);
        if ($actor->isLogistics()) {
            abort_unless($actor->canAccessPortal() && LogisticsCompany::where('user_id', $actor->id)->count() === 1, 403);

            return $actor;
        }

        return $this->settlements->current($actor);
    }

    public function scoped(User $actor): Builder
    {
        $actor = $this->current($actor);
        if (! $actor->isLogistics()) {
            $query = Order::query()->where(fn ($scope) => $scope->where('payment_method', 'cod')->orWhereHas('sellerSettlement')->orWhereHas('codAccount'));
            if ($actor->isSeller()) {
                $query->where(fn ($scope) => $scope->whereHas('sellerSettlement', fn ($record) => $record->where('seller_id', $actor->id))
                    ->orWhere(fn ($pending) => $pending->whereDoesntHave('sellerSettlement')->whereHas('items.shop', fn ($shop) => $shop->where('user_id', $actor->id))));
            }

            return $query;
        }
        $company = LogisticsCompany::where('user_id', $actor->id)->sole();

        // Original journal ownership survives later network restrictions and parcel reassignment.
        return Order::query()->where(fn ($scope) => $scope
            ->whereHas('codAccount', fn ($cash) => $cash->where('logistics_company_id', $company->id))
            ->orWhere(fn ($legacy) => $legacy->whereDoesntHave('codAccount')->where('payment_method', 'cod')
                ->whereHas('delivery', fn ($parcel) => $parcel->where('logistics_company_id', $company->id))));
    }

    public function filters(User $actor, array $input): array
    {
        $filters = Validator::make($input, [
            'from' => 'nullable|date_format:Y-m-d', 'to' => ['nullable', 'date_format:Y-m-d', ...(! empty($input['from']) ? ['after_or_equal:from'] : [])],
            'state' => ['nullable', Rule::in(self::STATES)], 'company' => 'nullable|integer|min:1|exists:logistics_companies,id',
            'recipient' => 'nullable|integer|min:1|exists:users,id', 'page' => 'nullable|integer|min:1|max:100000',
            'metric' => ['nullable', Rule::in(array_diff(array_keys($this->definitions($actor)), ['shipping_income', 'rider_paid']))],
        ])->validate();
        if (! $actor->isAdmin()) {
            abort_if($actor->isSeller() && ! empty($filters['company']), 403);
            abort_if($actor->isLogistics() && ! empty($filters['recipient']), 403);
            abort_if($actor->isSeller() && ! empty($filters['recipient']) && (int) $filters['recipient'] !== $actor->id, 403);
            abort_if($actor->isLogistics() && ! empty($filters['company'])
                && ! LogisticsCompany::where('user_id', $actor->id)->whereKey($filters['company'])->exists(), 403);
        }

        return $filters;
    }

    private function definitions(User $actor): array
    {
        $definitions = [];
        if (! $actor->isSeller()) {
            $definitions += [
                'collected' => ['Recorded COD collection', 'Original collected cash, counted once per order.'],
                'held' => ['Cash with riders and hubs', 'Current recorded rider and hub custody; excludes platform receipts.'],
                'remitted' => ['Cash received at platform', 'Platform custody in the cash journal. This is not a bank balance after seller payments.'],
                'reconciled' => ['COD reconciled at platform', 'Cash matched to its original collection by a recorded reconciliation.'],
                'excess' => ['Separately held extra cash', 'Recorded extra money remains separate from order proceeds.'],
                'shipping_charges' => ['Recorded shipping charges', 'Original charges on recorded COD sources; these are not logistics earnings.'],
            ];
        }
        if (! $actor->isLogistics()) {
            $definitions += [
                'pending' => ['Seller proceeds awaiting payment', 'Unpaid product share, including eligible and authorized releases; excludes cancelled and returned orders.'],
                'eligible' => ['Eligible seller proceeds', 'Buyer-confirmed and reconciled product share awaiting release approval.'],
                'authorized' => ['Authorized seller proceeds', 'Approved release with no recorded payment yet.'],
                'settled' => ['Seller proceeds paid', 'Actual recorded seller payment with private receipt evidence.'],
                'commission' => ['Recorded platform commission', 'The 10% product share associated with evidenced seller payments; excludes shipping.'],
                'discounts' => ['Order discounts', 'Discounts remain separate from the original 10%/90% product split.'],
            ];
        }
        $definitions += ['shipping_income' => ['Logistics shipping income', 'A shipping charge does not establish earned logistics income.'],
            'rider_paid' => ['Rider payouts', 'No recorded rider payout source is available.']];

        return $definitions;
    }

    private function add(array $amounts): string
    {
        $sum = BigInteger::zero();
        foreach ($amounts as $amount) {
            if (! is_int($amount) || $amount < 0) {
                throw new UnexpectedValueException('A retained cash source is incomplete.');
            }
            $sum = $sum->plus($amount);
        }

        return (string) $sum;
    }

    private function balances(array $state): array
    {
        foreach (['collected_cents', 'reconciled_cents', 'balances', 'excess', 'discrepancies', 'reconciled_reference', 'pending'] as $key) {
            if (! array_key_exists($key, $state)) {
                throw new UnexpectedValueException('A retained cash source is incomplete.');
            }
        }
        foreach (['balances', 'excess'] as $key) {
            if (! is_array($state[$key]) || collect(array_keys($state[$key]))->contains(fn ($holder) => ! preg_match('/\A(rider|hub|platform):[1-9][0-9]*\z/', $holder))) {
                throw new UnexpectedValueException('A retained cash holder is invalid.');
            }
        }
        if ($this->add(array_values($state['balances'])) !== $this->add([$state['collected_cents']])) {
            throw new UnexpectedValueException('Recorded custody does not match original collection.');
        }

        return ['collected' => $this->add([$state['collected_cents']]), 'reconciled' => $this->add([$state['reconciled_cents']]),
            'held' => $this->add(array_values(array_filter($state['balances'], fn ($key) => str_starts_with($key, 'rider:') || str_starts_with($key, 'hub:'), ARRAY_FILTER_USE_KEY))),
            'remitted' => $this->add(array_values(array_filter($state['balances'], fn ($key) => str_starts_with($key, 'platform:'), ARRAY_FILTER_USE_KEY))),
            'excess' => $this->add(array_values($state['excess']))];
    }

    private function cash(CodAccount $account, bool $details): array
    {
        $events = $account->events->sortBy('sequence');
        $last = $events->last();
        $collection = $events->first(fn ($event) => in_array($event->event_type, ['rider_collection', 'counter_collection'], true));
        if (! $last || ! $collection || $account->expected_cents <= 0 || $collection->amount_cents !== $account->expected_cents
            || $collection->event_type !== $account->source_kind || ($last->target_state['collected_cents'] ?? null) !== $account->expected_cents) {
            throw new UnexpectedValueException('Original cash evidence is unavailable.');
        }
        $state = $last->target_state;
        $amounts = $this->balances($state);
        if ($state['reconciled_reference'] && ! $events->contains(fn ($event) => $event->event_type === 'platform_reconciled'
            && $event->reference === $state['reconciled_reference'] && $state['reconciled_cents'] === $account->expected_cents)) {
            throw new UnexpectedValueException('Reconciliation evidence is unavailable.');
        }
        $status = $state['reconciled_reference'] ? 'reconciled'
            : (collect($state['discrepancies'])->contains(fn ($difference) => ! $difference['resolved_by']) ? 'difference'
                : ($state['pending'] ? 'awaiting_receipt' : ($amounts['remitted'] === (string) $account->expected_cents ? 'at_platform' : 'cash_held')));
        $payload = ['reference' => $account->reference, 'journal_reference' => $last->reference, 'status' => $status,
            'company_id' => $account->logistics_company_id, 'hub_id' => $account->hub_id,
            'recorded_at' => $account->created_at->toIso8601String(), 'collected_at' => $this->collectionDate($account)->toIso8601String(),
            'expected_cents' => (string) $account->expected_cents,
            'amounts' => $amounts + ['shipping_charges' => (string) $account->shipping_cents]];
        if ($details) {
            $payload['history'] = $events->map(fn ($event) => $event->only(['reference', 'sequence', 'event_type', 'amount_cents',
                'actor_id', 'actor_role', 'from_user_id', 'to_user_id', 'from_stage', 'to_stage', 'reason', 'evidence_reference', 'created_at'])
                + ['source_reference' => $event->source_event_id ? $events->firstWhere('id', $event->source_event_id)?->reference : null,
                    'before' => $this->balances($event->source_state), 'after' => $this->balances($event->target_state)])->values()->all();
        }

        return $payload;
    }

    private function collectionDate(CodAccount $account): CarbonImmutable
    {
        if ($account->source_kind !== 'counter_collection') {
            return CarbonImmutable::instance($account->created_at);
        }
        $event = $account->events->firstWhere('event_type', 'counter_collection');
        $original = $event?->private_evidence['original_collected_at'] ?? null;
        if (! is_string($original) || ! preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}[+-][0-9]{2}:[0-9]{2}\z/', $original)) {
            throw new UnexpectedValueException('The original collection date is unavailable.');
        }
        try {
            $date = CarbonImmutable::createFromFormat('Y-m-d\\TH:i:sP', $original)->utc();
        } catch (InvalidFormatException) {
            throw new UnexpectedValueException('The original collection date is unavailable.');
        }
        if ($date->gt($account->created_at)) {
            throw new UnexpectedValueException('The original collection date requires review.');
        }

        return $date;
    }

    public function row(Order $order, User $actor, bool $details = false): array
    {
        $account = $order->codAccount;
        $record = $order->sellerSettlement;
        $cash = $account ? $this->cash($account, $details && ! $actor->isSeller()) : null;
        $proceeds = $actor->isLogistics() ? null : $this->settlements->present($order, history: $details);
        $legacyCash = ! $account && ($order->payment_status === 'paid' || in_array($order->status, ['delivered', 'completed'], true));
        $legacyProceeds = ! $record && $order->commissionLedger && $order->commissionLedger->status !== 'pending';
        $unknownProceeds = $proceeds && ($legacyProceeds || $proceeds['seller_id'] === null || $proceeds['product_cents'] <= 0
            || (! $account && $order->items->sum(fn ($item) => CodMoney::cents($item->subtotal)) !== $proceeds['product_cents']));
        if ($proceeds && ! $record && in_array($order->status, ['cancelled', 'returned'], true)) {
            $proceeds['status'] = 'void';
        } elseif ($unknownProceeds) {
            $proceeds['status'] = 'unverified';
        }
        $metrics = $cash['amounts'] ?? array_fill_keys(self::CASH_METRICS, $legacyCash ? null : '0');
        if ($proceeds) {
            $share = (string) $proceeds['seller_cents'];
            $metrics += ['pending' => in_array($proceeds['status'], ['pending', 'eligible', 'authorized'], true) ? $share : '0',
                'eligible' => $proceeds['status'] === 'eligible' ? $share : '0',
                'authorized' => $proceeds['status'] === 'authorized' ? $share : '0',
                'settled' => $proceeds['status'] === 'settled' ? $share : '0',
                'commission' => $proceeds['status'] === 'settled' ? (string) $proceeds['commission_cents'] : '0',
                'discounts' => (string) $proceeds['discount_cents']];
            if ($unknownProceeds) {
                $metrics = array_replace($metrics, array_fill_keys(self::PROCEEDS_METRICS, null));
            }
        }
        $metrics = array_intersect_key($metrics, $this->definitions($actor));
        $cashStatus = $cash['status'] ?? ($legacyCash ? 'unverified' : 'not_collected');
        if ($actor->isSeller()) {
            $cash = null;
        }

        return ['order_id' => $order->id, 'currency' => 'PHP', 'order_number' => $record?->snapshot['order_number'] ?? $order->order_number,
            'recorded_at' => $account ? $this->collectionDate($account)->toIso8601String() : $order->created_at->toIso8601String(),
            'date_basis' => $account ? 'Original cash collection' : 'Order placed; cash not recorded',
            'cash_status' => $cashStatus,
            'cash' => $cash, 'proceeds' => $proceeds, 'metrics' => $metrics,
            'unavailable' => $legacyCash || $unknownProceeds,
            'url' => '/financial-oversight/'.$order->id];
    }

    private function rows(User $actor, array $filters): Collection
    {
        $query = $this->scoped($actor)->with(['items.shop.user', 'delivery', 'codAccount.events', 'commissionLedger', 'sellerSettlement.events']);
        if (! empty($filters['company'])) {
            $query->where(fn ($scope) => $scope->whereHas('codAccount', fn ($cash) => $cash->where('logistics_company_id', $filters['company']))
                ->orWhere(fn ($legacy) => $legacy->whereDoesntHave('codAccount')->whereHas('delivery', fn ($parcel) => $parcel->where('logistics_company_id', $filters['company']))));
        }
        if (! empty($filters['recipient'])) {
            $query->where(fn ($scope) => $scope->whereHas('sellerSettlement', fn ($record) => $record->where('seller_id', $filters['recipient']))
                ->orWhere(fn ($pending) => $pending->whereDoesntHave('sellerSettlement')->whereHas('items.shop', fn ($shop) => $shop->where('user_id', $filters['recipient']))));
        }
        $from = ! empty($filters['from']) ? CarbonImmutable::parse($filters['from'], 'Asia/Manila')->startOfDay()->utc() : null;
        $to = ! empty($filters['to']) ? CarbonImmutable::parse($filters['to'], 'Asia/Manila')->addDay()->startOfDay()->utc() : null;
        $rows = collect();
        foreach ($query->lazyById(100) as $order) {
            $date = $order->codAccount ? $this->collectionDate($order->codAccount) : $order->created_at;
            if (($from && $date->lt($from)) || ($to && $date->gte($to))) {
                continue;
            }
            $row = $this->row($order, $actor);
            if (! empty($filters['state']) && $row['cash_status'] !== $filters['state'] && ($row['proceeds']['status'] ?? null) !== $filters['state']) {
                continue;
            }
            if (! empty($filters['metric']) && (($row['metrics'][$filters['metric']] ?? null) === '0')) {
                continue;
            }
            $rows->push($row);
        }

        return $rows->sortByDesc('order_id')->values();
    }

    private function totals(User $actor, Collection $rows, array $filters, bool $failed = false): array
    {
        $totals = [];
        foreach ($this->definitions($actor) as $key => [$label, $definition]) {
            $unavailable = $failed || in_array($key, ['shipping_income', 'rider_paid'], true)
                || $rows->contains(fn ($row) => ! isset($row['metrics'][$key]));
            $sum = BigInteger::zero();
            if (! $unavailable) {
                foreach ($rows as $row) {
                    $sum = $sum->plus($row['metrics'][$key]);
                }
            }
            $totals[] = ['key' => $key, 'label' => $label, 'definition' => $definition, 'amount_cents' => $unavailable ? null : (string) $sum,
                'source_count' => $rows->filter(fn ($row) => isset($row['metrics'][$key]) && $row['metrics'][$key] !== '0')->count(),
                'url' => $unavailable ? null : '/financial-oversight?'.http_build_query(array_replace(array_diff_key($filters, ['page' => true]), ['metric' => $key]))];
        }

        return $totals;
    }

    public function page(User $actor, array $input, string $path = '/financial-oversight'): array
    {
        $actor = $this->current($actor);
        $filters = $this->filters($actor, $input);
        $error = null;
        try {
            $rows = $this->rows($actor, $filters);
        } catch (QueryException|UnexpectedValueException $exception) {
            report($exception);
            $rows = collect();
            $error = 'Financial sources could not be verified. Totals are unavailable; try again after the source is restored.';
        }
        $page = (int) ($filters['page'] ?? 1);

        return ['records' => new LengthAwarePaginator($rows->forPage($page, 12)->values(), $rows->count(), 12, $page, ['path' => $path, 'query' => $filters]),
            'totals' => $this->totals($actor, $rows, $filters, $error !== null), 'filters' => $filters, 'error' => $error,
            'mode' => $actor->isAdmin() ? 'admin' : ($actor->isSeller() ? 'seller' : 'company'), 'currency' => 'PHP', 'timezone' => 'Asia/Manila',
            'stateOptions' => $actor->isLogistics() ? array_values(array_diff(self::STATES, ['pending', 'eligible', 'authorized', 'settled', 'void'])) : self::STATES,
            'companies' => $actor->isAdmin() ? LogisticsCompany::orderBy('id')->get(['id', 'name'])->toArray() : [],
            'recipients' => $actor->isAdmin() ? User::where('role', 'seller')->orderBy('id')->get(['id', 'name'])->toArray() : []];
    }

    public function detail(User $actor, int $orderId): array
    {
        $actor = $this->current($actor);
        $order = $this->scoped($actor)->with(['items.shop.user', 'delivery', 'codAccount.events', 'commissionLedger', 'sellerSettlement.events'])->whereKey($orderId)->firstOrFail();

        return ['record' => $this->row($order, $actor, true), 'mode' => $actor->isAdmin() ? 'admin' : ($actor->isSeller() ? 'seller' : 'company')];
    }
}

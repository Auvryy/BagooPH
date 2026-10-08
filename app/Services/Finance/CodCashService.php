<?php

namespace App\Services\Finance;

use App\Models\CodAccount;
use App\Models\CodCashEvent;
use App\Models\CodCustodyEntry;
use App\Models\CourierProfile;
use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\HubHandler;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\Order;
use App\Models\User;
use App\Rules\ApplicationText;
use App\Rules\AsciiPositiveInteger;
use App\Services\Logistics\LogisticsEligibilityService;
use App\Services\Logistics\PickupClaimService;
use App\Services\Logistics\WaybillScanInputService;
use App\Services\Notifications\CodNoticeService;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CodCashService
{
    public const COMMANDS = ['offer', 'receive', 'cancel', 'adjust', 'reconcile', 'authorize-recovery'];

    public function __construct(private readonly LogisticsEligibilityService $eligibility, private readonly CodNoticeService $notices) {}

    private function emptyState(): array
    {
        return ['collected_cents' => 0, 'balances' => [], 'excess' => [], 'discrepancies' => [],
            'pending' => null, 'recovery' => null, 'reconciled_cents' => 0, 'reconciled_reference' => null];
    }

    public function current(User $actor): User
    {
        $actor = User::findOrFail($actor->id);
        abort_unless($actor->closed_at === null && $actor->email_verified_at !== null
            && ($actor->isCourier() || $actor->isLogistics() || $actor->isAdmin()), 403);
        abort_if($actor->isAdmin() && ! $actor->canAccessPortal(), 403);

        return $actor;
    }

    public function scoped(User $actor): Builder
    {
        $actor = $this->current($actor);
        $query = CodAccount::query();
        if ($actor->isAdmin()) {
            return $query;
        }

        return $query->where(function (Builder $scope) use ($actor) {
            // Recorded cash responsibilities survive suspension and later placement changes.
            $scope->where('collector_id', $actor->id)->orWhereHas('events', fn ($events) => $events
                ->where(fn ($parties) => $parties->where('from_user_id', $actor->id)->orWhere('to_user_id', $actor->id)));
            if ($actor->isLogistics() && $actor->canAccessPortal()) {
                $scope->orWhereIn('logistics_company_id', LogisticsCompany::eligible()->where('user_id', $actor->id)->select('id'))
                    ->orWhereIn('hub_id', HubHandler::eligible()->where('user_id', $actor->id)->select('hub_id'));
            }
        });
    }

    public function account(User $actor, int $id): CodAccount
    {
        return $this->scoped($actor)->whereKey($id)->firstOrFail();
    }

    public function riderInput(Delivery $parcel, User $actor, array $metadata): array
    {
        $input = app(WaybillScanInputService::class)->normalize($metadata);
        $input = Validator::make($input, [
            'barcode' => app(WaybillScanInputService::class)->barcodeRules(),
            'recipient_name' => ['required', 'string', new ApplicationText('name', 2, 255)],
            'recipient_relationship' => ['required', Rule::in(['buyer', 'household', 'authorized_recipient'])],
            'cash_received' => ['required', 'string', 'regex:'.CodMoney::RULE],
            'change_given' => ['required', 'string', 'regex:'.CodMoney::RULE],
            'cash_confirmed' => ['required', 'accepted'], 'request_token' => ['required', 'uuid'],
            'notes' => app(WaybillScanInputService::class)->notesRules(500),
            ...$this->protectedFields(),
        ])->validate();
        $input['barcode'] = app(WaybillScanInputService::class)->matchedBarcode($input['barcode'], $parcel);
        $input['recipient_name'] = trim($input['recipient_name']);
        $input['notes'] ??= null;
        $input['cash_confirmed'] = true;
        $input['cash_received'] = CodMoney::cents($input['cash_received']);
        $input['change_given'] = CodMoney::cents($input['change_given']);
        if (! $actor->isCourier() || $actor->id !== $parcel->assigned_rider_id || ! $actor->canAccessPortal()
            || $parcel->delivery_type !== 'doorstep' || $parcel->order->payment_method !== 'cod'
            || CodMoney::cents($parcel->order->total_amount) !== $input['cash_received'] - $input['change_given']) {
            throw new DomainException('The assigned delivery rider must collect the exact saved COD amount and return correct change.');
        }

        return $input;
    }

    public function retryRider(Delivery $parcel, User $actor, array $metadata): void
    {
        $account = CodAccount::where('delivery_id', $parcel->id)->first();
        $input = $this->riderInput($parcel, $actor, $metadata);
        $original = $account?->events()->where('event_type', 'rider_collection')->first();
        if (! $original || $original->actor_id !== $actor->id || $original->request_token !== $input['request_token']
            || ! hash_equals($original->request_fingerprint, $this->fingerprint($actor, 'rider_collection', $input))
            || (isset($metadata['proof_hash']) && ! hash_equals($original->private_evidence['proof_hash'], $metadata['proof_hash']))) {
            throw new DomainException('This handoff already has different evidence. Its original cash, proof and receipt are retained.');
        }
    }

    public function collectRider(Delivery $parcel, User $actor, DeliveryCheckpoint $checkpoint, array $metadata): CodCashEvent
    {
        abort_unless(DB::transactionLevel() > 0, 403);
        // The caller holds order, parcel and eligibility locks; this write shares its transaction.
        $input = $this->riderInput($parcel, $actor, $metadata);
        if ($parcel->status !== 'delivered' || $parcel->order->status !== 'delivered' || $parcel->order->payment_status !== 'pending'
            || $checkpoint->delivery_id !== $parcel->id || $checkpoint->scanned_by_id !== $actor->id
            || $checkpoint->checkpoint_type !== 'delivered' || $checkpoint->barcode_scanned !== $parcel->tracking_number
            || ($checkpoint->source_state['delivery_status'] ?? null) !== 'out_for_delivery') {
            throw new DomainException('Cash collection needs the actual assigned-rider handoff source.');
        }
        $account = $this->createAccount($parcel, 'rider_collection', $actor->id, ['delivery_checkpoint_id' => $checkpoint->id]);
        $state = $account->state;
        $state['collected_cents'] = $account->expected_cents;
        $state['balances']['rider:'.$actor->id] = $account->expected_cents;
        $proof = substr($checkpoint->proof_image, strlen('/storage/'));

        return $this->append($account, $actor, 'rider_collection', $input, $state, [
            'to_user_id' => $actor->id, 'to_stage' => 'rider', 'amount_cents' => $account->expected_cents,
            'received_cents' => $account->expected_cents, 'expected_cents' => $account->expected_cents,
            'evidence_reference' => $checkpoint->record_reference,
            'private_evidence' => ['recipient_name' => $input['recipient_name'], 'recipient_relationship' => $input['recipient_relationship'],
                'tender_cents' => $input['cash_received'], 'change_cents' => $input['change_given'],
                'proof_hash' => hash('sha256', Storage::disk('public')->get($proof))],
        ]);
    }

    public function collectCounter(Delivery $parcel, User $actor, CodCustodyEntry $source, string $requestToken, bool $legacy = false, array $reviewInput = []): CodCashEvent
    {
        abort_unless(DB::transactionLevel() > 0 && ($legacy ? $actor->isAdmin() && $actor->canAccessPortal()
            : $actor->id === $source->actor_id && $actor->isLogistics() && $actor->canAccessPortal()
                && $this->eligibility->canScan($actor, LogisticsHub::findOrFail($source->hub_id))), 403);
        if ($source->delivery_id !== $parcel->id || $source->order_id !== $parcel->order_id
            || $source->hub_id !== $parcel->destination_bayan_hub_id || $source->logistics_company_id !== $parcel->logistics_company_id
            || $source->amount_cents !== CodMoney::cents($parcel->order->total_amount)
            || ! app(PickupClaimService::class)->hasCollectionEvidence($parcel)) {
            throw new DomainException('Counter cash needs its original verified buyer collection, waybill and exact amount.');
        }
        $account = $this->createAccount($parcel, 'counter_collection', $source->holder_user_id, ['pickup_cash_entry_id' => $source->id]);
        $state = $account->state;
        $state['collected_cents'] = $source->amount_cents;
        $state['balances']['hub:'.$source->holder_user_id] = $source->amount_cents;

        return $this->append($account, $actor, 'counter_collection', $reviewInput + ['request_token' => $requestToken], $state, [
            'to_user_id' => $source->holder_user_id, 'to_stage' => 'hub', 'amount_cents' => $source->amount_cents,
            'received_cents' => $source->amount_cents, 'expected_cents' => $account->expected_cents,
            'evidence_reference' => $source->reference, 'provenance' => $legacy ? 'verified_counter_history' : 'counter_handler',
            'private_evidence' => ['original_collected_at' => $source->created_at->toIso8601String(), 'original_actor_id' => $source->actor_id],
        ]);
    }

    private function createAccount(Delivery $parcel, string $kind, ?int $collectorId, array $source = []): CodAccount
    {
        $due = CodMoney::cents($parcel->order->total_amount);
        if ($due <= 0 || $parcel->order->payment_method !== 'cod' || ! $parcel->destination_bayan_hub_id || ! $parcel->logistics_company_id) {
            throw new DomainException('This cash record needs the actual COD snapshot and original destination facility.');
        }

        return CodAccount::create($source + ['order_id' => $parcel->order_id, 'delivery_id' => $parcel->id,
            'logistics_company_id' => $parcel->logistics_company_id, 'hub_id' => $parcel->destination_bayan_hub_id,
            'collector_id' => $collectorId, 'source_kind' => $kind, 'expected_cents' => $due,
            'product_subtotal_cents' => CodMoney::cents($parcel->order->subtotal), 'discount_cents' => CodMoney::cents($parcel->order->voucher_discount),
            'shipping_cents' => CodMoney::cents($parcel->order->shipping_fee), 'state' => $this->emptyState()]);
    }

    public function reviewLegacy(User $actor, Delivery $delivery, array $input): CodCashEvent
    {
        $input = Validator::make($input, ['request_token' => ['required', 'uuid'], 'expected_status' => ['required', 'string', 'max:40'],
            'reason' => ['required', 'string', new ApplicationText('notes', 10, 1000)],
            'evidence_reference' => ['required', 'string', new ApplicationText('bin', 3, 120)], ...$this->protectedFields()])->validate();

        return DB::transaction(function () use ($actor, $delivery, $input) {
            $order = Order::whereKey($delivery->order_id)->lockForUpdate()->firstOrFail();
            $parcel = Delivery::whereKey($delivery->id)->lockForUpdate()->firstOrFail()->setRelation('order', $order);
            $this->lockNetwork($parcel, [$actor->id]);
            $actor = $this->current($actor);
            abort_unless($actor->isAdmin(), 403);
            $existing = CodAccount::where('delivery_id', $parcel->id)->lockForUpdate()->first();
            if ($existing) {
                $retry = $existing->events()->where('request_token', $input['request_token'])->first();
                if ($retry && $retry->actor_id === $actor->id && hash_equals($retry->request_fingerprint, $this->fingerprint($actor, $retry->event_type, $input))) {
                    return $retry;
                }
                throw new DomainException('An original cash record already exists. Review its retained history.');
            }
            if ($parcel->status !== $input['expected_status'] || $order->payment_method !== 'cod'
                || (! in_array($parcel->status, ['delivered', 'customer_collected'], true) && $order->payment_status !== 'paid')) {
                throw new DomainException('The older order changed or is not a cash-review candidate.');
            }
            $source = CodCustodyEntry::where('delivery_id', $parcel->id)->where('entry_type', 'counter_collection')->first();
            if ($source && app(PickupClaimService::class)->hasCollectionEvidence($parcel)) {
                $event = $this->collectCounter($parcel, $actor, $source, $input['request_token'], true, $input);
                // The initial adoption records the original source. Review evidence gets its own immutable entry.
                $this->append($event->account->fresh(), $actor, 'legacy_review', array_replace($input, ['request_token' => (string) Str::uuid()]),
                    $event->account->fresh()->state, ['source_event_id' => $event->id, 'evidence_reference' => $input['evidence_reference'], 'reason' => $input['reason']]);

                return $event;
            }
            $account = $this->createAccount($parcel, 'legacy_review', null);

            return $this->append($account, $actor, 'legacy_review', $input, $account->state,
                ['evidence_reference' => $input['evidence_reference'], 'reason' => $input['reason']]);
        });
    }

    public function command(User $actor, CodAccount $account, string $action, array $input): CodCashEvent
    {
        abort_unless(in_array($action, self::COMMANDS, true), 404);
        $input = $this->commandInput($action, $input);

        return DB::transaction(function () use ($actor, $account, $action, $input) {
            $snapshot = CodAccount::findOrFail($account->id);
            $order = Order::whereKey($snapshot->order_id)->lockForUpdate()->firstOrFail();
            $parcel = Delivery::whereKey($snapshot->delivery_id)->lockForUpdate()->firstOrFail()->setRelation('order', $order);
            $snapshot = $snapshot->fresh();
            $partyIds = collect(array_keys($snapshot->state['balances']))->map(fn ($key) => (int) explode(':', $key)[1])->all();
            $this->lockNetwork($parcel, [...$partyIds, $actor->id, $input['recipient_id'] ?? null, $input['holder_id'] ?? null], $snapshot);
            $actor = $this->current($actor);
            $this->account($actor, $account->id);
            $account = CodAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
            $last = $account->events()->latest('sequence')->firstOrFail();
            if ($account->version !== $last->sequence || $account->state !== $last->target_state) {
                throw new DomainException('The cash balance does not match its retained history. Platform review is required.');
            }
            $original = $account->events()->where('request_token', $input['request_token'])->first();
            if ($original) {
                if ($original->actor_id !== $actor->id || ! hash_equals($original->request_fingerprint, $this->fingerprint($actor, $action, $input))) {
                    throw new DomainException('This request already recorded different cash evidence.');
                }

                return $original;
            }
            if ($account->version !== (int) $input['expected_version'] || $account->reconciled_at !== null) {
                throw new DomainException('The cash record changed or was already reconciled. Refresh it before continuing.');
            }
            if (in_array($action, ['adjust', 'reconcile', 'authorize-recovery'], true)) {
                abort_unless($actor->isAdmin() && $actor->canAccessPortal(), 403);
            }

            return match ($action) {
                'offer' => $this->offer($account, $actor, $input),
                'receive' => $this->receive($account, $actor, $input),
                'cancel' => $this->cancel($account, $actor, $input),
                'adjust' => $this->adjust($account, $actor, $input),
                'reconcile' => $this->reconcile($account, $actor, $input, $order),
                'authorize-recovery' => $this->authorizeRecovery($account, $actor, $input),
            };
        });
    }

    public function protectedFields(): array
    {
        return array_fill_keys(['expected_cents', 'amount_cents', 'received_cents', 'discrepancy_cents', 'total_amount', 'due_amount', 'payment_status', 'commission', 'holder_user_id',
            'actor_id', 'actor_role', 'created_at', 'reconciled_at', 'state', 'version'], ['prohibited']);
    }

    private function commandInput(string $action, array $input): array
    {
        $money = ['required', 'string', 'regex:'.CodMoney::RULE];
        $person = ['required', new AsciiPositiveInteger, 'integer', 'min:1'];
        $reason = ['required', 'string', new ApplicationText('notes', 10, 1000)];
        $reference = ['required', 'string', new ApplicationText('bin', 3, 120)];
        $rules = match ($action) {
            'offer' => ['holder_id' => $person, 'recipient_id' => $person, 'amount' => $money, 'evidence_reference' => $reference],
            'receive' => ['offer_reference' => $reference, 'received_amount' => $money, 'evidence_reference' => $reference,
                'receipt_confirmed' => ['required', 'accepted'],
                'reason' => ['nullable', 'string', new ApplicationText('notes', 10, 1000)]],
            'cancel' => ['offer_reference' => $reference, 'reason' => $reason],
            'adjust' => ['source_reference' => $reference, 'adjustment_type' => ['required', Rule::in(['shortage_recovered', 'overage_separated'])],
                'amount' => $money, 'evidence_reference' => $reference, 'reason' => $reason],
            'authorize-recovery' => ['holder_id' => $person, 'recipient_id' => $person, 'reason' => $reason],
            default => ['evidence_reference' => $reference, 'reason' => $reason],
        };
        $input = Validator::make($input, $rules + ['request_token' => ['required', 'uuid'],
            'expected_version' => ['required', new AsciiPositiveInteger, 'integer', 'min:1'], ...$this->protectedFields()])->validate();
        foreach (['holder_id', 'recipient_id', 'expected_version'] as $key) {
            if (isset($input[$key])) {
                $input[$key] = (int) $input[$key];
            }
        }
        foreach (['amount', 'received_amount'] as $key) {
            if (isset($input[$key])) {
                $input[$key] = CodMoney::cents($input[$key]);
            }
        }
        if ($action === 'receive') {
            $input['receipt_confirmed'] = true;
        }

        return $input;
    }

    private function offer(CodAccount $account, User $actor, array $input): CodCashEvent
    {
        $state = $account->state;
        if ($state['pending']) {
            throw new DomainException('Confirm or cancel the pending handover before offering another.');
        }
        $stage = $this->holderStage($account, $input['holder_id']);
        $key = $stage.':'.$input['holder_id'];
        $own = $input['holder_id'] === $actor->id;
        $manager = $stage === 'hub' && $actor->isLogistics() && $actor->canAccessPortal()
            && $this->eligibility->isCompanyAdministrator($actor, $account->logistics_company_id);
        abort_unless(($own && ! $actor->isAdmin()) || $manager, 403);
        if ($input['amount'] <= 0 || $input['amount'] > $state['balances'][$key]) {
            throw new DomainException('Offer a positive amount within this recorded holder’s remaining cash responsibility.');
        }
        $recipient = $this->current(User::findOrFail($input['recipient_id']));
        $target = $stage === 'rider' ? 'hub' : 'platform';
        if ($stage === 'rider' && ! $this->canReceiveHub($recipient, $account)) {
            $grant = $state['recovery'];
            if (! $grant || $grant['holder_id'] !== $input['holder_id'] || $grant['recipient_id'] !== $recipient->id
                || now()->greaterThanOrEqualTo($grant['expires_at']) || ! $recipient->isAdmin() || ! $recipient->canAccessPortal()) {
                throw new DomainException('Rider cash must reach its original destination hub, or use a current platform-authorized recovery handover.');
            }
            $target = 'platform';
        } elseif ($stage === 'hub' && (! $recipient->isAdmin() || ! $recipient->canAccessPortal())) {
            throw new DomainException('Hub cash requires a currently eligible Platform Admin recipient.');
        }
        if ($recipient->id === $input['holder_id']) {
            throw new DomainException('The receiving person must be different from the cash holder.');
        }
        $reference = 'COD-'.strtoupper((string) Str::uuid());
        $state['pending'] = ['reference' => $reference, 'holder_id' => $input['holder_id'], 'offered_by_id' => $actor->id,
            'recipient_id' => $recipient->id, 'from_stage' => $stage, 'to_stage' => $target, 'amount_cents' => $input['amount']];

        return $this->append($account, $actor, 'handover_offered', $input, $state, [
            'from_user_id' => $input['holder_id'], 'to_user_id' => $recipient->id, 'from_stage' => $stage, 'to_stage' => $target,
            'expected_cents' => $input['amount'], 'evidence_reference' => $input['evidence_reference'],
            'provenance' => $manager ? 'company_cash_manager' : 'recorded_cash_holder',
        ], $reference);
    }

    private function receive(CodAccount $account, User $actor, array $input): CodCashEvent
    {
        $state = $account->state;
        $pending = $this->pending($account, $input['offer_reference']);
        abort_unless($pending['recipient_id'] === $actor->id, 403);
        if (($pending['to_stage'] === 'hub' && ! $this->canReceiveHub($actor, $account))
            || ($pending['to_stage'] === 'platform' && (! $actor->isAdmin() || ! $actor->canAccessPortal()))) {
            throw new DomainException('The intended receiving person or facility is no longer eligible. Cancel and review the handover.');
        }
        if ($pending['from_stage'] === 'rider' && $pending['to_stage'] === 'platform') {
            $grant = $state['recovery'];
            if (! $grant || $grant['holder_id'] !== $pending['holder_id'] || $grant['recipient_id'] !== $actor->id
                || now()->greaterThanOrEqualTo($grant['expires_at'])) {
                throw new DomainException('The recovery authority expired. Cancel the unconfirmed offer and obtain a fresh platform review.');
            }
        }
        $received = $input['received_amount'];
        $expected = $pending['amount_cents'];
        $difference = $received - $expected;
        if ($difference !== 0 && empty($input['reason'])) {
            throw new DomainException('Explain the counted shortage or overage before confirming this receipt.');
        }
        $applied = min($received, $expected);
        $from = $pending['from_stage'].':'.$pending['holder_id'];
        $to = $pending['to_stage'].':'.$actor->id;
        if (($state['balances'][$from] ?? 0) < $expected) {
            throw new DomainException('The offered amount no longer matches its recorded source cash.');
        }
        $state['balances'][$from] -= $applied;
        $state['balances'][$to] = ($state['balances'][$to] ?? 0) + $applied;
        if ($difference > 0) {
            $state['excess'][$to] = ($state['excess'][$to] ?? 0) + $difference;
        }
        $reference = 'COD-'.strtoupper((string) Str::uuid());
        if ($difference) {
            $state['discrepancies'][$reference] = ['difference_cents' => $difference, 'holder_id' => $pending['holder_id'],
                'recipient_id' => $actor->id, 'from_stage' => $pending['from_stage'], 'to_stage' => $pending['to_stage'],
                'resolved_by' => null, 'recovery_evidence' => null];
        }
        $state['pending'] = null;

        return $this->append($account, $actor, 'handover_received', $input, $state, [
            'from_user_id' => $pending['holder_id'], 'to_user_id' => $actor->id, 'from_stage' => $pending['from_stage'], 'to_stage' => $pending['to_stage'],
            'amount_cents' => $applied, 'expected_cents' => $expected, 'received_cents' => $received, 'discrepancy_cents' => $difference,
            'source_event_id' => $account->events()->where('reference', $pending['reference'])->sole()->id,
            'evidence_reference' => $input['evidence_reference'], 'reason' => $input['reason'] ?? null,
        ], $reference);
    }

    private function cancel(CodAccount $account, User $actor, array $input): CodCashEvent
    {
        $pending = $this->pending($account, $input['offer_reference']);
        $manager = $actor->isLogistics() && $actor->canAccessPortal() && $this->eligibility->isCompanyAdministrator($actor, $account->logistics_company_id);
        // Cancelling an unconfirmed offer retains the original holder's entire cash responsibility.
        abort_unless(in_array($actor->id, [$pending['holder_id'], $pending['offered_by_id']], true) || $manager || $actor->isAdmin(), 403);
        $state = $account->state;
        $state['pending'] = null;

        return $this->append($account, $actor, 'handover_cancelled', $input, $state, [
            'from_user_id' => $pending['holder_id'], 'to_user_id' => $pending['recipient_id'], 'reason' => $input['reason'],
            'source_event_id' => $account->events()->where('reference', $pending['reference'])->sole()->id,
        ]);
    }

    private function adjust(CodAccount $account, User $actor, array $input): CodCashEvent
    {
        $state = $account->state;
        $source = $account->events()->where('reference', $input['source_reference'])->first();
        $difference = $state['discrepancies'][$input['source_reference']] ?? null;
        if (! $source || ! $difference || $difference['resolved_by'] || $input['amount'] !== abs($difference['difference_cents']) || $state['pending']) {
            throw new DomainException('Use an unresolved original difference and its exact amount, after pending handovers finish.');
        }
        if ($difference['difference_cents'] < 0) {
            $recovery = $account->events()->where('reference', $input['evidence_reference'])->where('event_type', 'handover_received')->first();
            $used = collect($state['discrepancies'])->pluck('recovery_evidence')->filter()->all();
            if ($input['adjustment_type'] !== 'shortage_recovered' || ! $recovery || $recovery->sequence <= $source->sequence
                || $recovery->from_user_id !== $source->from_user_id || $recovery->to_user_id !== $source->to_user_id
                || $recovery->from_stage !== $source->from_stage || $recovery->to_stage !== $source->to_stage
                || $recovery->discrepancy_cents !== 0 || $recovery->amount_cents !== $input['amount'] || in_array($recovery->reference, $used, true)) {
                throw new DomainException('A shortage needs a later verified receipt of the missing amount between the original parties.');
            }
            $state['discrepancies'][$source->reference]['recovery_evidence'] = $recovery->reference;
        } elseif ($input['adjustment_type'] !== 'overage_separated') {
            throw new DomainException('Record the overage as separate unallocated cash; it cannot increase the order’s COD amount.');
        }
        $reference = 'COD-'.strtoupper((string) Str::uuid());
        $state['discrepancies'][$source->reference]['resolved_by'] = $reference;

        // Review never creates cash. Excess remains separately held, and a shortage needs an actual recovery receipt.
        return $this->append($account, $actor, 'adjustment', $input, $state, [
            'amount_cents' => -$difference['difference_cents'], 'source_event_id' => $source->id,
            'from_user_id' => $source->from_user_id, 'to_user_id' => $source->to_user_id,
            'evidence_reference' => $input['evidence_reference'], 'reason' => $input['reason'],
        ], $reference);
    }

    private function reconcile(CodAccount $account, User $actor, array $input, Order $order): CodCashEvent
    {
        $state = $account->state;
        $platform = $this->atStage($state, 'platform');
        if ($order->payment_method !== 'cod' || CodMoney::cents($order->total_amount) !== $account->expected_cents
            || $state['collected_cents'] !== $account->expected_cents || $state['pending']
            || $platform !== $account->expected_cents || $this->atStage($state, 'rider') !== 0 || $this->atStage($state, 'hub') !== 0
            || collect($state['discrepancies'])->contains(fn ($difference) => ! $difference['resolved_by'])) {
            throw new DomainException('Reconciliation needs all original COD at the platform, confirmed receipts and resolved differences.');
        }
        $reference = 'COD-'.strtoupper((string) Str::uuid());
        $state['reconciled_cents'] = $account->expected_cents;
        $state['reconciled_reference'] = $reference;
        $event = $this->append($account, $actor, 'platform_reconciled', $input, $state, [
            'amount_cents' => $account->expected_cents, 'expected_cents' => $account->expected_cents,
            'evidence_reference' => $input['evidence_reference'], 'reason' => $input['reason'],
        ], $reference, true);
        $order->update(['payment_status' => 'paid']);

        return $event;
    }

    private function authorizeRecovery(CodAccount $account, User $actor, array $input): CodCashEvent
    {
        $stage = $this->holderStage($account, $input['holder_id']);
        $recipient = $this->current(User::findOrFail($input['recipient_id']));
        if ($stage !== 'rider' || ! $recipient->isAdmin() || ! $recipient->canAccessPortal() || $account->state['pending']
            || LogisticsHub::eligible()->whereKey($account->hub_id)->exists()) {
            throw new DomainException('Direct platform recovery is only for recorded rider cash whose original hub is restricted.');
        }
        $state = $account->state;
        $state['recovery'] = ['holder_id' => $input['holder_id'], 'recipient_id' => $recipient->id, 'expires_at' => now()->addDay()->toIso8601String()];

        return $this->append($account, $actor, 'recovery_authorized', $input, $state,
            ['from_user_id' => $input['holder_id'], 'to_user_id' => $recipient->id, 'reason' => $input['reason']]);
    }

    public function atStage(array $state, string $stage): int
    {
        return array_sum(array_filter($state['balances'], fn ($key) => str_starts_with($key, $stage.':'), ARRAY_FILTER_USE_KEY));
    }

    private function holderStage(CodAccount $account, int $holderId): string
    {
        foreach (['rider', 'hub'] as $stage) {
            if (($account->state['balances'][$stage.':'.$holderId] ?? 0) > 0) {
                return $stage;
            }
        }
        throw new DomainException('This person has no recorded rider or hub cash remaining on this order.');
    }

    private function pending(CodAccount $account, string $reference): array
    {
        $pending = $account->state['pending'];
        if (! $pending || $pending['reference'] !== $reference) {
            throw new DomainException('Refresh the current pending cash handover.');
        }

        return $pending;
    }

    public function canReceiveHub(User $actor, CodAccount $account): bool
    {
        return $actor->isLogistics() && $actor->canAccessPortal() && $actor->email_verified_at !== null
            && HubHandler::eligible()->where('user_id', $actor->id)->where('hub_id', $account->hub_id)
                ->where('logistics_company_id', $account->logistics_company_id)->exists();
    }

    private function lockNetwork(Delivery $parcel, array $ids, ?CodAccount $account = null): void
    {
        $companyId = $account?->logistics_company_id ?? $parcel->logistics_company_id;
        $hubId = $account?->hub_id ?? $parcel->destination_bayan_hub_id;
        $owner = LogisticsCompany::whereKey($companyId)->value('user_id');
        $ids = collect([...$ids, $owner])->filter()->unique()->sort()->values();
        User::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        // Known cash recovery locks the same resources as operations without restoring their eligibility.
        LogisticsCompany::whereKey($companyId)->lockForUpdate()->firstOrFail();
        LogisticsHub::whereKey($hubId)->lockForUpdate()->firstOrFail();
        CourierProfile::whereIn('user_id', $ids)->orderBy('id')->lockForUpdate()->get();
        HubHandler::whereIn('user_id', $ids)->orderBy('id')->lockForUpdate()->get();
    }

    private function fingerprint(User $actor, string $action, array $input): string
    {
        unset($input['request_token']);
        ksort($input);

        return hash('sha256', json_encode([$actor->id, $action, $input], JSON_THROW_ON_ERROR));
    }

    private function append(CodAccount $account, User $actor, string $type, array $input, array $state, array $fields = [], ?string $reference = null, bool $reconciled = false): CodCashEvent
    {
        $action = match ($type) {
            'handover_offered' => 'offer', 'handover_received' => 'receive', 'handover_cancelled' => 'cancel',
            'adjustment' => 'adjust', 'platform_reconciled' => 'reconcile', 'recovery_authorized' => 'authorize-recovery', default => $type,
        };
        $event = new CodCashEvent($fields + ['cod_account_id' => $account->id, 'sequence' => $account->version + 1,
            'actor_id' => $actor->id, 'event_type' => $type, 'provenance' => $actor->isAdmin() ? 'platform_admin' : 'cash_participant',
            'source_state' => $account->state, 'target_state' => $state, 'request_token' => $input['request_token'],
            'request_fingerprint' => $this->fingerprint($actor, $action, $input)]);
        $event->reference = $reference ?? 'COD-'.strtoupper((string) Str::uuid());
        $event->save();
        $account->state = $state;
        $account->version = $event->sequence;
        if ($reconciled) {
            $account->reconciled_at = $event->created_at;
        }
        $account->save();
        $this->notices->record($event);

        return $event;
    }
}

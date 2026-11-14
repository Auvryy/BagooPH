<?php

namespace App\Services\Finance;

use App\Models\BuyerReturnCase;
use App\Models\BuyerReturnMoneyEvent;
use App\Models\CodAccount;
use App\Models\Delivery;
use App\Models\LogisticsCompany;
use App\Models\LogisticsManifest;
use App\Models\Order;
use App\Models\SellerSettlement;
use App\Models\ShipmentFinancialEvent;
use App\Models\ShipmentFinancialPlan;
use App\Models\ShipmentLossReconciliation;
use App\Models\User;
use App\Models\VoucherOrderAllocation;
use App\Models\VoucherRevisionBalance;
use App\Rules\ApplicationText;
use App\Rules\AsciiPositiveInteger;
use App\Services\AccountRestrictionService;
use App\Services\BuyerAccessService;
use App\Services\Returns\BuyerReturnMoneyService;
use App\Services\Wallet\WalletCreditService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class ShipmentLossCash
{
    private const OFFERS = ['offer-company', 'offer-advance', 'offer-buyer-compensation-return', 'offer-seller-compensation-return'];

    private const RECEIPTS = ['receive-company', 'receive-advance', 'receive-buyer-compensation-return', 'receive-seller-compensation-return'];

    private const PAYMENTS = ['record-buyer-payment', 'record-seller-protection'];

    private const ACKS = ['confirm-buyer-receipt', 'confirm-seller-receipt', 'report-buyer-nonreceipt', 'report-seller-nonreceipt',
        'resolve-buyer-nonreceipt', 'resolve-seller-nonreceipt',
        'confirm-company-cash-return', 'report-company-cash-nonreceipt', 'resolve-company-cash-nonreceipt'];

    private function assertEvidence(ShipmentFinancialEvent $event): void
    {
        abort_unless($event->proof_path && str_starts_with($event->proof_path, 'shipment-finance/') && ! str_contains($event->proof_path, '..')
            && $event->proof_bytes > 0 && $event->proof_bytes <= 5242880 && $event->proof_hash
            && Storage::disk('local')->exists($event->proof_path) && Storage::disk('local')->size($event->proof_path) === (int) $event->proof_bytes
            && hash_equals($event->proof_hash, hash_file('sha256', Storage::disk('local')->path($event->proof_path))), 409,
            'Retain the original private transfer evidence before using this cash source.');
    }

    public function assertFundingEvidence(ShipmentFinancialPlan $plan): void
    {
        foreach ($plan->events()->whereIn('event_type', ['loss-receive-company', 'loss-receive-advance'])->get() as $receipt) {
            $offer = $plan->events()->whereKey($receipt->source_event_id)->firstOrFail();
            abort_unless($receipt->consumed_source_id === $offer->id && $offer->recipient_id === $receipt->actor_id
                && $offer->actor_id !== $receipt->actor_id && $receipt->amount_cents === $offer->amount_cents
                && $offer->event_type === str_replace('receive-', 'offer-', $receipt->event_type), 409,
                'Retain the actual original named receipt and its single consumed transfer source.');
            $this->assertEvidence($offer);
            $this->assertEvidence($receipt);
        }
    }

    private function paymentState(Collection $events, string $party): array
    {
        $type = $party === 'buyer' ? 'record-buyer-payment' : 'record-seller-protection';
        $payment = $events->firstWhere('event_type', 'loss-'.$type);
        $latest = $events->filter(fn ($event) => in_array($event->event_type, ['loss-'.$type, 'loss-confirm-'.$party.'-receipt',
            'loss-report-'.$party.'-nonreceipt', 'loss-resolve-'.$party.'-nonreceipt'], true))->last();
        $status = match ($latest?->event_type) {
            'loss-confirm-'.$party.'-receipt' => 'confirmed',
            'loss-report-'.$party.'-nonreceipt' => 'disputed',
            null => 'awaiting_payment',
            default => 'payment_recorded',
        };

        return ['payment_id' => $payment?->id, 'amount_cents' => $payment?->amount_cents ?? 0, 'status' => $status];
    }

    private function companyReturnStates(Collection $events): array
    {
        return $events->where('event_type', 'loss-record-company-cash-return')->map(function ($returnEvent) use ($events) {
            $latest = $events->filter(fn ($event) => $event->id === $returnEvent->id || ($event->source_event_id === $returnEvent->id
                && in_array($event->event_type, ['loss-confirm-company-cash-return', 'loss-report-company-cash-nonreceipt', 'loss-resolve-company-cash-nonreceipt'], true)))->last();
            $status = match ($latest?->event_type) {
                'loss-confirm-company-cash-return' => 'confirmed',
                'loss-report-company-cash-nonreceipt' => 'disputed',
                default => 'payment_recorded',
            };

            return ['id' => $returnEvent->id, 'amount_cents' => $returnEvent->amount_cents, 'cash_reference' => $returnEvent->cash_reference, 'status' => $status];
        })->values()->all();
    }

    public function state(ShipmentFinancialPlan $plan): array
    {
        abort_unless($plan->kind === 'transit_loss' && $plan->snapshot['payment_method'] === 'cod', 409);
        $events = $plan->events()->get();
        $pending = fn ($type) => $events->where('event_type', 'loss-'.$type)->filter(fn ($offer) => ! $events->contains('consumed_source_id', $offer->id));
        $company = (int) $events->where('event_type', 'loss-receive-company')->sum('amount_cents');
        $advances = (int) $events->where('event_type', 'loss-receive-advance')->sum('amount_cents');
        $retired = (int) $events->where('event_type', 'loss-retire-advance')->sum('amount_cents');
        $paid = (int) $events->whereIn('event_type', ['loss-record-buyer-payment', 'loss-record-seller-protection'])->sum('amount_cents');
        $buyerReturned = (int) $events->where('event_type', 'loss-receive-buyer-compensation-return')->sum('amount_cents');
        $sellerReturned = (int) $events->where('event_type', 'loss-receive-seller-compensation-return')->sum('amount_cents');
        $companyReturned = (int) $events->where('event_type', 'loss-record-company-cash-return')->sum('amount_cents');
        $buyer = $this->paymentState($events, 'buyer');
        $seller = $this->paymentState($events, 'seller');
        $companyReturns = $this->companyReturnStates($events);
        abort_unless($company >= 0 && $company <= $plan->snapshot['company_funding_due_cents'] && $retired >= 0 && $retired <= $advances
            && $companyReturned >= 0 && $companyReturned <= $company, 409,
            'Review the original loss funding and actual recorded advance or company returns.');
        $remainingPayments = ($buyer['payment_id'] ? 0 : $plan->snapshot['buyer_refund_cents'])
            + ($seller['payment_id'] ? 0 : $plan->snapshot['seller_protection_cents']);
        $balance = $company + $advances + $buyerReturned + $sellerReturned - $retired - $paid - $companyReturned;
        abort_unless($balance >= 0, 409, 'Original loss cash cannot spend another authority’s funding.');
        $resolved = $events->contains('event_type', 'loss-resolve-cash');
        $paidAny = $resolved || $buyer['payment_id'] !== null || $seller['payment_id'] !== null;

        return ['version' => (int) $events->max('sequence'), 'company_due_cents' => $plan->snapshot['company_funding_due_cents'],
            'company_received_cents' => $company, 'company_remaining_cents' => $plan->snapshot['company_funding_due_cents'] - $company,
            'pending_company_cents' => (int) $pending('offer-company')->sum('amount_cents'),
            'pending_advance_cents' => (int) $pending('offer-advance')->sum('amount_cents'),
            'advance_received_cents' => $advances, 'advance_returned_cents' => $retired, 'advance_outstanding_cents' => $advances - $retired,
            'received_backing_cents' => $company + $advances - $retired, 'balance_cents' => $balance,
            'protected_remaining_cents' => $remainingPayments + $plan->snapshot['protected_earned_shipping_cents'],
            'buyer' => $buyer, 'seller' => $seller, 'zero_refund_assessed' => $events->contains('event_type', 'loss-assess-zero-buyer'),
            'resolved' => $resolved, 'paid_any' => $paidAny,
            'buyer_compensation_returned_cents' => $buyerReturned, 'seller_compensation_returned_cents' => $sellerReturned,
            'pending_buyer_return_cents' => (int) $pending('offer-buyer-compensation-return')->sum('amount_cents'),
            'pending_seller_return_cents' => (int) $pending('offer-seller-compensation-return')->sum('amount_cents'),
            'company_cash_returned_cents' => $companyReturned, 'company_cash_net_cents' => $company - $companyReturned,
            'company_cash_returns' => $companyReturns,
            'has_company_return_dispute' => collect($companyReturns)->contains(fn ($row) => $row['status'] === 'disputed'),
            'has_unconfirmed_company_return' => collect($companyReturns)->contains(fn ($row) => $row['status'] === 'payment_recorded')];
    }

    private function inputs(string $action, array $input, ?UploadedFile $proof): array
    {
        $offer = in_array($action, self::OFFERS, true);
        $receipt = in_array($action, self::RECEIPTS, true);
        $payment = in_array($action, self::PAYMENTS, true);
        $retire = $action === 'retire-advance' || $action === 'record-company-cash-return';
        $ack = in_array($action, self::ACKS, true);
        $needsProof = $offer || $receipt || $payment || $retire || str_starts_with($action, 'resolve-') && $ack;
        $needsAmount = in_array($action, ['offer-company', 'offer-advance', 'retire-advance', 'record-company-cash-return'], true);
        $needsCashRef = $offer || $payment || $retire;
        foreach (['reason', 'cash_reference'] as $field) {
            if (is_string($input[$field] ?? null)) {
                $input[$field] = trim($input[$field]);
            }
        }
        if (isset($input['cash_reference'])) {
            $input['cash_reference'] = Str::upper($input['cash_reference']);
        }
        $data = Validator::make([...$input, 'proof' => $proof], ['expected_version' => ['required', new AsciiPositiveInteger, 'integer'],
            'request_token' => ['required', 'uuid'], 'reason' => ['required', new ApplicationText('notes', 10, 1000)],
            'amount' => $needsAmount ? ['required', 'string', 'regex:'.CodMoney::RULE] : ['prohibited'],
            'recipient_id' => $offer ? ['required', new AsciiPositiveInteger, 'integer'] : ['prohibited'],
            'source_event_id' => $receipt || $ack || $action === 'retire-advance' ? ['required', new AsciiPositiveInteger, 'integer'] : ['prohibited'],
            'cash_reference' => $needsCashRef ? ['required', 'string', 'min:3', 'max:120', 'regex:/\A[A-Z0-9][A-Z0-9 ._:\/\-]*\z/'] : ['prohibited'],
            'proof' => [$needsProof ? 'required' : 'prohibited', 'nullable', 'file', 'max:5120'],
            'payment_confirmed' => $needsProof ? ['required', 'accepted'] : ['prohibited'],
            'zero_refund_confirmed' => $action === 'assess-zero-buyer' ? ['required', 'accepted'] : ['prohibited'],
            'financial_resolution_confirmed' => $action === 'resolve-cash' ? ['required', 'accepted'] : ['prohibited'],
            'amount_cents' => ['prohibited'], 'buyer_id' => ['prohibited'], 'seller_id' => ['prohibited'], 'company_id' => ['prohibited'],
            'status' => ['prohibited'], 'created_at' => ['prohibited']])->validate();
        unset($data['proof']);
        foreach (['expected_version', 'recipient_id', 'source_event_id'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = (int) $data[$field];
            }
        }
        $data['request_token'] = strtolower($data['request_token']);
        foreach (['payment_confirmed', 'zero_refund_confirmed', 'financial_resolution_confirmed'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = true;
            }
        }

        return $data;
    }

    private function authorize(User $actor, ShipmentFinancialPlan $plan, string $action): User
    {
        $actor = User::findOrFail($actor->id);
        abort_unless($actor->closed_at === null && $actor->email_verified_at, 403);
        if (in_array($action, ['offer-company', 'confirm-company-cash-return', 'report-company-cash-nonreceipt'], true)) {
            abort_unless($actor->isLogistics() && $actor->isKycApproved()
                && LogisticsCompany::whereKey($plan->logistics_company_id)->where('user_id', $actor->id)->exists(), 403);
        } elseif (in_array($action, ['offer-buyer-compensation-return', 'confirm-buyer-receipt', 'report-buyer-nonreceipt'], true)) {
            app(BuyerAccessService::class)->requireOrderHistory($actor);
            abort_unless($actor->id === $plan->buyer_id, 403);
        } elseif (in_array($action, ['offer-seller-compensation-return', 'confirm-seller-receipt', 'report-seller-nonreceipt'], true)) {
            abort_unless($actor->isSeller() && $actor->isKycApproved() && $actor->id === $plan->seller_id, 403);
        } else {
            app(WalletCreditService::class)->admin($actor);
        }

        return $actor;
    }

    public function command(User $actor, ShipmentFinancialPlan $original, string $action, array $input, ?UploadedFile $proof = null): ShipmentFinancialEvent
    {
        abort_unless(in_array($action, [...self::OFFERS, ...self::RECEIPTS, ...self::PAYMENTS, ...self::ACKS,
            'assess-zero-buyer', 'resolve-cash', 'retire-advance', 'record-company-cash-return'], true), 404);
        $data = $this->inputs($action, $input, $proof);
        $files = app(ShipmentFinanceFiles::class);
        $info = $proof ? $files->inspect($proof) : null;
        $token = hash('sha256', $data['request_token']);
        $fingerprint = hash('sha256', json_encode([$original->id, $actor->id, $action, $data, $info['proof_hash'] ?? null], JSON_THROW_ON_ERROR));
        $paths = [];
        try {
            $result = DB::transaction(function () use ($actor, $original, $action, $data, $proof, $info, $files, $token, $fingerprint, &$paths) {
                app(AccountRestrictionService::class)->lockGuard();
                $order = Order::whereKey($original->order_id)->lockForUpdate()->firstOrFail();
                Delivery::whereKey($original->delivery_id)->lockForUpdate()->firstOrFail();
                User::whereIn('id', array_filter([$actor->id, $original->buyer_id, $original->seller_id, $data['recipient_id'] ?? null]))->orderBy('id')->lockForUpdate()->get();
                CodAccount::where('order_id', $order->id)->lockForUpdate()->first();
                SellerSettlement::where('order_id', $order->id)->lockForUpdate()->first();
                $plan = ShipmentFinancialPlan::whereKey($original->id)->lockForUpdate()->firstOrFail();
                abort_unless($plan->kind === 'transit_loss' && $plan->snapshot['payment_method'] === 'cod', 409, 'Use the original purchased payment method.');
                LogisticsCompany::whereKey($plan->logistics_company_id)->lockForUpdate()->firstOrFail();
                LogisticsManifest::whereKey($plan->snapshot['source']['manifest_id'])->lockForUpdate()->firstOrFail();
                if ($plan->buyer_return_case_id) {
                    $case = BuyerReturnCase::whereKey($plan->buyer_return_case_id)->lockForUpdate()->firstOrFail();
                    $case->parcel()->lockForUpdate()->firstOrFail();
                }
                $actor = $this->authorize($actor, $plan, $action);
                if ($prior = ShipmentFinancialEvent::where('request_token_hash', $token)->first()) {
                    abort_unless($prior->shipment_financial_plan_id === $plan->id && $prior->actor_id === $actor->id && hash_equals($prior->request_fingerprint, $fingerprint), 409,
                        'Retain the exact original actual cash request and evidence.');

                    return $prior;
                }
                app(ShipmentRefusalReview::class)->unused($token);
                $state = $this->state($plan);
                abort_unless(($plan->active_order_id || $plan->active_return_case_id) && $state['version'] === $data['expected_version'], 409, 'Review the original active loss and current financial history.');
                $released = Schema::hasTable('shipment_loss_reconciliations')
                    && ShipmentLossReconciliation::where('shipment_financial_plan_id', $plan->id)->where('status', 'released')->exists();
                abort_if($released, 409, 'Quarantine was already reconciled and released.');
                $found = app(ShipmentLossSource::class)->finding($plan) !== null;
                $foundOnlyActions = ['offer-buyer-compensation-return', 'receive-buyer-compensation-return',
                    'offer-seller-compensation-return', 'receive-seller-compensation-return',
                    'record-company-cash-return', 'confirm-company-cash-return', 'report-company-cash-nonreceipt', 'resolve-company-cash-nonreceipt'];
                if ($found) {
                    app(ShipmentLossSource::class)->assertQuarantined($plan);
                    if (! $state['paid_any']) {
                        abort_unless(in_array($action, ['receive-company', 'receive-advance', 'retire-advance',
                            'record-company-cash-return', 'confirm-company-cash-return', 'report-company-cash-nonreceipt', 'resolve-company-cash-nonreceipt'], true), 409,
                            'Found-parcel quarantine requires retiring advances and returning unused company cash instead of new payouts.');
                    } else {
                        abort_unless(in_array($action, ['offer-company', 'receive-company', 'receive-advance', 'retire-advance',
                            ...$foundOnlyActions, ...self::ACKS], true), 409,
                            'Found-parcel quarantine retains the existing paid outcome and permits only advance retirement, voluntary compensation returns, or company cash return reconciliation.');
                        if ($action === 'offer-company') {
                            abort_unless($state['advance_outstanding_cents'] > 0, 409, 'Offer additional company cash only while an advance remains outstanding.');
                        }
                    }
                } else {
                    abort_if(in_array($action, $foundOnlyActions, true), 409,
                        'Record an actual found-parcel quarantine intake before reconciling cash returns.');
                    if (! in_array($action, [...self::RECEIPTS, ...self::ACKS], true)) {
                        app(ShipmentLossSource::class)->assertApproved($plan);
                    }
                    if ($state['resolved']) {
                        abort_unless(in_array($action, ['offer-company', 'receive-company', 'receive-advance', 'retire-advance'], true), 409, 'Retain the original completed cash outcome.');
                    }
                }
                $source = isset($data['source_event_id']) ? $plan->events()->whereKey($data['source_event_id'])->firstOrFail() : null;
                $amount = 0;
                $recipient = null;
                $context = ['plan_reference' => $plan->reference, 'no_physical_receipt' => true, 'demo_only' => false];
                $consume = null;
                if (in_array($action, ['offer-buyer-compensation-return', 'offer-seller-compensation-return'], true)) {
                    $recipient = app(WalletCreditService::class)->admin(User::findOrFail($data['recipient_id']))->id;
                    abort_unless($recipient !== $actor->id, 403, 'Name the distinct current admin who actually received this transfer.');
                    $isBuyer = $action === 'offer-buyer-compensation-return';
                    $part = $isBuyer ? 'buyer' : 'seller';
                    $amount = $plan->snapshot[$isBuyer ? 'buyer_refund_cents' : 'seller_protection_cents'];
                    abort_unless($amount > 0 && $state[$part]['payment_id'] !== null && $state[$part]['status'] === 'confirmed'
                        && $state[$part.'_compensation_returned_cents'] === 0 && $state['pending_'.$part.'_return_cents'] === 0, 409,
                        'Only a confirmed actual compensation payout may be voluntarily returned once.');
                    $context += ['company_id' => $plan->logistics_company_id, 'source_role' => $actor->role, 'voluntary_owner_return' => true, 'actually_received_cents' => 0];
                } elseif (in_array($action, ['offer-company', 'offer-advance'], true)) {
                    $recipient = app(WalletCreditService::class)->admin(User::findOrFail($data['recipient_id']))->id;
                    abort_unless($recipient !== $actor->id, 403, 'Name the distinct current admin who actually received this transfer.');
                    $amount = CodMoney::cents($data['amount']);
                    $companyCap = $found && $state['paid_any']
                        ? min($state['company_remaining_cents'], $state['advance_outstanding_cents'])
                        : $state['company_remaining_cents'];
                    $maximum = $action === 'offer-company' ? $companyCap - $state['pending_company_cents']
                        : $state['company_due_cents'] - $state['company_received_cents'] - $state['advance_outstanding_cents'] - $state['pending_advance_cents'];
                    abort_unless($amount > 0 && $amount <= $maximum, 409, 'Offer only the remaining original company liability or required actual advance.');
                    $context += ['company_id' => $plan->logistics_company_id, 'source_role' => $actor->role, 'actually_received_cents' => 0];
                } elseif (in_array($action, self::RECEIPTS, true)) {
                    $expected = 'loss-'.str_replace('receive-', 'offer-', $action);
                    abort_unless($source->event_type === $expected && $source->recipient_id === $actor->id && $source->actor_id !== $actor->id
                        && ($source->context['plan_reference'] ?? null) === $plan->reference
                        && ! $plan->events()->where('consumed_source_id', $source->id)->exists(), 409, 'Only the named actual receiving admin may acknowledge the original transfer once.');
                    if ($action === 'receive-company') {
                        abort_unless(($source->context['company_id'] ?? null) === $plan->logistics_company_id
                            && ($source->context['source_role'] ?? null) === 'logistics' && $source->amount_cents <= $state['company_remaining_cents'], 409);
                    } elseif ($action === 'receive-buyer-compensation-return') {
                        abort_unless($source->actor_id === $plan->buyer_id && $state['buyer_compensation_returned_cents'] === 0
                            && $source->amount_cents === $plan->snapshot['buyer_refund_cents'], 409);
                    } elseif ($action === 'receive-seller-compensation-return') {
                        abort_unless($source->actor_id === $plan->seller_id && $state['seller_compensation_returned_cents'] === 0
                            && $source->amount_cents === $plan->snapshot['seller_protection_cents'], 409);
                    }
                    $this->assertEvidence($source);
                    $amount = $source->amount_cents;
                    $recipient = $actor->id;
                    $consume = $source->id;
                    $context += ['source_reference' => $source->cash_reference, 'received_from_id' => $source->actor_id,
                        'actually_received_cents' => $amount, 'company_id' => $plan->logistics_company_id,
                        'original_transfer_receipt_only' => true, 'no_new_beneficiary_payment_authorized' => true];
                } elseif (in_array($action, self::PAYMENTS, true)) {
                    $this->assertFundingEvidence($plan);
                    $buyer = $action === 'record-buyer-payment';
                    $part = $buyer ? 'buyer' : 'seller';
                    $amount = $plan->snapshot[$buyer ? 'buyer_refund_cents' : 'seller_protection_cents'];
                    $recipient = $buyer ? $plan->buyer_id : $plan->seller_id;
                    abort_unless($amount > 0 && ! $state[$part]['payment_id'] && $state['received_backing_cents'] >= $plan->snapshot['reserve_goal_cents']
                        && $state['balance_cents'] >= $state['protected_remaining_cents'], 409, 'Retain actual received backing for every unpaid original recipient and earned shipping; offers and liability are not received funds.');
                    if ($buyer) {
                        $cash = app(BuyerReturnMoneyService::class)->cashEvidence($order->fresh());
                        abort_unless($cash['verified'], 409, 'Verify the actual original COD collection and acknowledged platform reconciliation before repaying.');
                        $already = (int) BuyerReturnMoneyEvent::where('order_id', $order->id)->where('event_type', 'record-repayment')->sum('amount_cents')
                            + (int) ShipmentFinancialEvent::whereIn('shipment_financial_plan_id', ShipmentFinancialPlan::where('order_id', $order->id)->select('id'))
                                ->where('event_type', 'loss-record-buyer-payment')->sum('amount_cents');
                        abort_unless($already + $amount <= $plan->snapshot['actual_buyer_paid_cents'], 409, 'Buyer cash repayments cannot exceed the original actual payment.');
                        $context['original_cod_evidence'] = $cash;
                    }
                    $context += ['funding' => $state, 'original_recipient_id' => $recipient, 'separate_seller_protection' => ! $buyer];
                } elseif ($action === 'assess-zero-buyer') {
                    abort_unless($plan->snapshot['buyer_refund_cents'] === 0 && ! $state['zero_refund_assessed'] && ! $state['buyer']['payment_id'], 409,
                        'Only an original zero refund can be assessed without a cash transfer.');
                    $context += ['no_buyer_refund_due' => true, 'actual_original_buyer_paid_cents' => $plan->snapshot['actual_buyer_paid_cents']];
                } elseif ($action === 'resolve-cash') {
                    $this->assertFundingEvidence($plan);
                    abort_unless($state['received_backing_cents'] >= $plan->snapshot['reserve_goal_cents']
                        && $state['balance_cents'] >= $plan->snapshot['protected_earned_shipping_cents']
                        && ($plan->snapshot['buyer_refund_cents'] > 0 ? $state['buyer']['status'] === 'confirmed' : $state['zero_refund_assessed'])
                        && ($plan->snapshot['seller_protection_cents'] === 0 || $state['seller']['status'] === 'confirmed'), 409,
                        'Retain actual original funding, recipient acknowledgements and earned shipping before resolving the loss finances.');
                    $context += ['funding' => $state, 'retained_earned_shipping_cents' => $plan->snapshot['protected_earned_shipping_cents'],
                        'no_new_cash_transfer' => true];
                    if (! $plan->buyer_return_case_id) {
                        $this->releasePromotions($plan, $actor);
                    }
                } elseif ($action === 'retire-advance') {
                    $this->assertFundingEvidence($plan);
                    abort_unless($source->event_type === 'loss-receive-advance', 409);
                    $offer = $plan->events()->whereKey($source->source_event_id)->where('event_type', 'loss-offer-advance')->firstOrFail();
                    $recipient = $offer->actor_id;
                    abort_unless($recipient !== $actor->id, 403, 'A distinct current admin must record the actual return to the original advance funder.');
                    $amount = CodMoney::cents($data['amount']);
                    $returned = (int) $plan->events()->where('event_type', 'loss-retire-advance')->where('source_event_id', $source->id)->sum('amount_cents');
                    $protectedHold = $found
                        ? ($state['resolved'] ? $plan->snapshot['protected_earned_shipping_cents'] : 0)
                        : $state['protected_remaining_cents'];
                    abort_unless($amount > 0 && $amount <= $source->amount_cents - $returned && $amount <= $state['advance_outstanding_cents']
                        && $amount <= max(0, $state['balance_cents'] - $protectedHold), 409,
                        'Record only an actual excess advance return after protecting every unpaid recipient and earned shipping.');
                    $context += ['source_reference' => $offer->cash_reference, 'original_advance_receipt_id' => $source->id, 'funding' => $state];
                } elseif ($action === 'record-company-cash-return') {
                    abort_if(in_array($actor->id, $plan->snapshot['source_actor_ids'] ?? [], true), 403,
                        'An independent current admin must return unused company cash.');
                    $this->assertFundingEvidence($plan);
                    abort_unless($state['advance_outstanding_cents'] === 0, 409, 'Retire every outstanding advance before returning cash to the company.');
                    abort_if($state['has_company_return_dispute'] || $state['buyer']['status'] === 'disputed' || $state['seller']['status'] === 'disputed', 409,
                        'Resolve open nonreceipt disputes before returning cash to the company.');
                    $protectedHold = $state['resolved'] ? $plan->snapshot['protected_earned_shipping_cents'] : 0;
                    $freeCash = max(0, $state['balance_cents'] - $protectedHold);
                    $maximum = min($freeCash, $state['company_cash_net_cents']);
                    $amount = CodMoney::cents($data['amount']);
                    abort_unless($amount > 0 && $amount <= $maximum, 409, 'Return only actual available surplus cash up to the net received company contribution.');
                    $ownerId = (int) LogisticsCompany::whereKey($plan->logistics_company_id)->value('user_id');
                    abort_unless($ownerId > 0 && $ownerId !== $actor->id, 403);
                    $recipient = $ownerId;
                    $context += ['company_id' => $plan->logistics_company_id, 'company_cash_return' => true, 'no_parcel_release' => true];
                } elseif (str_ends_with($action, '-company-cash-return') || str_ends_with($action, '-company-cash-nonreceipt')) {
                    if ($action === 'resolve-company-cash-nonreceipt') {
                        abort_if(in_array($actor->id, $plan->snapshot['source_actor_ids'] ?? [], true), 403,
                            'An independent current admin must resolve disputed company cash return evidence.');
                    }
                    $ownerId = (int) LogisticsCompany::whereKey($plan->logistics_company_id)->value('user_id');
                    abort_unless($source->event_type === 'loss-record-company-cash-return' && $source->recipient_id === $ownerId, 409,
                        'Select an actual recorded company cash return transfer.');
                    $current = collect($state['company_cash_returns'])->firstWhere('id', $source->id);
                    $status = $current['status'] ?? null;
                    $recipient = $ownerId;
                    abort_unless($status !== null && (str_starts_with($action, 'resolve-') ? $status === 'disputed'
                        : (str_starts_with($action, 'confirm-') ? in_array($status, ['payment_recorded', 'disputed'], true) : $status === 'payment_recorded')), 409,
                        'Review the current company cash return receipt or nonreceipt history.');
                    $context += ['original_payment_reference' => $source->cash_reference, 'company_cash_return' => true, 'no_second_payment' => true];
                } else {
                    $party = str_contains($action, 'buyer') ? 'buyer' : 'seller';
                    $type = $party === 'buyer' ? 'loss-record-buyer-payment' : 'loss-record-seller-protection';
                    $recipient = $party === 'buyer' ? $plan->buyer_id : $plan->seller_id;
                    abort_unless($source->event_type === $type && $source->recipient_id === $recipient
                        && $source->amount_cents === $plan->snapshot[$party === 'buyer' ? 'buyer_refund_cents' : 'seller_protection_cents'], 409,
                        'Select the exact original payment to this beneficiary.');
                    $status = $state[$party]['status'];
                    abort_unless(str_starts_with($action, 'resolve-') ? $status === 'disputed'
                        : (str_starts_with($action, 'confirm-') ? in_array($status, ['payment_recorded', 'disputed'], true) : $status === 'payment_recorded'), 409,
                        'Review the original recipient confirmation or nonreceipt history.');
                    $context += ['original_payment_reference' => $source->cash_reference, 'no_second_payment' => true];
                }
                if (isset($data['cash_reference'])) {
                    abort_if(ShipmentFinancialEvent::where('cash_reference', $data['cash_reference'])->exists()
                        || BuyerReturnMoneyEvent::where('cash_reference', $data['cash_reference'])->exists(), 409, 'Retain the original unique actual transfer reference across return and shipment finances.');
                }
                $path = $proof ? $files->store($proof, $info) : null;
                if ($path) {
                    $paths[] = $path;
                }

                return ShipmentFinancialEvent::create(['shipment_financial_plan_id' => $plan->id, 'actor_id' => $actor->id, 'event_type' => 'loss-'.$action,
                    'sequence' => $state['version'] + 1, 'amount_cents' => $amount, 'recipient_id' => $recipient, 'source_event_id' => $source?->id,
                    'consumed_source_id' => $consume, 'cash_reference' => $data['cash_reference'] ?? null, 'reason' => $data['reason'],
                    'proof_path' => $path, ...($info ? array_diff_key($info, ['extension' => true]) : []), 'context' => $context,
                    'request_token_hash' => $token, 'request_fingerprint' => $fingerprint, 'created_at' => now()]);
            }, 3);
            $files->discardUnused($paths);

            return $result;
        } catch (Throwable $error) {
            $files->discardUnused($paths);
            throw $error;
        }
    }

    private function releasePromotions(ShipmentFinancialPlan $plan, User $actor): void
    {
        $parts = VoucherOrderAllocation::where('order_id', $plan->order_id)->with(['redemption', 'fundingEntries'])->orderBy('id')->get();
        $balances = VoucherRevisionBalance::whereIn('voucher_revision_id', $parts->pluck('redemption.voucher_revision_id')->unique())
            ->orderBy('voucher_revision_id')->lockForUpdate()->get()->keyBy('voucher_revision_id');
        foreach ($parts as $part) {
            $source = $part->fundingEntries->firstWhere('event_type', 'commitment');
            $amount = $part->seller_expense_cents + $part->platform_expense_cents;
            abort_unless($source && $source->amount_cents === $amount
                && ! $part->fundingEntries->contains(fn ($entry) => in_array($entry->event_type, ['expense', 'release'], true))
                && ! DB::table('wallet_promotion_expenses')->where('voucher_order_allocation_id', $part->id)->exists()
                && ! DB::table('wallet_voucher_releases')->where('voucher_order_allocation_id', $part->id)->exists(), 409, 'Retain the original unused funding before loss release.');
            $balance = $balances[$part->redemption->voucher_revision_id];
            abort_unless($balance && $balance->committed_cents >= $amount, 409);
            $part->fundingEntries()->create(['reference' => 'VFD-'.Str::upper((string) Str::uuid()), 'event_type' => 'release', 'source_entry_id' => $source->id,
                'actor_id' => $actor->id, 'amount_cents' => $amount, 'reason' => 'Unused original commitment released after reviewed loss '.$plan->reference.'.']);
            $balance->decrement('committed_cents', $amount);
            $balance->increment('released_cents', $amount);
        }
    }
}

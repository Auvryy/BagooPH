<?php

namespace App\Services\Finance;

use App\Models\ShipmentFinancialPlan;
use App\Models\ShipmentLossAccounting;
use App\Models\ShipmentLossDisposition;
use App\Models\User;
use App\Models\WalletRefundCreditRequest;
use App\Models\WalletRefundReserveEntry;
use App\Models\WalletTransaction;
use App\Services\Returns\BuyerReturnMoneyService;
use App\Services\Wallet\WalletFundingCredits;
use App\Services\Wallet\WalletLedger;
use App\Services\Wallet\WalletLossCreditService;
use App\Services\Wallet\WalletLossGrantReturn;
use App\Services\Wallet\WalletLossSourceReturn;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ShipmentLossControls
{
    public function present(User $actor, ShipmentFinancialPlan $plan, array $state, Collection $events): array
    {
        $wallet = $plan->snapshot['payment_method'] === 'wallet';
        $admin = $actor->isAdmin();
        $company = $actor->isLogistics();
        $active = (bool) ($plan->active_order_id || $plan->active_return_case_id);
        $finding = app(ShipmentLossSource::class)->finding($plan);
        $sourceReady = false;
        $hold = $finding ? 'The found parcel is in quarantine. Independent reconciliation is required before new funding or compensation.' : null;
        if ($active) {
            try {
                app(ShipmentLossSource::class)->assertApproved($plan);
                $sourceReady = true;
            } catch (HttpExceptionInterface $error) {
                if ($error->getStatusCode() !== 409) {
                    throw $error;
                }
                $hold = $admin || $company ? $error->getMessage() : 'The original loss needs reconciliation before another payment update.';
            }
        }
        $actions = [];
        $add = function (string $action, ?int $maximum = null, array $sources = []) use (&$actions, $wallet) {
            $actions[] = app(ShipmentLossActions::class)->definition($action, $wallet ? 'wallet' : 'cod')
                + ['maximum_cents' => $maximum, 'sources' => $sources];
        };
        $disposition = ShipmentLossDisposition::where('shipment_financial_plan_id', $plan->id)->first();
        $accounting = Schema::hasTable('shipment_loss_accountings')
            ? ShipmentLossAccounting::where('shipment_financial_plan_id', $plan->id)->first() : null;
        $requests = WalletRefundCreditRequest::where('shipment_financial_plan_id', $plan->id)->whereNull('repayment_plan_id')
            ->where('authority_reference', $plan->reference)->orderByDesc('id')->get();
        $balance = $wallet && $company ? app(WalletLedger::class)->balance($actor) : null;
        $available = 0;
        if ($wallet && $company && $balance !== null) {
            try {
                $available = app(WalletFundingCredits::class)->available($actor, $plan->reference);
            } catch (HttpExceptionInterface $error) {
                if ($error->getStatusCode() !== 409) {
                    throw $error;
                }
                $hold ??= $error->getMessage();
            }
        }
        if ($active && $wallet) {
            if ($company && $finding) {
                try {
                    $sources = app(WalletLossGrantReturn::class)->sources($actor, $plan);
                    if ($sources) {
                        $add('return-unused-credit', null, $sources);
                    }
                    if (! $state['resolved'] && ($sources = app(WalletLossSourceReturn::class)->sources($actor, $plan))) {
                        $add('return-company-source', null, $sources);
                    }
                } catch (HttpExceptionInterface $error) {
                    if ($error->getStatusCode() !== 409) {
                        throw $error;
                    }
                    $hold = $error->getMessage();
                }
            }
            if ($company && $sourceReady && $state['company_remaining_cents'] > 0 && $available > 0) {
                $add('contribute-company', min($available, $state['company_remaining_cents']));
            }
            if ($admin && $sourceReady && ! $state['resolved']) {
                if ($state['original_required_cents'] > $state['original_reserved_cents']) {
                    $add('reserve-original');
                }
                $advance = $state['company_due_cents'] - $state['company_received_cents'] - $state['advance_outstanding_cents'];
                if ($advance > 0) {
                    $add('approve-advance', $advance);
                }
                if ($state['original_required_cents'] === $state['original_reserved_cents'] && $state['balance_cents'] >= $state['reserve_goal_cents']
                    && $state['company_due_cents'] <= $state['company_received_cents'] + $state['advance_outstanding_cents']) {
                    $add('resolve-wallet');
                }
            }
        }
        if ($active && ! $wallet) {
            $this->cash($actor, $plan, $state, $events, $sourceReady, $add, $hold);
        }
        if ($admin && $active) {
            if (! $disposition && ! $events->contains(fn ($event) => $event->amount_cents > 0 || $event->wallet_transaction_id)
                && ! WalletRefundReserveEntry::where('shipment_financial_plan_id', $plan->id)->exists() && ! $requests->contains('status', 'pending')) {
                $add('void-unfunded');
            }
            if ($sourceReady && ! $disposition && ! in_array($actor->id, $plan->snapshot['source_actor_ids'], true)) {
                $add('record-disposition');
            }
            if ($sourceReady && $disposition && ! $accounting && ! in_array($actor->id, $plan->snapshot['source_actor_ids'], true)) {
                $add('account-loss');
            }
        }
        $credits = null;
        if ($wallet && ($admin || $company)) {
            $maximum = 0;
            if ($company && $active && $sourceReady && ! $requests->contains('status', 'pending')) {
                try {
                    $maximum = app(WalletLossCreditService::class)->maximum($actor, $plan);
                } catch (HttpExceptionInterface $error) {
                    if ($error->getStatusCode() !== 409) {
                        throw $error;
                    }
                    $hold ??= $error->getMessage();
                }
            }
            $credits = ['maximum_cents' => $maximum, 'wallet_balance_cents' => $balance, 'available_for_authority_cents' => $company ? $available : null,
                'requests' => $requests->map(fn ($request) => $request->only(['id', 'reference', 'status', 'version', 'amount_cents', 'reason', 'review_reason'])
                    + ['can_review' => $admin && $active && $request->status === 'pending' && $request->actor_id !== $actor->id,
                        'can_approve' => $admin && $active && $request->status === 'pending' && $request->actor_id !== $actor->id && $sourceReady,
                        'wallet_reference' => $request->wallet_transaction_id ? WalletTransaction::find($request->wallet_transaction_id)?->reference : null])->all()];
        }
        $offer = collect($actions)->contains('receiver', true);
        $receivers = $offer ? User::where('role', 'admin')->whereNotNull('email_verified_at')->whereKeyNot($actor->id)->orderBy('id')->get()
            ->filter(fn ($user) => $user->canAccessPortal())->map(fn ($user) => $user->only(['id', 'name']))->values()->all() : [];

        return ['version' => (int) $events->max('sequence'), 'actions' => $actions, 'receivers' => $receivers, 'hold_message' => $hold,
            'credits' => $credits, 'disposition' => $admin && $disposition ? ['reference' => $disposition->reference,
                'proof_url' => '/shipment-finances/'.$plan->id.'/disposition/proof'] : null,
            'finding' => $admin && $finding ? ['reference' => $finding->reference, 'proof_url' => '/shipment-finances/'.$plan->id.'/finding/proof'] : null,
            'accounting' => $admin && $accounting ? ['reference' => $accounting->reference,
                'verified' => app(ShipmentLossAccountingService::class)->verified($accounting)] : null,
            'funding' => $admin || $company ? ['Available backing' => $state['balance_cents'], 'Company funding outstanding' => $active ? $state['company_remaining_cents'] : 0,
                'Advance outstanding' => $active ? $state['advance_outstanding_cents'] : 0] : null];
    }

    private function cash(User $actor, ShipmentFinancialPlan $plan, array $state, Collection $events, bool $sourceReady, callable $add, ?string &$hold): void
    {
        $admin = $actor->isAdmin();
        $found = app(ShipmentLossSource::class)->finding($plan) !== null;
        $payable = $sourceReady;
        if ($actor->isLogistics() && $payable && $state['company_remaining_cents'] > $state['pending_company_cents']) {
            $add('offer-company', $state['company_remaining_cents'] - $state['pending_company_cents']);
        }
        $fundingReady = true;
        try {
            app(ShipmentLossCash::class)->assertFundingEvidence($plan);
        } catch (HttpExceptionInterface $error) {
            if ($error->getStatusCode() !== 409) {
                throw $error;
            }
            $fundingReady = false;
            if ($admin) {
                $hold ??= $error->getMessage();
            }
        }
        if ($admin) {
            foreach (['company', 'advance'] as $kind) {
                $pending = $events->where('event_type', 'loss-offer-'.$kind)->where('recipient_id', $actor->id)
                    ->filter(fn ($event) => ! $events->contains('consumed_source_id', $event->id));
                if ($pending->isNotEmpty()) {
                    $add('receive-'.$kind, null, $pending->map(fn ($event) => ['id' => $event->id,
                        'label' => $event->cash_reference, 'amount_cents' => $event->amount_cents])->values()->all());
                }
            }
            $advance = $state['company_due_cents'] - $state['company_received_cents'] - $state['advance_outstanding_cents'] - $state['pending_advance_cents'];
            if ($sourceReady && ! $state['resolved'] && $advance > 0) {
                $add('offer-advance', $advance);
            }
            if ($sourceReady && ! $state['resolved']) {
                foreach (['buyer' => 'record-buyer-payment', 'seller' => 'record-seller-protection'] as $party => $action) {
                    $amount = $plan->snapshot[$party === 'buyer' ? 'buyer_refund_cents' : 'seller_protection_cents'];
                    if ($fundingReady && $amount > 0 && ! $state[$party]['payment_id'] && $state['received_backing_cents'] >= $plan->snapshot['reserve_goal_cents']
                        && $state['balance_cents'] >= $state['protected_remaining_cents']
                        && ($party !== 'buyer' || app(BuyerReturnMoneyService::class)->cashEvidence($plan->order)['verified'])) {
                        $add($action);
                    }
                    if ($state[$party]['status'] === 'disputed') {
                        $add('resolve-'.$party.'-nonreceipt', null, [['id' => $state[$party]['payment_id'], 'label' => 'Original '.$party.' payment', 'amount_cents' => $state[$party]['amount_cents']]]);
                    }
                }
                if ($plan->snapshot['buyer_refund_cents'] === 0 && ! $state['zero_refund_assessed']) {
                    $add('assess-zero-buyer');
                }
                if ($fundingReady && $state['received_backing_cents'] >= $plan->snapshot['reserve_goal_cents']
                    && $state['balance_cents'] >= $plan->snapshot['protected_earned_shipping_cents']
                    && ($plan->snapshot['buyer_refund_cents'] > 0 ? $state['buyer']['status'] === 'confirmed' : $state['zero_refund_assessed'])
                    && ($plan->snapshot['seller_protection_cents'] === 0 || $state['seller']['status'] === 'confirmed')) {
                    $add('resolve-cash');
                }
            }
            if ($found && ! $state['resolved']) {
                foreach (['buyer', 'seller'] as $party) {
                    if ($state[$party]['status'] === 'disputed') {
                        $add('resolve-'.$party.'-nonreceipt', null, [['id' => $state[$party]['payment_id'], 'label' => 'Original '.$party.' payment', 'amount_cents' => $state[$party]['amount_cents']]]);
                    }
                }
            }
            if ($payable && $fundingReady) {
                $free = max(0, $state['balance_cents'] - $state['protected_remaining_cents']);
                $sources = $events->where('event_type', 'loss-receive-advance')->map(function ($receipt) use ($actor, $events, $free, $state) {
                    $offer = $events->firstWhere('id', $receipt->source_event_id);
                    $remaining = $receipt->amount_cents - (int) $events->where('event_type', 'loss-retire-advance')->where('source_event_id', $receipt->id)->sum('amount_cents');
                    $maximum = min($free, $remaining, $state['advance_outstanding_cents']);

                    return $offer && $offer->actor_id !== $actor->id && $maximum > 0
                        ? ['id' => $receipt->id, 'label' => $offer->cash_reference.' · '.User::findOrFail($offer->actor_id)->name, 'amount_cents' => $maximum] : null;
                })->filter()->values()->all();
                if ($sources) {
                    $add('retire-advance', max(array_column($sources, 'amount_cents')), $sources);
                }
            }
        }
        if (($sourceReady || $found) && ! $state['resolved'] && ($actor->isBuyer() || $actor->isSeller())) {
            $party = $actor->isBuyer() ? 'buyer' : 'seller';
            $payment = $state[$party];
            $sources = [['id' => $payment['payment_id'], 'label' => 'My original payment', 'amount_cents' => $payment['amount_cents']]];
            if (in_array($payment['status'], ['payment_recorded', 'disputed'], true)) {
                $add('confirm-'.$party.'-receipt', null, $sources);
            }
            if ($payment['status'] === 'payment_recorded') {
                $add('report-'.$party.'-nonreceipt', null, $sources);
            }
        }
    }
}

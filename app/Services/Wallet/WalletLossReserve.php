<?php

namespace App\Services\Wallet;

use App\Models\LogisticsCompany;
use App\Models\ShipmentFinancialPlan;
use App\Models\User;
use App\Models\WalletAccount;
use App\Models\WalletRefundReserveEntry;
use App\Models\WalletTransaction;
use App\Services\Finance\ShipmentLossSource;
use Illuminate\Support\Facades\DB;

class WalletLossReserve
{
    public function __construct(private readonly WalletLedger $ledger) {}

    private function entries(ShipmentFinancialPlan $plan)
    {
        return WalletRefundReserveEntry::where('shipment_financial_plan_id', $plan->id)->where('order_id', $plan->order_id)
            ->where('authority_reference', $plan->reference)->whereNull('buyer_return_repayment_plan_id');
    }

    public function summary(ShipmentFinancialPlan $plan): array
    {
        $entries = $this->entries($plan)->get();
        $own = (int) $entries->whereIn('source_kind', ['liable_company', 'company_source_return'])->sum('delta_cents');
        $advance = (int) $entries->where('source_kind', 'advance')->sum('delta_cents');
        $retired = -(int) $entries->where('source_kind', 'advance_retirement')->sum('delta_cents');
        $returned = -(int) $entries->where('source_kind', 'found_advance_return')->sum('delta_cents');

        return ['balance_cents' => (int) $entries->sum('delta_cents'), 'company_due_cents' => $plan->snapshot['company_funding_due_cents'],
            'company_received_cents' => $own, 'company_remaining_cents' => $plan->snapshot['company_funding_due_cents'] - $own,
            'advance_cents' => $advance, 'advance_retired_cents' => $retired, 'advance_returned_cents' => $returned, 'advance_outstanding_cents' => $advance - $retired - $returned,
            'original_required_cents' => $plan->snapshot['original_escrow_required_cents'],
            'original_reserved_cents' => (int) $entries->whereIn('source_kind', ['original_escrow', 'original_escrow_return'])->sum('delta_cents'),
            'reserve_goal_cents' => $plan->snapshot['reserve_goal_cents'], 'buyer_refund_cents' => $plan->snapshot['buyer_refund_cents'],
            'seller_protection_cents' => $plan->snapshot['seller_protection_cents'], 'protected_earned_shipping_cents' => $plan->snapshot['protected_earned_shipping_cents'],
            'resolved' => WalletTransaction::where('business_key', 'shipment-loss:'.$plan->id)->exists()];
    }

    /** Called only inside the original plan's locked financial command. */
    public function execute(User $actor, ShipmentFinancialPlan $plan, string $action, ?int $amount, string $reason, string $token): WalletTransaction
    {
        abort_unless(DB::transactionLevel() > 0 && $plan->kind === 'transit_loss' && $plan->snapshot['payment_method'] === 'wallet'
            && ($plan->active_order_id || $plan->active_return_case_id), 409, 'Retain the active original loss authority and virtual payment.');
        if ($action === 'contribute-company') {
            abort_unless($actor->isLogistics() && $actor->email_verified_at && $actor->isKycApproved() && $actor->closed_at === null
                && LogisticsCompany::whereKey($plan->logistics_company_id)->where('user_id', $actor->id)->exists(), 403,
                'Only the original liable company owner may transfer its own virtual credits.');
        } else {
            app(WalletCreditService::class)->admin($actor);
        }
        abort_unless(in_array($action, ['reserve-original', 'contribute-company', 'approve-advance', 'resolve-wallet'], true), 409);
        $state = $this->summary($plan);
        app(ShipmentLossSource::class)->assertApproved($plan);
        // Late own-company funding retires an actual advance without rewriting the original loss or refund.
        if ($state['resolved']) {
            abort_unless($action === 'contribute-company', 409, 'Retain the original completed loss settlement.');
        }
        if ($action === 'reserve-original') {
            $amount = $state['original_required_cents'] - $state['original_reserved_cents'];
            $payment = $plan->order->walletPayment;
            $reserved = (int) WalletRefundReserveEntry::where('order_id', $plan->order_id)->whereIn('source_kind', ['original_escrow', 'original_escrow_return'])->sum('delta_cents');
            abort_unless($amount > 0 && $payment && ! $payment->settlement_transaction_id && $reserved + $amount <= $payment->amount_cents, 409,
                'Reserve only the original unspent buyer payment once.');

            return $this->deposit($plan, $actor, $this->ledger->platform('escrow'), 'original_escrow', $amount, $reason, $token);
        }
        if ($action === 'contribute-company') {
            abort_unless(is_int($amount) && $amount > 0 && $amount <= $state['company_remaining_cents'], 409,
                'Transfer only the original company’s remaining approved liability.');
            abort_unless($amount <= app(WalletFundingCredits::class)->available($actor, $plan->reference), 409,
                'Use credits available to this original loss. Other reviewed grants remain reserved for their own obligations.');

            return $this->deposit($plan, $actor, $this->ledger->user($actor), 'liable_company', $amount, $reason, $token, $actor->id);
        }
        if ($action === 'approve-advance') {
            abort_unless(is_int($amount) && $amount > 0 && $amount <= $state['company_due_cents'] - $state['company_received_cents'] - $state['advance_outstanding_cents'], 409,
                'Approve only the remaining explicit demonstration advance. Company debt remains payable.');

            return $this->deposit($plan, $actor, $this->ledger->platform('demo_issuance'), 'advance', $amount, $reason, $token);
        }
        abort_unless($state['original_reserved_cents'] === $state['original_required_cents']
            && $state['company_due_cents'] <= $state['company_received_cents'] + $state['advance_outstanding_cents']
            && $state['balance_cents'] >= $state['reserve_goal_cents'], 409,
            'Retain actual original escrow and company or explicitly advanced funding for every approved loss recipient.');
        $payment = $plan->order->walletPayment;
        $refunded = (int) WalletTransaction::where('order_id', $plan->order_id)->whereIn('kind', [
            'wallet_return_refund', 'wallet_delivery_refund', 'wallet_cancellation_refund', 'wallet_refusal_refund', 'wallet_loss_refund'])->sum('amount_cents');
        abort_unless($payment && $refunded + $state['buyer_refund_cents'] <= $payment->amount_cents, 409,
            'Actual buyer refunds cannot exceed the original payment. Seller protection is a separate funded amount.');
        $deltas = [];
        foreach ([[$this->ledger->platform('return_reserve'), -$state['reserve_goal_cents']],
            [$this->ledger->user(User::findOrFail($plan->buyer_id)), $state['buyer_refund_cents']],
            [$this->ledger->user(User::findOrFail($plan->seller_id)), $state['seller_protection_cents']],
            [$this->ledger->platform('shipping'), $state['protected_earned_shipping_cents']]] as [$account, $delta]) {
            $deltas[$account->id] = ($deltas[$account->id] ?? 0) + $delta;
        }
        $transaction = $this->ledger->post('shipment-loss:'.$plan->id, 'wallet_loss_refund', $state['buyer_refund_cents'], $deltas, $actor, $reason,
            ['shipment_financial_plan_reference' => $plan->reference, 'reserve_source_kind' => 'refund', 'funding' => $state,
                'buyer_refund_cents' => $state['buyer_refund_cents'], 'seller_protection_cents' => $state['seller_protection_cents'],
                'protected_earned_shipping_cents' => $state['protected_earned_shipping_cents'], 'demo_only' => true],
            orderId: $plan->order_id, returnId: $plan->buyer_return_case_id, token: $token);
        $this->record($plan, $transaction, 'refund', -$state['reserve_goal_cents']);
        $this->retireExcess($plan, $actor);

        return $transaction;
    }

    private function record(ShipmentFinancialPlan $plan, WalletTransaction $transaction, string $kind, int $amount, ?int $userId = null): void
    {
        if ($amount === 0) {
            return;
        }
        WalletRefundReserveEntry::create(['order_id' => $plan->order_id, 'authority_reference' => $plan->reference,
            'shipment_financial_plan_id' => $plan->id, 'buyer_return_repayment_plan_id' => null,
            'wallet_transaction_id' => $transaction->id, 'wallet_account_id' => $this->ledger->platform('return_reserve')->id,
            'source_user_id' => $userId, 'source_kind' => $kind, 'delta_cents' => $amount, 'context' => ['demo_only' => true], 'created_at' => now()]);
    }

    private function deposit(ShipmentFinancialPlan $plan, User $actor, WalletAccount $source, string $kind, int $amount,
        string $reason, string $token, ?int $userId = null): WalletTransaction
    {
        $transaction = $this->ledger->post('shipment-loss-funding:'.$plan->id.':'.$token, 'wallet_loss_funding', $amount,
            [$source->id => -$amount, $this->ledger->platform('return_reserve')->id => $amount], $actor, $reason,
            ['shipment_financial_plan_reference' => $plan->reference, 'reserve_source_kind' => $kind, 'source_account_id' => $source->id, 'demo_only' => true],
            orderId: $plan->order_id, returnId: $plan->buyer_return_case_id, token: $token);
        $this->record($plan, $transaction, $kind, $amount, $userId);
        $this->retireExcess($plan, $actor);

        return $transaction;
    }

    private function retireExcess(ShipmentFinancialPlan $plan, User $actor): void
    {
        $state = $this->summary($plan);
        $free = max(0, $state['balance_cents'] - ($state['resolved'] ? 0 : $state['reserve_goal_cents']));
        $amount = min($free, $state['advance_outstanding_cents']);
        if ($amount <= 0) {
            return;
        }
        $transaction = $this->ledger->post('shipment-loss-advance-retire:'.$plan->id.':'.$this->entries($plan)->max('id'), 'wallet_loss_advance_retirement', $amount,
            [$this->ledger->platform('return_reserve')->id => -$amount, $this->ledger->platform('demo_issuance')->id => $amount], $actor,
            'Retire only actual excess loss advance after preserving every unpaid original recipient.',
            ['shipment_financial_plan_reference' => $plan->reference, 'reserve_source_kind' => 'advance_retirement', 'demo_only' => true],
            orderId: $plan->order_id, returnId: $plan->buyer_return_case_id);
        $this->record($plan, $transaction, 'advance_retirement', -$amount);
    }
}

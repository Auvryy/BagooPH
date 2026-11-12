<?php

namespace App\Services\Wallet;

use App\Models\BuyerReturnCase;
use App\Models\BuyerReturnRepaymentPlan;
use App\Models\Delivery;
use App\Models\LogisticsCompany;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletAccount;
use App\Models\WalletPayment;
use App\Models\WalletRefundReserveEntry;
use App\Models\WalletTransaction;
use App\Rules\ApplicationText;
use App\Services\Returns\BuyerReturnAmounts;
use App\Services\Returns\BuyerReturnService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class WalletReturnReserve
{
    public function __construct(private readonly WalletLedger $ledger, private readonly BuyerReturnAmounts $amounts) {}

    private function locked(User $actor, BuyerReturnCase $snapshot): array
    {
        abort_unless(DB::transactionLevel() > 0, 409, 'Virtual reserve changes require the original financial transaction.');
        $order = Order::whereKey($snapshot->order_id)->lockForUpdate()->firstOrFail();
        Delivery::whereKey($snapshot->delivery_id)->lockForUpdate()->firstOrFail();
        User::whereIn('id', [$actor->id, $snapshot->buyer_id, $snapshot->seller_id])->orderBy('id')->lockForUpdate()->get();
        $payment = WalletPayment::where('order_id', $order->id)->lockForUpdate()->firstOrFail();
        $case = BuyerReturnCase::whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
        $case->load('items', 'parcel');
        LogisticsCompany::whereKey($case->parcel?->logistics_company_id)->lockForUpdate()->first();
        $order->setRelation('walletPayment', $payment);
        $case->setRelation('order', $order);
        $actor = app(BuyerReturnService::class)->current($actor);
        abort_unless($order->payment_method === 'wallet' && $order->buyer_id === $case->buyer_id && app(WalletPaymentService::class)->paid($order), 409,
            'Verify the original virtual buyer payment before reserving or returning credits.');
        $plan = BuyerReturnRepaymentPlan::where('buyer_return_case_id', $case->id)->lockForUpdate()->firstOrFail();
        $this->amounts->adjustmentFinancials($order);
        $basis = $this->amounts->successorForCase($case);
        abort_unless($plan->snapshot['source_snapshot']['units'] === $basis['unit_snapshot']
            && $plan->snapshot['source_snapshot']['shipping_units'] === $basis['shipping_unit_snapshot']
            && $plan->snapshot['buyer_refund_cents'] === $basis['buyer_cents'] + $basis['buyer_paid_shipping_cents'], 409,
            'Retain the approved original units and paid-shipping basis.');

        return [$actor, $case, $plan];
    }

    private function admin(User $actor): void
    {
        abort_unless($actor->isAdmin() && $actor->canAccessPortal() && $actor->email_verified_at, 403);
    }

    private function inputs(string $reason, string $token): array
    {
        $data = Validator::make(['reason' => trim($reason), 'request_token' => $token], [
            'reason' => ['required', new ApplicationText('notes', 10, 1000)], 'request_token' => ['required', 'uuid']])->validate();

        return [$data['reason'], strtolower($data['request_token'])];
    }

    private function key(BuyerReturnRepaymentPlan $plan, string $kind, string $token): string
    {
        return 'return-reserve:'.$plan->id.':'.$kind.':'.$token;
    }

    private function prior(BuyerReturnRepaymentPlan $plan, User $actor, string $kind, ?int $amount, string $reason, string $token): ?WalletTransaction
    {
        $prior = WalletTransaction::where('business_key', $this->key($plan, $kind, $token))->first();
        if ($prior) {
            abort_unless($prior->actor_id === $actor->id && $prior->reason === $reason
                && $prior->request_token_hash === hash('sha256', $token) && $prior->order_id === $plan->order_id
                && ($prior->context['repayment_plan_reference'] ?? null) === $plan->reference
                && ($prior->context['requested_amount_cents'] ?? null) === $amount, 409,
                'Retain the exact original virtual reserve request.');
        }

        return $prior;
    }

    private function entries(BuyerReturnRepaymentPlan $plan)
    {
        return WalletRefundReserveEntry::where('authority_reference', $plan->reference)->where('buyer_return_repayment_plan_id', $plan->id)->where('order_id', $plan->order_id);
    }

    private function originalTerms(BuyerReturnRepaymentPlan $plan): array
    {
        $snapshot = $plan->snapshot;
        if ($snapshot['fault'] !== 'seller') {
            return ['kind' => null, 'amount_cents' => 0, 'promotion_cents' => 0];
        }
        $settled = $snapshot['source_snapshot']['seller_payment'] !== null;

        return ['kind' => $settled ? 'original_commission' : 'original_escrow',
            'amount_cents' => $settled ? $snapshot['commission_reversal_cents'] : $snapshot['original_buyer_merchandise_cents'],
            'promotion_cents' => $settled ? $snapshot['promotion_reversal_cents'] : 0];
    }

    public function summary(BuyerReturnRepaymentPlan $plan): array
    {
        $entries = $this->entries($plan)->get();
        $original = $this->originalTerms($plan);
        $liableKind = $plan->snapshot['fault'] === 'seller' ? 'liable_seller' : 'liable_company';
        $due = $plan->snapshot[$plan->snapshot['fault'] === 'seller' ? 'seller_recovery_due_cents' : 'company_funding_due_cents'];
        $liable = (int) $entries->where('source_kind', $liableKind)->sum('delta_cents');
        $advance = (int) $entries->where('source_kind', 'advance')->sum('delta_cents');
        $retired = -(int) $entries->where('source_kind', 'advance_retirement')->sum('delta_cents');

        return ['balance_cents' => (int) $entries->sum('delta_cents'), 'liability_due_cents' => $due, 'liable_received_cents' => $liable,
            'liability_remaining_cents' => $due - $liable, 'advance_cents' => $advance, 'advance_retired_cents' => $retired,
            'advance_outstanding_cents' => $advance - $retired,
            'original_required_cents' => $original['amount_cents'],
            'original_reserved_cents' => $original['kind'] ? (int) $entries->where('source_kind', $original['kind'])->sum('delta_cents') : 0,
            'buyer_refund_cents' => $plan->snapshot['buyer_refund_cents'], 'promotion_restore_cents' => $original['promotion_cents'],
            'refund_goal_cents' => $plan->snapshot['buyer_refund_cents'] + $original['promotion_cents']];
    }

    private function record(BuyerReturnRepaymentPlan $plan, WalletTransaction $transaction, string $kind, int $delta, ?int $sourceUser = null): void
    {
        if ($delta === 0) {
            return;
        }
        WalletRefundReserveEntry::create(['order_id' => $plan->order_id, 'authority_reference' => $plan->reference,
            'buyer_return_repayment_plan_id' => $plan->id, 'wallet_transaction_id' => $transaction->id,
            'wallet_account_id' => $this->ledger->platform('return_reserve')->id, 'source_user_id' => $sourceUser,
            'source_kind' => $kind, 'delta_cents' => $delta, 'context' => ['demo_only' => true], 'created_at' => now()]);
    }

    private function deposit(BuyerReturnRepaymentPlan $plan, User $actor, WalletAccount $source, string $kind, int $amount,
        ?int $requested, string $reason, string $token, ?int $sourceUser = null): WalletTransaction
    {
        $reserve = $this->ledger->platform('return_reserve');
        $transaction = $this->ledger->post($this->key($plan, $kind, $token), 'wallet_return_funding', $amount,
            [$source->id => -$amount, $reserve->id => $amount], $actor, $reason,
            ['repayment_plan_reference' => $plan->reference, 'reserve_source_kind' => $kind, 'requested_amount_cents' => $requested,
                'source_account_id' => $source->id, 'demo_only' => true], orderId: $plan->order_id, returnId: $plan->buyer_return_case_id, token: $token);
        $this->record($plan, $transaction, $kind, $amount, $sourceUser);
        $this->retireExcess($plan, $actor);

        return $transaction;
    }

    public function original(User $actor, BuyerReturnCase $case, string $reason, string $token): WalletTransaction
    {
        [$reason, $token] = $this->inputs($reason, $token);
        [$actor, $case, $plan] = $this->locked($actor, $case);
        $this->admin($actor);
        $terms = $this->originalTerms($plan);
        $kind = $terms['kind'] ?? 'original_none';
        if ($prior = $this->prior($plan, $actor, $kind, null, $reason, $token)) {
            return $prior;
        }
        $state = $this->summary($plan);
        $amount = $state['original_required_cents'] - $state['original_reserved_cents'];
        abort_unless($case->status === 'repayment_ready' && $amount > 0, 409, 'No unreserved original virtual source is available for this case.');
        $payment = $case->order->walletPayment;
        if ($kind === 'original_escrow') {
            $reserved = (int) WalletRefundReserveEntry::where('order_id', $case->order_id)->where('source_kind', 'original_escrow')->sum('delta_cents');
            $paidShipping = $plan->snapshot['source_snapshot']['shipping']['buyer_paid_shipping_cents'];
            abort_unless($payment->settlement_transaction_id === null && $reserved + $amount + $paidShipping <= $payment->amount_cents, 409,
                'Protect the original paid shipping and verify the unspent original escrow source.');
            $source = $this->ledger->platform('escrow');
        } else {
            $settlement = WalletTransaction::find($payment->settlement_transaction_id);
            abort_unless($settlement && $settlement->reference === $plan->snapshot['source_snapshot']['seller_payment']['reference'], 409,
                'Use the actual original virtual settlement before reserving its commission reversal.');
            $source = $this->ledger->platform('commission');
        }

        return $this->deposit($plan, $actor, $source, $kind, $amount, null, $reason, $token);
    }

    public function liability(User $actor, BuyerReturnCase $case, int $amount, string $reason, string $token): WalletTransaction
    {
        [$reason, $token] = $this->inputs($reason, $token);
        [$actor, $case, $plan] = $this->locked($actor, $case);
        $seller = $plan->snapshot['fault'] === 'seller';
        abort_unless($actor->email_verified_at && $actor->isKycApproved()
            && ($seller ? $actor->isSeller() && $actor->id === $case->seller_id
                : $actor->isLogistics() && LogisticsCompany::whereKey($plan->snapshot['liable_company_id'])->where('user_id', $actor->id)->exists()), 403,
            'Only the named original liable account may transfer its own virtual credits.');
        $kind = $seller ? 'liable_seller' : 'liable_company';
        if ($prior = $this->prior($plan, $actor, $kind, $amount, $reason, $token)) {
            return $prior;
        }
        $state = $this->summary($plan);
        abort_unless(in_array($case->status, ['repayment_ready', 'repaid', 'closed_no_repayment'], true)
            && $amount > 0 && $amount <= $state['liability_remaining_cents'], 409, 'Transfer only the remaining approved virtual liability.');

        return $this->deposit($plan, $actor, $this->ledger->user($actor), $kind, $amount, $amount, $reason, $token, $actor->id);
    }

    public function advance(User $actor, BuyerReturnCase $case, int $amount, string $reason, string $token): WalletTransaction
    {
        [$reason, $token] = $this->inputs($reason, $token);
        [$actor, $case, $plan] = $this->locked($actor, $case);
        $this->admin($actor);
        if ($prior = $this->prior($plan, $actor, 'advance', $amount, $reason, $token)) {
            return $prior;
        }
        $state = $this->summary($plan);
        abort_unless($case->status === 'repayment_ready' && $amount > 0
            && $amount <= $state['liability_due_cents'] - $state['liable_received_cents'] - $state['advance_outstanding_cents'], 409,
            'Approve only the remaining explicitly backed demonstration advance. Original liable-party debt remains separate.');

        return $this->deposit($plan, $actor, $this->ledger->platform('demo_issuance'), 'advance', $amount, $amount, $reason, $token);
    }

    public function refund(User $actor, BuyerReturnCase $case, string $reason, string $token): WalletTransaction
    {
        [$reason, $token] = $this->inputs($reason, $token);
        [$actor, $case, $plan] = $this->locked($actor, $case);
        $this->admin($actor);
        $key = 'return:'.$case->id;
        if ($prior = WalletTransaction::where('business_key', $key)->first()) {
            abort_unless($prior->actor_id === $actor->id && $prior->reason === $reason && $prior->request_token_hash === hash('sha256', $token)
                && ($prior->context['repayment_plan_reference'] ?? null) === $plan->reference, 409, 'Retain this original actual virtual refund.');

            return $prior;
        }
        $state = $this->summary($plan);
        abort_unless($case->status === 'repayment_ready' && $state['original_reserved_cents'] === $state['original_required_cents']
            && $state['liability_due_cents'] <= $state['liable_received_cents'] + $state['advance_outstanding_cents']
            && $state['balance_cents'] >= $state['refund_goal_cents'], 409,
            'Reserve the actual original source and liable-party or approved demonstration funding before refunding.');
        $already = (int) WalletTransaction::where('order_id', $case->order_id)->whereIn('kind', ['wallet_return_refund', 'wallet_delivery_refund', 'wallet_cancellation_refund'])->sum('amount_cents');
        abort_unless($already + $state['buyer_refund_cents'] <= $case->order->walletPayment->amount_cents, 409, 'Actual virtual buyer refunds cannot exceed the original buyer payment.');
        $deltas = [];
        if ($state['refund_goal_cents'] > 0) {
            $deltas[$this->ledger->platform('return_reserve')->id] = -$state['refund_goal_cents'];
        }
        if ($state['buyer_refund_cents'] > 0) {
            $deltas[$this->ledger->user($case->order->buyer)->id] = $state['buyer_refund_cents'];
        }
        if ($state['promotion_restore_cents'] > 0) {
            $deltas[$this->ledger->platform('promotion')->id] = $state['promotion_restore_cents'];
        }
        $transaction = $this->ledger->post($key, 'wallet_return_refund', $state['buyer_refund_cents'], $deltas, $actor, $reason,
            ['repayment_plan_reference' => $plan->reference, 'reserve_source_kind' => 'refund', 'funding' => $state,
                'escrow_debit_cents' => 0, 'demo_only' => true], orderId: $case->order_id, returnId: $case->id, token: $token);
        $this->record($plan, $transaction, 'refund', -$state['refund_goal_cents']);
        $this->retireExcess($plan, $actor);

        return $transaction;
    }

    private function retireExcess(BuyerReturnRepaymentPlan $plan, User $actor): void
    {
        $state = $this->summary($plan);
        $paid = WalletTransaction::where('business_key', 'return:'.$plan->buyer_return_case_id)->exists();
        $free = max(0, $state['balance_cents'] - ($paid ? 0 : $state['refund_goal_cents']));
        $amount = min($free, $state['advance_outstanding_cents']);
        if ($amount <= 0) {
            return;
        }
        $sequence = $this->entries($plan)->max('id');
        $transaction = $this->ledger->post('return-advance-retire:'.$plan->id.':'.$sequence, 'wallet_return_advance_retirement', $amount,
            [$this->ledger->platform('return_reserve')->id => -$amount, $this->ledger->platform('demo_issuance')->id => $amount], $actor,
            'Retired the actual excess demonstration advance after protecting the original refund reserve.',
            ['repayment_plan_reference' => $plan->reference, 'reserve_source_kind' => 'advance_retirement', 'demo_only' => true],
            orderId: $plan->order_id, returnId: $plan->buyer_return_case_id);
        $this->record($plan, $transaction, 'advance_retirement', -$amount);
    }
}

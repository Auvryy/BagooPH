<?php

namespace App\Services\Returns;

use App\Models\BuyerReturnCase;
use App\Models\Order;
use App\Models\WalletTransaction;
use App\Services\Finance\CodMoney;
use App\Services\Finance\SellerSettlementService;
use App\Services\Wallet\WalletPaymentService;

class BuyerReturnRefundPlan
{
    public function __construct(private readonly BuyerReturnAmounts $amounts) {}

    /** An evidenced amount plan is neither received backing nor a repayment. */
    public function postDelivery(BuyerReturnCase $case): array
    {
        $case = $case->fresh(['order', 'items', 'parcel']);
        abort_unless(in_array($case->status, ['seller_received', 'condition_review', 'repayment_ready'], true) && $case->parcel, 409,
            'Complete the actual return and seller receipt before preparing repayment.');
        $custody = app(BuyerReturnCustodyService::class);
        $custody->assertFinishedRoute($case, $case->parcel, afterSellerReceipt: true);
        $receipt = $case->events()->where('event_type', 'return-seller-receive')->latest('id')->first();
        abort_unless($receipt?->actor_id === $case->seller_id && $receipt->actor_role === 'seller'
            && $case->evidence()->where('buyer_return_event_id', $receipt->id)->where('purpose', 'seller-receive')->exists(), 409,
            'Retain the actual seller handover and its private receipt evidence.');
        $basis = $this->amounts->successorForCase($case);
        $finding = app(ReturnFaultReviewService::class)->current($case);
        $decision = $finding ? $case->events()->where('reference', $finding['event_reference'])->first() : null;
        abort_unless(($finding['policy_version'] ?? null) === 2 && in_array($finding['fault'] ?? null, ['seller', 'courier'], true)
            && $decision && in_array($decision->actor_role, ['seller', 'admin'], true)
            && ($decision->actor_role !== 'seller' || $decision->actor_id === $case->seller_id)
            && ($finding['delivery_checkpoint_id'] ?? null) === $case->delivery_checkpoint_id, 409,
            'An original policy-two seller or independently reviewed courier finding is required.');
        $sellerFault = $finding['fault'] === 'seller';
        if (! $sellerFault) {
            abort_unless($decision->actor_role === 'admin' && ! in_array($decision->actor_id, [$case->buyer_id, $case->seller_id], true)
                && ($finding['company_id'] ?? null) === $case->parcel->logistics_company_id && trim((string) ($finding['evidence'] ?? '')) !== '', 409,
                'Courier funding needs an independent evidenced finding against the original company.');
        }
        $payment = $this->payment($case->order);
        $sellerPayment = $this->sellerPayment($case->order);
        $refund = $basis['buyer_cents'] + $basis['buyer_paid_shipping_cents'];
        abort_unless($refund <= $payment['paid_cents'], 409, 'The original units cannot refund more than the buyer actually paid.');

        return ['policy_version' => 2, 'buyer_return_case_id' => $case->id, 'order_id' => $case->order_id, 'case_version' => $case->version,
            'fault' => $finding['fault'], 'fault_event_reference' => $decision->reference,
            'liable_company_id' => $sellerFault ? null : $case->parcel->logistics_company_id,
            'original_buyer_merchandise_cents' => $basis['buyer_cents'], 'original_seller_cents' => $basis['seller_cents'],
            'outbound_shipping_refund_cents' => $basis['buyer_paid_shipping_cents'], 'buyer_refund_cents' => $refund,
            'seller_reversal_cents' => $sellerFault ? $basis['seller_cents'] : 0,
            'commission_reversal_cents' => $sellerFault ? $basis['commission_cents'] : 0,
            'promotion_reversal_cents' => $sellerFault ? $basis['platform_discount_cents'] : 0,
            'seller_recovery_due_cents' => $sellerFault ? ($sellerPayment ? $basis['seller_cents'] : 0) + $basis['buyer_paid_shipping_cents'] : 0,
            'company_funding_due_cents' => $sellerFault ? 0 : $refund,
            'buyer_return_charge_cents' => 0, 'seller_protection_cents' => 0,
            'protected_shipping_cents' => $basis['source_snapshot']['shipping_cents'],
            'source_snapshot' => ['payment' => $payment, 'seller_payment' => $sellerPayment,
                'seller_receipt_event_id' => $receipt->id, 'units' => $basis['unit_snapshot'],
                'shipping_units' => $basis['shipping_unit_snapshot'], 'shipping' => $basis['shipping_source_snapshot']]];
    }

    public function payment(Order $order): array
    {
        $total = CodMoney::cents($order->total_amount);
        if ($order->payment_method === 'wallet') {
            abort_unless(app(WalletPaymentService::class)->paid($order) && $order->walletPayment->amount_cents === $total, 409,
                'Verify the original virtual payment before preparing its refund.');

            return ['mode' => 'wallet', 'reference' => $order->walletPayment->reference, 'paid_cents' => $total, 'demo_only' => true];
        }
        $cash = $order->codAccount;
        $event = $cash?->events()->whereIn('event_type', ['rider_collection', 'counter_collection'])->first();
        abort_unless($order->payment_method === 'cod' && $order->payment_status === 'paid' && $cash && $event
            && $cash->delivery_id === $order->delivery?->id && $cash->expected_cents === $total
            && $cash->product_subtotal_cents === CodMoney::cents($order->subtotal)
            && $cash->shipping_cents === CodMoney::cents($order->shipping_fee) && $cash->discount_cents === CodMoney::cents($order->voucher_discount)
            && $event->amount_cents === $total && ($event->target_state['collected_cents'] ?? null) === $total, 409,
            'Verify the original COD collection. A paid label alone cannot create a cash refund.');

        return ['mode' => 'cod', 'reference' => $event->reference, 'paid_cents' => $total,
            'reconciliation_required' => true];
    }

    public function sellerPayment(Order $order): ?array
    {
        if ($order->payment_method === 'cod') {
            $event = app(SellerSettlementService::class)->verifiedPayment($order->sellerSettlement);

            return $event ? ['mode' => 'cod', 'reference' => $event->reference, 'amount_cents' => $event->amount_cents] : null;
        }
        $id = $order->walletPayment?->settlement_transaction_id;
        if (! $id) {
            return null;
        }
        $transaction = WalletTransaction::whereKey($id)->where('order_id', $order->id)->where('kind', 'wallet_settlement')->first();
        abort_unless($transaction, 409, 'The original seller credit needs its retained virtual settlement.');

        return ['mode' => 'wallet', 'reference' => $transaction->reference, 'amount_cents' => $transaction->amount_cents];
    }
}

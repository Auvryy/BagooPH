<?php

namespace App\Services\Finance;

use App\Models\BuyerReturnManifestParcel;
use App\Models\BuyerReturnRepaymentPlan;
use App\Models\DeliveryCheckpoint;
use App\Models\LogisticsEarning;
use App\Models\LogisticsManifestEvent;
use App\Models\Order;
use App\Models\WalletTransaction;
use App\Services\Returns\BuyerReturnAmounts;
use App\Services\Returns\BuyerReturnCustodyService;
use App\Services\Returns\BuyerReturnRefundPlan;

class ShipmentLossSource
{
    public function context(LogisticsManifestEvent $original): array
    {
        $observation = $original->fresh(['manifest', 'parcel.delivery']);
        $manifest = $observation->manifest;
        abort_unless($observation->event_type === 'report-discrepancy' && ($observation->payload['kind'] ?? null) === 'missing'
            && $observation->barcode_scanned === null && $manifest && $manifest->dispatched_at
            && $manifest->status === 'dispatched' && $observation->hub_id === $manifest->destination_hub_id
            && $observation->actor_role === 'logistics' && $observation->actor_id
            && ! $manifest->events()->where('event_type', 'resolve-discrepancy')->where('payload->source_event_id', $observation->id)->exists(), 409,
            'A reviewed transit loss requires an unresolved original missing-parcel observation on actual departed transport.');
        $case = null;
        if ($manifest->direction === 'buyer_return') {
            $member = BuyerReturnManifestParcel::whereKey($observation->payload['return_member_id'] ?? 0)->where('manifest_id', $manifest->id)->first();
            $parcel = $member?->parcel;
            $case = $parcel?->returnCase;
            abort_unless($member && $parcel && $case && $case->status === 'in_transit'
                && $member->included && $member->active_return_parcel_id === $parcel->id && ! $member->received_at
                && $member->barcode_snapshot === $parcel->barcode && $parcel->logistics_company_id === $manifest->logistics_company_id
                && $parcel->status === 'in_transit' && $parcel->current_hub_id === null
                && ($parcel->custody['kind'] ?? null) === 'manifest' && ($parcel->custody['manifest_id'] ?? null) === $manifest->id
                && $manifest->events()->where('event_type', 'buyer-return-departed')->where('barcode_scanned', $parcel->barcode)->exists()
                && ! $manifest->events()->where('event_type', 'buyer-return-received')->where('barcode_scanned', $parcel->barcode)->exists()
                && ! BuyerReturnRepaymentPlan::where('buyer_return_case_id', $case->id)->exists(), 409,
                'Retain this original claimed return in its actual missing transport, without an already approved repayment or seller receipt.');
            app(BuyerReturnCustodyService::class)->assertSource($case, $parcel);
            $order = $case->order;
            $state = $parcel->state();
            $barcode = $parcel->barcode;
        } else {
            $member = $observation->parcel;
            $parcel = $member?->delivery;
            $order = $parcel?->order;
            $custody = $parcel ? DeliveryCheckpoint::lastCustody($parcel) : [];
            $recovery = $manifest->direction === 'recovery' ? $manifest->recoveryCase : null;
            $validDirection = in_array($manifest->direction, ['outbound', 'return'], true)
                || ($recovery && $recovery->delivery_id === $parcel?->id && $recovery->active_delivery_id === $parcel?->id
                    && $recovery->state === 'in_transit' && in_array($recovery->original_direction, ['outbound', 'return'], true));
            abort_unless($validDirection && $member && $parcel && $order
                && $member->included && $member->active_delivery_id === $parcel->id && ! $member->received_at
                && $member->tracking_number_snapshot === $parcel->tracking_number && $parcel->logistics_company_id === $manifest->logistics_company_id
                && $parcel->current_hub_id === null && ($custody['kind'] ?? null) === 'manifest' && ($custody['manifest_id'] ?? null) === $manifest->id
                && ! in_array($order->status, ['completed', 'delivered', 'returned', 'cancelled'], true)
                && ! $order->buyerReturns()->exists()
                && $manifest->events()->where('manifest_parcel_id', $member->id)->where('event_type', 'parcel_dispatched')->exists()
                && ! $manifest->events()->where('manifest_parcel_id', $member->id)->whereIn('event_type', ['parcel_received', 'handover-recovery-received'])->exists(), 409,
                'The original undelivered parcel must still lack its actual receipt on this transport. A later receipt or completed sale needs a different review.');
            $checkpoint = $parcel->checkpoints()->whereNull('source_checkpoint_id')->whereNotNull('custody_after')->latest('id')->first();
            abort_unless($checkpoint && $checkpoint->manifest_number === $manifest->reference && $checkpoint->custody_after === $custody, 409,
                'Retain the original departure checkpoint and current recorded transport custody.');
            $state = DeliveryCheckpoint::state($parcel) + ['custody' => $custody, 'checkpoint_id' => $checkpoint->id];
            $barcode = $parcel->tracking_number;
        }
        $order = Order::with('items.shop', 'walletPayment', 'delivery', 'codAccount')->findOrFail($order->id);
        abort_unless(($order->shipping_quote['refund_policy_version'] ?? 1) === 2 && $order->items->isNotEmpty()
            && $order->items->pluck('shop_id')->unique()->count() === 1, 409, 'Retain original supported purchased terms and one original seller.');

        return compact('observation', 'manifest', 'member', 'parcel', 'order', 'case', 'state', 'barcode');
    }

    public function snapshot(LogisticsManifestEvent $original, int $protection): array
    {
        $context = $this->context($original);
        ['observation' => $observation, 'manifest' => $manifest, 'order' => $order, 'case' => $case] = $context;
        $amounts = app(BuyerReturnAmounts::class);
        $basis = $case ? $amounts->successorForCase($case->load('items', 'order.items')) : $amounts->successorOriginal($order);
        $units = $case ? $basis['unit_snapshot'] : $basis['units'];
        $net = array_sum(array_column($units, 'buyer_cents'));
        $paidShipping = $case ? $basis['buyer_paid_shipping_cents'] : $basis['shipping_cents'] - $basis['shipping_discount_cents'];
        $wallet = $order->payment_method === 'wallet';
        abort_unless($net >= 0 && $paidShipping >= 0 && ($case || $net + $paidShipping === CodMoney::cents($order->total_amount)), 409,
            'The original net merchandise, paid shipping and order total must agree.');
        $payment = null;
        $payments = app(BuyerReturnRefundPlan::class);
        $sellerPayment = $payments->sellerPayment($order);
        if ($wallet || $case) {
            $payment = $payments->payment($order);
            if (! $case) {
                abort_unless(! $order->walletPayment->settlement_transaction_id && ! $sellerPayment
                    && ! WalletTransaction::where('order_id', $order->id)->whereIn('kind', ['wallet_return_refund', 'wallet_delivery_refund', 'wallet_cancellation_refund', 'wallet_refusal_refund', 'wallet_loss_refund'])->exists(), 409,
                    'Review the original unspent payment; existing proceeds or refunds cannot be spent again.');
            }
        } else {
            abort_unless($order->payment_method === 'cod' && $order->payment_status === 'pending' && ! $order->codAccount && ! $order->walletPayment && ! $sellerPayment, 409,
                'Uncollected COD has no buyer repayment source. Review any existing cash collection separately.');
        }
        $refund = $payment ? $net + $paidShipping : 0;
        abort_unless($refund >= 0 && $refund <= ($payment['paid_cents'] ?? 0), 409, 'Loss repayment cannot exceed the original actual buyer payment.');
        $cap = $case ? 0 : $basis['original_seller_cents'];
        abort_unless($protection >= 0 && $protection <= $cap, 409,
            'Review seller protection separately, within original net proceeds and without duplicating a delivered sale or paid proceeds.');
        $earnings = $case ? collect() : LogisticsEarning::where('delivery_id', $order->delivery->id)->where('pool', 'shipping')->whereNull('reversed_at')->orderBy('id')->get();
        foreach ($earnings as $earning) {
            $checkpoint = DeliveryCheckpoint::find($earning->checkpoint_id);
            abort_unless($earning->role === 'pickup' && $earning->order_id === $order->id && $earning->logistics_company_id === $manifest->logistics_company_id
                && $earning->net_amount_cents >= 0 && $checkpoint?->checkpoint_type === 'arrived_at_origin_hub'
                && $checkpoint->delivery_id === $order->delivery->id && $earning->source_key === 'parcel:'.$order->delivery->id.':pickup', 409,
                'Protect actual original earned pickup amounts; do not invent delivery or company earnings for a lost parcel.');
        }
        $earned = (int) $earnings->sum('net_amount_cents');
        $escrow = $wallet && ! $case ? $refund : 0;

        return ['policy_version' => 2, 'kind' => 'transit_loss', 'scope' => $case ? 'buyer_return' : 'undelivered',
            'order_id' => $order->id, 'delivery_id' => $order->delivery->id, 'buyer_return_case_id' => $case?->id,
            'buyer_id' => $order->buyer_id, 'seller_id' => $order->items->first()->shop->user_id, 'logistics_company_id' => $manifest->logistics_company_id,
            'payment_method' => $order->payment_method, 'actual_buyer_paid_cents' => $payment['paid_cents'] ?? 0,
            'original_merchandise_cents' => $net, 'buyer_paid_shipping_cents' => $paidShipping, 'buyer_refund_cents' => $refund,
            'seller_protection_cap_cents' => $cap, 'seller_protection_cents' => $protection, 'protected_earned_shipping_cents' => $earned,
            'original_escrow_required_cents' => $escrow, 'reserve_goal_cents' => $refund + $protection + $earned,
            'company_funding_due_cents' => $refund + $protection + $earned - $escrow,
            'units' => $units, 'shipping_units' => $case ? $basis['shipping_unit_snapshot'] : null,
            'original_payment' => $payment, 'original_seller_payment' => $sellerPayment,
            'promotion_snapshot' => $case ? $basis['source_snapshot']['promotion_snapshot'] : $basis['promotion_snapshot'],
            'source' => ['observation_id' => $observation->id, 'observation_reference' => $observation->reference,
                'manifest_id' => $manifest->id, 'manifest_reference' => $manifest->reference, 'direction' => $manifest->direction,
                'member_id' => $context['member']->id, 'barcode' => $context['barcode'], 'physical_state' => $context['state'],
                'earning_sources' => $earnings->map(fn ($entry) => $entry->only(['id', 'source_key', 'checkpoint_id', 'role', 'user_id', 'net_amount_cents']))->all()],
            'source_actor_ids' => array_values(array_unique(array_filter([$order->buyer_id, $order->items->first()->shop->user_id,
                $observation->actor_id, $manifest->driver_id, $manifest->dispatcher_id, $manifest->company->user_id])))];
    }
}

<?php

namespace App\Services\Orders;

use App\Models\CodAccount;
use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\Order;
use App\Models\PickupClaim;
use App\Models\PickupClaimEvent;
use App\Models\User;
use App\Models\WalletHandoverReceipt;
use App\Services\Finance\CodMoney;
use App\Services\Finance\LogisticsEarningsService;
use App\Services\Notifications\LifecycleNoticeService;
use App\Services\Wallet\WalletPaymentService;
use App\Services\Wallet\WalletSettlementService;
use DomainException;
use Illuminate\Support\Facades\DB;

class AutomaticDeliveryCompletionService
{
    public function complete(Delivery $parcel, DeliveryCheckpoint $proof, User $actor): void
    {
        DB::transaction(function () use ($parcel, $proof, $actor) {
            $order = Order::whereKey(Delivery::whereKey($parcel->id)->value('order_id'))->lockForUpdate()->firstOrFail();
            if ($order->logistics_flow_version < 2) {
                return;
            }
            $parcel = Delivery::whereKey($parcel->id)->lockForUpdate()->firstOrFail();
            $parcel->setRelation('order', $order);
            $proof = $parcel->checkpoints()->whereKey($proof->id)->first();
            if (! $proof || $proof->scanned_by_id !== $actor->id) {
                throw new DomainException('Use the original saved recipient handover and its actual operator.');
            }
            if ($order->status === 'completed' && ($original = $this->evidence($order))) {
                if (($original->custody_after['delivery_proof_reference'] ?? null) !== $proof->record_reference) {
                    throw new DomainException('This order already completed from a different original handover.');
                }

                return;
            }
            if ($order->status !== 'delivered' || $parcel->status !== $proof->checkpoint_type
                || ! $parcel->delivered_at || $order->payment_status !== 'paid' || ! $this->supportedProof($parcel, $proof)) {
                throw new DomainException('Automatic completion requires the actual recipient handover and verified original payment.');
            }
            $before = DeliveryCheckpoint::state($parcel);
            $order->update(['status' => 'completed']);
            $completion = DeliveryCheckpoint::record($parcel, 'delivery_completed', actor: $actor,
                notes: 'Order completed automatically after verified recipient handover. Delivery evidence: '.$proof->record_reference,
                barcodeScanned: $proof->barcode_scanned,
                evidence: ['source_state' => $before, 'target_state' => DeliveryCheckpoint::state($parcel),
                    'custody_before' => $proof->custody_after, 'custody_after' => $proof->custody_after + ['delivery_proof_reference' => $proof->record_reference]],
                scanProvenance: 'verified_delivery_completion');
            app(LogisticsEarningsService::class)->completed($parcel, $completion);
            if ($order->payment_method === 'wallet') {
                app(WalletSettlementService::class)->settleOnDelivery($order);
            }
            app(LifecycleNoticeService::class)->order($order, 'completed');
        });
        // Keep the caller's delivery change flags, but do not retain its pre-completion order relation.
        $parcel->unsetRelation('order');
    }

    public function evidence(Order $order): ?DeliveryCheckpoint
    {
        if ($order->logistics_flow_version < 2 || $order->status !== 'completed') {
            return null;
        }
        $parcel = $order->delivery;
        $completion = $parcel?->checkpoints()->where('checkpoint_type', 'delivery_completed')->whereNull('source_checkpoint_id')->first();
        if (! $completion || $completion->scan_provenance !== 'verified_delivery_completion'
            || ($completion->source_state['order_status'] ?? null) !== 'delivered'
            || ($completion->target_state['order_status'] ?? null) !== 'completed') {
            return null;
        }
        $proof = $parcel->checkpoints()->where('record_reference', $completion->custody_after['delivery_proof_reference'] ?? '')
            ->whereIn('checkpoint_type', ['delivered', 'customer_collected'])->whereNull('source_checkpoint_id')->first();

        return $proof && $proof->scanned_by_id === $completion->scanned_by_id && $proof->barcode_scanned === $completion->barcode_scanned
            && $this->supportedProof($parcel, $proof) ? $completion : null;
    }

    private function supportedProof(Delivery $parcel, DeliveryCheckpoint $proof): bool
    {
        $order = $parcel->order;
        if ($proof->source_checkpoint_id || $proof->barcode_scanned !== $parcel->tracking_number
            || ($proof->target_state['order_status'] ?? null) !== 'delivered'
            || ($proof->target_state['delivery_status'] ?? null) !== $proof->checkpoint_type
            || ($proof->custody_after['kind'] ?? null) !== 'buyer' || ($proof->custody_after['user_id'] ?? null) !== $order->buyer_id) {
            return false;
        }
        if ($proof->checkpoint_type === 'customer_collected') {
            $claim = PickupClaim::where('delivery_id', $parcel->id)->where('buyer_id', $order->buyer_id)
                ->where('hub_id', $proof->hub_id)->where('status', 'collected')->whereNotNull('consumed_at')->first();
            if ($proof->actor_role !== 'logistics' || ! $claim || $proof->hub_id !== $parcel->destination_bayan_hub_id
                || ! PickupClaimEvent::where('pickup_claim_id', $claim->id)->where('event_type', 'collected')
                    ->where('actor_id', $proof->scanned_by_id)->where('barcode_scanned', $proof->barcode_scanned)
                    ->where('reference', $proof->custody_after['pickup_collection_reference'] ?? '')->exists()) {
                return false;
            }
        } elseif ($proof->checkpoint_type !== 'delivered' || $proof->actor_role !== 'courier' || ! $proof->proof_image
            || ($proof->source_state['delivery_status'] ?? null) !== 'out_for_delivery'
            || ($proof->custody_before['kind'] ?? null) !== 'courier' || ($proof->custody_before['user_id'] ?? null) !== $proof->scanned_by_id) {
            return false;
        }
        if ($order->payment_method === 'wallet') {
            return app(WalletPaymentService::class)->paid($order, allowRefunded: true)
                && ($proof->checkpoint_type === 'customer_collected' || WalletHandoverReceipt::where('delivery_id', $parcel->id)
                    ->where('wallet_payment_id', $order->walletPayment?->id)
                    ->where('delivery_checkpoint_id', $proof->id)->where('actor_id', $proof->scanned_by_id)->exists());
        }
        if ($order->payment_method !== 'cod') {
            return false;
        }
        $account = CodAccount::where('delivery_id', $parcel->id)->where('order_id', $order->id)
            ->where('collector_id', $proof->scanned_by_id)->where('expected_cents', CodMoney::cents($order->total_amount))
            ->when($proof->checkpoint_type === 'delivered', fn ($q) => $q->where('delivery_checkpoint_id', $proof->id))->first();

        return $account && $account->events()->where('event_type', $proof->checkpoint_type === 'delivered' ? 'rider_collection' : 'counter_collection')
            ->where('actor_id', $proof->scanned_by_id)->where('amount_cents', $account->expected_cents)->exists();
    }
}

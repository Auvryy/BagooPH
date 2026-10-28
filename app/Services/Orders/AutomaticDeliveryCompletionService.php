<?php

namespace App\Services\Orders;

use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\Order;
use App\Models\User;
use App\Services\Finance\LogisticsEarningsService;
use App\Services\Notifications\LifecycleNoticeService;
use App\Services\Wallet\WalletSettlementService;
use Illuminate\Support\Facades\DB;

class AutomaticDeliveryCompletionService
{
    public function complete(Delivery $parcel, DeliveryCheckpoint $proof, User $actor): void
    {
        DB::transaction(function () use ($parcel, $proof, $actor) {
            $order = Order::whereKey($parcel->order_id)->lockForUpdate()->firstOrFail();
            if ($order->logistics_flow_version < 2) {
                return;
            }
            if ($order->status === 'completed' && $this->evidence($order)) {
                return;
            }
            if ($proof->delivery_id !== $parcel->id || ! in_array($proof->checkpoint_type, ['delivered', 'customer_collected'], true)
                || $proof->source_checkpoint_id || ! in_array($parcel->status, ['delivered', 'customer_collected'], true)
                || ! $parcel->delivered_at || $order->payment_status !== 'paid'
                || ($proof->custody_after['kind'] ?? null) !== 'buyer' || ($proof->custody_after['user_id'] ?? null) !== $order->buyer_id) {
                throw new \DomainException('Automatic completion requires the actual recipient handover and verified original payment.');
            }
            $parcel->setRelation('order', $order);
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
            && ($proof->custody_after['kind'] ?? null) === 'buyer' && ($proof->custody_after['user_id'] ?? null) === $order->buyer_id ? $completion : null;
    }
}

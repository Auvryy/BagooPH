<?php

namespace App\Services\Wallet;

use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\User;
use App\Models\WalletHandoverReceipt;
use App\Services\Courier\CourierProofService;
use App\Services\Finance\CodCashService;
use Illuminate\Support\Facades\DB;

class WalletHandoverService
{
    private function fingerprint(User $actor, array $input): string
    {
        return hash('sha256', json_encode([$actor->id, $input['barcode'], $input['recipient_name'], $input['recipient_relationship'],
            $input['notes'], $input['request_token']], JSON_THROW_ON_ERROR));
    }

    public function record(Delivery $parcel, User $actor, DeliveryCheckpoint $checkpoint, array $metadata): void
    {
        abort_unless(DB::transactionLevel() > 0, 409);
        $input = app(CodCashService::class)->riderInput($parcel, $actor, $metadata);
        app(WalletPaymentService::class)->assertPrepaidHandover($parcel->order, $metadata);
        abort_unless($parcel->status === 'delivered' && $parcel->order->status === 'delivered'
            && $checkpoint->delivery_id === $parcel->id && $checkpoint->scanned_by_id === $actor->id
            && $checkpoint->checkpoint_type === 'delivered' && $checkpoint->barcode_scanned === $parcel->tracking_number
            && ($checkpoint->source_state['delivery_status'] ?? null) === 'out_for_delivery', 409);
        WalletHandoverReceipt::create(['delivery_id' => $parcel->id, 'delivery_checkpoint_id' => $checkpoint->id,
            'wallet_payment_id' => $parcel->order->walletPayment->id, 'actor_id' => $actor->id,
            'request_token_hash' => hash('sha256', $input['request_token']), 'fingerprint' => $this->fingerprint($actor, $input),
            'recipient_name' => $input['recipient_name'], 'recipient_relationship' => $input['recipient_relationship'],
            'proof_hash' => app(CourierProofService::class)->hash($checkpoint->proof_image), 'created_at' => now()]);
    }

    public function retry(Delivery $parcel, User $actor, array $metadata): void
    {
        $input = app(CodCashService::class)->riderInput($parcel, $actor, $metadata);
        $receipt = WalletHandoverReceipt::where('delivery_id', $parcel->id)->first();
        abort_unless($receipt && $receipt->actor_id === $actor->id
            && hash_equals($receipt->request_token_hash, hash('sha256', $input['request_token']))
            && hash_equals($receipt->fingerprint, $this->fingerprint($actor, $input))
            && (! isset($metadata['proof_hash']) || hash_equals($receipt->proof_hash, $metadata['proof_hash'])), 409,
            'This wallet handover already saved different recipient or proof details. Its original receipt is retained.');
    }
}

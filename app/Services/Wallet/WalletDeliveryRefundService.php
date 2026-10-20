<?php

namespace App\Services\Wallet;

use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletPayment;
use App\Models\WalletTransaction;
use App\Rules\ApplicationText;
use App\Services\AccountRestrictionService;
use App\Services\Logistics\DeliveryReturnService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class WalletDeliveryRefundService
{
    public function eligible(Order $order): bool
    {
        $parcel = $order->delivery;
        if ($order->status !== 'returned' || $parcel?->status !== 'returned' || $parcel->current_hub_id !== null
            || ! app(WalletPaymentService::class)->paid($order) || $order->walletPayment?->settlement_transaction_id
            || $order->buyerReturns()->exists()) {
            return false;
        }
        $returns = app(DeliveryReturnService::class);
        try {
            $route = $returns->route($parcel);
            if ($returns->position($parcel, $route) !== count($route->hub_ids) - 1) {
                return false;
            }
        } catch (DomainException) {
            return false;
        }
        $event = $returns->event($route, 'seller_received');
        $checkpoint = $parcel->checkpoints()->where('checkpoint_type', 'parcel_returned')->whereNull('source_checkpoint_id')->first();
        $custody = DeliveryCheckpoint::lastCustody($parcel);
        $shops = $order->items()->with('shop')->get()->pluck('shop')->unique('id');
        $shop = $shops->count() === 1 ? $shops->first() : null;

        return $shop && $event && $event->actor_id === $shop->user_id && $event->actor_role === 'seller'
            && $event->barcode_scanned === $parcel->tracking_number && $checkpoint?->scanned_by_id === $event->actor_id
            && $checkpoint->barcode_scanned === $parcel->tracking_number && $checkpoint->hub_id === $parcel->origin_bayan_hub_id
            && ($checkpoint->target_state['order_status'] ?? null) === 'returned'
            && ($custody['kind'] ?? null) === 'seller' && ($custody['user_id'] ?? null) === $shop->user_id
            && ($custody['shop_id'] ?? null) === $shop->id && ($custody['return_route_reference'] ?? null) === $route->reference
            && ($custody['seller_receipt_reference'] ?? null) === $event->reference;
    }

    public function refund(User $actor, Order $original, array $input): WalletTransaction
    {
        $data = Validator::make($input, ['request_token' => ['required', 'uuid'], 'payment_reference' => ['required', 'string', 'max:50'],
            'reason' => ['required', 'string', new ApplicationText('notes', 10, 500)], 'wallet_refund_confirmed' => ['accepted'],
            'amount' => ['prohibited'], 'buyer_id' => ['prohibited'], 'seller_id' => ['prohibited'],
        ])->validate();

        return DB::transaction(function () use ($actor, $original, $data) {
            app(AccountRestrictionService::class)->lockGuard();
            $order = Order::whereKey($original->id)->lockForUpdate()->firstOrFail();
            $order->setRelation('delivery', Delivery::where('order_id', $order->id)->lockForUpdate()->firstOrFail());
            User::whereIn('id', [$actor->id, $order->buyer_id])->orderBy('id')->lockForUpdate()->get();
            $admin = app(WalletCreditService::class)->admin($actor);
            $payment = WalletPayment::where('order_id', $order->id)->lockForUpdate()->firstOrFail();
            abort_unless(hash_equals($payment->reference, $data['payment_reference']), 409, 'Review the original wallet payment reference.');
            if ($prior = WalletTransaction::where('business_key', 'delivery-refund:'.$order->id)->first()) {
                abort_unless($prior->actor_id === $admin->id && hash_equals($prior->request_token_hash ?? '', hash('sha256', $data['request_token']))
                    && $prior->reason === trim($data['reason']), 409, 'The original returned-parcel refund is already recorded.');

                return $prior;
            }
            abort_unless($this->eligible($order), 409, 'The undelivered parcel must complete its actual reverse route and seller receipt before a full wallet refund.');
            app(WalletReservationService::class)->releaseReturned($order, 'Original wallet promotion reservation released after the undelivered parcel returned.');
            $ledger = app(WalletLedger::class);
            $buyer = $ledger->user(User::findOrFail($order->buyer_id));
            $escrow = $ledger->platform('escrow');
            $transaction = $ledger->post('delivery-refund:'.$order->id, 'wallet_delivery_refund', $payment->amount_cents,
                [$buyer->id => $payment->amount_cents, $escrow->id => -$payment->amount_cents], $admin, trim($data['reason']),
                ['payment_id' => $payment->id, 'return_route' => DeliveryCheckpoint::lastCustody($order->delivery)['return_route_reference']],
                orderId: $order->id, token: $data['request_token']);
            $payment->update(['status' => 'refunded', 'closed_at' => now()]);
            $order->update(['payment_status' => 'refunded']);

            return $transaction;
        }, 3);
    }
}

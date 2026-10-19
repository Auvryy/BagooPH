<?php

namespace App\Services\Wallet;

use App\Models\Delivery;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletAccount;
use App\Models\WalletPayment;
use App\Models\WalletPaymentAttempt;
use App\Models\WalletTransaction;
use App\Services\BuyerAccessService;
use App\Services\Finance\CodMoney;
use App\Services\Orders\OrderLifecycleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class WalletPaymentService
{
    public function sourceHash(Order $order): string
    {
        $items = $order->items()->with('shop:id,user_id')->orderBy('id')->get()->map(fn ($item) => [...$item->only([
            'id', 'product_id', 'shop_id', 'product_sale_id', 'quantity', 'unit_price', 'subtotal', 'color', 'size', 'sku_snapshot',
        ]), 'seller_id' => $item->shop?->user_id])->all();

        return hash('sha256', json_encode([$order->only(['id', 'buyer_id', 'subtotal', 'shipping_fee', 'voucher_discount',
            'voucher_accounting_version', 'total_amount', 'payment_method']), $items], JSON_THROW_ON_ERROR));
    }

    public function issue(Order $order): WalletPayment
    {
        abort_unless(DB::transactionLevel() > 0 && $order->payment_method === 'wallet' && $order->payment_status === 'pending', 409);

        return WalletPayment::create(['reference' => 'WPA-'.Str::upper((string) Str::uuid()), 'order_id' => $order->id,
            'buyer_id' => $order->buyer_id, 'amount_cents' => CodMoney::cents($order->total_amount),
            'source_hash' => $this->sourceHash($order), 'status' => 'pending', 'expires_at' => now()->addHour()]);
    }

    public function assertSource(Order $order, WalletPayment $payment): void
    {
        abort_unless($order->payment_method === 'wallet' && $payment->order_id === $order->id
            && $payment->buyer_id === $order->buyer_id && $payment->amount_cents === CodMoney::cents($order->total_amount)
            && hash_equals($payment->source_hash, $this->sourceHash($order)), 409,
            'The original wallet payment and purchased amounts need review.');
    }

    public function paid(Order $order, bool $allowRefunded = false): bool
    {
        if ($order->payment_method !== 'wallet') {
            return false;
        }
        $payment = WalletPayment::where('order_id', $order->id)->first();
        if (! $payment || ! $payment->paid_at || ! in_array($payment->status, $allowRefunded ? ['paid', 'refunded'] : ['paid'], true)
            || ! in_array($order->payment_status, $allowRefunded ? ['paid', 'refunded'] : ['paid'], true)
            || $payment->buyer_id !== $order->buyer_id || $payment->amount_cents !== CodMoney::cents($order->total_amount)
            || ! hash_equals($payment->source_hash, $this->sourceHash($order))) {
            return false;
        }
        $transaction = WalletTransaction::find($payment->wallet_transaction_id);
        if (! $transaction || $transaction->kind !== 'wallet_payment' || $transaction->order_id !== $order->id
            || $transaction->actor_id !== $order->buyer_id || $transaction->amount_cents !== $payment->amount_cents
            || ($transaction->context['payment_id'] ?? null) !== $payment->id) {
            return false;
        }
        $buyer = WalletAccount::where('user_id', $order->buyer_id)->first();
        $escrow = WalletAccount::where('account_key', 'platform:escrow')->first();
        $entries = $transaction->entries()->get();

        return $payment->amount_cents === 0 ? $entries->isEmpty()
            : $buyer && $escrow && $entries->count() === 2
                && $entries->firstWhere('wallet_account_id', $buyer->id)?->delta_cents === -$payment->amount_cents
                && $entries->firstWhere('wallet_account_id', $escrow->id)?->delta_cents === $payment->amount_cents;
    }

    public function assertFulfillable(Order $order): void
    {
        abort_if($order->payment_method === 'wallet' && ! $this->paid($order), 409,
            'The buyer must complete the original Bagoo Wallet payment before this parcel can be prepared or delivered.');
    }

    public function assertPrepaidHandover(Order $order, array $input): void
    {
        abort_unless($order->payment_method === 'wallet' && $this->paid($order), 409,
            'The original wallet payment must be verified before releasing this parcel without cash.');
        foreach (['cash_received', 'change_given'] as $field) {
            $value = $input[$field] ?? '0';
            abort_unless(is_string($value) && preg_match(CodMoney::RULE, $value) && CodMoney::cents($value) === 0, 422,
                'This parcel is already paid with virtual credits. Do not record cash collection.');
        }
        abort_if((bool) ($input['cash_confirmed'] ?? false), 422, 'This wallet parcel has no cash collection to confirm.');
    }

    public function command(User $actor, WalletPayment $payment, string $action, array $input): WalletPaymentAttempt
    {
        abort_unless(in_array($action, ['pay', 'cancel'], true), 404);
        $data = Validator::make($input, ['request_token' => ['required', 'uuid'],
            'payment_confirmed' => $action === 'pay' ? ['accepted'] : ['nullable', 'boolean'],
            'amount' => ['prohibited'], 'amount_cents' => ['prohibited'], 'buyer_id' => ['prohibited'], 'payment_status' => ['prohibited'],
        ])->validate();
        $token = hash('sha256', $data['request_token']);

        return DB::transaction(function () use ($actor, $payment, $action, $token, $data) {
            $order = Order::whereKey($payment->order_id)->lockForUpdate()->firstOrFail();
            $order->setRelation('delivery', Delivery::where('order_id', $order->id)->lockForUpdate()->first());
            $buyer = app(BuyerAccessService::class)->requirePortal($actor, lock: true);
            abort_unless($buyer->email_verified_at && $order->buyer_id === $buyer->id, 403);
            $payment = WalletPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $this->assertSource($order, $payment);
            if ($prior = WalletPaymentAttempt::where('request_token_hash', $token)->first()) {
                abort_unless($prior->wallet_payment_id === $payment->id && $prior->actor_id === $buyer->id && $prior->action === $action, 409,
                    'This payment request already belongs to another saved action.');

                return $prior;
            }
            $transaction = null;
            if ($payment->status === 'paid' && $this->paid($order)) {
                $transaction = WalletTransaction::findOrFail($payment->wallet_transaction_id);
                $outcome = 'paid';
                $message = 'Your original wallet payment is already recorded. No additional credits were deducted.';
            } elseif ($payment->status !== 'pending' || $order->status === 'cancelled') {
                $outcome = $payment->status;
                $message = 'This wallet payment is closed. No credits were deducted.';
            } elseif ($payment->expires_at->lessThanOrEqualTo(now())) {
                app(OrderLifecycleService::class)->expireUnpaidWallet($order);
                $outcome = 'expired';
                $message = 'This unpaid wallet order expired after one hour. Its reserved items were released.';
            } elseif ($action === 'cancel') {
                $outcome = 'cancelled_attempt';
                $message = 'Payment cancelled. Your order remains unpaid and no credits were deducted.';
            } else {
                abort_unless(in_array($order->status, ['placed', 'pending'], true), 409, 'This order cannot accept a new wallet payment.');
                $ledger = app(WalletLedger::class);
                $account = $ledger->user($buyer);
                $escrow = $ledger->platform('escrow');
                $balance = WalletAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
                abort_unless($balance->balance_cents === (int) $balance->entries()->sum('delta_cents'), 409, 'Your wallet transaction history needs review.');
                if ($balance->balance_cents < $payment->amount_cents) {
                    $outcome = 'failed';
                    $message = 'Payment failed: you do not have enough wallet credits. Request credits, then choose Pay again before the deadline.';
                } else {
                    $transaction = $ledger->post('payment:'.$payment->id, 'wallet_payment', $payment->amount_cents,
                        [$account->id => -$payment->amount_cents, $escrow->id => $payment->amount_cents], $buyer,
                        'Buyer confirmed payment for '.$order->order_number.'.', ['payment_id' => $payment->id, 'demo_only' => true],
                        orderId: $order->id, token: $data['request_token']);
                    $payment->fill(['status' => 'paid', 'paid_at' => now(), 'wallet_transaction_id' => $transaction->id])->save();
                    $order->update(['payment_status' => 'paid']);
                    $outcome = 'paid';
                    $message = 'Wallet payment recorded. The seller can now prepare your order.';
                }
            }

            return WalletPaymentAttempt::create(['reference' => 'WAT-'.Str::upper((string) Str::uuid()),
                'wallet_payment_id' => $payment->id, 'actor_id' => $buyer->id, 'request_token_hash' => $token,
                'action' => $action, 'outcome' => $outcome, 'message' => $message, 'wallet_transaction_id' => $transaction?->id, 'created_at' => now()]);
        }, 3);
    }

    public function closeCancellation(Order $order, ?User $actor, string $reason, bool $expired = false): void
    {
        if ($order->payment_method !== 'wallet') {
            return;
        }
        abort_unless(DB::transactionLevel() > 0 && $order->status === 'cancelled', 409);
        $payment = WalletPayment::where('order_id', $order->id)->lockForUpdate()->firstOrFail();
        $this->assertSource($order, $payment);
        if (in_array($payment->status, ['refunded', 'expired', 'cancelled'], true)) {
            return;
        }
        if ($payment->status === 'paid') {
            abort_unless($this->paid($order) && ! $payment->settlement_transaction_id, 409, 'This payment needs its original settlement and refund review.');
            $ledger = app(WalletLedger::class);
            $account = $ledger->user(User::findOrFail($order->buyer_id));
            $escrow = $ledger->platform('escrow');
            $ledger->post('cancellation:'.$order->id, 'wallet_cancellation_refund', $payment->amount_cents,
                [$account->id => $payment->amount_cents, $escrow->id => -$payment->amount_cents], $actor, $reason,
                ['payment_id' => $payment->id], orderId: $order->id);
            $payment->fill(['status' => 'refunded', 'closed_at' => now()])->save();
            $order->update(['payment_status' => 'refunded']);
        } else {
            abort_unless($payment->status === 'pending' && ! $payment->wallet_transaction_id, 409);
            $payment->fill(['status' => $expired ? 'expired' : 'cancelled', 'closed_at' => now()])->save();
            $order->update(['payment_status' => 'cancelled']);
            app(WalletReservationService::class)->restoreUses($order);
        }
    }

    public function expireDue(int $limit = 100): int
    {
        $ids = WalletPayment::where('status', 'pending')->where('expires_at', '<=', now())->orderBy('id')->limit($limit)->pluck('order_id');
        $count = 0;
        foreach ($ids as $id) {
            $count += DB::transaction(function () use ($id) {
                $order = Order::whereKey($id)->lockForUpdate()->firstOrFail();
                $order->setRelation('delivery', Delivery::where('order_id', $id)->lockForUpdate()->first());
                $payment = WalletPayment::where('order_id', $id)->lockForUpdate()->firstOrFail();
                if ($payment->status !== 'pending' || $payment->expires_at->isFuture()) {
                    return 0;
                }
                $this->assertSource($order, $payment);
                app(OrderLifecycleService::class)->expireUnpaidWallet($order);

                return 1;
            }, 3);
        }

        return $count;
    }
}

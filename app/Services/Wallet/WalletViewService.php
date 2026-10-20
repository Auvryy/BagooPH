<?php

namespace App\Services\Wallet;

use App\Models\Order;
use App\Models\User;
use App\Models\WalletAccount;
use App\Models\WalletEntry;
use App\Models\WalletPayment;
use App\Models\WalletTopUpRequest;
use App\Models\WalletTransaction;
use App\Services\BuyerAccessService;
use Illuminate\Support\Str;

class WalletViewService
{
    public function buyer(User $actor): array
    {
        $buyer = app(BuyerAccessService::class)->requireOrderHistory($actor);
        $balance = app(WalletLedger::class)->balance($buyer);
        $account = WalletAccount::where('user_id', $buyer->id)->first();
        $history = WalletEntry::where('wallet_account_id', $account?->id ?? 0)->with('transaction')
            ->orderByDesc('id')->paginate(10, ['*'], 'wallet_page')->withQueryString()->through(fn ($entry) => [
                'id' => $entry->id, 'reference' => $entry->transaction->reference, 'kind' => $entry->transaction->kind,
                'delta_cents' => $entry->delta_cents, 'balance_after_cents' => $entry->balance_after_cents,
                'reason' => $entry->transaction->reason, 'order_id' => $entry->transaction->order_id,
                'created_at' => $entry->created_at->toIso8601String(),
            ]);

        return ['available' => $balance !== null, 'demo_only' => true,
            'balance_cents' => $balance, 'currency' => 'PHP',
            'can_request' => $balance !== null && $buyer->canAccessPortal() && $buyer->email_verified_at !== null,
            'request_token' => (string) Str::uuid(), 'transactions' => $history,
            'credit_requests' => WalletTopUpRequest::where('buyer_id', $buyer->id)->latest('id')->limit(10)->get()
                ->map(fn ($request) => $request->only(['id', 'reference', 'amount_cents', 'reason', 'status', 'review_reason', 'created_at', 'reviewed_at']))->all()];
    }

    public function payment(User $actor, WalletPayment $payment): array
    {
        $buyer = app(BuyerAccessService::class)->requireExistingOrders($actor, $payment->order);
        $order = Order::with('items.product')->findOrFail($payment->order_id);
        abort_unless($order->buyer_id === $buyer->id && $payment->buyer_id === $buyer->id, 403);
        app(WalletPaymentService::class)->assertSource($order, $payment);
        $verified = app(WalletPaymentService::class)->paid($order, allowRefunded: true);
        $last = $payment->attempts()->latest('id')->first();
        $balance = app(WalletLedger::class)->balance($buyer);
        $refunds = WalletTransaction::where('order_id', $order->id)->whereIn('kind', ['wallet_return_refund', 'wallet_delivery_refund', 'wallet_cancellation_refund']);

        return ['id' => $payment->id, 'reference' => $payment->reference, 'status' => $payment->status,
            'amount_cents' => $payment->amount_cents, 'order_id' => $order->id, 'order_number' => $order->order_number,
            'items' => $order->items->map(fn ($item) => ['id' => $item->id, 'name' => $item->product?->name ?? 'Purchased item',
                'quantity' => $item->quantity, 'unit_price' => $item->unit_price])->all(),
            'balance_cents' => $balance,
            'expires_at' => $payment->expires_at->toIso8601String(), 'paid_at' => $payment->paid_at?->toIso8601String(),
            'can_pay' => $balance !== null && $buyer->canAccessPortal() && $buyer->email_verified_at && $payment->status === 'pending'
                && in_array($order->status, ['placed', 'pending'], true) && $payment->expires_at->isFuture(),
            'payment_reference' => $verified ? WalletTransaction::find($payment->wallet_transaction_id)?->reference : null,
            'refunded_cents' => (int) (clone $refunds)->sum('amount_cents'), 'refund_reference' => (clone $refunds)->latest('id')->value('reference'),
            'request_token' => (string) Str::uuid(), 'last_attempt' => $last?->only(['outcome', 'message', 'created_at'])];
    }
}

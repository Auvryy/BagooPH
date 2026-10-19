<?php

namespace App\Services\Wallet;

use App\Models\BuyerReturnAdjustment;
use App\Models\BuyerReturnCase;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\User;
use App\Models\VoucherOrderAllocation;
use App\Models\VoucherRevisionBalance;
use App\Models\WalletPayment;
use App\Models\WalletTransaction;
use App\Rules\ApplicationText;
use App\Services\AccountRestrictionService;
use App\Services\Finance\SellerSettlementService;
use App\Services\Returns\BuyerReturnAmounts;
use App\Services\ShopEligibilityService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class WalletSettlementService
{
    public function scoped(User $actor): Builder
    {
        $actor = User::findOrFail($actor->id);
        abort_unless($actor->closed_at === null && $actor->email_verified_at
            && (($actor->isAdmin() && $actor->canAccessPortal()) || ($actor->isSeller() && $actor->isKycApproved())), 403);

        return Order::where('payment_method', 'wallet')->whereHas('walletPayment', fn ($payment) => $payment->whereIn('status', ['paid', 'refunded']))
            ->when($actor->isSeller(), fn ($query) => $query
                ->whereHas('items.shop', fn ($shop) => $shop->where('user_id', $actor->id)));
    }

    public function basis(Order $order): array
    {
        $source = app(BuyerReturnAmounts::class)->original($order);
        $returns = BuyerReturnAdjustment::where('order_id', $order->id)->orderBy('id')->get();
        $payment = WalletPayment::where('order_id', $order->id)->firstOrFail();
        $gross = array_sum(array_column($source['units'], 'gross_cents'));
        $refunded = (int) WalletTransaction::where('order_id', $order->id)->where('kind', 'wallet_return_refund')->sum('amount_cents');

        return ['seller_cents' => $source['original_seller_cents'] - $returns->sum('seller_cents'),
            'commission_cents' => SellerSettlementService::split($gross)['commission_cents'] - $returns->sum('commission_cents'),
            'shipping_cents' => $source['shipping_cents'], 'promotion_cents' => array_sum(array_column($source['units'], 'platform_discount_cents'))
                - $returns->sum('platform_discount_cents') + $source['shipping_discount_cents'],
            'escrow_cents' => $payment->amount_cents - $refunded, 'refunded_cents' => $refunded,
            'adjustment_ids' => $returns->modelKeys(), 'gross_product_cents' => $gross];
    }

    public function present(Order $order): array
    {
        $order->loadMissing('items.shop.user', 'delivery');
        $payment = $order->walletPayment;
        if ($payment?->status === 'refunded') {
            $refund = WalletTransaction::where('order_id', $order->id)->whereIn('kind', ['wallet_delivery_refund', 'wallet_cancellation_refund'])->latest('id')->first();

            return ['order_id' => $order->id, 'order_number' => $order->order_number, 'shop_name' => $order->items->first()?->shop?->name,
                'seller_id' => $order->items->first()?->shop?->user_id, 'payment_reference' => $payment->reference,
                'status' => 'refunded', 'can_settle' => false, 'can_refund_delivery' => false, 'blockers' => [], 'consent' => '',
                'seller_cents' => 0, 'commission_cents' => 0, 'shipping_cents' => 0, 'promotion_cents' => 0,
                'refunded_cents' => $refund?->amount_cents ?? 0, 'settlement_reference' => null, 'refund_reference' => $refund?->reference];
        }
        $settlement = $payment?->settlement_transaction_id ? WalletTransaction::find($payment->settlement_transaction_id) : null;
        $basis = $this->basis($order);
        $issues = [];
        if (! app(WalletPaymentService::class)->paid($order)) {
            $issues[] = 'The original wallet payment is not verified.';
        }
        $checkpoint = $order->delivery?->checkpoints()->where('checkpoint_type', 'buyer_completed')->whereNull('source_checkpoint_id')->first();
        if ($order->status !== 'completed' || ! $checkpoint || $checkpoint->scanned_by_id !== $order->buyer_id
            || $checkpoint->actor_role !== 'buyer' || ($checkpoint->source_state['order_status'] ?? null) !== 'delivered'
            || ($checkpoint->target_state['order_status'] ?? null) !== 'completed'
            || ! in_array($order->delivery?->status, ['delivered', 'customer_collected'], true)) {
            $issues[] = 'The buyer must confirm receipt through the recorded delivery flow.';
        }
        if ($order->buyerReturns()->whereNotIn('status', BuyerReturnCase::TERMINAL)->exists()) {
            $issues[] = 'A buyer return still needs review or repayment.';
        }
        $shop = $order->items->first()?->shop;
        if (! $shop || $order->items->pluck('shop_id')->unique()->count() !== 1 || ! $shop->user?->canAccessPortal()
            || ! $shop->user?->email_verified_at || ! app(ShopEligibilityService::class)->isEligible($shop)) {
            $issues[] = 'The original seller and shop must remain approved and active.';
        }
        $consent = hash('sha256', json_encode([$payment?->reference, $basis], JSON_THROW_ON_ERROR));
        $returnRefund = app(WalletDeliveryRefundService::class)->eligible($order);
        if ($returnRefund) {
            $issues = ['The undelivered parcel returned to its seller. An admin can refund the original wallet payment.'];
        }

        return ['order_id' => $order->id, 'order_number' => $order->order_number, 'shop_name' => $shop?->name,
            'seller_id' => $shop?->user_id, 'payment_reference' => $payment?->reference,
            'settlement_reference' => $settlement?->reference, 'status' => $settlement ? 'credited' : ($returnRefund ? 'refund pending' : ($issues ? 'pending' : 'eligible')),
            'can_settle' => ! $settlement && $issues === [], 'can_refund_delivery' => $returnRefund,
            'payment_cents' => $payment?->amount_cents, 'blockers' => $issues, 'consent' => $consent, ...$basis];
    }

    public function settle(User $actor, Order $original, array $input): WalletTransaction
    {
        $data = Validator::make($input, ['request_token' => ['required', 'uuid'], 'consent' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'reason' => ['required', 'string', new ApplicationText('notes', 10, 500)], 'credit_proceeds_confirmed' => ['accepted'],
            'amount' => ['prohibited'], 'seller_id' => ['prohibited'], 'commission_cents' => ['prohibited'],
        ])->validate();

        return DB::transaction(function () use ($actor, $original, $data) {
            app(AccountRestrictionService::class)->lockGuard();
            $order = Order::whereKey($original->id)->lockForUpdate()->firstOrFail();
            Delivery::where('order_id', $order->id)->lockForUpdate()->first();
            $order->load('items.shop.user', 'delivery');
            User::whereIn('id', [$actor->id, $order->buyer_id, $order->items->first()?->shop?->user_id])->orderBy('id')->lockForUpdate()->get();
            $admin = app(WalletCreditService::class)->admin($actor);
            $payment = WalletPayment::where('order_id', $order->id)->lockForUpdate()->firstOrFail();
            abort_unless($order->payment_method === 'wallet' && app(WalletPaymentService::class)->paid($order), 409);
            if ($payment->settlement_transaction_id) {
                $prior = WalletTransaction::findOrFail($payment->settlement_transaction_id);
                abort_unless($prior->actor_id === $admin->id && hash_equals($prior->request_token_hash ?? '', hash('sha256', $data['request_token']))
                    && ($prior->context['consent'] ?? null) === $data['consent'] && $prior->reason === trim($data['reason']), 409,
                    'Virtual proceeds have already been credited. Reload their original reference.');

                return $prior;
            }
            $view = $this->present($order);
            abort_unless($view['can_settle'], 409, implode(' ', $view['blockers']));
            abort_unless(hash_equals($view['consent'], $data['consent']), 409, 'The payable virtual proceeds changed. Review the current amounts again.');
            $revisionIds = VoucherOrderAllocation::where('order_id', $order->id)->with('redemption')->get()->pluck('redemption.voucher_revision_id')->unique();
            VoucherRevisionBalance::whereIn('voucher_revision_id', $revisionIds)->orderBy('voucher_revision_id')->lockForUpdate()->get();
            $ledger = app(WalletLedger::class);
            $seller = $ledger->user($order->items->first()->shop->user);
            $escrow = $ledger->platform('escrow');
            $commission = $ledger->platform('commission');
            $shipping = $ledger->platform('shipping');
            $promotion = $ledger->platform('promotion');
            $transaction = $ledger->post('settlement:'.$payment->id, 'wallet_settlement', $view['seller_cents'],
                [$seller->id => $view['seller_cents'], $escrow->id => -$view['escrow_cents'], $commission->id => $view['commission_cents'],
                    $shipping->id => $view['shipping_cents'], $promotion->id => -$view['promotion_cents']], $admin, trim($data['reason']),
                ['payment_id' => $payment->id, 'consent' => $view['consent'], 'basis' => $this->basis($order), 'demo_only' => true],
                orderId: $order->id, token: $data['request_token']);
            $this->spendPromotions($order, $transaction);
            $payment->update(['settlement_transaction_id' => $transaction->id]);

            return $transaction;
        }, 3);
    }

    private function spendPromotions(Order $order, WalletTransaction $transaction): void
    {
        $parts = VoucherOrderAllocation::where('order_id', $order->id)->with('redemption')->orderBy('id')->get();
        $balances = VoucherRevisionBalance::whereIn('voucher_revision_id', $parts->pluck('redemption.voucher_revision_id')->unique())
            ->orderBy('voucher_revision_id')->lockForUpdate()->get()->keyBy('voucher_revision_id');
        foreach ($parts as $part) {
            $entries = $part->fundingEntries;
            $source = $entries->firstWhere('event_type', 'commitment');
            abort_unless($source && $source->amount_cents === $part->seller_expense_cents + $part->platform_expense_cents
                && ! $entries->contains(fn ($entry) => in_array($entry->event_type, ['expense', 'release'], true))
                && ! DB::table('wallet_voucher_releases')->where('voucher_order_allocation_id', $part->id)->exists(), 409);
            $balance = $balances[$part->redemption->voucher_revision_id];
            abort_unless($balance->committed_cents >= $source->amount_cents, 409);
            DB::table('wallet_promotion_expenses')->insert(['voucher_order_allocation_id' => $part->id,
                'source_entry_id' => $source->id, 'wallet_transaction_id' => $transaction->id,
                'amount_cents' => $source->amount_cents, 'created_at' => now()]);
            $balance->decrement('committed_cents', $source->amount_cents);
            $balance->increment('spent_cents', $source->amount_cents);
        }
    }

    public function refundReturn(Order $order, BuyerReturnCase $case, array $basis, User $actor, string $reason, string $token): WalletTransaction
    {
        abort_unless(DB::transactionLevel() > 0 && app(WalletPaymentService::class)->paid($order), 409, 'The original wallet payment must be verified before refund.');
        app(WalletCreditService::class)->admin($actor);
        abort_unless($case->order_id === $order->id && $case->buyer_id === $order->buyer_id && $case->status === 'repayment_ready', 409);
        $originalBasis = app(BuyerReturnAmounts::class)->forCase($case);
        abort_unless($basis === $originalBasis, 409, 'Refunds must use the original purchased units and exact approved amounts.');
        $payment = WalletPayment::where('order_id', $order->id)->lockForUpdate()->firstOrFail();
        $refunded = (int) WalletTransaction::where('order_id', $order->id)->where('kind', 'wallet_return_refund')->sum('amount_cents');
        abort_unless($refunded + $basis['buyer_cents'] <= $payment->amount_cents, 409, 'Wallet refunds cannot exceed the original amount paid.');
        $ledger = app(WalletLedger::class);
        $buyer = $ledger->user(User::findOrFail($order->buyer_id));
        $deltas = [$buyer->id => $basis['buyer_cents']];
        if ($payment->settlement_transaction_id) {
            $seller = $ledger->user(User::findOrFail($case->seller_id));
            $commission = $ledger->platform('commission');
            $promotion = $ledger->platform('promotion');
            $deltas += [$seller->id => -$basis['seller_cents'], $commission->id => -$basis['commission_cents'], $promotion->id => $basis['platform_discount_cents']];
        } else {
            $deltas[$ledger->platform('escrow')->id] = -$basis['buyer_cents'];
        }

        return $ledger->post('return:'.$case->id, 'wallet_return_refund', $basis['buyer_cents'], $deltas, $actor, $reason,
            ['payment_id' => $payment->id, 'adjustment_id' => BuyerReturnAdjustment::where('buyer_return_case_id', $case->id)->value('id'), 'units' => $basis['unit_snapshot']],
            orderId: $order->id, returnId: $case->id, token: $token);
    }
}

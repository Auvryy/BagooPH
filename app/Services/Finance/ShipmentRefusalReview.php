<?php

namespace App\Services\Finance;

use App\Models\BuyerReturnEvent;
use App\Models\BuyerReturnMoneyEvent;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\DeliveryCheckpoint;
use App\Models\DeliveryRecoveryEvent;
use App\Models\LogisticsCompany;
use App\Models\Order;
use App\Models\ShipmentFinancialEvent;
use App\Models\ShipmentFinancialPlan;
use App\Models\User;
use App\Models\WalletPayment;
use App\Models\WalletTransaction;
use App\Rules\ApplicationText;
use App\Services\AccountRestrictionService;
use App\Services\Logistics\DeliveryReturnService;
use App\Services\Returns\BuyerReturnAmounts;
use App\Services\Wallet\WalletCreditService;
use App\Services\Wallet\WalletPaymentService;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Throwable;

class ShipmentRefusalReview
{
    private function details(array $input): array
    {
        if (is_string($input['cost_reference'] ?? null)) {
            $input['cost_reference'] = trim($input['cost_reference']);
        }

        return Validator::make($input, ['reverse_fee' => ['required', 'string', 'regex:'.CodMoney::RULE],
            'cost_reference' => ['required', new ApplicationText('notes', 3, 120)],
            'amount_cents' => ['prohibited'], 'buyer_id' => ['prohibited'], 'seller_id' => ['prohibited'],
            'buyer_refund_cents' => ['prohibited'], 'logistics_company_id' => ['prohibited']])->validate();
    }

    public function preview(User $actor, Order $order, array $input): array
    {
        $admin = app(WalletCreditService::class)->admin($actor);
        $snapshot = $this->snapshot($order, $this->details($input));
        abort_if(in_array($admin->id, $snapshot['source_actor_ids'], true), 403, 'An independent admin must review the original refusal and return evidence.');

        return ['snapshot' => $snapshot, 'review_token' => app(AccountRestrictionService::class)->token($snapshot)];
    }

    public function snapshot(Order $original, array $details): array
    {
        $order = $original->fresh(['items.shop', 'delivery', 'walletPayment', 'codAccount']);
        $parcel = $order->delivery;
        abort_unless(($order->shipping_quote['refund_policy_version'] ?? 1) === 2
            && $order->status === 'returned' && $parcel?->status === 'returned' && $parcel->current_hub_id === null
            && $order->items->isNotEmpty() && $order->items->pluck('shop_id')->unique()->count() === 1
            && ! $order->buyerReturns()->exists(), 409, 'Review the original policy-two parcel after its actual return to the seller.');
        $seller = $order->items->first()->shop?->user_id;
        abort_unless($seller && $parcel->logistics_company_id && $parcel->order_id === $order->id, 409);
        $returns = app(DeliveryReturnService::class);
        try {
            $route = $returns->route($parcel);
            $position = $returns->position($parcel, $route);
        } catch (DomainException $error) {
            abort(409, $error->getMessage());
        }
        $receipt = $returns->event($route, 'seller_received');
        $checkpoint = $parcel->checkpoints()->where('checkpoint_type', 'parcel_returned')->whereNull('source_checkpoint_id')->first();
        $source = DeliveryCheckpoint::findOrFail($route->source_checkpoint_id);
        $attempt = DeliveryAttempt::where('delivery_id', $parcel->id)->orderByDesc('attempt_number')->first();
        $hubReceipt = $attempt ? DeliveryRecoveryEvent::where('delivery_attempt_id', $attempt->id)->where('event_type', 'hub_return')->first() : null;
        $custody = DeliveryCheckpoint::lastCustody($parcel);
        abort_unless($position === count($route->hub_ids) - 1 && $receipt && $checkpoint && $hubReceipt && $receipt->actor_id === $seller && $receipt->actor_role === 'seller'
            && $receipt->barcode_scanned === $parcel->tracking_number && $checkpoint->scanned_by_id === $seller
            && $checkpoint->barcode_scanned === $parcel->tracking_number && $checkpoint->hub_id === $parcel->origin_bayan_hub_id
            && ($checkpoint->target_state['order_status'] ?? null) === 'returned'
            && ($custody['kind'] ?? null) === 'seller' && ($custody['user_id'] ?? null) === $seller
            && ($custody['return_route_reference'] ?? null) === $route->reference
            && ($custody['seller_receipt_reference'] ?? null) === $receipt->reference
            && $attempt?->reason_code === 'customer_refused' && $attempt->barcode_scanned === $parcel->tracking_number
            && $source->checkpoint_type === 'failed_delivery_hub_return'
            && ($source->custody_after['recovery_reference'] ?? null) === $hubReceipt->reference
            && $source->scanned_by_id === $hubReceipt->actor_id && $source->hub_id === $hubReceipt->hub_id
            && $hubReceipt->delivery_id === $parcel->id && $hubReceipt->barcode_scanned === $parcel->tracking_number
            && $parcel->checkpoints()->where('checkpoint_type', 'picked_up')->where('barcode_scanned', $parcel->tracking_number)->exists(), 409,
            'The original refusal, actual reverse route and owning seller receipt must agree.');
        abort_unless($attempt->proof_path && Storage::disk('local')->exists($attempt->proof_path)
            && hash_equals($attempt->proof_hash, hash('sha256', Storage::disk('local')->get($attempt->proof_path))), 409,
            'The original private refusal evidence is unavailable.');
        $basis = app(BuyerReturnAmounts::class)->successorOriginal($order);
        $merchandise = array_sum(array_column($basis['units'], 'buyer_cents'));
        $paidShipping = $basis['shipping_cents'] - $basis['shipping_discount_cents'];
        $total = CodMoney::cents($order->total_amount);
        abort_unless($paidShipping >= 0 && $merchandise + $paidShipping === $total, 409, 'The original item, shipping and payment amounts need review.');
        $wallet = $order->payment_method === 'wallet';
        if ($wallet) {
            $payment = $order->walletPayment;
            abort_unless(app(WalletPaymentService::class)->paid($order) && $payment && $payment->amount_cents === $total
                && ! $payment->settlement_transaction_id && ! $order->codAccount
                && ! WalletTransaction::where('order_id', $order->id)->whereIn('kind', ['wallet_return_refund', 'wallet_delivery_refund', 'wallet_cancellation_refund'])->exists(), 409,
                'Retain the actual original unspent virtual payment before this refusal refund.');
            $paid = $total;
            $paymentReference = $payment->reference;
        } else {
            abort_unless($order->payment_method === 'cod' && $order->payment_status === 'pending' && ! $order->codAccount
                && ! $order->walletPayment && ! app(SellerSettlementService::class)->verifiedPayment($order->sellerSettlement), 409,
                'This refusal assessment requires evidenced uncollected COD. Collected cash needs its own reviewed outcome.');
            $paid = 0;
            $paymentReference = null;
        }
        $fee = CodMoney::cents($details['reverse_fee']);
        $charged = $wallet ? min($merchandise, $fee) : 0;

        return ['policy_version' => 2, 'kind' => 'buyer_refusal', 'order_id' => $order->id, 'delivery_id' => $parcel->id,
            'buyer_id' => $order->buyer_id, 'seller_id' => $seller, 'logistics_company_id' => $parcel->logistics_company_id,
            'payment_method' => $order->payment_method, 'original_payment_reference' => $paymentReference, 'actual_buyer_paid_cents' => $paid,
            'original_merchandise_cents' => $merchandise, 'outbound_shipping_cents' => $basis['shipping_cents'],
            'buyer_outbound_shipping_cents' => $paidShipping, 'shipping_subsidy_cents' => $basis['shipping_discount_cents'],
            'reverse_fee_cents' => $fee, 'cost_reference' => $details['cost_reference'], 'reverse_fee_withheld_cents' => $charged,
            'buyer_refund_cents' => $wallet ? $merchandise - $charged : 0,
            'buyer_liability_cents' => $wallet ? $fee - $charged : $paidShipping + $fee,
            'original_escrow_use_cents' => $wallet ? $total : 0, 'seller_protection_cents' => 0, 'seller_proceeds_cents' => 0,
            'commission_cents' => 0, 'units' => $basis['units'], 'promotion_snapshot' => $basis['promotion_snapshot'],
            'source_actor_ids' => array_values(array_unique([$order->buyer_id, $seller, $attempt->rider_id, $route->actor_id,
                LogisticsCompany::findOrFail($parcel->logistics_company_id)->user_id])),
            'source' => ['attempt_id' => $attempt->id, 'attempt_reference' => $attempt->reference, 'attempt_proof_hash' => $attempt->proof_hash,
                'return_route_reference' => $route->reference, 'return_source_checkpoint_id' => $source->id,
                'seller_receipt_reference' => $receipt->reference, 'seller_receipt_checkpoint_id' => $checkpoint->id, 'custody' => $custody]];
    }

    public function approve(User $actor, Order $original, array $input, UploadedFile $proof): ShipmentFinancialPlan
    {
        $details = $this->details($input);
        $data = Validator::make($input, ['request_token' => ['required', 'uuid'], 'review_token' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'reason' => ['required', new ApplicationText('notes', 10, 1000)], 'conforming_shipment_confirmed' => ['required', 'accepted']])->validate();
        $data['reason'] = trim($data['reason']);
        $token = hash('sha256', strtolower($data['request_token']));
        $files = app(ShipmentFinanceFiles::class);
        $info = $files->inspect($proof);
        $fingerprint = hash('sha256', json_encode([$original->id, $actor->id, $details, $data['reason'], $data['review_token'], $info['proof_hash']], JSON_THROW_ON_ERROR));
        $paths = [];
        try {
            $result = DB::transaction(function () use ($actor, $original, $details, $data, $token, $fingerprint, $files, $proof, $info, &$paths) {
                app(AccountRestrictionService::class)->lockGuard();
                $order = Order::whereKey($original->id)->lockForUpdate()->firstOrFail();
                Delivery::where('order_id', $order->id)->lockForUpdate()->firstOrFail();
                User::whereIn('id', [$actor->id, $order->buyer_id, $order->items->first()?->shop?->user_id])->orderBy('id')->lockForUpdate()->get();
                WalletPayment::where('order_id', $order->id)->lockForUpdate()->first();
                $admin = app(WalletCreditService::class)->admin($actor);
                if ($prior = ShipmentFinancialPlan::where('request_token_hash', $token)->first()) {
                    abort_unless($prior->order_id === $order->id && $prior->actor_id === $admin->id && hash_equals($prior->request_fingerprint, $fingerprint), 409);

                    return $prior;
                }
                $this->unused($token);
                abort_if(ShipmentFinancialPlan::where('order_id', $order->id)->exists(), 409, 'The original shipment already has a financial review.');
                $review = $this->preview($admin, $order, $details);
                abort_unless(hash_equals($review['review_token'], $data['review_token']), 409, 'The original refusal or amounts changed. Review the current evidence again.');
                $path = $files->store($proof, $info);
                $paths[] = $path;
                $snapshot = $review['snapshot'];
                $plan = ShipmentFinancialPlan::create(['order_id' => $order->id, 'undelivered_order_id' => $order->id, 'delivery_id' => $snapshot['delivery_id'], 'buyer_id' => $order->buyer_id,
                    'seller_id' => $snapshot['seller_id'], 'logistics_company_id' => $snapshot['logistics_company_id'], 'actor_id' => $admin->id,
                    'kind' => 'buyer_refusal', 'snapshot' => $snapshot, 'reason' => $data['reason'], 'proof_path' => $path,
                    ...array_diff_key($info, ['extension' => true]), 'request_token_hash' => $token, 'request_fingerprint' => $fingerprint, 'created_at' => now()]);
                ShipmentFinancialEvent::create(['shipment_financial_plan_id' => $plan->id, 'actor_id' => $admin->id, 'event_type' => 'approved', 'sequence' => 1,
                    'amount_cents' => 0, 'reason' => $data['reason'], 'context' => ['plan_reference' => $plan->reference, 'no_money_moved' => true],
                    'request_token_hash' => $token, 'request_fingerprint' => $fingerprint, 'created_at' => now()]);

                return $plan;
            }, 3);
            $files->discardUnused($paths);

            return $result;
        } catch (Throwable $error) {
            $files->discardUnused($paths);
            throw $error;
        }
    }

    public function unused(string $token): void
    {
        abort_if(ShipmentFinancialPlan::where('request_token_hash', $token)->exists() || ShipmentFinancialEvent::where('request_token_hash', $token)->exists()
            || WalletTransaction::where('request_token_hash', $token)->exists() || BuyerReturnEvent::where('request_token_hash', $token)->exists()
            || BuyerReturnMoneyEvent::where('request_token_hash', $token)->exists(), 409, 'This financial request token already belongs to another retained action.');
    }
}

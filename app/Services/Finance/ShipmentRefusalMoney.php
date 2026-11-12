<?php

namespace App\Services\Finance;

use App\Models\Delivery;
use App\Models\Order;
use App\Models\ShipmentFinancialEvent;
use App\Models\ShipmentFinancialPlan;
use App\Models\User;
use App\Models\VoucherOrderAllocation;
use App\Models\VoucherRevisionBalance;
use App\Models\WalletPayment;
use App\Rules\ApplicationText;
use App\Rules\AsciiPositiveInteger;
use App\Services\AccountRestrictionService;
use App\Services\Wallet\WalletCreditService;
use App\Services\Wallet\WalletLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ShipmentRefusalMoney
{
    public function execute(User $actor, ShipmentFinancialPlan $original, array $input): ShipmentFinancialEvent
    {
        $wallet = $original->snapshot['payment_method'] === 'wallet';
        if (is_string($input['reason'] ?? null)) {
            $input['reason'] = trim($input['reason']);
        }
        $data = Validator::make($input, ['expected_version' => ['required', new AsciiPositiveInteger, 'integer'], 'request_token' => ['required', 'uuid'],
            'reason' => ['required', new ApplicationText('notes', 10, 1000)],
            'wallet_refund_confirmed' => $wallet ? ['required', 'accepted'] : ['prohibited'],
            'assessment_confirmed' => $wallet ? ['prohibited'] : ['required', 'accepted'],
            'amount' => ['prohibited'], 'buyer_id' => ['prohibited'], 'recipient_id' => ['prohibited'], 'proof' => ['prohibited'], 'cash_reference' => ['prohibited']])->validate();
        $token = hash('sha256', strtolower($data['request_token']));
        $fingerprint = hash('sha256', json_encode([$original->id, $actor->id, (int) $data['expected_version'], $data['reason'], $wallet], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $original, $data, $wallet, $token, $fingerprint) {
            app(AccountRestrictionService::class)->lockGuard();
            $order = Order::whereKey($original->order_id)->lockForUpdate()->firstOrFail();
            Delivery::whereKey($original->delivery_id)->lockForUpdate()->firstOrFail();
            User::whereIn('id', [$actor->id, $original->buyer_id, $original->seller_id])->orderBy('id')->lockForUpdate()->get();
            $payment = WalletPayment::where('order_id', $order->id)->lockForUpdate()->first();
            $admin = app(WalletCreditService::class)->admin($actor);
            $plan = ShipmentFinancialPlan::whereKey($original->id)->lockForUpdate()->firstOrFail();
            abort_unless($plan->kind === 'buyer_refusal' && $wallet === ($plan->snapshot['payment_method'] === 'wallet'), 409);
            if ($prior = ShipmentFinancialEvent::where('request_token_hash', $token)->first()) {
                abort_unless($prior->shipment_financial_plan_id === $plan->id && $prior->actor_id === $admin->id
                    && hash_equals($prior->request_fingerprint, $fingerprint), 409);

                return $prior;
            }
            $review = app(ShipmentRefusalReview::class);
            $review->unused($token);
            $version = (int) $plan->events()->max('sequence');
            abort_unless($version === (int) $data['expected_version'] && ! $plan->events()->whereIn('event_type', ['wallet-refund', 'zero-refund-assessed'])->exists(), 409,
                'Review the original financial history before continuing.');
            $snapshot = $review->snapshot($order, ['reverse_fee' => SellerSettlementService::pesos($plan->snapshot['reverse_fee_cents']),
                'cost_reference' => $plan->snapshot['cost_reference']]);
            abort_unless($snapshot === $plan->snapshot, 409, 'The original return, payment or fee evidence changed. Retain the approved review before moving money.');
            $parts = VoucherOrderAllocation::where('order_id', $order->id)->with(['redemption', 'fundingEntries'])->orderBy('id')->get();
            $balances = VoucherRevisionBalance::whereIn('voucher_revision_id', $parts->pluck('redemption.voucher_revision_id')->unique())
                ->orderBy('voucher_revision_id')->lockForUpdate()->get()->keyBy('voucher_revision_id');
            $transaction = null;
            if ($wallet) {
                $ledger = app(WalletLedger::class);
                $buyer = $ledger->user(User::findOrFail($plan->buyer_id));
                $escrow = $ledger->platform('escrow');
                $shipping = $ledger->platform('shipping');
                $reverse = $ledger->platform('reverse_shipping');
                $promotion = $ledger->platform('promotion');
                $transaction = $ledger->post('shipment-refusal:'.$plan->id, 'wallet_refusal_refund', $snapshot['buyer_refund_cents'],
                    [$buyer->id => $snapshot['buyer_refund_cents'], $escrow->id => -$snapshot['original_escrow_use_cents'],
                        $shipping->id => $snapshot['outbound_shipping_cents'], $reverse->id => $snapshot['reverse_fee_withheld_cents'],
                        $promotion->id => -$snapshot['shipping_subsidy_cents']], $admin, $data['reason'],
                    ['shipment_financial_plan_reference' => $plan->reference, 'payment_reference' => $payment->reference,
                        'reverse_fee_cents' => $snapshot['reverse_fee_cents'], 'reverse_fee_withheld_cents' => $snapshot['reverse_fee_withheld_cents'],
                        'company_id' => $plan->logistics_company_id, 'buyer_liability_cents' => $snapshot['buyer_liability_cents'], 'demo_only' => true],
                    orderId: $order->id, token: strtolower($data['request_token']));
            }
            foreach ($parts as $part) {
                $entries = $part->fundingEntries;
                $commitment = $entries->firstWhere('event_type', 'commitment');
                $amount = $part->seller_expense_cents + $part->platform_expense_cents;
                abort_unless($commitment && $commitment->amount_cents === $amount && $part->product_discount_cents + $part->shipping_discount_cents === $amount
                    && ($part->shipping_discount_cents === 0 || $part->shipping_discount_cents === $amount)
                    && ! $entries->contains(fn ($entry) => in_array($entry->event_type, ['expense', 'release'], true))
                    && ! DB::table('wallet_promotion_expenses')->where('voucher_order_allocation_id', $part->id)->exists()
                    && ! DB::table('wallet_voucher_releases')->where('voucher_order_allocation_id', $part->id)->exists(), 409,
                    'The original unused product funding and actual outbound shipping subsidy need review.');
                $balance = $balances[$part->redemption->voucher_revision_id];
                abort_unless($balance && $balance->committed_cents >= $amount, 409);
                $spent = $part->shipping_discount_cents > 0;
                if ($wallet) {
                    $table = $spent ? 'wallet_promotion_expenses' : 'wallet_voucher_releases';
                    $record = ['voucher_order_allocation_id' => $part->id, 'source_entry_id' => $commitment->id, 'amount_cents' => $amount, 'created_at' => now()];
                    $record += $spent ? ['wallet_transaction_id' => $transaction->id] : ['wallet_payment_id' => $payment->id, 'reason' => 'Unused product funding released after reviewed refusal '.$plan->reference.'.'];
                    DB::table($table)->insert($record);
                } else {
                    $part->fundingEntries()->create(['reference' => 'VFD-'.Str::upper((string) Str::uuid()), 'event_type' => $spent ? 'expense' : 'release',
                        'source_entry_id' => $commitment->id, 'actor_id' => $admin->id, 'amount_cents' => $amount,
                        'reason' => 'Reviewed refusal '.$plan->reference.': '.($spent ? 'actual outbound shipping subsidy retained.' : 'unused product commitment released.')]);
                }
                $balance->decrement('committed_cents', $amount);
                $balance->increment($spent ? 'spent_cents' : 'released_cents', $amount);
            }

            return ShipmentFinancialEvent::create(['shipment_financial_plan_id' => $plan->id, 'actor_id' => $admin->id,
                'event_type' => $snapshot['buyer_refund_cents'] > 0 ? 'wallet-refund' : 'zero-refund-assessed', 'sequence' => $version + 1,
                'amount_cents' => $snapshot['buyer_refund_cents'], 'recipient_id' => $snapshot['buyer_refund_cents'] > 0 ? $plan->buyer_id : null,
                'wallet_transaction_id' => $transaction?->id, 'reason' => $data['reason'],
                'context' => ['plan_reference' => $plan->reference, 'buyer_liability_cents' => $snapshot['buyer_liability_cents'],
                    'wallet_reference' => $transaction?->reference, 'outbound_shipping_spent_cents' => $snapshot['buyer_outbound_shipping_cents'],
                    'reverse_fee_withheld_cents' => $snapshot['reverse_fee_withheld_cents'], 'demo_only' => $wallet,
                    'no_buyer_payment' => $snapshot['buyer_refund_cents'] === 0], 'request_token_hash' => $token,
                'request_fingerprint' => $fingerprint, 'created_at' => now()]);
        }, 3);
    }

    public function walletBasis(ShipmentFinancialPlan $plan): array
    {
        $snapshot = $plan->snapshot;
        $event = $plan->events()->whereIn('event_type', ['wallet-refund', 'zero-refund-assessed'])->first();

        return ['seller_cents' => 0, 'commission_cents' => 0, 'shipping_cents' => $event ? $snapshot['outbound_shipping_cents'] : 0,
            'promotion_cents' => $event ? $snapshot['shipping_subsidy_cents'] : 0, 'escrow_cents' => $event ? 0 : $snapshot['original_escrow_use_cents'],
            'refunded_cents' => $event?->amount_cents ?? 0, 'adjustment_ids' => [], 'gross_product_cents' => array_sum(array_column($snapshot['units'], 'gross_cents')),
            'original_escrow_reserved_cents' => 0, 'reverse_shipping_cents' => $event ? $snapshot['reverse_fee_withheld_cents'] : 0,
            'buyer_shipping_liability_cents' => $snapshot['buyer_liability_cents'], 'shipment_financial_plan_reference' => $plan->reference];
    }
}

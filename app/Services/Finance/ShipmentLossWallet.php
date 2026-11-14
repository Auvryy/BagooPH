<?php

namespace App\Services\Finance;

use App\Models\BuyerReturnCase;
use App\Models\Delivery;
use App\Models\LogisticsCompany;
use App\Models\LogisticsManifest;
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
use App\Services\Wallet\WalletLossReserve;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ShipmentLossWallet
{
    public function walletBasis(ShipmentFinancialPlan $plan): array
    {
        $state = app(WalletLossReserve::class)->summary($plan);

        return ['seller_cents' => 0, 'commission_cents' => 0, 'shipping_cents' => $state['resolved'] ? $state['protected_earned_shipping_cents'] : 0,
            'promotion_cents' => 0, 'escrow_cents' => $state['original_required_cents'] - $state['original_reserved_cents'],
            'refunded_cents' => $state['resolved'] ? $state['buyer_refund_cents'] : 0, 'adjustment_ids' => [],
            'gross_product_cents' => array_sum(array_column($plan->snapshot['units'], 'gross_cents')),
            'original_escrow_reserved_cents' => $state['original_reserved_cents'], 'seller_protection_cents' => $state['seller_protection_cents'],
            'shipment_financial_plan_reference' => $plan->reference];
    }

    public function command(User $actor, ShipmentFinancialPlan $original, string $action, array $input): ShipmentFinancialEvent
    {
        $confirmations = ['reserve-original' => 'original_reserve_confirmed', 'contribute-company' => 'company_transfer_confirmed',
            'approve-advance' => 'demo_advance_confirmed', 'resolve-wallet' => 'loss_settlement_confirmed'];
        abort_unless(isset($confirmations[$action]), 404);
        $hasAmount = in_array($action, ['contribute-company', 'approve-advance'], true);
        if (is_string($input['reason'] ?? null)) {
            $input['reason'] = trim($input['reason']);
        }
        $rules = ['expected_version' => ['required', new AsciiPositiveInteger, 'integer'], 'request_token' => ['required', 'uuid'],
            'reason' => ['required', new ApplicationText('notes', 10, 1000)],
            'amount' => $hasAmount ? ['required', 'string', 'regex:'.CodMoney::RULE] : ['prohibited'],
            'buyer_id' => ['prohibited'], 'seller_id' => ['prohibited'], 'company_id' => ['prohibited'], 'recipient_id' => ['prohibited'],
            'amount_cents' => ['prohibited'], 'proof' => ['prohibited'], 'cash_reference' => ['prohibited']];
        foreach ($confirmations as $name => $field) {
            $rules[$field] = $name === $action ? ['required', 'accepted'] : ['prohibited'];
        }
        $data = Validator::make($input, $rules)->validate();
        $amount = $hasAmount ? CodMoney::cents($data['amount']) : null;
        $uuid = strtolower($data['request_token']);
        $token = hash('sha256', $uuid);
        $fingerprint = hash('sha256', json_encode([$original->id, $actor->id, $action, (int) $data['expected_version'], $amount, $data['reason']], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $original, $action, $data, $amount, $uuid, $token, $fingerprint) {
            app(AccountRestrictionService::class)->lockGuard();
            Order::whereKey($original->order_id)->lockForUpdate()->firstOrFail();
            Delivery::whereKey($original->delivery_id)->lockForUpdate()->firstOrFail();
            User::whereIn('id', [$actor->id, $original->buyer_id, $original->seller_id])->orderBy('id')->lockForUpdate()->get();
            $actor = User::findOrFail($actor->id);
            WalletPayment::where('order_id', $original->order_id)->lockForUpdate()->firstOrFail();
            $plan = ShipmentFinancialPlan::whereKey($original->id)->lockForUpdate()->firstOrFail();
            abort_unless($plan->kind === 'transit_loss' && $plan->snapshot['payment_method'] === 'wallet', 409);
            LogisticsCompany::whereKey($plan->logistics_company_id)->lockForUpdate()->firstOrFail();
            LogisticsManifest::whereKey($plan->snapshot['source']['manifest_id'])->lockForUpdate()->firstOrFail();
            if ($plan->buyer_return_case_id) {
                $case = BuyerReturnCase::whereKey($plan->buyer_return_case_id)->lockForUpdate()->firstOrFail();
                $case->parcel()->lockForUpdate()->firstOrFail();
            }
            if ($prior = ShipmentFinancialEvent::where('request_token_hash', $token)->first()) {
                abort_unless($actor->closed_at === null && $actor->email_verified_at
                    && ($action === 'contribute-company' ? $actor->isLogistics() && $actor->isKycApproved()
                        && LogisticsCompany::whereKey($plan->logistics_company_id)->where('user_id', $actor->id)->exists()
                        : $actor->isAdmin() && $actor->canAccessPortal())
                    && $prior->shipment_financial_plan_id === $plan->id && $prior->actor_id === $actor->id && hash_equals($prior->request_fingerprint, $fingerprint), 409,
                    'Retain the exact original loss financial request.');

                return $prior;
            }
            app(ShipmentRefusalReview::class)->unused($token);
            $version = (int) $plan->events()->max('sequence');
            abort_unless($version === (int) $data['expected_version'], 409, 'Review the current original loss financial history before continuing.');
            $reserve = app(WalletLossReserve::class);
            $transaction = $reserve->execute($actor, $plan, $action, $amount, $data['reason'], $uuid);
            if ($action === 'resolve-wallet' && ! $plan->buyer_return_case_id) {
                $this->releaseUnusedPromotions($plan, $actor);
            }

            return ShipmentFinancialEvent::create(['shipment_financial_plan_id' => $plan->id, 'actor_id' => $actor->id, 'sequence' => $version + 1,
                'event_type' => 'loss-'.$action, 'amount_cents' => $transaction->amount_cents,
                'recipient_id' => $action === 'resolve-wallet' && $plan->snapshot['buyer_refund_cents'] > 0 ? $plan->buyer_id : null,
                'wallet_transaction_id' => $transaction->id, 'reason' => $data['reason'], 'request_token_hash' => $token,
                'request_fingerprint' => $fingerprint, 'context' => ['plan_reference' => $plan->reference, 'wallet_reference' => $transaction->reference,
                    'buyer_refund_cents' => $action === 'resolve-wallet' ? $plan->snapshot['buyer_refund_cents'] : 0,
                    'seller_protection_cents' => $action === 'resolve-wallet' ? $plan->snapshot['seller_protection_cents'] : 0,
                    'protected_earned_shipping_cents' => $action === 'resolve-wallet' ? $plan->snapshot['protected_earned_shipping_cents'] : 0,
                    'funding' => $reserve->summary($plan), 'demo_only' => true, 'no_physical_receipt' => true], 'created_at' => now()]);
        }, 3);
    }

    private function releaseUnusedPromotions(ShipmentFinancialPlan $plan, User $actor): void
    {
        // No completed sale or final delivery consumes these commitments. Recorded earned pickup is separately company-funded.
        $parts = VoucherOrderAllocation::where('order_id', $plan->order_id)->with(['redemption', 'fundingEntries'])->orderBy('id')->get();
        $balances = VoucherRevisionBalance::whereIn('voucher_revision_id', $parts->pluck('redemption.voucher_revision_id')->unique())
            ->orderBy('voucher_revision_id')->lockForUpdate()->get()->keyBy('voucher_revision_id');
        foreach ($parts as $part) {
            $commitment = $part->fundingEntries->firstWhere('event_type', 'commitment');
            $amount = $part->seller_expense_cents + $part->platform_expense_cents;
            abort_unless($commitment && $commitment->amount_cents === $amount
                && ! $part->fundingEntries->contains(fn ($entry) => in_array($entry->event_type, ['expense', 'release'], true))
                && ! DB::table('wallet_promotion_expenses')->where('voucher_order_allocation_id', $part->id)->exists()
                && ! DB::table('wallet_voucher_releases')->where('voucher_order_allocation_id', $part->id)->exists(), 409,
                'Retain the original unused voucher commitment before its reviewed loss release.');
            $balance = $balances[$part->redemption->voucher_revision_id];
            abort_unless($balance && $balance->committed_cents >= $amount, 409);
            DB::table('wallet_voucher_releases')->insert(['voucher_order_allocation_id' => $part->id, 'source_entry_id' => $commitment->id,
                'wallet_payment_id' => $plan->order->walletPayment->id, 'amount_cents' => $amount,
                'reason' => 'Unused original commitment released after reviewed loss '.$plan->reference.'.', 'created_at' => now()]);
            $balance->decrement('committed_cents', $amount);
            $balance->increment('released_cents', $amount);
        }
    }
}

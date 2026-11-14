<?php

namespace App\Services\Wallet;

use App\Models\BuyerReturnCase;
use App\Models\Delivery;
use App\Models\LogisticsCompany;
use App\Models\LogisticsManifest;
use App\Models\Order;
use App\Models\ShipmentFinancialEvent;
use App\Models\ShipmentFinancialPlan;
use App\Models\ShipmentLossReconciliation;
use App\Models\User;
use App\Models\WalletPayment;
use App\Models\WalletRefundReserveEntry;
use App\Rules\ApplicationText;
use App\Rules\AsciiPositiveInteger;
use App\Services\AccountRestrictionService;
use App\Services\BuyerAccessService;
use App\Services\Finance\ShipmentLossSource;
use App\Services\Finance\ShipmentRefusalReview;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

/** Voluntary paid-compensation returns and priority reimbursements after a parcel is found. */
class WalletLossCompensationReturn
{
    public function execute(User $actor, ShipmentFinancialPlan $original, string $action, array $input): ShipmentFinancialEvent
    {
        $confirmations = [
            'return-buyer-compensation' => 'buyer_compensation_return_confirmed',
            'return-seller-compensation' => 'seller_compensation_return_confirmed',
            'restore-company-compensation' => 'company_compensation_reimbursement_confirmed',
            'restore-escrow-compensation' => 'escrow_compensation_reimbursement_confirmed',
        ];
        abort_unless(isset($confirmations[$action]), 404);
        $allowed = ['expected_version', 'request_token', 'reason', $confirmations[$action]];
        $rules = array_fill_keys(array_diff(array_keys($input), $allowed), ['prohibited']);
        if (is_string($input['reason'] ?? null)) {
            $input['reason'] = trim($input['reason']);
        }
        $data = Validator::make($input, $rules + [
            'expected_version' => ['required', new AsciiPositiveInteger, 'integer'],
            'request_token' => ['required', 'uuid'],
            'reason' => ['required', new ApplicationText('notes', 10, 1000)],
            $confirmations[$action] => ['required', 'accepted'],
        ])->validate();
        $uuid = strtolower($data['request_token']);
        $token = hash('sha256', $uuid);
        $fingerprint = hash('sha256', json_encode([$original->id, $actor->id, (int) $data['expected_version'], $data['reason'], $action], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $original, $action, $data, $uuid, $token, $fingerprint) {
            app(AccountRestrictionService::class)->lockGuard();
            $order = Order::whereKey($original->order_id)->lockForUpdate()->firstOrFail();
            Delivery::whereKey($original->delivery_id)->lockForUpdate()->firstOrFail();
            User::whereIn('id', array_unique([$actor->id, $original->buyer_id, $original->seller_id]))->orderBy('id')->lockForUpdate()->get();
            WalletPayment::where('order_id', $order->id)->lockForUpdate()->first();
            $plan = ShipmentFinancialPlan::whereKey($original->id)->lockForUpdate()->firstOrFail();
            $company = LogisticsCompany::whereKey($plan->logistics_company_id)->lockForUpdate()->firstOrFail();
            User::whereKey($company->user_id)->lockForUpdate()->firstOrFail();
            LogisticsManifest::whereKey($plan->snapshot['source']['manifest_id'])->lockForUpdate()->firstOrFail();
            if ($plan->buyer_return_case_id) {
                $case = BuyerReturnCase::whereKey($plan->buyer_return_case_id)->lockForUpdate()->firstOrFail();
                $case->parcel()->lockForUpdate()->firstOrFail();
            }

            $actor = $this->authorize($actor, $plan, $action);
            if ($prior = ShipmentFinancialEvent::where('request_token_hash', $token)->first()) {
                abort_unless($prior->shipment_financial_plan_id === $plan->id && $prior->actor_id === $actor->id
                    && $prior->event_type === 'loss-'.$action && hash_equals($prior->request_fingerprint, $fingerprint), 409,
                    'Retain the exact original compensation-return request.');

                return $prior;
            }
            app(ShipmentRefusalReview::class)->unused($token);
            abort_unless($plan->kind === 'transit_loss' && $plan->snapshot['payment_method'] === 'wallet', 409);
            $released = Schema::hasTable('shipment_loss_reconciliations')
                && ShipmentLossReconciliation::where('shipment_financial_plan_id', $plan->id)->where('status', 'released')->exists();
            abort_if($released, 409, 'Quarantine was already reconciled and released.');

            $finding = app(ShipmentLossSource::class)->assertQuarantined($plan);
            $version = (int) $plan->events()->max('sequence');
            abort_unless($version === (int) $data['expected_version'], 409, 'Review the current original financial history before continuing.');

            $reserve = app(WalletLossReserve::class);
            $state = $reserve->summary($plan);
            $resolve = $plan->events()->where('event_type', 'loss-resolve-wallet')->first();
            abort_unless($state['resolved'] && $resolve, 409, 'Voluntary compensation returns apply only to already-paid Wallet settlements.');

            $ledger = app(WalletLedger::class);
            $reserveAccount = $ledger->platform('return_reserve');

            if ($action === 'return-buyer-compensation') {
                $amount = $plan->snapshot['buyer_refund_cents'];
                abort_unless($amount > 0 && $state['buyer_compensation_returned_cents'] === 0, 409,
                    'Only an actual unpaid-return buyer refund may be voluntarily returned once.');
                abort_unless(($ledger->balance($actor) ?? 0) >= $amount, 409,
                    'Your Wallet balance must cover the full voluntary return.');
                $transaction = $ledger->post('loss-buyer-compensation-return:'.$plan->id, 'wallet_loss_buyer_compensation_return', $amount,
                    [$ledger->user($actor)->id => -$amount, $reserveAccount->id => $amount], $actor, $data['reason'],
                    ['shipment_financial_plan_reference' => $plan->reference, 'reserve_source_kind' => 'buyer_compensation_return',
                        'finding_reference' => $finding->reference, 'demo_only' => true],
                    orderId: $plan->order_id, returnId: $plan->buyer_return_case_id, token: $uuid);
                WalletRefundReserveEntry::create(['order_id' => $plan->order_id, 'authority_reference' => $plan->reference,
                    'shipment_financial_plan_id' => $plan->id, 'buyer_return_repayment_plan_id' => null,
                    'wallet_transaction_id' => $transaction->id, 'wallet_account_id' => $reserveAccount->id,
                    'source_user_id' => $actor->id, 'source_kind' => 'buyer_compensation_return', 'delta_cents' => $amount,
                    'context' => ['finding_reference' => $finding->reference, 'demo_only' => true], 'created_at' => now()]);
                $reserve->retireExcess($plan, $actor);

                return ShipmentFinancialEvent::create(['shipment_financial_plan_id' => $plan->id, 'actor_id' => $actor->id,
                    'event_type' => 'loss-return-buyer-compensation', 'sequence' => $version + 1, 'amount_cents' => $amount,
                    'recipient_id' => null, 'source_event_id' => $resolve->id, 'wallet_transaction_id' => $transaction->id,
                    'reason' => $data['reason'], 'request_token_hash' => $token, 'request_fingerprint' => $fingerprint,
                    'context' => ['plan_reference' => $plan->reference, 'wallet_reference' => $transaction->reference,
                        'finding_reference' => $finding->reference, 'voluntary_owner_return' => true, 'no_parcel_release' => true],
                    'created_at' => now()]);
            }

            if ($action === 'return-seller-compensation') {
                $amount = $plan->snapshot['seller_protection_cents'];
                abort_unless($amount > 0 && $state['seller_compensation_returned_cents'] === 0, 409,
                    'Only an actual seller protection payout may be voluntarily returned once.');
                abort_unless(($ledger->balance($actor) ?? 0) >= $amount, 409,
                    'Your Wallet balance must cover the full voluntary return.');
                $transaction = $ledger->post('loss-seller-compensation-return:'.$plan->id, 'wallet_loss_seller_compensation_return', $amount,
                    [$ledger->user($actor)->id => -$amount, $reserveAccount->id => $amount], $actor, $data['reason'],
                    ['shipment_financial_plan_reference' => $plan->reference, 'reserve_source_kind' => 'seller_compensation_return',
                        'finding_reference' => $finding->reference, 'demo_only' => true],
                    orderId: $plan->order_id, returnId: $plan->buyer_return_case_id, token: $uuid);
                WalletRefundReserveEntry::create(['order_id' => $plan->order_id, 'authority_reference' => $plan->reference,
                    'shipment_financial_plan_id' => $plan->id, 'buyer_return_repayment_plan_id' => null,
                    'wallet_transaction_id' => $transaction->id, 'wallet_account_id' => $reserveAccount->id,
                    'source_user_id' => $actor->id, 'source_kind' => 'seller_compensation_return', 'delta_cents' => $amount,
                    'context' => ['finding_reference' => $finding->reference, 'demo_only' => true], 'created_at' => now()]);
                $reserve->retireExcess($plan, $actor);

                return ShipmentFinancialEvent::create(['shipment_financial_plan_id' => $plan->id, 'actor_id' => $actor->id,
                    'event_type' => 'loss-return-seller-compensation', 'sequence' => $version + 1, 'amount_cents' => $amount,
                    'recipient_id' => null, 'source_event_id' => $resolve->id, 'wallet_transaction_id' => $transaction->id,
                    'reason' => $data['reason'], 'request_token_hash' => $token, 'request_fingerprint' => $fingerprint,
                    'context' => ['plan_reference' => $plan->reference, 'wallet_reference' => $transaction->reference,
                        'finding_reference' => $finding->reference, 'voluntary_owner_return' => true, 'no_parcel_release' => true],
                    'created_at' => now()]);
            }

            abort_unless($state['advance_outstanding_cents'] === 0, 409,
                'Retire every outstanding platform advance before restoring company or escrow reimbursements.');

            if ($action === 'restore-company-compensation') {
                $owner = User::findOrFail($company->user_id);
                $remainingCompany = max(0, $state['company_received_cents'] - $state['company_reimbursed_cents']);
                $amount = min($state['balance_cents'], $remainingCompany);
                abort_unless($amount > 0, 409, 'No excess returned-compensation balance is available to reimburse the company.');
                $transaction = $ledger->post('loss-company-reimbursement:'.$plan->id.':'.$token, 'wallet_loss_company_reimbursement', $amount,
                    [$reserveAccount->id => -$amount, $ledger->user($owner)->id => $amount], $actor, $data['reason'],
                    ['shipment_financial_plan_reference' => $plan->reference, 'reserve_source_kind' => 'company_compensation_reimbursement',
                        'company_owner_id' => $owner->id, 'finding_reference' => $finding->reference, 'demo_only' => true],
                    orderId: $plan->order_id, returnId: $plan->buyer_return_case_id, token: $uuid);
                WalletRefundReserveEntry::create(['order_id' => $plan->order_id, 'authority_reference' => $plan->reference,
                    'shipment_financial_plan_id' => $plan->id, 'buyer_return_repayment_plan_id' => null,
                    'wallet_transaction_id' => $transaction->id, 'wallet_account_id' => $reserveAccount->id,
                    'source_user_id' => $owner->id, 'source_kind' => 'company_compensation_reimbursement', 'delta_cents' => -$amount,
                    'context' => ['finding_reference' => $finding->reference, 'demo_only' => true], 'created_at' => now()]);

                return ShipmentFinancialEvent::create(['shipment_financial_plan_id' => $plan->id, 'actor_id' => $actor->id,
                    'event_type' => 'loss-restore-company-compensation', 'sequence' => $version + 1, 'amount_cents' => $amount,
                    'recipient_id' => $owner->id, 'source_event_id' => $resolve->id, 'wallet_transaction_id' => $transaction->id,
                    'reason' => $data['reason'], 'request_token_hash' => $token, 'request_fingerprint' => $fingerprint,
                    'context' => ['plan_reference' => $plan->reference, 'wallet_reference' => $transaction->reference,
                        'finding_reference' => $finding->reference, 'no_parcel_release' => true],
                    'created_at' => now()]);
            }

            $remainingCompany = max(0, $state['company_received_cents'] - $state['company_reimbursed_cents']);
            abort_unless($remainingCompany === 0 || $state['balance_cents'] === 0, 409,
                'Reimburse company funding before returning any remaining surplus to platform escrow.');
            $amount = min($state['balance_cents'], $state['original_reserved_cents']);
            abort_unless($amount > 0, 409, 'No original escrow surplus is available for reimbursement.');
            $transaction = $ledger->post('loss-escrow-reimbursement:'.$plan->id.':'.$token, 'wallet_loss_escrow_reimbursement', $amount,
                [$reserveAccount->id => -$amount, $ledger->platform('escrow')->id => $amount], $actor, $data['reason'],
                ['shipment_financial_plan_reference' => $plan->reference, 'reserve_source_kind' => 'escrow_compensation_reimbursement',
                    'finding_reference' => $finding->reference, 'demo_only' => true],
                orderId: $plan->order_id, returnId: $plan->buyer_return_case_id, token: $uuid);
            WalletRefundReserveEntry::create(['order_id' => $plan->order_id, 'authority_reference' => $plan->reference,
                'shipment_financial_plan_id' => $plan->id, 'buyer_return_repayment_plan_id' => null,
                'wallet_transaction_id' => $transaction->id, 'wallet_account_id' => $reserveAccount->id,
                'source_user_id' => null, 'source_kind' => 'escrow_compensation_reimbursement', 'delta_cents' => -$amount,
                'context' => ['finding_reference' => $finding->reference, 'demo_only' => true], 'created_at' => now()]);

            return ShipmentFinancialEvent::create(['shipment_financial_plan_id' => $plan->id, 'actor_id' => $actor->id,
                'event_type' => 'loss-restore-escrow-compensation', 'sequence' => $version + 1, 'amount_cents' => $amount,
                'recipient_id' => null, 'source_event_id' => $resolve->id, 'wallet_transaction_id' => $transaction->id,
                'reason' => $data['reason'], 'request_token_hash' => $token, 'request_fingerprint' => $fingerprint,
                'context' => ['plan_reference' => $plan->reference, 'wallet_reference' => $transaction->reference,
                    'finding_reference' => $finding->reference, 'no_parcel_release' => true],
                'created_at' => now()]);
        }, 3);
    }

    private function authorize(User $actor, ShipmentFinancialPlan $plan, string $action): User
    {
        $actor = User::findOrFail($actor->id);
        abort_unless($actor->closed_at === null && $actor->email_verified_at, 403);
        if ($action === 'return-buyer-compensation') {
            app(BuyerAccessService::class)->requireOrderHistory($actor);
            abort_unless($actor->id === $plan->buyer_id, 403, 'Only the actual refunded buyer may voluntarily return compensation.');

            return $actor;
        }
        if ($action === 'return-seller-compensation') {
            abort_unless($actor->isSeller() && $actor->isKycApproved() && $actor->id === $plan->seller_id, 403,
                'Only the actual protected seller may voluntarily return protection proceeds.');

            return $actor;
        }
        $admin = app(WalletCreditService::class)->admin($actor);
        abort_if(in_array($admin->id, $plan->snapshot['source_actor_ids'] ?? [], true), 403,
            'An independent current admin must authorize reimbursement.');

        return $admin;
    }
}

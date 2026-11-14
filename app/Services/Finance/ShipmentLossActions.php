<?php

namespace App\Services\Finance;

use App\Models\ShipmentFinancialEvent;
use App\Models\ShipmentFinancialPlan;
use App\Models\ShipmentLossReconciliation;
use App\Models\User;
use App\Services\Wallet\WalletLossCompensationReturn;
use App\Services\Wallet\WalletLossGrantReturn;
use App\Services\Wallet\WalletLossPlatformReturn;
use App\Services\Wallet\WalletLossSourceReturn;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class ShipmentLossActions
{
    private const WALLET = [
        'reserve-original' => ['Reserve original virtual payment', 'original_reserve_confirmed'],
        'contribute-company' => ['Fund from my company Wallet', 'company_transfer_confirmed'],
        'approve-advance' => ['Record a demonstration advance', 'demo_advance_confirmed'],
        'resolve-wallet' => ['Apply the approved Wallet outcome', 'loss_settlement_confirmed'],
        'return-buyer-compensation' => ['Voluntarily return my Wallet refund', 'buyer_compensation_return_confirmed'],
        'return-seller-compensation' => ['Voluntarily return my Wallet seller protection', 'seller_compensation_return_confirmed'],
        'restore-company-compensation' => ['Reimburse company Wallet funding', 'company_compensation_reimbursement_confirmed'],
        'restore-escrow-compensation' => ['Restore surplus returned compensation to Escrow', 'escrow_compensation_reimbursement_confirmed'],
    ];

    private const CASH = [
        'offer-company' => 'Record my actual company transfer', 'offer-advance' => 'Record an actual advance transfer',
        'receive-company' => 'Confirm my incoming company transfer', 'receive-advance' => 'Confirm my incoming advance',
        'record-buyer-payment' => 'Record the original buyer refund', 'record-seller-protection' => 'Record separate seller protection',
        'confirm-buyer-receipt' => 'Confirm I received my refund', 'confirm-seller-receipt' => 'Confirm I received seller protection',
        'report-buyer-nonreceipt' => 'Report my refund as not received', 'report-seller-nonreceipt' => 'Report seller protection as not received',
        'resolve-buyer-nonreceipt' => 'Review the original disputed buyer payment', 'resolve-seller-nonreceipt' => 'Review the original disputed seller payment',
        'assess-zero-buyer' => 'Record that no buyer refund is due', 'resolve-cash' => 'Finish the acknowledged cash outcome',
        'retire-advance' => 'Record an actual advance return',
        'offer-buyer-compensation-return' => 'Offer voluntary buyer cash refund return',
        'receive-buyer-compensation-return' => 'Confirm incoming buyer cash refund return',
        'offer-seller-compensation-return' => 'Offer voluntary seller cash protection return',
        'receive-seller-compensation-return' => 'Confirm incoming seller cash protection return',
        'record-company-cash-return' => 'Record company cash reimbursement',
        'confirm-company-cash-return' => 'Confirm incoming company cash reimbursement',
        'report-company-cash-nonreceipt' => 'Report company cash reimbursement nonreceipt',
        'resolve-company-cash-nonreceipt' => 'Review disputed company cash reimbursement',
    ];

    public function definition(string $action, string $mode): array
    {
        $wallet = $mode === 'wallet' && isset(self::WALLET[$action]);
        $cash = $mode === 'cod' && isset(self::CASH[$action]);
        $grantReturn = $mode === 'wallet' && $action === 'return-unused-credit';
        $sourceReturn = $mode === 'wallet' && $action === 'return-company-source';
        $platformReturn = $mode === 'wallet' && $action === 'return-platform-source';
        $reconcileAction = in_array($action, ['reconcile-found', 'invalidate-reconciliation'], true);
        abort_unless($wallet || $cash || $grantReturn || $sourceReturn || $platformReturn || $reconcileAction
            || in_array($action, ['void-unfunded', 'record-disposition', 'account-loss'], true), 404);
        $offer = $cash && in_array($action, ['offer-company', 'offer-advance', 'offer-buyer-compensation-return', 'offer-seller-compensation-return'], true);
        $source = $cash && (str_starts_with($action, 'receive-') || str_contains($action, '-receipt')
            || str_contains($action, '-nonreceipt') || $action === 'retire-advance' || $action === 'confirm-company-cash-return');
        $proof = $cash && ($offer || str_starts_with($action, 'receive-') || str_starts_with($action, 'record-')
            || in_array($action, ['retire-advance', 'resolve-buyer-nonreceipt', 'resolve-seller-nonreceipt', 'resolve-company-cash-nonreceipt'], true));
        $confirmation = $wallet ? self::WALLET[$action][1] : match ($action) {
            'void-unfunded' => 'unfunded_void_confirmed', 'record-disposition' => 'disposition_confirmed',
            'account-loss' => 'loss_accounting_confirmed',
            'return-unused-credit' => 'unused_credit_return_confirmed',
            'return-company-source' => 'company_source_return_confirmed',
            'return-platform-source' => 'platform_source_return_confirmed',
            'reconcile-found' => 'reconciliation_confirmed',
            'invalidate-reconciliation' => 'invalidation_confirmed',
            'assess-zero-buyer' => 'zero_refund_confirmed', 'resolve-cash' => 'financial_resolution_confirmed',
            default => $proof ? 'payment_confirmed' : 'action_confirmed',
        };

        return ['id' => $action, 'label' => $wallet ? self::WALLET[$action][0] : (self::CASH[$action] ?? match ($action) {
            'void-unfunded' => 'Void an entirely unfunded review', 'account-loss' => 'Account for the confirmed lost parcel',
            'return-unused-credit' => 'Return my unused reviewed credits',
            'return-company-source' => 'Restore my unused reserve contribution',
            'return-platform-source' => 'Restore an unused platform source',
            'reconcile-found' => 'Approve found-parcel quarantine reconciliation',
            'invalidate-reconciliation' => 'Invalidate un-released reconciliation',
            default => 'Record independent operational loss evidence',
        }),
            'confirmation' => $confirmation, 'proof' => $proof || in_array($action, ['record-disposition', 'reconcile-found'], true),
            'amount' => in_array($action, ['offer-company', 'offer-advance', 'retire-advance', 'record-company-cash-return'], true) || ($wallet && in_array($action, ['contribute-company', 'approve-advance'], true)),
            'receiver' => $offer, 'source' => $source || $grantReturn || $sourceReturn || $platformReturn,
            'cash_reference' => $cash && ($offer || str_starts_with($action, 'record-') || $action === 'retire-advance'),
            'outcome_path' => $action === 'reconcile-found'];
    }

    public function execute(User $actor, ShipmentFinancialPlan $plan, string $action, array $input, ?UploadedFile $proof): ShipmentFinancialEvent
    {
        $spec = $this->definition($action, $plan->snapshot['payment_method']);
        $allowed = ['expected_version', 'request_token', 'reason', $spec['confirmation']];
        foreach (['amount' => 'amount', 'receiver' => 'recipient_id', 'source' => 'source_event_id', 'cash_reference' => 'cash_reference', 'outcome_path' => 'outcome_path'] as $flag => $field) {
            if ($spec[$flag]) {
                $allowed[] = $field;
            }
        }
        $rules = array_fill_keys(array_diff(array_keys($input), $allowed), ['prohibited']);
        $rules[$spec['confirmation']] = ['required', 'accepted'];
        Validator::make($input, $rules)->validate();
        Validator::make(['proof' => $proof], ['proof' => [$spec['proof'] ? 'required' : 'prohibited', 'nullable', 'file', 'max:5120']])->validate();
        unset($input['action_confirmed']);
        if ($action !== 'account-loss' && Schema::hasTable('shipment_loss_reconciliations')
            && ShipmentLossReconciliation::where('shipment_financial_plan_id', $plan->id)->where('status', 'released')->exists()) {
            abort(409, 'Quarantine was already reconciled and physically released; financial balances on this loss record are locked.');
        }
        if ($action === 'reconcile-found') {
            $record = app(ShipmentLossReconciliationService::class)->approve($actor, $plan, $input, $proof);

            return $plan->events()->where('request_token_hash', $record->request_token_hash)->firstOrFail();
        }
        if ($action === 'invalidate-reconciliation') {
            $record = app(ShipmentLossReconciliationService::class)->invalidate($actor, $plan, $input);

            return $plan->events()->where('request_token_hash', $record->invalidation_token_hash)->firstOrFail();
        }
        if (in_array($action, ['return-buyer-compensation', 'return-seller-compensation', 'restore-company-compensation', 'restore-escrow-compensation'], true)) {
            return app(WalletLossCompensationReturn::class)->execute($actor, $plan, $action, $input);
        }
        if ($action === 'return-unused-credit') {
            return app(WalletLossGrantReturn::class)->execute($actor, $plan, $input);
        }
        if ($action === 'return-company-source') {
            return app(WalletLossSourceReturn::class)->execute($actor, $plan, $input);
        }
        if ($action === 'return-platform-source') {
            return app(WalletLossPlatformReturn::class)->execute($actor, $plan, $input);
        }
        if ($action === 'void-unfunded') {
            return app(ShipmentLossReview::class)->voidUnfunded($actor, $plan, $input);
        }
        if ($action === 'record-disposition') {
            $record = app(ShipmentLossDispositionService::class)->record($actor, $plan, $input, $proof);

            return $plan->events()->where('request_token_hash', $record->request_token_hash)->firstOrFail();
        }
        if ($action === 'account-loss') {
            $record = app(ShipmentLossAccountingService::class)->record($actor, $plan, $input);

            return $plan->events()->where('request_token_hash', $record->request_token_hash)->firstOrFail();
        }

        return $plan->snapshot['payment_method'] === 'wallet'
            ? app(ShipmentLossWallet::class)->command($actor, $plan, $action, $input)
            : app(ShipmentLossCash::class)->command($actor, $plan, $action, $input, $proof);
    }
}

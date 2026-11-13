<?php

namespace App\Services\Finance;

use App\Models\ShipmentFinancialEvent;
use App\Models\ShipmentFinancialPlan;
use App\Models\User;
use App\Services\Wallet\WalletLossGrantReturn;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;

class ShipmentLossActions
{
    private const WALLET = [
        'reserve-original' => ['Reserve original virtual payment', 'original_reserve_confirmed'],
        'contribute-company' => ['Fund from my company Wallet', 'company_transfer_confirmed'],
        'approve-advance' => ['Record a demonstration advance', 'demo_advance_confirmed'],
        'resolve-wallet' => ['Apply the approved Wallet outcome', 'loss_settlement_confirmed'],
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
    ];

    public function definition(string $action, string $mode): array
    {
        $wallet = $mode === 'wallet' && isset(self::WALLET[$action]);
        $cash = $mode === 'cod' && isset(self::CASH[$action]);
        $grantReturn = $mode === 'wallet' && $action === 'return-unused-credit';
        abort_unless($wallet || $cash || $grantReturn || in_array($action, ['void-unfunded', 'record-disposition', 'account-loss'], true), 404);
        $offer = $cash && in_array($action, ['offer-company', 'offer-advance'], true);
        $source = $cash && (str_starts_with($action, 'receive-') || str_contains($action, '-receipt')
            || str_contains($action, '-nonreceipt') || $action === 'retire-advance');
        $proof = $cash && ($offer || str_starts_with($action, 'receive-') || str_starts_with($action, 'record-')
            || in_array($action, ['retire-advance', 'resolve-buyer-nonreceipt', 'resolve-seller-nonreceipt'], true));
        $confirmation = $wallet ? self::WALLET[$action][1] : match ($action) {
            'void-unfunded' => 'unfunded_void_confirmed', 'record-disposition' => 'disposition_confirmed',
            'account-loss' => 'loss_accounting_confirmed',
            'return-unused-credit' => 'unused_credit_return_confirmed',
            'assess-zero-buyer' => 'zero_refund_confirmed', 'resolve-cash' => 'financial_resolution_confirmed',
            default => $proof ? 'payment_confirmed' : 'action_confirmed',
        };

        return ['id' => $action, 'label' => $wallet ? self::WALLET[$action][0] : (self::CASH[$action] ?? match ($action) {
            'void-unfunded' => 'Void an entirely unfunded review', 'account-loss' => 'Account for the confirmed lost parcel',
            'return-unused-credit' => 'Return my unused reviewed credits',
            default => 'Record independent operational loss evidence',
        }),
            'confirmation' => $confirmation, 'proof' => $proof || $action === 'record-disposition',
            'amount' => $offer || $action === 'retire-advance' || $wallet && in_array($action, ['contribute-company', 'approve-advance'], true),
            'receiver' => $offer, 'source' => $source || $grantReturn,
            'cash_reference' => $cash && ($offer || str_starts_with($action, 'record-') || $action === 'retire-advance')];
    }

    public function execute(User $actor, ShipmentFinancialPlan $plan, string $action, array $input, ?UploadedFile $proof): ShipmentFinancialEvent
    {
        $spec = $this->definition($action, $plan->snapshot['payment_method']);
        $allowed = ['expected_version', 'request_token', 'reason', $spec['confirmation']];
        foreach (['amount' => 'amount', 'receiver' => 'recipient_id', 'source' => 'source_event_id', 'cash_reference' => 'cash_reference'] as $flag => $field) {
            if ($spec[$flag]) {
                $allowed[] = $field;
            }
        }
        $rules = array_fill_keys(array_diff(array_keys($input), $allowed), ['prohibited']);
        $rules[$spec['confirmation']] = ['required', 'accepted'];
        Validator::make($input, $rules)->validate();
        Validator::make(['proof' => $proof], ['proof' => [$spec['proof'] ? 'required' : 'prohibited', 'nullable', 'file', 'max:5120']])->validate();
        unset($input['action_confirmed']);
        if ($action === 'return-unused-credit') {
            return app(WalletLossGrantReturn::class)->execute($actor, $plan, $input);
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

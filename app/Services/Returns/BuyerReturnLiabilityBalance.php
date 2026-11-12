<?php

namespace App\Services\Returns;

use App\Models\BuyerReturnCase;
use App\Models\BuyerReturnRepaymentPlan;
use App\Services\Wallet\WalletReturnReserve;
use Illuminate\Support\Facades\Schema;

class BuyerReturnLiabilityBalance
{
    public function forCase(BuyerReturnCase $case): ?array
    {
        if (! Schema::hasTable('buyer_return_repayment_plans')) {
            return null;
        }
        $plan = BuyerReturnRepaymentPlan::where('buyer_return_case_id', $case->id)->first();
        if (! $plan) {
            return null;
        }
        abort_unless(($case->order->shipping_quote['refund_policy_version'] ?? 1) === 2, 409,
            'Retain the original approved successor terms before closing their financial obligation.');
        app(BuyerReturnAmounts::class)->adjustmentFinancials($case->order);
        if ($case->order->payment_method === 'cod') {
            return app(BuyerReturnCashExecution::class)->funding($case) + ['liable_company_id' => $plan->snapshot['liable_company_id']];
        }
        abort_unless($case->order->payment_method === 'wallet', 409);
        $state = app(WalletReturnReserve::class)->summary($plan);
        $seller = $plan->snapshot['fault'] === 'seller';
        $recovery = $seller ? $state['liable_received_cents'] : 0;
        $compensation = $seller ? 0 : $state['liable_received_cents'];

        return ['recovery_due_cents' => $plan->snapshot['seller_recovery_due_cents'], 'recovery_received_cents' => $recovery,
            'recovery_offered_cents' => $recovery, 'recovery_remaining_cents' => $plan->snapshot['seller_recovery_due_cents'] - $recovery,
            'compensation_due_cents' => $plan->snapshot['company_funding_due_cents'], 'compensation_received_cents' => $compensation,
            'compensation_offered_cents' => $compensation, 'compensation_remaining_cents' => $plan->snapshot['company_funding_due_cents'] - $compensation,
            'advance_received_cents' => $state['advance_cents'], 'advance_retired_cents' => $state['advance_retired_cents'],
            'liable_company_id' => $plan->snapshot['liable_company_id']];
    }
}

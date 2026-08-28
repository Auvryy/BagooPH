<?php

namespace Tests\Feature\E2E\Support;

use App\Models\CommissionLedger;
use App\Models\Order;
use PHPUnit\Framework\Assert;

trait AssertsCommissionLedgers
{
    public function assertCommissionSplit(Order $order, ?float $expectedGross = null): CommissionLedger
    {
        $ledger = CommissionLedger::where('order_id', $order->id)->first();

        Assert::assertNotNull(
            $ledger,
            "Expected CommissionLedger entry for Order #{$order->order_number}, but none was found."
        );

        $gross = $expectedGross !== null ? (float) $expectedGross : (float) $order->subtotal;
        $expectedSeller = round($gross * 0.90, 2);
        $expectedPlatform = round($gross * 0.10, 2);
        $expectedDelivery = 60.00;

        Assert::assertEquals(
            $gross,
            (float) $ledger->gross_amount,
            "Gross amount in CommissionLedger does not match expected gross."
        );

        Assert::assertEquals(
            $expectedSeller,
            (float) $ledger->seller_amount,
            "Seller amount in CommissionLedger must be exactly 90% of gross (Expected: {$expectedSeller}, Got: {$ledger->seller_amount})."
        );

        Assert::assertEquals(
            $expectedPlatform,
            (float) $ledger->platform_commission,
            "Platform commission in CommissionLedger must be exactly 10% of gross (Expected: {$expectedPlatform}, Got: {$ledger->platform_commission})."
        );

        Assert::assertEquals(
            $expectedDelivery,
            (float) $ledger->delivery_fee,
            "Courier delivery fee in CommissionLedger must be ₱60.00."
        );

        Assert::assertEquals(
            'settled',
            $ledger->status,
            "CommissionLedger status must be 'settled'."
        );

        return $ledger;
    }

    public function assertLedgerIdempotent(Order $order): void
    {
        $count = CommissionLedger::where('order_id', $order->id)->count();

        Assert::assertEquals(
            1,
            $count,
            "Expected exactly 1 CommissionLedger record for Order #{$order->order_number}, but found {$count}."
        );
    }
}

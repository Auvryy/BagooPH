<?php

namespace Tests\Feature\E2E\Support;

use App\Models\Order;
use PHPUnit\Framework\Assert;

trait SimulatesOrderLifecycle
{
    public function assertOrderStage(Order $order, string $expectedOrderStatus, string $expectedDeliveryStatus): void
    {
        $order->refresh();
        $delivery = $order->delivery?->fresh();

        Assert::assertEquals(
            $expectedOrderStatus,
            $order->status,
            "Expected order #{$order->order_number} to be in status '{$expectedOrderStatus}', but got '{$order->status}'."
        );

        if ($expectedDeliveryStatus === 'none') {
            Assert::assertNull($delivery, "Expected order #{$order->order_number} to have no delivery record, but one exists.");
        } else {
            Assert::assertNotNull($delivery, "Expected order #{$order->order_number} to have a delivery record, but none found.");
            Assert::assertEquals(
                $expectedDeliveryStatus,
                $delivery->status,
                "Expected delivery for order #{$order->order_number} to be in status '{$expectedDeliveryStatus}', but got '{$delivery->status}'."
            );
        }
    }
}
